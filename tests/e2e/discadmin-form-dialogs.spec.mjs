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
    <button id="cc-opener" type="button" onclick="openContentCore()">CONTENT CORE EVENT</button>
    <div class="modal" id="modal"><div class="modalbox"><div class="modalhead"><div><h2 id="mtitle"></h2></div><button class="iconbtn" onclick="closeModal()">ESC</button></div><div id="notice" class="notice"></div><div id="mcontent"></div><div class="modalfoot"><span class="helper">Saved to database.</span><div class="footactions"><button class="btn ghost" onclick="closeModal()">CANCEL</button><button class="btn red" id="saveBtn">SAVE</button></div></div></div></div>
    <div id="module-host"></div>
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
      window.BRVTALContentCore = {
        closeEvent(){ document.getElementById('eventModal')?.classList.remove('open'); }
      };
      window.openContentCore = function(){
        const host = document.getElementById('module-host');
        if (!document.getElementById('eventModal')) {
          host.innerHTML = '<div id="eventModal" class="modal"><div class="modalbox"><div class="modalhead"><h2 id="eventHeading">NEW EVENT</h2><div class="modal-actions"><button class="icon" onclick="BRVTALContentCore.closeEvent()">CLOSE</button></div></div><div id="eventNotice" class="notice"></div><div class="field"><label>Name *</label><input id="e_title"></div><div class="field"><label>Date & time *</label><input id="e_event_date" type="datetime-local"></div><div class="field"><label>City *</label><input id="e_city"></div><div class="field"><label>Status</label><select id="e_status"><option value="draft">draft</option><option value="published">published</option><option value="upcoming">upcoming</option></select></div><div class="foot"><button>CANCEL</button><button id="cc-saveBtn">SAVE DRAFT</button></div></div></div>';
        }
        document.getElementById('eventModal').classList.add('open');
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

  await page.evaluate(() => document.getElementById('saveBtn').click());
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

test('Content Core modal uses the same dialog semantics and conditional lifecycle requirements', async ({ page }) => {
  await mount(page);
  const opener = page.getByRole('button', { name: 'CONTENT CORE EVENT' });
  await opener.click();

  const dialog = page.locator('#eventModal .modalbox');
  await expect(dialog).toHaveAttribute('role', 'dialog');
  await expect(dialog).toHaveAttribute('aria-modal', 'true');
  await expect(dialog).toHaveAttribute('aria-labelledby', 'eventHeading');
  await expect(page.locator('#e_title')).toHaveAttribute('required', '');
  await expect(page.locator('#e_title')).toBeFocused();
  await expect(page.locator('#e_event_date')).toHaveAttribute('aria-required', 'false');
  await expect(page.locator('#e_city')).toHaveAttribute('aria-required', 'false');
  await expect(page.locator('.admin-content-core-state')).toContainText('Draft can be saved with a name only');

  await page.locator('#e_status').selectOption('published');
  await expect(page.locator('#e_event_date')).toHaveAttribute('required', '');
  await expect(page.locator('#e_city')).toHaveAttribute('required', '');
  await expect(page.locator('.admin-content-core-state')).toContainText('requires name, date and city');

  await page.keyboard.press('Escape');
  await expect(page.locator('#eventModal')).toBeHidden();
  await expect(opener).toBeFocused();
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

test('Tab stays inside the Content Core dialog', async ({ page }) => {
  await mount(page);
  await page.getByRole('button', { name: 'CONTENT CORE EVENT' }).click();

  const close = page.locator('#eventModal .modal-actions .icon');
  const save = page.locator('#cc-saveBtn');

  await save.focus();
  await page.keyboard.press('Tab');
  await expect(close).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await expect(save).toBeFocused();
});
