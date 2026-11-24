import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

// #1929: the event page is a purchase page. The ticket panel sits beside the event
// (sticky on desktop) or right after the hero (phones), with a cart of several types.
const css = ['css/public-entity.css', 'css/public-event-record.css', 'css/public-ticket-checkout.css',
  'css/public-cromo.css', 'css/public-cromo-pages.css'].map(file => readFileSync(file, 'utf8')).join('\n');
const script = readFileSync('js/public-ticket-checkout.js', 'utf8');
const url = 'https://www.brvtal.com.co/events/cart-fixture';
const php = `require_once 'config/public_seo.php'; require_once 'config/public_page.php';
$f = json_decode($argv[1], true);
$until = date('Y-m-d H:i:s', strtotime('+4 days'));
$tickets = [
  ['ticket_type_id'=>5,'title'=>'Preventa','price'=>25000,'currency'=>'COP','status'=>'active','available_until'=>$until],
  ['ticket_type_id'=>6,'title'=>'VIP','price'=>60000,'currency'=>'COP','status'=>'active','description'=>'Zona VIP'],
];
$lineup = [];
foreach (range(1, 6) as $i) $lineup[] = ['title'=>'Artista '.$i,'slug'=>'artista-'.$i,'route_type'=>'artists','image'=>''];
$page = ['entity'=>['id'=>7,'route_type'=>'events','title'=>'Genesis','description'=>str_repeat('Una noche irrepetible. ', 40),
  'status'=>$f['status']],
  'facts'=>['DATE'=>'04.12.2026 / 9:00 PM','LOCATION'=>'Sala X, Pereira'],'links'=>[],
  'related'=>['LINEUP'=>$lineup,'TICKETS'=>$tickets],
  'record'=>['state'=>'active','status'=>$f['status']==='cancelled'?'CANCELLED':'TICKETS AVAILABLE','year'=>'2026'],'degraded'=>false,
  'checkout'=>$f['online'] ? ['offers'=>[['id'=>5,'title'=>'Preventa','price_cop'=>25000,'available'=>20],
    ['id'=>6,'title'=>'VIP','price_cop'=>60000,'available'=>2]],'methods'=>['CARD','NEQUI','PSE']] : []];
$seo = ['title'=>'Genesis','description'=>'Prueba','image'=>'','canonical'=>'https://www.brvtal.com.co/events/cart-fixture','schema'=>[]];
echo brvtal_public_entity_page($page, $seo);`;
function render({ online = true, status = 'tickets_available' } = {}) {
  return execFileSync('php', ['-r', php, JSON.stringify({ online, status })], { encoding: 'utf8' })
    .replace('</head>', '<style>' + css + '</style></head>')
    .replace('<script src="/js/public-ticket-checkout.js" defer></script>', '');
}
async function open(page, fixture) {
  await page.route(url, route => route.fulfill({ contentType: 'text/html', body: render(fixture) }));
  await page.route('**/api/ticketing/waitlist.php**', route => route.fulfill({ contentType: 'application/json', body: '{"ok":true,"types":[]}' }));
  await page.goto(url);
  await page.addScriptTag({ content: script });
}

test('escritorio: el panel de compra va al lado, queda fijo al bajar y arma el pedido', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await open(page);
  const panel = page.locator('#boletas');
  const hero = await page.locator('.entity-hero').boundingBox();
  const box = await panel.boundingBox();
  const main = await page.locator('.event-buy-main').boundingBox();
  expect(box.x).toBeGreaterThan(main.x + main.width - 1); // right column
  expect(box.y).toBeLessThan(hero.y + hero.height + 200); // starts right after the hero
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await page.locator('[data-cart-type="6"] [data-cart-step="1"]').click();
  await expect(page.locator('[data-cart-pay]')).toHaveText('Pagar $85.000');
  await page.locator('#lineup').scrollIntoViewIfNeeded();
  await expect(panel).toBeInViewport();
  expect((await panel.boundingBox()).y).toBeLessThan(200); // sticky near the top
  await expect(page.locator('.event-sticky-buy')).toBeHidden(); // desktop: no bottom bar
  await page.evaluate(() => scrollTo(0, document.querySelector('.event-buy-layout').offsetTop - 80));
  await testInfo.attach('cart-1440', { body: await page.screenshot(), contentType: 'image/png' });
});

test('móvil: el panel sigue al encabezado y la barra inferior muestra total y pago', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await open(page);
  const heroBottom = await page.locator('.entity-hero').evaluate(el => el.getBoundingClientRect().bottom + scrollY);
  const panelTop = await page.locator('#boletas').evaluate(el => el.getBoundingClientRect().top + scrollY);
  const statementTop = await page.locator('.entity-statement').evaluate(el => el.getBoundingClientRect().top + scrollY);
  expect(panelTop).toBeGreaterThan(heroBottom - 1);
  expect(panelTop).toBeLessThan(statementTop); // before the description
  await page.locator('#boletas').scrollIntoViewIfNeeded();
  await testInfo.attach('cart-390', { body: await page.locator('#boletas').screenshot(), contentType: 'image/png' });
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await page.locator('#lineup').scrollIntoViewIfNeeded();
  const bar = page.locator('.event-sticky-buy');
  await expect(bar).toBeVisible();
  await expect(bar.locator('[data-cart-sticky-summary]')).toHaveText('2 boletas · $50.000');
  await expect(bar.getByRole('button', { name: 'Pagar' })).toBeVisible();
  await expect(bar.getByRole('link', { name: 'Elegir boletas' })).toBeHidden();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
});

test('venta en línea apagada: se ven las boletas y un aviso, sin botón de pago', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await open(page, { online: false });
  await expect(page.locator('#brvtal-ticket-cart')).toHaveCount(0);
  await expect(page.locator('#boletas .event-ticket-card')).toHaveCount(2);
  await expect(page.locator('#boletas .event-ticket-card').first()).toContainText('25,000 COP');
  await expect(page.locator('#boletas .event-buy-note')).toContainText('todavía no está abierta');
  await expect(page.locator('.entity-actions .event-buy-button')).toHaveText('Ver boletas');
  await expect(page.locator('.entity-actions .event-buy-button')).toHaveAttribute('href', '/events/cart-fixture#boletas');
  await expect(page.locator('[data-cart-pay]')).toHaveCount(0);
});

test('evento cancelado: no vende', async ({ page }) => {
  await open(page, { online: true, status: 'cancelled' });
  await expect(page.locator('#brvtal-ticket-cart')).toHaveCount(0);
  await expect(page.locator('[data-cart-pay]')).toHaveCount(0);
  await expect(page.locator('.event-buy-button[href="#boletas"]:has-text("Comprar")')).toHaveCount(0);
});

// #1948: buying without an account. The server re-validates everything; these
// checks pin what the browser sends and what the buyer sees.
const orderToken = 'ab'.repeat(16);
async function openCheckout(page, { authenticated = false, onPrepare, storage } = {}) {
  const calls = { prepare: [], status: [], wompi: [] };
  await page.route(/\/events\/cart-fixture(\?.*)?$/, async route => {
    const request = route.request();
    const kind = request.headers()['x-brvtal-ticket-checkout'];
    if (request.method() === 'POST' && kind === 'prepare') {
      calls.prepare.push({ headers: request.headers(), body: request.postDataJSON() });
      const response = onPrepare ? await onPrepare() : {
        ok: true, order_id: 9, order_token: orderToken,
        checkout: { environment: 'sandbox', public_key: 'pub_test_synthetic', currency: 'COP',
          amount_in_cents: 5000000, reference: 'BW7-9-CARD-' + 'c'.repeat(24),
          signature_integrity: 'd'.repeat(64), redirect_url: url } };
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(response) });
    }
    if (kind === 'status') {
      calls.status.push(new URL(request.url()).searchParams.toString());
      return route.fulfill({ contentType: 'application/json', body: '{"ok":true,"state":"approved"}' });
    }
    return route.fulfill({ contentType: 'text/html', body: render() });
  });
  await page.route('**/api/ticketing/waitlist.php**', route => route.fulfill({ contentType: 'application/json', body: '{"ok":true,"types":[]}' }));
  await page.route('**/api/buyer/auth.php', route => route.fulfill({ contentType: 'application/json',
    body: JSON.stringify({ ok: true, authenticated, csrf: authenticated ? 'csrf-sintetico' : '' }) }));
  await page.route('https://checkout.wompi.co/**', route => {
    calls.wompi.push(route.request().url());
    return route.fulfill({ contentType: 'text/html', body: '<h1>Wompi sintético</h1>' });
  });
  await page.goto(url);
  if (storage) await page.evaluate(value => sessionStorage.setItem('brvtal-checkout:/events/cart-fixture', JSON.stringify(value)), storage);
  await page.addScriptTag({ content: script });
  return calls;
}
async function fillGuest(page, { consent = true, email = 'ana@example.test' } = {}) {
  await page.getByLabel('Correo').fill(email);
  await page.getByLabel('Nombre').fill('Ana Gómez');
  if (consent) await page.getByLabel(/Acepto el tratamiento/).check();
}

// Reproduce the exact account-required panel from main before #1948. This
// captures a browser-rendered pre-change fixture, NOT a production screenshot.
function renderBeforeGuest() {
  const current = render();
  const oldAccount = '<p class="event-cart-account">Para pagar necesitas tu cuenta BRVTAL. '
    + '<a href="/mi-cuenta/acceso">Inicia sesión o créala</a>.</p>';
  const baseline = current
    .replace(/<fieldset class="event-cart-buyer" data-cart-buyer>[\s\S]*?<\/fieldset>/, '')
    .replace(/<p class="event-cart-account" data-cart-account>[\s\S]*?<\/p>/, oldAccount);
  if (baseline === current || baseline.includes('data-cart-buyer') ||
      !baseline.includes(oldAccount)) throw new Error('Guest baseline fixture not reconstructed');
  return baseline;
}
async function captureGuestVisual(testInfo, filename, shot) {
  await testInfo.attach(filename, { body: shot, contentType: 'image/png' });
  // Existing Chromium CI visual-artifact upload collects this named folder.
  const dir = 'test-results/public-concept05-fidelity-1948';
  mkdirSync(dir, { recursive: true });
  writeFileSync(`${dir}/${filename}.png`, shot);
  if (process.env.BRVTAL_VISUAL_EVIDENCE) {
    mkdirSync(process.env.BRVTAL_VISUAL_EVIDENCE, { recursive: true });
    writeFileSync(`${process.env.BRVTAL_VISUAL_EVIDENCE}/${filename}.png`, shot);
  }
}
for (const [label, size] of [['390', { width: 390, height: 844 }], ['1440', { width: 1440, height: 900 }]]) {
  test(`sin cuenta: correo, nombre y consentimiento bastan para ir a Wompi (${label})`, async ({ page }, testInfo) => {
    await page.setViewportSize(size);
    const before = await page.context().newPage();
    await before.setViewportSize(size);
    await before.route(/\/events\/cart-fixture(\?.*)?$/, route =>
      route.fulfill({ contentType: 'text/html', body: renderBeforeGuest() }));
    await before.goto(url);
    await before.locator('#boletas').scrollIntoViewIfNeeded();
    await captureGuestVisual(testInfo, `cart-guest-antes-${label}`,
      await before.locator('#boletas').screenshot());
    await before.close();
    const calls = await openCheckout(page);
    await expect(page.locator('[data-cart-buyer]')).toBeVisible();
    await expect(page.locator('#brvtal-ticket-cart')).not.toContainText('necesitas tu cuenta');
    // Honeypot: off-screen, out of the tab order and hidden from assistive tech.
    const trap = page.locator('input[name="website"]');
    await expect(trap).toHaveAttribute('tabindex', '-1');
    await expect(page.locator('.event-cart-trap')).toHaveAttribute('aria-hidden', 'true');
    expect((await trap.boundingBox()).x).toBeLessThan(0);
    await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
    await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
    await fillGuest(page);
    if (size.width > 900) {
      // #1950: the sticky panel never hides Pagar below the fold; it scrolls inside itself.
      await page.locator('#lineup').scrollIntoViewIfNeeded();
      const panel = await page.locator('#boletas').boundingBox();
      expect(panel.y + panel.height).toBeLessThanOrEqual(size.height + 1);
      await page.locator('[data-cart-pay]').scrollIntoViewIfNeeded();
      await expect(page.locator('[data-cart-pay]')).toBeInViewport();
      await page.evaluate(() => scrollTo(0, 0));
    }
    await page.locator('[data-cart-buyer]').scrollIntoViewIfNeeded();
    const shot = await page.locator('#boletas').screenshot();
    await captureGuestVisual(testInfo, `cart-guest-despues-${label}`, shot);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(size.width);
    await page.locator('[data-cart-pay]').click();
    await expect.poll(() => calls.wompi.length).toBe(1);
    const [prepare] = calls.prepare;
    expect(prepare.headers['x-csrf-token']).toBeUndefined();
    expect(prepare.body.buyer).toEqual({ email: 'ana@example.test', name: 'Ana Gómez', consent: true, website: '' });
    expect(prepare.body.lines).toEqual([{ ticket_type_id: 5, quantity: 2 }]);
    expect(calls.wompi[0]).toContain('public-key=pub_test_synthetic');
  });
}

test('sin consentimiento o con correo inválido no se reserva y se dice por qué', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const calls = await openCheckout(page);
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await fillGuest(page, { consent: false });
  await page.locator('[data-cart-pay]').click();
  await expect(page.locator('#ticket-checkout-status')).toHaveText('Acepta el tratamiento de datos para poder comprar.');
  await expect(page.locator('input[name="buyer_consent"]')).toHaveAttribute('aria-invalid', 'true');
  await page.getByLabel(/Acepto el tratamiento/).check();
  await page.getByLabel('Correo').fill('no-es-correo');
  await page.locator('[data-cart-pay]').click();
  await expect(page.locator('#ticket-checkout-status')).toHaveText('Escribe un correo válido: ahí te llegan las boletas.');
  await expect(page.getByLabel('Correo')).toBeFocused();
  expect(calls.prepare).toHaveLength(0);
});

test('con sesión iniciada se ocultan los datos y se paga con la cuenta', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  const calls = await openCheckout(page, { authenticated: true });
  await expect(page.locator('[data-cart-buyer]')).toBeHidden();
  await expect(page.locator('[data-cart-account]')).toBeHidden();
  await page.locator('[data-cart-type="6"] [data-cart-step="1"]').click();
  await page.locator('[data-cart-pay]').click();
  await expect.poll(() => calls.wompi.length).toBe(1);
  expect(calls.prepare[0].headers['x-csrf-token']).toBe('csrf-sintetico');
  expect(calls.prepare[0].body.buyer).toBeUndefined();
});

test('al volver de Wompi el estado se consulta con el order_token, sin sesión', async ({ page }) => {
  const calls = await openCheckout(page, { storage: { intent: 'T13-x', order_id: '9', order_token: orderToken,
    lines: [{ ticket_type_id: 5, quantity: 1 }], method: 'CARD' } });
  await expect(page.locator('#ticket-checkout-status')).toHaveText('Pedido #9: Pago confirmado. Te enviamos las boletas con QR a tu correo.');
  expect(calls.status).toEqual([`order_token=${orderToken}`]);
});

function mockGuestOrder() {
  return { ok: true, order_id: 9, order_token: orderToken, checkout: { environment: 'mock' } };
}

test('invitado: tras timeout y recarga, la misma identidad conserva la clave de reintento', async ({ page }) => {
  let attempts = 0;
  const calls = await openCheckout(page, { onPrepare: () => (++attempts === 1
    ? { ok: false, error: 'CHECKOUT_TIMEOUT' } : mockGuestOrder()) });
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await fillGuest(page);
  await page.locator('[data-cart-pay]').click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('tardó demasiado');
  const saved = await page.evaluate(() => JSON.parse(sessionStorage.getItem('brvtal-checkout:/events/cart-fixture')));
  expect(saved.buyer_fingerprint).toMatch(/^[a-f0-9]{64}$/);
  const firstKey = calls.prepare[0].body.idempotency_key;
  await page.reload();
  await page.addScriptTag({ content: script });
  await fillGuest(page);
  await expect(page.locator('[data-cart-pay]')).toBeEnabled();
  await page.locator('[data-cart-pay]').click();
  await expect.poll(() => calls.prepare.length).toBe(2);
  expect(calls.prepare[1].body.idempotency_key).toBe(firstKey);
  // The request is recorded before the browser handles the mocked response.
  // Assert the completed save, not an intermediate in-flight session record.
  await expect(page.locator('#ticket-checkout-status')).toContainText('Reserva preparada');
  await expect.poll(() => page.evaluate(() =>
    JSON.parse(sessionStorage.getItem('brvtal-checkout:/events/cart-fixture'))?.order_token
  )).toBe(orderToken);
});

test('invitado: un reintento fallido conserva order_token, y otro comprador usa otra clave', async ({ page }) => {
  let attempts = 0;
  const calls = await openCheckout(page, { onPrepare: () => (++attempts === 2
    ? { ok: false, error: 'CHECKOUT_TIMEOUT' } : mockGuestOrder()) });
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await fillGuest(page);
  await page.locator('[data-cart-pay]').click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('Reserva preparada');
  const originalKey = calls.prepare[0].body.idempotency_key;
  await page.locator('[data-cart-pay]').click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('tardó demasiado');
  const pending = await page.evaluate(() => JSON.parse(sessionStorage.getItem('brvtal-checkout:/events/cart-fixture')));
  expect(pending.order_token).toBe(orderToken);
  expect(pending.intent).toBe(originalKey);
  await page.getByLabel('Correo').fill('otra@example.test');
  await page.locator('[data-cart-pay]').click();
  await expect.poll(() => calls.prepare.length).toBe(3);
  expect(calls.prepare[2].body.idempotency_key).not.toBe(originalKey);
  expect(calls.prepare[2].body.buyer.email).toBe('otra@example.test');
});

test('invitado: los datos del comprador permanecen bloqueados durante prepare', async ({ page }) => {
  let release;
  const calls = await openCheckout(page, {
    onPrepare: () => new Promise(resolve => { release = resolve; }),
  });
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await fillGuest(page);
  await page.locator('[data-cart-pay]').click();
  await expect.poll(() => calls.prepare.length).toBe(1);
  await expect(page.getByLabel('Correo')).not.toBeEditable();
  await expect(page.getByLabel('Nombre')).not.toBeEditable();
  await expect(page.getByLabel(/Acepto el tratamiento/)).toBeDisabled();
  expect(typeof release).toBe('function');
  release(mockGuestOrder());
  await expect(page.locator('#ticket-checkout-status')).toContainText('Reserva preparada');
  await expect(page.getByLabel('Correo')).toBeEditable();
  await expect(page.getByLabel('Nombre')).toBeEditable();
});

test('la cuenta bloqueada no se enumera: respuesta genérica con orientación', async ({ page }) => {
  const calls = await openCheckout(page, { onPrepare: () => ({ ok: false, error: 'CHECKOUT_UNAVAILABLE' }) });
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await fillGuest(page);
  await page.locator('[data-cart-pay]').click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('si tu cuenta está bloqueada');
  expect(calls.prepare).toHaveLength(1);
});
