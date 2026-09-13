import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const analyticsJs = readFileSync(join(process.cwd(), 'js/public-analytics.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-analytics-e2e.html';

test('Google Analytics stays off until accepted and can be revoked', async ({ page }) => {
  let googleRequests = 0;
  await page.route('https://www.googletagmanager.com/**', route => {
    googleRequests++;
    return route.fulfill({ contentType: 'text/javascript', body: '' });
  });
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><body><h1>BRVTAL</h1><script data-ga-id="G-AB12CD34">${analyticsJs}</script></body></html>`,
  }));

  await page.goto(harnessUrl);
  await expect(page.getByText('It stays off until you agree.')).toBeVisible();
  expect(googleRequests).toBe(0);
  await page.getByRole('button', { name: 'NO THANKS' }).click();
  await page.reload();
  await expect(page.locator('.analytics-choice')).toHaveCount(0);
  expect(googleRequests).toBe(0);

  await page.getByRole('button', { name: 'ANALYTICS SETTINGS' }).click();
  await page.getByRole('button', { name: 'ALLOW ANALYTICS' }).click();
  await expect.poll(() => googleRequests).toBe(1);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('brvtal.analytics.choice.v1'))).toBe('accepted');

  await page.getByRole('button', { name: 'ANALYTICS SETTINGS' }).click();
  await page.getByRole('button', { name: 'NO THANKS' }).click();
  await expect.poll(() => page.evaluate(() => localStorage.getItem('brvtal.analytics.choice.v1'))).toBe('rejected');
  expect(googleRequests).toBe(1);
});
