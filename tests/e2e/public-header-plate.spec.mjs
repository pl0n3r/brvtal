import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// Real Cromo mount + synthetic public/slider API data, no production traffic.
const root = process.cwd();
const homeCss = readFileSync(join(root, 'css/public-cromo-home.css'), 'utf8');
const shellCss = readFileSync(join(root, 'css/public-cromo.css'), 'utf8');
const runtime = readFileSync(join(root, 'js/public-cromo-home.js'), 'utf8');
const startMarker = '/* #1860: Cromo header plate, safe anchors and audible-label affordance. */';
const endMarker = '/* End #1860. */';
const start = homeCss.indexOf(startMarker);
const end = homeCss.indexOf(endMarker);
if (start < 0 || end <= start) throw Error('#1860 CSS patch must be present');
// Reconstruct the true pre-fix baseline, including the original layout declarations.
const baseline = (homeCss.slice(0, start) + homeCss.slice(end + endMarker.length))
  .replace('color:#fff;background:rgba(3,3,3,.9);border-bottom:1px solid rgba(242,239,232,.35)', 'color:#fff')
  .replace('scroll-margin-top:clamp(100px,13vh,132px)', 'scroll-margin-top:84px')
  .replace('top:clamp(108px,14vh,150px)', 'top:clamp(20px,5vh,56px)');
const url = 'http://127.0.0.1:4173/cromo-header-plate-fixture.html';
const image = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#171717"/></svg>';
const data = {
  events: [{ id: 7, slug: 'cromo-night', title: 'NOCHE BRVTAL', event_date: '2099-08-14 20:00:00', city: 'Pereira', venue: 'Quinchía' }],
  artists: [{ id: 5, slug: 'artista', name: 'ARTISTA BRVTAL', is_collective_member: true }],
  sets: [{ id: 4, slug: 'set', title: 'SET BRVTAL', artist_name: 'ARTISTA BRVTAL' }],
  releases: [], memories: [], blog: [], archive: { events: [] }, relations: {},
};
const slides = {
  enabled: true, autoplay: false,
  slides: [
    { desktopSrc: '/banner-a.svg', title: 'BANNER A' },
    { desktopSrc: '/banner-b.svg', title: 'BANNER B' },
  ],
};
let serial = 0;
async function mount(page, { width, fixed, reduced = false }) {
  await page.setViewportSize({ width, height: 900 });
  await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
  await page.route('**/api/public.php', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, data }),
  }));
  await page.route('**/api/hero-slider.php', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, data: slides }),
  }));
  await page.route('**/banner-*.svg', route => route.fulfill({
    contentType: 'image/svg+xml', body: image,
  }));
  await page.route(url + '*', route => {
    const useFix = new URL(route.request().url()).searchParams.get('fixed') === 'true';
    return route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: '<!doctype html><html lang="es"><head>' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' +
        '<style>html,body{margin:0;padding:0}</style><style>' + shellCss + '\n' +
        (useFix ? homeCss : baseline) + '</style></head>' +
        '<body><main id="legacy">FALLBACK</main></body></html>',
    });
  });
  await page.goto(url + '?fixed=' + fixed + '&fixture=' + (++serial));
  await page.addScriptTag({ content: runtime });
  await expect(page.locator('#cromo-home .cr-top')).toBeVisible();
  await expect(page.locator('#destacados .cr-bn-stage')).toBeVisible();
}

async function anchor(page, selector) {
  await page.locator(selector).evaluate(node => node.scrollIntoView({ block: 'start' }));
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(16);
}

async function geometry(page) {
  return page.evaluate(() => {
    const header = document.querySelector('#cromo-home .cr-top');
    const h = header.getBoundingClientRect();
    const count = document.querySelector('#destacados .cr-bn-count')?.getBoundingClientRect();
    const controls = document.querySelector('#destacados .cr-bn-ctl')?.getBoundingClientRect();
    const heading = document.querySelector('#noches h2')?.getBoundingClientRect();
    const color = getComputedStyle(header).backgroundColor;
    const channels = color.match(/[\d.]+/g) || [];
    const opacity = channels.length > 3 ? Number(channels[3]) : 1;
    return {
      headerBottom: h.bottom, opacity, countTop: count?.top,
      controlsTop: controls?.top, headingTop: heading?.top,
      viewportWidth: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
}

for (const width of [390, 1440]) {
  test('Cromo header plate and slider clear the fixed bar at ' + width + 'px', async ({ page }, testInfo) => {
    // Genuine baseline-vs-patch screenshots from the same synthetic Cromo fixture.
    await mount(page, { width, fixed: false });
    await anchor(page, '#destacados');
    const beforePath = testInfo.outputPath('before-' + width + '.png');
    await page.screenshot({ path: beforePath });
    await testInfo.attach('before-' + width + '.png', {
      path: beforePath, contentType: 'image/png',
    });

    await mount(page, { width, fixed: true });
    await anchor(page, '#destacados');
    await expect.poll(async () => (await geometry(page)).opacity).toBeGreaterThanOrEqual(0.88);
    let metric = await geometry(page);
    expect(metric.countTop).toBeGreaterThan(metric.headerBottom + 8);
    expect(metric.controlsTop).toBeGreaterThan(metric.headerBottom + 8);
    expect(metric.scrollWidth).toBeLessThanOrEqual(metric.viewportWidth + 1);
    const afterPath = testInfo.outputPath('after-' + width + '.png');
    await page.screenshot({ path: afterPath });
    await testInfo.attach('after-' + width + '.png', {
      path: afterPath, contentType: 'image/png',
    });

    await anchor(page, '#noches');
    metric = await geometry(page);
    expect(metric.headingTop).toBeGreaterThan(metric.headerBottom + 8);

    const sound = page.locator('#cromo-home .cr-top .cr-snd');
    await expect(sound).toHaveAccessibleName(/Activar sonido/i);
    const box = await sound.boundingBox();
    expect(box?.width).toBeGreaterThanOrEqual(44);
    expect(box?.height).toBeGreaterThanOrEqual(44);
    if (width === 390) {
      expect(await sound.evaluate(node => getComputedStyle(node, '::after').content)).toContain('SONIDO');
    }
  });
}

test('reduced motion keeps a readable, non-animated header plate', async ({ page }) => {
  await mount(page, { width: 390, fixed: true, reduced: true });
  await anchor(page, '#noches');
  const { opacity } = await geometry(page);
  expect(opacity).toBeGreaterThanOrEqual(.88);
  const header = page.locator('#cromo-home .cr-top');
  await expect(header).toHaveCSS('animation-name', 'none');
  await expect(page.locator('#cromo-home .cr-top .cr-snd')).toHaveAccessibleName(/Activar sonido/i);
});
