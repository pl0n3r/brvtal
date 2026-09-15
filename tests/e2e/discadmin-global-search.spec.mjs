import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const recordNavigationJs = readFileSync(join(process.cwd(), 'discadmin/content-core-nav.js'), 'utf8');
const globalSearchJs = readFileSync(join(process.cwd(), 'discadmin/global-search.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/global-search-e2e.html';

function harnessHtml() {
  return `<!doctype html><html><head><meta charset="utf-8"></head><body>
    <div class="main"><div class="top"><div><div class="eyebrow">BRVTAL / DISCADMIN</div><h1>DASHBOARD</h1></div><div class="status"><i></i>ONLINE</div></div></div>
    <script>
      window.go = async section => { window.__globalSearchRoute = section; };
      window.BRVTALContentCore = { openEvent: async id => { window.__globalSearchRecordId = Number(id); } };
      window.BRVTALFeedback = { error: message => { window.__globalSearchError = message; } };
    </script>
    <script>${recordNavigationJs}</script>
    <script>${globalSearchJs}</script>
  </body></html>`;
}

test('global search opens with Ctrl+K, groups results and opens the selected record', async ({ page }) => {
  await page.route('**/api/admin-search.php*', route => {
    const url = new URL(route.request().url());
    const q = url.searchParams.get('q') || '';
    return route.fulfill({
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({
        ok: true,
        data: {
          query: q,
          total: 2,
          page_size: 6,
          groups: [
            {
              type: 'events',
              label: 'EVENTS',
              total: 1,
              offset: 0,
              has_more: false,
              items: [{ id: 10, type: 'events', module: 'events', title: 'Genesis', subtitle: 'Pereira · La Perla', status: 'published' }],
            },
            {
              type: 'releases',
              label: 'RELEASES',
              total: 1,
              offset: 0,
              has_more: false,
              items: [{ id: 20, type: 'releases', module: 'releases', title: 'Genesis Release', subtitle: 'BRVTAL001 · single', status: 'draft' }],
            },
          ],
        },
      }),
    });
  });

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: harnessHtml(),
  }));

  await page.goto(harnessUrl);
  await expect(page.getByRole('button', { name: 'Open global DISCADMIN search' })).toBeVisible();

  await page.keyboard.press('Control+K');
  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();

  const input = page.getByRole('searchbox', { name: 'Search all DISCADMIN content' });
  await input.fill('gen');
  await expect(dialog.getByText('EVENTS', { exact: true })).toBeVisible();
  await expect(dialog.getByText('RELEASES', { exact: true })).toBeVisible();
  await expect(dialog.getByText('Genesis', { exact: true })).toBeVisible();
  await expect(dialog.getByText('Pereira · La Perla', { exact: true })).toBeVisible();
  await expect(dialog.getByText('1 / 1').first()).toBeVisible();

  await dialog.getByRole('button', { name: /Genesis Pereira/ }).click();
  await expect.poll(() => page.evaluate(() => window.__globalSearchRoute)).toBe('events');
  await expect.poll(() => page.evaluate(() => window.__globalSearchRecordId)).toBe(10);
  await expect(dialog).toBeHidden();

  await page.keyboard.press('Control+K');
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect.poll(() => page.evaluate(() => window.__globalSearchError || '')).toBe('');
});

test('global search exposes exact totals and loads matches beyond the first six', async ({ page }) => {
  const initialItems = Array.from({ length: 6 }, (_, index) => ({
    id: 20 - index,
    type: 'events',
    module: 'events',
    title: `Match ${20 - index}`,
    subtitle: `Event #${20 - index}`,
    status: 'published',
  }));
  const olderItems = [
    { id: 14, type: 'events', module: 'events', title: 'Match 14', subtitle: 'Event #14', status: 'draft' },
    { id: 13, type: 'events', module: 'events', title: 'Match 13', subtitle: 'Event #13', status: 'draft' },
  ];

  await page.route('**/api/admin-search.php*', route => {
    const url = new URL(route.request().url());
    const q = url.searchParams.get('q') || '';
    const type = url.searchParams.get('type') || '';
    const offset = Number(url.searchParams.get('offset') || 0);
    const paged = type === 'events' && offset === 6;
    return route.fulfill({
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({
        ok: true,
        data: {
          query: q,
          total: 8,
          page_size: 6,
          groups: [{
            type: 'events',
            label: 'EVENTS',
            total: 8,
            offset: paged ? 6 : 0,
            has_more: !paged,
            items: paged ? olderItems : initialItems,
          }],
        },
      }),
    });
  });

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: harnessHtml(),
  }));

  await page.goto(harnessUrl);
  await page.keyboard.press('Control+K');
  const dialog = page.getByRole('dialog');
  const input = page.getByRole('searchbox', { name: 'Search all DISCADMIN content' });
  await input.fill('match');

  await expect(dialog.getByText('6 / 8', { exact: true })).toBeVisible();
  const more = dialog.getByRole('button', { name: 'LOAD MORE · 2 MORE' });
  await expect(more).toBeVisible();
  await more.click();

  await expect(dialog.getByText('Match 14', { exact: true })).toBeVisible();
  await expect(dialog.getByText('Match 13', { exact: true })).toBeVisible();
  await expect(dialog.getByText('8 / 8', { exact: true })).toBeVisible();
  await expect(dialog.locator('[data-search-more]')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => window.__globalSearchError || '')).toBe('');
});
