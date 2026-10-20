import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
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

test('Python AC-01..04 bridge executes alongside Playwright acceptance', () => {
  // package.json is reserved by a parallel V3, so wire the four Python AC
  // tests into the existing Playwright suite without modifying that manifest.
  execFileSync('python3', [
    '-m', 'unittest', '-v', 'tests/test_home_c05_journal_connected.py',
  ], {cwd:root,encoding:'utf8',timeout:30000,stdio:'pipe'});
});

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

test('Connected tolerates null CMS relationship counts without fabricated edges', async ({page}) => {
  const empty = structuredClone(data);
  empty.relations = {counts:null};
  await mount(page, empty);
  await expect(page.locator('[data-connected-lines] line')).toHaveCount(0);
  await expect(page.locator('[data-connected-ledger]')).toContainText('NO STRUCTURED LINKS YET.');
  await expect(page.locator('[data-connected-edges]')).toContainText('NO STRUCTURED RELATIONSHIPS PUBLISHED YET.');
  await expect(page.locator('[data-connected-node="events"] [data-connected-count]')).toHaveText('2');
});

test('Connected rejects malformed CMS counts without fabricating graph edges', async ({page}) => {
  const malformed = structuredClone(data);
  malformed.relations = {counts:{
    event_artist:true,
    event_set:{value:2},
    artist_set:[1,2],
    artist_release:-7,
    event_memory:'1.5',
    artist_memory:'9999999999999999999999',
    set_memory:'2',
    release_memory:'-1',
  }};
  await mount(page, malformed);
  await expect(page.locator('[data-connected-lines] line')).toHaveCount(1);
  await expect(page.locator('[data-connected-edge="set_memory"]')).toHaveAttribute('data-connected-count','2');
  await expect(page.locator('[data-connected-edge="event_artist"]')).toHaveCount(0);
  await expect(page.locator('[data-connected-edges]')).toContainText('2 VERIFIED RELATIONAL LINKS');
});

test('Connected declines an imprecise aggregate total while preserving exact CMS edges', async ({page}) => {
  const large = structuredClone(data);
  large.relations = {counts:{event_artist:Number.MAX_SAFE_INTEGER,event_set:1}};
  await mount(page, large);
  await expect(page.locator('[data-connected-lines] line')).toHaveCount(2);
  await expect(page.locator('[data-connected-edge="event_artist"]'))
    .toHaveAttribute('data-connected-count',String(Number.MAX_SAFE_INTEGER));
  await expect(page.locator('[data-connected-ledger-edge="event_set"]')).toContainText('1');
  await expect(page.locator('[data-connected-edges]')).toHaveText('RELATIONAL LINK TOTAL UNAVAILABLE.');
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

test('CMS publishes newest Journal entries before the top five, rejects drafts and invalid dates', async ({page}) => {
  const scrambled = structuredClone(data);
  scrambled.blog = [
    data.blog[2],
    {...data.blog[0],id:96,slug:'invalid-month',published_at:'2026-13-01 00:00:00'},
    data.blog[1],
    {...data.blog[0],id:97,slug:'draft',status:'draft',published_at:'2026-10-10 00:00:00'},
    {...data.blog[0],id:98,slug:'unsafe<script>',published_at:'2026-10-11 00:00:00'},
    {...data.blog[0],id:100,slug:'published-undated',published_at:null},
    data.blog[0],
    {...data.blog[1],id:99,slug:'same-time',published_at:data.blog[1].published_at},
  ];
  await mount(page, scrambled);
  await expect(page.locator('.transmission-card')).toHaveCount(5);
  await expect(page.locator('.transmission-feature .transmission-link'))
    .toHaveAttribute('href','/blog/night-does-not-end');
  expect(await page.locator('.transmission-link').evaluateAll(nodes =>
    nodes.map(node => node.getAttribute('href'))
  )).toEqual([
    '/blog/night-does-not-end','/blog/pressure-systems','/blog/same-time',
    '/blog/records-as-memory','/blog/published-undated'
  ]);
  await expect(page.getByRole('link',{name:'Open latest Journal entry'}))
    .toHaveAttribute('href','/blog/night-does-not-end');
  await expect(page.locator('a[href="/blog/draft"],a[href="/blog/invalid-month"]')).toHaveCount(0);
});

test('Published CMS post without a publication date stays visible without a false latest claim', async ({page}) => {
  const undated = structuredClone(data);
  undated.blog = [{...data.blog[0],slug:'published-undated',published_at:null}];
  await mount(page, undated);
  await expect(page.locator('.transmission-card')).toHaveCount(1);
  await expect(page.locator('.transmission-feature .transmission-link'))
    .toHaveAttribute('href','/blog/published-undated');
  await expect(page.locator('.transmission-meta')).toContainText('TRANSMISSION');
  await expect(page.getByRole('link',{name:'Open latest Journal entry'})).toHaveCount(0);
  await expect(page.locator('.transmissions-route')).toHaveCount(0);
});

test('real home partials source mounts exactly one Journal 06 and Connected 07 at 390 and 1440', async ({page}, testInfo) => {
  const source = readFileSync(join(root,'index.html'),'utf8');
  const journal = readFileSync(join(root,'partials/home/journal.html'),'utf8');
  const connected = readFileSync(join(root,'partials/home/connected.html'),'utf8');
  const php = readFileSync(join(root,'index.php'),'utf8');
  const loader = readFileSync(join(root,'js/public-runtime-loader.js'),'utf8');
  expect(php).toContain("'journal' => __DIR__ . '/partials/home/journal.html'");
  expect(php).toContain("'connected' => __DIR__ . '/partials/home/connected.html'");
  expect(php).toContain("'css/public-concept05-tokens.css'");
  expect(php).toContain("'css/public-concept05-journal-connected.css'");
  expect(php).toContain('data-concept="05"');
  expect(php.indexOf("'css/public-concept05-tokens.css'"))
    .toBeLessThan(php.indexOf("'css/public-concept05-journal-connected.css'"));
  expect(loader).toContain("'js/public-concept05-connected.js'");
  expect(source.match(/<!-- BRVTAL-HOME-PARTIAL:journal -->/g)).toHaveLength(1);
  expect(source.match(/<!-- BRVTAL-HOME-PARTIAL:connected -->/g)).toHaveLength(1);
  expect(journal).toContain('data-index="06"');
  expect(connected).toContain('data-index="07"');
  const preview = source.replace('<!-- BRVTAL-HOME-PARTIAL:journal -->',journal)
    .replace('<!-- BRVTAL-HOME-PARTIAL:connected -->',connected);
  expect(preview).toContain('id="transmissions"');
  expect(preview).toContain('id="connected"');
  for (const width of [390,1440]) {
    await page.setViewportSize({width,height:900});
    const localHtml = markup(data).replace(
      /<section class="scene transmissions c5-numbered"[\s\S]*?<\/section>/,
      journal
    ).replace(
      /<section class="connected scene c5-numbered"[\s\S]*?<\/section>/,
      connected
    );
    await page.route(harness, route=>route.fulfill({contentType:'text/html; charset=utf-8',body:localHtml}));
    await page.goto(harness);
    await page.addScriptTag({content:journalJs});
    await page.addScriptTag({content:connectedJs});
    await expect(page.locator('#transmissions[data-index="06"]')).toHaveCount(1);
    await expect(page.locator('#connected[data-index="07"]')).toHaveCount(1);
    await expect(page.locator('[data-connected-node]')).toHaveCount(5);
    await expect(page.locator('.transmission-feature')).toHaveCount(1);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await testInfo.attach('build-ahead-local-partials-'+width+'.png',{
      body:await page.screenshot({fullPage:true}), contentType:'image/png'
    });
  }
});
