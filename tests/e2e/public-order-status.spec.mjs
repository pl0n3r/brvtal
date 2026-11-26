import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

// #1958: purchase confirmation page. Only the server's state is shown.
const root = process.cwd();
const page = readFileSync(join(root, 'buyer/purchase.php'), 'utf8').replace(/^<\?php[\s\S]*?\?>/, '');
const previousLogin = readFileSync(join(root, 'buyer/login.php'), 'utf8');
const accountTemplate = readFileSync(join(root, 'buyer/index.php'), 'utf8')
  .replace(/^<\?php[\s\S]*?\?>/, '');
const accountScope = [{ token: 'ab'.repeat(16), order_number: 6,
  event_title: 'Genesis', total_cop: 50000, items: [{ name: 'Preventa', quantity: 2 }] }];
const accountHtml = scoped => accountTemplate.replace(
  /<\?= json_encode\([\s\S]*?\) \?>/, JSON.stringify(scoped ? accountScope : []));
// Pre-change return: guests reached this login page, not an order confirmation.
// Use the exact versioned HTML. The synthetic route disables scripts via CSP,
// rather than filtering HTML with a fragile regular expression. Not production.
const assets = {
  '/css/buyer-access.css': ['text/css', readFileSync(join(root, 'css/buyer-access.css'))],
  '/css/public-ticket-view.css': ['text/css', readFileSync(join(root, 'css/public-ticket-view.css'))],
  '/css/buyer-account.css': ['text/css', readFileSync(join(root, 'css/buyer-account.css'))],
  '/js/public-order-status.js': ['text/javascript', readFileSync(join(root, 'js/public-order-status.js'))],
  '/assets/fonts/BrokenGlass.ttf': ['font/ttf', readFileSync(join(root, 'assets/fonts/BrokenGlass.ttf'))],
};
const base = 'http://127.0.0.1:4173';
const token = 'ab'.repeat(16);
const order = state => ({ ok: true, order: { order_number: 6, state, event_title: 'Genesis',
  event_url: '/events/genesis', total_cop: 50000, items: [{ name: 'Preventa', quantity: 2 }],
  email_hint: 'a***@example.test' } });

async function setup(browserPage, states, scoped = true) {
  const asked = [];
  await browserPage.addInitScript(() => { window.BRVTAL_ORDER_POLL_MS = 150; });
  await browserPage.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/mi-cuenta') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: accountHtml(scoped) });
    if (url.pathname === '/api/buyer/auth.php') return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ok:true,authenticated:false,csrf:'csrf-fixture'}) });
    if (url.pathname === '/mi-cuenta/acceso' || url.pathname === '/buyer/login.php') {
      return route.fulfill({
        contentType: 'text/html; charset=utf-8',
        headers: { 'Content-Security-Policy': "script-src 'none'" },
        body: previousLogin,
      });
    }
    if (/^\/compra\//.test(url.pathname)) return route.fulfill({ contentType: 'text/html; charset=utf-8', body: page });
    if (url.pathname === '/api/ticketing/order-status.php') {
      asked.push(url.searchParams.get('t'));
      if (states === 404) return route.fulfill({ status: 404, contentType: 'application/json', body: '{"ok":false,"error":"ORDER_NOT_FOUND"}' });
      const state = states[Math.min(asked.length - 1, states.length - 1)];
      if (state === 429) return route.fulfill({ status: 429, headers: { 'Retry-After': '2' },
        contentType: 'application/json', body: '{"ok":false,"error":"RATE_LIMITED"}' });
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(order(state)) });
    }
    const asset = assets[url.pathname];
    if (asset) return route.fulfill({ contentType: asset[0], body: asset[1] });
    return route.fulfill({ status: 404, body: '' });
  });
  return asked;
}
async function capture(browserPage, testInfo, name) {
  const png = await browserPage.screenshot({ fullPage: true });
  await testInfo.attach(name, { body: png, contentType: 'image/png' });
  if (process.env.BRVTAL_VISUAL_EVIDENCE) {
    mkdirSync(process.env.BRVTAL_VISUAL_EVIDENCE, { recursive: true });
    writeFileSync(join(process.env.BRVTAL_VISUAL_EVIDENCE, name + '.png'), png);
  }
}

for (const [label, size] of [['390', { width: 390, height: 844 }], ['1440', { width: 1440, height: 900 }]]) {
  test(`confirmación: pasa de confirmando a pago confirmado (${label})`, async ({ page: browserPage }, testInfo) => {
    await browserPage.setViewportSize(size);
    const asked = await setup(browserPage, ['pending', 'pending', 'approved']);
    await browserPage.goto(`${base}/mi-cuenta/acceso`);
    await expect(browserPage.getByRole('heading', { name: /Entra a tu cuenta/i })).toBeVisible();
    await capture(browserPage, testInfo, `compra-antes-${label}`);
    await browserPage.goto(`${base}/compra/${token}`);
    await expect(browserPage.locator('#purchase-number')).toHaveText('Pedido #6');
    await expect(browserPage.locator('#purchase-state-text')).toHaveText('Confirmando tu pago…');
    await capture(browserPage, testInfo, `compra-confirmando-${label}`);
    await expect(browserPage.locator('#purchase-state-text')).toHaveText('Pago confirmado');
    await expect(browserPage.locator('#purchase-state')).toHaveAttribute('data-state', 'approved');
    await expect(browserPage.locator('#purchase-detail')).toContainText('a***@example.test');
    await expect(browserPage.locator('#purchase-items')).toHaveText('2 × Preventa');
    await expect(browserPage.locator('#purchase-total')).toHaveText('$50.000');
    await expect(browserPage.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/events/genesis');
    expect(asked.every(value => value === token)).toBe(true);
    const count = asked.length;
    await browserPage.waitForTimeout(500);
    expect(asked.length).toBe(count); // a final state stops polling
    expect(await browserPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(size.width);
    await capture(browserPage, testInfo, `compra-confirmada-${label}`);
    await capture(browserPage, testInfo, `compra-despues-${label}`);
    await browserPage.getByRole('link', { name: 'Ir a Mi cuenta' }).click();
    await expect(browserPage).toHaveURL(base + '/mi-cuenta');
    await expect(browserPage.getByRole('heading', { name: /Mis compras/i })).toBeVisible();
    await expect(browserPage.locator('.account-order')).toHaveCount(1);
    await expect(browserPage.locator('.account-order')).toContainText('Pedido #6');
    await expect(browserPage.locator('.account-order')).toContainText('Preventa');
    const accountBounds = await browserPage.locator('.account-content').boundingBox();
    expect(accountBounds).not.toBeNull();
    expect(accountBounds.width).toBeGreaterThan(size.width === 390 ? 310 : 620);
    expect(await browserPage.evaluate(() => document.documentElement.scrollWidth))
      .toBeLessThanOrEqual(size.width);
    await expect(browserPage.getByRole('link', { name: 'Devoluciones' })).toHaveCount(0);
    await expect(browserPage.getByText('Consulta las compras pagadas en este navegador.')).toBeVisible();
    await expect(browserPage.getByRole('link', { name: 'Iniciar sesión para otras compras' })).toBeVisible();
    await capture(browserPage, testInfo, `cuenta-compra-limitada-${label}`);
  });
}

test('confirmación: un enlace inválido explica qué hacer', async ({ page: browserPage }, testInfo) => {
  await browserPage.setViewportSize({ width: 390, height: 844 });
  await setup(browserPage, 404);
  await browserPage.goto(`${base}/compra/${token}`);
  await expect(browserPage.locator('#purchase-state-text')).toHaveText('No encontramos esta compra');
  await expect(browserPage.locator('#purchase-detail')).toContainText('Ábrelo desde el correo');
  await expect(browserPage.locator('#purchase-summary')).toBeHidden();
  await capture(browserPage, testInfo, 'compra-no-encontrada-390');
});

test('confirmación: un pago rechazado lo dice sin confirmar nada', async ({ page: browserPage }) => {
  await setup(browserPage, ['failed']);
  await browserPage.goto(`${base}/compra/${token}`);
  await expect(browserPage.locator('#purchase-state-text')).toHaveText('El pago no fue aprobado');
  await expect(browserPage.locator('#purchase-state')).toHaveAttribute('data-state', 'failed');
});


test('otro navegador con el mismo enlace no obtiene pedidos ni sesión', async ({ browser }) => {
  const separate = await browser.newContext({ viewport: { width: 390, height: 844 } });
  try {
    const page = await separate.newPage();
    await setup(page, ['approved'], false);
    await page.goto(base + '/compra/' + token);
    await expect(page.locator('#purchase-state-text')).toHaveText('Pago confirmado');
    await page.getByRole('link', { name: 'Ir a Mi cuenta' }).click();
    await expect(page).toHaveURL(base + '/buyer/login.php');
    await expect(page.getByRole('heading', { name: /Entra a tu cuenta/i })).toBeVisible();
  } finally { await separate.close(); }
});

// Isolated UI contract; real HTTP/provider evidence is separate.
for (const width of [390, 1440]) {
  test(`Retry-After conserva el último estado confirmado (${width})`, async ({ page: browserPage }, testInfo) => {
    await browserPage.setViewportSize({ width, height: 844 });
    const asked = await setup(browserPage, ['pending', 429, 'approved']);
    await browserPage.goto(base + '/compra/' + token);
    await expect(browserPage.locator('#purchase-detail')).toContainText('Volveremos a consultar');
    await expect(browserPage.locator('#purchase-state')).toHaveAttribute('data-state', 'pending');
    expect(asked).toHaveLength(2);
    await capture(browserPage, testInfo, `compra-limitada-${width}`);
    await browserPage.waitForTimeout(700);
    expect(asked).toHaveLength(2);
    await expect(browserPage.locator('#purchase-state')).toHaveAttribute('data-state', 'approved');
    expect(asked).toHaveLength(3);
    await browserPage.keyboard.press('Tab');
    await capture(browserPage, testInfo, `compra-recuperada-${width}`);
  });
}
