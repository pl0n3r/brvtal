import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'discadmin/admin-appearance.js'), 'utf8');
const css = readFileSync(join(process.cwd(), 'discadmin/admin-appearance.css'), 'utf8');
const wrapper = readFileSync(join(process.cwd(), 'discadmin/index.php'), 'utf8');
const auth = readFileSync(join(process.cwd(), 'config/admin_auth.php'), 'utf8');
const harness = 'http://127.0.0.1:4173/admin-appearance-harness.html';
const loginHarness = 'http://127.0.0.1:4173/admin-appearance-login-harness.html';

const styles = `:root{--red:#ff2038;--green:#49d98a}*{box-sizing:border-box}body{margin:0}.shell{display:grid;grid-template-columns:245px 1fr;min-height:100vh}.side{padding:24px}.sidefoot{margin-top:24px}.main{padding:24px}.nav button{display:block;width:100%}.login{min-height:100vh;display:grid;place-items:center}.loginbox{width:420px;padding:30px}.syscheck{padding:12px}`;

async function routeHarness(page, url, body) {
  await page.route(url, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><head><style>${styles}</style><style>${css}</style></head><body>${body}<script>${script}</script></body></html>`
  }));
}

async function openHarness(page) {
  await routeHarness(page, harness, `
    <div class="shell">
      <aside class="side"><div class="logo">BRVTAL</div><nav class="nav"><button class="active">Dashboard</button></nav><div class="sidefoot"><button id="logout">LOGOUT</button></div></aside>
      <main class="main">
        <div class="top"><h1>DASHBOARD</h1><button class="brvtal-global-search-trigger">SEARCH</button></div>
        <section class="dashsection">CONTENT</section>
        <section class="settings-v2"><div class="sv2-pane active">SETTINGS</div></section>
        <section id="system-status-v2"><div class="ssv2-panel">SYSTEM STATUS</div></section>
        <section class="content-health-panel"><h2 class="content-health-title">CONTENT HEALTH</h2></section>
        <section class="activity-panel">ADMIN ACTIVITY</section>
        <section class="brvtal-seo-section">SEO</section>
      </main>
    </div>`);
  await page.goto(harness);
}

async function openLoginHarness(page) {
  await routeHarness(page, loginHarness, `
    <div class="login"><form class="loginbox"><h1>BRVTAL</h1><div class="sub">DISCADMIN / CONTROL ROOM</div><div class="syscheck">SYSTEM READY</div><input aria-label="Email"><button type="button">ENTER</button></form></div>`);
  await page.goto(loginHarness);
}

async function rgbTotal(locator) {
  return locator.evaluate(element => {
    const match = getComputedStyle(element).backgroundColor.match(/[\d.]+/g) || [];
    return match.slice(0,3).reduce((sum,value) => sum + Number(value), 0);
  });
}

test('appearance selector exposes dark light and glass directly above logout', async ({ page }) => {
  await openHarness(page);
  const selector = page.locator('[data-appearance-selector="sidebar"]');
  await expect(selector).toBeVisible();
  await expect(selector.locator('button[data-discadmin-appearance]')).toHaveCount(3);
  const selectorBox = await selector.boundingBox();
  const logoutBox = await page.locator('#logout').boundingBox();
  expect(selectorBox?.y).toBeLessThan(logoutBox?.y ?? 0);
  await expect(page.locator('html')).not.toHaveAttribute('aria-checked', /.+/);
});

test('appearance changes instantly and persists in localStorage', async ({ page }) => {
  await openHarness(page);
  await page.locator('button[data-discadmin-appearance="light"]').click();
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','light');
  expect(await page.evaluate(() => localStorage.getItem('brvtal.discadmin.appearance'))).toBe('light');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','light');
  await expect(page.locator('button[data-discadmin-appearance="light"]')).toHaveAttribute('aria-checked','true');
  await page.locator('button[data-discadmin-appearance="glass"]').click();
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','glass');
});

test('Light applies to modern dashboard, settings, status, search and editorial surfaces', async ({ page }) => {
  await openHarness(page);
  await page.locator('button[data-discadmin-appearance="light"]').click();
  for (const selector of [
    '.dashsection',
    '.sv2-pane',
    '.ssv2-panel',
    '.content-health-panel',
    '.activity-panel',
    '.brvtal-seo-section',
    '.brvtal-global-search-trigger'
  ]) {
    await expect.poll(
      () => rgbTotal(page.locator(selector)),
      { message: selector, timeout: 1000 }
    ).toBeGreaterThan(620);
  }
  const titleColor = await page.locator('.content-health-title').evaluate(element => getComputedStyle(element).color);
  expect(titleColor).toMatch(/^rgb\((1[0-9]|2[0-9]|3[0-9])/);
});

test('Glass exposes translucent blurred material instead of an opaque Dark surface', async ({ page }) => {
  await openHarness(page);
  await page.locator('button[data-discadmin-appearance="glass"]').click();
  const material = await page.locator('.sv2-pane').evaluate(element => {
    const style = getComputedStyle(element);
    return {background:style.backgroundColor,backdrop:style.backdropFilter || style.webkitBackdropFilter};
  });
  expect(material.background).toMatch(/^rgba\(.+, 0\.[0-9]+\)$/);
  expect(material.backdrop).toContain('blur(');
});

test('login inherits saved appearance and offers the same accessible selector', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('brvtal.discadmin.appearance','light'));
  await openLoginHarness(page);
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','light');
  const selector = page.locator('[data-appearance-selector="login"]');
  await expect(selector).toBeVisible();
  await expect(selector.locator('button[data-discadmin-appearance]')).toHaveCount(3);
  await expect(selector.locator('button[data-discadmin-appearance="light"]')).toHaveAttribute('aria-checked','true');
  expect(await rgbTotal(page.locator('.loginbox'))).toBeGreaterThan(620);
});

test('appearance survives an authenticated-to-login DOM replacement', async ({ page }) => {
  await openHarness(page);
  await page.locator('button[data-discadmin-appearance="glass"]').click();
  await page.evaluate(() => {
    document.body.innerHTML = '<div class="login"><form class="loginbox"><h1>BRVTAL</h1><div class="sub">DISCADMIN</div><div class="syscheck">READY</div></form></div>';
  });
  await expect(page.locator('[data-appearance-selector="login"]')).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','glass');
  await expect(page.locator('button[data-discadmin-appearance="glass"]')).toHaveAttribute('aria-checked','true');
});

test('mobile selector keeps touch targets and supports keyboard radio navigation', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await openHarness(page);
  const dark = page.locator('button[data-discadmin-appearance="dark"]');
  const box = await dark.boundingBox();
  expect(box?.height).toBeGreaterThanOrEqual(44);
  await dark.focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','light');
  await page.keyboard.press('End');
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','glass');
});

test('wrapper loads early appearance bootstrap and versioned appearance assets', async () => {
  expect(wrapper).toContain('brvtal.discadmin.appearance');
  expect(wrapper).toContain('/discadmin/admin-appearance.css');
  expect(wrapper).toContain('/discadmin/admin-appearance.js');
  expect(wrapper.indexOf('$appearanceBoot')).toBeLessThan(wrapper.indexOf('$enhancements'));
  expect(css).toContain('--admin-surface');
  expect(css).toContain('data-discadmin-appearance="light"');
  expect(css).toContain('data-discadmin-appearance="glass"');
});

test('admin session uses rolling long-lived cookie without weakening core protections', async () => {
  expect(auth).toContain('BRVTAL_ADMIN_IDLE_TIMEOUT = 604800');
  expect(auth).toContain('BRVTAL_ADMIN_ABSOLUTE_TIMEOUT = 2592000');
  expect(auth).toContain('BRVTAL_ADMIN_COOKIE_LIFETIME = 2592000');
  expect(auth).toContain('brvtal_admin_refresh_cookie');
  expect(auth).toContain("'httponly' => true");
  expect(auth).toContain("'samesite' => 'Strict'");
  expect(auth).toContain('session_regenerate_id(true)');
  expect(auth).toContain('hash_equals($expected, $token)');
});