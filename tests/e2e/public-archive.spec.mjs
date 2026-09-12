import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const archiveJs = readFileSync(join(process.cwd(), 'js/archive.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-archive-e2e.html';

test('public archive separates active lifecycle from historical nights and filters by year', async ({ page }) => {
  await page.route('**/api/public*', route => route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({
      ok: true,
      data: {
        events: [
          { id: 10, title: 'Next Night', event_date: '2026-10-03 21:00:00', city: 'Pereira', venue: 'Warehouse', description: 'BRVTAL', status: 'published', cover_image: '/next.jpg', ticket_url: 'https://tickets.example/next' },
          { id: 11, title: 'Tickets Live', event_date: '2026-10-24 21:00:00', city: 'Bogota', venue: 'Club', description: 'BRVTAL', status: 'tickets_available', cover_image: '/tickets.jpg', ticket_url: 'https://tickets.example/live' },
        ],
        archive: {
          years: [2026, 2025],
          counts: { events: 2, sets: 1, media: 9, releases: 3 },
          events: [
            { id: 20, title: 'Session Five', event_date: '2026-08-07 21:00:00', archive_year: 2026, city: 'Pereira', venue: 'Studio', status: 'finished', cover_image: '/archive-2026.jpg', ticket_url: null, lineup: [{name:'PERKS'},{name:'DNL5'}], related_sets: [{id:90,title:'Session Five Set'}] },
            { id: 21, title: 'Old Signal', event_date: '2025-05-01 21:00:00', archive_year: 2025, city: 'Pereira', venue: 'Bunker', status: 'archived', cover_image: '/archive-2025.jpg', ticket_url: null, lineup: [], related_sets: [] },
          ],
        },
      },
    }),
  }));

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>
      <div class="events-track"><article class="event-card"><div class="event-info"><span class="event-status">ARCHIVE</span></div></article></div>
      <div class="event-archive" id="eventArchive">
        <div class="archive-summary"></div>
        <div class="archive-years"></div>
        <div class="archive-grid"></div>
      </div>
      <script>window.ScrollTrigger={refresh(){window.__archiveRefreshed=true;}};</script>
      <script>${archiveJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);

  await expect(page.locator('[data-public-event-id="10"]')).toBeVisible();
  await expect(page.locator('[data-public-event-id="10"] .event-status')).toHaveText('NEXT EXPERIENCE');
  await expect(page.locator('[data-public-event-id="11"] .event-status')).toHaveText('TICKETS AVAILABLE');
  await expect(page.locator('.events-track a[href="https://tickets.example/live"]')).toBeVisible();

  await expect(page.locator('[data-archive-id="20"]')).toBeVisible();
  await expect(page.locator('[data-archive-id="20"]')).toContainText('Session Five');
  await expect(page.locator('[data-archive-id="20"]')).toContainText('2 ARTISTS / 1 SET');
  await expect(page.locator('#eventArchive a')).toHaveCount(0);
  await expect(page.locator('.archive-summary')).toHaveText('2 NIGHTS / 1 RELATED SETS / 9 VISUAL RECORDS');

  await page.getByRole('button', { name: '2025' }).click();
  await expect(page.locator('[data-archive-id="20"]')).toBeHidden();
  await expect(page.locator('[data-archive-id="21"]')).toBeVisible();

  await page.getByRole('button', { name: 'ALL' }).click();
  await expect(page.locator('[data-archive-id="20"]')).toBeVisible();
  await expect(page.locator('[data-archive-id="21"]')).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.__archiveRefreshed === true)).toBe(true);
});
