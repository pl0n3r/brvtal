import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || ['brvtal', 'ci', 'password'].join('-');

test.skip(!baseUrl, 'BRVTAL_REAL_STACK_URL is required for the real-stack smoke');

test('Pages accepts valid JSON content through authenticated PHP/MariaDB API', async ({ page }) => {
  const login = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data: { email: adminEmail, password: adminPassword },
  });
  expect(login.ok(), `Admin login failed with HTTP ${login.status()}`).toBeTruthy();
  const loginPayload = await login.json();
  expect(loginPayload.ok).toBe(true);
  expect(loginPayload.csrf).toBeTruthy();

  const slug = 'manifiesto-brvtal-ci';
  const contentJson = JSON.stringify({ text: 'Manifiesto' });
  const create = await page.request.post(`${baseUrl}/api/index.php/pages`, {
    headers: { 'X-CSRF-Token': loginPayload.csrf },
    data: {
      title: 'MANIFIESTO BRVTAL',
      slug,
      locale: 'es',
      status: 'published',
      content_json: contentJson,
      seo_title: 'MANIFIESTO BRVTAL | BRVTAL',
      seo_description: 'Pagina de regresion para validar persistencia JSON del modulo Pages.',
    },
  });

  const createPayload = await create.json();
  expect(create.ok(), `Pages create failed with HTTP ${create.status()}: ${JSON.stringify(createPayload)}`).toBeTruthy();
  expect(createPayload.ok).toBe(true);
  expect(Number(createPayload.id)).toBeGreaterThan(0);
  const pageId = Number(createPayload.id);

  const read = await page.request.get(`${baseUrl}/api/index.php/pages/${pageId}`);
  expect(read.ok()).toBeTruthy();
  const readPayload = await read.json();
  expect(readPayload.ok).toBe(true);
  expect(readPayload.data.title).toBe('MANIFIESTO BRVTAL');
  expect(readPayload.data.slug).toBe(slug);
  expect(readPayload.data.locale).toBe('es');
  expect(readPayload.data.status).toBe('published');
  expect(readPayload.data.content_json).toBe(contentJson);
  expect(JSON.parse(readPayload.data.content_json)).toEqual({ text: 'Manifiesto' });
  expect(readPayload.data.seo_title).toBe('MANIFIESTO BRVTAL | BRVTAL');
  expect(readPayload.data.seo_description).toContain('persistencia JSON');

  const updateContent = JSON.stringify({ text: 'Manifiesto actualizado', blocks: [] });
  const update = await page.request.put(`${baseUrl}/api/index.php/pages/${pageId}`, {
    headers: { 'X-CSRF-Token': loginPayload.csrf },
    data: {
      content_json: updateContent,
      seo_description: 'JSON actualizado por el smoke autenticado de Pages.',
    },
  });
  const updatePayload = await update.json();
  expect(update.ok(), `Pages update failed with HTTP ${update.status()}: ${JSON.stringify(updatePayload)}`).toBeTruthy();
  expect(updatePayload.ok).toBe(true);

  const reread = await page.request.get(`${baseUrl}/api/index.php/pages/${pageId}`);
  expect(reread.ok()).toBeTruthy();
  const rereadPayload = await reread.json();
  expect(rereadPayload.data.content_json).toBe(updateContent);
  expect(JSON.parse(rereadPayload.data.content_json)).toEqual({ text: 'Manifiesto actualizado', blocks: [] });
});
