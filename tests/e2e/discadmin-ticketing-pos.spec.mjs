import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';

const finance=readFileSync('discadmin/ticketing-finance.js','utf8');
const pos=readFileSync('discadmin/ticketing-pos.js','utf8');
async function setup(page,handler) {
  await page.route('**/discadmin/pos-test.html',route=>route.fulfill({
    contentType:'text/html',body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
      +'<section data-admin-module="ticketing"><button data-ticketing-tab="finance" aria-selected="true">Finanzas</button>'
      +'<div id="panel"></div></section>'}));
  await page.route('**/discadmin/ticketing-finance.js',route=>route.fulfill({
    contentType:'text/javascript',body:finance}));
  await page.route('**/discadmin/ticketing-pos.js',route=>route.fulfill({
    contentType:'text/javascript',body:pos}));
  await page.route('**/discadmin/ticketing-finance.php',route=>route.fulfill({
    contentType:'text/html',body:'<section data-finance-module><form data-finance-form>'
      +'<input name="event_id"><button type="submit">Consultar</button>'
      +'<button data-finance-export type="button">Exportar CSV</button></form>'
      +'<p data-finance-status></p><div data-finance-results></div></section>'}));
  await page.route('**/discadmin/ticketing-orders.php',route=>route.fulfill({
    contentType:'text/html',body:'<section data-ticketing-records data-ticketing-csrf="'+ 'a'.repeat(64) +'"></section>'}));
  await page.route('**/api/ticketing/admin-finances.php?*',route=>route.fulfill({
    contentType:'application/json',body:'{"rows":[]}' }));
  await page.route('**/api/ticketing/admin-pos.php',handler);
  await page.goto('https://www.brvtal.com.co/discadmin/pos-test.html');
  await page.evaluate(async()=>{
    const m=await import('/discadmin/ticketing-finance.js');
    await m.mount(document.querySelector('#panel'));
  });
  await expect(page.locator('[data-pos-module]')).toBeVisible();
}
test('T24 mobile: two-stage cash confirmation, no payment provider request',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const calls=[];
  await setup(page,route=>{
    const body=route.request().postDataJSON();
    calls.push({body,headers:route.request().headers()});
    const data=body.action==='open'?{shift_id:3,status:'open'}:
      body.action==='prepare'?{sale_id:7,status:'prepared',amount_cop:25000,
        confirmation_token:'b'.repeat(48),replayed:false}:
      {sale_id:7,status:'paid',receipt:'POS-7',tickets:['BRVTAL:T1:synthetic-token'],amount_cop:25000};
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data})});
  });
  const section=page.locator('[data-pos-module]');
  await expect(section).toContainText('Transferencias y QR Wompi requieren verificación independiente');
  await expect(section.locator('option[value="transfer"]')).toBeDisabled();
  await expect(section.locator('option[value="wompi_qr"]')).toBeDisabled();
  expect(calls).toHaveLength(0);
  await section.locator('[name="event_id"]').fill('6');
  await section.getByRole('button',{name:'Abrir caja de prueba'}).click();
  await expect(section.getByRole('button',{name:'Preparar venta'})).toBeVisible();
  await section.locator('[name="ticket_type_id"]').fill('10');
  await section.getByRole('button',{name:'Preparar venta'}).click();
  await expect(section.getByRole('button',{name:'Confirmar cobro de prueba'})).toBeVisible();
  expect(calls.map(x=>x.body.action)).toEqual(['open','prepare']);
  await section.getByRole('button',{name:'Confirmar cobro de prueba'}).click();
  await expect(section).toContainText('POS-7');
  expect(calls.map(x=>x.body.action)).toEqual(['open','prepare','confirm']);
  expect(calls.at(-1).body.confirm).toBe(true);
  expect(calls.at(-1).headers['x-csrf-token']).toBe('a'.repeat(64));
  expect(calls.filter(x=>x.body.method==='wompi_qr'||x.body.method==='transfer')).toHaveLength(0);
});
test('T24 desktop: cancelled preparation does not confirm or emit',async({page})=>{
  await page.setViewportSize({width:1440,height:900});
  const actions=[];
  await setup(page,route=>{
    const b=route.request().postDataJSON();actions.push(b.action);
    const data=b.action==='open'?{shift_id:3,status:'open'}:
      b.action==='prepare'?{sale_id:9,amount_cop:25000,status:'prepared',
        confirmation_token:'b'.repeat(48)}:{sale_id:9,status:'cancelled',tickets:[]};
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data})});
  });
  const s=page.locator('[data-pos-module]');
  await s.locator('[name="event_id"]').fill('6');
  await s.getByRole('button',{name:'Abrir caja de prueba'}).click();
  await s.locator('[name="ticket_type_id"]').fill('10');
  await s.getByRole('button',{name:'Preparar venta'}).click();
  await s.getByRole('button',{name:'Cancelar preparación'}).click();
  await expect(s).toContainText('anulada');
  expect(actions).toEqual(['open','prepare','cancel']);
  await expect(s.locator('[data-pos-receipt]')).toBeEmpty();
});
test('T24 network denial fails closed without local paid receipt',async({page})=>{
  await setup(page,route=>route.fulfill({status:403,contentType:'application/json',
    body:'{"ok":false,"error":"POS_SYNTHETIC_ONLY"}'}));
  const s=page.locator('[data-pos-module]');
  await s.locator('[name="event_id"]').fill('6');
  await s.getByRole('button',{name:'Abrir caja de prueba'}).click();
  await expect(s).toContainText('Operación no disponible');
  await expect(s.getByRole('button',{name:'Preparar venta'})).toBeHidden();
  await expect(s.locator('[data-pos-receipt]')).toBeEmpty();
});
