import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';

// Spanish-only public navigation is the canonical partial served by index.php.
const nav = readFileSync(join(process.cwd(), 'partials/home/nav.html'), 'utf8');
const runtime = readFileSync(join(process.cwd(), 'js/app.js'), 'utf8');

test('Spanish-only navigation has no language selector and keeps the menu accessible', async ({page}) => {
  await page.setContent('<!doctype html><html lang="es"><body>' + nav + '</body></html>');
  await expect(page.locator('html')).toHaveAttribute('lang', 'es');
  await expect(page.locator('#languageSelector')).toHaveCount(0);
  await expect(page.locator('[data-locale="en"]')).toHaveCount(0);
  await expect(page.locator('#menuToggle')).toBeVisible();
  await page.keyboard.press('Tab');
  const buttons = await page.locator('button:visible').count();
  expect(buttons).toBeGreaterThan(0);
});

test('Spanish-only mobile navigation has no language toggle or horizontal overflow', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await page.setContent('<!doctype html><html lang="es"><head><style>body{margin:0}header{display:flex;justify-content:space-between;align-items:center;padding:0 16px;box-sizing:border-box}</style></head><body>' + nav + '</body></html>');
  await expect(page.locator('html')).toHaveAttribute('lang','es');
  await expect(page.locator('#menuToggle')).toBeVisible();
  await expect(page.locator('#languageSelector')).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('Spanish-only client rejects stale EN localStorage and never changes document language to EN', async ({page}) => {
  await page.setContent('<!doctype html><html lang="es"><body>' + nav + '</body></html>');
  const source=runtime;
  expect(source).toContain("window.localStorage.removeItem('brvtal.public.locale')");
  expect(source).toContain("if (locale !== 'es') return false");
  expect(source).not.toContain('document.documentElement.lang = locale');
});
