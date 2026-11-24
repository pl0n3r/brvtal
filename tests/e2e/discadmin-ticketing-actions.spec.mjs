import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const script = readFileSync('discadmin/ticketing-orders.js','utf8');
const css = readFileSync('discadmin/ticketing.css','utf8');
const fragment = '<section data-ticketing-records data-ticketing-csrf="synthetic-csrf">'
  + '<form data-ticketing-records-form><input type="hidden" name="kind" value="orders">'
  + '<input name="q"><input name="email"><input name="event_id"><select name="status"></select><input name="from"><input name="to">'
  + '<button type="submit">Consultar</button></form>'
  + '<p role="status" data-ticketing-records-status></p><div data-ticketing-records-results></div>'
  + '<button type="button" data-ticketing-records-more hidden>Más</button><div data-ticketing-records-detail></div></section>';
const item = {id:91,event_id:6,email:'synthetic@example.test',status:'pending',total_cop:0,
  units:1,approvals:0,last_payment_state:'pending',mail_state:'no_delivery'};
async function launch(page, actionReply) {
  await page.route('**/discadmin/t18b-test.html', route => route.fulfill({contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
      + css + '</style><section data-admin-module="ticketing"><button data-ticketing-tab="orders" aria-selected="true">Pedidos</button>'
      + '<div id="panel"></div></section></html>'}));
  await page.route('**/discadmin/ticketing-orders.js', route =>
    route.fulfill({contentType:'text/javascript',body:script}));
  await page.route('**/discadmin/ticketing-orders.php*', route =>
    route.fulfill({contentType:'text/html',body:fragment}));
  await page.route('**/api/ticketing/admin-orders.php?*', route => {
    const detail = new URL(route.request().url()).searchParams.has('order_id');
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:detail
      ? {rows:[item],detail:{items:[],payments:[],mail:[]}}
      : {rows:[item],kind:'orders',next_cursor:null,has_more:false}})});
  });
  await page.route('**/api/ticketing/admin-actions.php', actionReply);
  await page.goto('https://www.brvtal.com.co/discadmin/t18b-test.html');
  await page.evaluate(async () => {
    const module = await import('/discadmin/ticketing-orders.js');
    await module.mount(document.querySelector('#panel'),'orders');
  });
  await page.getByRole('button',{name:'Detalle #91'}).click();
  await expect(page.locator('[data-ticketing-actions]')).toBeVisible();
}
test('T18b 390: cancel, confirm, keyboard and CSRF do not emit real mail', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  const calls=[];
  await launch(page, route => {
    calls.push({headers:route.request().headers(),body:route.request().postDataJSON()});
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,
      data:{receipt_id:8,status:'recorded',email_sent:false,payment_changed:false}})});
  });
  await page.locator('[data-ticketing-action]').selectOption('note');
  await page.locator('[data-ticketing-note]').fill('Revision sintetica sin datos personales');
  await page.getByRole('button',{name:'Preparar acción'}).click();
  await expect(page.locator('[data-ticketing-confirm]')).toBeVisible();
  await page.getByRole('button',{name:'Cancelar'}).click();
  expect(calls).toHaveLength(0);
  await page.getByRole('button',{name:'Preparar acción'}).click();
  await page.getByRole('button',{name:'Confirmar simulación'}).focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('[data-ticketing-action-status]')).toContainText('registrada');
  expect(calls).toHaveLength(1);
  expect(calls[0].headers['x-csrf-token']).toBe('synthetic-csrf');
  expect(calls[0].body.action).toBe('note');
  expect(calls[0].body.idempotency_key).toMatch(/^[\w-]{20,64}$/);
});
test('T18b 1440: missing consent and 403 do not change payment or send', async ({page}) => {
  await page.setViewportSize({width:1440,height:900});
  let forbidden = false;
  await launch(page, route => route.fulfill(forbidden
    ? {status:403,contentType:'application/json',body:'{"ok":false,"error":"FORBIDDEN"}'}
    : {status:409,contentType:'application/json',body:'{"ok":false,"error":"CONSENT_REQUIRED"}'}));
  await page.locator('[data-ticketing-action]').selectOption('contact_preview');
  await page.getByRole('button',{name:'Preparar acción'}).click();
  await page.getByRole('button',{name:'Confirmar simulación'}).click();
  await expect(page.locator('[data-ticketing-action-status]')).toContainText('consentimiento');
  forbidden = true;
  await page.getByRole('button',{name:'Preparar acción'}).click();
  await page.getByRole('button',{name:'Confirmar simulación'}).click();
  await expect(page.locator('[data-ticketing-action-status]')).toContainText('permiso');
});
test('T18b backend contract: real-mode sends, payment mutations and PII not allowed', () => {
  const php=readFileSync('api/ticketing/admin-actions.php','utf8');
  const core=readFileSync('config/ticketing_admin_actions.php','utf8');
  expect(php).toContain('brvtal_admin_require_csrf()');
  expect(core).toContain('BRVTAL_INTEGRATION_TESTS');
  expect(core).toContain('brvtalTicketAdminCanAction');
  expect(core).not.toContain('curl_exec(');
  expect(core).not.toContain('UPDATE ticket_orders');
  expect(core).not.toContain('ticket_mail_outbox');
});
