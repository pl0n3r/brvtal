import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const app = readFileSync(join(root, 'js/app.js'), 'utf8');
const enhancer = readFileSync(join(root, 'js/public-concept05-nights-artists.js'), 'utf8');
const css = [
  'css/public-concept05-tokens.css',
  'css/mobile-events.css',
  'css/public-concept05-nights-artists.css',
].map(path => readFileSync(join(root, path), 'utf8')).join('\n');

const runtimeFixture = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body data-concept="05">
<button id="menuToggle"><strong>+</strong></button>
<aside id="menuPanel" aria-hidden="true"><nav></nav></aside>
<button id="soundToggle"><b>OFF</b></button>
<section class="events c5-numbered" id="events">
  <div class="section-head"><span class="mono">BRVTAL NIGHTS / 02</span><h2>NIGHTS</h2><span class="mono">DRAG / SWIPE →</span></div>
  <div class="events-track"></div>
  <div id="eventArchive"></div>
</section>
<section class="artists c5-numbered" id="artists">
  <div class="section-head"><span class="mono">BRVTAL ARTISTS / 03</span><h2>ARTISTS</h2><span class="mono">CORE / ALUMNI / COLLABORATORS</span></div>
  <div class="artist-list"></div>
  <div class="artist-preview"><img alt=""></div>
</section>
</body></html>`;

test('dynamic Home projects active + archived Nights and canonical Artist routes from one shared payload', async ({ page }) => {
  await page.setContent(runtimeFixture);
  await page.evaluate(() => {
    window.__publicPayloadReads = 0;
    const payload = {
      data: {
        events: [
          { id:1, title:'ACTIVE NIGHT', slug:'active-night', event_date:'2026-10-10 22:00:00', city:'Pereira', venue:'Warehouse', status:'upcoming', cover_image:'/media/active.jpg', ticket_url:'https://tickets.example/active' },
          { id:2, title:'NO SLUG NIGHT', event_date:'2026-11-01 22:00:00', city:'Pereira', status:'published' },
        ],
        archive: {
          events: [
            { id:1, title:'DUPLICATE SHOULD NOT RENDER', slug:'duplicate', event_date:'2026-01-01', city:'Pereira', status:'archived' },
            { id:3, title:'PAST NIGHT', slug:'past-night', event_date:'2026-08-07', city:'Pereira', venue:'Archive Room', status:'archived', cover_image:'/media/past.jpg' },
          ],
        },
        artists: [
          { name:'PL0N3R', slug:'pl0n3r', photo:'/media/pl0n3r.jpg', website_url:'https://external.example/should-not-win' },
          { name:'NO SLUG', website_url:'https://external.example/also-no' },
        ],
        sets: [],
        media: [],
        settings: {},
      },
    };
    window.BRVTALPublicDataPromise = Promise.resolve({ payload, url:'/api/public.php' }).then(value => {
      window.__publicPayloadReads += 1;
      return value;
    });
  });

  await page.addScriptTag({ content: app });
  const result = await page.evaluate(() => window.BRVTALDynamicHome.init());
  expect(result.ok).toBe(true);

  await expect(page.locator('.events-track .event-card')).toHaveCount(3);
  await expect(page.locator('.event-card[data-c5-lifecycle="active"]')).toHaveCount(2);
  await expect(page.locator('.event-card[data-c5-lifecycle="archive"]')).toHaveCount(1);
  await expect(page.locator('.event-card').filter({ hasText:'PAST NIGHT' }).locator('.event-record')).toHaveAttribute('href', '/events/past-night');
  await expect(page.locator('.event-card').filter({ hasText:'ACTIVE NIGHT' }).locator('.event-record')).toHaveAttribute('href', '/events/active-night');
  await expect(page.locator('.event-card').filter({ hasText:'NO SLUG NIGHT' }).locator('.event-record')).toHaveCount(0);
  await expect(page.locator('.event-card').filter({ hasText:'DUPLICATE SHOULD NOT RENDER' })).toHaveCount(0);

  await expect(page.locator('.artist-list .artist').first()).toHaveAttribute('href', '/artists/pl0n3r');
  await expect(page.locator('.artist-list .artist').first()).not.toHaveAttribute('href', /external\.example/);
  await expect(page.locator('.artist-list .artist').nth(1)).toHaveAttribute('href', '#artists');
  expect(await page.evaluate(() => window.__publicPayloadReads)).toBe(1);
});

const visualFixture = `<!doctype html><html class="native-events-scroll"><head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box}html,body{margin:0;width:100%;overflow-x:hidden;background:#050505;color:#eee}
.section-head{display:flex}.events-track{display:flex}.event-card{flex:0 0 auto}.artist-list{width:100%}
${css}
</style></head><body data-concept="05">
<section class="events c5-numbered" id="events">
  <div class="section-head"><span class="mono">BRVTAL NIGHTS / 02</span><h2>NIGHTS</h2><span class="mono">DRAG / SWIPE →</span></div>
  <div class="events-track">
    <article class="event-card"><div class="event-img"><img src="https://example.test/night.svg" alt="Night"></div><div class="event-info"><span class="mono">14.08.2026 / PEREIRA</span><h3>VERY LONG NIGHT TITLE THAT MUST WRAP SAFELY</h3><p>WAREHOUSE / PEREIRA</p><span class="event-status">ARCHIVE</span><div class="c5-night-actions"><a class="event-record mono" href="/events/night">VIEW RECORD ↗</a></div></div></article>
    <article class="event-card"><div class="event-img"></div><div class="event-info"><span class="mono">07.08.2026 / PEREIRA</span><h3>SESSION 05</h3><p>BRVTAL</p><span class="event-status">ARCHIVE</span></div></article>
    <article class="event-card"><div class="event-img"></div><div class="event-info"><span class="mono">01.08.2026 / PEREIRA</span><h3>NO SIGNAL</h3><p>BRVTAL</p><span class="event-status">ARCHIVE</span></div></article>
    <article class="event-card"><div class="event-img"></div><div class="event-info"><span class="mono">01.07.2026 / PEREIRA</span><h3>AFTERIMAGE</h3><p>BRVTAL</p><span class="event-status">ARCHIVE</span></div></article>
  </div>
  <div id="eventArchive"></div>
</section>
<section class="artists c5-numbered" id="artists">
  <div class="section-head"><span class="mono">BRVTAL ARTISTS / 03</span><h2>ARTISTS</h2><span class="mono">CORE / ALUMNI / COLLABORATORS</span></div>
  <div class="artist-list">
    <section class="roster-group"><div class="roster-group-head mono"><span>CORE / ACTIVE</span><b>02</b></div>
      <a class="artist" href="/artists/pl0n3r" data-image="https://example.test/artist.svg"><span>01</span><strong>PL0N3R</strong><i>BRVTAL / ACTIVE</i></a>
      <a class="artist" href="/artists/long-name"><span>02</span><strong>VERY LONG ARTIST NAME THAT MUST WRAP WITHOUT OVERFLOW</strong><i>ARTIST / COLLABORATOR</i></a>
    </section>
  </div>
</section>
<nav class="c5-bottom-nav"><a href="#events">NIGHTS</a><a href="#artists">ARTISTS</a></nav>
</body></html>`;

async function mountVisual(page, viewport) {
  await page.setViewportSize(viewport);
  await page.route('https://example.test/night.svg', route => route.fulfill({
    status:200, contentType:'image/svg+xml', body:'<svg xmlns="http://www.w3.org/2000/svg" width="600" height="750"></svg>'
  }));
  await page.route('https://example.test/artist.svg', route => route.fulfill({
    status:200, contentType:'image/svg+xml', body:'<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800"></svg>'
  }));
  await page.setContent(visualFixture);
  await page.addScriptTag({ content: enhancer });
  await page.waitForFunction(() => document.documentElement.dataset.concept05Strips === 'ready');
}

test('Concept 05 desktop Nights keeps 3–4 dense cards in the editorial strip and Artists uses portrait cards', async ({ page }) => {
  await mountVisual(page, { width:1440, height:900 });

  const eventWidth = await page.locator('.event-card').first().evaluate(el => el.getBoundingClientRect().width);
  expect(eventWidth).toBeGreaterThanOrEqual(270);
  expect(eventWidth).toBeLessThanOrEqual(345);
  expect(1440 / eventWidth).toBeGreaterThan(4);

  await expect(page.locator('.c5-section-route--nights')).toHaveAttribute('href', '#eventArchive');
  await expect(page.locator('.c5-section-route--artists')).toHaveAttribute('href', '/artists/pl0n3r');
  await expect(page.locator('.c5-artist-media')).toHaveCount(2);
  await expect(page.locator('.c5-artist-media.is-media-missing')).toHaveCount(1);

  const artistWidths = await page.locator('.c5-artist-card').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().width));
  artistWidths.forEach(width => expect(width).toBeGreaterThan(150));
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('Concept 05 mobile Nights stays swipeable while Artists becomes an authored two-column grid', async ({ page }) => {
  await mountVisual(page, { width:390, height:844 });

  const event = await page.locator('.event-card').first().evaluate(el => {
    const rect = el.getBoundingClientRect();
    return { width:rect.width, right:rect.right };
  });
  expect(event.width).toBeGreaterThanOrEqual(300);
  expect(event.width).toBeLessThanOrEqual(390 * 0.88);

  const track = await page.locator('.events-track').evaluate(el => ({
    overflowX:getComputedStyle(el).overflowX,
    touchAction:getComputedStyle(el).touchAction,
    scrollWidth:el.scrollWidth,
    clientWidth:el.clientWidth,
  }));
  expect(track.overflowX).toBe('auto');
  expect(track.touchAction).toContain('pan-y');
  expect(track.scrollWidth).toBeGreaterThan(track.clientWidth);

  const gridColumns = await page.locator('.roster-group').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length);
  expect(gridColumns).toBe(2);
  await expect(page.locator('.event-record')).toHaveCSS('min-height', '44px');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('Concept 05 failed Night artwork hides broken-image chrome and keeps the card usable', async ({ page }) => {
  await page.setViewportSize({ width:390, height:844 });
  await page.setContent(`<!doctype html><html><body data-concept="05"><section class="events"><div class="events-track"><article class="event-card"><div class="event-img"><img src="https://example.test/missing.jpg" alt="Missing"></div><div class="event-info"><h3>STILL HERE</h3></div></article></div><div id="eventArchive"></div></section><section class="artists"><div class="artist-list"></div></section></body></html>`);
  await page.route('https://example.test/missing.jpg', route => route.fulfill({ status:404, body:'missing' }));
  await page.addStyleTag({ content: css });
  await page.addScriptTag({ content: enhancer });

  await expect(page.locator('.event-img')).toHaveClass(/is-media-missing/);
  await expect(page.locator('.event-img img')).toBeHidden();
  await expect(page.locator('.event-info h3')).toHaveText('STILL HERE');
});
