import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const root = process.cwd();
const runtime = readFileSync(join(root, 'js/public-cromo-home.js'), 'utf8');
const php = (source) => execFileSync('php', ['-r', source], { cwd: root, encoding: 'utf8' });
const fallback = php(
  "require 'config/public_home.php';" +
  ' $html = \'<aside class="menu-panel" id="menuPanel" aria-hidden="true"></aside><footer class="footer scene"></footer>\';' +
  ' echo brvtalPublicHomeConcept05Footer(brvtalPublicHomeConcept05Menu($html));'
);
const inner = php("require 'config/public_cromo_chrome.php'; echo brvtalPublicCromoTopbar();");
const homeUrl = 'http://127.0.0.1:4173/';
const innerUrl = homeUrl + 'events/demo';
const login = readFileSync(join(root, 'buyer/login.php'), 'utf8');
const account = readFileSync(join(root, 'buyer/index.php'), 'utf8');

async function buyerSession(page, authenticated) {
  await page.route('**/api/buyer/auth.php', (route) => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, authenticated, csrf: 'fixture-csrf' }),
  }));
}
async function staticDestinations(page) {
  await page.route('**/mi-cuenta/acceso', (route) =>
    route.fulfill({ contentType: 'text/html', body: login }));
  await page.route('**/mi-cuenta', (route) =>
    route.fulfill({ contentType: 'text/html', body: account }));
}
async function home(page, authenticated, cromo = false) {
  await buyerSession(page, authenticated);
  await staticDestinations(page);
  await page.route('**/api/public.php', (route) => route.fulfill({
    status: cromo ? 200 : 503, contentType: 'application/json',
    body: JSON.stringify(cromo ? {
      ok: true, data: { events: [], artists: [], sets: [], memories: [], blog: [], archive: { events: [] } },
    } : { ok: false }),
  }));
  await page.route('**/api/hero-slider.php', (route) =>
    route.fulfill({ status: 503, contentType: 'application/json', body: '{}' }));
  await page.route('**/api/contact.php', (route) =>
    route.fulfill({ status: 503, contentType: 'application/json', body: '{}' }));
  await page.route(homeUrl, (route) => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>' +
      '<body><header>BRVTAL</header>' + fallback + '</body></html>',
  }));
  await page.goto(homeUrl);
  await page.addScriptTag({ content: runtime });
}

test('guest: fallback home navigation and footer expose Mi cuenta on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 780 });
  await home(page, false);
  const anchors = page.locator('.c5-menu-nav [data-buyer-account-link], .c5-footer-contact [data-buyer-account-link]');
  await expect(anchors).toHaveCount(2);
  for (const a of await anchors.all()) await expect(a).toHaveAttribute('href', '/mi-cuenta/acceso');
  await page.locator('.c5-footer-contact [data-buyer-account-link]').click();
  await expect(page).toHaveURL(homeUrl + 'mi-cuenta/acceso');
  await expect(page.getByRole('heading', { name: 'Cuenta de comprador' })).toBeVisible();
});

test('logged-in buyer: Cromo menu and footer lead to Mis compras; keyboard and mobile menu work', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 780 });
  await home(page, true, true);
  const nav = page.locator('#cromo-home .cr-nav-actions [data-buyer-account-link]');
  const foot = page.locator('#cromo-home footer [data-buyer-account-link]');
  await expect(nav).toHaveAttribute('href', '/mi-cuenta');
  await expect(foot).toHaveAttribute('href', '/mi-cuenta');
  const toggle = page.getByRole('button', { name: 'MENÚ' });
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await nav.focus();
  await expect(nav).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(homeUrl + 'mi-cuenta');
  await expect(page.getByRole('heading', { name: 'Mis compras' })).toBeVisible();
});

test('inner Cromo topbar uses buyer API, not an admin cookie or cached identity', async ({ page }) => {
  await buyerSession(page, true);
  await staticDestinations(page);
  await page.route(innerUrl, (route) => route.fulfill({
    contentType: 'text/html',
    body: '<!doctype html><html lang="es"><body>' + inner + '<main>Evento sintético</main></body></html>',
  }));
  await page.goto(innerUrl);
  const accountLink = page.locator('.cp-tools [data-buyer-account-link]');
  await expect(accountLink).toHaveAttribute('href', '/mi-cuenta');
  await accountLink.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(homeUrl + 'mi-cuenta');
});

test('unauthenticated inner topbar safely defaults to access on API failure', async ({ page }) => {
  await page.route('**/api/buyer/auth.php', (route) =>
    route.fulfill({ status: 503, contentType: 'application/json', body: '{}' }));
  await page.route(innerUrl, (route) => route.fulfill({
    contentType: 'text/html',
    body: '<!doctype html><html lang="es"><body>' + inner + '</body></html>',
  }));
  await page.goto(innerUrl);
  await expect(page.locator('.cp-tools [data-buyer-account-link]')).toHaveAttribute('href', '/mi-cuenta/acceso');
});

test('Apache real-stack preserves legacy query on 301 and serves Spanish endpoints', async ({ request }) => {
  const real = process.env.BRVTAL_REAL_STACK_URL || '';
  test.skip(!real, 'Disposable Apache/LiteSpeed real-stack URL not supplied in this job');
  for (const [oldPath, spanishPath] of [
    ['/buyer/', '/mi-cuenta'],
    ['/buyer/index.php', '/mi-cuenta'],
    ['/buyer/login.php', '/mi-cuenta/acceso'],
  ]) {
    const response = await request.get(real + oldPath + '?token=fixture%2Fvalue&source=correo', {
      maxRedirects: 0,
    });
    expect(response.status(), oldPath + ' redirects permanently').toBe(301);
    const target = new URL(response.headers().location, real);
    expect(target.pathname).toBe(spanishPath);
    expect(target.searchParams.get('token')).toBe('fixture/value');
    expect(target.searchParams.get('source')).toBe('correo');
  }
  for (const [path, title] of [['/mi-cuenta', 'Mis compras'], ['/mi-cuenta/acceso', 'Cuenta de comprador']]) {
    const response = await request.get(real + path, { maxRedirects: 0 });
    expect(response.status(), path + ' serves buyer template').toBe(200);
    expect(await response.text()).toContain(title);
  }
});
