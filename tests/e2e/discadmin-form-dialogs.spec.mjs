import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = readFileSync(join(process.cwd(), 'discadmin/admin-form-dialogs.css'), 'utf8');
const js = readFileSync(join(process.cwd(), 'discadmin/admin-form-dialogs.js'), 'utf8');

async function mount(page) {
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>
    body{background:#050505;color:#fff;font-family:Arial}.modal{display:none}.modal.open{display:block}.modalbox{background:#080909;padding:12px}.field{margin:12px}.notice{display:none}.notice.show{display:block}
  </style><style>${css}</style></head><body>
    <button id="opener" type="button" onclick="openLegacy()">NEW EVENT</button>
    <button id="blog-opener" type="button" onclick="openBlog()">NEW BLOG POST</button>
    <div class="modal" id="modal"><div class="modalbox"><div class="modalhead"><div><h2 id="mtitle"></h2></div><button class="iconbtn" onclick="closeModal()">ESC</button></div><div id="notice" class="notice"></div><div id="mcontent"></div><div class="modalfoot"><span class="helper">Saved to database.</span><div class="footactions"><button class="btn ghost" onclick="closeModal()">CANCEL</button><button class="btn red" id="saveBtn">SAVE</button></div></div></div></div>
    <script>
      window.saved = 0;
      window.closeModal = function(){ document.getElementById('modal').classList.remove('open'); };
      window.openLegacy = function(){
        document.getElementById('mtitle').textContent = 'NEW EVENTS';
        document.getElementById('mcontent').innerHTML = '<div class="form"><div class="field"><label for="f_title">Title *</label><input id="f_title"></div><div class="field"><label for="f_status">Status</label><select id="f_status"><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></div><div class="field"><label>Cover image</label><input id="f_cover_image"></div></div>';
        document.getElementById('saveBtn').onclick = function(){ window.saved += 1; };
        document.getElementById('modal').classList.add('open');
      };
      window.openBlog = function(){
        document.getElementById('mtitle').textContent = 'NEW BLOG POST';
        document.getElementById('mcontent').innerHTML = '<div class="form"><div class="field"><label>Title *</label><input id="blog_title"></div><div class="field"><label>Status</label><select id="blog_status_field"><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></select></div></div>';
        document.getElementById('saveBtn').onclick = function(){ window.saved += 1; };
        document.getElementById('modal').classList.add('open');
      };
    </script>
    <script>${js}</script>
  </body></html>`);
}

test('legacy editor becomes an accessible dialog and restores focus on Escape', async ({ page }) => {
  await mount(page);
  const opener = page.getByRole('button', { name: 'NEW EVENT' });
  await opener.click();

  const dialog = page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await expect(dialog).toHaveAttribute('aria-modal', 'true');
  await expect(dialog).toHaveAttribute('aria-labelledby', 'mtitle');
  await expect(page.locator('#f_title')).toHaveAttribute('required', '');
  await expect(page.locator('#f_title')).toHaveAttribute('aria-required', 'true');
  await expect(page.locator('#f_title')).toBeFocused();
  await expect(page.locator('label[for="f_cover_image"]')).toHaveText('Cover image');
  await expect(page.getByRole('button', { name: 'SAVE DRAFT' })).toBeVisible();
  await expect(page.locator('.admin-publication-state')).toContainText('Private working state');

  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();
});

test('required validation blocks save, then published state is explicit and legacy handler still runs', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'NEW EVENT' }).click();

  await page.getByRole('button', { name: 'SAVE DRAFT' }).click();
  expect(await page.evaluate(() => window.saved)).toBe(0);
  await expect(page.locator('#notice')).toHaveAttribute('role', 'alert');
  await expect(page.locator('#notice')).toContainText('Title is required');
  await expect(page.locator('#f_title')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#f_title')).toBeFocused();

  await page.locator('#f_title').fill('GENESIS');
  await page.locator('#f_status').selectOption('published');
  await expect(page.getByRole('button', { name: 'SAVE & PUBLISH' })).toBeVisible();
  await expect(page.locator('.admin-publication-state')).toContainText('Saving will publish the current record');

  await page.getByRole('button', { name: 'SAVE & PUBLISH' }).click();
  expect(await page.evaluate(() => window.saved)).toBe(1);
  await expect(page.locator('#saveBtn')).toHaveText('SAVING…');
  await expect(page.locator('#saveBtn')).toHaveAttribute('aria-busy', 'true');

  await page.locator('#saveBtn').click();
  expect(await page.evaluate(() => window.saved)).toBe(1);
});

test('Blog and Releases-style fields inherit the same required and publication behavior', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'NEW BLOG POST' }).click();

  await expect(page.locator('#blog_title')).toHaveAttribute('required', '');
  await expect(page.locator('label[for="blog_title"]')).toContainText('REQUIRED');
  await expect(page.getByRole('button', { name: 'SAVE DRAFT' })).toBeVisible();

  await page.locator('#blog_title').fill('BRVTAL NEWS');
  await page.locator('#blog_status_field').selectOption('archived');
  await expect(page.getByRole('button', { name: 'SAVE ARCHIVED' })).toBeVisible();
  await expect(page.locator('.admin-publication-state')).toContainText('Historical state');
});

test('Tab stays inside the active dialog', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'NEW EVENT' }).click();
  await page.locator('#f_title').fill('GENESIS');

  const save = page.locator('#saveBtn');
  await save.focus();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Close dialog' })).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await expect(save).toBeFocused();
});
