import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const editorDraftsJs = readFileSync(join(process.cwd(), 'discadmin/editor-drafts.js'), 'utf8');
const legacyDraftsJs = readFileSync(join(process.cwd(), 'discadmin/legacy-editor-drafts.js'), 'utf8');
const adminReliabilityJs = readFileSync(join(process.cwd(), 'discadmin/admin-reliability.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/page-drafts-e2e.html';

const seedPage = {
  id:9,
  title:'About BRVTAL',
  slug:'about',
  locale:'en',
  content_json:'{"blocks":[{"type":"text","text":"Server copy"}]}',
  seo_title:'About BRVTAL',
  seo_description:'Server SEO copy',
  status:'draft',
  updated_at:'2026-09-26 20:00:00'
};

function harness(record = seedPage) {
  return `<!doctype html><html><body>
    <button data-admin-logout type="button" onclick="logout()">LOGOUT</button>
    <div class="modal open" id="modal">
      <div id="mcontent">
        <div class="form">
          <div class="section"><div class="grid2">
            <input id="f_title" value="${record.title}">
            <input id="f_slug" value="${record.slug}">
            <select id="f_locale"><option value="en" selected>English</option><option value="es">Español</option></select>
            <select id="f_status"><option value="draft" selected>Draft</option><option value="published">Published</option></select>
            <textarea id="f_content_json">${record.content_json}</textarea>
            <input id="f_seo_title" value="${record.seo_title}">
            <textarea id="f_seo_description">${record.seo_description}</textarea>
          </div></div>
        </div>
      </div>
    </div>
    <script>
      window.csrf='page-drafts-csrf';
      window.state={authed:true};
      window.render=()=>{};
      window.go=async()=>true;
      window.req=async()=>({ok:true});
      window.logout=async()=>true;
      window.__touches=0;
      window.__clean=0;
      window.BRVTALUnsavedChanges={
        touch(){window.__touches+=1},
        markClean(){window.__clean+=1}
      };
    </script>
    <script>${editorDraftsJs}</script>
    <script>${adminReliabilityJs}</script>
    <script>${legacyDraftsJs}</script>
    <script>
      window.__pageRecord=${JSON.stringify(record)};
      window.__pagePayload=()=>({
        title:document.getElementById('f_title').value,
        slug:document.getElementById('f_slug').value,
        locale:document.getElementById('f_locale').value || 'en',
        content_json:document.getElementById('f_content_json').value,
        seo_title:document.getElementById('f_seo_title').value,
        seo_description:document.getElementById('f_seo_description').value,
        status:document.getElementById('f_status').value || 'draft'
      });
      void window.BRVTALLegacyDrafts.bind('pages',9,window.__pageRecord);
    </script>
  </body></html>`;
}

async function loadHarness(page) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html',
    body:harness()
  }));
  await page.goto(harnessUrl);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Saved to server');
}

test('Pages autosaves locally and restores after reload without a server mutation', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_seo_description').fill('Recovered Pages SEO copy.');

  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  expect(await page.evaluate(async () =>
    (await BRVTALDrafts.load('pages','9'))?.data?.seo_description
  )).toBe('Recovered Pages SEO copy.');

  await page.reload();
  const recovery = page.locator('[data-legacy-draft-recovery]');
  await expect(recovery).toBeVisible();
  await page.locator('[data-legacy-draft-restore]').click();

  await expect(page.locator('#f_seo_description')).toHaveValue('Recovered Pages SEO copy.');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Unsaved');
  expect(await page.evaluate(() => window.__touches)).toBeGreaterThan(0);
});

test('Pages reports revision drift before restoring a local draft', async ({page}) => {
  await loadHarness(page);
  await page.evaluate(async () => {
    const data=window.__pagePayload();
    data.title='Conflicting local page';
    await BRVTALDrafts.save('pages','9',{
      base_revision:'2026-09-25 10:00:00',
      data
    });
  });

  await page.reload();
  const recovery = page.locator('[data-legacy-draft-recovery]');
  await expect(recovery).toBeVisible();
  await expect(recovery).toHaveAttribute('data-conflict','1');
  await expect(recovery).toContainText('SERVER CHANGED');
  await expect(page.locator('#f_title')).toHaveValue('About BRVTAL');

  await page.locator('[data-legacy-draft-restore]').click();
  await expect(page.locator('#f_title')).toHaveValue('Conflicting local page');
});

test('Pages keeps edits made after the submitted Save payload', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_title').fill('Submitted page title');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  const submitted = await page.evaluate(() => window.__pagePayload());

  await page.locator('#f_title').fill('Newer local page title');
  const result = await page.evaluate(async payload =>
    BRVTALLegacyDrafts.serverSaved({
      type:'pages',
      id:9,
      payload,
      result:{ok:true,changed:1}
    }), submitted
  );

  expect(result.keepOpen).toBe(true);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('newer edits remain unsaved');
  expect(await page.evaluate(async () =>
    (await BRVTALDrafts.load('pages','9'))?.data?.title
  )).toBe('Newer local page title');

  const current = await page.evaluate(() => window.__pagePayload());
  const clean = await page.evaluate(async payload =>
    BRVTALLegacyDrafts.serverSaved({
      type:'pages',
      id:9,
      payload,
      result:{ok:true,changed:1}
    }), current
  );
  expect(clean.keepOpen).toBe(false);
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('pages','9'))).toBe(null);
  expect(await page.evaluate(() => window.__clean)).toBeGreaterThan(0);
});

test('Pages save failure is honest when local storage is unavailable', async ({page}) => {
  await loadHarness(page);
  await page.evaluate(() => {
    Storage.prototype.__brvtalOriginalSetItem = Storage.prototype.setItem;
    Storage.prototype.setItem = () => {
      throw new DOMException('Quota exceeded','QuotaExceededError');
    };
  });

  await page.locator('#f_title').fill('Visible unsaved page title');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Save failed');
  await page.evaluate(() => BRVTALLegacyDrafts.saveFailed({type:'pages',id:9}));
  await expect(page.locator('[data-legacy-draft-state]'))
    .toContainText('latest changes not stored locally; keep this editor open');
  await expect(page.locator('#f_title')).toHaveValue('Visible unsaved page title');
});

test('Pages drafts are removed at the session boundary', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_title').fill('Session-bound page draft');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');

  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:auth-required')));
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('pages','9'))).toBe(null);
});

test('shell logout purges all editorial draft keys through the active reliability handler', async ({page}) => {
  await loadHarness(page);
  await page.locator('#f_title').fill('Draft before explicit logout');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  await page.evaluate(() => localStorage.setItem('brvtal.discadmin.draft.v1:stale:pages:77','{}'));

  await page.locator('[data-admin-logout]').click();

  await expect.poll(() => page.evaluate(() =>
    Object.keys(localStorage).filter(key => key.startsWith('brvtal.discadmin.draft.v1:')).length
  )).toBe(0);
  expect(await page.evaluate(() => window.state.authed)).toBe(false);
});

test('auth expiry invalidates a draft save before its storage write commits', async ({page}) => {
  await loadHarness(page);
  await page.evaluate(() => {
    const originalDigest = crypto.subtle.digest.bind(crypto.subtle);
    crypto.subtle.digest = async (...args) => {
      await new Promise(resolve => setTimeout(resolve, 120));
      return originalDigest(...args);
    };
    BRVTALDrafts.clearAll();
  });

  await page.locator('#f_title').fill('Race against auth expiry');
  await page.waitForTimeout(660);
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:auth-required')));

  await page.waitForTimeout(180);
  const keys = await page.evaluate(() =>
    Object.keys(localStorage).filter(key => key.startsWith('brvtal.discadmin.draft.v1:'))
  );
  expect(keys).toEqual([]);
});

