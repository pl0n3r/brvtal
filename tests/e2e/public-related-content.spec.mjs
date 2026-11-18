import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const relatedJs = readFileSync(join(process.cwd(), 'js/related-content.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-related-content-e2e.html';

const data = {
  events: [{
    id: 10,
    title: 'NEXT NIGHT',
    slug: 'next-night',
    status: 'upcoming',
    event_date: '2026-10-10 22:00:00',
    venue: 'WAREHOUSE',
    city: 'PEREIRA',
    cover_image: ''
  }],
  archive: { events: [{
    id: 11,
    title: 'PAST NIGHT',
    slug: 'past-night',
    status: 'finished',
    event_date: '2025-05-10 22:00:00',
    archive_year: 2025,
    venue: 'OLD WAREHOUSE',
    city: 'PEREIRA',
    cover_image: ''
  }] },
  artists: [{
    id: 1,
    name: 'PL0N3R',
    slug: 'pl0n3r',
    bio: 'INDUSTRIAL HARD TECHNO',
    photo: ''
  }],
  sets: [{
    id: 20,
    title: 'PL0N3R SET',
    slug: 'pl0n3r-set',
    artist_id: 1,
    artist_name: 'PL0N3R',
    event_id: 10,
    event_title: 'NEXT NIGHT',
    platform: 'soundcloud',
    external_url: 'https://soundcloud.com/example',
    description: 'LIVE RECORDING',
    cover_image: ''
  }],
  memories: [{
    id: 50,
    media_id: 150,
    type: 'image',
    title: 'GENESIS FLOOR',
    file_path: '/uploads/genesis-floor.jpg'
  }],
  releases: [{
    id: 30,
    title: 'SIGNAL 001',
    slug: 'signal-001',
    release_type: 'ep',
    release_date: '2026-09-01',
    description: 'BRVTAL CATALOG RELEASE',
    artwork: '',
    spotify_url: 'https://open.spotify.com/track/example'
  }],
  relations: {
    artists: { '1': { events: [10, 11], sets: [20], releases: [30], memories: [50] } },
    events: {
      '10': { artists: [1], sets: [20], memories: [50] },
      '11': { artists: [1], sets: [], memories: [] }
    },
    sets: { '20': { artist: 1, event: 10, memories: [] } },
    releases: { '30': { artists: [1], memories: [] } },
    memories: { '50': { events: [10], artists: [1], sets: [], releases: [] } },
    counts: { event_artist: 2, event_set: 1, artist_set: 1, artist_release: 1, event_memory: 1, artist_memory: 1, set_memory: 0, release_memory: 0 }
  }
};

function harness() {
  const payload = JSON.stringify(data).replace(/</g, '\\u003c');
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body><main><section id="media"></section></main><script>${relatedJs}</script><script>window.BRVTALRelatedContent.init(${payload});</script></body></html>`;
}

test.beforeEach(async ({ page }) => {
  await page.route(harnessUrl, route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: harness() }));
  await page.goto(harnessUrl);
});

test('Connected exposes Artists, Events, Sets and Releases as first-class layers', async ({ page }) => {
  await expect(page.getByRole('tab')).toHaveText(['ARTISTS', 'EVENTS', 'SETS', 'RELEASES']);
  await expect(page.getByRole('tab', { name: 'ARTISTS' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('[data-related-detail] h3')).toHaveText('PL0N3R');
  await expect(page.locator('[data-related-detail]')).toContainText('GENESIS FLOOR');
  await expect(page.getByRole('tab')).toHaveCount(4);
  await expect(page.getByRole('tab', {name:'MEMORIES'})).toHaveCount(0);

  await page.locator('[data-related-detail] [data-related-select][data-related-type="sets"][data-related-id="20"]').click();
  await expect(page.getByRole('tab', { name: 'SETS' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('[data-related-detail] h3')).toHaveText('PL0N3R SET');
  await expect(page.getByRole('link', { name: 'VIEW SET ↗' })).toHaveAttribute('href', '/sets/pl0n3r-set');
  await expect(page.getByRole('link', { name: 'OPEN PLATFORM ↗' })).toHaveAttribute('target', '_blank');

  await page.locator('[data-related-detail] [data-related-select][data-related-type="events"][data-related-id="10"]').click();
  await expect(page.getByRole('tab', { name: 'EVENTS' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('[data-related-detail] h3')).toHaveText('NEXT NIGHT');
  await expect(page.locator('[data-related-detail]')).toContainText('GENESIS FLOOR');
  await expect(page.locator('[data-related-summary]')).toContainText('2 MEMORY LINKS');
});

test('CONNECTED tabs use roving focus and the expected keyboard navigation pattern', async ({ page }) => {
  const artists = page.getByRole('tab', { name: 'ARTISTS' });
  const events = page.getByRole('tab', { name: 'EVENTS' });
  const sets = page.getByRole('tab', { name: 'SETS' });
  const releases = page.getByRole('tab', { name: 'RELEASES' });
  const panel = page.getByRole('tabpanel');

  await expect(artists).toHaveAttribute('tabindex', '0');
  await expect(events).toHaveAttribute('tabindex', '-1');
  await expect(sets).toHaveAttribute('tabindex', '-1');
  await expect(releases).toHaveAttribute('tabindex', '-1');
  await expect(artists).toHaveAttribute('aria-controls', 'related-network-panel');
  await expect(panel).toHaveAttribute('aria-labelledby', 'related-tab-artists');

  await artists.focus();
  await page.keyboard.press('ArrowRight');
  await expect(events).toBeFocused();
  await expect(events).toHaveAttribute('aria-selected', 'true');
  await expect(events).toHaveAttribute('tabindex', '0');
  await expect(artists).toHaveAttribute('tabindex', '-1');
  await expect(panel).toHaveAttribute('aria-labelledby', 'related-tab-events');
  await expect(page.locator('[data-related-detail] h3')).toHaveText('NEXT NIGHT');

  await page.keyboard.press('End');
  await expect(releases).toBeFocused();
  await expect(releases).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('[data-related-detail] h3')).toHaveText('SIGNAL 001');

  await page.keyboard.press('ArrowRight');
  await expect(artists).toBeFocused();
  await expect(artists).toHaveAttribute('aria-selected', 'true');

  await page.keyboard.press('End');
  await page.keyboard.press('Home');
  await expect(artists).toBeFocused();
  await page.keyboard.press('ArrowLeft');
  await expect(releases).toBeFocused();

  await page.keyboard.press('Tab');
  await expect(panel).toBeFocused();
  await expect(sets).not.toBeFocused();
});

test('Archived Event returns to its exact Archive context while active Event does not', async ({ page }) => {
  await page.evaluate(() => history.replaceState({}, '', '?network_type=events&network_id=11#network'));
  await page.getByRole('tab', { name: 'EVENTS' }).click();
  await page.locator('[data-related-select][data-related-type="events"][data-related-id="11"]').first().click();

  await expect(page.locator('[data-related-detail] h3')).toHaveText('PAST NIGHT');
  const archiveLink = page.getByRole('link', { name: 'VIEW IN ARCHIVE ↗' });
  await expect(archiveLink).toBeVisible();
  const archiveHref = await archiveLink.getAttribute('href');
  const archiveUrl = new URL(archiveHref, page.url());
  expect(archiveUrl.searchParams.get('archive_year')).toBe('2025');
  expect(archiveUrl.searchParams.get('archive_q')).toBe('PAST NIGHT');
  expect(archiveUrl.searchParams.has('network_type')).toBe(false);
  expect(archiveUrl.searchParams.has('network_id')).toBe(false);
  expect(archiveUrl.hash).toBe('#eventArchive');

  await page.locator('[data-related-select][data-related-type="events"][data-related-id="10"]').first().click();
  await expect(page.locator('[data-related-detail] h3')).toHaveText('NEXT NIGHT');
  await expect(page.getByRole('link', { name: 'VIEW IN ARCHIVE ↗' })).toHaveCount(0);
});

test('Release detail remains inside the graph and links back to its artist', async ({ page }) => {
  await page.locator('[data-related-detail] [data-related-select][data-related-type="releases"][data-related-id="30"]').click();
  await expect(page.getByRole('tab', { name: 'RELEASES' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('[data-related-detail] h3')).toHaveText('SIGNAL 001');
  await expect(page.getByRole('link', { name: 'VIEW RELEASE ↗' })).toHaveAttribute('href', '/releases/signal-001');
  await expect(page.getByRole('link', { name: 'LISTEN ↗' })).toHaveAttribute('target', '_blank');

  await page.locator('[data-related-detail] [data-related-select][data-related-type="artists"][data-related-id="1"]').click();
  await expect(page.getByRole('tab', { name: 'ARTISTS' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.locator('[data-related-detail] h3')).toHaveText('PL0N3R');
});
