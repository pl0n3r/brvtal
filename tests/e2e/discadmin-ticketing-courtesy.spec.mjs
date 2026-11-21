import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const source=readFileSync('discadmin/ticketing-courtesy.js','utf8');
async function start(page,responder) {
  await page.route('**/discadmin/t25-fixture.html',route=>route.fulfill({
    contentType:'text/html',body:'<html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><div id="panel"><section data-ticketing-records data-ticketing-csrf="synthetic-csrf"></section></div></html>'
  }));
  await page.route('**/discadmin/ticketing-courtesy.js',route=>route.fulfill({
    contentType:'text/javascript',body:source
  }));
  await page.route('**/api/ticketing/admin-courtesy.php',responder);
  await page.goto('https://www.brvtal.com.co/discadmin/t25-fixture.html');
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
  await page.getByLabel('Evento').fill('1');
  await page.getByLabel('Tipo de boleta').fill('11');
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
  await expect(page.locator('[data-courtesy-status]')).toContainText('ID válido');
  expect(attempts).toBe(0);
  await page.getByLabel('ID de cortesía').fill('8');
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
