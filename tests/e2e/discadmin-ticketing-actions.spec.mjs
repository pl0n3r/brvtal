import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const script=readFileSync('discadmin/ticketing-orders.js','utf8');
const css=readFileSync('discadmin/ticketing.css','utf8');
const fragment='<section data-ticketing-records data-ticketing-csrf="test-csrf">'
 +'<form data-ticketing-records-form><input type="hidden" name="kind" value="orders">'
 +'<input name="q"><input name="email"><input name="event_id"><select name="status"></select>'
 +'<input name="from"><input name="to"><button type="submit">Consultar</button></form>'
 +'<p role="status" data-ticketing-records-status></p><div data-ticketing-records-results></div>'
 +'<button type="button" data-ticketing-records-more hidden>Más</button>'
 +'<div data-ticketing-records-detail></div></section>';
const row={id:91,event_id:6,email:'test@example.test',status:'paid',total_cop:85000,units:1,
  approvals:1,last_payment_state:'approved',mail_state:'failed'};
const detail={order:{status:'paid',event_title:'SAVØR',total_cop:85000,created_at_utc:'2026-11-24 16:00:00'},
  items:[{ticket_name_snapshot:'General',quantity:1}],
  payments:[{provider:'wompi',status:'approved',gross_amount_cop:85000,provider_reference:'REF-1',
    provider_transaction_id:'TX-1',created_at_utc:'2026-11-24 16:02:00'}],
  issue:{state:'done',attempts:1},entries:{total:1,used:0,first_issued_at:'2026-11-24 16:05:00'},
  mail:[{delivery_state:'failed',created_at:'2026-11-24 16:06:00'}],notes:[]};
async function launch(page,reply) {
  await page.route('**/discadmin/t18b-test.html',r=>r.fulfill({contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    +css+'</style><section data-admin-module="ticketing"><button data-ticketing-tab="orders" aria-selected="true">Pedidos</button><div id="panel"></div></section>'}));
  await page.route('**/discadmin/ticketing-orders.js',r=>r.fulfill({contentType:'text/javascript',body:script}));
  await page.route('**/discadmin/ticketing-orders.php*',r=>r.fulfill({contentType:'text/html',body:fragment}));
  await page.route('**/api/ticketing/admin-orders.php?*',r=>{
    const expanded=new URL(r.request().url()).searchParams.has('order_id');
    r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:
      expanded?{rows:[row],detail}:{rows:[row],kind:'orders',next_cursor:null,has_more:false}})});
  });
  await page.route('**/api/ticketing/admin-actions.php',reply);
  await page.goto('https://www.brvtal.com.co/discadmin/t18b-test.html');
  await page.evaluate(async()=>{
    const m=await import('/discadmin/ticketing-orders.js');
    await m.mount(document.querySelector('#panel'),'orders');
  });
  await page.getByRole('button',{name:'Detalle #91'}).click();
  await expect(page.locator('[data-ticketing-order-page]')).toBeVisible();
}
test('1979 C móvil: pasos, alerta, diálogo, nota con autor, CSRF',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const calls=[];
  await launch(page,r=>{
    calls.push({body:r.request().postDataJSON(),headers:r.request().headers()});
    detail.notes=[{note_text:'Revisión completada',actor_name:'Administración',created_at_utc:'2026-11-24 16:10:00'}];
    r.fulfill({contentType:'application/json',body:'{"ok":true,"data":{"status":"recorded"}}'});
  });
  await expect(page.locator('[data-ticketing-order-journey] li')).toHaveCount(5);
  await expect(page.locator('[data-ticketing-order-journey] li').nth(3)).toContainText('Requiere atención');
  await expect(page.locator('[data-ticketing-order-page]')).toContainText('Fallido');
  await expect(page.locator('[data-ticketing-order-page]')).toContainText('Boletas emitidas.');
  await expect(page.getByRole('button',{name:'Reintentar emisión ahora'})).toHaveCount(0);
  for(const label of ['Boletas','Pago','Notas']) await expect(page.getByRole('heading',{name:label,exact:true})).toBeVisible();
  await expect(page.getByText('simulación únicamente')).toHaveCount(0);
  expect(script).not.toContain('El correo con los QR sale en el siguiente minuto.');
  await page.screenshot({path:'test-results/order-C-390-before.png',fullPage:true});
  await page.getByRole('button',{name:'Agregar nota'}).click();
  await page.locator('[data-ticketing-note]').fill('Revisión completada');
  await page.getByRole('button',{name:'Volver'}).click();expect(calls).toHaveLength(0);
  await page.getByRole('button',{name:'Agregar nota'}).click();
  await page.locator('[data-ticketing-note]').fill('Revisión completada');
  await page.getByRole('button',{name:'Confirmar nota'}).click();
  await expect(page.locator('[data-ticketing-note-list]')).toContainText('Revisión completada');
  expect(calls).toHaveLength(1);
  expect(calls[0].headers['x-csrf-token']).toBe('test-csrf');
  expect(calls[0].body.action).toBe('note');
  expect(calls[0].body.idempotency_key).toMatch(/^[\w-]{20,64}$/);
  await page.screenshot({path:'test-results/order-C-390.png',fullPage:true});
});
test('1979 C escritorio: correo fallido permite reenvío, nunca cancelar pagado',async({page})=>{
  await page.setViewportSize({width:1440,height:900});
  const calls=[];await launch(page,r=>{
    calls.push(r.request().postDataJSON());
    r.fulfill({contentType:'application/json',body:'{"ok":true,"data":{"status":"queued","email_queued":true}}'});
  });
  await expect(page.getByText('REF-1')).toBeVisible();
  await page.screenshot({path:'test-results/order-C-1440-before.png',fullPage:true});
  await page.locator('.tk-order-stalled button').click();
  await page.getByRole('button',{name:'Confirmar reenvío'}).click();
  expect(calls).toHaveLength(1);expect(calls[0].action).toBe('resend');
  await expect(page.locator('[data-order-action="cancel_pending"]')).toHaveCount(0);
  await page.screenshot({path:'test-results/order-C-1440.png',fullPage:true});
});

test('1979 AC-02: cancelar pendiente exige confirmación y no toca pedidos pagados',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const originalStatus=row.status;
  const originalOrderStatus=detail.order.status;
  const originalPayment=detail.payments;
  const originalIssue=detail.issue;
  const originalMail=detail.mail;
  const calls=[];
  row.status='pending';
  detail.order.status='pending';
  detail.payments=[];
  detail.issue=null;
  detail.mail=[];
  try {
    await launch(page,r=>{
      calls.push({payload:r.request().postDataJSON(),csrf:r.request().headers()['x-csrf-token']});
      row.status='cancelled';
      detail.order.status='cancelled';
      r.fulfill({contentType:'application/json',
        body:'{"ok":true,"data":{"status":"cancelled","payment_changed":false}}'});
    });
    await page.locator('.tk-order-menu summary').click();
    await page.getByRole('button',{name:'Cancelar pedido pendiente'}).click();
    await expect(page.locator('dialog')).toBeVisible();
    await expect(page.locator('dialog')).toContainText('Se liberará su inventario');
    await page.getByRole('button',{name:'Volver'}).click();
    expect(calls).toHaveLength(0);
    await page.locator('.tk-order-menu summary').click();
    await page.getByRole('button',{name:'Cancelar pedido pendiente'}).click();
    await page.getByRole('button',{name:'Confirmar cancelación'}).click();
    await expect(page.locator('[data-ticketing-order-page]')).toContainText('Cancelado');
    expect(calls).toHaveLength(1);
    expect(calls[0].csrf).toBe('test-csrf');
    expect(calls[0].payload.action).toBe('cancel_pending');
    expect(calls[0].payload.order_id).toBe('91');
    await expect(page.locator('[data-order-action="cancel_pending"]')).toHaveCount(0);
  } finally {
    row.status=originalStatus;
    detail.order.status=originalOrderStatus;
    detail.payments=originalPayment;
    detail.issue=originalIssue;
    detail.mail=originalMail;
  }
});

test('1979 backend source safety contract',()=>{
  const api=readFileSync('api/ticketing/admin-actions.php','utf8');
  const core=readFileSync('config/ticketing_admin_actions.php','utf8');
  const orders=readFileSync('config/ticketing_admin_orders.php','utf8');
  expect(api).toContain('brvtal_admin_require_csrf()');
  expect(core).toContain('brvtalTicketAdminCanAction');
  expect(core).toContain('brvtal_ticket_mail_enqueue');
  expect(core).toContain('MAIL_PAYLOAD_NOT_RECOVERABLE');
  expect(core).not.toContain('curl_exec(');
  expect(core).not.toContain('random_bytes(32)');
  expect(orders).toContain('ticket_order_notes');
});
