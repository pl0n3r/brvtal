import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const editorDraftsJs = readFileSync(join(process.cwd(), 'discadmin/editor-drafts.js'), 'utf8');
const legacyDraftsJs = readFileSync(join(process.cwd(), 'discadmin/legacy-editor-drafts.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/set-drafts-e2e.html';

const seedSet = {
  id:11,
  title:'Industrial Signal',
  slug:'industrial-signal',
  platform:'soundcloud',
  external_url:'https://soundcloud.com/brvtal/industrial-signal',
  embed_url:'https://w.soundcloud.com/player/?url=industrial-signal',
  cover_image:'/uploads/media/set-cover.jpg',
  artist_id:7,
  event_id:3,
  status:'published',
  sort_order:5,
  description:'Server set description',
  updated_at:'2026-09-26 21:00:00'
};

function harness(record = seedSet) {
  return `<!doctype html><html><body>
    <div class="modal open" id="modal">
      <div id="mcontent"><div class="form"><div class="section"><div class="grid2">
        <input id="f_title" value="${record.title}">
        <input id="f_slug" value="${record.slug}">
        <select id="f_platform">
          <option value="soundcloud" ${record.platform==='soundcloud'?'selected':''}>SoundCloud</option>
          <option value="youtube" ${record.platform==='youtube'?'selected':''}>YouTube</option>
        </select>
        <input id="f_external_url" value="${record.external_url}">
        <input id="f_embed_url" value="${record.embed_url}">
        <input id="f_cover_image" value="${record.cover_image}">
        <select id="f_artist_id"><option value="">—</option><option value="7" selected>PL0N3R</option><option value="8">OTHER</option></select>
        <select id="f_event_id"><option value="">—</option><option value="3" selected>BRVTAL NIGHT</option><option value="4">OTHER</option></select>
        <select id="f_status"><option value="draft">Draft</option><option value="published" selected>Published</option></select>
        <textarea id="f_description">${record.description}</textarea>
      </div></div></div></div>
    </div>
    <script>
      window.__touches=0;window.__clean=0;
      window.BRVTALUnsavedChanges={
        touch(){window.__touches+=1},
        markClean(){window.__clean+=1}
      };
    </script>
    <script>${editorDraftsJs}</script>
    <script>${legacyDraftsJs}</script>
    <script>
      window.__setRecord=${JSON.stringify(record)};
      window.__setPayload=()=>({
        title:document.getElementById('f_title').value,
        slug:document.getElementById('f_slug').value,
        platform:document.getElementById('f_platform').value,
        external_url:document.getElementById('f_external_url').value,
        embed_url:document.getElementById('f_embed_url').value,
        cover_image:document.getElementById('f_cover_image').value,
        artist_id:document.getElementById('f_artist_id').value ? Number(document.getElementById('f_artist_id').value) : null,
        event_id:document.getElementById('f_event_id').value ? Number(document.getElementById('f_event_id').value) : null,
        status:document.getElementById('f_status').value,
        description:document.getElementById('f_description').value,
        sort_order:${Number(record.sort_order || 0)}
      });
      void window.BRVTALLegacyDrafts.bind('sets',11,window.__setRecord);
    </script>
  </body></html>`;
}

async function loadHarness(page) {
  await page.route(harnessUrl, route => route.fulfill({contentType:'text/html',body:harness()}));
  await page.goto(harnessUrl);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Saved to server');
}

test('Sets autosaves and restores editorial and relation fields locally', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_description').fill('Recovered set description');
  await page.locator('#f_artist_id').selectOption('8');
  await page.locator('#f_event_id').selectOption('4');

  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  const stored=await page.evaluate(async () => (await BRVTALDrafts.load('sets','11'))?.data);
  expect(stored.description).toBe('Recovered set description');
  expect(stored.artist_id).toBe(8);
  expect(stored.event_id).toBe(4);

  await page.reload();
  await expect(page.locator('[data-legacy-draft-recovery]')).toBeVisible();
  await page.locator('[data-legacy-draft-restore]').click();
  await expect(page.locator('#f_description')).toHaveValue('Recovered set description');
  await expect(page.locator('#f_artist_id')).toHaveValue('8');
  await expect(page.locator('#f_event_id')).toHaveValue('4');
});

test('Sets reports revision drift before restore', async ({page}) => {
  await loadHarness(page);
  await page.evaluate(async () => {
    const data=window.__setPayload();
    delete data.sort_order;
    data.title='Local conflicting set';
    await BRVTALDrafts.save('sets','11',{
      base_revision:'2026-09-25 10:00:00',
      data
    });
  });

  await page.reload();
  const recovery=page.locator('[data-legacy-draft-recovery]');
  await expect(recovery).toBeVisible();
  await expect(recovery).toHaveAttribute('data-conflict','1');
  await expect(recovery).toContainText('SERVER CHANGED');
  await expect(page.locator('#f_title')).toHaveValue('Industrial Signal');
});

test('Sets successful Save ignores visual sort_order metadata', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_description').fill('Server-bound set description');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  const submitted=await page.evaluate(() => window.__setPayload());

  const result=await page.evaluate(async payload => BRVTALLegacyDrafts.serverSaved({
    type:'sets',id:11,payload,result:{ok:true,changed:1}
  }),submitted);

  expect(result.keepOpen).toBe(false);
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('sets','11'))).toBe(null);
  expect(await page.evaluate(() => window.__clean)).toBeGreaterThan(0);
});

test('Sets keeps edits made after the submitted Save payload', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_description').fill('Submitted set description');
  const submitted=await page.evaluate(() => window.__setPayload());
  await page.locator('#f_description').fill('Newer local set description');

  const result=await page.evaluate(async payload => BRVTALLegacyDrafts.serverSaved({
    type:'sets',id:11,payload,result:{ok:true,changed:1}
  }),submitted);

  expect(result.keepOpen).toBe(true);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('newer edits remain unsaved');
  expect(await page.evaluate(async () => (await BRVTALDrafts.load('sets','11'))?.data?.description))
    .toBe('Newer local set description');
});

test('Sets failed Save keeps a recoverable local draft', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_description').fill('Retry this failed set Save');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');

  const kept=await page.evaluate(() => BRVTALLegacyDrafts.saveFailed({type:'sets',id:11}));
  expect(kept).toBe(true);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Save failed · local draft kept');
  expect(await page.evaluate(async () => (await BRVTALDrafts.load('sets','11'))?.data?.description))
    .toBe('Retry this failed set Save');
});

test('Sets drafts are removed at the session boundary', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_description').fill('Session-bound set draft');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:auth-required')));
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('sets','11'))).toBe(null);
});
