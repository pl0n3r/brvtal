import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const appJs = readFileSync(join(process.cwd(), 'js/app.js'), 'utf8');
const archiveJs = readFileSync(join(process.cwd(), 'js/archive.js'), 'utf8');
const relatedJs = readFileSync(join(process.cwd(), 'js/related-content.js'), 'utf8');

test('Home omits blank ticket/set/social links and preserves real HTTP(S) URLs', async ({ page }) => {
  const harness = 'http://127.0.0.1:4173/public-empty-url-home.html';
  await page.route('**/api/public.php', route => route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({
      ok: true,
      data: {
        events: [{ id: 1, title: 'NO TICKET EVENT', status: 'published', ticket_url: '   ' }],
        artists: [],
        sets: [{ id: 2, title: 'SILENT SET', artist_name: 'PL0N3R', platform: 'soundcloud', external_url: '\t  ' }],
        media: [],
        settings: {
          social: {
            instagram: '   ',
            soundcloud: ' https://soundcloud.com/brvtal ',
            youtube: '',
            website: null,
          },
        },
      },
    }),
  }));
  await page.route(harness, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><body>
      <button id="menuToggle">MENU <strong>+</strong></button>
      <aside id="menuPanel" aria-hidden="true"></aside>
      <button id="soundToggle">SOUND <b>OFF</b></button>
      <span id="dynamicStatus"></span><div id="apiFallback"></div>
      <a data-social="instagram" href="/placeholder">Instagram</a>
      <a data-social="soundcloud" href="/placeholder">SoundCloud</a>
      <a data-social="youtube" href="/placeholder">YouTube</a>
      <a data-social="website" href="/placeholder">Website</a>
      <main><div class="events-track"></div><div class="set-list"></div></main>
      <script>${appJs}</script>
    </body></html>`,
  }));

  await page.goto(harness);
  await expect(page.locator('#dynamicStatus')).toHaveText('LIVE / CMS CONNECTED');
  await expect(page.locator('.event-ticket')).toHaveCount(0);
  await expect(page.locator('.set-action')).toHaveCount(0);
  await expect(page.locator('[data-social="instagram"]')).toBeHidden();
  await expect(page.locator('[data-social="youtube"]')).toBeHidden();
  await expect(page.locator('[data-social="website"]')).toBeHidden();
  await expect(page.locator('[data-social="soundcloud"]')).toBeVisible();
  await expect(page.locator('[data-social="soundcloud"]')).toHaveAttribute('href', 'https://soundcloud.com/brvtal');
});

test('Archive omits a blank ticket CTA without affecting a valid ticket', async ({ page }) => {
  const harness = 'http://127.0.0.1:4173/public-empty-url-archive.html';
  await page.route('**/api/public.php', route => route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({
      ok: true,
      data: {
        events: [
          { id: 10, title: 'NO TICKET', status: 'tickets_available', ticket_url: '   ' },
          { id: 11, title: 'TICKETS LIVE', status: 'tickets_available', ticket_url: ' https://tickets.example/live ' },
        ],
        archive: { years: [], counts: {}, events: [] },
      },
    }),
  }));
  await page.route(harness, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><body>
      <div class="events-track"></div>
      <section id="eventArchive"><div class="archive-years"></div><div class="archive-summary"></div><div class="archive-grid"></div></section>
      <script>${archiveJs}</script>
    </body></html>`,
  }));

  await page.goto(harness);
  await expect(page.locator('[data-public-event-id="10"]')).toBeVisible();
  await expect(page.locator('[data-public-event-id="10"] .event-ticket')).toHaveCount(0);
  await expect(page.locator('[data-public-event-id="11"] .event-ticket')).toHaveAttribute('href', 'https://tickets.example/live');
});

test('CONNECTED omits blank external CTAs for Sets and Releases', async ({ page }) => {
  await page.setContent('<!doctype html><html lang="en"><body><main><section id="media"></section></main></body></html>');
  await page.addScriptTag({ content: relatedJs });
  await page.evaluate(() => {
    window.BRVTALRelatedContent.init({
      events: [],
      archive: { events: [] },
      artists: [{ id: 1, name: 'PL0N3R', slug: 'pl0n3r' }],
      sets: [{ id: 20, title: 'SILENT SET', slug: 'silent-set', artist_id: 1, external_url: '   ', platform: 'soundcloud' }],
      releases: [{ id: 30, title: 'SILENT RELEASE', slug: 'silent-release', spotify_url: '\n  ' }],
      relations: {
        artists: { '1': { events: [], sets: [20], releases: [30] } },
        events: {},
        sets: { '20': { artist: 1, event: null } },
        releases: { '30': { artists: [1] } },
        counts: { event_artist: 0, event_set: 0, artist_set: 1, artist_release: 1 },
      },
    });
    window.BRVTALRelatedContent.select('sets', 20);
  });

  await expect(page.getByRole('link', { name: 'VIEW SET ↗' })).toHaveAttribute('href', '/sets/silent-set');
  await expect(page.getByRole('link', { name: 'OPEN PLATFORM ↗' })).toHaveCount(0);

  await page.evaluate(() => window.BRVTALRelatedContent.select('releases', 30));
  await expect(page.getByRole('link', { name: 'VIEW RELEASE ↗' })).toHaveAttribute('href', '/releases/silent-release');
  await expect(page.getByRole('link', { name: 'LISTEN ↗' })).toHaveCount(0);
});
