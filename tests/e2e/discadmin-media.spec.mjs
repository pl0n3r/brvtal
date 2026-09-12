import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const mediaLibraryJs = readFileSync(join(process.cwd(), 'discadmin/media-library.js'), 'utf8');
const adminModulesJs = readFileSync(join(process.cwd(), 'discadmin/admin-modules.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/e2e.html';
const onePixelPng = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');

const mediaItem = {
  id: 11,
  type: 'image',
  title: 'Genesis poster',
  file_path: 'uploads/media/2026/09/genesis.jpg',
  mime_type: 'image/jpeg',
  file_size: 120000,
  alt_text: 'Genesis poster alt',
  status: 'published',
  created_at: '2026-09-11 17:00:00',
  engine: {
    status: 'ready',
    focal_point: { x: 0.5, y: 0.5 },
    variants: {
      square: {
        path: 'uploads/media/2026/09/genesis--square-480.webp',
        width: 480,
        height: 480,
        mime_type: 'image/webp'
      }
    }
  },
  quality: { grade: 'good', contexts: { square: { label: 'GRID / AVATAR', width: 800, height: 800, ready: true }, card: { label: 'CONTENT CARD', width: 1200, height: 900, ready: true }, hero: { label: 'EVENT HERO', width: 1920, height: 1080, ready: false } } }
};

async function mockApi(page) {
  await page.route('**/uploads/media/**', route => route.fulfill({ status: 200, contentType: 'image/png', body: onePixelPng }));
  await page.route('**/discadmin/media-library.js**', route => route.fulfill({ contentType: 'application/javascript', body: mediaLibraryJs }));
  await page.route('**/discadmin/media-library.css**', route => route.fulfill({ contentType: 'text/css', body: '' }));
  await page.route('**/api/auth', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ authenticated: true, csrf: 'csrf-token' })
  }));
  await page.route('**/api/index.php/auth', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ authenticated: true, csrf: 'csrf-token' })
  }));
  await page.route('**/api/media-permissions.php', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, repaired: 0 })
  }));
  await page.route('**/api/media-library.php**', route => {
    const url = new URL(route.request().url());
    const action = url.searchParams.get('action') || 'list';
    if (action === 'list') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: [mediaItem] }) });
    }
    if (action === 'detail') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: { ...mediaItem, usage: [] } }) });
    }
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: mediaItem }) });
  });
  await page.route('**/api/index.php/events/42', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, data: { id: 42 } })
  }));
  await page.route('**/api/index.php/artists/7', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, data: { id: 7 } })
  }));
  await page.route('**/api/index.php/sets**', route => route.fulfill({
    status: 500,
    contentType: 'application/json',
    body: JSON.stringify({ ok: false, error: 'TEST_FAILURE' })
  }));
}

async function loadHarness(page, body) {
  await mockApi(page);
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html><head></head><body>${body}</body></html>`
  }));
  await page.goto(harnessUrl);
}

async function loadMediaLibraryHarness(page) {
  await loadHarness(page, `
    <div class="thumbcell"><div class="thumbph">IMG</div><input id="f_cover_image" value=""></div>
    <div class="thumbcell"><div class="thumbph">IMG</div><input id="f_photo" value=""></div>
    <script>${mediaLibraryJs}</script>
  `);
}

async function loadAdminHarness(page) {
  await loadHarness(page, `
    <button id="saveBtn">SAVE</button>
    <input id="f_title" value="Genesis">
    <input id="f_slug" value="genesis">
    <input id="f_event_date" value="2026-09-11T21:00">
    <input id="f_venue" value="La Perla">
    <input id="f_city" value="Pereira">
    <textarea id="f_description">Test event</textarea>
    <input id="f_skin" value="core">
    <input id="f_accent" value="#b6ff00">
    <input id="f_cover_image" value="/uploads/media/2026/09/genesis.jpg">
    <input id="f_ticket_url" value="">
    <select id="f_status"><option value="published" selected>published</option></select>
    <input id="f_sort_order" value="0">
    <script>
      window.state = { section: 'events' };
      window.csrf = 'csrf-token';
      window.render = () => {};
      window.closeModal = () => { window.__closed = true; };
      window.go = async section => { window.__went = section; };
      window.openModal = () => {};
      window.req = async (path, options = {}) => {
        window.__lastReq = { path, method: options.method || 'GET', body: options.body || '' };
        const response = await fetch('/api/index.php' + path, { method: options.method || 'GET', body: options.body || null });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false) throw new Error(payload.error || 'REQUEST_FAILED');
        return payload;
      };
    </script>
    <script>${adminModulesJs}</script>
  `);
}

test('media picker normalizes paths, updates inputs/previews, and shows guidance', async ({ page }) => {
  await loadMediaLibraryHarness(page);

  await expect(page.locator('.media-picker-btn').first()).toBeVisible();
  await page.locator('.media-picker-btn').first().click();
  await expect(page.locator('.brvtal-media-picker h3')).toHaveText('SELECT MEDIA');
  await page.getByRole('button', { name: /Genesis poster/i }).click();

  await expect(page.locator('#f_cover_image')).toHaveValue('/uploads/media/2026/09/genesis.jpg');
  await expect(page.locator('.thumbcell img').first()).toHaveAttribute('src', '/uploads/media/2026/09/genesis.jpg');
  await expect(page.getByText('Media selected. Press SAVE to persist this record.')).toBeVisible();
});

test('media inspector previews contexts and submits a focal point regeneration', async ({ page }) => {
  await loadHarness(page, `<section data-admin-module="media"><input id="media-search"><select id="media-type-filter"><option value=""></option></select><select id="media-month-filter"></select><button id="media-upload"></button><input id="media-file" type="file"><button id="media-register"></button><div id="media-dropzone"></div><div id="media-status"></div><div id="media-summary"></div><div id="media-grid"></div><aside id="media-inspector"></aside></section><script>${mediaLibraryJs}</script><script>BRVTALMediaLibrary.mount(document.querySelector('[data-admin-module=media]'))</script>`);
  await page.getByRole('button', { name: /Genesis poster/i }).click();
  await expect(page.getByText('FOCAL POINT / CROP')).toBeVisible();
  await expect(page.locator('.media-context')).toHaveCount(3);
  await page.locator('#media-focal-x').fill('75');
  await page.locator('#media-focal-y').fill('25');
  const transform = page.waitForRequest(request => request.url().includes('action=transform'));
  await page.getByRole('button', { name: 'SAVE FOCUS + REGENERATE' }).click();
  const request = await transform;
  expect(request.postDataJSON()).toEqual({x:0.75,y:0.25});
});

test('event save sends selected media path and shows success feedback', async ({ page }) => {
  await loadAdminHarness(page);
  await page.evaluate(() => window.save('events', 42));

  const lastReq = await page.evaluate(() => window.__lastReq);
  expect(lastReq.path).toBe('/events/42');
  expect(lastReq.method).toBe('PUT');
  expect(JSON.parse(lastReq.body).cover_image).toBe('/uploads/media/2026/09/genesis.jpg');
  await expect(page.getByText('Changes saved.')).toBeVisible();
});

test('artist save sends selected media path', async ({ page }) => {
  await loadAdminHarness(page);
  await page.evaluate(() => {
    document.body.innerHTML += '<input id="f_name" value="PL0N3R"><input id="f_bio" value=""><input id="f_photo" value="/uploads/media/2026/09/genesis.jpg"><input id="f_instagram_url" value=""><input id="f_soundcloud_url" value=""><input id="f_website_url" value=""><select id="f_status"><option value="published" selected>published</option></select><input id="f_sort_order" value="0">';
  });
  await page.evaluate(() => window.save('artists', 7));

  const lastReq = await page.evaluate(() => window.__lastReq);
  expect(lastReq.path).toBe('/artists/7');
  expect(lastReq.method).toBe('PUT');
  expect(JSON.parse(lastReq.body).photo).toBe('/uploads/media/2026/09/genesis.jpg');
});

test('failed mutations show persistent error feedback', async ({ page }) => {
  await loadAdminHarness(page);
  await page.evaluate(async () => {
    await fetch('/api/index.php/sets/1', { method: 'PUT', body: '{}' });
  });

  await expect(page.locator('.brvtal-feedback.error')).toBeVisible();
  await expect(page.getByText('Test failure')).toBeVisible();
});

test('broken thumbnails degrade to a placeholder', async ({ page }) => {
  await loadAdminHarness(page);
  await page.evaluate(() => {
    const img = document.createElement('img');
    img.className = 'thumb';
    img.src = 'missing.jpg';
    document.body.appendChild(img);
    img.dispatchEvent(new Event('error', { bubbles: true }));
  });

  await expect(page.getByText('NO IMG')).toBeVisible();
});
