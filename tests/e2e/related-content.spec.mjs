import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const relatedJs = readFileSync(join(process.cwd(), 'js/related-content.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/related-content-e2e.html';

test('related content explorer connects artists to events, sets and releases without a separate page', async ({ page }) => {
  const data = {
    events: [
      { id: 10, title: 'NEXT SIGNAL', slug: 'next-signal', event_date: '2026-10-03 21:00:00', city: 'Pereira', venue: 'Warehouse', status: 'tickets_available', cover_image: '/next.jpg' },
    ],
    archive: {
      events: [
        { id: 11, title: 'PAST SIGNAL', slug: 'past-signal', event_date: '2026-08-07 21:00:00', city: 'Pereira', venue: 'Studio', status: 'finished', cover_image: '/past.jpg' },
      ],
    },
    artists: [
      { id: 1, name: 'PL0N3R', slug: 'pl0n3r', bio: 'HARD TECHNO', photo: '/artist-one.jpg' },
      { id: 2, name: 'DNL5', bio: 'INDUSTRIAL', photo: '/artist-two.jpg' },
    ],
    sets: [
      { id: 20, title: 'PL0N3R LIVE', slug: 'pl0n3r-live', artist_id: 1, artist_name: 'PL0N3R', event_id: 10, event_title: 'NEXT SIGNAL', platform: 'soundcloud', external_url: 'https://sound.example/pl0n3r' },
      { id: 21, title: 'DNL5 ARCHIVE', artist_id: 2, artist_name: 'DNL5', event_id: 11, event_title: 'PAST SIGNAL', platform: 'youtube', external_url: 'https://video.example/dnl5' },
    ],
    releases: [
      { id: 30, title: 'SIGNAL 001', slug: 'signal-001', release_type: 'single', release_date: '2026-09-01', spotify_url: 'https://music.example/signal', artwork: '/release.jpg' },
    ],
    relations: {
      events: {
        '10': { artists: [1], sets: [20] },
        '11': { artists: [2], sets: [21] },
      },
      artists: {
        '1': { events: [10], sets: [20], releases: [30] },
        '2': { events: [11], sets: [21], releases: [] },
      },
      sets: {
        '20': { artist: 1, event: 10 },
        '21': { artist: 2, event: 11 },
      },
      releases: {
        '30': { artists: [1] },
      },
      counts: { event_artist: 2, event_set: 2, artist_set: 2, artist_release: 1 },
    },
  };

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>
      <main><section id="sets"></section><section id="media"></section></main>
      <script>window.ScrollTrigger={refresh(){window.__relatedRefreshed=true;}};window.BRVTALPublicArchive={getData(){return ${JSON.stringify(data)};}};</script>
      <script>${relatedJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);

  await expect(page.locator('#network')).toBeVisible();
  await expect(page.locator('[data-related-summary]')).toContainText('7 PUBLIC LINKS');
  await expect(page.locator('[data-related-detail]')).toContainText('PL0N3R');
  await expect(page.locator('[data-related-detail]')).toContainText('NEXT SIGNAL');
  await expect(page.locator('[data-related-detail]')).toContainText('PL0N3R LIVE');
  await expect(page.locator('[data-related-detail]')).toContainText('SIGNAL 001');
  await expect(page.locator('a[href="/artists/pl0n3r"]')).toBeVisible();
  await expect(page.locator('a[href="/sets/pl0n3r-live"]')).toBeVisible();
  await expect(page.locator('a[href="/releases/signal-001"]')).toBeVisible();

  await page.getByRole('button', { name: /DNL5/ }).first().click();
  await expect(page.locator('[data-related-detail]')).toContainText('PAST SIGNAL');
  await expect(page.locator('[data-related-detail]')).toContainText('DNL5 ARCHIVE');
  await expect(page.locator('a[href="/events/past-signal"]')).toBeVisible();
  await expect(page.locator('[data-related-detail]')).not.toContainText('SIGNAL 001');

  await page.getByRole('tab', { name: 'EVENTS' }).click();
  await page.getByRole('button', { name: /PAST SIGNAL/ }).first().click();
  await expect(page.locator('[data-related-detail]')).toContainText('EVENT / FINISHED');
  await expect(page.locator('[data-related-detail]')).toContainText('DNL5');
  await expect(page.locator('[data-related-detail]')).toContainText('DNL5 ARCHIVE');

  await expect.poll(() => page.evaluate(() => document.documentElement.dataset.related)).toBe('live');
  await expect.poll(() => page.evaluate(() => window.__relatedRefreshed === true)).toBe(true);
});

test('related explorer keeps mobile list accessible and honors reduced motion', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><body><main><section id="media"></section></main><script>
      window.__scrolls=[];Element.prototype.scrollTo=function(options){window.__scrolls.push(options)};
      window.BRVTALPublicArchive={getData(){return {artists:[{id:1,name:'ONE',slug:'one'},{id:2,name:'TWO',slug:'two'}],events:[],relations:{artists:{'1':{},'2':{}},counts:{}}}}};
    </script><script>${relatedJs}</script></body></html>`,
  }));
  await page.goto(harnessUrl);
  await page.locator('[data-related-entity], [data-related-select]').last().click();
  await expect.poll(() => page.evaluate(() => window.__scrolls.at(-1)?.behavior)).toBe('instant');
  await expect.poll(() => page.locator('[data-related-list]').evaluate(el => getComputedStyle(el).maxHeight)).toBe('none');
});
