import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = [
  'css/style.css',
  'css/public-roster.css',
].map(path => readFileSync(join(process.cwd(), path), 'utf8')).join('\n');
const rosterScript = readFileSync(join(process.cwd(), 'js/public-roster.js'), 'utf8');

const markup = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>
<section class="artists scene" id="artists">
  <div class="section-head"><span class="mono">BRVTAL ROSTER / 03</span><h2>ROSTER</h2><span class="mono">CORE / ALUMNI / ARTISTS</span></div>
  <div class="artist-list">
    <section class="roster-group roster-group--active" data-roster-group="active">
      <div class="roster-group-head mono"><span>CORE / ACTIVE</span><b>01</b></div>
      <a class="artist artist--active" href="/artists/pl0n3r"><span>01</span><strong>PL0N3R</strong><i>BRVTAL / ACTIVE · SINCE 2026</i></a>
    </section>
    <section class="roster-group roster-group--alumni" data-roster-group="alumni">
      <div class="roster-group-head mono"><span>ALUMNI / ARCHIVE</span><b>01</b></div>
      <a class="artist artist--alumni" href="/artists/archive-signal"><span>02</span><strong>ARCHIVE SIGNAL</strong><i>BRVTAL / ALUMNI · 2025—2026</i></a>
    </section>
    <section class="roster-group roster-group--network" data-roster-group="network">
      <div class="roster-group-head mono"><span>ARTISTS / COLLABORATORS</span><b>01</b></div>
      <a class="artist artist--network" href="/artists/long-collaborator-name"><span>03</span><strong>VERY LONG COLLABORATOR NAME FOR MOBILE</strong><i>ARTIST / COLLABORATOR</i></a>
    </section>
  </div>
  <div class="artist-preview"></div><div class="artist-crosshair"></div>
</section>
</body></html>`;

const runtimeMarkup = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>
<section class="artists scene" id="artists">
  <div class="section-head"><span class="mono">ARTISTS / STATIC</span><h2>ARTISTS</h2><span class="mono">STATIC FALLBACK</span></div>
  <div class="artist-list"><a class="artist" href="#artists"><span>01</span><strong>STATIC FALLBACK ARTIST</strong><i>STATIC</i></a></div>
  <div class="artist-preview"><img src="/assets/fallback.jpg" alt=""></div><div class="artist-crosshair"></div>
</section>
</body></html>`;

test('Roster groups membership states and keeps canonical profile links', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.setContent(markup);
  await expect(page.locator('[data-roster-group="active"]')).toContainText('CORE / ACTIVE');
  await expect(page.locator('[data-roster-group="alumni"]')).toContainText('ALUMNI / ARCHIVE');
  await expect(page.locator('[data-roster-group="network"]')).toContainText('ARTISTS / COLLABORATORS');
  await expect(page.locator('.artist--active')).toHaveAttribute('href', '/artists/pl0n3r');
});

test('Roster stays readable and touch-safe on mobile without horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(markup);

  const dimensions = await page.locator('.artist').evaluateAll(nodes => nodes.map(node => ({
    height: node.getBoundingClientRect().height,
    right: node.getBoundingClientRect().right,
    left: node.getBoundingClientRect().left,
  })));
  for (const item of dimensions) {
    expect(item.height).toBeGreaterThanOrEqual(44);
    expect(item.left).toBeGreaterThanOrEqual(0);
    expect(item.right).toBeLessThanOrEqual(390.5);
  }

  const pageMetrics = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(pageMetrics.scrollWidth).toBeLessThanOrEqual(pageMetrics.viewport);
  await expect(page.locator('.artist--network strong')).toBeVisible();
  await expect(page.locator('.artist-preview')).toBeHidden();
});

test('Roster runtime renders the shared public payload with lifecycle ordering and canonical links', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.setContent(runtimeMarkup);
  await page.evaluate(() => {
    window.__rosterRendered = null;
    window.addEventListener('brvtal:roster-rendered', event => { window.__rosterRendered = event.detail; }, { once: true });
    window.BRVTALPublicDataPromise = Promise.resolve({
      payload: {
        data: {
          artists: [
            {name:'NETWORK <SCRIPT> & "RAVE" \'NIGHT\'', slug:'network-artist', collective_status:'none', sort_order:1, bio:'SHOULD NOT LEAK', website_url:'https://example.com'},
            {name:'ACTIVE SECOND', slug:'active-second', collective_status:'active', collective_order:8, sort_order:1, collective_joined_at:'2026-08-01', photo:'/uploads/active-second.jpg'},
            {name:'ALUMNI ONE', slug:'alumni-one', collective_status:'alumni', collective_order:1, collective_joined_at:'2024-01-01', collective_left_at:'2025-12-31'},
            {name:'ACTIVE FIRST', slug:'active<&"first', collective_status:'active', collective_order:2, sort_order:9, collective_joined_at:'2025-02-03', photo:'/uploads/active-"first"&<cut>.jpg'},
          ],
        },
      },
    });
  });
  await page.addScriptTag({ content: rosterScript });
  expect(await page.evaluate(() => typeof window.BRVTALPublicRoster?.render)).toBe('function');
  await page.evaluate(() => window.dispatchEvent(new Event('load')));

  await expect(page.locator('html')).toHaveAttribute('data-public-roster', 'connected');
  await expect(page.locator('.section-head > span').first()).toHaveText('BRVTAL ROSTER / 04');
  await expect(page.locator('[data-roster-group="active"] .artist strong')).toHaveText(['ACTIVE FIRST', 'ACTIVE SECOND']);
  await expect(page.locator('[data-roster-group="alumni"] .artist strong')).toHaveText(['ALUMNI ONE']);
  await expect(page.locator('[data-roster-group="network"] .artist strong')).toHaveText(['NETWORK <SCRIPT> & "RAVE" \'NIGHT\'']);
  await expect(page.locator('.artist-list script')).toHaveCount(0);
  await expect(page.locator('[data-roster-group="active"] .artist').first()).toHaveAttribute('href', '/artists/active%3C%26%22first');
  await expect(page.locator('[data-roster-group="active"] .artist').first()).toHaveAttribute('data-image', '/uploads/active-"first"&<cut>.jpg');
  await expect(page.locator('[data-roster-group="network"] .artist')).not.toHaveAttribute('href', 'https://example.com');
  await expect(page.locator('.artist-list')).not.toContainText('SHOULD NOT LEAK');
  await expect(page.locator('.artist-preview img')).toHaveAttribute('src', '/uploads/active-"first"&<cut>.jpg');
  expect(await page.evaluate(() => window.__rosterRendered)).toEqual({ count: 4 });
});

test('Roster runtime preserves the static fallback when the shared request fails', async ({ page }) => {
  await page.setContent(runtimeMarkup);
  await page.evaluate(() => {
    window.BRVTALPublicDataPromise = {
      then(_resolve, reject) { reject(new Error('offline')); },
    };
  });
  await page.addScriptTag({ content: rosterScript });
  await page.evaluate(() => window.dispatchEvent(new Event('load')));

  await page.waitForTimeout(260);
  await expect(page.locator('.artist-list')).toContainText('STATIC FALLBACK ARTIST');
  await expect(page.locator('html')).not.toHaveAttribute('data-public-roster', 'connected');
});
