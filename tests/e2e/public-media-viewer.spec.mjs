import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const scrollLockJs = readFileSync(join(process.cwd(), 'js/menu-scroll-lock.js'), 'utf8');
const publicMediaJs = readFileSync(join(process.cwd(), 'js/public-media.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-media-viewer-scroll-lock-e2e.html';

test('public Media viewer locks background scroll and cooperates with other modal owners', async ({ page }) => {
  await page.route('**/*', async route => {
    const url = route.request().url();
    const parsed = new URL(url);

    if (url === harnessUrl) {
      await route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: `<!doctype html><html><head><style>
          body{margin:0}.spacer{height:3000px}
          #trigger{position:absolute;top:700px}
          .public-media-viewer{position:fixed;inset:0;z-index:10;background:#111}
        </style></head><body>
          <button id="trigger" type="button">OPEN MEDIA</button>
          <div class="media-grid"></div>
          <div class="spacer"></div>
          <script>
            window.__lenisStats = { stops: 0, starts: 0, resizes: 0 };
            window.Lenis = class FakeLenis {
              constructor(){ this.isStopped = false; }
              stop(){ this.isStopped = true; window.__lenisStats.stops++; }
              start(){ this.isStopped = false; window.__lenisStats.starts++; }
              resize(){ window.__lenisStats.resizes++; }
            };
          </script>
          <script>${scrollLockJs}</script>
          <script>window.__lenisInstance = new Lenis();</script>
          <script>${publicMediaJs}</script>
        </body></html>`,
      });
      return;
    }

    if (parsed.pathname === '/api/public-image-delivery.php') {
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: {} }) });
      return;
    }

    if (parsed.pathname === '/uploads/test.jpg') {
      await route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"><rect width="16" height="16"/></svg>'
      });
      return;
    }

    await route.fulfill({ status: 404, body: '' });
  });

  await page.goto(harnessUrl);
  const trigger = page.locator('#trigger');
  await page.evaluate(() => window.scrollTo(0, 500));
  await trigger.focus();

  await page.evaluate(() => {
    window.BRVTALPublicMedia.openViewer({
      id: 1,
      type: 'image',
      file_path: '/uploads/test.jpg',
      title: 'Test memory',
      alt_text: 'Test memory'
    }, document.getElementById('trigger'));
  });

  await expect(page.locator('#public-media-viewer')).toBeVisible();
  await expect(page.locator('[data-public-media-close]')).toBeFocused();
  expect(await page.evaluate(() => window.BRVTALScrollLock.has('media-viewer'))).toBe(true);
  await expect(page.locator('html')).toHaveClass(/public-scroll-locked/);
  await expect(page.locator('body')).toHaveClass(/public-scroll-locked/);
  expect(await page.locator('body').evaluate(el => el.style.position)).toBe('fixed');
  expect(await page.locator('body').evaluate(el => el.style.top)).toBe('-500px');
  expect(await page.evaluate(() => window.__lenisStats.stops)).toBe(1);
  expect(await page.evaluate(() => window.__lenisInstance.isStopped)).toBe(true);

  await page.mouse.wheel(0, 900);
  expect(await page.locator('body').evaluate(el => el.style.top)).toBe('-500px');

  await page.evaluate(() => window.BRVTALScrollLock.lock('menu'));
  await page.keyboard.press('Escape');
  await expect(page.locator('#public-media-viewer')).toHaveCount(0);
  await expect(trigger).toBeFocused();
  expect(await page.evaluate(() => window.BRVTALScrollLock.has('media-viewer'))).toBe(false);
  expect(await page.evaluate(() => window.BRVTALScrollLock.has('menu'))).toBe(true);
  expect(await page.evaluate(() => window.BRVTALScrollLock.isLocked())).toBe(true);
  expect(await page.evaluate(() => window.__lenisStats.starts)).toBe(0);
  expect(await page.locator('body').evaluate(el => el.style.top)).toBe('-500px');

  await page.evaluate(() => window.BRVTALScrollLock.unlock('menu'));
  await expect(page.locator('html')).not.toHaveClass(/public-scroll-locked/);
  await expect(page.locator('body')).not.toHaveClass(/public-scroll-locked/);
  expect(await page.evaluate(() => window.scrollY)).toBe(500);
  expect(await page.evaluate(() => window.__lenisStats.starts)).toBe(1);
  expect(await page.evaluate(() => window.__lenisStats.resizes)).toBe(1);
  expect(await page.evaluate(() => window.__lenisInstance.isStopped)).toBe(false);
});
