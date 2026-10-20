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
          { id:2, title:'NO SLUG NIGHT', event_date:'2026-11-01 22:00:00', city:'Pereira', status:'published', ticket_types:[{ status:'active', external_url:'https://tickets.example/type' }] },
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
  await expect(page.locator('.event-card').filter({ hasText:'NO SLUG NIGHT' }).locator('.event-ticket')).toHaveAttribute('href', 'https://tickets.example/type');
  await expect(page.locator('.event-card').filter({ hasText:'PAST NIGHT' }).locator('.event-ticket')).toHaveCount(0);
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

  await page.evaluate(() => { document.documentElement.style.fontSize = '20px'; });
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

test('Concept 05 strip motion stays static under reduced motion and visual-test mode', async ({ page }) => {
  await page.emulateMedia({ reducedMotion:'reduce' });
  await mountVisual(page, { width:1440, height:900 });
  await page.locator('html').evaluate(el => el.classList.add('c5-visual-test'));

  const states = await page.evaluate(() => ({
    nightTransition:getComputedStyle(document.querySelector('.event-img img')).transitionDuration,
    artistTransition:getComputedStyle(document.querySelector('.c5-artist-media img')).transitionDuration,
  }));
  expect(states.nightTransition).toBe('0s');
  expect(states.artistTransition).toBe('0s');
});


// #971 — composed production-owned partials, not a duplicate hardcoded design shell.
const c5NightsPartial = readFileSync(join(root, 'partials/home/nights.html'), 'utf8');
const c5ArtistsPartial = readFileSync(join(root, 'partials/home/artists.html'), 'utf8');
const c5BoardCss = readFileSync(join(root, 'css/public-concept05-home.css'), 'utf8');
const c5RosterRuntime = readFileSync(join(root, 'js/public-roster.js'), 'utf8');

async function mountRealPartialPair(page, width) {
  await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
  const shell = '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    + '*{box-sizing:border-box}html,body{margin:0;max-width:100%;background:#050505;color:#eee}main{max-width:100%}'
    + c5BoardCss + css + '</style></head><body data-concept="05"><main id="top">'
    + '<div class="c5-board-region c5-board-region--pair" data-c5-board="02-03">'
    + c5NightsPartial + c5ArtistsPartial + '</div></main></body></html>';
  await page.setContent(shell);
  await page.addScriptTag({ content: enhancer });
  await page.addScriptTag({ content: app });
  await page.addScriptTag({ content: c5RosterRuntime });
}

test('C05 real partials 1440 row and 390 stack, with bounded widths', async ({ page }) => {
  for (const width of [1440, 390]) {
    await mountRealPartialPair(page, width);
    const nights = await page.locator('#events').boundingBox();
    const artists = await page.locator('#artists').boundingBox();
    expect(nights).not.toBeNull();
    expect(artists).not.toBeNull();
    if (width === 1440) {
      expect(Math.abs(artists.y - nights.y)).toBeLessThan(3);
      expect(artists.x).toBeGreaterThan(nights.x + nights.width - 3);
    } else {
      expect(artists.y).toBeGreaterThanOrEqual(nights.y + nights.height - 3);
      expect(artists.x).toBeLessThan(20);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <=
      document.documentElement.clientWidth)).toBe(true);
  }
});

test('CMS archive and published roster replace honest empty states without canned names', async ({ page }) => {
  await mountRealPartialPair(page, 1440);
  await expect(page.locator('[data-c5-nights-empty]')).toBeVisible();
  await expect(page.locator('[data-c5-artists-empty]')).toBeVisible();
  await expect(page.locator('.events-track .event-card')).toHaveCount(0);
  await expect(page.locator('.artist-list .artist')).toHaveCount(0);
  await page.evaluate(() => {
    window.BRVTAL_CONCEPT05_RENDER_PAST_NIGHTS({ archive: { events: [
      { id: 202, title: 'PUBLISHED PAST NIGHT', status: 'archived',
        event_date: '2026-04-06', city: 'Pereira', slug: 'past-night' },
      { id: 999, title: 'DO NOT SHOW DRAFT', is_published: false, status: 'draft' },
      { id: 998, title: 'DO NOT SHOW NUMERIC ZERO', is_published: 0, status: 'archived' },
      { id: 997, title: 'DO NOT SHOW STRING ZERO', is_published: '0', status: 'archived' }
    ] } });
    window.BRVTALDynamicHome.renderEvents(
      [{ id: 101, title: 'UPCOMING HIDDEN', status: 'upcoming', event_date: '2026-12-03', slug: 'upcoming' }],
      [{ id: 202, title: 'PUBLISHED PAST NIGHT', status: 'archived', event_date: '2026-04-06', city: 'Pereira', slug: 'past-night' }],
    );
    window.BRVTALPublicRoster.render([
      { name: 'CMS PUBLISHED ARTIST', slug: 'cms-published-artist', status: 'active' }
    ]);
  });
  await expect(page.locator('[data-c5-past-nights] .event-card[data-c5-lifecycle="archive"]')).toHaveCount(1);
  await expect(page.locator('.event-card[data-c5-lifecycle="active"]')).toBeHidden();
  await expect(page.locator('[data-c5-nights-empty]')).toBeHidden();
  await expect(page.locator('[data-c5-artists-empty]')).toBeHidden();
  await expect(page.locator('[data-c5-past-nights] .event-record[href="/events/past-night"]')).toHaveCount(1);

  // The real CMS card must be usable by touch, not just the legacy fixture.
  const recordTap = page.locator('[data-c5-past-nights] .event-record[href="/events/past-night"]');
  await expect(recordTap).toHaveCSS('min-height', '44px');
  const target = await recordTap.boundingBox();
  expect(target).not.toBeNull();
  expect(target.height).toBeGreaterThanOrEqual(44);
  expect(target.width).toBeGreaterThanOrEqual(44);
  await expect(page.locator('.artist[href="/artists/cms-published-artist"]')).toHaveCount(1);
  await expect(page.locator('[data-c5-past-nights]')).not.toContainText('UPCOMING HIDDEN');
  await expect(page.locator('[data-c5-past-nights]')).not.toContainText('DO NOT SHOW DRAFT');
  await expect(page.locator('[data-c5-past-nights]')).not.toContainText('DO NOT SHOW NUMERIC ZERO');
  await expect(page.locator('[data-c5-past-nights]')).not.toContainText('DO NOT SHOW STRING ZERO');
  await expect(page.locator('[data-c5-legacy-track]')).toBeHidden();
  await page.evaluate(() => {
    document.querySelector('[data-c5-legacy-track]').textContent = 'ACTIVE ONLY';
  });
  await expect(page.locator('[data-c5-past-nights]')).toContainText('PUBLISHED PAST NIGHT');
  await page.evaluate(() => window.BRVTAL_CONCEPT05_RENDER_PAST_NIGHTS({archive:{events:[]}}));
  await expect(page.locator('[data-c5-nights-empty]')).toBeVisible();
  await expect(page.locator('.c5-section-route--nights')).toBeHidden();
});

test('C05 02/03 owner-reference A/B screenshots for real partials', async ({ page }, testInfo) => {
  const reference = join(root, 'docs/reference/home-concept05-owner-reference-v2.png');
  await testInfo.attach('owner-v2-reference', { path: reference, contentType: 'image/png' });
  for (const width of [1440, 390]) {
    await mountRealPartialPair(page, width);
    await page.evaluate(() => {
      window.BRVTAL_CONCEPT05_RENDER_PAST_NIGHTS({archive:{events:[
        { id: 303, title: 'CMS ARCHIVED NIGHT', status: 'archived',
          event_date: '2026-08-07', slug: 'archived-night', city: 'Pereira' },
      ]}});
      window.BRVTALPublicRoster.render([
        { name: 'CMS ARTIST', slug: 'cms-artist', status: 'active' },
      ]);
    });
    await expect(page.locator('[data-c5-past-nights] .event-card[data-c5-lifecycle="archive"]')).toHaveCount(1);
    await testInfo.attach('build-ahead-partials-' + width, {
      body: await page.screenshot({ fullPage: true }), contentType: 'image/png',
    });
  }
  // Human A/B against the attached approved reference remains a separate PR gate.
});


test('owner v2 roster has six columns desktop and two mobile, with real CMS artists', async ({ page }) => {
  for (const width of [1440, 390]) {
    await mountRealPartialPair(page, width);
    await page.evaluate(() => window.BRVTALPublicRoster.render(
      Array.from({ length: 6 }, (_, i) => ({
        name: 'CMS ARTIST ' + (i + 1), slug: 'cms-artist-' + (i + 1),
        is_collective_member: true, sort_order: i + 1,
      })),
    ));
    await expect(page.locator('.roster-group .artist')).toHaveCount(6);
    const columns = await page.locator('.roster-group').first().evaluate(element =>
      getComputedStyle(element).gridTemplateColumns.trim().split(/\s+/).length,
    );
    expect(columns).toBe(width === 1440 ? 6 : 2);
    expect(await page.evaluate(() =>
      document.documentElement.scrollWidth <= document.documentElement.clientWidth,
    )).toBe(true);
  }
});
