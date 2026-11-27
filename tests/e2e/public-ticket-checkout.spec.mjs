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
    "'price_cop'=>30000,'available'=>3],['id'=>6,'title'=>'VIP','price_cop'=>50000,'available'=>2]],",
    "'methods'=>['CARD','NEQUI']]:[]];",
    "$seo=['title'=>'Noche sintética','description'=>'Prueba','image'=>'',",
    "'canonical'=>'https://www.brvtal.com.co/events/t13-fixture','schema'=>[]];",
    'echo brvtal_public_entity_page($page,$seo);',
  ].join(' ');
  return execFileSync('php', ['-r', php, fixture], { encoding: 'utf8' })
    .replace('</head>', '<style>' + css + '</style></head>')
    // The real renderer already includes this defer script. Playwright injects
    // the same local source below; leaving both creates competing form listeners.
    .replace('<script src="/js/public-ticket-checkout.js" defer></script>', '');
}
async function open(page, fixture, { timeoutMs } = {}) {
  await page.route(path, route => route.fulfill({ contentType: 'text/html', body: render(fixture) }));
  await page.goto(path);
  if (timeoutMs) await page.evaluate(ms => { window.BRVTAL_CHECKOUT_TIMEOUT_MS = ms; }, timeoutMs);
  await page.addScriptTag({ content: script });
}
const plus = (page, id) => page.locator(`[data-cart-type="${id}"] [data-cart-step="1"]`);
const minus = (page, id) => page.locator(`[data-cart-type="${id}"] [data-cart-step="-1"]`);
const payButton = page => page.locator('[data-cart-pay]');
test('#1929 390/1440: carrito de varios tipos con cupo del servidor, teclado y total', async ({ page }) => {
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 820 });
    await open(page);
    const cart = page.locator('#brvtal-ticket-cart');
    await expect(cart).toBeVisible();
    await expect(page.locator('.event-buy-button').first()).toHaveAttribute('href','/events/t13-fixture#boletas');
    await expect(payButton(page)).toBeDisabled();
    await expect(page.locator('[data-cart-total]')).toHaveText('$0');
    await plus(page, 5).click();
    await plus(page, 5).click();
    await expect(page.locator('[data-cart-total]')).toHaveText('$60.000');
    await plus(page, 6).click();
    await expect(page.locator('[data-cart-lines] li')).toHaveText([/2 × General.*\$60\.000/, /1 × VIP.*\$50\.000/]);
    await expect(page.locator('[data-cart-total]')).toHaveText('$110.000');
    await expect(payButton(page)).toHaveText('Pagar $110.000');
    await expect(payButton(page)).toBeEnabled();
    await plus(page, 5).click();
    await expect(plus(page, 5)).toBeDisabled(); // 3 available General
    await minus(page, 5).click();
    await expect(plus(page, 5)).toBeEnabled();
    await page.getByRole('radio', { name: 'Nequi' }).focus();
    await expect(page.getByRole('radio', { name: 'Nequi' })).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
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
    expect(route.request().headers()['x-brvtal-ticket-checkout']).toBe('snapshot');
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state:'unverified'})});
  });
  await plus(page, 5).click();
  const button=payButton(page);
  await button.click();
  // #1948: without a session the cart asks for the buyer's details instead of a login.
  await expect(page.locator('#ticket-checkout-status')).toHaveText('Escribe un correo válido: ahí te llegan las boletas.');
  expect(requests).toHaveLength(0);
  allowed=true;
  await button.click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('No fue posible');
  await expect(button).toBeDisabled();
  await page.getByRole('button', {name:'Consultar reserva'}).click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('No pudimos verificar');
  expect(requests).toHaveLength(2);
  expect(requests[0].idempotency_key).toBe(requests[1].idempotency_key);
  expect(requests[1].lines).toEqual([{ ticket_type_id: 5, quantity: 1 }]);
  expect(requests[1]).not.toHaveProperty('price');
  expect(requests[1]).not.toHaveProperty('status');
  await page.reload();
  await page.addScriptTag({content:script});
  await expect(page.locator('#ticket-checkout-status')).toContainText('No pudimos verificar');
  expect(requests[2]).toEqual(requests[1]);
  expect(statusOrders).toEqual([]);
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
    .toHaveText('Pedido #51: Pago confirmado. Te enviamos las boletas con QR a tu correo.');
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
    expect(route.request().headers()['x-brvtal-ticket-checkout']).toBe('snapshot');
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,state:'unverified'})});
  });
  await plus(page, 5).click();
  await plus(page, 5).click();
  await plus(page, 6).click();
  await payButton(page).click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('No fue posible');
  const saved=await page.evaluate(()=>JSON.parse(sessionStorage.getItem('brvtal-checkout:'+location.pathname)));
  expect(saved.intent).toBe(requests[0].idempotency_key);
  expect(saved.lines).toEqual([{ticket_type_id:5,quantity:2},{ticket_type_id:6,quantity:1}]);
  await page.reload();
  await page.addScriptTag({content:script});
  await expect(page.locator('#ticket-checkout-status')).toContainText('No pudimos verificar');
  await expect(payButton(page)).toBeDisabled();
  expect(requests).toHaveLength(2);
  expect(requests[1]).toEqual(requests[0]);
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
  await plus(page, 6).click();
  const button=payButton(page);
  await button.click();
  // Synchronize the UI lock with the real held POST, not the earlier auth phase.
  await expect.poll(()=>requests.length).toBe(1);
  await expect(button).toBeDisabled();
  await expect(plus(page, 5)).toBeDisabled();
  await expect(minus(page, 6)).toBeDisabled();
  await page.evaluate(()=>document.querySelector('[data-cart-type="5"] [data-cart-step="1"]').click());
  await expect(button).toBeDisabled();
  release();
  await expect(page.locator('#ticket-checkout-status')).toContainText('pendiente');
  expect(requests).toHaveLength(1);
});

test('#1940 una reserva que no responde se corta, avisa y reintenta con la misma intención', async ({ page }) => {
  await open(page, undefined, { timeoutMs: 600 });
  await page.route('**/api/buyer/auth.php', route => route.fulfill({
    contentType: 'application/json', body: JSON.stringify({ ok: true, authenticated: true, csrf: 'fixture' }) }));
  const keys = [];
  let hang = true;
  await page.route('**/events/t13-fixture**', async route => {
    if (route.request().method() !== 'POST') return route.fallback();
    keys.push(route.request().postDataJSON().idempotency_key);
    if (hang) return; // never answer: the server is stuck
    expect(route.request().headers()['x-brvtal-ticket-checkout']).toBe('snapshot');
    return route.fulfill({ contentType: 'application/json',
      body: JSON.stringify({ ok: true, state: 'unverified' }) });
  });
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  const pay = page.locator('[data-cart-pay]');
  await pay.click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('tardó demasiado', { timeout: 5000 });
  await expect(pay).toBeDisabled();
  hang = false;
  await page.getByRole('button', {name:'Consultar reserva'}).click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('No pudimos verificar');
  expect(keys).toHaveLength(2);
  expect(keys[1]).toBe(keys[0]);
});
