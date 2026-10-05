import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';
const fixtureQuery = 'BULK CURSOR FIXTURE';

test.skip(!baseUrl || !adminPassword, 'BRVTAL real-stack admin credentials are required');

async function login(page) {
  const response = await page.request.post(baseUrl + '/api/index.php/auth', {
    data:{email:adminEmail,password:adminPassword},
  });
  expect(response.ok(), 'Admin login failed with HTTP ' + response.status()).toBeTruthy();
  const payload = await response.json();
  expect(payload.ok).toBe(true);
  expect(payload.csrf).toBeTruthy();
  return payload;
}

async function openBulk(page) {
  await page.goto(baseUrl + '/discadmin/', {waitUntil:'domcontentloaded'});
  await page.waitForFunction(() => Boolean(window.state?.authed), null, {timeout:10_000});
  await page.evaluate(() => window.BRVTALBulkActions.open('events'));
  const dialog = page.locator('#brvtal-bulk-actions');
  await expect(dialog).toHaveClass(/open/);
  return dialog;
}

async function searchBulk(dialog, query) {
  const search = dialog.locator('.brvtal-bulk-search');
  await search.fill(query);
  await expect(dialog.locator('.brvtal-bulk-page-info')).not.toContainText('LOADING', {timeout:10_000});
  return search;
}

async function catalogOne(page, title) {
  const response = await page.request.get(
    baseUrl + '/api/bulk-catalog.php?resource=events&q=' + encodeURIComponent(title) + '&limit=50'
  );
  expect(response.status()).toBe(200);
  const payload = await response.json();
  expect(payload.ok).toBe(true);
  expect(payload.data.items).toHaveLength(1);
  return payload.data.items[0];
}

test('catalog over 500 remains searchable and page-complete without duplicates', async ({ page }) => {
  await login(page);

  let cursor = '';
  let pageCount = 0;
  const ids = [];
  do {
    const params = new URLSearchParams({resource:'events',q:fixtureQuery,limit:'50'});
    if (cursor) params.set('cursor', cursor);
    const response = await page.request.get(baseUrl + '/api/bulk-catalog.php?' + params.toString());
    expect(response.status()).toBe(200);
    const payload = await response.json();
    expect(payload.ok).toBe(true);
    expect(payload.data.resource).toBe('events');
    expect(payload.data.q).toBe(fixtureQuery);
    expect(payload.data.pagination.total).toBe(605);
    expect(payload.data.pagination.returned).toBe(payload.data.items.length);
    expect(payload.data.pagination.snapshot_complete).toBe(!payload.data.pagination.has_more);
    ids.push(...payload.data.items.map(item => Number(item.id)));
    cursor = payload.data.pagination.next_cursor || '';
    pageCount += 1;
    expect(pageCount).toBeLessThanOrEqual(13);
  } while (cursor);

  expect(pageCount).toBe(13);
  expect(ids).toHaveLength(605);
  expect(new Set(ids).size).toBe(605);
  expect([...ids].sort((a,b) => a-b)).toEqual(ids);

  const dialog = await openBulk(page);
  await searchBulk(dialog, 'BULK CURSOR FIXTURE 0601');
  await expect(dialog.locator('[data-bulk-row]')).toHaveCount(1);
  await expect(dialog.locator('.brvtal-bulk-row-title')).toHaveText('BULK CURSOR FIXTURE 0601');
  await expect(dialog.locator('.brvtal-bulk-page-info')).toContainText('1 SHOWN OF 1');
  await expect(dialog.locator('.brvtal-bulk-page-info')).toContainText('SNAPSHOT COMPLETE');
});

test('cross-page selection caps at 100 and canonical CSRF POST mutates only selected IDs', async ({ page }) => {
  await login(page);
  const dialog = await openBulk(page);

  await searchBulk(dialog, fixtureQuery);
  await expect(dialog.locator('[data-bulk-row]')).toHaveCount(50);
  await dialog.locator('.brvtal-bulk-select-all').click();
  await expect(dialog.locator('.brvtal-bulk-count')).toHaveText('50 SELECTED');
  await dialog.locator('.brvtal-bulk-next').click();
  await expect(dialog.locator('.brvtal-bulk-page-info')).toContainText('PAGE 2');
  await dialog.locator('.brvtal-bulk-select-all').click();
  await expect(dialog.locator('.brvtal-bulk-count')).toHaveText('100 SELECTED');
  await dialog.locator('.brvtal-bulk-next').click();
  await expect(dialog.locator('.brvtal-bulk-page-info')).toContainText('PAGE 3');
  await dialog.locator('.brvtal-bulk-select-all').click();
  await expect(dialog.locator('.brvtal-bulk-count')).toHaveText('100 SELECTED');

  await page.keyboard.press('Escape');
  await expect(dialog).not.toHaveClass(/open/);

  const reopened = await openBulk(page);
  await searchBulk(reopened, 'BULK CURSOR FIXTURE 0601');
  const firstBox = reopened.locator('[data-bulk-id]').first();
  const firstId = Number(await firstBox.getAttribute('data-bulk-id'));
  await firstBox.check();
  await expect(reopened.locator('.brvtal-bulk-count')).toHaveText('1 SELECTED');

  await searchBulk(reopened, 'BULK CURSOR FIXTURE 0602');
  await expect(reopened.locator('.brvtal-bulk-count')).toHaveText('1 SELECTED');
  const secondBox = reopened.locator('[data-bulk-id]').first();
  const secondId = Number(await secondBox.getAttribute('data-bulk-id'));
  await secondBox.check();
  await expect(reopened.locator('.brvtal-bulk-count')).toHaveText('2 SELECTED');

  const untouchedBefore = await catalogOne(page, 'BULK CURSOR FIXTURE 0603');
  expect(untouchedBefore.status).toBe('draft');

  await reopened.locator('.brvtal-bulk-status').selectOption('archived');
  let confirmationSeen = false;
  page.once('dialog', async dialogEvent => {
    confirmationSeen = dialogEvent.type() === 'confirm';
    await dialogEvent.accept();
  });
  const mutationPromise = page.waitForRequest(request =>
    request.method() === 'POST' && request.url().endsWith('/api/bulk-actions.php')
  );
  const responsePromise = page.waitForResponse(response =>
    response.request().method() === 'POST' && response.url().endsWith('/api/bulk-actions.php')
  );
  await reopened.locator('.brvtal-bulk-apply').click();
  const mutation = await mutationPromise;
  const response = await responsePromise;
  expect(confirmationSeen).toBe(true);
  expect(response.status()).toBe(200);
  expect(mutation.headers()['x-csrf-token']).toBeTruthy();
  const body = mutation.postDataJSON();
  expect(body).toMatchObject({action:'set_status',resource:'events',status:'archived'});
  expect(body.ids.sort((a,b) => a-b)).toEqual([firstId,secondId].sort((a,b) => a-b));

  expect((await catalogOne(page, 'BULK CURSOR FIXTURE 0601')).status).toBe('archived');
  expect((await catalogOne(page, 'BULK CURSOR FIXTURE 0602')).status).toBe('archived');
  expect((await catalogOne(page, 'BULK CURSOR FIXTURE 0603')).status).toBe('draft');
});

test('keyboard mobile aria-live and incomplete responses remain fail-closed', async ({ page }) => {
  await login(page);
  await page.setViewportSize({width:390,height:844});
  const dialog = await openBulk(page);
  const search = dialog.locator('.brvtal-bulk-search');
  await expect(search).toBeFocused();
  await expect(dialog.locator('.brvtal-bulk-list')).toHaveAttribute('aria-live','polite');
  await expect(dialog.locator('.brvtal-bulk-page-info')).toHaveAttribute('aria-live','polite');
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveAttribute('aria-hidden','true');

  await page.route('**/api/bulk-catalog.php**', route => {
    const items = Array.from({length:50}, (_, index) => ({
      id:index + 1,
      label:'TRUNCATED ' + (index + 1),
      slug:'truncated-' + (index + 1),
      status:'draft',
    }));
    return route.fulfill({
      status:200,
      contentType:'application/json; charset=utf-8',
      body:JSON.stringify({
        ok:true,
        data:{
          resource:'events',
          q:'',
          items,
          pagination:{
            limit:50, returned:50, total:500,
            has_more:false, next_cursor:null, snapshot_complete:true,
            range:{after_id:0,last_id:50},
          },
        },
      }),
    });
  });

  await page.evaluate(() => window.BRVTALBulkActions.open('events'));
  await expect(dialog.locator('.brvtal-bulk-list')).toContainText('BULK ACTIONS UNAVAILABLE');
  await expect(dialog.locator('.brvtal-bulk-page-info')).toContainText('CATALOG UNAVAILABLE');
  await expect(dialog.locator('.brvtal-bulk-apply')).toBeDisabled();
});
