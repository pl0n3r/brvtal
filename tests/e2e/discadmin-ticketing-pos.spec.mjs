import {test, expect} from '@playwright/test';
import {readFileSync,mkdirSync,writeFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';

const finance=readFileSync('discadmin/ticketing-finance.js','utf8');
// Exact pre-PR main is the real before image, not an invented mock-up.
const baselineSha='22921a146ae404be05236026a8bd79acc503beb7';
function baselineFinance() {
  const ref=baselineSha+':discadmin/ticketing-finance.js';
  try { return execFileSync('git',['show',ref],{encoding:'utf8',timeout:20000}); }
  catch {
    execFileSync('git',['fetch','--no-tags','--depth=1','origin',baselineSha],
      {timeout:60000});
    return execFileSync('git',['show',ref],{encoding:'utf8',timeout:20000});
  }
}
const financeBefore2033=baselineFinance();
const financePhp=readFileSync('discadmin/ticketing-finance.php','utf8');
const fragment=financePhp.slice(financePhp.indexOf('<section data-finance-module'));
const pos=readFileSync('discadmin/ticketing-pos.js','utf8');
const css=readFileSync('discadmin/ticketing.css','utf8');
const catalog={ok:true,events:[{id:6,title:'Genesis',ticket_types:[
  {id:10,name:'General'},{id:11,name:'VIP'}]}]};
const row={event_id:6,gross_cop:50000,discount_cop:0,approved_cop:50000,
  refund_cop:5000,refund_in_period_cop:5000,net_after_refunds_cop:45000,paid_orders:2,
  pending_orders:1,declined_orders:0,refunded_orders:0,tickets:2,capacity:100,checkins:1,
  fee_cop:2300,fee_vat_cop:437,withheld_cop:0,payout_cop:47263,difference_cop:2737,
  types:[{ticket_type_id:10,quantity:2,gross_cop:50000}],
  transactions:[{transaction_id:'TX-TEST',order_id:2}]};
const beforeHtml = '<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
  +'<body style="margin:0;background:#050505;color:#f4f5f6;font:14px system-ui;padding:16px">'
  +'<h2>Finanzas de boletería</h2><form>'
  +'<label>Evento <input type="number" placeholder="Todos"></label>'
  +'<label>Desde (UTC) <input type="date"></label><label>Hasta (UTC) <input type="date"></label></form>'
  +'<table><thead><tr>'
  +['Evento','Bruto','Descuentos','Aprobado','Pagos','Pendientes','Rechazados','Reembolsados',
    'Entradas','Cupos','Checkins','Comisión','IVA','Retenciones','Neto reportado','Diferencia teórica']
    .map(label=>'<th style="padding:12px;border:1px solid #333">'+label+'</th>').join('')
  +'</tr></thead><tbody><tr>'
  +Array(16).fill('<td style="padding:12px;border:1px solid #333">25.000 COP</td>').join('')
  +'</tr></tbody></table></body></html>';

async function setup(page, handler, rows=[], openCash=true, catalogMode='api',
    financeSource=finance,posSource=pos) {
  await page.route('**/discadmin/pos-test.html',route=>route.fulfill({
    contentType:'text/html',body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
      +'<body style="margin:0;background:#050505;color:#f4f5f6;font-family:system-ui">'
      +'<section class="ticketing" data-admin-module="ticketing" style="padding:16px">'
      +'<button data-ticketing-tab="finance" aria-selected="true">Finanzas</button>'
      +'<div id="panel" role="tabpanel"></div></section></body></html>'}));
  await page.route('**/discadmin/ticketing-finance.js',route=>route.fulfill({
    contentType:'text/javascript',body:financeSource}));
  await page.route('**/discadmin/ticketing-pos.js',route=>route.fulfill({
    contentType:'text/javascript',body:posSource}));
  await page.route('**/discadmin/ticketing-finance.php',route=>route.fulfill({
    contentType:'text/html',body:catalogMode==='embedded' ? fragment.replace(/<script type="application\/json" data-finance-catalog>[\s\S]*?<\/script>/,
      '<script type="application/json" data-finance-catalog>'+JSON.stringify(catalog)+'</script>') : fragment}));
  await page.route('**/discadmin/ticketing-orders.php',route=>route.fulfill({
    contentType:'text/html',body:'<section data-ticketing-records data-ticketing-csrf="'+ 'a'.repeat(64) +'"></section>'}));
  await page.route('**/api/ticketing/admin-promos.php?catalog=1',route=>route.fulfill({
    status:catalogMode==='embedded'?403:200,contentType:'application/json',
    body:catalogMode==='embedded'?'{"ok":false,"error":"FORBIDDEN"}':JSON.stringify(catalog)}));
  await page.route('**/api/ticketing/admin-finances.php?*',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify({ok:true,rows})}));
  await page.route('**/api/ticketing/admin-pos.php',handler);
  await page.goto('https://www.brvtal.com.co/discadmin/pos-test.html');
  await page.addStyleTag({content:css});
  await page.evaluate(async()=>{
    const m=await import('/discadmin/ticketing-finance.js');
    await m.mount(document.querySelector('#panel'));
  });
  await expect(page.locator('[data-finance-module]')).toBeVisible();
  if (openCash) {
    await page.getByRole('button',{name:'Caja',exact:true}).click();
    await expect(page.locator('[data-pos-module]')).toBeVisible();
    await expect(page.locator('[data-pos-module] [name="event_id"] option')).toHaveCount(2);
  }
}

test('T24 móvil: caja requiere dos confirmaciones sin cobro del proveedor',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const calls=[];
  await setup(page,route=>{
    const body=route.request().postDataJSON();calls.push({body,headers:route.request().headers()});
    const data=body.action==='open'?{shift_id:3,status:'open'}:
      body.action==='prepare'?{sale_id:7,status:'prepared',amount_cop:25000,
        confirmation_token:'b'.repeat(48),replayed:false}:
      {sale_id:7,status:'paid',receipt:'POS-7',tickets:['BRVTAL:T1:synthetic-token'],amount_cop:25000};
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data})});
  });
  const section=page.locator('[data-pos-module]');
  await expect(section).toContainText('Transferencias y QR requieren verificación independiente');
  await expect(section.locator('[data-pos-sale] option[value="transfer"]')).toHaveAttribute('disabled','');
  await expect(section.locator('[data-pos-sale] option[value="wompi_qr"]')).toHaveAttribute('disabled','');
  expect(calls).toHaveLength(0);
  await section.locator('[name="event_id"]').selectOption('6');
  await section.getByRole('button',{name:'Abrir caja',exact:true}).click();
  await expect(section.getByRole('button',{name:'Preparar venta'})).toBeVisible();
  await section.locator('[name="ticket_type_id"]').selectOption('10');
  await section.getByRole('button',{name:'Preparar venta'}).click();
  await expect(section.getByRole('button',{name:'Confirmar registro'})).toBeVisible();
  expect(calls.map(x=>x.body.action)).toEqual(['open','prepare']);
  await section.getByRole('button',{name:'Confirmar registro'}).click();
  await expect(section).toContainText('POS-7');
  expect(calls.map(x=>x.body.action)).toEqual(['open','prepare','confirm']);
  expect(calls.at(-1).body.confirm).toBe(true);
  expect(calls.at(-1).headers['x-csrf-token']).toBe('a'.repeat(64));
  expect(calls.filter(x=>x.body.method==='wompi_qr'||x.body.method==='transfer')).toHaveLength(0);
});

test('T24 escritorio: cancelar preparación no confirma ni emite',async({page})=>{
  await page.setViewportSize({width:1440,height:900});
  const actions=[];
  await setup(page,route=>{
    const b=route.request().postDataJSON();actions.push(b.action);
    const data=b.action==='open'?{shift_id:3,status:'open'}:
      b.action==='prepare'?{sale_id:9,amount_cop:25000,status:'prepared',
        confirmation_token:'b'.repeat(48)}:{sale_id:9,status:'cancelled',tickets:[]};
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data})});
  });
  const section=page.locator('[data-pos-module]');
  await section.locator('[name="event_id"]').selectOption('6');
  await section.getByRole('button',{name:'Abrir caja',exact:true}).click();
  await section.locator('[name="ticket_type_id"]').selectOption('10');
  await section.getByRole('button',{name:'Preparar venta'}).click();
  await section.getByRole('button',{name:'Cancelar preparación'}).click();
  await expect(section).toContainText('anulada');
  expect(actions).toEqual(['open','prepare','cancel']);
  await expect(section.locator('[data-pos-receipt]')).toBeEmpty();
});

test('T24 respuesta denegada no crea recibo local',async({page})=>{
  await setup(page,route=>route.fulfill({status:403,contentType:'application/json',
    body:'{"ok":false,"error":"POS_SYNTHETIC_ONLY"}'}));
  const section=page.locator('[data-pos-module]');
  await section.locator('[name="event_id"]').selectOption('6');
  await section.getByRole('button',{name:'Abrir caja',exact:true}).click();
  await expect(section).toContainText('Operación no disponible');
  await expect(section.getByRole('button',{name:'Preparar venta'})).toBeHidden();
  await expect(section.locator('[data-pos-receipt]')).toBeEmpty();
});

for (const width of [390,1440]) {
  test('AC-01 Finanzas antes/después, sin desborde a '+width+' px',async({page},testInfo)=>{
    await page.setViewportSize({width,height:900});
    await page.route('**/discadmin/finance-before.html',route=>route.fulfill({
      contentType:'text/html',body:beforeHtml}));
    await page.goto('https://www.brvtal.com.co/discadmin/finance-before.html');
    await testInfo.attach('finanzas-antes-'+width+'.png',{body:await page.screenshot({fullPage:true}),
      contentType:'image/png'});
    await setup(page,route=>route.fulfill({status:403,contentType:'application/json',
      body:'{"ok":false}'}),[row],false);
    const financeRoot=page.locator('[data-finance-module]');
    await expect(financeRoot.locator('.tk-form')).toBeVisible();
    await expect(financeRoot.locator('table.tk-table thead th')).toHaveCount(7);
    await expect(financeRoot.locator('details')).toHaveCount(1);
    await expect(financeRoot.locator('[name="event_id"] option')).toHaveCount(2);
    await expect(financeRoot).not.toContainText(/sintético|pruebas|UTC/i);
    await expect(page.getByRole('button',{name:'Caja',exact:true})).toBeVisible();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await testInfo.attach('finanzas-despues-'+width+'.png',{body:await page.screenshot({fullPage:true}),
      contentType:'image/png'});
  });
}

test('AC-01: rol admin con finanzas pero sin settings conserva selectores POS',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const requests=[];
  await setup(page,route=>{
    requests.push(route.request().postDataJSON());
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{
      shift_id:3,status:'open'}})});
  },[],true,'embedded');
  const section=page.locator('[data-pos-module]');
  await expect(section.locator('[name="event_id"] option')).toHaveText(['Elige un evento','Genesis']);
  await section.locator('[name="event_id"]').selectOption('6');
  await expect(section.locator('[name="ticket_type_id"] option')).toHaveText(['Elige una boleta','General','VIP']);
  await section.getByRole('button',{name:'Abrir caja',exact:true}).click();
  await expect(section).toContainText('Caja abierta');
  expect(requests).toHaveLength(1);
  expect(requests[0].action).toBe('open');
  const php=readFileSync('discadmin/ticketing-finance.php','utf8');
  expect(php).toContain("brvtalTicketingCan((string)($q->fetchColumn() ?: ''),'finance')");
  // #2022: every canonical selling state, not only 'published'.
  expect(php).toContain("brvtal_public_event_statuses()['active']");
  expect(php).not.toContain("status='published'");
  expect(php).toContain('data-finance-catalog');
});

function quoteFixture(route,request,override={}){
  const expiry=Math.floor(Date.now()/1000)+120;
  const q={channel:'manual',unit_price_cop:25000,amount_cop:25000*Number(request.quantity),
    source:'stage',stage_code:'preventa',expires_at_utc:new Date(expiry*1000).toISOString().replace('.000Z','Z'),
    quote_token:expiry+'.'+Number(override.unit_price_cop??25000).toString(16).padStart(63,'0')
      +Number(request.quantity).toString(16),...override};
  return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:q})});
}

// The established POS visual workflow uploads this directory. Preserve its
// historical four-file contract and keep this issue's evidence separate.
async function quoteCapture(page,testInfo,phase,width){
  const png=await page.screenshot({fullPage:true});
  expect(png.subarray(0,8)).toEqual(Buffer.from([137,80,78,71,13,10,26,10]));
  expect(png.readUInt32BE(16)).toBe(width);
  expect(png.readUInt32BE(20)).toBeGreaterThanOrEqual(400);
  const dir='artifacts/issue-2017/issue-2286';
  mkdirSync(dir,{recursive:true});
  const name='2286-'+phase+'-'+width+'.png';
  writeFileSync(dir+'/'+name,png);
  await testInfo.attach(name,{body:png,contentType:'image/png'});
}

async function fillManualQuote(form,quantity='2'){
  await form.locator('[name="manual_event_id"]').selectOption('6');
  await form.locator('[name="manual_ticket_type_id"]').selectOption('10');
  await form.locator('[name="manual_quantity"]').fill(quantity);
  await form.locator('[name="manual_buyer_name"]').fill('Comprador Ejemplo');
  await form.locator('[name="manual_buyer_email"]').fill('manual@example.test');
  await form.locator('[name="manual_method"]').selectOption('nequi');
  await form.locator('[name="manual_payment_reference"]').fill('REF-2026-001');
  await form.locator('[name="manual_confirm"]').check();
}

for (const width of [390,1440]) {
  test('AC-02 pedido offline owner, capturas antes/después '+width+' px',async({page},testInfo)=>{
    await page.setViewportSize({width,height:900});
    const calls=[];
    await setup(page,route=>{
      const request=route.request();
      const body=request.postDataJSON();calls.push({body,headers:request.headers()});
      if(body.action==='manual_quote')return quoteFixture(route,body);
      return route.fulfill({contentType:'application/json',body:JSON.stringify({
        ok:true,data:{order_id:123,sale_id:44,status:'paid',
          amount_cop:50000,method:'nequi',issuance_queued:true,replayed:false}
      })});
    });
    const manual=page.locator('[data-pos-manual]');
    await expect(manual).toBeVisible();
    // Baseline is the same mounted Caja with the new module hidden, not
    // a fake external website or an invented production screenshot.
    await manual.evaluate(el=>{el.style.display='none';});
    await testInfo.attach('manual-baseline-'+width+'.png',
      {body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await manual.evaluate(el=>{el.style.display='';});
    const form=manual.locator('[data-pos-manual-form]');
    await fillManualQuote(form);
    // Same reviewed form on the exact pre-change source, with synthetic data only.
    const beforeRef='3556cf05e99db8044ce71acbfcb08ea0776aba0a';
    const ref=beforeRef+':discadmin/ticketing-pos.js';
    try{execFileSync('git',['cat-file','-e',ref]);}
    catch{execFileSync('git',['fetch','--no-tags','--depth=1','origin',beforeRef],{timeout:60000});}
    const baseline=await page.context().newPage();
    await baseline.setViewportSize({width,height:900});
    await setup(baseline,route=>route.abort('failed'),[],true,'api',finance,
      execFileSync('git',['show',ref],{encoding:'utf8'}));
    const beforeForm=baseline.locator('[data-pos-manual-form]');
    await fillManualQuote(beforeForm);
    await beforeForm.getByRole('button',{name:'Revisar pedido manual'}).click();
    await expect(beforeForm.locator('[data-pos-manual-preview]')).toContainText('Comprador Ejemplo');
    await quoteCapture(baseline,testInfo,'before',width);
    await baseline.close();
    await expect(form.getByRole('button',{name:'Revisar pedido manual'})).toBeEnabled();
    expect(calls).toHaveLength(0);
    await form.getByRole('button',{name:'Revisar pedido manual'}).click();
    await expect(form.locator('[data-pos-manual-preview]')).toContainText('Comprador Ejemplo');
    await expect(form.locator('[data-pos-manual-preview]')).toContainText('REF-2026-001');
    await expect(form.locator('[data-pos-manual-preview]')).toContainText('Total a registrar: 50.000 COP');
    await expect(form.locator('[data-pos-manual-preview]')).toContainText('Precio unitario: 25.000 COP');
    await expect(form.locator('[data-pos-manual-preview]')).toContainText('Etapa Preventa');
    await expect(form.locator('[data-pos-manual-preview]')).toContainText('Válida hasta');
    expect(calls).toHaveLength(1);
    expect(calls[0].body).toEqual({action:'manual_quote',event_id:'6',ticket_type_id:'10',quantity:'2',
      idempotency_key:expect.stringMatching(/^[a-f0-9]{32}$/)});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    const summaryBox=await form.locator('[data-pos-manual-preview]').boundingBox();
    const confirmBox=await form.getByRole('button',{name:'Confirmar y crear pedido'}).boundingBox();
    expect(summaryBox).not.toBeNull();expect(confirmBox).not.toBeNull();
    expect(confirmBox.y).toBeGreaterThanOrEqual(summaryBox.y+summaryBox.height);
    expect(confirmBox.height).toBeGreaterThanOrEqual(44);
    expect(confirmBox.height).toBeLessThanOrEqual(80);
    await quoteCapture(page,testInfo,'after',width);
    writeFileSync('artifacts/issue-2017/issue-2286/manifest-'+width+'.json',JSON.stringify({
      issue:2286,before_sha:beforeRef,after_head_sha:process.env.ISSUE_2017_PR_HEAD_SHA??null,
      viewport_width:width,synthetic_fixture:true,source:'actual Playwright Chromium',
      captures:['2286-before-'+width+'.png','2286-after-'+width+'.png']
    },null,2));
    await form.getByRole('button',{name:'Confirmar y crear pedido'}).click();
    await expect(manual).toContainText('Pedido #123 creado');
    await expect(manual).toContainText('emisión de QR en cola');
    expect(calls).toHaveLength(2);
    expect(calls[1].body).toMatchObject({
      action:'manual_order',event_id:'6',ticket_type_id:'10',quantity:'2',
      buyer_name:'Comprador Ejemplo',buyer_email:'manual@example.test',
      method:'nequi',payment_reference:'REF-2026-001',confirm:true
    });
    expect(calls[1].body.idempotency_key).toBe(calls[0].body.idempotency_key);
    expect(calls[1].body.quote_token).toMatch(/^\d{10}\.[a-f0-9]{64}$/);
    expect(calls[1].body).not.toHaveProperty('amount_cop');
    expect(calls[1].headers['x-csrf-token']).toBe('a'.repeat(64));
    await expect(form.getByRole('button',{name:'Confirmar y crear pedido'})).toBeDisabled();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await testInfo.attach('manual-created-'+width+'.png',
      {body:await page.screenshot({fullPage:true}),contentType:'image/png'});
  });
}

test('AC-02 pago no efectivo exige referencia, error de aforo no inventa pedido',async({page})=>{
  const calls=[];
  await setup(page,route=>{
    const body=route.request().postDataJSON();calls.push(body);
    if(body.action==='manual_quote')return quoteFixture(route,body);
    return route.fulfill({status:409,contentType:'application/json',
      body:'{"ok":false,"error":"INVENTORY_SOLD_OUT"}'});
  });
  const form=page.locator('[data-pos-manual-form]');
  await form.locator('[name="manual_event_id"]').selectOption('6');
  await form.locator('[name="manual_ticket_type_id"]').selectOption('10');
  await form.locator('[name="manual_buyer_name"]').fill('Comprador Ejemplo');
  await form.locator('[name="manual_buyer_email"]').fill('manual@example.test');
  await form.locator('[name="manual_method"]').selectOption('transfer');
  await form.locator('[name="manual_confirm"]').check();
  await expect(form.locator('[name="manual_payment_reference"]')).toHaveAttribute('required','');
  await form.getByRole('button',{name:'Revisar pedido manual'}).click();
  expect(calls).toHaveLength(0);
  await form.locator('[name="manual_payment_reference"]').fill('BANCO-00012');
  await form.getByRole('button',{name:'Revisar pedido manual'}).click();
  await expect(form.locator('[data-pos-manual-preview]')).toContainText('BANCO-00012');
  expect(calls).toHaveLength(1);
  await form.getByRole('button',{name:'Confirmar y crear pedido'}).click();
  await expect(page.locator('[data-pos-manual]')).toContainText('No hay cupo suficiente');
  expect(calls.map(x=>x.action)).toEqual(['manual_quote','manual_order']);
  await expect(form.getByRole('button',{name:'Revisar pedido manual'})).toBeEnabled();
  await expect(form.locator('[data-pos-manual-preview]')).toBeEmpty();
  await expect(page.locator('[data-pos-manual-receipt]')).toBeEmpty();
});

test('AC-02 servidor rechaza admin sin revelar QR o pedido',async({page})=>{
  await setup(page,route=>route.fulfill({status:403,contentType:'application/json',
    body:'{"ok":false,"error":"FORBIDDEN"}'}));
  const form=page.locator('[data-pos-manual-form]');
  await form.locator('[name="manual_event_id"]').selectOption('6');
  await form.locator('[name="manual_ticket_type_id"]').selectOption('10');
  await form.locator('[name="manual_buyer_name"]').fill('Comprador Ejemplo');
  await form.locator('[name="manual_buyer_email"]').fill('manual@example.test');
  await form.locator('[name="manual_method"]').selectOption('cash');
  await expect(form.locator('[name="manual_payment_reference"]')).toBeDisabled();
  await form.locator('[name="manual_confirm"]').check();
  await form.getByRole('button',{name:'Revisar pedido manual'}).click();
  await expect(page.locator('[data-pos-manual]')).toContainText('Solo el propietario');
  await expect(page.locator('[data-pos-manual-preview]')).toBeEmpty();
  await expect(form.getByRole('button',{name:'Confirmar y crear pedido'})).toHaveCount(0);
  await expect(page.locator('[data-pos-manual-receipt]')).toBeEmpty();
});

test('AC-02 edición posterior al resumen exige una revisión nueva',async({page})=>{
  const calls=[];
  await setup(page,route=>{
    const body=route.request().postDataJSON();calls.push(body);
    if(body.action==='manual_quote')return quoteFixture(route,body);
    return route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,data:{order_id:124,status:'paid',amount_cop:75000,method:'nequi',
        issuance_queued:true,replayed:false}
    })});
  });
  const form=page.locator('[data-pos-manual-form]');
  await form.locator('[name="manual_event_id"]').selectOption('6');
  await form.locator('[name="manual_ticket_type_id"]').selectOption('10');
  await form.locator('[name="manual_buyer_name"]').fill('Comprador Ejemplo');
  await form.locator('[name="manual_buyer_email"]').fill('manual@example.test');
  await form.locator('[name="manual_method"]').selectOption('nequi');
  await form.locator('[name="manual_payment_reference"]').fill('REF-2026-001');
  await form.locator('[name="manual_confirm"]').check();
  await form.getByRole('button',{name:'Revisar pedido manual'}).click();
  await expect(form.locator('[data-pos-manual-preview]')).toContainText('1 boleta(s)');
  await form.locator('[name="manual_quantity"]').fill('3');
  await expect(form.locator('[data-pos-manual-preview]')).toBeEmpty();
  await expect(form.getByRole('button',{name:'Revisar pedido manual'})).toBeVisible();
  expect(calls.map(x=>x.action)).toEqual(['manual_quote']);
  await form.getByRole('button',{name:'Revisar pedido manual'}).click();
  await expect(form.locator('[data-pos-manual-preview]')).toContainText('3 boleta(s)');
  await form.getByRole('button',{name:'Confirmar y crear pedido'}).click();
  await expect(page.locator('[data-pos-manual]')).toContainText('Pedido #124 creado');
  expect(calls.map(x=>x.action)).toEqual(['manual_quote','manual_quote','manual_order']);
  expect(calls[2].quantity).toBe('3');
  expect(calls[2].quote_token).toMatch(/3$/);
  expect(calls.every(x=>x.idempotency_key===calls[0].idempotency_key)).toBeTruthy();
});


for (const width of [390,1440]) {
  test('AC-03 #2033: capturas reales de fuente exact-main y HEAD '+width+' px',
    async({page},testInfo)=>{
      await page.setViewportSize({width,height:900});
      for (const [phase,source] of [
        ['before',financeBefore2033],['after',finance]
      ]) {
        await setup(page,route=>route.fulfill({status:403,contentType:'application/json',
          body:'{"ok":false,"error":"FORBIDDEN"}'}),[row],false,'api',source);
        const root=page.locator('[data-finance-module]');
        await expect(root).toBeVisible();
        await expect(root.locator('table.tk-table thead th'))
          .toHaveCount(phase==='before'?5:7);
        // Keep the comparison in the same expanded operational state.
        await root.locator('details summary').first().click();
        if(phase==='after'){
          await expect(root).toContainText('Devuelto');
          await expect(root).toContainText('Neto tras devolución');
          await expect(root).toContainText('5.000');
          await expect(root).toContainText('45.000');
          await expect(root.getByText('Sin dato').first()).toBeVisible();
        } else {
          await expect(root).not.toContainText('Neto tras devolución');
        }
        expect(await page.evaluate(()=>document.documentElement.scrollWidth))
          .toBeLessThanOrEqual(width);
        const visualPath='artifacts/issue-2033';
        mkdirSync(visualPath,{recursive:true});
        const fileName=phase+'-'+width+'.png';
        const png=await page.screenshot({fullPage:true});
        writeFileSync(visualPath+'/'+fileName,png);
        await testInfo.attach('issue2033-'+fileName,{
          body:png,contentType:'image/png'
        });
        if (width===1440 && phase==='after') {
          writeFileSync(visualPath+'/manifest.json',JSON.stringify({
            issue:2033,pr:2141,before_sha:baselineSha,
            after_source:'PR head SHA named on GitHub Actions artifact',
            screenshot_files:['before-390.png','after-390.png','before-1440.png','after-1440.png'],
            browser:'Playwright Chromium',synthetic_fixture:true,
            production_observed:false,real_personal_data:false,
            actual_browser:!!page.context().browser()
          },null,2));
        }
      }
  });
}

for (const width of [390,1440]) {
  test('2124 pérdida de respuesta y recuperación sin venta repetida '+width,async({page},testInfo)=>{
    await page.setViewportSize({width,height:900});
    const calls=[];let committed=false,available=false,denied=false;
    const handler=async route=>{
      const request=route.request().postDataJSON();calls.push(request);
      if(request.action==='manual_quote'){await quoteFixture(route,request);return;}
      if(request.action==='manual_order'){committed=true;await route.abort('failed');return;}
      expect(request.action).toBe('manual_intent');
      if(denied){await route.fulfill({status:403,contentType:'application/json',body:'{"ok":false,"error":"FORBIDDEN"}'});return;}
      if(!available){await route.fulfill({status:503,contentType:'application/json',body:'{"ok":false,"error":"POS_UNAVAILABLE"}'});return;}
      expect(committed).toBeTruthy();
      await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{
        state:'found',order_id:123,amount_cop:50000,method:'cash',status:'paid',issuance:'issued'}})});
    };
    const beforeRef='3e31c526319b6cdc2cccdefdd2f13c83976c4c71';
    try {execFileSync('git',['cat-file','-e',beforeRef+':discadmin/ticketing-pos.js']);}
    catch {execFileSync('git',['fetch','--no-tags','--depth=1','origin',beforeRef],{timeout:60000});}
    const beforeSource=execFileSync('git',['show',beforeRef+':discadmin/ticketing-pos.js'],{encoding:'utf8'});
    const baseline=await page.context().newPage();
    await baseline.setViewportSize({width,height:900});
    await setup(baseline,handler,[],true,'api',finance,beforeSource);
    await testInfo.attach('2124-before-'+width+'.png',{body:await baseline.screenshot({fullPage:true}),contentType:'image/png'});
    await baseline.close();
    await setup(page,handler);
    const manual=page.locator('[data-pos-manual]');
    const form=manual.locator('form');
    await form.locator('[name="manual_event_id"]').selectOption('6');
    await form.locator('[name="manual_ticket_type_id"]').selectOption('10');
    await form.locator('[name="manual_buyer_name"]').fill('Comprador Ejemplo');
    await form.locator('[name="manual_buyer_email"]').fill('manual@example.test');
    await form.locator('[name="manual_confirm"]').check();
    await form.getByRole('button',{name:'Revisar pedido manual'}).click();
    await form.getByRole('button',{name:'Confirmar y crear pedido'}).click();
    await expect(manual).toContainText('No se pudo verificar el recibo');
    await expect(form.getByRole('button',{name:'Confirmar y crear pedido'})).toBeDisabled();
    await testInfo.attach('2124-uncertain-'+width+'.png',{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    const intent=calls.find(x=>x.action==='manual_order').idempotency_key;
    expect(await page.evaluate(()=>sessionStorage.getItem('brvtal.pos.manual.intent.v1'))).toBe(intent);
    denied=true;
    const lookup=manual.getByRole('button',{name:'Consultar recibo pendiente'});
    await lookup.focus();await expect(lookup).toBeFocused();await page.keyboard.press('Enter');
    await expect(manual).toContainText('Solo el propietario puede consultar este recibo');
    await expect(form.getByRole('button',{name:'Confirmar y crear pedido'})).toBeDisabled();
    await testInfo.attach('2124-denied-'+width+'.png',{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    denied=false;available=true;
    await page.reload();
    await page.addStyleTag({content:css});
    await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-finance.js');await m.mount(document.querySelector('#panel'));});
    await page.getByRole('button',{name:'Caja',exact:true}).click();
    await expect(page.locator('[data-pos-manual]')).toContainText('Pedido #123 recuperado');
    expect(calls.filter(x=>x.action==='manual_order')).toHaveLength(1);
    expect(calls.filter(x=>x.action==='manual_intent').every(x=>x.idempotency_key===intent&&Object.keys(x).length===2)).toBeTruthy();
    await expect(page.locator('[data-pos-manual]')).toContainText('entrega por correo no verificada');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await testInfo.attach('2124-recovered-'+width+'.png',{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
  });
}

test('2286 conflicto de tarifa obliga a nueva cotización y clic explícito; replay conserva recibo',async({page})=>{
  const calls=[];let quotes=0,orders=0;
  await setup(page,route=>{
    const body=route.request().postDataJSON();calls.push(body);
    if(body.action==='manual_quote'){
      quotes++;
      return quoteFixture(route,body,quotes===1?{}:{unit_price_cop:30000,amount_cop:60000,stage_code:'general'});
    }
    expect(body.action).toBe('manual_order');orders++;
    if(orders===1)return route.fulfill({status:409,contentType:'application/json',
      body:'{"ok":false,"error":"POS_QUOTE_CHANGED"}'});
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{
      order_id:125,status:'paid',amount_cop:50000,method:'nequi',replayed:true,issuance:'issued'}})});
  });
  const form=page.locator('[data-pos-manual-form]');
  await fillManualQuote(form);
  await form.getByRole('button',{name:'Revisar pedido manual'}).click();
  await expect(form.locator('[data-pos-manual-preview]')).toContainText('50.000 COP');
  await form.getByRole('button',{name:'Confirmar y crear pedido'}).click();
  await expect(page.locator('[data-pos-manual]')).toContainText('Revisa una cotización nueva');
  await expect(form.locator('[data-pos-manual-preview]')).toBeEmpty();
  expect(calls.map(x=>x.action)).toEqual(['manual_quote','manual_order']);
  await form.getByRole('button',{name:'Revisar pedido manual'}).click();
  await expect(form.locator('[data-pos-manual-preview]')).toContainText('60.000 COP');
  await expect(form.locator('[data-pos-manual-preview]')).toContainText('Etapa General');
  expect(orders).toBe(1);
  await form.getByRole('button',{name:'Confirmar y crear pedido'}).click();
  await expect(page.locator('[data-pos-manual]')).toContainText('Pedido #125 recuperado');
  await expect(page.locator('[data-pos-manual-receipt]')).toContainText('50000 COP');
  await expect(form.locator('[data-pos-manual-preview]')).toBeEmpty();
  expect(calls[1].quote_token).not.toBe(calls[3].quote_token);
  expect(calls.map(x=>x.action)).toEqual(['manual_quote','manual_order','manual_quote','manual_order']);
  expect(calls.every(x=>x.idempotency_key===calls[0].idempotency_key)).toBeTruthy();
});

test('2286 cotización vencida antes del clic nunca envía manual_order',async({page})=>{
  const calls=[];
  await setup(page,route=>{
    const body=route.request().postDataJSON();calls.push(body);
    expect(body.action).toBe('manual_quote');
    return quoteFixture(route,body);
  });
  const form=page.locator('[data-pos-manual-form]');
  await fillManualQuote(form);
  await form.getByRole('button',{name:'Revisar pedido manual'}).click();
  await expect(form.getByRole('button',{name:'Confirmar y crear pedido'})).toBeVisible();
  await page.clock.setFixedTime(new Date(Date.now()+180000));
  await form.getByRole('button',{name:'Confirmar y crear pedido'}).click();
  await expect(page.locator('[data-pos-manual]')).toContainText('La cotización venció');
  await expect(form.locator('[data-pos-manual-preview]')).toBeEmpty();
  expect(calls.map(x=>x.action)).toEqual(['manual_quote']);
});

test('2286 respuesta de cotización tardía no confirma datos editados',async({page})=>{
  let release,received=false;
  const delayed=new Promise(resolve=>{release=resolve;});
  await setup(page,async route=>{
    const body=route.request().postDataJSON();expect(body.action).toBe('manual_quote');
    received=true;await delayed;return quoteFixture(route,body);
  });
  const form=page.locator('[data-pos-manual-form]');
  await fillManualQuote(form);
  await form.getByRole('button',{name:'Revisar pedido manual'}).click();
  await expect.poll(()=>received).toBeTruthy();
  await form.locator('[name="manual_quantity"]').fill('3');
  release();
  await expect(page.locator('[data-pos-manual]')).toContainText('Los datos cambiaron');
  await expect(form.locator('[data-pos-manual-preview]')).toBeEmpty();
  await expect(form.getByRole('button',{name:'Revisar pedido manual'})).toBeEnabled();
});

test('2286 respuesta inválida no habilita confirmación; tarifa base explícita',async({page})=>{
  const calls=[];
  const responses=[{amount_cop:1},{quote_token:''},{source:'door'},
    {source:'base',stage_code:null}];
  await setup(page,route=>{
    const body=route.request().postDataJSON();calls.push(body.action);
    expect(body.action).toBe('manual_quote');
    return quoteFixture(route,body,responses.shift());
  });
  const form=page.locator('[data-pos-manual-form]');
  await fillManualQuote(form);
  for(let i=0;i<3;i++){
    await form.getByRole('button',{name:'Revisar pedido manual'}).click();
    await expect(page.locator('[data-pos-manual]')).toContainText('No hay cotización válida');
    await expect(form.locator('[data-pos-manual-preview]')).toBeEmpty();
    await expect(form.getByRole('button',{name:'Confirmar y crear pedido'})).toHaveCount(0);
  }
  await form.getByRole('button',{name:'Revisar pedido manual'}).click();
  await expect(form.locator('[data-pos-manual-preview]')).toContainText('Precio base');
  expect(calls).toEqual(Array(4).fill('manual_quote'));
});
