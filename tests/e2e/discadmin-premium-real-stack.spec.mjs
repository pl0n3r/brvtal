import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL real-stack admin credentials are required');

async function login(page) {
  const response = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data:{email:adminEmail,password:adminPassword},
  });
  expect(response.ok(), `Admin login failed with HTTP ${response.status()}`).toBeTruthy();
  const payload = await response.json();
  expect(payload.ok).toBe(true);
}

async function openModule(page, moduleName, rootSelector) {
  await page.goto(`${baseUrl}/discadmin/`, {waitUntil:'domcontentloaded'});
  await page.waitForFunction(() => Boolean(window.state?.authed), null, {timeout:10_000});
  await page.evaluate(async module => {
    await window.go?.(module);
  }, moduleName);
  await expect(page.locator(rootSelector)).toBeVisible({timeout:10_000});
}

async function computedFont(page, selector) {
  return page.locator(selector).first().evaluate(el => parseFloat(getComputedStyle(el).fontSize));
}

test('real DISCADMIN uses the readable premium type scale on desktop and mobile', async ({ page }) => {
  await login(page);
  await openModule(page, 'dashboard', '#brvtal-dashboard-v2');

  expect(await computedFont(page, 'body')).toBeGreaterThanOrEqual(14);
  expect(await computedFont(page, '.nav button:visible')).toBeGreaterThanOrEqual(13);
  expect(await computedFont(page, '.dashboard-v2-sub')).toBeGreaterThanOrEqual(13);
  expect(await computedFont(page, '.dashboard-v2-row-meta')).toBeGreaterThanOrEqual(12);

  await page.evaluate(async () => {
    await window.go?.('settings');
  });
  await expect(page.getByTestId('settings-v2-root')).toBeVisible({timeout:10_000});
  expect(await computedFont(page, '.sv2-tabs button:visible')).toBeGreaterThanOrEqual(12);
  expect(await computedFont(page, '.sv2-hero p')).toBeGreaterThanOrEqual(13);

  await page.setViewportSize({width:390,height:844});
  await page.evaluate(async () => {
    await window.go?.('dashboard');
  });
  await expect(page.locator('#brvtal-dashboard-v2')).toBeVisible({timeout:10_000});
  expect(await computedFont(page, 'body')).toBeGreaterThanOrEqual(14);
  expect(await computedFont(page, '.nav button')).toBeGreaterThanOrEqual(13);
});
