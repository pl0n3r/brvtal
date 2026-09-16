import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL_REAL_STACK_URL and admin credentials are required for the real-stack smoke');

test('Media writes stay behind the canonical Media Library integrity boundary', async ({ page }) => {
  const login = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data: {email: adminEmail, password: adminPassword},
  });
  expect(login.ok()).toBeTruthy();
  const auth = await login.json();
  const headers = {'X-CSRF-Token': auth.csrf};

  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
    'base64',
  );
  let mediaId = 0;

  try {
    const legacyUpload = await page.request.post(`${baseUrl}/api/index.php/upload`, {headers});
    expect(legacyUpload.status()).toBe(404);
    expect(await legacyUpload.json()).toMatchObject({ok:false, error:'NOT_FOUND'});

    const legacyCreate = await page.request.post(`${baseUrl}/api/index.php/media`, {
      headers,
      data: {type:'image', title:'LEGACY MEDIA MUST STAY CLOSED', file_path:'/uploads/legacy.png', status:'published'},
    });
    expect(legacyCreate.status()).toBe(404);
    expect(await legacyCreate.json()).toMatchObject({ok:false, error:'NOT_FOUND'});

    const upload = await page.request.post(`${baseUrl}/api/media-library.php?action=upload`, {
      headers,
      multipart: {
        title: 'CI MEDIA INTEGRITY',
        alt_text: 'CI media integrity fixture',
        file: {name:'ci-media-integrity.png', mimeType:'image/png', buffer:png},
      },
    });
    expect(upload.status()).toBe(201);
    const uploaded = await upload.json();
    mediaId = Number(uploaded?.data?.id || 0);
    const filePath = String(uploaded?.data?.file_path || '');
    expect(mediaId).toBeGreaterThan(0);
    expect(filePath).toMatch(/^\/uploads\/media\//);
    expect(uploaded?.data?.status).toBe('draft');

    const duplicateRegister = await page.request.post(`${baseUrl}/api/media-library.php?action=register`, {
      headers,
      data: {type:'image', title:'CI DUPLICATE OWNER', file_path:filePath, status:'draft'},
    });
    expect(duplicateRegister.status()).toBe(409);
    expect(await duplicateRegister.json()).toMatchObject({
      ok:false,
      error:'LOCAL_MEDIA_ALREADY_REGISTERED',
      media_id:mediaId,
    });

    const legacyDelete = await page.request.delete(`${baseUrl}/api/index.php/media/${mediaId}`, {headers});
    expect(legacyDelete.status()).toBe(404);
    expect(await legacyDelete.json()).toMatchObject({ok:false, error:'NOT_FOUND'});

    const detailAfterLegacyDelete = await page.request.get(`${baseUrl}/api/media-library.php?action=detail&id=${mediaId}`);
    expect(detailAfterLegacyDelete.ok()).toBeTruthy();
    expect(Number((await detailAfterLegacyDelete.json())?.data?.id)).toBe(mediaId);

    const canonicalDelete = await page.request.delete(`${baseUrl}/api/media-library.php?id=${mediaId}`, {headers});
    expect(canonicalDelete.ok()).toBeTruthy();
    expect(await canonicalDelete.json()).toMatchObject({ok:true, deleted:1});
    mediaId = 0;
  } finally {
    if (mediaId > 0) {
      await page.request.delete(`${baseUrl}/api/media-library.php?id=${mediaId}`, {headers}).catch(() => {});
    }
  }
});
