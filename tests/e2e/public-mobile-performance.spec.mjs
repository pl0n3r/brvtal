import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'js/mobile-performance.js'), 'utf8');
const publicEntry = readFileSync(join(process.cwd(), 'index.php'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-mobile-performance-e2e.html';

async function openHarness(page, coarse) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><body>
      <div id="loader">LOADING</div>
      <canvas id="fxCanvas"></canvas>
      <script>
        window.matchMedia = query => ({matches: query === '(pointer: coarse)' ? ${coarse ? 'true' : 'false'} : false, media: query, addEventListener(){}, removeEventListener(){}});
        window.Lenis = function Lenis() {};
      </script>
      <script>${script}</script>
    </body></html>`,
  }));
  await page.goto(harnessUrl);
}

test('touch layouts skip artificial loader and disable continuous desktop effects before app runtime', async ({ page }) => {
  expect(publicEntry).toContain("str_replace('<script src=\"js/app.js\"></script>'");
  expect(publicEntry).toContain('<script src=\\"js/mobile-performance.js\\"></script>\\n  <script src=\\"js/app.js\\"></script>');

  await openHarness(page, true);
  await expect(page.locator('#loader')).toHaveCount(0);
  await expect(page.locator('#fxCanvas')).toHaveCount(0);
  await expect(page.locator('html')).toHaveClass(/touch-performance-mode/);
  expect(await page.evaluate(() => window.Lenis)).toBeNull();
});

test('fine pointer layouts preserve desktop loader and effects', async ({ page }) => {
  await openHarness(page, false);
  await expect(page.locator('#loader')).toHaveCount(1);
  await expect(page.locator('#fxCanvas')).toHaveCount(1);
  await expect(page.locator('html')).not.toHaveClass(/touch-performance-mode/);
  expect(await page.evaluate(() => typeof window.Lenis)).toBe('function');
});
