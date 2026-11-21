import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const source=readFileSync('discadmin/ticketing-promos.js','utf8');
test('T23 Promos: 403, snapshots sin edición y módulo en shell a 390px',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  await page.route('**/discadmin/t23.html',r=>r.fulfill({contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'+
      '<main data-panel></main><script>window.csrf="test-csrf"</script></html>'}));
  await page.route('**/discadmin/ticketing-promos.js',r=>r.fulfill({
    contentType:'text/javascript',body:source}));
  let deny=true,created=0,deactivated=0;
  await page.route('**/api/ticketing/admin-promos.php*',r=>{
    if (deny) return r.fulfill({status:403,contentType:'application/json',
      body:'{"ok":false,"error":"FORBIDDEN"}'});
    const post=r.request().method()==='POST';
    if (post) {
      expect(r.request().headers()['x-csrf-token']).toBe('test-csrf');
      const payload=r.request().postDataJSON();
      expect(['create','deactivate']).toContain(payload.action);
      expect(payload).not.toHaveProperty('order_id');
      if (payload.action==='create') created++; else deactivated++;
    }
    return r.fulfill({contentType:'application/json',body:JSON.stringify(
      post?{ok:true}:{ok:true,rules:[{id:8,kind:'fixed',uses_count:1,
        max_uses:2,active:1,campaign_key:'noche'}]})});
  });
  await page.goto('https://www.brvtal.com.co/discadmin/t23.html');
  await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-promos.js');
    m.mount(document.querySelector('[data-panel]'));});
  await page.getByLabel('Evento ID').fill('6');
  await page.getByRole('button',{name:'Consultar reglas'}).click();
  await expect(page.getByRole('status')).toContainText('Sin permiso');
  deny=false;
  await page.getByRole('button',{name:'Consultar reglas'}).click();
  await expect(page.locator('[data-promo-rules]')).toContainText('1/2 usos');
  await expect(page.getByRole('button',{name:/editar/i})).toHaveCount(0);
  await page.getByRole('button',{name:'Desactivar'}).click();
  expect(deactivated).toBe(1);
  await expect(page.getByRole('status')).toContainText('ventas anteriores');
  expect(created).toBe(0);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth))
    .toBeLessThanOrEqual(391);
});

test('T23: checkout sintético confirma desglose del servidor, no persiste cupón',async({page})=>{
  const checkoutSource=readFileSync('js/public-ticket-checkout.js','utf8');
  await page.setViewportSize({width:390,height:820});
  const url='https://www.brvtal.com.co/events/t23-fixture';
  await page.route('**/events/t23-fixture',route=>route.fulfill({
    contentType:'text/html',body:'<!doctype html><html lang="es">'+
      '<meta name="viewport" content="width=device-width,initial-scale=1">'+
      '<main class="event-ticket-checkout"><form id="brvtal-ticket-checkout" data-t23-synthetic="1">'+
      '<label>Tipo<select name="ticket_type_id"><option value="10" data-price="15000" '+
      'data-available="5">General</option></select></label>'+
      '<label>Cantidad<input type="number" name="quantity" value="2"></label>'+
      '<label>Método<select name="method"><option value="CARD">CARD</option></select></label>'+
      '<label>Invitación<input name="waitlist_token"></label>'+
      '<output id="ticket-checkout-total"></output>'+
      '<button type="submit">Continuar al pago</button></form>'+
      '<p id="ticket-checkout-status" role="status"></p></main></html>'
  }));
  await page.route('**/api/ticketing/waitlist.php*',route=>route.fulfill({
    contentType:'application/json',body:'{"ok":true,"types":[]}' }));
  await page.route('**/api/buyer/auth.php',route=>route.fulfill({
    contentType:'application/json',body:'{"ok":true,"authenticated":true,"csrf":"test"}'
  }));
  const posted=[];
  await page.route('**/events/t23-fixture**',route=>{
    if (route.request().method()!=='POST') return route.fallback();
    posted.push(route.request().postDataJSON());
    return route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,order_id:88,checkout:{environment:'mock',amount_in_cents:2500000},
      promo_breakdown:{subtotal_cop:30000,discount_cop:5000,total_cop:25000}
    })});
  });
  await page.goto(url);
  await page.addScriptTag({content:checkoutSource});
  const promo=page.getByLabel('Cupón promocional (solo pruebas)');
  await expect(promo).toBeVisible();
  await promo.fill('NOCHE2026A');
  await expect(page.locator('#ticket-checkout-total')).toContainText('pendiente de verificación');
  await page.getByRole('button',{name:'Continuar al pago'}).click();
  await expect(page.locator('#ticket-checkout-total')).toContainText('Descuento');
  await expect(page.locator('#ticket-checkout-total')).toContainText('25.000');
  await expect(page.locator('#ticket-checkout-status')).toContainText('pendiente');
  expect(posted).toHaveLength(1);
  expect(posted[0].promo_code).toBe('NOCHE2026A');
  expect(posted[0]).not.toHaveProperty('total_cop');
  expect(posted[0]).not.toHaveProperty('price');
  expect(await page.evaluate(()=>sessionStorage.getItem('brvtal-checkout:'+location.pathname)))
    .not.toContain('NOCHE2026A');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth))
    .toBeLessThanOrEqual(391);
});

test('T23: cambio de evento vacía reglas e ignora respuesta tardía', async ({page}) => {
  const url='https://www.brvtal.com.co/discadmin/t23-event-race.html';
  await page.route('**/discadmin/t23-event-race.html', r => r.fulfill({
    contentType:'text/html',body:'<!doctype html><html lang="es"><main data-panel></main></html>'
  }));
  await page.route('**/discadmin/ticketing-promos.js', r => r.fulfill({
    contentType:'text/javascript',body:source
  }));
  let releaseSix;
  const pendingSix=new Promise(resolve => { releaseSix=resolve; });
  await page.route('**/api/ticketing/admin-promos.php*', async route => {
    const id=new URL(route.request().url()).searchParams.get('event_id');
    if (id==='6') await pendingSix;
    await route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,rules:[{id:Number(id),kind:'fixed',uses_count:0,
        max_uses:1,active:0,campaign_key:'evento-'+id}]})});
  });
  await page.goto(url);
  await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-promos.js');
    m.mount(document.querySelector('[data-panel]'));});
  await page.getByLabel('Evento ID').fill('6');
  const requestSix=page.waitForRequest(req=>req.url().includes('event_id=6'));
  await page.getByRole('button',{name:'Consultar reglas'}).click();
  await requestSix;
  await page.getByLabel('Evento ID').fill('7');
  releaseSix();
  await expect(page.getByRole('button',{name:'Consultar reglas'})).toBeEnabled();
  await expect(page.locator('[data-promo-rules]')).toBeEmpty();
  await page.getByRole('button',{name:'Consultar reglas'}).click();
  await expect(page.locator('[data-promo-rules]')).toContainText('evento-7');
  await page.getByLabel('Evento ID').fill('8');
  await expect(page.locator('[data-promo-rules]')).toBeEmpty();
});
