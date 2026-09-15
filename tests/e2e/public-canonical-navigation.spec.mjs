import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const enhancement = readFileSync(join(process.cwd(), 'js/public-canonical-navigation.js'), 'utf8');

test('Home discovery links Events, Artists and Sets to canonical BRVTAL pages', async ({ page }) => {
  await page.setContent(`<!doctype html><html><body>
    <div class="events-track">
      <article class="event-card" data-public-event-id="10">
        <div class="event-info"><h3>NEXT NIGHT</h3><a class="event-ticket" href="https://tickets.example/next">TICKETS</a></div>
      </article>
    </div>
    <div class="artist-list">
      <a class="artist" href="https://instagram.example/artist"><strong>ARTIST ONE</strong></a>
    </div>
    <div class="set-list">
      <article class="set-item"><div class="set-main"><h4>LIVE SET</h4></div><a class="set-action" href="https://soundcloud.example/live-set">LISTEN</a></article>
    </div>
  </body></html>`);

  await page.addScriptTag({ content: enhancement });
  await page.evaluate(() => {
    window.dispatchEvent(new CustomEvent('brvtal:public-data', { detail: {
      events: [{ id: 10, slug: 'next-night', title: 'NEXT NIGHT' }],
      artists: [{ id: 20, slug: 'artist-one', name: 'ARTIST ONE', instagram_url: 'https://instagram.example/artist' }],
      sets: [{ id: 30, slug: 'live-set', title: 'LIVE SET', external_url: 'https://soundcloud.example/live-set' }],
    }}));
  });

  await expect(page.locator('.event-info h3 a[data-public-canonical]')).toHaveAttribute('href', '/events/next-night');
  await expect(page.locator('.event-ticket')).toHaveAttribute('href', 'https://tickets.example/next');

  await expect(page.locator('.artist[data-public-canonical]')).toHaveAttribute('href', '/artists/artist-one');
  await expect(page.locator('.artist')).toHaveAttribute('aria-label', 'View artist: ARTIST ONE');

  await expect(page.locator('.set-main h4 a[data-public-canonical]')).toHaveAttribute('href', '/sets/live-set');
  await expect(page.locator('.set-action')).toHaveAttribute('href', 'https://soundcloud.example/live-set');
});

test('invalid or missing slugs do not create broken canonical links', async ({ page }) => {
  await page.setContent(`<!doctype html><html><body>
    <div class="events-track"><article class="event-card"><div class="event-info"><h3>EVENT</h3></div></article></div>
    <div class="artist-list"><a class="artist" href="https://example.com/profile"><strong>ARTIST</strong></a></div>
    <div class="set-list"><article class="set-item"><div class="set-main"><h4>SET</h4></div></article></div>
  </body></html>`);

  await page.addScriptTag({ content: enhancement });
  await page.evaluate(() => {
    window.BRVTALPublicCanonicalNavigation.apply({
      events: [{ slug: '../bad' }],
      artists: [{ slug: '' }],
      sets: [{ slug: 'BAD SLUG' }],
    });
  });

  await expect(page.locator('[data-public-canonical]')).toHaveCount(0);
  await expect(page.locator('.artist')).toHaveAttribute('href', 'https://example.com/profile');
});
