import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const code=readFileSync('discadmin/ticketing-entries.js','utf8');
const pagePhp=readFileSync('discadmin/ticketing-entries.php','utf8');
const apiPhp=readFileSync('api/ticketing/admin-entries.php','utf8');
const fixture='<section data-ticketing-entries data-ticketing-csrf="fixture" data-ticketing-can-reissue="1" data-ticketing-can-void="1"><p data-entry-status role="status"></p><div data-entry-list></div><button data-entry-more hidden>Más</button><div data-entry-detail></div><div data-entry-qr hidden><div data-entry-qr-image></div><button data-entry-download>Descargar</button><button data-entry-close>Cerrar</button></div></section>';
const row={id:31,event_id:8,order_id:99,status:'issued',uses:0,mail_state:'queued'};
async function setup(page,reply) {
  await page.route('**/discadmin/t19-fixture.html',r=>r.fulfill({contentType:'text/html',body:'<html lang="es"><div id="panel"></div></html>'}));
  await page.route('**/discadmin/ticketing-entries.php',r=>r.fulfill({contentType:'text/html',body:fixture}));
  await page.route('**/discadmin/ticketing-entries.js',r=>r.fulfill({contentType:'text/javascript',body:code}));
  await page.route('**/api/ticketing/admin-entries.php*',reply);
  await page.goto('https://www.brvtal.com.co/discadmin/t19-fixture.html');
  await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-entries.js');await m.mount(document.querySelector('#panel'));});
}
test('T19 boletas responsive, RBAC visual y consulta privada',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  await setup(page,r=>r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[row]})}));
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.getByRole('button',{name:'Reemitir'})).toBeVisible();
  await expect(page.locator('[data-entry-status]')).toContainText('Consulta privada');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
});
test('T19 doble clic no repite acción, antifraude y confirmación',async({page})=>{
  let posts=0,release;
  await setup(page,r=>{
    if (r.request().method()==='GET') return r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[row]})});
    posts++;
    return new Promise(resolve=>{release=()=>resolve(r.fulfill({contentType:'application/json',body:'{"ok":true,"data":{"qr":null,"replayed":true}}'}));});
  });
  page.on('dialog',async d=>{if(d.type()==='prompt')await d.accept('lost');else await d.accept();});
  await page.getByRole('button',{name:'Reemitir'}).dblclick();
  await expect.poll(()=>posts).toBe(1);
  release();
  await expect(page.locator('[data-entry-status]')).toContainText('Consulta privada');
});
test('T19 servidor exige CSRF, rol fuerte, firma de pago y no reconstituye secreto SHA',()=>{
  expect(pagePhp).toContain('brvtal_admin_require()');
  expect(apiPhp).toContain('brvtal_admin_require_csrf()');
  expect(apiPhp).toContain("brvtalTicketingCan($role,$capability)");
  expect(apiPhp).toContain("w.signed_status='APPROVED'");
  expect(apiPhp).toContain("hash_equals($hash,$parsed['hash'])");
  expect(apiPhp).toContain('IDEMPOTENCY_CONFLICT');
  expect(apiPhp).toContain('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED').toBe(false);
  expect(apiPhp).not.toContain("SET status='paid'");
});
