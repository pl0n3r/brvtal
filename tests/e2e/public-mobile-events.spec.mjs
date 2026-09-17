import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = readFileSync(join(process.cwd(), 'css/mobile-events.css'), 'utf8');
const enhancement = readFileSync(join(process.cwd(), 'js/mobile-events.js'), 'utf8');
const baseCss = '.events-track{display:flex;gap:24px}.event-card{flex:0 0 auto;height:260px;background:#222}';

async function mountEvents(page, width = 1440, height = 900) {
  await page.setViewportSize({ width, height });
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${baseCss}${css}</style></head><body>
    <section class="events">
      <div class="events-track" style="transform:translate3d(-200px,0,0)">
        ${Array.from({ length: 6 }, (_, index) => `<article class="event-card">EVENT ${index + 1}</article>`).join('')}
      </div>
    </section>
    <div style="height:1800px"></div>
  </body></html>`);
}

test('Events use native horizontal browsing and remove the legacy pin on desktop', async ({ page }) => {
  await mountEvents(page);
  await page.evaluate(() => {
    const events = document.querySelector('.events');
    window.__pinKilled = false;
    window.__sceneKilled = false;
    window.__animationKilled = false;
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
    window.gsap = { set: element => { element.style.transform = ''; } };
  });

  await page.addScriptTag({ content: enhancement });
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));

  const track = page.locator('.events-track');
  await expect(page.locator('html')).toHaveClass(/native-events-scroll/);
  await expect(track).toHaveAttribute('role', 'region');
  await expect(track).toHaveAttribute('tabindex', '0');
  await expect(track).toHaveAttribute('aria-label', /Drag, swipe or scroll horizontally/i);
  await expect.poll(() => track.evaluate(el => getComputedStyle(el).overflowX)).toBe('auto');
  await expect.poll(() => track.evaluate(el => getComputedStyle(el).scrollSnapType)).toContain('inline');
  expect(css).toContain('scroll-snap-type: inline proximity');

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

test('vertical wheel input over Events continues down the page on mobile', async ({ page }) => {
  await mountEvents(page, 390, 844);
  await page.addScriptTag({ content: enhancement });
  const track = page.locator('.events-track');

  const box = await track.boundingBox();
  expect(box).not.toBeNull();
  await page.mouse.move(box.x + box.width / 2, Math.min(box.y + box.height / 2, 700));
  const before = await page.evaluate(() => window.scrollY);
  await page.mouse.wheel(0, 520);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(before + 100);
  await expect(page.locator('body')).not.toHaveCSS('position', 'fixed');
});

test('desktop mouse drag browses Events horizontally without page pinning', async ({ page }) => {
  await mountEvents(page);
  await page.addScriptTag({ content: enhancement });
  const track = page.locator('.events-track');
  const box = await track.boundingBox();
  expect(box).not.toBeNull();

  await page.mouse.move(box.x + Math.min(box.width - 80, 1050), box.y + Math.min(180, box.height / 2));
  await page.mouse.down();
  await page.mouse.move(box.x + 350, box.y + Math.min(180, box.height / 2), { steps: 8 });
  await page.mouse.up();

  await expect.poll(() => track.evaluate(el => el.scrollLeft)).toBeGreaterThan(120);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});
