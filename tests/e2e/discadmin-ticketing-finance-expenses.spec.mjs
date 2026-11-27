import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';

const baseline='39ba5719ee98b456b4ad9ce78ef200cd9f5b9a2d';
function source(path,before) {
  if (!before) return readFileSync(path,'utf8');
  const ref=baseline+':'+path;
  try {return execFileSync('git',['show',ref],{encoding:'utf8',timeout:20000});}
  catch {
    execFileSync('git',['fetch','--no-tags','--depth=1','origin',baseline],{timeout:60000});
    return execFileSync('git',['show',ref],{encoding:'utf8',timeout:20000});
  }
}
const catalog={ok:true,events:[{id:6,title:'Evento sintético',ticket_types:[]}]};
async function mount(page,before,handler) {
  const php=source('discadmin/ticketing-finance.php',before);
  const fragment=php.slice(php.indexOf('<section data-finance-module'))
    .replace(/<script type="application\/json" data-finance-catalog>[\s\S]*?<\/script>/,
      '<script type="application/json" data-finance-catalog>'+JSON.stringify(catalog)+'</script>')
    .replace(/data-ledger-csrf="[^"]*"/,'data-ledger-csrf="'+ 'a'.repeat(64)+'"');
  await page.route('**/*',route=>route.abort());
  await page.route('**/discadmin/expenses-test.html',route=>route.fulfill({contentType:'text/html',body:
    '<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
    +'<body style="margin:0;background:#050505;color:#f4f5f6;font:14px system-ui;padding:16px">'
    +'<section data-admin-module="ticketing"><button data-ticketing-tab="finance" aria-selected="true">Finanzas</button>'
    +'<div id="panel"></div></section></body></html>'}));
  await page.route('**/discadmin/ticketing-finance.php',route=>route.fulfill({contentType:'text/html',body:fragment}));
  await page.route('**/discadmin/ticketing-finance.js',route=>route.fulfill({contentType:'text/javascript',body:source('discadmin/ticketing-finance.js',before)}));
  await page.route('**/api/ticketing/admin-finances.php*',handler);
  await page.goto('http://127.0.0.1:4173/discadmin/expenses-test.html');
  await page.addStyleTag({content:source('discadmin/ticketing.css',before)});
  await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-finance.js');await m.mount(document.querySelector('#panel'));});
}
function fixture() {
  const rows={expenses:[],budgets:[]},writes=[];
  let reject=0,next=1;
  return {rows,writes,reject:code=>{reject=code;},handler:async route=>{
    const req=route.request(),url=new URL(req.url());
    if (req.method()==='GET') {
      const data=url.searchParams.get('ledger')==='1'
        ?url.searchParams.has('event_id')?{ok:true,...rows}:catalog:{ok:true,rows:[]};
      return route.fulfill({json:data});
    }
    expect(req.headers()['x-csrf-token']).toBe('a'.repeat(64));
    const b=req.postDataJSON();writes.push(b);
    expect(b.event_id).toBe(6);
    if (reject) return route.fulfill({status:reject,json:{ok:false}});
    const list=rows[b.kind==='expense'?'expenses':'budgets'];
    const old=list.find(x=>Number(x.id)===b.previous_id);
    const row={...(b.action==='void'?{...old,status:'void'}:b.values),id:String(next++)};
    row.amount_cop=String(row.amount_cop);
    if (old) list.splice(list.indexOf(old),1);
    list.push(row);
    return route.fulfill({json:{ok:true,id:row.id}});
  }};
}
async function open(page) {
  await page.getByRole('button',{name:'Gastos',exact:true}).click();
  const pane=page.locator('#tk-finance-ledger');
  await pane.getByLabel('Evento del libro').selectOption('6');
  await expect(pane.getByText('No hay gastos registrados.')).toBeVisible();
  return pane;
}
async function fill(pane,concept,amount,reason) {
  await pane.getByLabel('Concepto').fill(concept);
  await pane.getByLabel('Importe (COP)').fill(amount);
  await pane.getByLabel('Fecha (Bogotá)').fill('2026-11-26');
  await pane.getByLabel('Motivo del registro o ajuste').fill(reason);
}
for (const width of [390,1440]) {
  test('2191 libro de gastos y presupuesto '+width+' px',async({page},testInfo)=>{
    await page.setViewportSize({width,height:900});
    const fx=fixture();
    await mount(page,true,fx.handler);
    await expect(page.getByRole('button',{name:'Gastos',exact:true})).toHaveCount(0);
    await testInfo.attach('2191-before-'+width+'.png',{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await page.unrouteAll({behavior:'wait'});
    await mount(page,false,fx.handler);
    const pane=await open(page);
    await fill(pane,'Sonido sintético','45000','Registro fixture');
    await pane.getByRole('button',{name:'Guardar registro'}).click();
    await expect(pane.locator('[data-ledger-row="1"]')).toContainText('Sonido sintético');
    await pane.locator('[data-ledger-row="1"]').getByRole('button',{name:'Editar'}).click();
    await fill(pane,'Sonido ajustado','50000','Ajuste fixture');
    await pane.getByLabel('Estado').selectOption('paid');
    await pane.getByRole('button',{name:'Guardar registro'}).click();
    await expect(pane.locator('[data-ledger-row="2"]')).toContainText('Pagado');
    await pane.getByLabel('Tipo de registro').selectOption('budget');
    await pane.getByLabel('Categoría').selectOption('produccion');
    await pane.getByLabel('Importe (COP)').fill('90000');
    await pane.getByLabel('Fecha (Bogotá)').fill('2026-11-26');
    await pane.getByLabel('Motivo del registro o ajuste').fill('Presupuesto fixture');
    await pane.getByRole('button',{name:'Guardar registro'}).click();
    await expect(pane.locator('[data-ledger-row="3"]')).toContainText('Vigente');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await testInfo.attach('2191-after-'+width+'.png',{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await pane.locator('[data-ledger-row="2"]').getByRole('button',{name:'Editar'}).click();
    await pane.getByLabel('Motivo del registro o ajuste').fill('Anulación fixture');
    await pane.getByRole('button',{name:'Anular registro'}).click();
    await expect(pane.locator('[data-ledger-row="4"]')).toContainText('Anulado');
    expect(fx.writes.map(x=>[x.kind,x.action,x.previous_id])).toEqual([
      ['expense','create',null],['expense','adjust',1],['budget','create',null],['expense','void',2]]);
    expect(fx.writes[3].values).toEqual({});
    await page.getByRole('button',{name:'Resumen',exact:true}).click();
    await expect(page.getByRole('button',{name:'Caja',exact:true})).toBeVisible();
  });
}
test('2191 rechazo de escritura no inventa un registro ni reenvía',async({page})=>{
  const fx=fixture();fx.reject(403);
  await mount(page,false,fx.handler);
  const pane=await open(page);
  await fill(pane,'Fixture no autorizado','20000','Motivo fixture');
  await pane.getByRole('button',{name:'Guardar registro'}).click();
  await expect(pane.locator('[data-ledger-status]')).toContainText('Sin permiso');
  await expect(pane.getByText('No hay gastos registrados.')).toBeVisible();
  expect(fx.writes).toHaveLength(1);
});
