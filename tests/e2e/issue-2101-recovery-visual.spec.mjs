import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';
const base=process.env.BRVTAL_RECOVERY_BASE_DIR, candidate=process.env.BRVTAL_RECOVERY_CANDIDATE_DIR;
const output=process.env.BRVTAL_RECOVERY_CAPTURE_DIR;
const fragment='<section data-ticketing-records data-ticketing-csrf="fixture-csrf">'
 +'<form data-ticketing-records-form><input type="hidden" name="kind" value="orders">'
 +'<input name="q"><input name="email"><input name="event_id"><select name="status"></select>'
 +'<input name="from"><input name="to"><button>Consultar</button></form>'
 +'<p role="status" data-ticketing-records-status></p><div data-ticketing-records-results></div>'
 +'<button data-ticketing-records-more hidden>Más</button><div data-ticketing-records-detail></div></section>';
const row={id:91,event_id:6,email:'buyer@example.test',status:'paid',total_cop:85000,units:1,approvals:1,last_payment_state:'approved',mail_state:'failed'};
const detail={order:{status:'paid',event_title:'Evento sintético',total_cop:85000,created_at_utc:'2026-11-24 16:00:00'},
 items:[{ticket_name_snapshot:'General',quantity:1}],payments:[{provider:'wompi',status:'approved',gross_amount_cop:85000,provider_reference:'FIXTURE-1',created_at_utc:'2026-11-24 16:02:00'}],
 issue:{state:'done',attempts:1},entries:{total:1,used:0,first_issued_at:'2026-11-24 16:05:00'},mail:[{delivery_state:'failed',created_at:'2026-11-24 16:06:00'}],notes:[]};
for(const width of [390,1440]) for(const stage of ['before','after']) {
 test('2101 '+stage+' '+width,async({page})=>{
  test.skip(!base||!candidate||!output,'Dedicated exact-source evidence job only');
  await page.setViewportSize({width,height:900});
  const source=stage==='before'?base:candidate;
  const js=readFileSync(join(source,'discadmin/ticketing-orders.js'),'utf8');
  const css=readFileSync(join(source,'discadmin/ticketing.css'),'utf8');
  await page.route('**/*',r=>r.abort());
  await page.route('**/discadmin/recovery-fixture.html',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style><section data-admin-module="ticketing"><button data-ticketing-tab="orders" aria-selected="true">Pedidos</button><main id="panel"></main></section>'}));
  await page.route('**/discadmin/ticketing-orders.js',r=>r.fulfill({contentType:'text/javascript',body:js}));
  await page.route('**/discadmin/ticketing-orders.php*',r=>r.fulfill({contentType:'text/html',body:fragment}));
  await page.route('**/api/ticketing/admin-orders.php?*',r=>r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:new URL(r.request().url()).searchParams.has('order_id')?{rows:[row],detail}:{rows:[row],kind:'orders',next_cursor:null,has_more:false}})}));
  let calls=0,release;
  const gate=new Promise(resolve=>{release=resolve;});
  await page.route('**/api/ticketing/admin-actions.php',async r=>{
   calls++; if(calls===1) await gate;
   await r.fulfill({status:calls===1?409:403,contentType:'application/json',body:JSON.stringify({ok:false,error:calls===1?'MAIL_PAYLOAD_NOT_RECOVERABLE':'FORBIDDEN'})});
  });
  try {
   await page.goto('https://www.brvtal.com.co/discadmin/recovery-fixture.html');
   await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-orders.js');await m.mount(document.querySelector('#panel'),'orders');});
   await page.getByRole('button',{name:'Detalle #91'}).click();
   await page.locator('.tk-order-stalled button').click();
   const dialog=page.locator('dialog'), confirm=page.getByRole('button',{name:'Confirmar reenvío'});
   await expect(dialog).toBeVisible();
   await expect(confirm).toBeFocused();
   if(stage==='after') await expect(dialog).toContainText('acceso a Mi cuenta');
   else await expect(dialog).not.toContainText('acceso a Mi cuenta');
   mkdirSync(output,{recursive:true});
   const shot=state=>page.screenshot({path:join(output,state+'-'+width+'.png'),fullPage:true,animations:'disabled'});
   await shot(stage);
   if(stage==='after'){
    await page.keyboard.press('Enter');
    await expect(dialog).toContainText('Guardando la acción');
    await expect(confirm).toBeDisabled(); await shot('loading');
    release();
    await expect(dialog).toContainText('No hay acceso recuperable'); await shot('error');
    await confirm.click(); await expect(dialog).toContainText('Sin permisos'); await shot('forbidden');
    await page.getByRole('button',{name:'Volver'}).focus(); await page.keyboard.press('Enter');
    await expect(dialog).not.toBeVisible(); expect(calls).toBe(2);
   }
  } finally { release(); }
 });
}
