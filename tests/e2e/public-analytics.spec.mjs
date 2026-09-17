import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const analyticsJs = readFileSync(join(process.cwd(), 'js/public-analytics.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-analytics-e2e.html';

test('Google Tag Manager loads immediately without an acceptance gate', async ({ page }) => {
  let tagManagerRequests = 0;
  await page.route('https://www.googletagmanager.com/gtm.js**', route => {
    tagManagerRequests++;
    return route.fulfill({ contentType: 'text/javascript', body: '' });
  });
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><body><h1>BRVTAL</h1><script data-gtm-id="GTM-W23PHGJG">${analyticsJs}</script></body></html>`,
  }));

  await page.goto(harnessUrl);

  await expect.poll(() => tagManagerRequests).toBe(1);
  await expect(page.locator('.analytics-choice')).toHaveCount(0);
  await expect(page.locator('.analytics-preferences')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => window.dataLayer?.some(item => item?.event === 'gtm.js'))).toBe(true);

  const consentCommands = await page.evaluate(() => (window.dataLayer || [])
    .filter(item => item && item[0] === 'consent')
    .map(item => [item[0], item[1], item[2]]));

  expect(consentCommands).toContainEqual(['consent', 'default', {
    analytics_storage: 'granted',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
  }]);
  expect(await page.evaluate(() => localStorage.getItem('brvtal.analytics.choice.v1'))).toBeNull();
});
