import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const bulkActionsJs = readFileSync(join(process.cwd(), 'discadmin/bulk-actions.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/bulk-actions-e2e.html';

async function installHarness(page, csrfToken) {
  const body = `<!doctype html><html><head><meta charset="utf-8"></head><body><div class="nav"><button class="active" onclick="go('events')">EVENTS</button></div><div class="main"><div class="top"><div><div class="eyebrow">BRVTAL CMS</div><h1>EVENTS</h1></div><span class="status"><i></i>ONLINE</span></div></div><script>let csrf='${csrfToken}';window.go=async section=>{window.__bulkRoute=section};window.BRVTALFeedback={error:message=>{window.__bulkError=message}};</script><script>${bulkActionsJs}</script></body></html>`;
  await page.route(harnessUrl, route => route.fulfill({contentType:'text/html; charset=utf-8', body}));
}

async function clickBulk(dialog, name) {
  await dialog.getByRole('button', { name }).click();
}

async function expectBulkText(dialog, text) {
  await expect(dialog.getByText(text, { exact:true })).toBeVisible();
}

async function expectBulkRows(dialog, count) {
  await expect(dialog.locator('[data-bulk-row]')).toHaveCount(count);
}

function bulkCatalogPayload(resource, rows, requestUrl) {
  const url = new URL(requestUrl);
  const q = url.searchParams.get('q') || '';
  const afterId = Number(url.searchParams.get('cursor') || 0);
  const needle = q.trim().toLowerCase();
  const matching = rows.filter(row => {
    const label = row.label || row.title || row.name || ('#' + row.id);
    return `${label} ${row.slug || ''} ${row.status || ''}`.toLowerCase().includes(needle);
  });
  const items = matching
    .filter(row => Number(row.id) > afterId)
    .slice(0, 50)
    .map(row => ({
      id:Number(row.id),
      label:row.label || row.title || row.name || ('#' + row.id),
      slug:String(row.slug || ''),
      status:String(row.status || ''),
    }));
  const lastId = items.length ? items.at(-1).id : afterId;
  const hasMore = matching.some(row => Number(row.id) > lastId);
  return {
    ok:true,
    data:{
      resource,
      q,
      items,
      pagination:{
        limit:50,
        returned:items.length,
        total:matching.length,
        has_more:hasMore,
        next_cursor:hasMore ? String(lastId) : null,
        snapshot_complete:!hasMore,
        range:{after_id:afterId,last_id:lastId},
      },
    },
  };
}

async function installCatalogRoute(page, catalogs) {
  await page.route('**/api/bulk-catalog.php**', route => {
    const url = new URL(route.request().url());
    const resource = url.searchParams.get('resource') || '';
    const rows = catalogs[resource] || [];
    return route.fulfill({
      contentType:'application/json; charset=utf-8',
      body:JSON.stringify(bulkCatalogPayload(resource, rows, route.request().url())),
    });
  });
}

test('bulk actions selects multiple events, confirms, sends CSRF and refreshes canonical module', async ({ page }) => {
  let mutation = null;

  await installCatalogRoute(page, {
    events:[
      { id: 11, title: 'Genesis', slug: 'genesis', status: 'draft' },
      { id: 12, title: 'BRVTAL Session', slug: 'brvtal-session', status: 'draft' },
    ],
  });

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

  await clickBulk(dialog, 'SELECT PAGE');
  await expectBulkText(dialog, '2 SELECTED');
  await dialog.getByRole('combobox', { name: 'Bulk status action' }).selectOption('published');
  await clickBulk(dialog, 'APPLY STATUS');

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
  await installCatalogRoute(page, {events:rows});
  await installHarness(page, 'bulk-synthetic-token');
  await page.goto(harnessUrl);
  await page.getByRole('button', { name: 'Open bulk actions for EVENTS' }).click();
  const dialog = page.getByRole('dialog');
  await expectBulkRows(dialog, Math.min(50, count));
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
  await expectBulkText(dialog, 'PAGE 1 · 50 SHOWN OF 605 · IDS >0–50 · MORE AVAILABLE');
  await clickBulk(dialog, 'NEXT');
  await expectBulkText(dialog, 'PAGE 2 · 50 SHOWN OF 605 · IDS >50–100 · MORE AVAILABLE');
  await expectBulkRows(dialog, 50);
  for (let pageNumber = 2; pageNumber < 11; pageNumber += 1) {
    await clickBulk(dialog, 'NEXT');
  }
  await expectBulkText(dialog, 'PAGE 11 · 50 SHOWN OF 605 · IDS >500–550 · MORE AVAILABLE');
  await expectBulkText(dialog, 'Event 501');
  await expectBulkRows(dialog, 50);

  await dialog.getByRole('searchbox', { name: 'Search bulk action items' }).fill('event-601');
  await expectBulkText(dialog, 'PAGE 1 · 1 SHOWN OF 1 · IDS >0–601 · SNAPSHOT COMPLETE');
  await expectBulkText(dialog, 'Event 601');
  await dialog.locator('[data-bulk-id="601"]').check();
  await expectBulkText(dialog, '1 SELECTED');
  await dialog.getByRole('combobox', { name: 'Bulk status action' }).selectOption('draft');
  await clickBulk(dialog, 'APPLY STATUS');
  await expect.poll(() => mutation).toEqual({
    action:'set_status',resource:'events',status:'draft',ids:[601],
  });
  await expect(dialog).toBeHidden();
});

test('bulk action pagination preserves selection across pages and never allows more than 100', async ({ page }) => {
  const dialog = await openLargeCatalog(page, 260);
  await clickBulk(dialog, 'SELECT PAGE');
  await expectBulkText(dialog, '50 SELECTED');
  await clickBulk(dialog, 'NEXT');
  await clickBulk(dialog, 'SELECT PAGE');
  await expectBulkText(dialog, '100 SELECTED');
  await clickBulk(dialog, 'NEXT');
  await expectBulkText(dialog, 'PAGE 3 · 50 SHOWN OF 260 · IDS >100–150 · MORE AVAILABLE');
  await clickBulk(dialog, 'SELECT PAGE');
  await expectBulkText(dialog, '100 SELECTED');
  await expect.poll(() => page.evaluate(() => window.__bulkError)).toContain('at most 100');
  await expect(dialog.locator('[data-bulk-id="101"]')).not.toBeChecked();

  // Keyboard/mouse checkbox cannot bypass the 100-item cap.
  await page.evaluate(() => {
    const checkbox = document.querySelector('[data-bulk-id="101"]');
    checkbox.checked = true;
    checkbox.dispatchEvent(new Event('change', {bubbles:true}));
  });
  await expect(dialog.locator('[data-bulk-id="101"]')).not.toBeChecked();
  await expectBulkText(dialog, '100 SELECTED');
  await clickBulk(dialog, 'PREVIOUS');
  await expect(dialog.locator('[data-bulk-id="51"]')).toBeChecked();
  await clickBulk(dialog, 'CLEAR PAGE');
  await expectBulkText(dialog, '50 SELECTED');
});

test('grid preselection can reference a record past page ten without losing its selection', async ({ page }) => {
  const dialog = await openLargeCatalog(page, 605);
  await page.evaluate(() => window.BRVTALBulkActions.open('events', [605, 605]));
  await expectBulkText(dialog, '1 SELECTED');
  await dialog.getByRole('searchbox', { name: 'Search bulk action items' }).fill('event-605');
  await expectBulkText(dialog, 'Event 605');
  await expect(dialog.locator('[data-bulk-id="605"]')).toBeChecked();
  await clickBulk(dialog, 'CLEAR PAGE');
  await expectBulkText(dialog, '0 SELECTED');
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
  await clickBulk(dialog, 'NEXT');
  await clickBulk(dialog, 'NEXT');
  await expectBulkText(dialog, 'PAGE 3 · 21 SHOWN OF 121 · IDS >100–121 · SNAPSHOT COMPLETE');
  await expectBulkRows(dialog, 21);
});
