import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const consentJs = readFileSync(join(process.cwd(), 'js/cookie-consent.js'), 'utf8');
const analyticsJs = readFileSync(join(process.cwd(), 'js/public-analytics.js'), 'utf8');
const measurementJs = readFileSync(join(process.cwd(), 'js/public-measurement.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-analytics-e2e.html';

test('GTM is blocked by default, rejected safely, and enabled only after explicit opt-in', async ({ page }) => {
  let tagManagerRequests = 0;
  await page.route('https://www.googletagmanager.com/gtm.js**', route => {
    tagManagerRequests++;
    return route.fulfill({ contentType: 'text/javascript', body: '' });
  });
  await page.route('**/js/public-analytics.js*', route =>
    route.fulfill({ contentType: 'text/javascript', body: analyticsJs }));
  await page.route('**/js/public-measurement.js*', route =>
    route.fulfill({ contentType: 'text/javascript', body: measurementJs }));
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      + '<body><h1>BRVTAL</h1><footer></footer>'
      + '<script data-gtm-id="GTM-W23PHGJG" data-version="test">' + consentJs + '</script>'
      + '</body></html>',
  }));

  await page.goto(harnessUrl);
  await expect(page.locator('#brvtal-cookie-banner')).toBeVisible();
  expect(tagManagerRequests).toBe(0);
  expect(await page.evaluate(() => window.dataLayer)).toBeUndefined();

  await page.getByRole('button', { name: 'Rechazar', exact: true }).click();
  expect(tagManagerRequests).toBe(0);
  await page.getByRole('button', { name: 'Preferencias de cookies' }).click();
  await expect(page.locator('#brvtal-cookie-banner')).toBeVisible();
  await page.getByRole('button', { name: 'Aceptar', exact: true }).click();
  await expect.poll(() => tagManagerRequests).toBe(1);
  await expect.poll(() => page.evaluate(() => window.dataLayer?.some(item => item?.event === 'gtm.js'))).toBe(true);
  expect(await page.evaluate(() => document.cookie.includes('brvtal_cookie_choice=accepted'))).toBe(true);

  const commands = await page.evaluate(() => (window.dataLayer || [])
    .filter(item => item && item[0] === 'consent').map(item => [item[0], item[1], item[2]]));
  expect(commands).toContainEqual(['consent', 'default', {
    analytics_storage: 'granted',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
  }]);

  await page.getByRole('button', { name: 'Preferencias de cookies' }).click();
  await page.getByRole('button', { name: 'Rechazar', exact: true }).click();
  await expect.poll(() => page.evaluate(() => window.BRVTALCookieConsent?.isAccepted())).toBe(false);
  await page.reload();
  expect(tagManagerRequests).toBe(1);
  await expect(page.locator('#brvtal-cookie-banner')).toBeHidden();
});

test('direct GTM loader without explicit consent fails closed', async ({ page }) => {
  let requests = 0;
  await page.route('https://www.googletagmanager.com/gtm.js**', route => {
    requests++;
    return route.fulfill({ contentType: 'text/javascript', body: '' });
  });
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html',
    body: '<html><body><script data-gtm-id="GTM-W23PHGJG">' + analyticsJs + '</script></body></html>',
  }));
  await page.goto(harnessUrl);
  expect(requests).toBe(0);
  expect(await page.evaluate(() => window.__BRVTAL_GTM_LOADED__)).toBeUndefined();
});
