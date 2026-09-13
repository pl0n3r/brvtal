import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = readFileSync(join(process.cwd(), 'css/mobile-events.css'), 'utf8');
const enhancement = readFileSync(join(process.cwd(), 'js/mobile-events.js'), 'utf8');

test('mobile events use native horizontal scrolling without killing scene tracking', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>
    <section class="events">
      <div class="events-track" style="transform: translate3d(-200px, 0, 0)">
        <article class="event-card">ONE</article>
        <article class="event-card">TWO</article>
      </div>
    </section>
  </body></html>`);

  await page.evaluate(() => {
    const events = document.querySelector('.events');
    window.__pinKilled = false;
    window.__sceneKilled = false;
    window.__refreshed = false;
    window.ScrollTrigger = {
      getAll: () => [
        {
          trigger: events,
          vars: { pin: true },
          animation: { kill: () => { window.__animationKilled = true; } },
          kill: () => { window.__pinKilled = true; },
        },
        {
          trigger: events,
          vars: { pin: false },
          kill: () => { window.__sceneKilled = true; },
        },
      ],
      refresh: () => { window.__refreshed = true; },
    };
    window.gsap = {
      set: (element) => { element.style.transform = ''; },
    };
  });

  await page.addScriptTag({ content: enhancement });
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));

  const track = page.locator('.events-track');
  await expect(page.locator('html')).toHaveClass(/native-events-scroll/);
  await expect(track).toHaveAttribute('role', 'region');
  await expect(track).toHaveAttribute('tabindex', '0');
  await expect(track).toHaveAttribute('aria-label', /Swipe or scroll horizontally/i);
  await expect.poll(() => track.evaluate(el => getComputedStyle(el).overflowX)).toBe('auto');
  await expect.poll(() => track.evaluate(el => getComputedStyle(el).scrollSnapType)).toContain('mandatory');

  const state = await page.evaluate(() => ({
    pinKilled: window.__pinKilled,
    sceneKilled: window.__sceneKilled,
    animationKilled: window.__animationKilled,
    refreshed: window.__refreshed,
    transform: document.querySelector('.events-track').style.transform,
  }));

  expect(state.pinKilled).toBe(true);
  expect(state.sceneKilled).toBe(false);
  expect(state.animationKilled).toBe(true);
  expect(state.refreshed).toBe(true);
  expect(state.transform).toBe('');
});

test('desktop leaves the events track untouched', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.setContent(`<!doctype html><html><head><style>${css}</style></head><body><section class="events"><div class="events-track" style="transform: translateX(-100px)"><article class="event-card">ONE</article></div></section></body></html>`);
  await page.evaluate(() => {
    window.__pinKilled = false;
    window.ScrollTrigger = {
      getAll: () => [{ trigger: document.querySelector('.events'), vars: { pin: true }, kill: () => { window.__pinKilled = true; } }],
      refresh: () => {},
    };
  });
  await page.addScriptTag({ content: enhancement });

  await expect(page.locator('html')).not.toHaveClass(/native-events-scroll/);
  expect(await page.evaluate(() => window.__pinKilled)).toBe(false);
  await expect(page.locator('.events-track')).not.toHaveAttribute('role', 'region');
});
