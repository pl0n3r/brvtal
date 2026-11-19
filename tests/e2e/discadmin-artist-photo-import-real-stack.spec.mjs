import {test, expect} from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const email = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const password = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';
const fixture = 'https://fixture.brvtal.test';

test.skip(!baseUrl || !password, 'Disposable BRVTAL real stack is required');

async function login(page) {
  const response = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data:{email,password}
  });
  expect(response.status()).toBe(200);
  const auth = await response.json();
  expect(auth.ok).toBe(true);
  expect(auth.csrf).toBeTruthy();
  return auth;
}

test('AC-01: authenticated artist editor imports real HTTPS fixture into Media and public artist; failures preserve old photo', async ({page}, testInfo) => {
  const auth = await login(page);
  const key = `${Date.now().toString(36)}-${testInfo.workerIndex}`;
  const slug = `ci-photo-import-${key}`;
  const name = `CI PHOTO IMPORT ${key}`;
  const oldPhoto = '/uploads/ci/hero-integrity.jpg';
  let artistId = 0;

  try {
    const create = await page.request.post(`${baseUrl}/api/index.php/artists`, {
      headers:{'X-CSRF-Token':auth.csrf},
      data:{name,slug,bio:'Synthetic real-stack import contract',status:'draft',photo:oldPhoto}
    });
    expect(create.ok(), await create.text()).toBe(true);
    const created = await create.json();
    artistId = Number(created.id || 0);
    expect(artistId).toBeGreaterThan(0);

    await page.goto(`${baseUrl}/discadmin/`, {waitUntil:'domcontentloaded'});
    await page.waitForFunction(() => Boolean(window.state?.authed), null, {timeout:10000});
    await page.evaluate(async id => {
      await window.go('artists');
      if (!window.state.rows.some(row => Number(row.id) === id)) {
        throw Error('Created artist missing from the real admin list');
      }
      window.openModal('artists',id);
    }, artistId);
    await expect(page.locator('#modal')).toHaveClass(/open/);
    await expect(page.locator('#f_photo')).toHaveValue(oldPhoto);

    // Server-side failure: the request still reaches the real authenticated endpoint.
    await page.locator('#artistPhotoImportUrl').fill(`${fixture}/unavailable.png`);
    await page.locator('#artistPhotoImportBtn').click();
    await expect(page.locator('#artistPhotoImportStatus')).toContainText('no respondió');
    await expect(page.locator('#f_photo')).toHaveValue(oldPhoto);

    // Only the upload-failure branch is intercepted; download goes through real PHP.
    await page.route('**/api/media-library.php?action=upload', route => route.fulfill({
      status:503, contentType:'application/json',
      body:JSON.stringify({ok:false,error:'UPLOAD_FAILED'})
    }), {times:1});
    await page.locator('#artistPhotoImportUrl').fill(`${fixture}/artist.png`);
    await page.locator('#artistPhotoImportBtn').click();
    await expect(page.locator('#artistPhotoImportStatus')).toContainText('No se pudo importar');
    await expect(page.locator('#f_photo')).toHaveValue(oldPhoto);

    // No browser interception on the successful download, Media upload or artist save.
    const uploadDone = page.waitForResponse(response =>
      response.url().includes('/api/media-library.php?action=upload')
      && response.request().method() === 'POST' && response.status() === 201
    );
    await page.locator('#artistPhotoImportUrl').fill(`${fixture}/artist.png`);
    await page.locator('#artistPhotoImportBtn').click();
    const upload = await uploadDone;
    const uploaded = await upload.json();
    expect(uploaded.ok).toBe(true);
    const mediaId = Number(uploaded.data?.id || 0);
    const ownPath = uploaded.data?.file_path;
    expect(mediaId).toBeGreaterThan(0);
    expect(ownPath).toMatch(/^\/uploads\/media\/\d{4}\/\d{2}\/[a-f0-9]{32}\.png$/);
    await expect(page.locator('#f_photo')).toHaveValue(ownPath);
    await expect(page.locator('#artistPhotoImportStatus')).toContainText('registrada en Media');

    const asset = await page.request.get(`${baseUrl}/api/media-library.php?action=detail&id=${mediaId}`);
    expect(asset.ok()).toBe(true);
    const mediaRecord = await asset.json();
    expect(mediaRecord.data).toMatchObject({id:mediaId,file_path:ownPath,mime_type:'image/png'});

    await page.locator('#f_status').selectOption('published');
    const saveDone = page.waitForResponse(response =>
      response.url().endsWith(`/api/index.php/artists/${artistId}`)
      && response.request().method() === 'PUT'
    );
    await page.locator('#saveBtn').click();
    const saved = await saveDone;
    expect(saved.ok(), await saved.text()).toBe(true);
    const stored = await page.request.get(`${baseUrl}/api/index.php/artists/${artistId}`);
    expect(stored.ok()).toBe(true);
    const artist = (await stored.json()).data;
    expect(artist.photo).toBe(ownPath);
    expect(artist.status).toBe('published');

    const publicPage = await page.request.get(`${baseUrl}/artists/${slug}`);
    expect(publicPage.status()).toBe(200);
    const html = await publicPage.text();
    expect(html).toContain(name);
    expect(html).toContain('/uploads/media/');
    expect(html).not.toContain(fixture);
    const photoFile = await page.request.get(`${baseUrl}${ownPath}`);
    expect(photoFile.status()).toBe(200);
    expect(photoFile.headers()['content-type']).toContain('image/png');
  } finally {
    if (artistId) {
      await page.request.delete(`${baseUrl}/api/index.php/artists/${artistId}`, {
        headers:{'X-CSRF-Token':auth.csrf}
      }).catch(() => {});
    }
  }
});
