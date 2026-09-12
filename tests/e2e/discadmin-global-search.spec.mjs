import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const globalSearchJs = readFileSync(join(process.cwd(), 'discadmin/global-search.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/global-search-e2e.html';

test('global search opens with Ctrl+K, groups results and routes through canonical shell', async ({ page }) => {
  await page.route('**/api/admin-search.php?q=*', route => {
    const url = new URL(route.request().url());
    const q = url.searchParams.get('q') || '';
    return route.fulfill({
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({
        ok: true,
        data: {
          query: q,
          total: 2,
          groups: [
            {
              type: 'events',
              label: 'EVENTS',
              items: [{ id: 10, type: 'events', module: 'events', title: 'Genesis', subtitle: 'Pereira · La Perla', status: 'published' }],
            },
            {
              type: 'releases',
              label: 'RELEASES',
              items: [{ id: 20, type: 'releases', module: 'releases', title: 'Genesis Release', subtitle: 'BRVTAL001 · single', status: 'draft' }],
            },
          ],
        },
      }),
    });
  });

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><meta charset="utf-8"></head><body>
      <div class="main"><div class="top"><div><div class="eyebrow">BRVTAL / DISCADMIN</div><h1>DASHBOARD</h1></div><div class="status"><i></i>ONLINE</div></div></div>
      <script>
        window.go = async section => { window.__globalSearchRoute = section; };
        window.BRVTALFeedback = { error: message => { window.__globalSearchError = message; } };
      </script>
      <script>${globalSearchJs}</script>
    </body></html>`,
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

  await dialog.getByRole('button', { name: /Genesis Pereira/ }).click();
  await expect.poll(() => page.evaluate(() => window.__globalSearchRoute)).toBe('events');
  await expect(dialog).toBeHidden();

  await page.keyboard.press('Control+K');
  await expect(dialog).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect.poll(() => page.evaluate(() => window.__globalSearchError || '')).toBe('');
});
