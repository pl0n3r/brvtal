import { test, expect } from '@playwright/test';
import { openDiscadminModule } from './helpers/discadmin.mjs';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';
const receiverUrl = process.env.BRVTAL_INDEXNOW_STUB_ORIGIN || 'http://127.0.0.1:4175';

test.skip(!baseUrl || !adminPassword, 'BRVTAL real-stack URL and admin credentials are required');

async function login(page) {
  const response = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data: {email:adminEmail,password:adminPassword},
  });
  expect(response.ok(), `Admin login failed with HTTP ${response.status()}`).toBeTruthy();
  const payload = await response.json();
  expect(payload.ok).toBe(true);
  expect(payload.csrf).toBeTruthy();
  return payload;
}

async function settings(page) {
  const response = await page.request.get(`${baseUrl}/api/index.php/settings`);
  expect(response.ok()).toBeTruthy();
  const payload = await response.json();
  return payload.data || [];
}

async function captured(page) {
  const response = await page.request.get(`${receiverUrl}/captured`);
  expect(response.ok()).toBeTruthy();
  const payload = await response.json();
  return payload.payloads || [];
}

test('IndexNow is configurable in Settings and submits real public mutations', async ({ page }, testInfo) => {
  const auth = await login(page);
  const key = 'BRVTAL-IndexNow-2026-E2E';
  const keyLocation = '/indexnow-ci-key.txt';
  const endpoint = 'https://www.bing.com/indexnow';
  const runKey = `${Date.now().toString(36)}-${testInfo.workerIndex}`;
  const oldSlug = `indexnow-artist-${runKey}`;
  const newSlug = `${oldSlug}-renamed`;
  const disabledSlug = `indexnow-disabled-${runKey}`;
  const bulkSlug = `indexnow-bulk-${runKey}`;
  let firstArtistId = 0;
  let bulkArtistId = 0;
  let disabledArtistId = 0;

  await page.request.delete(`${receiverUrl}/captured`);

  const invalid = await page.request.post(`${baseUrl}/api/index.php/settings`, {
    headers: {'X-CSRF-Token':auth.csrf},
    data: {
      setting_key:'indexnow',
      setting_value:JSON.stringify({enabled:true,key:'bad_key'}),
      is_json:1,
    },
  });
  expect(invalid.status()).toBe(422);
  expect((await invalid.json()).error).toBe('INDEXNOW_KEY_INVALID');

  await openDiscadminModule(page, baseUrl, 'settings', 'settings-v2-root');
  await page.getByTestId('settings-tab-seo').click();
  await page.getByTestId('indexnow-enabled').selectOption('1');
  await page.getByTestId('settings-field-indexnow_key').fill(key);
  await page.getByTestId('settings-field-indexnow_key_location').fill(keyLocation);
  await page.getByTestId('indexnow-endpoint').selectOption(endpoint);
  await page.getByTestId('indexnow-save').click();

  await expect.poll(async () => {
    const rows = await settings(page);
    const row = rows.find(item => item.setting_key === 'indexnow');
    if (!row) return null;
    try { return JSON.parse(String(row.setting_value || '{}')); }
    catch { return null; }
  }, {timeout:10_000}).toEqual({
    enabled:true,
    key,
    key_location:keyLocation,
    endpoint,
  });

  const keyResponse = await page.request.get(`${baseUrl}/indexnow-key.php`);
  expect(keyResponse.status()).toBe(200);
  expect((await keyResponse.text()).trim()).toBe(key);

  try {
    const created = await page.request.post(`${baseUrl}/api/index.php/artists`, {
      headers: {'X-CSRF-Token':auth.csrf},
      data: {name:`INDEXNOW ARTIST ${runKey}`,slug:oldSlug,status:'published',sort_order:99},
    });
    expect(created.status()).toBe(201);
    firstArtistId = Number((await created.json()).id || 0);
    expect(firstArtistId).toBeGreaterThan(0);

    await expect.poll(async () => {
      const payloads = await captured(page);
      return payloads.some(payload =>
        payload.key === key
        && payload.keyLocation === `${baseUrl}${keyLocation}`
        && Array.isArray(payload.urlList)
        && payload.urlList.includes(`${baseUrl}/artists/${oldSlug}`)
        && payload.urlList.includes(`${baseUrl}/`)
      );
    }, {timeout:10_000}).toBe(true);

    const renamed = await page.request.put(`${baseUrl}/api/index.php/artists/${firstArtistId}`, {
      headers: {'X-CSRF-Token':auth.csrf},
      data: {slug:newSlug},
    });
    expect(renamed.ok()).toBeTruthy();

    await expect.poll(async () => {
      const payloads = await captured(page);
      return payloads.some(payload =>
        Array.isArray(payload.urlList)
        && payload.urlList.includes(`${baseUrl}/artists/${oldSlug}`)
        && payload.urlList.includes(`${baseUrl}/artists/${newSlug}`)
      );
    }, {timeout:10_000}).toBe(true);

    const removed = await page.request.delete(`${baseUrl}/api/index.php/artists/${firstArtistId}`, {
      headers: {'X-CSRF-Token':auth.csrf},
    });
    expect(removed.ok()).toBeTruthy();
    firstArtistId = 0;

    await expect.poll(async () => {
      const payloads = await captured(page);
      return payloads.filter(payload =>
        Array.isArray(payload.urlList)
        && payload.urlList.includes(`${baseUrl}/artists/${newSlug}`)
      ).length;
    }, {timeout:10_000}).toBeGreaterThanOrEqual(2);

    const bulkCreate = await page.request.post(`${baseUrl}/api/index.php/artists`, {
      headers: {'X-CSRF-Token':auth.csrf},
      data: {name:`INDEXNOW BULK ${runKey}`,slug:bulkSlug,status:'published',sort_order:101},
    });
    expect(bulkCreate.status()).toBe(201);
    bulkArtistId = Number((await bulkCreate.json()).id || 0);
    expect(bulkArtistId).toBeGreaterThan(0);

    await expect.poll(async () => {
      const payloads = await captured(page);
      return payloads.filter(payload =>
        Array.isArray(payload.urlList)
        && payload.urlList.includes(`${baseUrl}/artists/${bulkSlug}`)
      ).length;
    }, {timeout:10_000}).toBeGreaterThanOrEqual(1);

    const beforeBulkUnpublish = (await captured(page)).filter(payload =>
      Array.isArray(payload.urlList)
      && payload.urlList.includes(`${baseUrl}/artists/${bulkSlug}`)
    ).length;

    const bulkUnpublish = await page.request.post(`${baseUrl}/api/bulk-actions.php`, {
      headers: {'X-CSRF-Token':auth.csrf},
      data: {
        action:'set_status',
        resource:'artists',
        status:'draft',
        ids:[bulkArtistId],
      },
    });
    expect(bulkUnpublish.ok()).toBeTruthy();

    await expect.poll(async () => {
      const payloads = await captured(page);
      return payloads.filter(payload =>
        Array.isArray(payload.urlList)
        && payload.urlList.includes(`${baseUrl}/artists/${bulkSlug}`)
      ).length;
    }, {timeout:10_000}).toBeGreaterThan(beforeBulkUnpublish);

    const bulkCleanup = await page.request.delete(`${baseUrl}/api/index.php/artists/${bulkArtistId}`, {
      headers: {'X-CSRF-Token':auth.csrf},
    });
    expect(bulkCleanup.ok()).toBeTruthy();
    bulkArtistId = 0;

    const beforeDisable = (await captured(page)).length;
    await page.getByTestId('indexnow-enabled').selectOption('0');
    await page.getByTestId('indexnow-save').click();
    await expect.poll(async () => {
      const rows = await settings(page);
      const row = rows.find(item => item.setting_key === 'indexnow');
      if (!row) return null;
      return JSON.parse(String(row.setting_value || '{}')).enabled;
    }, {timeout:10_000}).toBe(false);

    const disabledCreate = await page.request.post(`${baseUrl}/api/index.php/artists`, {
      headers: {'X-CSRF-Token':auth.csrf},
      data: {name:`INDEXNOW DISABLED ${runKey}`,slug:disabledSlug,status:'published',sort_order:100},
    });
    expect(disabledCreate.status()).toBe(201);
    disabledArtistId = Number((await disabledCreate.json()).id || 0);

    await page.waitForTimeout(250);
    expect((await captured(page)).length).toBe(beforeDisable);

    const disabledKeyResponse = await page.request.get(`${baseUrl}/indexnow-key.php`);
    expect(disabledKeyResponse.status()).toBe(404);
  } finally {
    if (firstArtistId > 0) {
      await page.request.delete(`${baseUrl}/api/index.php/artists/${firstArtistId}`, {
        headers: {'X-CSRF-Token':auth.csrf},
      });
    }
    if (bulkArtistId > 0) {
      await page.request.delete(`${baseUrl}/api/index.php/artists/${bulkArtistId}`, {
        headers: {'X-CSRF-Token':auth.csrf},
      });
    }
    if (disabledArtistId > 0) {
      await page.request.delete(`${baseUrl}/api/index.php/artists/${disabledArtistId}`, {
        headers: {'X-CSRF-Token':auth.csrf},
      });
    }
  }
});
