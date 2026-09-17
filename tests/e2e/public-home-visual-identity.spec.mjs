import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const baseCss = readFileSync(join(root, 'css/style.css'), 'utf8');
const visualCss = readFileSync(join(root, 'css/public-visual-identity.css'), 'utf8');
const mobileEventsCss = readFileSync(join(root, 'css/mobile-events.css'), 'utf8');
const controlCss = readFileSync(join(root, 'css/public-controls.css'), 'utf8');
const runtime = readFileSync(join(root, 'js/public-home-visual.js'), 'utf8');
const router = readFileSync(join(root, 'index.php'), 'utf8');
const contact = readFileSync(join(root, 'config/public_contact_page.php'), 'utf8');

const fixture = `<!doctype html><html class="native-events-scroll"><head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>${baseCss}\n${controlCss}\n${mobileEventsCss}\n${visualCss}</style></head><body>
<section class="hero"><div class="hero-title">BRVTAL</div></section>
<section class="events">
  <div class="section-head"><h2>EVENTS</h2></div>
  <div class="events-track">
    <article class="event-card" id="without-ticket"><div class="event-img"></div><div class="event-info"><span class="mono">17.09.2026 / PEREIRA</span><h3>NO SIGNAL</h3><p>BRVTAL EXPERIENCE</p><span class="event-status">NEXT EXPERIENCE</span></div></article>
    <article class="event-card" id="with-ticket"><div class="event-img"></div><div class="event-info"><span class="mono">18.09.2026 / PEREIRA</span><h3>RED SIGNAL</h3><p>BRVTAL EXPERIENCE</p><span class="event-status">ACTIVE</span><a class="event-ticket mono" href="https://example.com/tickets">TICKETS ↗</a></div></article>
    <article class="event-card"><div class="event-img"></div><div class="event-info"><span class="mono">19.09.2026 / PEREIRA</span><h3>AFTERIMAGE</h3><p>BRVTAL EXPERIENCE</p><span class="event-status">ARCHIVE</span></div></article>
  </div>
  <div id="eventArchive"></div>
</section>
<section class="artists"><div class="section-head"><h2>ROSTER</h2></div></section>
<section class="sets"><div class="section-head"><h2>SETS</h2></div></section>
<section class="media"><div class="media-title"><h2>MEMORIES</h2></div></section>
</body></html>`;

async function mount(page, viewport = { width: 1440, height: 900 }) {
  await page.setViewportSize(viewport);
  await page.setContent(fixture);
  await page.addScriptTag({ content: runtime });
}

test('public router loads the Home visual layer and lightweight event enhancer', async () => {
  expect(router).toContain('css/public-visual-identity.css');
  expect(router).toContain('js/public-home-visual.js');
  expect(contact).toContain('css/public-visual-identity.css');
});

test('Event compositions expose a clear action without duplicating real ticket CTAs', async ({ page }) => {
  await mount(page);

  await expect(page.locator('#without-ticket .event-view')).toHaveCount(1);
  await expect(page.locator('#without-ticket .event-view')).toHaveAttribute('href', '#eventArchive');
  await expect(page.locator('#with-ticket .event-ticket')).toHaveCount(1);
  await expect(page.locator('#with-ticket .event-view')).toHaveCount(0);

  await page.locator('.events-track').evaluate(track => {
    track.innerHTML = '<article class="event-card" id="dynamic-card"><div class="event-img"></div><div class="event-info"><h3>DYNAMIC NIGHT</h3><p>API RENDER</p><span class="event-status">ACTIVE</span></div></article>';
  });
  await expect(page.locator('#dynamic-card .event-view')).toHaveCount(1);
});

test('desktop Events keeps roughly two to three cards visible inside native horizontal scrolling', async ({ page }) => {
  await mount(page, { width: 1440, height: 900 });

  const cardWidth = await page.locator('.event-card').first().evaluate(el => el.getBoundingClientRect().width);
  expect(cardWidth).toBeGreaterThanOrEqual(430);
  expect(cardWidth).toBeLessThanOrEqual(680);
  expect(1440 / cardWidth).toBeGreaterThan(2);
  expect(1440 / cardWidth).toBeLessThan(3.4);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(await page.locator('.events-track').evaluate(el => el.scrollWidth > el.clientWidth)).toBe(true);
});

test('mobile Event cards stay contained while page vertical navigation remains available', async ({ page }) => {
  await mount(page, { width: 390, height: 844 });

  const cardWidth = await page.locator('.event-card').first().evaluate(el => el.getBoundingClientRect().width);
  expect(cardWidth).toBeLessThanOrEqual(390 * 0.85);
  expect(cardWidth).toBeGreaterThan(280);
  await expect(page.locator('#without-ticket .event-view')).toHaveCSS('min-height', '48px');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(await page.locator('.events-track').evaluate(el => getComputedStyle(el).overflowX)).toBe('auto');
});

test('reduced motion disables decorative title and Hero signal animations', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await mount(page);

  expect(await page.locator('.events .section-head h2').evaluate(el => getComputedStyle(el).animationName)).toBe('none');
  expect(await page.locator('.hero').evaluate(el => getComputedStyle(el, '::before').animationName)).toBe('none');
  expect(await page.locator('.media-title h2').evaluate(el => getComputedStyle(el).animationName)).toBe('none');
});
