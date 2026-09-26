import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const editorDraftsJs = readFileSync(join(process.cwd(), 'discadmin/editor-drafts.js'), 'utf8');
const legacyDraftsJs = readFileSync(join(process.cwd(), 'discadmin/legacy-editor-drafts.js'), 'utf8');
const releasesJs = readFileSync(join(process.cwd(), 'discadmin/releases.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/release-drafts-e2e.html';

const seedRelease = {
  id:4,
  title:'Industrial Signal',
  slug:'industrial-signal',
  release_type:'single',
  catalog_number:'BRVTAL001',
  release_date:'2026-09-11',
  description:'Server release description',
  artwork:'/uploads/media/release.jpg',
  spotify_url:'https://open.spotify.com/track/test',
  soundcloud_url:'https://soundcloud.com/brvtal/test',
  bandcamp_url:'https://brvtal.bandcamp.com/test',
  youtube_url:'https://youtube.com/watch?v=test',
  beatport_url:'https://beatport.com/release/test',
  status:'published',
  featured:1,
  sort_order:2,
  artists:[{artist_id:7,role:'Primary',sort_order:0}],
  updated_at:'2026-09-26 21:30:00'
};

function harness(record = seedRelease) {
  return `<!doctype html><html><body>
    <div class="modal open" id="modal"><div id="mcontent"><div class="form">
      <div id="releaseFields">
        <input id="release_title" value="${record.title}">
        <input id="release_slug" value="${record.slug}">
        <select id="release_type"><option value="single" selected>Single</option><option value="ep">EP</option></select>
        <input id="release_catalog" value="${record.catalog_number}">
        <input id="release_date" value="${record.release_date}">
        <select id="release_status_field"><option value="draft">Draft</option><option value="published" selected>Published</option></select>
        <input id="release_artwork" value="${record.artwork}">
        <textarea id="release_description">${record.description}</textarea>
        <input id="release_featured" type="checkbox" checked>
        <input id="release_spotify" value="${record.spotify_url}">
        <input id="release_soundcloud" value="${record.soundcloud_url}">
        <input id="release_bandcamp" value="${record.bandcamp_url}">
        <input id="release_youtube" value="${record.youtube_url}">
        <input id="release_beatport" value="${record.beatport_url}">
        <input data-release-artist="7" type="checkbox" checked>
        <input data-release-role="7" value="Primary">
        <input data-release-artist="8" type="checkbox">
        <input data-release-role="8" value="Remixer">
      </div>
    </div></div></div>
    <button id="saveBtn">GUARDAR</button>
    <script>window.__touches=0;window.__clean=0;window.csrf='release-drafts-csrf';
      window.BRVTALUnsavedChanges={touch(){window.__touches+=1},markClean(){window.__clean+=1}};
      window.BRVTALMediaLibrary={openPicker(){}};
      window.BRVTALPublicPreview={bindButton(){}};
      window.BRVTALFeedback={error(){}};
      window.closeModal=()=>{window.__closed=(window.__closed||0)+1};
    </script>
    <script>${editorDraftsJs}</script>
    <script>${legacyDraftsJs}</script>
    <script>${releasesJs}</script>
    <script>
      window.__releaseRecord=${JSON.stringify(record)};
      window.__releasePayload=()=>({
        title:document.getElementById('release_title').value,
        slug:document.getElementById('release_slug').value,
        release_type:document.getElementById('release_type').value,
        catalog_number:document.getElementById('release_catalog').value,
        release_date:document.getElementById('release_date').value,
        description:document.getElementById('release_description').value,
        artwork:document.getElementById('release_artwork').value,
        spotify_url:document.getElementById('release_spotify').value,
        soundcloud_url:document.getElementById('release_soundcloud').value,
        bandcamp_url:document.getElementById('release_bandcamp').value,
        youtube_url:document.getElementById('release_youtube').value,
        beatport_url:document.getElementById('release_beatport').value,
        status:document.getElementById('release_status_field').value,
        featured:document.getElementById('release_featured').checked ? 1 : 0,
        sort_order:2,
        artists:[{artist_id:7,role:document.querySelector('[data-release-role="7"]').value,sort_order:0}]
      });
      void BRVTALLegacyDrafts.bind('releases',4,window.__releaseRecord);
    </script>
  </body></html>`;
}

async function loadHarness(page) {
  await page.route(harnessUrl, route => route.fulfill({contentType:'text/html',body:harness()}));
  await page.route('**/api/releases.php*', async route => {
    const method=route.request().method();
    if (method==='GET') return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[seedRelease]})});
    if (method==='PUT') return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{...seedRelease,updated_at:'2026-09-26 21:40:00'}})});
    if (method==='POST') return route.fulfill({status:201,contentType:'application/json',body:JSON.stringify({ok:true,data:{...seedRelease,id:9,updated_at:'2026-09-26 21:40:00'}})});
    return route.fulfill({status:404,contentType:'application/json',body:JSON.stringify({ok:false,error:'UNEXPECTED_ROUTE'})});
  });
  await page.route('**/api/index.php/artists',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[{id:7,name:'PL0N3R'},{id:8,name:'OTHER'}]})}));
  await page.goto(harnessUrl);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Saved to server');
}

test('Releases autosaves and restores editorial + linked artist state', async ({page}) => {
  await loadHarness(page);
  await page.locator('#release_description').fill('Recovered release description');
  await page.locator('[data-release-role="7"]').fill('Remixer');
  await page.locator('[data-release-artist="8"]').check();

  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  const stored=await page.evaluate(async () => (await BRVTALDrafts.load('releases','4'))?.data);
  expect(stored.description).toBe('Recovered release description');
  expect(stored.artists).toEqual([
    {artist_id:7,role:'Remixer',sort_order:0},
    {artist_id:8,role:'Remixer',sort_order:1}
  ]);

  await page.reload();
  await expect(page.locator('[data-legacy-draft-recovery]')).toBeVisible();
  await page.locator('[data-legacy-draft-restore]').click();
  await expect(page.locator('#release_description')).toHaveValue('Recovered release description');
  await expect(page.locator('[data-release-role="7"]')).toHaveValue('Remixer');
  await expect(page.locator('[data-release-artist="8"]')).toBeChecked();
});

test('Releases reports revision drift before restore', async ({page}) => {
  await loadHarness(page);
  await page.evaluate(async () => {
    const data=window.__releasePayload();
    delete data.sort_order;
    await BRVTALDrafts.save('releases','4',{base_revision:'2026-09-25 10:00:00',data});
  });
  await page.reload();
  const recovery=page.locator('[data-legacy-draft-recovery]');
  await expect(recovery).toBeVisible();
  await expect(recovery).toHaveAttribute('data-conflict','1');
  await expect(recovery).toContainText('SERVER CHANGED');
});

test('Releases successful Save clears the matching draft and advances revision', async ({page}) => {
  await loadHarness(page);
  await page.locator('#release_description').fill('Server-bound release description');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');

  const result=await page.evaluate(() => window.BRVTALLegacyDrafts.serverSaved({
    type:'releases',
    id:4,
    payload:window.__releasePayload(),
    result:{ok:true,data:{id:4,updated_at:'2026-09-26 21:40:00'}}
  }));
  expect(result.keepOpen).toBe(false);
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('releases','4'))).toBe(null);
  expect(await page.evaluate(() => window.__clean)).toBeGreaterThan(0);
});

test('Releases Save race preserves newer local edits under the new server revision', async ({page}) => {
  await loadHarness(page);
  await page.locator('#release_description').fill('Submitted release description');
  const submitted=await page.evaluate(() => window.__releasePayload());
  await page.locator('#release_description').fill('Newer local release description');

  const result=await page.evaluate(async payload => window.BRVTALLegacyDrafts.serverSaved({
    type:'releases',
    id:4,
    payload,
    result:{ok:true,data:{id:4,updated_at:'2026-09-26 21:50:00'}}
  }),submitted);

  expect(result.keepOpen).toBe(true);
  const stored=await page.evaluate(async () => BRVTALDrafts.load('releases','4'));
  expect(stored.base_revision).toBe('2026-09-26 21:50:00');
  expect(stored.data.description).toBe('Newer local release description');
});

test('Releases failed Save keeps a recoverable draft', async ({page}) => {
  await loadHarness(page);
  await page.locator('#release_description').fill('Retry release Save');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  expect(await page.evaluate(() => BRVTALLegacyDrafts.saveFailed({type:'releases',id:4}))).toBe(true);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Save failed · local draft kept');
  expect(await page.evaluate(async () => (await BRVTALDrafts.load('releases','4'))?.data?.description))
    .toBe('Retry release Save');
});

test('New Release save race moves recovery from new identity to returned server ID', async ({page}) => {
  await loadHarness(page);
  await page.evaluate(async () => {
    await BRVTALDrafts.clearAll();
    await BRVTALLegacyDrafts.bind('releases',null,{});
  });
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Unsaved new release');

  await page.locator('#release_description').fill('Submitted new release');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  const submitted=await page.evaluate(() => BRVTALLegacyDrafts.snapshot('releases'));
  await page.locator('#release_description').fill('Newer new-release edit');

  const result=await page.evaluate(async payload => BRVTALLegacyDrafts.serverSaved({
    type:'releases',
    id:null,
    payload,
    result:{ok:true,data:{id:9,updated_at:'2026-09-26 22:00:00'}}
  }),submitted);

  expect(result).toEqual({keepOpen:true,id:9});
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('releases','new'))).toBe(null);
  const moved=await page.evaluate(async () => BRVTALDrafts.load('releases','9'));
  expect(moved.base_revision).toBe('2026-09-26 22:00:00');
  expect(moved.data.description).toBe('Newer new-release edit');
});

test('Releases drafts are removed at the session boundary', async ({page}) => {
  await loadHarness(page);
  await page.locator('#release_description').fill('Session-bound release draft');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:auth-required')));
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('releases','4'))).toBe(null);
});
