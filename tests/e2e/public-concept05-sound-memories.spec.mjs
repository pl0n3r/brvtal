import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
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
    cover_image: 'http://127.0.0.1:4173/media/sound-cover.svg',
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
];

const memories = [
  {
    id: 21,
    media_id: 201,
    type: 'image',
    title: 'WAREHOUSE PRESSURE',
    context: 'GENESIS / PEREIRA',
    alt_text: 'Crowd at GENESIS',
    file_path: 'http://127.0.0.1:4173/media/memory-one.svg',
    relations: [{ related_type: 'event', related_id: 20, route_type: 'events', slug: 'genesis', label: 'GENESIS' }],
  },
  {
    id: 22,
    media_id: 202,
    type: 'image',
    title: 'RED AFTERIMAGE',
    context: '05:12 / LIGHTS ON',
    alt_text: 'Red light afterimage',
    file_path: 'http://127.0.0.1:4173/media/memory-two.svg',
    relations: [],
  },
  {
    id: 23,
    media_id: 203,
    type: 'audio',
    title: 'ROOM TONE',
    context: 'AUDIO MEMORY',
    file_path: 'http://127.0.0.1:4173/media/room-tone.mp3',
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
  await page.route('**/media/*.svg', route => route.fulfill({
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="640"><rect width="800" height="640" fill="white"/></svg>',
  }));
  await page.route('**/media/room-tone.mp3', route => route.fulfill({ contentType: 'audio/mpeg', body: '' }));
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

  await expect(page.locator('.set-library-item')).toHaveCount(2);
  await expect(page.locator('.set-library-item').first()).toHaveClass(/c5-sound-feature/);
  await expect(page.locator('.set-library-item').first().locator('.set-record-link')).toHaveAttribute('href', '/sets/genesis-closing-signal');
  await expect(page.locator('.set-library-item').first().locator('.set-listen-action')).toHaveAttribute('href', 'https://soundcloud.com/brvtal/signal');
  await expect(page.locator('.set-library-item').nth(1).locator('.set-listen-action')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'ARTIST / PL0N3R' })).toHaveAttribute('href', '/artists/pl0n3r');
  await expect(page.getByRole('link', { name: 'EVENT / GENESIS' })).toHaveAttribute('href', '/events/genesis');
  await expect(page.locator('.c5-sound-signal')).toHaveCount(1);
  await expect(page.locator('.c5-section-route--sound')).toHaveAttribute('href', '/sets/genesis-closing-signal');

  const cover = await page.locator('.c5-sound-feature .set-library-cover').boundingBox();
  expect(cover).not.toBeNull();
  expect(cover.width).toBeGreaterThan(220);

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

  const cover = await page.locator('.c5-sound-feature .set-library-cover').boundingBox();
  expect(cover).not.toBeNull();
  expect(cover.width).toBeGreaterThan(340);

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
  await page.route('**/missing-*', route => route.fulfill({ status: 404, body: 'missing' }));
  await page.goto(harness);
  await page.addScriptTag({ content: setsJs });
  await page.addScriptTag({ content: mediaJs });
  await page.addScriptTag({ content: enhancerJs });
  await page.evaluate(() => {
    window.BRVTALPublicSetsLibrary.render([{
      id: 1,
      title: 'BROKEN COVER',
      slug: 'broken-cover',
      cover_image: 'http://127.0.0.1:4173/missing-cover.jpg',
    }]);
    window.BRVTALPublicMedia.render([{
      id: 2,
      media_id: 202,
      type: 'image',
      title: 'BROKEN MEMORY',
      file_path: 'http://127.0.0.1:4173/missing-memory.jpg',
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

test('Concept 05 Sound and Memories motion is static for reduced-motion users', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await mount(page);

  const memoryTransition = await page.locator('.c5-memory-cell img').first().evaluate(node => getComputedStyle(node).transitionDuration);
  const filterTransition = await page.locator('[data-sets-mode]').first().evaluate(node => getComputedStyle(node).transitionDuration);
  expect(memoryTransition).toBe('0s');
  expect(filterTransition).toBe('0s');
});
