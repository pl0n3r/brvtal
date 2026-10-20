import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'js/public-sets-library.js'), 'utf8');
const styles = readFileSync(join(process.cwd(), 'css/public-sets-library.css'), 'utf8');
const harness = 'http://127.0.0.1:4173/public-sets-library-phase-c.html';

const sets = [
  {
    id:3,duration_seconds:5432,title:'DNL5 — Closing Signal',slug:'dnl5-closing-signal',artist_id:12,artist_name:'DNL5',artist_slug:'dnl5',
    event_id:null,event_title:null,event_slug:null,platform:'soundcloud',external_url:'https://soundcloud.com/brvtal/dnl5-closing-signal',description:'Recorded live for BRVTAL.'
  },
  {
    id:2,duration_seconds:95,title:'GENESIS — PL0N3R',slug:'genesis-pl0n3r',artist_id:13,artist_name:'PL0N3R',artist_slug:'pl0n3r',
    event_id:7,event_title:'GENESIS',event_slug:'genesis',platform:'youtube',external_url:'https://youtube.com/watch?v=brvtal',description:'A live record from GENESIS.'
  },
  {
    id:1,duration_seconds:null,title:'HAKKI — Session Archive',slug:'hakki-session-archive',artist_id:11,artist_name:'HAKKI',artist_slug:'hakki',
    event_id:6,event_title:'SESSION #5',event_slug:'session-5',platform:'soundcloud',external_url:'https://soundcloud.com/brvtal/hakki-session',description:''
  },
  {
    id:4,duration_seconds:0,title:'Independent Transmission',slug:'independent-transmission',artist_id:null,artist_name:null,artist_slug:null,
    event_id:null,event_title:null,event_slug:null,platform:'other',external_url:'https://example.com/listen',description:'Standalone BRVTAL transmission.'
  },
];

function markup(promiseScript, libraryScript = script) {
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>
    :root{--line:rgba(255,255,255,.14);--magenta:#ff147f;--accent:#ff147f}*{box-sizing:border-box}html,body{margin:0;background:#090909;color:#eee;font-family:Arial,sans-serif}.mono{font-family:monospace}.sets{padding:40px 5vw;--accent:var(--magenta)}.sets-intro{margin:30px 0}.set-list{border-top:1px solid var(--line)}.set-item{display:grid;grid-template-columns:100px 1fr 80px;gap:20px;padding:28px 0;border-bottom:1px solid var(--line)}
    ${styles}
  </style></head><body>
    <section class="sets" id="sets">
      <div class="section-head"><span class="mono">SOUND LIBRARY / 00</span><h2>SETS</h2><span class="mono">LISTEN / EXPLORE</span></div>
      <div class="sets-intro"><p>BRVTAL SOUND</p><h3>IT IS THE EXPERIENCE.</h3></div>
      <div class="set-list"><article class="set-item" id="fallback-set"><div>000</div><div>STATIC FALLBACK</div></article></div>
    </section>
    <script>${promiseScript}</script><script>${libraryScript}</script>
  </body></html>`;
}

async function openWithSets(page) {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:markup(`window.BRVTALPublicDataPromise = Promise.resolve({payload:{data:{sets:${JSON.stringify(sets)}}}});`),
  }));
  await page.goto(harness);
  expect(await page.evaluate(() => typeof window.BRVTALPublicSetsLibrary?.render)).toBe('function');
  await expect(page.locator('html')).toHaveAttribute('data-public-sets', 'library');
}

test('Sets library renders canonical records and real Artist/Event relations from shared public data', async ({ page }) => {
  await openWithSets(page);

  const rows = page.locator('.set-library-item');
  await expect(rows).toHaveCount(4);
  await expect(page.locator('#fallback-set')).toHaveCount(0);
  await expect(page.locator('[data-sets-count]')).toHaveText('04 / 04 RECORDS');
  await expect(page.locator('.section-head > span').first()).toHaveText('SOUND LIBRARY / 04');

  const genesis = page.locator('.set-library-item', {hasText:'GENESIS — PL0N3R'});
  await expect(genesis.locator('.set-record-link')).toHaveAttribute('href', '/sets/genesis-pl0n3r');
  await expect(genesis.locator('.set-library-relations a[href="/artists/pl0n3r"]')).toContainText('ARTIST / PL0N3R');
  await expect(genesis.locator('.set-library-relations a[href="/events/genesis"]')).toContainText('EVENT / GENESIS');
  await expect(genesis.locator('.set-listen-action')).toHaveAttribute('href', 'https://youtube.com/watch?v=brvtal');
  await expect(genesis).toContainText('A live record from GENESIS.');

  const independent = page.locator('.set-library-item', {hasText:'Independent Transmission'});
  await expect(independent).toContainText('INDEPENDENT RECORD');
  await expect(independent.locator('.set-library-relations a')).toHaveCount(0);
});

test('Sets discovery controls are inserted immediately after the intro block', async ({ page }) => {
  await openWithSets(page);

  const controlsFollowIntro = await page.locator('.sets-intro').evaluate(intro =>
    intro.nextElementSibling?.classList.contains('sets-library-controls') === true
  );
  expect(controlsFollowIntro).toBe(true);
});

test('Artist and Event modes filter only structurally related Sets and expose specific relation choices', async ({ page }) => {
  await openWithSets(page);

  await page.locator('[data-sets-mode="artist"]').click();
  await expect(page.locator('[data-sets-options]')).toBeVisible();
  await expect(page.locator('.set-library-item')).toHaveCount(3);
  await expect(page.locator('[data-sets-count]')).toHaveText('03 / 04 RECORDS');
  await expect(page.locator('[data-sets-relation]', {hasText:'DNL5'})).toHaveCount(1);
  await page.locator('[data-sets-relation]', {hasText:'DNL5'}).click();
  await expect(page.locator('.set-library-item')).toHaveCount(1);
  await expect(page.locator('.set-library-item')).toContainText('DNL5 — Closing Signal');

  await page.locator('[data-sets-mode="event"]').click();
  await expect(page.locator('.set-library-item')).toHaveCount(2);
  await expect(page.locator('[data-sets-relation]', {hasText:'GENESIS'})).toHaveCount(1);
  await page.locator('[data-sets-relation]', {hasText:'GENESIS'}).click();
  await expect(page.locator('.set-library-item')).toHaveCount(1);
  await expect(page.locator('.set-library-item')).toContainText('GENESIS — PL0N3R');

  await page.locator('[data-sets-mode="latest"]').click();
  await expect(page.locator('.set-library-item')).toHaveCount(4);
  await expect(page.locator('[data-sets-options]')).toBeHidden();
});

test('Sets library is touch-safe and does not overflow a 390px viewport', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await openWithSets(page);

  const modeHeight = await page.locator('[data-sets-mode="latest"]').evaluate(node => node.getBoundingClientRect().height);
  const listenHeight = await page.locator('.set-listen-action').first().evaluate(node => node.getBoundingClientRect().height);
  expect(modeHeight).toBeGreaterThanOrEqual(44);
  expect(listenHeight).toBeGreaterThanOrEqual(44);

  await page.locator('[data-sets-mode="artist"]').click();
  const relationHeight = await page.locator('[data-sets-relation]').first().evaluate(node => node.getBoundingClientRect().height);
  expect(relationHeight).toBeGreaterThanOrEqual(44);
  const dimensions = await page.evaluate(() => ({viewport:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth}));
  expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.viewport);
});

test('late-injected Sets module hydrates real CMS durations after window load', async ({ page }) => {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:markup('window.BRVTALPublicDataPromise = Promise.resolve({payload:{data:{sets:' +
      JSON.stringify(sets) + '}}});', ''),
  }));
  await page.goto(harness);
  await page.waitForLoadState('load');
  await expect(page.locator('#fallback-set')).toContainText('STATIC FALLBACK');
  // The normal load event has already fired before this runtime module is injected.
  await page.addScriptTag({content:script});
  await expect(page.locator('#fallback-set')).toHaveCount(0);
  await expect(page.locator('[data-set-id="3"] [data-set-duration]')).toHaveText('DURATION / 1:30:32');
  await expect(page.locator('[data-set-id="2"] [data-set-duration]')).toHaveText('DURATION / 01:35');
});

test('valid empty public Sets data replaces the static fallback with a true empty archive state', async ({ page }) => {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:markup(`window.BRVTALPublicDataPromise = Promise.resolve({payload:{data:{sets:[]}}});`),
  }));
  await page.goto(harness);

  await expect(page.locator('html')).toHaveAttribute('data-public-sets', 'library');
  await expect(page.locator('#fallback-set')).toHaveCount(0);
  await expect(page.locator('.sets-library-empty')).toContainText('NO PUBLISHED SETS YET.');
  await expect(page.locator('[data-sets-count]')).toHaveText('00 / 00 RECORDS');
  await expect(page.locator('.sets-library-modes')).toBeHidden();
  await expect(page.locator('.section-head > span').first()).toHaveText('SOUND LIBRARY / 00');
});

test('missing or malformed Sets data does not erase the static fallback', async ({ page }) => {
  for (const payload of [{data:{artists:[]}}, {data:{sets:null}}, {data:{sets:{id:1}}}]) {
    await page.route(harness, route => route.fulfill({
      contentType:'text/html; charset=utf-8',
      body:markup('window.BRVTALPublicDataPromise = Promise.resolve({payload:' + JSON.stringify(payload) + '});'),
    }));
    await page.goto(harness);
    await page.waitForTimeout(350);
    await expect(page.locator('#fallback-set')).toContainText('STATIC FALLBACK');
    await expect(page.locator('.sets-library-controls')).toHaveCount(0);
    await expect(page.locator('html')).not.toHaveAttribute('data-public-sets', 'library');
    await page.unroute(harness);
  }
});

test('shared public request failure preserves the static fallback and removes discovery controls', async ({ page }) => {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:markup(`window.BRVTALPublicDataPromise = new Promise((_, reject) => setTimeout(() => reject(new Error('offline')), 25));`),
  }));
  await page.goto(harness);
  await page.waitForTimeout(350);
  await expect(page.locator('#fallback-set')).toContainText('STATIC FALLBACK');
  await expect(page.locator('.sets-library-controls')).toHaveCount(0);
  await expect(page.locator('html')).not.toHaveAttribute('data-public-sets', 'library');
});


test('known CMS durations render only genuine values across 390 and 1440 viewports', async ({ page }) => {
  for (const width of [390, 1440]) {
    await page.setViewportSize({width, height:width === 390 ? 844 : 900});
    await openWithSets(page);
    const labels = page.locator('[data-set-duration]');
    await expect(labels).toHaveCount(2);
    await expect(page.locator('[data-set-id="3"] [data-set-duration]')).toHaveText('DURATION / 1:30:32');
    await expect(page.locator('[data-set-id="2"] [data-set-duration]')).toHaveText('DURATION / 01:35');
    await expect(page.locator('[data-set-id="1"] [data-set-duration]')).toHaveCount(0);
    await expect(page.locator('[data-set-id="4"] [data-set-duration]')).toHaveCount(0);
    const dimensions = await page.evaluate(() => ({
      viewport:document.documentElement.clientWidth,
      scrollWidth:document.documentElement.scrollWidth,
    }));
    expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.viewport);
  }
});

test('an explicitly unpublished CMS Set never leaks its duration in the public library', async ({ page }) => {
  await openWithSets(page);
  await page.evaluate(() => window.BRVTALPublicSetsLibrary.render([
    {id:88, title:'EDITOR DRAFT PRIVATE', status:'draft', duration_seconds:300},
    {id:89, title:'HIDDEN PREVIEW', status:'private', duration_seconds:600},
    {id:92, title:'UNKNOWN NULL STATUS', status:null, duration_seconds:30},
    {id:90, title:'PUBLIC RECORD', status:'published', duration_seconds:125},
    {id:91, title:'LEGACY PUBLIC API', duration_seconds:3601},
  ]));
  await expect(page.locator('.set-library-item')).toHaveCount(2);
  await expect(page.locator('[data-sets-count]')).toHaveText('02 / 02 RECORDS');
  await expect(page.locator('[data-set-id="90"] [data-set-duration]')).toHaveText('DURATION / 02:05');
  await expect(page.locator('[data-set-id="91"] [data-set-duration]')).toHaveText('DURATION / 1:00:01');
  await expect(page.locator('.set-list')).not.toContainText('EDITOR DRAFT PRIVATE');
  await expect(page.locator('.set-list')).not.toContainText('HIDDEN PREVIEW');
  await expect(page.locator('.set-list')).not.toContainText('UNKNOWN NULL STATUS');
});

test('inherited publication status is never trusted, even if it claims published', async ({ page }) => {
  await openWithSets(page);
  await page.evaluate(() => {
    const inheritedDraft = Object.assign(Object.create({status:'draft'}),
      {id:301, title:'INHERITED DRAFT', duration_seconds:300});
    const inheritedPublished = Object.assign(Object.create({status:'published'}),
      {id:302, title:'INHERITED PUBLISHED', duration_seconds:600});
    const inheritedGetter = Object.assign(Object.create({get status() {
      throw new Error('Inherited lifecycle getter should not run');
    }}), {id:303, title:'INHERITED GETTER', duration_seconds:900});
    window.BRVTALPublicSetsLibrary.render([
      inheritedDraft, inheritedPublished, inheritedGetter,
      {id:304, title:'LEGACY PUBLIC API', duration_seconds:125},
      {id:305, title:'EXPLICITLY PUBLISHED', status:'published', duration_seconds:3601},
    ]);
  });
  await expect(page.locator('.set-library-item')).toHaveCount(2);
  await expect(page.locator('[data-sets-count]')).toHaveText('02 / 02 RECORDS');
  await expect(page.locator('[data-set-id="304"] [data-set-duration]')).toHaveText('DURATION / 02:05');
  await expect(page.locator('[data-set-id="305"] [data-set-duration]')).toHaveText('DURATION / 1:00:01');
  for (const forbidden of ['INHERITED DRAFT', 'INHERITED PUBLISHED', 'INHERITED GETTER']) {
    await expect(page.locator('.set-list')).not.toContainText(forbidden);
  }
});

test('invalid, unknown, malicious and hour-boundary CMS durations are never fabricated', async ({ page }) => {
  await openWithSets(page);
  const inputs = [null, undefined, 0, -1, 86401, 1.5, false, '01', '1e2', ' <img src=x onerror=alert(1)>', 59, 60, 3600, 86400, '3601'];
  const observed = await page.evaluate(items => {
    const copies = items.map((duration_seconds, id) => ({id, title:'SET '+id, slug:'set-'+id, platform:'other', duration_seconds}));
    window.BRVTALPublicSetsLibrary.render(copies);
    return Array.from(document.querySelectorAll('.set-library-item')).map(node => node.querySelector('[data-set-duration]')?.textContent || null);
  }, inputs);
  expect(observed).toEqual([null, null, null, null, null, null, null, null, null, null, 'DURATION / 00:59', 'DURATION / 01:00', 'DURATION / 1:00:00', 'DURATION / 24:00:00', 'DURATION / 1:00:01']);
  await expect(page.locator('img[src="x"]')).toHaveCount(0);
});
