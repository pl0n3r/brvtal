import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const scrollManager = readFileSync(join(process.cwd(), 'js/menu-scroll-lock.js'), 'utf8');

test('desktop resize refreshes Lenis and ScrollTrigger without leaving the page locked', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.setContent('<!doctype html><html><body style="margin:0"><main style="height:4200px"></main></body></html>');

  await page.evaluate(() => {
    window.__resizeStats = { lenis: 0, triggers: 0 };
    window.Lenis = class FakeLenis {
      constructor() { this.isStopped = false; }
      stop() { this.isStopped = true; }
      start() { this.isStopped = false; }
      resize() { window.__resizeStats.lenis += 1; }
    };
    window.ScrollTrigger = {
      refresh() { window.__resizeStats.triggers += 1; }
    };
  });
  await page.addScriptTag({ content: scrollManager });
  await page.evaluate(() => {
    window.__testLenis = new Lenis();
    window.scrollTo(0, 700);
  });

  await page.setViewportSize({ width: 900, height: 780 });
  await page.waitForTimeout(40);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.waitForTimeout(40);

  const stats = await page.evaluate(() => window.__resizeStats);
  expect(stats.lenis).toBeGreaterThanOrEqual(2);
  expect(stats.triggers).toBeGreaterThanOrEqual(2);
  await expect(page.locator('html')).not.toHaveClass(/public-scroll-locked|menu-scroll-locked/);
  await expect(page.locator('body')).not.toHaveClass(/public-scroll-locked|menu-scroll-locked/);

  const before = await page.evaluate(() => window.scrollY);
  await page.mouse.wheel(0, 500);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(before + 100);
});
