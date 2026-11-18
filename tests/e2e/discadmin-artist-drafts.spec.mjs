import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const editorDraftsJs = readFileSync(join(process.cwd(), 'discadmin/editor-drafts.js'), 'utf8');
const legacyDraftsJs = readFileSync(join(process.cwd(), 'discadmin/legacy-editor-drafts.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/artist-drafts-e2e.html';

const seedArtist = {
  id:7,
  name:'PL0N3R',
  slug:'pl0n3r',
  bio:'Server artist bio',
  photo:'/uploads/media/pl0n3r.jpg',
  instagram_url:'https://instagram.com/pl0n3r',
  soundcloud_url:'https://soundcloud.com/pl0n3r',
  website_url:'https://brvtal.com.co',
  is_collective_member:1,
  status:'published',
  sort_order:4,
  updated_at:'2026-09-26 20:00:00'
};

function harness(record = seedArtist) {
  return `<!doctype html><html><body>
    <div class="modal open" id="modal">
      <div id="mcontent"><div class="form"><div class="section"><div class="grid2">
        <input id="f_name" value="${record.name}">
        <input id="f_slug" value="${record.slug}">
        <textarea id="f_bio">${record.bio}</textarea>
        <input id="f_photo" value="${record.photo}">
        <input id="f_instagram_url" value="${record.instagram_url}">
        <input id="f_soundcloud_url" value="${record.soundcloud_url}">
        <input id="f_website_url" value="${record.website_url}">
        <input id="f_is_collective_member" type="checkbox" ${record.is_collective_member ? 'checked' : ''}>
        <select id="f_status"><option value="draft">Draft</option><option value="published" selected>Published</option></select>
      </div></div></div></div>
    </div>
    <script>
      window.csrf='artist-drafts-csrf';
      window.__touches=0;window.__clean=0;
      window.BRVTALUnsavedChanges={
        touch(){window.__touches+=1},
        markClean(){window.__clean+=1}
      };
    </script>
    <script>${editorDraftsJs}</script>
    <script>${legacyDraftsJs}</script>
    <script>
      window.__artistRecord=${JSON.stringify(record)};
      window.__artistPayload=()=>({
        name:document.getElementById('f_name').value,
        slug:document.getElementById('f_slug').value,
        bio:document.getElementById('f_bio').value,
        photo:document.getElementById('f_photo').value,
        instagram_url:document.getElementById('f_instagram_url').value,
        soundcloud_url:document.getElementById('f_soundcloud_url').value,
        website_url:document.getElementById('f_website_url').value,
        is_collective_member:document.getElementById('f_is_collective_member').checked,
        status:document.getElementById('f_status').value,
        sort_order:${Number(record.sort_order || 0)}
      });
      void window.BRVTALLegacyDrafts.bind('artists',7,window.__artistRecord);
    </script>
  </body></html>`;
}

async function loadHarness(page) {
  await page.route(harnessUrl, route => route.fulfill({contentType:'text/html',body:harness()}));
  await page.goto(harnessUrl);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Saved to server');
}

test('Artists autosaves and restores all editorial fields locally', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_bio').fill('Recovered artist bio');
  await page.locator('#f_is_collective_member').uncheck();

  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  const stored = await page.evaluate(async () => (await BRVTALDrafts.load('artists','7'))?.data);
  expect(stored.bio).toBe('Recovered artist bio');
  expect(stored.is_collective_member).toBe(false);

  await page.reload();
  await expect(page.locator('[data-legacy-draft-recovery]')).toBeVisible();
  await page.locator('[data-legacy-draft-restore]').click();
  await expect(page.locator('#f_bio')).toHaveValue('Recovered artist bio');
  await expect(page.locator('#f_is_collective_member')).not.toBeChecked();
  expect(await page.evaluate(() => window.__touches)).toBeGreaterThan(0);
});

test('Artists reports revision drift before restore', async ({page}) => {
  await loadHarness(page);
  await page.evaluate(async () => {
    const data=window.__artistPayload();
    data.name='Local conflicting artist';
    delete data.sort_order;
    await BRVTALDrafts.save('artists','7',{
      base_revision:'2026-09-25 09:00:00',
      data
    });
  });
  await page.reload();
  const recovery=page.locator('[data-legacy-draft-recovery]');
  await expect(recovery).toBeVisible();
  await expect(recovery).toHaveAttribute('data-conflict','1');
  await expect(recovery).toContainText('SERVER CHANGED');
  await expect(page.locator('#f_name')).toHaveValue('PL0N3R');
});

test('Artists successful Save ignores non-editable visual sort_order metadata', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_bio').fill('Server-bound artist bio');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  const submitted=await page.evaluate(() => window.__artistPayload());

  const result=await page.evaluate(async payload => BRVTALLegacyDrafts.serverSaved({
    type:'artists',id:7,payload,result:{ok:true,changed:1}
  }),submitted);

  expect(result.keepOpen).toBe(false);
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('artists','7'))).toBe(null);
  expect(await page.evaluate(() => window.__clean)).toBeGreaterThan(0);
});

test('Artists keeps edits made after the submitted Save payload', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_bio').fill('Submitted artist bio');
  const submitted=await page.evaluate(() => window.__artistPayload());
  await page.locator('#f_bio').fill('Newer local artist bio');

  const result=await page.evaluate(async payload => BRVTALLegacyDrafts.serverSaved({
    type:'artists',id:7,payload,result:{ok:true,changed:1}
  }),submitted);

  expect(result.keepOpen).toBe(true);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('newer edits remain unsaved');
  expect(await page.evaluate(async () => (await BRVTALDrafts.load('artists','7'))?.data?.bio))
    .toBe('Newer local artist bio');
});

test('Artists drafts are removed at the session boundary', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_bio').fill('Session-bound artist draft');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:auth-required')));
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('artists','7'))).toBe(null);
});
