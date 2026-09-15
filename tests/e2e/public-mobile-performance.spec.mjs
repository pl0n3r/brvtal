import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const mobileScript = readFileSync(join(process.cwd(), 'js/mobile-performance.js'), 'utf8');
const runtimeLoader = readFileSync(join(process.cwd(), 'js/public-runtime-loader.js'), 'utf8');
const publicEntry = readFileSync(join(process.cwd(), 'index.php'), 'utf8');
const publicHtml = readFileSync(join(process.cwd(), 'index.html'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-motion-runtime-e2e.html';
const version = 'abc123';
const enhancementMarkers = {
  '/js/menu-accessibility.js': 'menu-accessibility',
  '/js/input-accessibility.js': 'input-accessibility',
  '/js/mobile-events.js': 'mobile-events',
  '/js/hero-slider.js': 'hero-slider',
  '/js/public-discovery-url-state.js': 'url-state',
  '/js/public-canonical-navigation.js': 'canonical-navigation'
};
const coreAndEnhancements = ['menu-scroll-lock', 'app', 'archive', 'media', 'menu-accessibility', 'input-accessibility', 'mobile-events', 'hero-slider', 'url-state', 'canonical-navigation'];

const isMotionCdn = url => url.includes('cdn.jsdelivr.net/npm/gsap@3.13.0') || url.includes('cdn.jsdelivr.net/npm/lenis@1.3.4');

async function openRuntimeHarness(page, { coarse = false, reduced = false, failScrollTrigger = false } = {}) {
  const requests = [];

  await page.route('**/*', async route => {
    const url = route.request().url();
    requests.push(url);
    const parsed = new URL(url);

    if (url === harnessUrl) {
      await route.fulfill({
        contentType: 'text/html; charset=utf-8',
        body: `<!doctype html><html><body>
          <div id="loader">LOADING</div>
          <canvas id="fxCanvas"></canvas>
          <script>
            window.__runtimeOrder = [];
            window.matchMedia = query => ({
              matches: query === '(pointer: coarse)' ? ${coarse ? 'true' : 'false'} : query === '(prefers-reduced-motion: reduce)' ? ${reduced ? 'true' : 'false'} : false,
              media: query,
              addEventListener(){},
              removeEventListener(){}
            });
          </script>
          <script src="/js/public-runtime-loader.js?v=${version}"></script>
        </body></html>`,
      });
      return;
    }

    if (parsed.pathname === '/js/public-runtime-loader.js') {
      await route.fulfill({ contentType: 'text/javascript', body: runtimeLoader });
      return;
    }

    if (parsed.pathname === '/js/mobile-performance.js') {
      await route.fulfill({ contentType: 'text/javascript', body: `${mobileScript}\nwindow.__runtimeOrder.push('mobile-performance');` });
      return;
    }

    const coreMarkers = {
      '/js/menu-scroll-lock.js': 'menu-scroll-lock',
      '/js/app.js': 'app',
      '/js/archive.js': 'archive',
      '/js/public-media.js': 'media'
    };
    const marker = coreMarkers[parsed.pathname] || enhancementMarkers[parsed.pathname];
    if (marker) {
      await route.fulfill({ contentType: 'text/javascript', body: `window.__runtimeOrder.push('${marker}');` });
      return;
    }

    if (url.includes('gsap.min.js')) {
      await route.fulfill({ contentType: 'text/javascript', body: "window.__runtimeOrder.push('gsap'); window.gsap = {};" });
      return;
    }
    if (url.includes('ScrollTrigger.min.js')) {
      if (failScrollTrigger) {
        await route.fulfill({ status: 503, contentType: 'text/plain', body: 'unavailable' });
      } else {
        await route.fulfill({ contentType: 'text/javascript', body: "window.__runtimeOrder.push('scrolltrigger'); window.ScrollTrigger = {};" });
      }
      return;
    }
    if (url.includes('lenis.min.js')) {
      await route.fulfill({ contentType: 'text/javascript', body: "window.__runtimeOrder.push('lenis'); window.Lenis = function Lenis() {};" });
      return;
    }

    await route.fulfill({ status: 404, body: '' });
  });

  await page.goto(harnessUrl);
  await page.evaluate(() => window.BRVTALRuntimeReady);
  return requests;
}

test('base public html exposes only the adaptive runtime entry', async () => {
  expect(publicHtml).toContain('<script src="js/public-runtime-loader.js"></script>');
  expect(publicHtml).not.toContain('cdn.jsdelivr.net/npm/gsap');
  expect(publicHtml).not.toContain('cdn.jsdelivr.net/npm/lenis');
  expect(publicHtml).not.toContain('<script src="js/app.js"></script>');
  expect(publicEntry).not.toContain("str_replace('<script src=\"js/app.js\"></script>'");
  expect(publicEntry).not.toContain('js/mobile-events.js');
});

test('touch runtime skips desktop motion downloads and preserves versioned module order', async ({ page }) => {
  const requests = await openRuntimeHarness(page, { coarse: true });

  expect(requests.filter(isMotionCdn)).toHaveLength(0);
  expect(requests.some(url => url.endsWith(`/js/mobile-performance.js?v=${version}`))).toBe(true);
  expect(requests.some(url => url.endsWith(`/js/menu-scroll-lock.js?v=${version}`))).toBe(true);
  expect(requests.some(url => url.endsWith(`/js/app.js?v=${version}`))).toBe(true);
  expect(requests.some(url => url.endsWith(`/js/public-discovery-url-state.js?v=${version}`))).toBe(true);
  await expect(page.locator('#loader')).toHaveCount(0);
  await expect(page.locator('#fxCanvas')).toHaveCount(0);
  await expect(page.locator('html')).toHaveAttribute('data-motion-runtime', 'touch-lite');
  expect(await page.evaluate(() => window.__runtimeOrder)).toEqual(['mobile-performance', ...coreAndEnhancements]);
});

test('reduced-motion runtime skips desktop motion downloads without requiring touch mode', async ({ page }) => {
  const requests = await openRuntimeHarness(page, { reduced: true });

  expect(requests.filter(isMotionCdn)).toHaveLength(0);
  expect(requests.some(url => url.includes('/js/mobile-performance.js'))).toBe(false);
  await expect(page.locator('html')).toHaveAttribute('data-motion-runtime', 'reduced-lite');
  expect(await page.evaluate(() => window.__runtimeOrder)).toEqual(coreAndEnhancements);
});

test('fine-pointer full-motion runtime loads motion stack before all public modules', async ({ page }) => {
  const requests = await openRuntimeHarness(page);

  expect(requests.filter(isMotionCdn)).toHaveLength(3);
  expect(requests.some(url => url.includes('/js/mobile-performance.js'))).toBe(false);
  await expect(page.locator('html')).toHaveAttribute('data-motion-runtime', 'enhanced');
  expect(await page.evaluate(() => window.__runtimeOrder)).toEqual(['gsap', 'scrolltrigger', 'lenis', ...coreAndEnhancements]);
});

test('core runtime still starts when an enhanced-motion CDN dependency fails', async ({ page }) => {
  await openRuntimeHarness(page, { failScrollTrigger: true });

  await expect(page.locator('html')).toHaveAttribute('data-motion-runtime', 'fallback');
  expect(await page.evaluate(() => window.__runtimeOrder)).toEqual(['gsap', ...coreAndEnhancements]);
});
