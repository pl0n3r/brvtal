import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';

const source=readFileSync('discadmin/ticketing-courtesy.js','utf8');
const css=readFileSync('discadmin/ticketing.css','utf8');
const catalog={ok:true,events:[{id:1,title:'Genesis',ticket_types:[{id:11,name:'General'}]}]};
const issued=(id=5,replayed=false)=>({ok:true,data:{invite_id:id,replayed,queued:true,sent:false,qr:null}});
const action=(id=5,status='queued')=>({ok:true,data:{invite_id:id,status,queued:status==='queued',sent:false}});

async function start(page,responder) {
  await page.route('**/discadmin/t25-fixture.html',route=>route.fulfill({
    contentType:'text/html',body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><div id="panel"><section data-ticketing-records data-ticketing-csrf="synthetic-csrf"></section></div></html>'
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
    (await import('/discadmin/ticketing-courtesy.js')).mount(document.querySelector('#panel'));
  });
}
async function fill(page,email='guest01@example.test') {
  await page.getByLabel('Evento').selectOption('1');
  await page.getByLabel('Boleta').selectOption('11');
  await page.getByLabel('Correo del invitado').fill(email);
  await page.getByLabel('Motivo interno').fill('Cortesía sintética');
}
const reply=(route,data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});

test('Cortesías: emisión sin ingresos, QR únicamente por correo, CSRF',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const requests=[];
  await start(page,route=>{requests.push(route.request());return reply(route,issued());});
  await fill(page);
  await page.getByRole('button',{name:'Emitir cortesía'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('correo con el QR quedó en cola');
  await expect(page.getByLabel('Número de cortesía')).toHaveValue('5');
  await expect(page.locator('[data-courtesy-qr]')).toHaveCount(0);
  await expect(page.locator('[data-ticketing-courtesy]')).not.toContainText('BRVTAL:T1:');
  expect(requests).toHaveLength(1);
  const req=requests[0];
  expect(req.headers()['x-csrf-token']).toBe('synthetic-csrf');
  expect(req.postDataJSON().action).toBe('issue');
  expect(req.postDataJSON().recipient).toBe('guest01@example.test');
  expect(req.postDataJSON().idempotency_key).toMatch(/^[\w-]{20,64}$/);
});

test('Cortesías: revocar y reenviar realmente gestionan el QR',async({page})=>{
  const actions=[];
  await start(page,route=>{
    const op=route.request().postDataJSON().action;actions.push(op);
    return reply(route,op==='issue'?issued():op==='revoke'?action(5,'revoked'):action(5,'queued'));
  });
  await fill(page);
  await page.getByRole('button',{name:'Emitir cortesía'}).click();
  await page.getByRole('button',{name:'Reenviar correo con QR'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('El enlace anterior ya no es válido');
  await page.getByRole('button',{name:'Revocar'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('Cortesía revocada y cupo liberado');
  expect(actions).toEqual(['issue','resend','revoke']);
});

test('Cortesías: acceso denegado y referencia inválida fallan cerrado',async({page})=>{
  let count=0;
  await start(page,route=>{count++;return reply(route,{ok:false,error:'FORBIDDEN'},403);});
  await page.getByRole('button',{name:'Revocar'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('número de cortesía válido');
  expect(count).toBe(0);
  await page.getByLabel('Número de cortesía').fill('8');
  await page.getByRole('button',{name:'Revocar'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('No autorizada');
  expect(count).toBe(1);
});

test('Cortesías: replay de red reutiliza clave sin duplicar',async({page})=>{
  const requests=[];
  await start(page,route=>{
    requests.push(route.request().postDataJSON());
    if(requests.length===1)return route.abort('failed');
    return reply(route,issued(17,true));
  });
  await fill(page);
  const submit=page.getByRole('button',{name:'Emitir cortesía'});
  await submit.click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('Sin confirmación');
  await submit.click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('no se creó otra entrada');
  expect(requests).toHaveLength(2);
  expect(requests[1].idempotency_key).toBe(requests[0].idempotency_key);
  await page.getByLabel('Correo del invitado').fill('guest02@example.test');
  await submit.click();
  await expect.poll(()=>requests.length).toBe(3);
  expect(requests[2].idempotency_key).not.toBe(requests[1].idempotency_key);
});

test('Cortesías: montaje idempotente, controles accesibles',async({page})=>{
  await start(page,route=>reply(route,issued()));
  await page.evaluate(async()=>{
    (await import('/discadmin/ticketing-courtesy.js')).mount(document.querySelector('#panel'));
  });
  await expect(page.locator('[data-ticketing-courtesy]')).toHaveCount(1);
  await expect(page.getByLabel('Correo del invitado')).toHaveAttribute('type','email');
  await expect(page.getByLabel('Evento')).toHaveJSProperty('tagName','SELECT');
  await page.getByRole('button',{name:'Emitir cortesía'}).focus();
  const focus=await page.getByRole('button',{name:'Emitir cortesía'}).evaluate(el=>document.activeElement===el);
  expect(focus).toBe(true);
});

test('Cortesías: contrato API sin proveedor directo ni secreto en UI',()=>{
  const endpoint=readFileSync('api/ticketing/admin-courtesy.php','utf8');
  const checkin=readFileSync('config/ticketing_checkin.php','utf8');
  const backend=readFileSync('config/ticketing_courtesy.php','utf8');
  expect(endpoint).toContain('brvtal_admin_require_csrf()');
  expect(checkin).toContain('brvtalCourtesyEnabled');
  expect(checkin).toContain("o.status='draft' AND o.total_cop=0");
  expect(backend).toContain('brvtalBuyerMailSeal');
  expect(backend).toContain('brvtal_ticket_mail_enqueue');
  expect(backend).not.toContain('COURTESY_DISABLED');
  expect(source).not.toContain('data.data.qr');
  expect(endpoint).not.toContain('curl_exec(');
});

for(const width of [390,1440]) {
  test('Cortesías capturas antes y después a '+width+'px',async({page},info)=>{
    await page.setViewportSize({width,height:900});
    const before='<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
      +'<body style="background:#050505;color:#fff;font:14px system-ui">'
      +'<h3>Cortesías · solo personal autorizado</h3><p>La operación no cobra ni envía correos.</p><form>'
      +'<label>Evento <input type="number" placeholder="ID"></label>'
      +'<label>Tipo de boleta <input type="number" placeholder="ID"></label>'
      +'<label>Identificador del invitado <input></label>'
      +'<label>Motivo interno <input></label></form></body></html>';
    await page.route('**/discadmin/courtesy-before.html',route=>route.fulfill({
      contentType:'text/html',body:before}));
    await page.goto('https://www.brvtal.com.co/discadmin/courtesy-before.html');
    await info.attach('cortesias-antes-'+width+'.png',{
      body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await start(page,route=>reply(route,{ok:false,error:'FORBIDDEN'},403));
    await page.getByLabel('Evento').selectOption('1');
    await expect(page.getByLabel('Boleta').locator('option')).toHaveCount(2);
    await expect(page.locator('[data-ticketing-courtesy]')).not.toContainText(/COURTESY_DISABLED|sin envío|identificador del invitado/i);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await info.attach('cortesias-despues-'+width+'.png',{
      body:await page.screenshot({fullPage:true}),contentType:'image/png'});
  });
}
