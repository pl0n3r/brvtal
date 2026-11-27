import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';
import {execFileSync} from 'node:child_process';

const html=readFileSync(join(process.cwd(),'buyer/index.php'),'utf8');
const url='http://127.0.0.1:4173/buyer/index.php';
const order={public_id:'a'.repeat(32),event_title:'Prueba sintética',status:'paid',refund_cause:'cancelled',total_cop:25000,currency:'COP',items:[
  {name:'Disponible',ticket_id:'b'.repeat(32),status:'issued'},
  {name:'Utilizada',ticket_id:'c'.repeat(32),status:'used'}
]};
test('T21 comprador solicita devolución parcial sin incluir entradas usadas ni duplicar',async({page})=>{
  const posts=[];let requests=0;
  await page.route(url,route=>route.fulfill({contentType:'text/html',body:html}));
  await page.route('**/api/buyer/auth.php',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify({authenticated:true,csrf:'csrf-sintetico'})
  }));
  await page.route('**/api/buyer/account.php**',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify({data:{orders:[order],has_more:false}})
  }));
  await page.route('**/api/buyer/refunds.php',route=>{
    if(route.request().method()==='POST'){
      posts.push({body:route.request().postDataJSON(),headers:route.request().headers()});
      return route.fulfill({status:requests++===0?503:201,contentType:'application/json',
        body:JSON.stringify({ok:true,data:{id:1,status:'requested'}})});
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify({data:[]})});
  });
  await page.goto(url);
  const form=page.getByRole('form',{name:'Solicitar devolución'});
  await expect(form).toBeVisible();
  await expect(form.getByRole('checkbox')).toHaveCount(1);
  await form.getByRole('checkbox').check();
  await form.getByLabel('Motivo de la devolución').fill('Motivo sintético sin datos personales');
  await form.getByRole('button',{name:'Enviar solicitud'}).click();
  await expect(form.getByRole('status')).toContainText('No se confirmó');
  await form.getByRole('button',{name:'Enviar solicitud'}).click();
  await expect(form.getByRole('status')).toContainText('Solicitud registrada');
  expect(posts).toHaveLength(2);
  expect(posts[0].body.entry_ids).toEqual(['b'.repeat(32)]);
  expect(posts[1].body.idempotency_key).toBe(posts[0].body.idempotency_key);
  expect(posts[0].headers['x-csrf-token']).toBe('csrf-sintetico');
});


test('Política A: una compra sin incidente no ofrece devolución',async({page})=>{
  await page.route(url,r=>r.fulfill({contentType:'text/html',body:html}));
  await page.route('**/api/buyer/auth.php',r=>r.fulfill({
    contentType:'application/json',body:JSON.stringify({authenticated:true,csrf:'csrf-sintetico'})}));
  await page.route('**/api/buyer/account.php**',r=>r.fulfill({
    contentType:'application/json',body:JSON.stringify({data:{orders:[{...order,refund_cause:null}],has_more:false}})}));
  await page.route('**/api/buyer/refunds.php',r=>r.fulfill({
    contentType:'application/json',body:JSON.stringify({data:[]})}));
  await page.goto(url);
  await expect(page.getByRole('form',{name:'Solicitar devolución'})).toHaveCount(0);
  await expect(page.getByText(/Las boletas no tienen devolución, salvo cancelación/)).toBeVisible();
  await expect(page.getByRole('link',{name:'Ver términos y condiciones'})).toHaveAttribute('href','/terms');
});

test('Administradores solo consultan, sin confirmar reembolsos',async({page})=>{
  const url='http://127.0.0.1:4173/discadmin/refunds-read-only';
  await page.route(url,r=>r.fulfill({contentType:'text/html',body:
    '<div id="refund-panel"></div><script type="module">import {mount} from "/discadmin/ticketing-refunds.js";mount(document.getElementById("refund-panel"),"csrf-synthetic");</script>'}));
  const module=readFileSync(join(process.cwd(),'discadmin/ticketing-refunds.js'),'utf8');
  await page.route('**/discadmin/ticketing-refunds.js',r=>r.fulfill({contentType:'text/javascript',body:module}));
  let posts=0;
  await page.route('**/api/ticketing/admin-refunds.php',r=>{
    if(r.request().method()==='POST'){posts++;return r.fulfill({status:403});}
    return r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,
      can_decide:false,events:[],data:[{id:7,event_title:'Evento',amount_cop:35000,status:'approved'}]})});
  });
  await page.goto(url);
  const region=page.getByRole('region',{name:'Gestión de devoluciones'});
  await expect(region.getByText('Consulta de solicitudes. Solo el dueño puede tomar decisiones.')).toBeVisible();
  await expect(region.getByRole('button',{name:/Aprobar|Rechazar|Confirmar reembolso/})).toHaveCount(0);
  expect(posts).toBe(0);
});

test('T21 DISCADMIN exige CSRF y motivo para rechazo, sin reembolso automático',async({page})=>{
  const calls=[];
  const endpoint='http://127.0.0.1:4173/discadmin/refunds-test';
  await page.route(endpoint,route=>route.fulfill({contentType:'text/html',body:
    '<div id="refund-panel"></div><script type="module">import {mount} from "/discadmin/ticketing-refunds.js"; mount(document.getElementById("refund-panel"),"csrf-synthetic");</script>'
  }));
  const module=readFileSync(join(process.cwd(),'discadmin/ticketing-refunds.js'),'utf8');
  await page.route('**/discadmin/ticketing-refunds.js',route=>route.fulfill({
    contentType:'text/javascript',body:module
  }));
  await page.route('**/api/ticketing/admin-refunds.php',route=>{
    if(route.request().method()==='POST'){
      calls.push({body:route.request().postDataJSON(),headers:route.request().headers()});
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{id:7,status:'rejected'}})});
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,can_decide:true,events:[],data:[
      {id:7,event_title:'Evento de prueba',amount_cop:35000,status:'requested'}
    ]})});
  });
  await page.goto(endpoint);
  const region=page.getByRole('region',{name:'Gestión de devoluciones'});
  await expect(region.getByRole('button',{name:'Aprobar devolución'})).toBeVisible();
  await region.getByRole('button',{name:'Rechazar devolución'}).click();
  await expect(region.getByText('Indica un motivo de rechazo.')).toBeVisible();
  expect(calls).toHaveLength(0);
  await region.getByLabel('Motivo de decisión').fill('Motivo sintético verificado');
  await region.getByRole('button',{name:'Rechazar devolución'}).click();
  await expect(region.getByText('Decisión registrada por el dueño.')).toBeVisible();
  expect(calls).toHaveLength(1);
  expect(calls[0].body.action).toBe('reject');
  expect(calls[0].headers['x-csrf-token']).toBe('csrf-synthetic');
});


test('T21 el shell único expone reembolsos solo a staff autorizado',async({page})=>{
  const base='http://127.0.0.1:4173/discadmin/refunds-shell-synthetic';
  const shell=readFileSync(join(process.cwd(),'discadmin/ticketing.js'),'utf8');
  const finance="export async function mount(panel){panel.textContent='Finanzas sintéticas';}";
  const refundModule=readFileSync(join(process.cwd(),'discadmin/ticketing-refunds.js'),'utf8');
  await page.route(base,route=>route.fulfill({contentType:'text/html',body:
    '<section data-admin-module="ticketing" data-ticketing-role="superadmin">'+
    '<div role="tablist"><button id="ticketing-tab-finance" role="tab" data-ticketing-tab="finance" aria-selected="true">Finanzas</button></div>'+
    '<div data-ticketing-panel role="tabpanel"></div></section>'}));
  await page.route('**/discadmin/ticketing-finance.js',route=>route.fulfill({
    contentType:'text/javascript',body:finance
  }));
  await page.route('**/discadmin/ticketing-refunds.js',route=>route.fulfill({
    contentType:'text/javascript',body:refundModule
  }));
  await page.route('**/api/ticketing/admin-refunds.php',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify({ok:true,can_decide:true,events:[],data:[],csrf:'a'.repeat(64)})
  }));
  await page.goto(base);
  await page.addScriptTag({content:shell});
  await page.evaluate(()=>window.BRVTALTicketing.select(
    document.querySelector('[data-admin-module="ticketing"]'),'finance'));
  await page.getByRole('button',{name:'Devoluciones'}).click();
  await expect(page.getByRole('region',{name:'Gestión de devoluciones'})).toBeVisible();
  await expect(page.getByText('Aún no hay solicitudes.')).toBeVisible();
});

test('T21 contratos PHP se ejecutan en Chromium sin base productiva',()=>{
  const stdout=execFileSync('php',['tests/integration/ticketing-refunds.php'],{
    encoding:'utf8',env:{...process.env,BRVTAL_INTEGRATION_TESTS:'0'}
  });
  expect(stdout).toContain('T21 refund contract checks OK');
});


test('T21 dueño registra reembolso ya hecho en Wompi, sin llamar a su API',async({page})=>{
  const endpoint='http://127.0.0.1:4173/discadmin/refund-confirm-test';
  let dispatched=false;
  await page.route(endpoint,route=>route.fulfill({contentType:'text/html',body:
    '<div id="refund-panel"></div><script type="module">import {mount} from "/discadmin/ticketing-refunds.js"; mount(document.getElementById("refund-panel"),"csrf-synthetic");</script>'
  }));
  const module=readFileSync(join(process.cwd(),'discadmin/ticketing-refunds.js'),'utf8');
  await page.route('**/discadmin/ticketing-refunds.js',route=>route.fulfill({
    contentType:'text/javascript',body:module
  }));
  await page.route('**/api/ticketing/admin-refunds.php',route=>{
    if(route.request().method()==='POST'){
      const input=route.request().postDataJSON();
      expect(input.action).toBe('manual_confirm');
      expect(input.receipt).toBe('WOMPI-REF-TEST-02');
      expect(route.request().headers()['x-csrf-token']).toBe('csrf-synthetic');
      dispatched=true;
      return route.fulfill({contentType:'application/json',body:JSON.stringify({
        ok:true,data:{id:7,status:'refunded'}})});
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,can_decide:true,events:[],data:[
      {id:7,event_title:'Evento de prueba',amount_cop:3000,status:dispatched?'refunded':'approved',
        payment_evidence:{channel:'wompi',state:'verified'},wompi_payment_ref:dispatched?null:'trx_test_94',can_manual_confirm:!dispatched}
    ]})});
  });
  await page.goto(endpoint);
  const region=page.getByRole('region',{name:'Gestión de devoluciones'});
  await region.getByLabel('Referencia del reembolso de Wompi').fill('WOMPI-REF-TEST-02');
  await region.getByRole('button',{name:'Confirmar reembolso hecho en Wompi'}).click();
  await expect(region.getByText('Comprobante registrado y boletas anuladas.')).toBeVisible();
  await expect(region.getByText(/Registrada y anulada/)).toBeVisible();
});

const refundCases=[
  {id:1,event_title:'Evento Wompi',amount_cop:35000,status:'approved',
    payment_evidence:{channel:'wompi',state:'verified'},wompi_payment_ref:'trx_test_94',can_manual_confirm:true},
  {id:2,event_title:'Evento taquilla',amount_cop:25000,status:'approved',
    payment_evidence:{channel:'pos',state:'registered_offline'},wompi_payment_ref:null,can_manual_confirm:false},
  {id:3,event_title:'Evento discrepante',amount_cop:40000,status:'manual_pending',
    payment_evidence:{channel:'conflict',state:'discrepancy'},wompi_payment_ref:null,can_manual_confirm:false},
  {id:4,event_title:'Evento sin evidencia',amount_cop:30000,status:'approved',
    payment_evidence:{channel:'unknown',state:'unavailable'},wompi_payment_ref:null,can_manual_confirm:false}
];
async function visualRefunds(page,source,rows=refundCases,owner=true,code=200,pending=null){
  const endpoint='http://127.0.0.1:4173/discadmin/refunds-visual.html';
  await page.unrouteAll({behavior:'wait'});
  await page.route(endpoint,r=>r.fulfill({contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
      +'<body style="background:#050505;color:#fff;font:14px system-ui;padding:16px">'
      +'<div id="refund-panel"></div><script type="module">'
      +'import {mount} from "/discadmin/ticketing-refunds.js";mount(document.getElementById("refund-panel"),"csrf-synthetic");'
      +'</script></body></html>'}));
  await page.route('**/discadmin/ticketing-refunds.js',r=>r.fulfill({
    contentType:'text/javascript',body:readFileSync(join(source,'discadmin/ticketing-refunds.js'),'utf8')}));
  await page.route('**/api/ticketing/admin-refunds.php',async r=>{
    if(pending)await pending;
    return r.fulfill({status:code,contentType:'application/json',
      body:JSON.stringify({ok:code===200,can_decide:owner,events:[],data:rows})});
  });
  await page.goto(endpoint);
  await page.addStyleTag({content:readFileSync(join(source,'discadmin/ticketing.css'),'utf8')});
  return page.getByRole('region',{name:'Gestión de devoluciones'});
}
for (const width of [390,1440]) {
  test('AC-04 Devoluciones antes/después a '+width+' px',async({page},testInfo)=>{
    await page.setViewportSize({width,height:900});
    const baseline=process.env.BRVTAL_REFUND_BASE_DIR;
    if(baseline){
      const before=await visualRefunds(page,baseline);
      await expect(before.locator('article.tk-step')).toHaveCount(refundCases.length);
      const path=testInfo.outputPath('devoluciones-antes-'+width+'.png');
      await page.screenshot({path,fullPage:true});
      await testInfo.attach('devoluciones-antes-'+width+'.png',{path,contentType:'image/png'});
    }else testInfo.annotations.push({type:'evidence',description:'Baseline checkout not supplied; before capture not certified.'});
    const region=await visualRefunds(page,process.cwd());
    await expect(region.locator('article.tk-step')).toHaveCount(refundCases.length);
    await expect(region.getByText('Medio original: Wompi acreditado')).toBeVisible();
    await expect(region.getByText('Medio original: POS / pago offline acreditado')).toBeVisible();
    await expect(region.getByText('Evidencia de pago discrepante')).toBeVisible();
    await expect(region.getByText('Evidencia de pago no verificable')).toBeVisible();
    const confirm=region.getByRole('button',{name:'Confirmar reembolso hecho en Wompi'});
    await expect(confirm).toHaveCount(1);
    await region.getByLabel('Referencia del reembolso de Wompi').focus();
    await page.keyboard.press('Tab');
    await expect(confirm).toBeFocused();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    const path=testInfo.outputPath('devoluciones-despues-'+width+'.png');
    await page.screenshot({path,fullPage:true});
    await testInfo.attach('devoluciones-despues-'+width+'.png',{path,contentType:'image/png'});
  });
}
test('AC-02 devolución: consulta, vacío, permiso y error',async({page})=>{
  let release;
  const pending=new Promise(resolve=>{release=resolve;});
  let region=await visualRefunds(page,process.cwd(),refundCases,false,200,pending);
  try{
    await expect(region.getByText('Cargando solicitudes…')).toBeVisible();
    await expect(region.locator('article')).toHaveCount(0);
  }finally{release();}
  await expect(region.locator('article.tk-step')).toHaveCount(4);
  await expect(region.getByRole('button',{name:/Confirmar reembolso|Aprobar|Rechazar/})).toHaveCount(0);
  region=await visualRefunds(page,process.cwd(),[]);
  await expect(region.getByText('Aún no hay solicitudes.')).toBeVisible();
  region=await visualRefunds(page,process.cwd(),[],false,403);
  await expect(region.getByText('No tienes permiso para consultar devoluciones.')).toBeVisible();
  await expect(region.locator('article')).toHaveCount(0);
  region=await visualRefunds(page,process.cwd(),[],false,503);
  await expect(region.getByText('No se pudieron consultar las devoluciones.')).toBeVisible();
});
