import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const source=readFileSync('discadmin/ticketing-courtesy.js','utf8');
const css=readFileSync('discadmin/ticketing.css','utf8');
const catalog={ok:true,events:[{id:1,title:'Genesis',ticket_types:[{id:11,name:'General'}]}]};
async function start(page,responder) {
  await page.route('**/discadmin/t25-fixture.html',route=>route.fulfill({
    contentType:'text/html',body:'<html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><div id="panel"><section data-ticketing-records data-ticketing-csrf="synthetic-csrf"></section></div></html>'
  }));
  await page.route('**/discadmin/ticketing-courtesy.js',route=>route.fulfill({
    contentType:'text/javascript',body:source
  }));
  await page.route('**/api/ticketing/admin-courtesy.php',responder);
  await page.route('**/api/ticketing/admin-promos.php?catalog=1',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify(catalog)}));
  await page.goto('https://www.brvtal.com.co/discadmin/t25-fixture.html');
  await page.addStyleTag({content:css});
  await page.evaluate(async()=>{
    const module=await import('/discadmin/ticketing-courtesy.js');
    module.mount(document.querySelector('#panel'));
  });
}
test('T25 390px: emisión confirmada una vez, sin pagos ni correo',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const requests=[];
  await start(page,route=>{
    requests.push({headers:route.request().headers(),body:route.request().postDataJSON()});
    route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,data:{invite_id:5,replayed:false,sent:false,qr:'BRVTAL:T1:sintetico'}
    })});
  });
  await page.getByLabel('Evento').selectOption('1');
  await page.getByLabel('Boleta').selectOption('11');
  await page.getByLabel('Identificador del invitado').fill('guest01');
  await page.getByLabel('Motivo interno').fill('Cortesía sintética');
  await page.getByRole('button',{name:'Emitir cortesía'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('registrada');
  await expect(page.locator('[data-courtesy-qr]')).toContainText('BRVTAL:T1:');
  expect(requests).toHaveLength(1);
  expect(requests[0].headers['x-csrf-token']).toBe('synthetic-csrf');
  expect(requests[0].body.action).toBe('issue');
  expect(requests[0].body.idempotency_key).toMatch(/^[\w-]{20,64}$/);
});
test('T25 1440px: 403, malformed ID and canceled changes fail closed',async({page})=>{
  await page.setViewportSize({width:1440,height:900});
  let attempts=0;
  await start(page,route=>{
    attempts++;
    route.fulfill({status:403,contentType:'application/json',
      body:'{"ok":false,"error":"FORBIDDEN"}'});
  });
  await page.getByRole('button',{name:'Revocar'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('número de cortesía válido');
  expect(attempts).toBe(0);
  await page.getByLabel('Número de cortesía').fill('8');
  await page.getByRole('button',{name:'Revocar'}).focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('[data-courtesy-status]')).toContainText('No autorizada');
  expect(attempts).toBe(1);
});
test('T25 integration contract: restricted action and common QR scan',()=>{
  const endpoint=readFileSync('api/ticketing/admin-courtesy.php','utf8');
  const checkin=readFileSync('config/ticketing_checkin.php','utf8');
  expect(endpoint).toContain('brvtal_admin_require_csrf()');
  expect(endpoint).toContain('brvtalCourtesyValidate');
  expect(checkin).toContain('brvtalCourtesyEnabled');
  expect(checkin).toContain("o.status='draft' AND o.total_cop=0");
  expect(endpoint).not.toContain('curl_exec(');
});

test('T25 mantiene QR tras revocación fallida y reenvío sin QR nuevo',async({page})=>{
  let revokeAttempts=0;
  await start(page,route=>{
    const action=route.request().postDataJSON().action;
    if (action==='revoke') {
      revokeAttempts++;
      return revokeAttempts===1
        ? route.fulfill({status:403,contentType:'application/json',body:'{"ok":false,"error":"FORBIDDEN"}'})
        : route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{invite_id:5,status:'revoked',sent:false}})});
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify(
      action==='issue'
        ? {ok:true,data:{invite_id:5,replayed:false,sent:false,qr:'BRVTAL:T1:solo-una-vez'}}
        : {ok:true,data:{invite_id:5,status:'logged_only',sent:false}}
    )});
  });
  await page.getByLabel('Evento').selectOption('1');
  await page.getByLabel('Boleta').selectOption('11');
  await page.getByLabel('Identificador del invitado').fill('guest01');
  await page.getByLabel('Motivo interno').fill('Cortesía sintética');
  await page.getByRole('button',{name:'Emitir cortesía'}).click();
  const qr=page.locator('[data-courtesy-qr]');
  await expect(qr).toHaveText('BRVTAL:T1:solo-una-vez');
  await expect(page.locator('[data-courtesy-qr-owner]')).toContainText('#5');
  await page.getByLabel('Número de cortesía').fill('5');
  await page.getByRole('button',{name:'Revocar'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('No autorizada');
  await expect(qr).toHaveText('BRVTAL:T1:solo-una-vez');
  await page.getByRole('button',{name:'Registrar reenvío (sin envío)'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('Reenvío registrado');
  await expect(qr).toHaveText('BRVTAL:T1:solo-una-vez');
  await page.getByRole('button',{name:'Revocar'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('Revocación registrada');
  await expect(qr).toHaveText('BRVTAL:T1:solo-una-vez');
  await page.getByRole('button',{name:'Ocultar QR'}).click();
  await expect(qr).toBeHidden();
});

test('T25 reintenta tras error de red con la misma clave; cambios generan otra',async({page})=>{
  const requests=[];
  await start(page,route=>{
    const body=route.request().postDataJSON();requests.push(body);
    if (requests.length===1) return route.abort('failed');
    return route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,data:{invite_id:17,replayed:true,qr:null,sent:false}})});
  });
  await page.getByLabel('Evento').selectOption('1');
  await page.getByLabel('Boleta').selectOption('11');
  await page.getByLabel('Identificador del invitado').fill('guest01');
  await page.getByLabel('Motivo interno').fill('Cortesía sintética');
  const submit=page.getByRole('button',{name:'Emitir cortesía'});
  await submit.click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('Sin confirmación');
  await submit.click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('no recupera');
  expect(requests).toHaveLength(2);
  expect(requests[1].idempotency_key).toBe(requests[0].idempotency_key);
  await expect(page.locator('[data-courtesy-qr]')).toBeHidden();
  await page.getByLabel('Identificador del invitado').fill('guest02');
  await submit.click();
  await expect.poll(()=>requests.length).toBe(3);
  expect(requests[2].idempotency_key).not.toBe(requests[1].idempotency_key);
});

test('T25 no duplica montajes ni presenta QR de otra invitación en replay',async({page})=>{
  let count=0;
  await start(page,route=>{
    count++;
    const data=count===1
      ? {invite_id:5,replayed:false,qr:'BRVTAL:T1:primero',sent:false}
      : {invite_id:9,replayed:true,qr:null,sent:false};
    return route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,data})});
  });
  await page.evaluate(async()=>{
    (await import('/discadmin/ticketing-courtesy.js')).mount(document.querySelector('#panel'));
  });
  await expect(page.locator('[data-ticketing-courtesy]')).toHaveCount(1);
  await page.getByLabel('Evento').selectOption('1');
  await page.getByLabel('Boleta').selectOption('11');
  await page.getByLabel('Identificador del invitado').fill('guest01');
  await page.getByLabel('Motivo interno').fill('Cortesía sintética');
  const submit=page.getByRole('button',{name:'Emitir cortesía'});
  await submit.click();
  await expect(page.locator('[data-courtesy-qr]')).toHaveText('BRVTAL:T1:primero');
  await page.getByLabel('Identificador del invitado').fill('guest02');
  await submit.click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('no recupera');
  await expect(page.locator('[data-courtesy-qr]')).toBeHidden();
  await expect(page.locator('[data-courtesy-qr-owner]')).toBeEmpty();
});

for (const width of [390,1440]) {
  test('AC-01 Cortesías antes/después y selectores a '+width+' px',async({page},testInfo)=>{
    await page.setViewportSize({width,height:900});
    const before='<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
      +'<body style="background:#050505;color:#fff;font:14px system-ui">'
      +'<h3>Cortesías · solo personal autorizado</h3><form>'
      +'<label>Evento <input type="number" placeholder="ID"></label>'
      +'<label>Tipo de boleta <input type="number" placeholder="ID"></label>'
      +'<label>Identificador del invitado <input></label>'
      +'<label>Motivo interno <input></label></form></body></html>';
    await page.route('**/discadmin/courtesy-before.html',r=>r.fulfill({
      contentType:'text/html',body:before}));
    await page.goto('https://www.brvtal.com.co/discadmin/courtesy-before.html');
    await testInfo.attach('cortesias-antes-'+width+'.png',{
      body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await start(page,r=>r.fulfill({status:403,contentType:'application/json',
      body:'{"ok":false,"error":"FORBIDDEN"}'}));
    await expect(page.locator('[data-ticketing-courtesy] .tk-form')).toHaveCount(2);
    await expect(page.getByLabel('Evento')).toHaveJSProperty('tagName','SELECT');
    await page.getByLabel('Evento').selectOption('1');
    await expect(page.getByLabel('Boleta').locator('option')).toHaveCount(2);
    await expect(page.locator('[data-ticketing-courtesy]')).not.toContainText(/sintético|pruebas|UTC/i);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await testInfo.attach('cortesias-despues-'+width+'.png',{
      body:await page.screenshot({fullPage:true}),contentType:'image/png'});
  });
}
