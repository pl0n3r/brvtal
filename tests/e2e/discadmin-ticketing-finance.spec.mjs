import {test,expect} from '@playwright/test';
import {readFileSync,mkdirSync} from 'node:fs';
import {execFileSync} from 'node:child_process';

const incidents=[
  {order_id:61091,state:'discrepancy',reason:'duplicate_wompi',channel:'conflict',authenticated_cop:40000,unproven_attempts:0,wompi_receipts:[{transaction_id:'W-61091-A',gross_cop:20000},{transaction_id:'W-61091-B',gross_cop:20000}]},
  {order_id:61092,state:'discrepancy',reason:'channel_conflict',channel:'conflict',authenticated_cop:null,unproven_attempts:0,wompi_receipts:[]}
];
const totals={all:[10000,30000,2],wompi:[10000,0,1],pos:[0,30000,1]};
const catalog={ok:true,events:[{id:6109,title:'Evento sintético',ticket_types:[]}]};
const endpoint='/api/ticketing/admin-finances.php';
const origin='http://127.0.0.1:4174';
function report(channel) {
  const [approved_cop,pos_cop,paid_orders]=totals[channel];
  return {ok:true,rows:[{event_id:6109,approved_cop,pos_cop,paid_orders,unverified_orders:2,discrepancies:incidents}]};
}
async function mount(page,handler) {
  const php=readFileSync('discadmin/ticketing-finance.php','utf8');
  const fragment=php.slice(php.indexOf('<section data-finance-module'))
    .replace(/<script type="application\/json" data-finance-catalog>[\s\S]*?<\/script>/,
      '<script type="application/json" data-finance-catalog>'+JSON.stringify(catalog)+'</script>')
    .replace(/data-ledger-csrf="[^"]*"/,'data-ledger-csrf="fixture"');
  await page.route('**/*',route=>route.abort());
  await page.route('**/discadmin/finance-2109-test.html',route=>route.fulfill({contentType:'text/html',body:
    '<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
    +'<body style="margin:0;background:#050505;color:#f4f5f6;font:14px system-ui;padding:16px">'
    +'<section data-admin-module="ticketing"><button data-ticketing-tab="finance" aria-selected="true">Finanzas</button>'
    +'<div id="panel"></div></section></body></html>'}));
  await page.route('**/discadmin/ticketing-finance.php',route=>route.fulfill({contentType:'text/html',body:fragment}));
  await page.route('**/discadmin/ticketing-finance.js',route=>route.fulfill({contentType:'text/javascript',body:readFileSync('discadmin/ticketing-finance.js','utf8')}));
  await page.route('**'+endpoint+'*',handler);
  await page.goto('http://127.0.0.1:4173/discadmin/finance-2109-test.html');
  await page.addStyleTag({content:readFileSync('discadmin/ticketing.css','utf8')});
  await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-finance.js');void m.mount(document.querySelector('#panel'));});
}
async function openEvidence(page) {
  const detail=page.locator('[data-finance-results] summary').filter({hasText:'2 incidencias'});
  await expect(detail).toBeVisible();
  await detail.focus();
  await expect(detail).toBeFocused();
  await detail.press('Enter');
  await expect(page.getByText('Incidencias de pago',{exact:true})).toBeVisible();
  await expect(page.getByText('Pedido #61091 — Cobros Wompi distintos')).toBeVisible();
  await expect(page.getByText('Pedido #61092 — Conflicto entre canales')).toBeVisible();
  await expect(page.getByText('Transacción W-61091-A',{exact:false})).toBeVisible();
  await expect(page.getByText('Transacción W-61091-B',{exact:false})).toBeVisible();
  await expect(page.getByText('Importe Wompi autenticado (no suma ingresos): Sin dato',{exact:false})).toBeVisible();
}
for (const width of [390,1440]) {
  test('2109 presentation, keyboard and filters '+width,async({page})=>{
    await page.setViewportSize({width,height:900});
    let mode='loading',release;
    const waiting=new Promise(resolve=>{release=resolve;});
    await mount(page,async route=>{
      const channel=new URL(route.request().url()).searchParams.get('channel')||'all';
      if(mode==='loading') await waiting;
      if(mode==='denied'||mode==='error') return route.fulfill({status:mode==='denied'?403:503,json:{ok:false}});
      return route.fulfill({json:mode==='empty'?{ok:true,rows:[]}:report(channel)});
    });
    const status=page.locator('[data-finance-status]');
    await expect(status).toHaveText('Consultando finanzas…');
    mode='ok';release();
    await openEvidence(page);
    for(const channel of ['wompi','pos']){
      await page.getByLabel('Canal').selectOption(channel);
      await page.getByRole('button',{name:'Consultar',exact:true}).click();
      await openEvidence(page);
    }
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    for(const [next,message] of [['empty','No hay movimientos para los filtros seleccionados.'],['denied','No tienes permiso para consultar finanzas.'],['error','No fue posible consultar finanzas.']]){
      mode=next;
      await page.getByRole('button',{name:'Consultar',exact:true}).click();
      await expect(page.getByText(message,{exact:true})).toBeVisible();
      await expect(page.getByText('Incidencias de pago',{exact:true})).toHaveCount(0);
    }
  });
}
function csvRows(text) {
  const rows=[];let row=[],field='',quoted=false;
  const source=text.replace(/^\uFEFF/,'');
  for(let i=0;i<source.length;i++){
    const ch=source[i];
    if(ch==='"'){
      if(quoted&&source[i+1]==='"'){field+='"';i++;}
      else quoted=!quoted;
    }else if(!quoted&&ch===','){row.push(field);field='';}
    else if(!quoted&&ch==='\n'){row.push(field.replace(/\r$/,''));rows.push(row);row=[];field='';}
    else field+=ch;
  }
  if(field||row.length){row.push(field);rows.push(row);}
  expect(quoted).toBe(false);
  return rows.slice(1).map(r=>Object.fromEntries(rows[0].map((key,i)=>[key,r[i]])));
}
async function login(request,role) {
  const response=await request.post(origin+'/api/index.php/auth',{data:{email:role+'-2109@example.test',password:'brvtal-fixture-2109'}});
  expect(response.ok()).toBeTruthy();
  expect((await response.json()).ok).toBe(true);
}
async function openShell(page){
  await page.goto(origin+'/discadmin/',{waitUntil:'domcontentloaded'});
  await page.locator('[data-admin-nav="ticketing"]').click();
  await page.locator('[data-ticketing-group="money"]').click();
  await page.getByRole('tab',{name:'Finanzas',exact:true}).click();
}
test.describe('2109 authenticated real stack',()=>{
  test.skip(process.env.BRVTAL_FINANCE_2109_HTTP!=='1','Disposable MariaDB HTTP fixture required');
  test('JSON/CSV, permissions, and exact before/after shell captures',async({page,browser})=>{
    test.setTimeout(120000);
    await page.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
    await login(page.request,'admin');
    let reference;
    for(const channel of ['all','wompi','pos']){
      const url=origin+endpoint+'?event_id=6109&channel='+channel;
      const response=await page.request.get(url);
      expect(response.ok()).toBeTruthy();
      const data=await response.json();
      expect(data.ok).toBe(true);
      expect(data.rows).toHaveLength(1);
      const row=data.rows[0];
      expect([row.approved_cop,row.pos_cop,row.paid_orders]).toEqual(totals[channel]);
      const sorted=[...row.discrepancies].sort((a,b)=>a.order_id-b.order_id);
      expect(sorted).toEqual(incidents);
      if(reference)expect(sorted).toEqual(reference);
      reference=sorted;
      const csv=await page.request.get(url+'&format=csv');
      expect(csv.ok()).toBeTruthy();
      const exported=csvRows(await csv.text());
      expect(exported).toHaveLength(1);
      expect(JSON.parse(exported[0].discrepancies)).toEqual(row.discrepancies);
      expect([exported[0].approved_cop,exported[0].pos_cop,exported[0].paid_orders]).toEqual(totals[channel].map(String));
      expect(JSON.stringify(row.discrepancies)).not.toContain('example.test');
    }
    const door=await browser.newContext();
    try {
      await login(door.request,'door');
      for(const suffix of ['', '&format=csv']){
        expect((await door.request.get(origin+endpoint+'?event_id=6109'+suffix)).status()).toBe(403);
      }
    }finally{await door.close();}
    expect(process.env.BASELINE_SHA).toMatch(/^[a-f0-9]{40}$/);
    const baseline=execFileSync('git',['show',process.env.BASELINE_SHA+':discadmin/ticketing-finance.js'],{encoding:'utf8'});
    mkdirSync('artifacts/issue-2109',{recursive:true});
    const jsRoute='**/discadmin/ticketing-finance.js*';
    for(const width of [390,1440]){
      await page.setViewportSize({width,height:900});
      await page.route(jsRoute,route=>route.fulfill({contentType:'text/javascript',body:baseline}));
      await openShell(page);
      await page.locator('[data-finance-results] summary').filter({hasText:'Ver desglose'}).click();
      await expect(page.getByText('Incidencias de pago',{exact:true})).toHaveCount(0);
      await page.screenshot({path:'artifacts/issue-2109/before-'+width+'.png',fullPage:true});
      await page.unroute(jsRoute);
      await openShell(page);
      await openEvidence(page);
      expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      await page.screenshot({path:'artifacts/issue-2109/after-'+width+'.png',fullPage:true});
    }
  });
});
