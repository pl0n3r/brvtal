import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const js = readFileSync('discadmin/ticketing-orders.js','utf8');
const css = readFileSync('discadmin/ticketing.css','utf8');
const php = readFileSync('discadmin/ticketing-orders.php','utf8');
const api = readFileSync('api/ticketing/admin-orders.php','utf8');
const fixture = '<section data-ticketing-records><h2>Pedidos y compradores</h2>'
  + '<form data-ticketing-records-form>'
  + '<label>Vista<select name="kind"><option value="orders">Pedidos</option><option value="buyers">Compradores</option></select></label>'
  + '<label>Buscar<input type="search" name="q"></label><label>Correo exacto<input type="email" name="email"></label>'
  + '<label>Evento<input type="number" name="event_id"></label><label>Estado<select name="status"></select></label>'
  + '<label>Desde<input type="date" name="from"></label><label>Hasta<input type="date" name="to"></label>'
  + '<button type="submit">Consultar</button><button type="button" data-ticketing-export>Exportar CSV</button></form>'
  + '<p role="status" data-ticketing-records-status></p><div data-ticketing-records-results></div>'
  + '<button type="button" data-ticketing-records-more hidden>Cargar más</button>'
  + '<div data-ticketing-records-detail></div></section>';
async function start(page, reply, kind = 'orders') {
  await page.route('**/discadmin/t18-fixture.html', route => route.fulfill({
    contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
      + '<style>' + css + '</style><section data-admin-module="ticketing">'
      + '<button type="button" data-ticketing-tab="orders" aria-selected="' + String(kind==='orders') + '">Pedidos</button>'
      + '<button type="button" data-ticketing-tab="buyers" aria-selected="' + String(kind==='buyers') + '">Compradores</button>'
      + '<div id="ticketing-panel"></div></section></html>'
  }));
  await page.route('**/discadmin/ticketing-orders.js', route => route.fulfill({contentType:'text/javascript',body:js}));
  await page.route('**/discadmin/ticketing-orders.php', route => route.fulfill({contentType:'text/html',body:fixture}));
  await page.route('**/api/ticketing/admin-orders.php?*', reply);
  await page.goto('https://www.brvtal.com.co/discadmin/t18-fixture.html');
  await page.evaluate(async kind => {
    const module = await import('/discadmin/ticketing-orders.js');
    await module.mount(document.getElementById('ticketing-panel'),kind);
  },kind);
}
const order = {id:91,buyer_id:3,event_id:6,status:'refunded',currency:'COP',
  total_cop:75000,email:'synthetic@example.test',units:2,approvals:1,
  last_payment_state:'refunded',mail_state:'failed'};
test('T18 pedidos: mobile, búsqueda, pago/correo desacoplados y detalle', async ({page}) => {
  await page.setViewportSize({width:390,height:840});
  let requests = [];
  await start(page,route => {
    const url = new URL(route.request().url());
    requests.push(url);
    const detail = url.searchParams.has('order_id');
    route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,data:detail
        ? {rows:[order],detail:{items:[{ticket_name_snapshot:'General',quantity:2}],
          payments:[{status:'refunded',gross_amount_cop:75000}],mail:[{delivery_state:'failed',created_at:'2026-11-19'}]}}
        : {rows:[order],kind:'orders',has_more:false,next_cursor:null}
    })});
  });
  await expect(page.locator('table tbody tr')).toHaveCount(1);
  await expect(page.locator('table')).toContainText('refunded');
  await expect(page.locator('table')).toContainText('failed');
  await page.locator('[name=q]').fill('91');
  await page.getByRole('button',{name:'Consultar'}).click();
  await expect.poll(()=>requests.some(url=>url.searchParams.get('q')==='91')).toBeTruthy();
  await page.getByRole('button',{name:'Detalle #91'}).click();
  await expect(page.locator('[data-ticketing-records-detail]')).toContainText('General');
  await expect(page.locator('[data-ticketing-records-detail]')).toContainText('failed');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
});
test('T18 compradores: empty search, explicit permission denial and safe failure', async ({page}) => {
  let denied = false;
  await start(page,route => route.fulfill(denied
    ? {status:403,contentType:'application/json',body:'{"ok":false,"error":"FORBIDDEN"}'}
    : {contentType:'application/json',body:JSON.stringify({ok:true,data:{rows:[],kind:'buyers',has_more:false,next_cursor:null}})}
  ),'buyers');
  await expect(page.locator('[data-ticketing-records-results]')).toContainText('No hay resultados');
  await expect(page.locator('select[name=status] option')).toHaveCount(4);
  denied = true;
  await page.getByRole('button',{name:'Consultar'}).click();
  await expect(page.locator('[data-ticketing-records-status]')).toContainText('Acceso no autorizado');
  await expect(page.locator('table')).toHaveCount(0);
});
test('T18 servidor obliga sesión, roles y filtros tipados; export sin secretos', () => {
  expect(php).toContain('brvtal_admin_require();');
  expect(php).toContain("brvtalTicketingCan($role, 'buyers')");
  expect(api).toContain('brvtalTicketAdminFilters($_GET)');
  expect(api).toContain('BUYER_PERMISSION_REQUIRED');
  expect(api).toContain("brvtalTicketingCan($role, 'export')");
  expect(api).not.toContain('provider_transaction_id');
});
