import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const bulkActionsJs = readFileSync(join(process.cwd(), 'discadmin/bulk-actions.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/bulk-actions-e2e.html';

test('bulk actions selects multiple events, confirms, sends CSRF and refreshes canonical module', async ({ page }) => {
  let mutation = null;

  await page.route('**/api/index.php/events', route => route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({
      ok: true,
      data: [
        { id: 11, title: 'Genesis', slug: 'genesis', status: 'draft' },
        { id: 12, title: 'BRVTAL Session', slug: 'brvtal-session', status: 'draft' },
      ],
    }),
  }));

  await page.route('**/api/bulk-actions.php', async route => {
    const request = route.request();
    mutation = {
      csrf: request.headers()['x-csrf-token'] || '',
      body: request.postDataJSON(),
    };
    return route.fulfill({
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({ok:true,data:{resource:'events',status:'published',ids:[11,12],matched:2,changed:2}}),
    });
  });

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><meta charset="utf-8"></head><body>
      <div class="nav"><button class="active" onclick="go('events')">EVENTS</button></div>
      <div class="main"><div class="top"><div><div class="eyebrow">BRVTAL CMS</div><h1>EVENTS</h1></div><span class="status"><i></i>ONLINE</span></div></div>
      <script>
        let csrf = 'bulk-ci-token';
        window.go = async section => { window.__bulkRoute = section; };
        window.BRVTALFeedback = {
          success: message => { window.__bulkSuccess = message; },
          error: message => { window.__bulkError = message; }
        };
      </script>
      <script>${bulkActionsJs}</script>
    </body></html>`,
  }));

  page.on('dialog', dialog => dialog.accept());
  await page.goto(harnessUrl);

  const trigger = page.getByRole('button', { name: 'Open bulk actions for EVENTS' });
  await expect(trigger).toBeVisible();
  await trigger.click();

  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText('Genesis', { exact: true })).toBeVisible();
  await expect(dialog.getByText('BRVTAL Session', { exact: true })).toBeVisible();

  await dialog.getByRole('button', { name: 'SELECT ALL' }).click();
  await expect(dialog.getByText('2 SELECTED', { exact: true })).toBeVisible();
  await dialog.getByRole('combobox', { name: 'Bulk status action' }).selectOption('published');
  await dialog.getByRole('button', { name: 'APPLY STATUS' }).click();

  await expect.poll(() => mutation?.csrf).toBe('bulk-ci-token');
  expect(mutation.body).toEqual({action:'set_status',resource:'events',status:'published',ids:[11,12]});
  await expect.poll(() => page.evaluate(() => window.__bulkRoute)).toBe('events');
  await expect(dialog).toBeHidden();
  await expect.poll(() => page.evaluate(() => window.__bulkError || '')).toBe('');
});
