import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const analyticsJs = readFileSync(join(process.cwd(), 'js/public-analytics.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-analytics-e2e.html';

test('Google Tag Manager stays off until accepted and revocation queues denied consent before reload', async ({ page }) => {
  let tagManagerRequests = 0;
  await page.route('https://www.googletagmanager.com/gtm.js**', route => {
    tagManagerRequests++;
    return route.fulfill({
      contentType: 'text/javascript',
      body: `
        localStorage.setItem('brvtal.test.gtm-loaded', '1');
        window.dataLayer.push = new Proxy(window.dataLayer.push, {
          apply(target, thisArg, args) {
            const item = args[0];
            if (item && item[0] === 'consent' && item[1] === 'update' && item[2]?.analytics_storage === 'denied') {
              localStorage.setItem('brvtal.test.consent-denied', '1');
            }
            return Reflect.apply(target, thisArg, args);
          }
        });
      `,
    });
  });
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><body><h1>BRVTAL</h1><script data-gtm-id="GTM-W23PHGJG">${analyticsJs}</script></body></html>`,
  }));

  await page.goto(harnessUrl);
  await expect(page.getByText('Tracking stays off until you agree.')).toBeVisible();
  expect(tagManagerRequests).toBe(0);
  await page.getByRole('button', { name: 'NO THANKS' }).click();
  await page.reload();
  await expect(page.locator('.analytics-choice')).toHaveCount(0);
  expect(tagManagerRequests).toBe(0);

  await page.getByRole('button', { name: 'ANALYTICS SETTINGS' }).click();
  await page.getByRole('button', { name: 'ALLOW ANALYTICS' }).click();
  await expect.poll(() => tagManagerRequests).toBe(1);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('brvtal.analytics.choice.v1'))).toBe('accepted');
  await expect.poll(() => page.evaluate(() => localStorage.getItem('brvtal.test.gtm-loaded'))).toBe('1');
  await expect.poll(() => page.evaluate(() => window.dataLayer?.some(item => item?.event === 'gtm.js'))).toBe(true);

  const consentCommands = await page.evaluate(() => (window.dataLayer || [])
    .filter(item => item && item[0] === 'consent')
    .map(item => [item[0], item[1], item[2]]));
  expect(consentCommands).toContainEqual(['consent', 'default', {
    analytics_storage: 'denied',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
  }]);
  expect(consentCommands).toContainEqual(['consent', 'update', {
    analytics_storage: 'granted',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
  }]);

  await page.getByRole('button', { name: 'ANALYTICS SETTINGS' }).click();
  const reloaded = page.waitForEvent('framenavigated');
  await page.getByRole('button', { name: 'NO THANKS' }).click();
  await reloaded;
  await expect.poll(() => page.evaluate(() => localStorage.getItem('brvtal.analytics.choice.v1'))).toBe('rejected');
  await expect.poll(() => page.evaluate(() => localStorage.getItem('brvtal.test.consent-denied'))).toBe('1');
  await expect(page.locator('.analytics-choice')).toHaveCount(0);
  expect(tagManagerRequests).toBe(1);
});
