import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const bulkActionsJs = readFileSync(join(process.cwd(), 'discadmin/bulk-actions.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/bulk-actions-e2e.html';

async function installHarness(page, csrfToken) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><meta charset="utf-8"></head><body>
      <div class="nav"><button class="active" onclick="go('events')">EVENTS</button></div>
      <div class="main"><div class="top"><div><div class="eyebrow">BRVTAL CMS</div><h1>EVENTS</h1></div><span class="status"><i></i>ONLINE</span></div></div>
      <script>
        let csrf = '${csrfToken}';
        window.go = async section => { window.__bulkRoute = section; };
        window.BRVTALFeedback = {
          success: message => { window.__bulkSuccess = message; },
          error: message => { window.__bulkError = message; }
        };
      </script>
      <script>${bulkActionsJs}</script>
    </body></html>`,
  }));
}

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

  await installHarness(page, 'bulk-ci-token');

  page.on('dialog', dialog => dialog.accept());
  await page.goto(harnessUrl);

  const trigger = page.getByRole('button', { name: 'Open bulk actions for EVENTS' });
  await expect(trigger).toBeVisible();
  await trigger.click();

  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText('Genesis', { exact: true })).toBeVisible();
  await expect(dialog.getByText('BRVTAL Session', { exact: true })).toBeVisible();

  await dialog.getByRole('button', { name: 'SELECT PAGE' }).click();
  await expect(dialog.getByText('2 SELECTED', { exact: true })).toBeVisible();
  await dialog.getByRole('combobox', { name: 'Bulk status action' }).selectOption('published');
  await dialog.getByRole('button', { name: 'APPLY STATUS' }).click();

  await expect.poll(() => mutation?.csrf).toBe('bulk-ci-token');
  expect(mutation.body).toEqual({action:'set_status',resource:'events',status:'published',ids:[11,12]});
  await expect.poll(() => page.evaluate(() => window.__bulkRoute)).toBe('events');
  await expect(dialog).toBeHidden();
  await expect.poll(() => page.evaluate(() => window.__bulkError || '')).toBe('');
});

/** Synthetic catalog only: no production content or credentials are used. */
async function openLargeCatalog(page, count) {
  const rows = Array.from({length: count}, (_, index) => ({
    id: index + 1,
    title: `Event ${index + 1}`,
    slug: `event-${index + 1}`,
    status: 'draft',
  }));
  await page.route('**/api/index.php/events', route => route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({ok:true,data:rows}),
  }));
  await installHarness(page, 'bulk-synthetic-token');
  await page.goto(harnessUrl);
  await page.getByRole('button', { name: 'Open bulk actions for EVENTS' }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.locator('[data-bulk-row]')).toHaveCount(Math.min(50, count));
  return dialog;
}

test('bulk actions searches the full catalog beyond 500 and mutates only explicitly selected IDs', async ({ page }) => {
  let mutation = null;
  await page.route('**/api/bulk-actions.php', route => {
    mutation = route.request().postDataJSON();
    return route.fulfill({
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({ok:true,data:{matched:mutation.ids.length,changed:mutation.ids.length}}),
    });
  });
  page.on('dialog', dialog => dialog.accept());

  const dialog = await openLargeCatalog(page, 605);
  await expect(dialog.getByText('PAGE 1/13 · 1–50 OF 605 TOTAL · SEARCH ALL RECORDS')).toBeVisible();
  await dialog.getByRole('button', { name: 'NEXT' }).click();
  await expect(dialog.getByText('PAGE 2/13 · 51–100 OF 605 TOTAL · SEARCH ALL RECORDS')).toBeVisible();
  await expect(dialog.locator('[data-bulk-row]')).toHaveCount(50);
  for (let pageNumber = 2; pageNumber < 11; pageNumber += 1) {
    await dialog.getByRole('button', { name: 'NEXT' }).click();
  }
  await expect(dialog.getByText('PAGE 11/13 · 501–550 OF 605 TOTAL · SEARCH ALL RECORDS')).toBeVisible();
  await expect(dialog.getByText('Event 501', { exact:true })).toBeVisible();
  await expect(dialog.locator('[data-bulk-row]')).toHaveCount(50);

  await dialog.getByRole('searchbox', { name: 'Filter bulk action items' }).fill('event-601');
  await expect(dialog.getByText('PAGE 1/1 · 1–1 OF 1 MATCHES (605 TOTAL) · SEARCH ALL RECORDS')).toBeVisible();
  await expect(dialog.getByText('Event 601', { exact:true })).toBeVisible();
  await dialog.locator('[data-bulk-id="601"]').check();
  await expect(dialog.getByText('1 SELECTED', { exact:true })).toBeVisible();
  await dialog.getByRole('combobox', { name: 'Bulk status action' }).selectOption('draft');
  await dialog.getByRole('button', { name: 'APPLY STATUS' }).click();
  await expect.poll(() => mutation).toEqual({
    action:'set_status',resource:'events',status:'draft',ids:[601],
  });
  await expect(dialog).toBeHidden();
});

test('bulk action pagination preserves selection across pages and never allows more than 100', async ({ page }) => {
  const dialog = await openLargeCatalog(page, 260);
  await dialog.getByRole('button', { name: 'SELECT PAGE' }).click();
  await expect(dialog.getByText('50 SELECTED', { exact:true })).toBeVisible();
  await dialog.getByRole('button', { name: 'NEXT' }).click();
  await dialog.getByRole('button', { name: 'SELECT PAGE' }).click();
  await expect(dialog.getByText('100 SELECTED', { exact:true })).toBeVisible();
  await dialog.getByRole('button', { name: 'NEXT' }).click();
  await expect(dialog.getByText('PAGE 3/6 · 101–150 OF 260 TOTAL · SEARCH ALL RECORDS')).toBeVisible();
  await dialog.getByRole('button', { name: 'SELECT PAGE' }).click();
  await expect(dialog.getByText('100 SELECTED', { exact:true })).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.__bulkError)).toContain('at most 100');
  await expect(dialog.locator('[data-bulk-id="101"]')).not.toBeChecked();

  // Keyboard/mouse checkbox cannot bypass the 100-item cap.
  await page.evaluate(() => {
    const checkbox = document.querySelector('[data-bulk-id="101"]');
    checkbox.checked = true;
    checkbox.dispatchEvent(new Event('change', {bubbles:true}));
  });
  await expect(dialog.locator('[data-bulk-id="101"]')).not.toBeChecked();
  await expect(dialog.getByText('100 SELECTED', { exact:true })).toBeVisible();
  await dialog.getByRole('button', { name: 'PREVIOUS' }).click();
  await expect(dialog.locator('[data-bulk-id="51"]')).toBeChecked();
  await dialog.getByRole('button', { name: 'CLEAR PAGE' }).click();
  await expect(dialog.getByText('50 SELECTED', { exact:true })).toBeVisible();
});

test('grid preselection can reference a record past page ten without losing its selection', async ({ page }) => {
  const dialog = await openLargeCatalog(page, 605);
  await page.evaluate(() => window.BRVTALBulkActions.open('events', [605, 605, 9999]));
  await expect(dialog.getByText('1 SELECTED', { exact:true })).toBeVisible();
  await dialog.getByRole('searchbox', { name: 'Filter bulk action items' }).fill('event-605');
  await expect(dialog.getByText('Event 605', { exact:true })).toBeVisible();
  await expect(dialog.locator('[data-bulk-id="605"]')).toBeChecked();
  await dialog.getByRole('button', { name: 'CLEAR PAGE' }).click();
  await expect(dialog.getByText('0 SELECTED', { exact:true })).toBeVisible();
});

test('bulk paging keeps the footer and controls reachable on narrow mobile screens', async ({ page }) => {
  await page.setViewportSize({width:390,height:640});
  const dialog = await openLargeCatalog(page, 121);
  for (const width of [390, 320]) {
    await page.setViewportSize({width,height:568});
    await expect(dialog.getByRole('button', { name: 'NEXT' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'APPLY STATUS' })).toBeVisible();
    const layout = await page.evaluate(() => {
      const foot = document.querySelector('.brvtal-bulk-foot').getBoundingClientRect();
      return {scrollWidth:document.documentElement.scrollWidth,footBottom:foot.bottom,height:window.innerHeight};
    });
    expect(layout.scrollWidth).toBeLessThanOrEqual(width);
    expect(layout.footBottom).toBeLessThanOrEqual(layout.height + 1);
  }
  await dialog.getByRole('button', { name: 'NEXT' }).click();
  await dialog.getByRole('button', { name: 'NEXT' }).click();
  await expect(dialog.getByText('PAGE 3/3 · 101–121 OF 121 TOTAL · SEARCH ALL RECORDS')).toBeVisible();
  await expect(dialog.locator('[data-bulk-row]')).toHaveCount(21);
});
