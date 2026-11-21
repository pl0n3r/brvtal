import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const source=readFileSync('discadmin/ticketing-waitlist.js','utf8');
const fixture='https://www.brvtal.com.co/discadmin/t28-fixture.html';
async function setup(page) {
  await page.route(fixture,route=>route.fulfill({contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><main id="panel"></main></html>'}));
  await page.route('**/discadmin/ticketing-waitlist.js',route=>route.fulfill({
    contentType:'text/javascript',body:source}));
  await page.goto(fixture);
}
test('T28 opt-in explícito, anti-bot y ninguna boleta/cobro al suscribirse',async ({page})=>{
  const received=[];
  await setup(page);
  await page.route('**/api/ticketing/waitlist.php',route=>{
    received.push(route.request().postDataJSON());
    route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,status:'waiting',payment_created:false,ticket_created:false})});
  });
  await page.evaluate(async ()=>{
    const m=await import('/discadmin/ticketing-waitlist.js');
    m.mountPublic(document.querySelector('#panel'),
      {eventId:5,typeId:10,csrf:'synthetic-csrf'});
  });
  await expect(page.getByRole('checkbox')).not.toBeChecked();
  await page.getByRole('button',{name:'Entrar en lista de espera'}).click();
  expect(received).toHaveLength(0);
  await page.getByRole('checkbox').check();
  await page.getByRole('button',{name:'Entrar en lista de espera'}).click();
  await expect(page.getByRole('status')).toContainText('No es una reserva ni una compra');
  expect(received).toEqual([{action:'subscribe',event_id:5,ticket_type_id:10,
    consent:'waitlist-v1',website:''}]);
  expect(JSON.stringify(received)).not.toMatch(/payment|ticket_created|card|charge/);
});
test('T28 DISCADMIN dentro del shell, FIFO y permiso CSRF para ofertar',async ({page})=>{
  await page.setViewportSize({width:390,height:844});
  const submitted=[];
  await setup(page);
  await page.route('**/api/ticketing/admin-waitlist.php*',route=>{
    if(route.request().method()==='POST') {
      submitted.push({payload:route.request().postDataJSON(),
        csrf:route.request().headers()['x-csrf-token']});
      return route.fulfill({contentType:'application/json',
        body:JSON.stringify({ok:true,offered:true,offer:{entry_id:1}})});
    }
    return route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,rows:[{id:1,ticket_type_id:10,buyer_id:7,
        status:'waiting',created_at_utc:'2026-11-21',offer_expires_at_utc:null}],
        counts:[{status:'waiting',total:1}]})});
  });
  page.on('dialog',dialog=>dialog.accept());
  await page.evaluate(async ()=>{
    const m=await import('/discadmin/ticketing-waitlist.js');
    m.mount(document.querySelector('#panel'),'synthetic-csrf');
  });
  await page.locator('[name=event_id]').fill('5');
  await page.getByRole('button',{name:'Consultar'}).click();
  await expect(page.locator('table tbody tr')).toHaveCount(1);
  await expect(page.locator('table')).toContainText('waiting');
  await page.locator('[name=ticket_type_id]').fill('10');
  await page.getByRole('button',{name:'Ofrecer un cupo'}).click();
  await expect.poll(()=>submitted.length).toBe(1);
  expect(submitted[0]).toEqual({payload:{action:'offer',event_id:5,ticket_type_id:10},
    csrf:'synthetic-csrf'});
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
});
