import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { join } from 'node:path';

const root = process.cwd();
const appJs = readFileSync(join(root, 'js/app.js'), 'utf8');
const setsJs = readFileSync(join(root, 'js/public-sets-library.js'), 'utf8');
const mediaJs = readFileSync(join(root, 'js/public-media.js'), 'utf8');
const enhancerJs = readFileSync(join(root, 'js/public-concept05-sound-memories.js'), 'utf8');
const css = [
  'css/public-concept05-tokens.css',
  'css/public-concept05-home.css',
  'css/public-sets-library.css',
  'css/public-media.css',
  'css/public-memories.css',
  'css/public-concept05-sound-memories.css',
].map(path => readFileSync(join(root, path), 'utf8')).join('\n');

const runtimeFixture = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body data-concept="05">
<button id="menuToggle"><strong>+</strong></button>
<aside id="menuPanel" aria-hidden="true"><nav></nav></aside>
<button id="soundToggle"><b>OFF</b></button>
<section class="sets c5-numbered" id="sets"><div class="sets-intro"></div><div class="set-list"></div></section>
<section class="media c5-numbered" id="media"><div class="media-grid"></div></section>
</body></html>`;

test('Dynamic Home sends Sets and curated Memories to canonical renderers without exposing raw Media Library records', async ({ page }) => {
  await page.setContent(runtimeFixture);
  await page.evaluate(() => {
    window.__payloadReads = 0;
    window.__setsArg = null;
    window.__memoriesArg = null;
    window.BRVTALPublicSetsLibrary = {
      render(items) {
        window.__setsArg = items;
        return true;
      },
    };
    window.BRVTALPublicMedia = {
      render(items) {
        window.__memoriesArg = items;
        return true;
      },
    };
    const payload = {
      data: {
        events: [],
        archive: { events: [] },
        artists: [],
        sets: [{ id: 7, title: 'CANONICAL SET', slug: 'canonical-set' }],
        memories: [{ id: 11, media_id: 101, title: 'CURATED MEMORY', type: 'image', file_path: '/uploads/curated.jpg' }],
        media: [{ id: 999, title: 'RAW MEDIA MUST NOT RENDER', type: 'image', file_path: '/uploads/raw.jpg' }],
        settings: {},
      },
    };
    window.BRVTALPublicDataPromise = Promise.resolve({ payload, url: '/api/public.php' }).then(value => {
      window.__payloadReads += 1;
      return value;
    });
  });

  await page.addScriptTag({ content: appJs });
  const result = await page.evaluate(() => window.BRVTALDynamicHome.init());
  expect(result.ok).toBe(true);
  expect(await page.evaluate(() => window.__payloadReads)).toBe(1);
  expect(await page.evaluate(() => window.__setsArg?.[0]?.title)).toBe('CANONICAL SET');
  expect(await page.evaluate(() => window.__memoriesArg?.[0]?.title)).toBe('CURATED MEMORY');
  expect(await page.evaluate(() => window.__memoriesArg?.some(item => item.id === 999))).toBe(false);
});

const harness = 'http://127.0.0.1:4173/concept05-sound-memories.html';

function markup() {
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<style>:root{--line:rgba(255,255,255,.2);--accent:#62ff21}*{box-sizing:border-box}html,body{margin:0;width:100%;overflow-x:hidden;background:#050505;color:#e8e6df}${css}</style>
</head><body data-concept="05">
<section class="sets scene c5-numbered" id="sets">
  <div class="section-head"><span class="mono">BRVTAL SOUND / 04</span><h2>SOUND</h2><span class="mono">SETS / LISTEN / ARCHIVE</span></div>
  <div class="sets-intro"><p class="mono">SOUND IS NOT BACKGROUND.</p><h3>IT IS THE<br><em>EXPERIENCE.</em></h3></div>
  <div class="set-list"><article id="fallback-set">STATIC FALLBACK</article></div>
</section>
<section id="eventArchive"></section>
<section class="media scene c5-numbered" id="media" aria-labelledby="memories-title">
  <div class="media-title"><span class="mono">BRVTAL MEMORIES / 05</span><h2 id="memories-title">MEMORIES</h2><div class="media-title-note"><strong>THE NIGHT REMAINS.</strong><p>A curated record.</p></div></div>
  <div class="public-media-tools"><label><span>SEARCH MEDIA</span><input type="search" data-public-media-search></label><div class="public-media-types"><button type="button" data-public-media-type="all">ALL</button><button type="button" data-public-media-type="image">IMAGES</button><button type="button" data-public-media-type="video">VIDEO</button><button type="button" data-public-media-type="audio">AUDIO</button></div></div>
  <div data-public-media-count></div>
  <div data-public-media-empty hidden><p>NO MEMORIES MATCH THESE FILTERS.</p><button type="button" data-public-media-reset>CLEAR FILTERS</button></div>
  <div class="media-grid"></div>
</section>
</body></html>`;
}

const sets = [
  {
    id: 1,
    title: 'GENESIS CLOSING SIGNAL',
    slug: 'genesis-closing-signal',
    platform: 'soundcloud',
    external_url: 'https://soundcloud.com/brvtal/signal',
    cover_image: 'http://127.0.0.1:4173/uploads/sound-cover.svg',
    description: 'A real Set attached to the night.',
    artist_id: 10,
    artist_name: 'PL0N3R',
    artist_slug: 'pl0n3r',
    event_id: 20,
    event_title: 'GENESIS',
    event_slug: 'genesis',
  },
  {
    id: 2,
    title: 'INDEPENDENT TRANSMISSION WITH A VERY LONG TITLE THAT MUST WRAP',
    slug: 'independent-transmission',
    platform: 'youtube',
    external_url: '',
    cover_image: '',
    description: 'No external playback URL is available.',
    artist_id: null,
    artist_name: null,
    event_id: null,
    event_title: null,
  },
  {
    id: 3,
    title: 'UNSAFE JAVASCRIPT SIGNAL',
    slug: 'unsafe-javascript-signal',
    platform: 'web',
    external_url: 'javascript:alert(1)',
    cover_image: '',
  },
  {
    id: 4,
    title: 'UNSAFE DATA SIGNAL',
    slug: 'unsafe-data-signal',
    platform: 'web',
    external_url: 'data:text/html,<script>alert(1)</script>',
    cover_image: '',
  },
];

const memories = [
  {
    id: 21,
    media_id: 201,
    type: 'image',
    title: 'WAREHOUSE PRESSURE',
    context: 'GENESIS / PEREIRA',
    alt_text: 'Crowd at GENESIS',
    file_path: 'http://127.0.0.1:4173/uploads/memory-one.svg',
    relations: [{ related_type: 'event', related_id: 20, route_type: 'events', slug: 'genesis', label: 'GENESIS' }],
  },
  {
    id: 22,
    media_id: 202,
    type: 'image',
    title: 'RED AFTERIMAGE',
    context: '05:12 / LIGHTS ON',
    alt_text: 'Red light afterimage',
    file_path: 'http://127.0.0.1:4173/uploads/memory-two.svg',
    relations: [],
  },
  {
    id: 23,
    media_id: 203,
    type: 'audio',
    title: 'ROOM TONE',
    context: 'AUDIO MEMORY',
    file_path: 'http://127.0.0.1:4173/uploads/room-tone.mp3',
    relations: [],
  },
];

async function mount(page, viewport = { width: 1440, height: 900 }) {
  await page.setViewportSize(viewport);
  await page.route(harness, route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: markup() }));
  await page.route('**/api/public-image-delivery.php', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, data: {} }),
  }));
  await page.route('**/uploads/*.svg', route => route.fulfill({
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="640"><rect width="800" height="640" fill="white"/></svg>',
  }));
  await page.route('**/uploads/room-tone.mp3', route => route.fulfill({ contentType: 'audio/mpeg', body: '' }));
  await page.goto(harness);
  await page.addScriptTag({ content: setsJs });
  await page.addScriptTag({ content: mediaJs });
  await page.addScriptTag({ content: enhancerJs });
  await page.evaluate(({ setData, memoryData }) => {
    window.BRVTALPublicSetsLibrary.render(setData);
    window.BRVTALPublicMedia.render(memoryData);
    window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT();
  }, { setData: sets, memoryData: memories });
  await page.waitForFunction(() =>
    document.documentElement.dataset.concept05Sound === 'ready'
    && document.documentElement.dataset.concept05Memories === 'ready'
  );
}

test('Concept 05 desktop Sound uses canonical records while Memories keeps a curated contact-sheet rhythm', async ({ page }) => {
  await mount(page);

  await expect(page.locator('.set-library-item')).toHaveCount(4);
  await expect(page.locator('.set-library-item').first()).toHaveClass(/c5-sound-feature/);
  await expect(page.locator('.set-library-item').first().locator('.set-record-link')).toHaveAttribute('href', '/sets/genesis-closing-signal');
  await expect(page.locator('.set-library-item').first().locator('.set-listen-action')).toHaveAttribute('href', 'https://soundcloud.com/brvtal/signal');
  await expect(page.locator('.set-library-item').nth(1).locator('.set-listen-action')).toHaveCount(0);
  await expect(page.locator('.set-library-item').nth(2).locator('.set-listen-action')).toHaveCount(0);
  await expect(page.locator('.set-library-item').nth(3).locator('.set-listen-action')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'ARTIST / PL0N3R' })).toHaveAttribute('href', '/artists/pl0n3r');
  await expect(
    page.locator('.set-library-item').first().getByRole('link', { name: 'EVENT / GENESIS' })
  ).toHaveAttribute('href', '/events/genesis');
  await expect(page.locator('.c5-sound-signal')).toHaveCount(1);
  await expect(page.locator('.c5-section-route--sound')).toHaveAttribute('href', '/sets/genesis-closing-signal');

  await expect(page.locator('.sets-intro')).toBeHidden();
  const feature = await page.locator('.c5-sound-feature').boundingBox();
  const cover = await page.locator('.c5-sound-feature .set-library-cover').boundingBox();
  const firstIndexedRecord = await page.locator('.set-library-item').nth(1).boundingBox();
  expect(feature).not.toBeNull();
  expect(cover).not.toBeNull();
  expect(firstIndexedRecord).not.toBeNull();
  expect(feature.height).toBeGreaterThanOrEqual(180);
  expect(feature.height).toBeLessThan(230);
  expect(cover.width).toBeGreaterThanOrEqual(120);
  expect(cover.width).toBeLessThanOrEqual(132);
  expect(firstIndexedRecord.x).toBeGreaterThan(feature.x + feature.width - 8);

  await expect(page.locator('[data-public-media-item]')).toHaveCount(3);
  await expect(page.locator('.c5-memory-cell')).toHaveCount(3);
  await expect(page.getByRole('link', { name: 'EVENT / GENESIS' }).last()).toHaveAttribute('href', '/events/genesis');
  await expect(page.locator('.c5-memory-annotation')).toContainText('PEOPLE / LIGHTS / MEMORIES / FOREVER');
  await expect(page.locator('.c5-section-route--memories')).toHaveAttribute('href', '#eventArchive');

  const widths = await page.locator('.c5-memory-cell').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().width));
  expect(widths[0]).toBeGreaterThan(widths[1]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('Concept 05 mobile Sound and Memories remain touch-safe, authored and overflow-free at 390', async ({ page }) => {
  await mount(page, { width: 390, height: 844 });

  await expect(page.locator('.sets-intro')).toBeHidden();
  const feature = await page.locator('.c5-sound-feature').boundingBox();
  const cover = await page.locator('.c5-sound-feature .set-library-cover').boundingBox();
  expect(feature).not.toBeNull();
  expect(cover).not.toBeNull();
  expect(feature.height).toBeGreaterThanOrEqual(92);
  expect(feature.height).toBeLessThan(180);
  expect(cover.width).toBeGreaterThanOrEqual(68);
  expect(cover.width).toBeLessThanOrEqual(76);
  expect(cover.height).toBeGreaterThanOrEqual(68);
  expect(cover.height).toBeLessThanOrEqual(76);

  for (const button of await page.locator('[data-sets-mode]').all()) {
    const box = await button.boundingBox();
    expect(box.height).toBeGreaterThanOrEqual(44);
  }
  const listen = await page.locator('.set-listen-action').first().boundingBox();
  expect(listen.height).toBeGreaterThanOrEqual(44);

  const cells = page.locator('.c5-memory-cell');
  const first = await cells.nth(0).boundingBox();
  const second = await cells.nth(1).boundingBox();
  expect(first.width).toBeGreaterThan(340);
  expect(second.width).toBeGreaterThan(160);
  expect(second.width).toBeLessThan(180);

  for (const button of await page.locator('[data-public-media-type]').all()) {
    const box = await button.boundingBox();
    expect(box.height).toBeGreaterThanOrEqual(44);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('Concept 05 broken Sound artwork and Memory media fail closed without broken-image chrome', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.route(harness, route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: markup() }));
  await page.route('**/api/public-image-delivery.php', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, data: {} }),
  }));
  await page.route('**/uploads/missing-*', route => route.fulfill({ status: 404, body: 'missing' }));
  await page.goto(harness);
  await page.addScriptTag({ content: setsJs });
  await page.addScriptTag({ content: mediaJs });
  await page.addScriptTag({ content: enhancerJs });
  await page.evaluate(() => {
    window.BRVTALPublicSetsLibrary.render([{
      id: 1,
      title: 'BROKEN COVER',
      slug: 'broken-cover',
      cover_image: 'http://127.0.0.1:4173/uploads/missing-cover.jpg',
    }]);
    window.BRVTALPublicMedia.render([{
      id: 2,
      media_id: 202,
      type: 'image',
      title: 'BROKEN MEMORY',
      file_path: 'http://127.0.0.1:4173/uploads/missing-memory.jpg',
    }]);
    window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT();
  });

  await expect(page.locator('.set-library-cover')).toHaveClass(/is-media-missing/);
  await expect(page.locator('.set-library-cover img')).toBeHidden();
  await expect(page.locator('.c5-memory-cell')).toHaveClass(/is-media-missing/);
  await expect(page.locator('.c5-memory-cell img')).toBeHidden();
  await expect(page.locator('[data-public-media-open]')).toBeDisabled();
  await expect(page.getByText('BROKEN MEMORY')).toBeVisible();
});


test('Concept 05 failed audio Memory disables its opener and replaces unusable viewer playback', async ({ page }) => {
  await page.setViewportSize({ width: 900, height: 800 });
  await page.route(harness, route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: markup() }));
  await page.route('**/api/public-image-delivery.php', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, data: {} }),
  }));
  await page.route('**/uploads/broken-audio.mp3', route => route.fulfill({ status: 404, body: 'missing' }));
  await page.goto(harness);
  await page.addScriptTag({ content: setsJs });
  await page.addScriptTag({ content: mediaJs });
  await page.addScriptTag({ content: enhancerJs });
  await page.evaluate(() => {
    window.BRVTALPublicMedia.render([{
      id: 33,
      media_id: 303,
      type: 'audio',
      title: 'BROKEN AUDIO MEMORY',
      file_path: '/uploads/broken-audio.mp3',
    }]);
    window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT();
  });

  const opener = page.getByRole('button', { name: 'Open BROKEN AUDIO MEMORY' });
  await opener.click();
  await expect(page.getByRole('dialog')).toContainText('MEDIA UNAVAILABLE');
  await expect(opener).toBeDisabled();
  await expect(page.locator('.c5-memory-cell')).toHaveClass(/is-media-missing/);
});

test('Concept 05 Sound and Memories motion is static for reduced-motion users', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await mount(page);

  const memoryTransition = await page.locator('.c5-memory-cell img').first().evaluate(node => getComputedStyle(node).transitionDuration);
  const filterTransition = await page.locator('[data-sets-mode]').first().evaluate(node => getComputedStyle(node).transitionDuration);
  expect(memoryTransition).toBe('0s');
  expect(filterTransition).toBe('0s');
});


test('Concept 05 Sound and Memories actions follow ES/EN locale without duplicate decorations', async ({ page }) => {
  await mount(page, { width: 390, height: 844 });
  await page.evaluate(() => {
    document.documentElement.dataset.locale = 'es';
    window.dispatchEvent(new CustomEvent('brvtal:localechange'));
  });
  await expect(page.locator('.c5-section-route--sound')).toHaveText('EXPLORAR SONIDO ↗');
  await expect(page.locator('.c5-section-route--memories')).toHaveText('EXPLORAR ARCHIVO ↓');

  await page.evaluate(() => {
    document.documentElement.dataset.locale = 'en';
    window.dispatchEvent(new CustomEvent('brvtal:localechange'));
  });
  await expect(page.locator('.c5-section-route--sound')).toHaveText('EXPLORE SOUND ↗');
  await expect(page.locator('.c5-section-route--memories')).toHaveText('EXPLORE ARCHIVE ↓');

  await page.evaluate(() => window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT());
  await expect(page.locator('.c5-section-route--sound')).toHaveCount(1);
  await expect(page.locator('.c5-section-route--memories')).toHaveCount(1);
  await expect(page.locator('.c5-memory-annotation')).toHaveCount(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= 390)).toBe(true);
});

test('Concept 05 hides decorative Memories annotation when CMS curated gallery becomes empty', async ({ page }) => {
  await mount(page);
  await expect(page.locator('.c5-memory-annotation')).toHaveCount(1);
  await page.evaluate(() => {
    window.BRVTALPublicMedia.render([]);
    window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT();
  });
  await expect(page.locator('[data-public-media-item]')).toHaveCount(0);
  await expect(page.locator('.c5-memory-annotation')).toHaveCount(0);
  await page.evaluate(data => {
    window.BRVTALPublicMedia.render(data);
    window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT();
  }, memories);
  await expect(page.locator('.c5-memory-annotation')).toHaveCount(1);
  await expect(page.locator('.c5-memory-cell')).toHaveCount(3);
});


test('Concept 05 hides decorative Memories annotation when search has no visible curated result', async ({ page }) => {
  await mount(page, { width: 390, height: 844 });
  const annotation = page.locator('.c5-memory-annotation');
  const search = page.locator('[data-public-media-search]');
  await expect(annotation).toHaveCount(1);
  await expect(page.locator('[data-public-media-item]:not([hidden])')).toHaveCount(3);

  await search.fill('NO MATCHING CMS MEMORY');
  await expect(page.locator('[data-public-media-item]:not([hidden])')).toHaveCount(0);
  await expect(page.locator('[data-public-media-empty]')).toBeVisible();
  await expect(annotation).toHaveCount(0);

  await search.fill('GENESIS');
  await expect(page.locator('[data-public-media-item]:not([hidden])')).toHaveCount(1);
  await expect(annotation).toHaveCount(1);

  await search.clear();
  await expect(page.locator('[data-public-media-item]:not([hidden])')).toHaveCount(3);
  await expect(annotation).toHaveCount(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= 390)).toBe(true);
});

test('Concept 05 gallery search preserves an already opted-in Sound iframe', async ({ page }) => {
  const embed = 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fbrvtal%2Fsignal';
  await page.route('https://w.soundcloud.com/**', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><title>Sound consent fixture</title>',
  }));
  await mount(page, { width: 390, height: 844 });
  await page.evaluate(records => {
    window.__soundDataReads = 0;
    // Use a tracked thenable: native Promise awaits bypass an overridden .then.
    window.BRVTALPublicDataPromise = {
      then(resolve) {
        window.__soundDataReads += 1;
        resolve({ payload: { data: { sets: records } } });
      },
    };
    window.BRVTALPublicSetsLibrary.render(records);
  }, [{ ...sets[0], embed_url: embed }, sets[1]]);

  await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'available');
  await page.locator('.c5-sound-player-toggle').click();
  const soundFrame = page.locator('.c5-sound-player-frame iframe');
  await expect(soundFrame).toHaveAttribute('src', embed);
  await page.evaluate(() => {
    window.__consentedSoundFrame = document.querySelector('.c5-sound-player-frame iframe');
  });
  const readsBeforeFilters = await page.evaluate(() => window.__soundDataReads);
  expect(readsBeforeFilters).toBeGreaterThan(0);

  const search = page.locator('[data-public-media-search]');
  await search.fill('NO MATCHING CMS MEMORY');
  await expect(page.locator('.c5-memory-annotation')).toHaveCount(0);
  await expect(page.locator('[data-public-media-empty]')).toBeVisible();
  await expect(soundFrame).toHaveCount(1);
  expect(await page.evaluate(() =>
    window.__consentedSoundFrame === document.querySelector('.c5-sound-player-frame iframe')
  )).toBe(true);

  await search.fill('GENESIS');
  await expect(page.locator('.c5-memory-annotation')).toHaveCount(1);
  await search.clear();
  await expect(page.locator('[data-public-media-item]:not([hidden])')).toHaveCount(3);
  await expect(soundFrame).toHaveAttribute('src', embed);
  await expect(page.locator('.c5-sound-player-toggle')).toHaveAttribute('aria-expanded', 'true');
  expect(await page.evaluate(() =>
    window.__consentedSoundFrame === document.querySelector('.c5-sound-player-frame iframe')
  )).toBe(true);
  // Settle MutationObserver callbacks; photo filters cannot resync Sound data.
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  expect(await page.evaluate(() => window.__soundDataReads)).toBe(readsBeforeFilters);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= 390)).toBe(true);
});

test('Concept 05 Sound and archive actions disappear when their CMS destination is unavailable', async ({ page }) => {
  await mount(page, { width: 390, height: 844 });
  await expect(page.locator('.c5-section-route--sound')).toHaveCount(1);
  await expect(page.locator('.c5-section-route--memories')).toHaveCount(1);

  await page.evaluate(() => {
    window.BRVTALPublicSetsLibrary.render([]);
    document.getElementById('eventArchive')?.remove();
    window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT();
  });
  await expect(page.getByText('NO PUBLISHED SETS YET.')).toBeVisible();
  await expect(page.locator('.c5-section-route--sound')).toHaveCount(0);
  await expect(page.locator('.c5-section-route--memories')).toHaveCount(0);

  await page.evaluate(records => {
    const archive = document.createElement('section');
    archive.id = 'eventArchive';
    document.body.appendChild(archive);
    window.BRVTALPublicSetsLibrary.render(records);
    window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT();
  }, sets);
  await expect(page.locator('.c5-section-route--sound')).toHaveAttribute('href', '/sets/genesis-closing-signal');
  await expect(page.locator('.c5-section-route--memories')).toHaveAttribute('href', '#eventArchive');
  await expect(page.locator('.c5-section-route--memories')).toHaveCount(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});


test('Concept 05 loads only allowlisted official embed after user activation', async ({ page }) => {
  await page.route('https://w.soundcloud.com/**', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><title>Consent-based SoundCloud player fixture</title>',
  }));
  await mount(page, { width: 390, height: 844 });

  const safeEmbed = 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fbrvtal%2Fsignal';
  const published = sets.map((set, index) => index === 0
    ? {...set, embed_url: safeEmbed}
    : set);
  await page.evaluate(records => {
    window.BRVTALPublicDataPromise = Promise.resolve({payload:{data:{sets:records}}});
    window.BRVTALPublicSetsLibrary.render(records);
  }, published);

  await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'available');
  const play = page.locator('.c5-sound-player-toggle');
  await expect(play).toHaveText('REPRODUCIR SET DESTACADO ↗');
  await expect(play).toHaveAttribute('aria-expanded', 'false');
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveCount(0);
  await play.click(); // Only this user gesture starts third-party loading.
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveAttribute('src', safeEmbed);
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveAttribute('sandbox', /allow-scripts/);
  await expect(play).toHaveAttribute('aria-expanded', 'true');

  await page.evaluate(() => {
    document.documentElement.dataset.locale = 'en';
    window.dispatchEvent(new Event('brvtal:localechange'));
  });
  await expect(play).toHaveText('CLOSE PLAYER ×');
  await play.click();
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveCount(0);
  await expect(play).toHaveText('PLAY FEATURED SET ↗');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('Concept 05 YouTube embeds send only origin referrer while SoundCloud stays private', async ({ page }) => {
  const referers = { youtube: [], soundcloud: [] };
  const fixture = '<!doctype html><title>Opt-in synthetic player</title>';
  await page.route('https://www.youtube-nocookie.com/**', route => {
    referers.youtube.push(route.request().headers()['referer'] || '');
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixture });
  });
  await page.route('https://w.soundcloud.com/**', route => {
    referers.soundcloud.push(route.request().headers()['referer'] || '');
    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixture });
  });
  await mount(page, { width: 390, height: 844 });

  const providers = [
    {
      kind: 'youtube',
      url: 'https://www.youtube-nocookie.com/embed/fictional-id?rel=0',
      policy: 'strict-origin-when-cross-origin',
    },
    {
      kind: 'soundcloud',
      url: 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fbrvtal%2Fsignal',
      policy: 'no-referrer',
    },
  ];
  for (const provider of providers) {
    await page.evaluate(records => {
      window.BRVTALPublicDataPromise = Promise.resolve({payload:{data:{sets:records}}});
      window.BRVTALPublicSetsLibrary.render(records);
    }, [{...sets[0], embed_url: provider.url}]);
    await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'available');
    const toggle = page.locator('.c5-sound-player-toggle');
    const iframe = page.locator('.c5-sound-player-frame iframe');
    await expect(iframe).toHaveCount(0);
    expect(referers[provider.kind]).toHaveLength(0);
    await toggle.click(); // Consent: no provider request before this click.
    await expect(iframe).toHaveAttribute('src', provider.url);
    await expect(iframe).toHaveAttribute('referrerpolicy', provider.policy);
    await expect.poll(() => referers[provider.kind].length).toBe(1);
    await toggle.click();
    await expect(iframe).toHaveCount(0);
  }
  expect(referers.youtube[0]).toBe(new URL(page.url()).origin + '/');
  expect(referers.soundcloud[0]).toBe('');
});

test('Concept 05 rejects untrusted CMS embeds and clears stale player when featured set disappears', async ({ page }) => {
  await mount(page);
  const malicious = [
    'javascript:alert(1)',
    'http://w.soundcloud.com/player/?url=x',
    'https://w.soundcloud.com.evil.test/player/?url=x',
    'https://evil.test/embed/track',
    'https://open.spotify.com.evil.test/embed/track',
    'https://w.soundcloud.com:8443/player/?url=x',
  ];
  for (const embed_url of malicious) {
    const records = sets.map((item, index) => index === 0 ? {...item, embed_url} : item);
    await page.evaluate(data => {
      window.BRVTALPublicDataPromise = Promise.resolve({payload:{data:{sets:data}}});
      window.BRVTALPublicSetsLibrary.render(data);
    }, records);
    await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'unavailable');
    await expect(page.locator('.c5-sound-player-toggle')).toHaveCount(0);
    await expect(page.locator('.c5-sound-player-frame iframe')).toHaveCount(0);
  }

  const safeEmbed = 'https://open.spotify.com/embed/track/7ouMYWpwJ422jRcDASZB7P';
  await page.evaluate(data => {
    window.BRVTALPublicDataPromise = Promise.resolve({payload:{data:{sets:data}}});
    window.BRVTALPublicSetsLibrary.render(data);
  }, [{...sets[0], embed_url: safeEmbed}]);
  await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'available');
  await expect(page.locator('.c5-sound-player-toggle')).toHaveCount(1);
  await page.evaluate(() => {
    window.BRVTALPublicSetsLibrary.render([]);
  });
  await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'unavailable');
  await expect(page.locator('.c5-sound-player')).toHaveCount(0);
});


test('Concept 05 unloads an active embed synchronously while replacement CMS data is pending', async ({ page }) => {
  await page.route('https://w.soundcloud.com/**', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><title>Opt-in audio fixture</title>',
  }));
  await mount(page, { width: 390, height: 844 });

  const oldEmbed = 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fbrvtal%2Fsignal';
  await page.evaluate(data => {
    window.BRVTALPublicDataPromise = Promise.resolve({payload:{data:{sets:data}}});
    window.BRVTALPublicSetsLibrary.render(data);
  }, [{...sets[0], embed_url: oldEmbed}]);

  await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'available');
  await page.locator('.c5-sound-player-toggle').click();
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveCount(1);

  // The next CMS request is deliberately never settled during the critical
  // transition. The old third-party iframe must disappear in the SAME turn.
  const staleDuringTransition = await page.evaluate(newSet => {
    window.BRVTALPublicDataPromise = new Promise(resolve => {
      window.__resolveNextFeaturedSet = resolve;
    });
    window.BRVTALPublicSetsLibrary.render([newSet]);
    return {
      player: document.querySelector('.c5-sound-player') !== null,
      frame: document.querySelector('.c5-sound-player-frame iframe') !== null,
      state: document.querySelector('#sets')?.dataset.c5Player,
    };
  }, {...sets[1], embed_url: 'https://open.spotify.com/embed/track/7ouMYWpwJ422jRcDASZB7P'});
  expect(staleDuringTransition).toEqual({ player:false, frame:false, state:'unavailable' });

  await page.evaluate(newSet => {
    window.__resolveNextFeaturedSet({payload:{data:{sets:[newSet]}}});
    delete window.__resolveNextFeaturedSet;
  }, {...sets[1], embed_url: 'https://open.spotify.com/embed/track/7ouMYWpwJ422jRcDASZB7P'});
  await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'available');
  await expect(page.locator('.c5-sound-player-toggle')).toHaveAttribute('aria-expanded', 'false');
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveCount(0);
});

test('Concept 05 artist filtering stops an opted-in Set and does not autoplay its replacement', async ({ page }) => {
  await page.route('https://w.soundcloud.com/**', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><title>Filtered player fixture</title>',
  }));
  const nextProviderRequests = [];
  await page.route('https://open.spotify.com/**', route => {
    nextProviderRequests.push(route.request().url());
    return route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: '<!doctype html><title>Filtered next provider</title>',
    });
  });
  await mount(page, { width: 390, height: 844 });

  const oldEmbed = 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fbrvtal%2Fsignal';
  const nextEmbed = 'https://open.spotify.com/embed/track/7ouMYWpwJ422jRcDASZB7P';
  const records = [
    { ...sets[0], embed_url: oldEmbed },
    {
      ...sets[1],
      artist_id: 99,
      artist_name: 'OTHER',
      artist_slug: 'other',
      embed_url: nextEmbed,
    },
  ];
  await page.evaluate(data => {
    window.BRVTALPublicDataPromise = Promise.resolve({ payload: { data: { sets: data } } });
    window.BRVTALPublicSetsLibrary.render(data);
  }, records);

  await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'available');
  await page.locator('.c5-sound-player-toggle').click();
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveAttribute('src', oldEmbed);

  // Canonical Sets filters re-render the list without a separate public-data event.
  // The Concept 05 MutationObserver must tear down old opt-in playback.
  await page.locator('[data-sets-mode="artist"]').click();
  await page.locator('[data-sets-relation="99"]').click();
  await expect(page.locator('.set-library-item')).toHaveCount(1);
  await expect(page.locator('.set-library-item').first()).toHaveAttribute('data-set-id', '2');
  await expect(page.locator('.c5-section-route--sound')).toHaveAttribute('href', '/sets/independent-transmission');
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveCount(0);
  await expect(page.locator('.c5-sound-player-toggle')).toHaveAttribute('aria-expanded', 'false');
  await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'available');
  expect(nextProviderRequests).toHaveLength(0);

  await page.locator('[data-sets-mode="latest"]').click();
  await expect(page.locator('.set-library-item')).toHaveCount(2);
  await expect(page.locator('.c5-section-route--sound')).toHaveAttribute('href', '/sets/genesis-closing-signal');
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= 390)).toBe(true);
});

test('Concept 05 stops a reused Set iframe before a new CMS promise resolves, but not on locale-only changes', async ({ page }) => {
  await page.route('https://w.soundcloud.com/**', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><title>Fake media player</title>',
  }));
  await mount(page, { width: 390, height: 844 });

  const oldEmbed = 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fold';
  const newEmbed = 'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fnew';
  await page.evaluate(data => {
    window.BRVTALPublicDataPromise = Promise.resolve({ payload: { data: { sets: data } } });
    window.BRVTALPublicSetsLibrary.render(data);
  }, [{ ...sets[0], embed_url: oldEmbed }]);
  await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'available');
  await page.locator('.c5-sound-player-toggle').click();
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveAttribute('src', oldEmbed);

  // A translation of the existing DOM is not a CMS content revision.
  await page.evaluate(() => {
    document.documentElement.dataset.locale = 'en';
    window.dispatchEvent(new CustomEvent('brvtal:localechange'));
  });
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveAttribute('src', oldEmbed);

  const immediate = await page.evaluate(updated => {
    window.BRVTALPublicDataPromise = new Promise(resolve => { window.__resolveSameIdSet = resolve; });
    window.BRVTALPublicSetsLibrary.render([updated]); // SAME Set ID, new DOM/URL.
    return {
      oldPlayer: !!document.querySelector('.c5-sound-player'),
      oldFrame: !!document.querySelector('.c5-sound-player-frame iframe'),
      state: document.querySelector('#sets')?.dataset.c5Player,
    };
  }, { ...sets[0], embed_url: newEmbed });
  expect(immediate).toEqual({ oldPlayer: false, oldFrame: false, state: 'unavailable' });

  await page.evaluate(updated => {
    window.__resolveSameIdSet({ payload: { data: { sets: [updated] } } });
    delete window.__resolveSameIdSet;
  }, { ...sets[0], embed_url: newEmbed });
  await expect(page.locator('#sets')).toHaveAttribute('data-c5-player', 'available');
  await expect(page.locator('.c5-sound-player-toggle')).toHaveAttribute('aria-expanded', 'false');
  await expect(page.locator('.c5-sound-player-frame iframe')).toHaveCount(0);
});

test('Concept 05 attaches Sound and Memories owner-v2 comparison evidence at 390 and 1440', async ({ page }, info) => {
  const ownerReference = readFileSync(join(root, 'docs/reference/home-concept05-owner-reference-v2.png'));
  expect(ownerReference.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
  await info.attach('owner-v2-reference', { body:ownerReference, contentType:'image/png' });
  const digest = image => createHash('sha256').update(image).digest('hex');
  const manifest = {
    schema:'brvtal-c05-sound-memories-evidence-v1',
    source:'synthetic CMS Set/Memory records and intercepted SVG media (not production)',
    reference:'docs/reference/home-concept05-owner-reference-v2.png',
    reference_sha256:digest(ownerReference),
    review_status:'PENDING_HUMAN_AB_COMPARISON',
    accepted_visual_deviations:null,
    review_notes:['Compare both blocks to owner v2 for each viewport before merging',
      'Synthetic records and placeholder SVGs are not production CMS/photography'],
    captures:[],
  };
  for (const viewport of [{width:390,height:844},{width:1440,height:900}]) {
    await mount(page, viewport);
    await expect(page.locator('.set-library-item')).toHaveCount(4);
    await expect(page.locator('.c5-memory-cell')).toHaveCount(3);
    for (const [block, selector] of [['sound','#sets'],['memories','#media']]) {
      const section = page.locator(selector);
      // Native lazy media can still be offscreen after CMS hydration. Force each
      // permitted fixture image into view, then require decoded pixels before A/B.
      const assets = section.locator('img[src]:not([hidden])');
      for (const asset of await assets.all()) {
        await asset.scrollIntoViewIfNeeded();
        await expect.poll(() => asset.evaluate(img => img.complete && img.naturalWidth > 0),
          {timeout:10000}).toBe(true);
      }
      const image = await section.screenshot({animations:'disabled',caret:'hide'});
      await info.attach('c05-' + block + '-' + viewport.width, {body:image,contentType:'image/png'});
      manifest.captures.push({viewport:viewport.width,block,sha256:digest(image)});
    }
    const full = await page.screenshot({fullPage:true,animations:'disabled',caret:'hide'});
    await info.attach('c05-fullpage-' + viewport.width, {body:full,contentType:'image/png'});
    manifest.captures.push({viewport:viewport.width,block:'fullpage',sha256:digest(full)});
  }
  expect(manifest.captures).toHaveLength(6);
  await info.attach('c05-owner-v2-ab-manifest', {
    body:Buffer.from(JSON.stringify(manifest,null,2)),contentType:'application/json',
  });
});

test('Concept 05 image-delivery fallback preserves a Memory until the original upload also fails', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await page.route(harness, route => route.fulfill({contentType:'text/html; charset=utf-8',body:markup()}));
  await page.route('**/api/public-image-delivery.php', route => route.fulfill({
    contentType:'application/json',
    body:JSON.stringify({ok:true,data:{
      '/uploads/fallback-ok.svg':{variants:{card:{src:'/uploads/failing-card.webp',width:600,height:800}}},
      '/uploads/fallback-broken.svg':{variants:{card:{src:'/uploads/failing-card-two.webp',width:600,height:800}}},
    }}),
  }));
  await page.route('**/uploads/failing-card*.webp', route => route.fulfill({status:404,body:'missing derivative'}));
  await page.route('**/uploads/fallback-ok.svg', route => route.fulfill({
    contentType:'image/svg+xml',
    body:'<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800"></svg>',
  }));
  await page.route('**/uploads/fallback-broken.svg', route => route.fulfill({status:404,body:'missing original'}));
  await page.goto(harness);
  await page.addScriptTag({content:mediaJs});
  await page.addScriptTag({content:enhancerJs});
  await page.evaluate(() => window.BRVTALPublicMedia.loadImageDelivery());

  for (const [path,canRecover] of [['/uploads/fallback-ok.svg',true],['/uploads/fallback-broken.svg',false]]) {
    await page.evaluate(file_path => {
      window.BRVTALPublicMedia.render([{id:81,media_id:181,type:'image',title:'FALLBACK MEMORY',file_path}]);
      const image=document.querySelector('.c5-memory-cell img');
      // Use the canonical image-delivery fallback listener, not a reimplementation.
      window.BRVTALPublicMedia.applyResponsiveImage(image,'card');
    },path);
    const item=page.locator('.c5-memory-cell');
    const image=item.locator('img');
    const opener=item.locator('[data-public-media-open]');
    if (canRecover) {
      await expect.poll(() => image.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
      await expect(image).toHaveAttribute('src','/uploads/fallback-ok.svg');
      await expect(image).toBeVisible();
      await expect(item).not.toHaveClass(/is-media-missing/);
      await expect(opener).toBeEnabled();
    } else {
      await expect(item).toHaveClass(/is-media-missing/);
      await expect(image).toBeHidden();
      await expect(opener).toBeDisabled();
    }
  }
});

test('Concept 05 Sound cover returns after WebP fallback, and hides only if original also fails', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await page.route(harness, route => route.fulfill({contentType:'text/html; charset=utf-8',body:markup()}));
  await page.route('**/api/public-image-delivery.php', route => route.fulfill({
    contentType:'application/json',
    body:JSON.stringify({ok:true,data:{
      '/uploads/sound-original.svg':{variants:{card:{src:'/uploads/sound-derivative.webp',width:600,height:800}}},
      '/uploads/sound-gone.svg':{variants:{card:{src:'/uploads/sound-derivative-gone.webp',width:600,height:800}}},
    }}),
  }));
  await page.route('**/uploads/sound-derivative*.webp', route => route.fulfill({status:404,body:'missing derivative'}));
  await page.route('**/uploads/sound-original.svg', route => route.fulfill({
    contentType:'image/svg+xml',
    body:'<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800"></svg>',
  }));
  await page.route('**/uploads/sound-gone.svg', route => route.fulfill({status:404,body:'missing original'}));
  await page.goto(harness);
  await page.addScriptTag({content:setsJs});
  await page.addScriptTag({content:mediaJs});
  await page.addScriptTag({content:enhancerJs});
  await page.evaluate(() => window.BRVTALPublicMedia.loadImageDelivery());

  for (const [path,recovers] of [['sound-original.svg',true],['sound-gone.svg',false]]) {
    await page.evaluate(item => {
      window.BRVTALPublicSetsLibrary.render([item]);
    }, {...sets[0],cover_image:'http://127.0.0.1:4173/uploads/' + path});
    const cover=page.locator('.set-library-cover').first();
    const image=cover.locator('img');
    // A failed original can hide a lazy cover before the WebP retry begins.
    // Eager loading triggers the retry without scrolling an invisible element.
    await image.evaluate(node => { node.loading = 'eager'; });
    await expect(image).toHaveAttribute('data-brvtal-fallback-applied','1');
    if (recovers) {
      await expect.poll(() => image.evaluate(el => el.complete && el.naturalWidth > 0)).toBe(true);
      await expect(image).toBeVisible();
      await expect(cover).not.toHaveClass(/set-library-cover--empty|is-media-missing/);
      await expect(cover).not.toHaveAttribute('aria-hidden','true');
    } else {
      await expect(cover).toHaveClass(/set-library-cover--empty|is-media-missing/);
      await expect(image).toBeHidden();
      await expect(cover).toHaveAttribute('aria-hidden','true');
    }
  }
});


test('Concept 05 applies Sound card fallback when manifest resolves after CMS rendering', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  let allowManifest;
  const manifestGate = new Promise(resolve => { allowManifest = resolve; });
  await page.route(harness, route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: markup() }));
  await page.route('**/api/public-image-delivery.php', async route => {
    await manifestGate;
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: {
      '/uploads/sound-original.svg': { variants: { card: { src: '/uploads/sound-derivative.webp', width: 600, height: 800 } } },
      '/uploads/sound-gone.svg': { variants: { card: { src: '/uploads/sound-derivative-gone.webp', width: 600, height: 800 } } },
    } }) });
  });
  await page.route('**/uploads/sound-derivative*.webp', route => route.fulfill({ status: 404, body: 'missing derivative' }));
  await page.route('**/uploads/sound-original.svg', route => route.fulfill({
    contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800"></svg>',
  }));
  await page.route('**/uploads/sound-gone.svg', route => route.fulfill({ status: 404, body: 'missing original' }));
  await page.goto(harness);
  await page.addScriptTag({ content: setsJs });
  await page.addScriptTag({ content: mediaJs });
  await page.addScriptTag({ content: enhancerJs });
  await page.evaluate(items => window.BRVTALPublicSetsLibrary.render(items), [
    { ...sets[0], id: 101, cover_image: 'http://127.0.0.1:4173/uploads/sound-original.svg' },
    { ...sets[0], id: 102, cover_image: 'http://127.0.0.1:4173/uploads/sound-gone.svg' },
  ]);
  const covers = page.locator('.set-library-cover');
  await expect(covers).toHaveCount(2);
  await page.evaluate(() => document.querySelectorAll('.set-library-cover img').forEach(img => { img.loading = 'eager'; }));
  allowManifest();
  for (const [index, recovers] of [[0, true], [1, false]]) {
    const cover = covers.nth(index);
    const image = cover.locator('img');
    await expect(image).toHaveAttribute('data-brvtal-fallback-applied', '1');
    if (recovers) {
      await expect.poll(() => image.evaluate(el => el.complete && el.naturalWidth > 0)).toBe(true);
      await expect(image).toBeVisible();
      await expect(cover).not.toHaveClass(/set-library-cover--empty|is-media-missing/);
    } else {
      await expect(image).toBeHidden();
      await expect(cover).toHaveClass(/set-library-cover--empty|is-media-missing/);
    }
  }
});


test('Concept 05 late successful image load revives a previously failed Memory', async ({ page }) => {
  // Synthetic CMS asset and intercepted delivery: no real production media.
  await page.setViewportSize({ width: 390, height: 844 });
  await page.route(harness, route => route.fulfill({
    contentType: 'text/html; charset=utf-8', body: markup(),
  }));
  await page.route('**/uploads/c5-late-missing.svg', route => route.fulfill({
    status: 404, body: 'missing original',
  }));
  await page.route('**/uploads/c5-late-recovered.svg', route => route.fulfill({
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="480"></svg>',
  }));
  await page.goto(harness);
  await page.addScriptTag({ content: mediaJs });
  await page.addScriptTag({ content: enhancerJs });

  await page.evaluate(() => {
    window.BRVTALPublicMedia.render([{
      id: 91, media_id: 191, type: 'image', title: 'LATE RECOVERY MEMORY',
      file_path: 'http://127.0.0.1:4173/uploads/c5-late-missing.svg',
    }]);
    window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT();
  });

  const cell = page.locator('.c5-memory-cell').first();
  const image = cell.locator('img');
  const opener = cell.locator('[data-public-media-open]');
  await expect(cell).toHaveClass(/is-media-missing/);
  await expect(image).toBeHidden();
  await expect(opener).toBeDisabled();

  await image.evaluate(node => {
    node.src = 'http://127.0.0.1:4173/uploads/c5-late-recovered.svg';
  });
  await expect.poll(() => image.evaluate(node =>
    node.complete && node.naturalWidth > 0)).toBe(true);
  await expect(cell).not.toHaveClass(/is-media-missing/);
  await expect(image).toBeVisible();
  await expect(opener).toBeEnabled();
  await expect(opener).not.toHaveAttribute('aria-disabled', 'true');
});
