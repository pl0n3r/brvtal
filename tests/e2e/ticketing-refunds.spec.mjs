import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';
import {execFileSync} from 'node:child_process';

const html=readFileSync(join(process.cwd(),'buyer/index.php'),'utf8');
const url='http://127.0.0.1:4173/buyer/index.php';
const order={public_id:'a'.repeat(32),event_title:'Prueba sintética',status:'paid',total_cop:25000,currency:'COP',items:[
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
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[
      {id:7,event_id:5,order_id:1,amount_cop:35000,status:'requested'}
    ]})});
  });
  await page.goto(endpoint);
  const region=page.getByRole('region',{name:'Gestión de devoluciones'});
  await expect(region.getByRole('button',{name:'Aprobar'})).toBeVisible();
  await region.getByRole('button',{name:'Rechazar'}).click();
  await expect(region.getByText('Indica un motivo de rechazo.')).toBeVisible();
  expect(calls).toHaveLength(0);
  await region.getByLabel('Motivo de gestión #7').fill('Motivo sintético verificado');
  await region.getByRole('button',{name:'Rechazar'}).click();
  await expect(region.getByText('Cambio registrado; no constituye un reembolso.')).toBeVisible();
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
    contentType:'application/json',body:JSON.stringify({ok:true,data:[],csrf:'a'.repeat(64)})
  }));
  await page.goto(base);
  await page.addScriptTag({content:shell});
  await page.evaluate(()=>window.BRVTALTicketing.select(
    document.querySelector('[data-admin-module="ticketing"]'),'finance'));
  await page.getByRole('button',{name:'Gestionar devoluciones · pruebas'}).click();
  await expect(page.getByRole('region',{name:'Gestión de devoluciones'})).toBeVisible();
  await expect(page.getByText('Sin solicitudes en el entorno sintético.')).toBeVisible();
});

test('T21 contratos PHP se ejecutan en Chromium sin base productiva',()=>{
  const stdout=execFileSync('php',['tests/integration/ticketing-refunds.php'],{
    encoding:'utf8',env:{...process.env,BRVTAL_INTEGRATION_TESTS:'0'}
  });
  expect(stdout).toContain('T21 refund contract checks OK');
});
