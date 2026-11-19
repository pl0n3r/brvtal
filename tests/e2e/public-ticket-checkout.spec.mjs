import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const script = readFileSync('js/public-ticket-checkout.js', 'utf8');
const css = readFileSync('css/public-ticket-checkout.css', 'utf8');
const path = 'https://www.brvtal.com.co/events/t13-fixture';
function render({ active = true, internal = true, external = true } = {}) {
  const fixture = JSON.stringify({ active, internal, external });
  const php = [
    "require_once 'config/public_seo.php'; require_once 'config/public_page.php';",
    '$f=json_decode($argv[1],true);',
    "$e=['id'=>42,'route_type'=>'events','title'=>'Noche sintética','description'=>'Boletas',",
    "'status'=>$f['active']?'tickets_available':'cancelled'];",
    "$offers=$f['external']?[['title'=>'General','price'=>30000,'currency'=>'COP',",
    "'status'=>'active','url'=>'https://tickets.example.invalid/general']]:[];",
    "$page=['entity'=>$e,'facts'=>[],'links'=>[],'related'=>['TICKETS'=>$offers],",
    "'record'=>['state'=>'active','status'=>$f['active']?'TICKETS AVAILABLE':'CANCELLED','year'=>'2026'],",
    "'degraded'=>false,",
    "'checkout'=>$f['internal']?['offers'=>[['id'=>5,'title'=>'General',",
    "'price_cop'=>30000,'available'=>3]],'methods'=>['CARD','NEQUI']]:[]];",
    "$seo=['title'=>'Noche sintética','description'=>'Prueba','image'=>'',",
    "'canonical'=>'https://www.brvtal.com.co/events/t13-fixture','schema'=>[]];",
    'echo brvtal_public_entity_page($page,$seo);',
  ].join(' ');
  return execFileSync('php', ['-r', php, fixture], { encoding: 'utf8' })
    .replace('</head>', '<style>' + css + '</style></head>');
}
async function open(page, fixture) {
  await page.route(path, route => route.fulfill({ contentType: 'text/html', body: render(fixture) }));
  await page.goto(path);
  await page.addScriptTag({ content: script });
}
test('T13 390/1440: servidor fija cupos, teclado y total sin recargo', async ({ page }) => {
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 820 });
    await open(page);
    const form = page.locator('#brvtal-ticket-checkout');
    await expect(form).toBeVisible();
    await expect(page.locator('.event-buy-button').first()).toHaveAttribute('href','#checkout-boletas');
    await expect(page.locator('#ticket-checkout-total')).toContainText('30.000');
    await form.getByLabel('Cantidad').fill('2');
    await expect(page.locator('#ticket-checkout-total')).toContainText('60.000');
    await form.getByLabel('Cantidad').fill('4');
    await expect(form.getByRole('button',{name:'Continuar al pago'})).toBeDisabled();
    await form.getByLabel('Cantidad').fill('1');
    await form.getByLabel('Método').focus();
    await expect(form.getByLabel('Método')).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width+1);
  }
});
test('T13 preserva venta externa y no vende eventos cancelados', async ({ page }) => {
  await open(page, { internal: false, external: true });
  await expect(page.locator('#brvtal-ticket-checkout')).toHaveCount(0);
  await expect(page.locator('.event-buy-button').first()).toHaveAttribute('href',
    'https://tickets.example.invalid/general');
  await open(page, { active: false, internal: true });
  await expect(page.locator('#brvtal-ticket-checkout')).toHaveCount(0);
  await expect(page.locator('#boletas .event-ticket-card a')).toHaveCount(0);
  await expect(page.locator('#boletas')).toContainText('Venta cerrada');
});
test('T13 huésped, timeout, retry y redirect nunca confirman pago', async ({ page }) => {
  await open(page);
  let allowed = false;
  let requests = [];
  let statusOrders = [];
  await page.route('**/api/buyer/auth.php', route => route.fulfill({
    contentType:'application/json',
    body: JSON.stringify({ ok:true, csrf:'fixture', authenticated:allowed }),
  }));
  // The status URL adds ?order_id; intercept both the base path and query variant.
  await page.route('**/events/t13-fixture**', async route => {
    if (route.request().method()!=='POST') {
      if (route.request().headers()['x-brvtal-ticket-checkout']==='status') {
        statusOrders.push(new URL(route.request().url()).searchParams.get('order_id'));
        return route.fulfill({status:200,contentType:'application/json',
          body:JSON.stringify({ok:true,state:'failed'})});
      }
      return route.fallback();
    }
    requests.push(route.request().postDataJSON());
    if (requests.length===1) return route.abort('failed');
    return route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,order_id:42,checkout:{environment:'mock',state:'awaiting_provider'},
    })});
  });
  const button=page.getByRole('button',{name:'Continuar al pago'});
  await button.click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('Inicia sesión');
  expect(requests).toHaveLength(0);
  allowed=true;
  await button.click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('No fue posible');
  await button.click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('pendiente');
  expect(requests).toHaveLength(2);
  expect(requests[0].idempotency_key).toBe(requests[1].idempotency_key);
  expect(requests[1]).not.toHaveProperty('price');
  expect(requests[1]).not.toHaveProperty('status');
  const statusRequest = page.waitForRequest(request =>
    request.headers()['x-brvtal-ticket-checkout'] === 'status'
    && new URL(request.url()).searchParams.get('order_id') === '42');
  await page.reload();
  await page.addScriptTag({content:script});
  await statusRequest;
  await expect(page.locator('#ticket-checkout-status'))
    .toHaveText('El pago no fue aprobado. Puedes volver a intentarlo.');
  expect(statusOrders).toEqual(['42']);
  await expect(page.getByText('Pago confirmado por el servidor.')).toHaveCount(0);
});

test('T13 recupera pago cuando la última boleta agotó el inventario', async ({page}) => {
  await open(page,{internal:false,external:false});
  await expect(page.locator('#brvtal-ticket-checkout')).toHaveCount(0);
  await expect(page.locator('#checkout-boletas')).toBeHidden();
  await page.evaluate(() => sessionStorage.setItem('brvtal-checkout:'+location.pathname,
    JSON.stringify({order_id:'51'})));
  const orderIds=[];
  await page.route('**/events/t13-fixture**',route=>{
    if (route.request().headers()['x-brvtal-ticket-checkout']==='status') {
      orderIds.push(new URL(route.request().url()).searchParams.get('order_id'));
      return route.fulfill({status:200,contentType:'application/json',
        body:JSON.stringify({ok:true,state:'approved'})});
    }
    return route.fallback();
  });
  const request=page.waitForRequest(r=>r.headers()['x-brvtal-ticket-checkout']==='status'
    && new URL(r.url()).searchParams.get('order_id')==='51');
  await page.reload();
  await page.addScriptTag({content:script});
  await request;
  await expect(page.locator('#checkout-boletas')).toBeVisible();
  await expect(page.locator('#ticket-checkout-status'))
    .toHaveText('Pago confirmado por el servidor. Consulta tus entradas en tu cuenta.');
  expect(orderIds).toEqual(['51']);
  await expect(page.locator('#brvtal-ticket-checkout')).toHaveCount(0);
});
test('T13 reusa la misma intención tras timeout y recarga', async ({page}) => {
  await open(page);
  await page.route('**/api/buyer/auth.php',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify({ok:true,authenticated:true,csrf:'fixture'}),
  }));
  const requests=[];
  await page.route('**/events/t13-fixture**',route=>{
    if (route.request().method()!=='POST') return route.fallback();
    requests.push(route.request().postDataJSON());
    if (requests.length===1) return route.abort('failed');
    return route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,order_id:71,checkout:{environment:'mock'}})});
  });
  await page.getByLabel('Cantidad').fill('2');
  await page.getByRole('button',{name:'Continuar al pago'}).click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('No fue posible');
  const saved=await page.evaluate(()=>JSON.parse(sessionStorage.getItem('brvtal-checkout:'+location.pathname)));
  expect(saved.intent).toBe(requests[0].idempotency_key);
  expect(saved.input.quantity).toBe(2);
  await page.reload();
  await page.addScriptTag({content:script});
  await expect(page.getByLabel('Cantidad')).toHaveValue('2');
  await page.getByRole('button',{name:'Continuar al pago'}).click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('pendiente');
  expect(requests).toHaveLength(2);
  expect(requests[1].idempotency_key).toBe(requests[0].idempotency_key);
});
test('T13 evita doble envío mientras la primera solicitud sigue pendiente', async ({page}) => {
  await open(page);
  await page.route('**/api/buyer/auth.php',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify({ok:true,authenticated:true,csrf:'fixture'}),
  }));
  const requests=[];
  let release;
  const hold=new Promise(resolve=>{release=resolve;});
  await page.route('**/events/t13-fixture**',async route=>{
    if (route.request().method()!=='POST') return route.fallback();
    requests.push(route.request().postDataJSON());
    await hold;
    return route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,order_id:72,checkout:{environment:'mock'}})});
  });
  const button=page.getByRole('button',{name:'Continuar al pago'});
  await button.click();
  await expect(button).toBeDisabled();
  // Wait until the synthetic POST is actually held before testing concurrent input.
  // Otherwise a transient auth/checkout failure can restore the button first.
  await expect.poll(()=>requests.length).toBe(1);
  await expect(page.getByLabel('Cantidad')).toBeDisabled();
  await page.evaluate(()=>document.querySelector('[name=quantity]')
    .dispatchEvent(new Event('input',{bubbles:true})));
  await expect(button).toBeDisabled();
  release();
  await expect(page.locator('#ticket-checkout-status')).toContainText('pendiente');
  expect(requests).toHaveLength(1);
});
