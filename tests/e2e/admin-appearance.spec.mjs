import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'discadmin/admin-appearance.js'), 'utf8');
const css = readFileSync(join(process.cwd(), 'discadmin/admin-appearance.css'), 'utf8');
const wrapper = readFileSync(join(process.cwd(), 'discadmin/index.php'), 'utf8');
const harness = 'http://127.0.0.1:4173/admin-appearance-harness.html';

async function openHarness(page) {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><head><style>:root{--red:#ff2038}</style><style>${css}</style></head><body><div class="shell"><aside class="side"><div class="logo">BRVTAL</div><nav class="nav"><button>Dashboard</button></nav><div class="sidefoot"><button id="logout">LOGOUT</button></div></aside><main class="main"><div class="top"><h1>DASHBOARD</h1></div><section class="dashsection">CONTENT</section></main></div><script>${script}</script></body></html>`
  }));
  await page.goto(harness);
}

test('appearance selector exposes dark light and glass directly above logout', async ({ page }) => {
  await openHarness(page);
  const selector = page.locator('.discadmin-appearance');
  await expect(selector).toBeVisible();
  await expect(selector.locator('[data-discadmin-appearance]')).toHaveCount(3);
  await expect(page.locator('.sidefoot').locator('.discadmin-appearance')).toBeVisible();
  const selectorBox = await selector.boundingBox();
  const logoutBox = await page.locator('#logout').boundingBox();
  expect(selectorBox?.y).toBeLessThan(logoutBox?.y ?? 0);
});

test('appearance changes instantly and persists in localStorage', async ({ page }) => {
  await openHarness(page);
  await page.locator('[data-discadmin-appearance="light"]').click();
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','light');
  expect(await page.evaluate(() => localStorage.getItem('brvtal.discadmin.appearance'))).toBe('light');
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','light');
  await expect(page.locator('[data-discadmin-appearance="light"]')).toHaveAttribute('aria-checked','true');
  await page.locator('[data-discadmin-appearance="glass"]').click();
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','glass');
});

test('mobile selector keeps touch targets and supports keyboard radio navigation', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await openHarness(page);
  const dark = page.locator('[data-discadmin-appearance="dark"]');
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
  expect(css).toContain('data-discadmin-appearance="light"');
  expect(css).toContain('data-discadmin-appearance="glass"');
});
