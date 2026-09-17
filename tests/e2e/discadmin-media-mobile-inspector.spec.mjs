import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const mediaLibraryJs = readFileSync(join(process.cwd(), 'discadmin/media-library.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/media-mobile-inspector-e2e.html';

const item = id => ({
  id,
  type: 'image',
  title: 'Asset ' + id,
  file_path: '/uploads/media/asset-' + id + '.jpg',
  mime_type: 'image/jpeg',
  file_size: 120000,
  alt_text: 'Asset ' + id,
  status: 'published',
  created_at: '2026-09-17 17:00:00',
  engine: { status: 'ready', focal_point: {x:.5,y:.5}, variants: {} },
  quality: { grade: 'good', contexts: {} },
});

async function loadMedia(page, width = 390) {
  const items = Array.from({length: 14}, (_, index) => item(index + 1));
  await page.setViewportSize({width,height:844});
  await page.addInitScript(() => {
    window.__mediaInspectorScrolls = [];
    HTMLElement.prototype.scrollIntoView = function(options) {
      window.__mediaInspectorScrolls.push({id:this.id,options});
    };
  });
  await page.route('**/api/auth', route => route.fulfill({
    contentType:'application/json',
    body:JSON.stringify({authenticated:true,csrf:'csrf-token'})
  }));
  await page.route('**/api/media-library.php**', route => {
    const url = new URL(route.request().url());
    const action = url.searchParams.get('action') || 'list';
    if (action === 'detail') {
      const id = Number(url.searchParams.get('id'));
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{...item(id),usage:[]}})});
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:items})});
  });
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body>
      <section data-admin-module="media">
        <input id="media-search">
        <select id="media-type-filter"><option value=""></option></select>
        <select id="media-month-filter"></select>
        <button id="media-upload"></button><input id="media-file" type="file">
        <button id="media-register"></button><div id="media-dropzone"></div>
        <div id="media-status"></div><div id="media-summary"></div>
        <div id="media-grid"></div>
        <aside id="media-inspector"></aside>
      </section>
      <script>${mediaLibraryJs}</script>
      <script>BRVTALMediaLibrary.mount(document.querySelector('[data-admin-module=media]'))</script>
    </body></html>`
  }));
  await page.goto(harnessUrl);
  await expect(page.getByRole('button', {name:/Asset 1/i})).toBeVisible();
}

test('mobile asset selection reveals the inspector immediately', async ({page}) => {
  await loadMedia(page, 390);
  await page.getByRole('button', {name:/Asset 1/i}).click();
  await expect(page.locator('#media-edit-title')).toHaveValue('Asset 1');

  expect(await page.evaluate(() => window.__mediaInspectorScrolls)).toEqual([{
    id:'media-inspector',
    options:{behavior:'auto',block:'start'}
  }]);

  await page.evaluate(() => window.BRVTALMediaLibrary.refresh(1));
  await expect(page.locator('#media-edit-title')).toHaveValue('Asset 1');
  await expect.poll(() => page.evaluate(() => window.__mediaInspectorScrolls.length)).toBe(1);
});

test('desktop asset selection keeps the current viewport position', async ({page}) => {
  await loadMedia(page, 1200);
  await page.getByRole('button', {name:/Asset 2/i}).click();
  await expect(page.locator('#media-edit-title')).toHaveValue('Asset 2');
  expect(await page.evaluate(() => window.__mediaInspectorScrolls.length)).toBe(0);
});
