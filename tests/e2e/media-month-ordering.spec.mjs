import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const mediaLibraryJs = readFileSync(join(process.cwd(), 'discadmin/media-library.js'), 'utf8');

const items = [
  {id:1,type:'document',title:'Dec 2025',file_path:'/uploads/dec.pdf',mime_type:'application/pdf',file_size:1,status:'published',created_at:'2025-12-01 00:00:00'},
  {id:2,type:'document',title:'Feb 2026 A',file_path:'/uploads/feb-a.pdf',mime_type:'application/pdf',file_size:1,status:'published',created_at:'2026-02-01 00:00:00'},
  {id:3,type:'document',title:'Oct 2026',file_path:'/uploads/oct.pdf',mime_type:'application/pdf',file_size:1,status:'published',created_at:'2026-10-01 00:00:00'},
  {id:4,type:'document',title:'Feb 2026 B',file_path:'/uploads/feb-b.pdf',mime_type:'application/pdf',file_size:1,status:'published',created_at:'2026-02-15 00:00:00'}
];

test('Media Library month filter keeps unique months in descending order', async ({ page }) => {
  await page.route('**/api/media-library.php**', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ok:true,data:items,engine:{gd:false,webp:false}})
  }));

  await page.setContent(`<!doctype html><html><body>
    <section id="media-root">
      <input id="media-search">
      <select id="media-type-filter"><option value=""></option></select>
      <select id="media-month-filter"></select>
      <button id="media-upload"></button>
      <input id="media-file" type="file">
      <button id="media-register"></button>
      <div id="media-dropzone" tabindex="0"></div>
      <div id="media-status"></div>
      <div id="media-summary"></div>
      <div id="media-grid"></div>
      <aside id="media-inspector"></aside>
    </section>
    <script>${mediaLibraryJs}</script>
    <script>BRVTALMediaLibrary.mount(document.getElementById('media-root'));</script>
  </body></html>`);

  await expect(page.locator('#media-month-filter option')).toHaveCount(4);
  const values = await page.locator('#media-month-filter option').evaluateAll(options => options.map(option => option.value));
  expect(values).toEqual(['', '2026-10', '2026-02', '2025-12']);
});
