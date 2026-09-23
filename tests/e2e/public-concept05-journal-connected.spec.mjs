import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const journalJs = readFileSync(join(root, 'js/public-transmissions.js'), 'utf8');
const connectedJs = readFileSync(join(root, 'js/public-concept05-connected.js'), 'utf8');
const css = [
  'css/public-concept05-tokens.css',
  'css/public-concept05-home.css',
  'css/public-transmissions.css',
  'css/public-concept05-journal-connected.css',
].map(path => readFileSync(join(root, path), 'utf8')).join('\n');

const harness = 'http://127.0.0.1:4173/concept05-journal-connected.html';

const data = {
  events: [{id:7,title:'GENESIS',slug:'genesis'}],
  archive: {events:[{id:8,title:'PAST SIGNAL',slug:'past-signal'}]},
  artists: [{id:2,name:'PL0N3R',slug:'pl0n3r'}],
  sets: [{id:3,title:'GENESIS SET',slug:'genesis-set'}],
  releases: [{id:4,title:'SIGNAL 001',slug:'signal-001'}],
  memories: [{id:5,media_id:50,title:'FLOOR',file_path:'/uploads/floor.svg',type:'image'}],
  blog: [
    {
      id:11,
      title:'THE NIGHT DOES NOT END WHEN THE LIGHTS COME ON',
      slug:'night-does-not-end',
      excerpt:'A field note about bodies, noise, memory and the city after the final signal.',
      cover_image:'/uploads/journal-cover.svg',
      published_at:'2026-09-22 18:00:00',
      tags:[{name:'Culture'},{name:'Archive'}],
      relations:[
        {related_type:'event',related_id:7},
        {related_type:'artist',related_id:2},
      ],
    },
    {
      id:12,
      title:'PRESSURE SYSTEMS',
      slug:'pressure-systems',
      excerpt:'Notes from the sound floor.',
      cover_image:'',
      published_at:'2026-09-20 18:00:00',
      tags:[{name:'Sound'}],
      relations:[{related_type:'set',related_id:3}],
    },
    {
      id:13,
      title:'RECORDS AS MEMORY',
      slug:'records-as-memory',
      excerpt:'Catalog notes.',
      cover_image:'',
      published_at:'2026-09-19 18:00:00',
      tags:[],
      relations:[{related_type:'release',related_id:4}],
    },
  ],
  relations: {
    counts: {
      event_artist:2,
      event_set:1,
      artist_set:1,
      artist_release:1,
      event_memory:1,
      artist_memory:1,
      set_memory:0,
      release_memory:0,
    },
  },
};

function markup(payload = data) {
  const json = JSON.stringify(payload).replace(/</g, '\\u003c');
  return `<!doctype html><html data-concept="05"><head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>*{box-sizing:border-box}html,body{margin:0;width:100%;background:#050505;color:#e8e6df}${css}</style>
</head><body data-concept="05">
<section class="scene transmissions c5-numbered" id="transmissions" data-dynamic="transmissions">
  <div class="section-head"><span class="mono">BRVTAL JOURNAL / 06</span><h2>JOURNAL</h2><span class="mono" data-transmissions-count>SIGNAL ARCHIVE</span></div>
  <div class="transmissions-intro"><h3>FIELD<br>NOTES.</h3><p>Dispatches from BRVTAL culture.</p></div>
  <div class="transmissions-list" data-transmissions-list><article id="fallback">STATIC JOURNAL FALLBACK</article></div>
</section>
<section class="connected scene c5-numbered" data-scene="ARCHIVE" data-index="07" id="connected" aria-labelledby="connected-title">
  <div class="c5-connected-graph" data-connected-graph aria-live="polite">
    <h2 id="connected-title" class="sr-only">Connected</h2>
    <div class="c5-connected-kicker mono">PUBLIC RELATIONAL SYSTEM / VERIFIED EDGES ONLY</div>
    <svg class="c5-connected-lines" data-connected-lines aria-hidden="true"></svg>
    <ul class="c5-connected-nodes">
      <li data-connected-node="events"><a href="#events"><span class="mono">EVENTS</span><strong data-connected-count>—</strong></a></li>
      <li data-connected-node="artists"><a href="#artists"><span class="mono">ARTISTS</span><strong data-connected-count>—</strong></a></li>
      <li data-connected-node="sets"><a href="#sets"><span class="mono">SOUND</span><strong data-connected-count>—</strong></a></li>
      <li data-connected-node="releases"><a href="/releases"><span class="mono">RECORDS</span><strong data-connected-count>—</strong></a></li>
      <li data-connected-node="memories"><a href="#media"><span class="mono">MEMORIES</span><strong data-connected-count>—</strong></a></li>
    </ul>
    <div class="c5-connected-ledger" data-connected-ledger aria-label="Published relationships"></div>
    <p class="c5-connected-edges mono" data-connected-edges>&nbsp;</p>
    <p class="c5-connected-tagline">TODO CONECTADO.</p>
  </div>
</section>
<script>
window.__publicReads=0;
window.BRVTALPublicDataPromise=Promise.resolve({payload:{data:${json}}}).then(value=>{window.__publicReads+=1;return value;});
</script>
</body></html>`;
}

async function mount(page, payload = data, viewport = {width:1440,height:900}, brokenCover = false) {
  await page.setViewportSize(viewport);
  await page.route(harness, route => route.fulfill({contentType:'text/html; charset=utf-8',body:markup(payload)}));
  await page.route('**/uploads/journal-cover.svg', route => route.fulfill(
    brokenCover
      ? {status:404,body:'missing'}
      : {contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="white"/></svg>'}
  ));
  await page.goto(harness);
  await page.addScriptTag({content:journalJs});
  await page.addScriptTag({content:connectedJs});
  await page.waitForFunction(() =>
    document.documentElement.dataset.publicTransmissions === 'editorial'
    && document.documentElement.dataset.publicConnected === 'relational'
  );
  await page.waitForTimeout(50);
}

test('Concept 05 desktop Journal authors a real feature/index and Connected draws only verified graph edges', async ({page}) => {
  await mount(page);

  await expect(page.locator('.transmission-card')).toHaveCount(3);
  const feature = page.locator('.transmission-feature');
  await expect(feature).toHaveCount(1);
  await expect(feature.locator('.transmission-link')).toHaveAttribute('href','/blog/night-does-not-end');
  await expect(feature.locator('.transmission-cover img')).toBeVisible();
  await expect(feature.locator('.transmission-relations a')).toHaveCount(2);
  await expect(feature.getByRole('link',{name:'EVENT / GENESIS'})).toHaveAttribute('href','/events/genesis');
  await expect(feature.getByRole('link',{name:'ARTIST / PL0N3R'})).toHaveAttribute('href','/artists/pl0n3r');
  await expect(page.locator('.transmission-indexed')).toHaveCount(2);
  await expect(page.getByRole('link',{name:'Open latest Journal entry'})).toHaveAttribute('href','/blog/night-does-not-end');

  await expect(page.locator('[data-connected-node="events"] [data-connected-count]')).toHaveText('2');
  await expect(page.locator('[data-connected-node="artists"] [data-connected-count]')).toHaveText('1');
  await expect(page.locator('[data-connected-node="sets"] [data-connected-count]')).toHaveText('1');
  await expect(page.locator('[data-connected-node="releases"] [data-connected-count]')).toHaveText('1');
  await expect(page.locator('[data-connected-node="memories"] [data-connected-count]')).toHaveText('1');

  await expect(page.locator('[data-connected-lines] line')).toHaveCount(6);
  await expect(page.locator('[data-connected-edge="event_artist"]')).toHaveAttribute('data-connected-count','2');
  await expect(page.locator('[data-connected-edge="set_memory"]')).toHaveCount(0);
  await expect(page.locator('[data-connected-ledger-edge="release_memory"]')).toHaveCount(0);
  await expect(page.locator('[data-connected-edges]')).toContainText('7 VERIFIED RELATIONAL LINKS');

  expect(await page.evaluate(() => window.__publicReads)).toBe(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('Concept 05 Journal + Connected is separately authored and touch-safe at 390', async ({page}) => {
  await mount(page, data, {width:390,height:844});

  const feature = await page.locator('.transmission-feature').boundingBox();
  expect(feature).not.toBeNull();
  expect(feature.width).toBeGreaterThan(350);

  const indexed = await page.locator('.transmission-indexed').first().boundingBox();
  expect(indexed).not.toBeNull();
  expect(indexed.width).toBeGreaterThan(350);

  for (const link of await page.locator('.c5-connected-nodes a').all()) {
    const box = await link.boundingBox();
    expect(box.height).toBeGreaterThanOrEqual(44);
  }

  await expect(page.locator('.c5-connected-nodes li').last()).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('Journal feature cover fails closed while editorial copy and canonical route remain usable', async ({page}) => {
  await mount(page, data, {width:900,height:800}, true);

  await expect(page.locator('.transmission-cover')).toHaveClass(/is-media-missing/);
  await expect(page.locator('.transmission-cover img')).toBeHidden();
  await expect(page.locator('.transmission-feature h3')).toContainText('THE NIGHT DOES NOT END');
  await expect(page.locator('.transmission-feature .transmission-link')).toHaveAttribute('href','/blog/night-does-not-end');
});

test('Connected renders no fake lines when the canonical relation graph has zero edges', async ({page}) => {
  const zero = structuredClone(data);
  zero.relations = {counts:{}};
  await mount(page, zero);

  await expect(page.locator('[data-connected-lines] line')).toHaveCount(0);
  await expect(page.locator('[data-connected-ledger]')).toContainText('NO STRUCTURED LINKS YET.');
  await expect(page.locator('[data-connected-edges]')).toContainText('NO STRUCTURED RELATIONSHIPS PUBLISHED YET.');
  await expect(page.locator('[data-connected-node="events"] [data-connected-count]')).toHaveText('2');
});

test('Journal empty state remains honest and reduced-motion leaves the authored modules static', async ({page}) => {
  await page.emulateMedia({reducedMotion:'reduce'});
  const empty = structuredClone(data);
  empty.blog = [];
  await mount(page, empty);

  await expect(page.locator('.transmissions-empty')).toContainText('NO TRANSMISSIONS PUBLISHED YET.');
  await expect(page.locator('.transmission-card')).toHaveCount(0);
  const nodeTransition = await page.locator('.c5-connected-nodes a').first().evaluate(node => getComputedStyle(node).transitionDuration);
  expect(nodeTransition).toBe('0s');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});
