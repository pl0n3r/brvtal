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
    if (route.request().method()==='GET') return route.fulfill({
      contentType:'application/json',body:JSON.stringify({ok:true,
        challenge:{question:'2 + 3 = ?',captcha_token:'synthetic-waitlist-token'}})});
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
  await expect(page.getByRole('button',{name:'Entrar en lista de espera'})).toBeEnabled();
  await page.getByRole('button',{name:'Entrar en lista de espera'}).click();
  expect(received).toHaveLength(0);
  await page.getByRole('checkbox').check();
  await page.locator('#panel [name=captcha_answer]').fill('5');
  await page.getByRole('button',{name:'Entrar en lista de espera'}).click();
  await expect(page.getByRole('status')).toContainText('No es una reserva ni una compra');
  expect(received).toEqual([{action:'subscribe',event_id:5,ticket_type_id:10,
    consent:'waitlist-v1',website:'',captcha_token:'synthetic-waitlist-token',
    captcha_answer:'5'}]);
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


test('T28: formulario público consentido y canje opaco sin URL ni sessionStorage',async ({page})=>{
  const {execFileSync}=await import('node:child_process');
  const body=execFileSync('php',['-r',[
    "require_once 'config/public_seo.php'; require_once 'config/public_page.php';",
    "$e=['id'=>42,'route_type'=>'events','title'=>'Noche sintética','description'=>'Entradas','status'=>'tickets_available'];",
    "$page=['entity'=>$e,'facts'=>[],'links'=>[],'related'=>[],'record'=>['state'=>'active','status'=>'TICKETS AVAILABLE','year'=>'2026'],'degraded'=>false,",
    "'checkout'=>[]];",
    "$seo=['title'=>'Noche','description'=>'Prueba','image'=>'','canonical'=>'https://www.brvtal.com.co/events/t28-offer','schema'=>[]];",
    "echo brvtal_public_entity_page($page,$seo);",
  ].join(' ')],{encoding:'utf8'})
    .replace('<script src="/js/public-ticket-checkout.js" defer></script>','');
  const url='https://www.brvtal.com.co/events/t28-offer';
  await page.route(url,route=>route.fulfill({contentType:'text/html',body}));
  await page.route('**/api/buyer/auth.php',route=>route.fulfill({contentType:'application/json',
    body:JSON.stringify({ok:true,authenticated:true,csrf:'synthetic-csrf'})}));
  await page.route('**/discadmin/ticketing-waitlist.js',route=>route.fulfill({
    contentType:'text/javascript',body:source}));
  const posted=[];
  await page.route('**/api/ticketing/waitlist.php?catalog=t28-offer',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify({ok:true,event_id:42,
      types:[{id:10,name:'General'}],checkout:{invite_mode:true,
        offers:[{id:10,title:'General',price_cop:25000,available:0,invite_only:true}],
        methods:['CARD']}})}));
  await page.route('**/api/ticketing/waitlist.php',route=>{
    if (route.request().method()==='GET') return route.fulfill({
      contentType:'application/json',body:JSON.stringify({ok:true,
        challenge:{question:'2 + 3 = ?',captcha_token:'synthetic-waitlist-token'}})});
    posted.push({kind:'optin',body:route.request().postDataJSON()});
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,status:'waiting'})});
  });
  await page.route(url,route=>{
    if(route.request().method()==='POST'){
      posted.push({kind:'checkout',body:route.request().postDataJSON(),url:route.request().url()});
      return route.fulfill({contentType:'application/json',body:JSON.stringify({
        ok:true,order_id:94,checkout:{environment:'mock',state:'awaiting_provider'}})});
    }
    return route.fulfill({contentType:'text/html',body});
  });
  await page.goto(url);
  await page.addScriptTag({content:readFileSync('js/public-ticket-checkout.js','utf8')});
  const join=page.locator('#lista-espera');
  await expect(join).toContainText('No reserva cupos ni confirma pagos');
  await join.getByRole('checkbox').check();
  await expect(join.getByRole('button',{name:'Entrar en lista de espera'})).toBeEnabled();
  await join.locator('[name=captcha_answer]').fill('5');
  await join.getByRole('button',{name:'Entrar en lista de espera'}).click();
  await expect(join.getByRole('status')).toContainText('No es una reserva');
  expect(posted[0].body).toMatchObject({action:'subscribe',event_id:42,ticket_type_id:10,
    consent:'waitlist-v1',captcha_token:'synthetic-waitlist-token',captcha_answer:'5'});
  const button=page.getByRole('button',{name:'Continuar al pago'});
  await expect(button).toBeDisabled();
  const code='T'.repeat(43);
  await page.getByLabel('Código de invitación').fill(code);
  await expect(button).toBeEnabled();
  await button.click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('Reserva preparada');
  const request=posted.find(x=>x.kind==='checkout');
  expect(request.body).toMatchObject({waitlist_token:code,ticket_type_id:10,quantity:1,
    method:'CARD'});
  expect(request.url).not.toContain(code);
  const session=await page.evaluate(()=>JSON.stringify(sessionStorage));
  expect(session).not.toContain(code);
  await expect(page.getByLabel('Código de invitación')).toHaveValue('');
  await expect(button).toBeDisabled();
});
