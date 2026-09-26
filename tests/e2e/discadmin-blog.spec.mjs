import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const blogJs = readFileSync(join(process.cwd(), 'discadmin/blog.js'), 'utf8');
const mediaLibraryJs = readFileSync(join(process.cwd(), 'discadmin/media-library.js'), 'utf8');
const editorDraftsJs = readFileSync(join(process.cwd(), 'discadmin/editor-drafts.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/blog-e2e.html';

const mediaItem = {
  id: 11,
  type: 'image',
  title: 'Editorial cover',
  file_path: 'uploads/media/2026/09/editorial.jpg',
  mime_type: 'image/jpeg',
  file_size: 120000,
  alt_text: 'Editorial cover alt',
  status: 'published',
  created_at: '2026-09-11 17:00:00',
  engine: { status: 'ready', variants: {} }
};

const seedPost = {
  id: 9,
  title: 'BRVTAL Journal 001',
  slug: 'brvtal-journal-001',
  excerpt: 'An editorial story.',
  body: 'Long-form story body.',
  cover_image: '/uploads/media/2026/09/editorial.jpg',
  seo_title: 'BRVTAL Journal 001',
  seo_description: 'Editorial description',
  status: 'published',
  featured: 1,
  sort_order: 0,
  published_at: '2026-09-11 20:30:00',
  updated_at: '2026-09-11 20:31:00',
  tags: [{ id: 1, name: 'Hard Techno', slug: 'hard-techno' }],
  relations: [{ related_type: 'artist', related_id: 7, sort_order: 0 }]
};

async function mockApi(page, options = {}) {
  const failedRelated = new Set(options.failedRelated || []);
  const emptyRelated = new Set(options.emptyRelated || []);
  let mutationCount = 0;
  const relatedPayload = (type, data) => {
    if (failedRelated.has(type)) {
      return {
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ ok: false, error: `${type.toUpperCase()}_SOURCE_UNAVAILABLE` })
      };
    }
    return {
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, data: emptyRelated.has(type) ? [] : data })
    };
  };

  await page.route('**/uploads/media/**', route => route.fulfill({ status: 200, contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"></svg>' }));
  await page.route('**/api/index.php/auth', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ authenticated: true, csrf: 'csrf-token' }) }));
  await page.route('**/api/media-library.php**', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: [mediaItem] }) }));
  await page.route('**/api/index.php/events', route => route.fulfill(relatedPayload('event', [{ id: 4, title: 'Genesis', slug: 'genesis' }])));
  await page.route('**/api/index.php/artists', route => route.fulfill(relatedPayload('artist', [{ id: 7, name: 'PL0N3R', slug: 'pl0n3r' }])));
  await page.route('**/api/index.php/sets', route => route.fulfill(relatedPayload('set', [{ id: 5, title: 'BRVTAL Session', slug: 'brvtal-session' }])));
  await page.route('**/api/releases.php', route => route.fulfill(relatedPayload('release', [{ id: 3, title: 'BRVTAL001', slug: 'brvtal001' }])));
  await page.route('**/api/blog.php**', async route => {
    const req = route.request();
    const url = new URL(req.url());
    const id = url.searchParams.get('id');
    if (req.method() === 'GET') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: id ? seedPost : [seedPost] }) });
    }
    const body = req.postDataJSON?.() || JSON.parse(req.postData() || '{}');
    mutationCount += 1;
    const mutation = { method: req.method(), body, url: req.url() };
    await page.evaluate(current => {
      window.__blogMutation = current;
      window.__blogMutations = [...(window.__blogMutations || []), current];
    }, mutation);

    if (options.failMutation) {
      return route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({ok:false,error:'SAVE_FAILED'})});
    }

    const warningCreate = Boolean(options.warningOnCreate)
      && req.method() === 'POST'
      && mutationCount === 1;
    const data = {
      ...seedPost,
      ...body,
      id: id ? Number(id) : 10,
      body: warningCreate ? '<p>Server-cleaned body.</p>' : body.body
    };
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        ok: true,
        data,
        warnings: warningCreate ? ['Unsupported <script> markup was removed.'] : []
      })
    });
  });
}

async function loadHarness(page, options = {}) {
  await mockApi(page, options);
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html><body>
      <div id="blog-status"></div>
      <section data-admin-module="blog" class="brvtal-blog">
        <button id="blog-new" type="button">+ NEW POST</button>
        <span id="blog-total"></span><span id="blog-published"></span><span id="blog-drafts"></span><span id="blog-featured"></span>
        <input id="blog-search"><select id="blog-status-filter"><option value=""></option><option value="published">published</option></select>
        <div id="blog-grid"></div>
      </section>
      <div class="modal" id="modal"><div><h2 id="mtitle"></h2><div id="notice" class="notice"></div><div id="mcontent"></div><button id="saveBtn">GUARDAR</button></div></div>
      <script>window.csrf='csrf-token';window.closeModal=()=>document.getElementById('modal').classList.remove('open');window.confirm=()=>true;</script>
      <script>${mediaLibraryJs}</script>
      <script>${editorDraftsJs}</script>
      <script>${blogJs}</script>
      <script>window.BRVTALBlog.mount(document.querySelector('[data-admin-module="blog"]'));</script>
    </body></html>`
  }));
  await page.goto(harnessUrl);
}

test('blog catalog renders published editorial content', async ({ page }) => {
  await loadHarness(page);
  await expect(page.getByText('BRVTAL Journal 001')).toBeVisible();
  await expect(page.locator('#blog-total')).toHaveText('1');
  await expect(page.locator('#blog-published')).toHaveText('1');
  await expect(page.locator('#blog-featured')).toHaveText('1');
});

test('new blog post uses Media Library and related content without manual taxonomy', async ({ page }) => {
  await loadHarness(page);
  await page.locator('#blog-new').click();
  await expect(page.locator('#mtitle')).toHaveText('NEW BLOG POST');

  await page.locator('#blog_title').fill('Underground Signal');
  await expect(page.locator('#blog_slug')).toHaveValue('underground-signal');
  await page.locator('#blog_excerpt').fill('A new BRVTAL editorial signal.');
  await page.locator('[data-blog-body-visual]').fill('Long-form editorial content.');
  await expect(page.locator('#blog_tags')).toHaveCount(0);
  await expect(page.getByText('TAXONOMY', { exact: true })).toHaveCount(0);
  await page.locator('[data-blog-related-type="artist"][data-blog-related-id="7"]').check();
  await page.locator('[data-blog-related-type="release"][data-blog-related-id="3"]').check();

  await page.locator('#blog-cover-picker').click();
  await page.getByRole('button', { name: /Editorial cover/i }).click();
  await expect(page.locator('#blog_cover_image')).toHaveValue('/uploads/media/2026/09/editorial.jpg');

  await page.locator('#saveBtn').click();
  await expect.poll(() => page.evaluate(() => window.__blogMutation?.method)).toBe('POST');
  const payload = await page.evaluate(() => window.__blogMutation.body);
  expect(payload.title).toBe('Underground Signal');
  expect(payload.cover_image).toBe('/uploads/media/2026/09/editorial.jpg');
  expect(payload).not.toHaveProperty('tags');
  expect(payload.relations).toEqual([
    { related_type: 'artist', related_id: 7, sort_order: 0 },
    { related_type: 'release', related_id: 3, sort_order: 1 }
  ]);
});

test('editing a blog post leaves existing taxonomy untouched without exposing controls', async ({ page }) => {
  await loadHarness(page);
  await page.getByRole('button', { name: 'EDIT' }).click();
  await expect(page.locator('#blog_tags')).toHaveCount(0);
  await expect(page.getByText('TAXONOMY', { exact: true })).toHaveCount(0);
  await expect(page.locator('[data-blog-related-type="artist"][data-blog-related-id="7"]')).toBeChecked();
  await page.locator('#blog_excerpt').fill('Updated editorial excerpt.');
  await page.locator('#saveBtn').click();

  await expect.poll(() => page.evaluate(() => window.__blogMutation?.method)).toBe('PUT');
  const payload = await page.evaluate(() => window.__blogMutation.body);
  expect(payload.excerpt).toBe('Updated editorial excerpt.');
  expect(payload).not.toHaveProperty('tags');
});

test('editing preserves existing relations when one related source fails', async ({ page }) => {
  await loadHarness(page, { failedRelated: ['artist'] });

  await page.getByRole('button', { name: 'EDIT' }).click();
  const artists = page.locator('[data-blog-related-source="artist"]');
  await expect(artists).toHaveAttribute('data-state', 'error');
  await expect(artists).toContainText('Unable to load ARTISTS. Existing relations will be preserved.');
  await expect(artists.locator('[data-blog-related-type="artist"]')).toHaveCount(0);

  await page.locator('[data-blog-related-type="release"][data-blog-related-id="3"]').check();
  await page.locator('#blog_excerpt').fill('Safe unrelated edit.');
  await page.locator('#saveBtn').click();

  await expect.poll(() => page.evaluate(() => window.__blogMutation?.method)).toBe('PUT');
  const payload = await page.evaluate(() => window.__blogMutation.body);
  expect(payload.excerpt).toBe('Safe unrelated edit.');
  expect(payload.relations).toEqual([
    { related_type: 'artist', related_id: 7, sort_order: 0 },
    { related_type: 'release', related_id: 3, sort_order: 1 }
  ]);
});

test('successful empty related source remains an authoritative empty state', async ({ page }) => {
  await loadHarness(page, { emptyRelated: ['artist'] });

  await page.getByRole('button', { name: 'EDIT' }).click();
  const artists = page.locator('[data-blog-related-source="artist"]');
  await expect(artists).toHaveAttribute('data-state', 'ready');
  await expect(artists).toContainText('No records available.');
  await expect(artists).not.toContainText('Unable to load');
});


test('rich Blog editor round-trips source safely and reports client cleanup', async ({ page }) => {
  await loadHarness(page);
  await page.locator('#blog-new').click();

  const visual = page.locator('[data-blog-body-visual]');
  await visual.evaluate(element => {
    element.innerHTML = '<h2>Signal</h2><p>Body <strong>bold</strong>.</p>';
    element.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText' }));
  });

  await page.locator('[data-blog-body-mode="source"]').click();
  const source = page.locator('#blog_body');
  await expect(source).toBeVisible();
  await expect(source).toHaveValue(/<h2>Signal<\/h2>/);
  await expect(source).toHaveValue(/<strong>bold<\/strong>/);

  await source.fill('<div><h3>Archive</h3><p onclick="alert(1)">Safe<script>alert(2)</script></p></div>');
  await page.locator('[data-blog-body-mode="visual"]').click();

  await expect(visual.locator('h3')).toHaveText('Archive');
  await expect(visual.locator('script')).toHaveCount(0);
  await expect(visual.locator('[onclick]')).toHaveCount(0);
  await expect(page.locator('[data-blog-body-warning]')).toBeVisible();
  await expect(page.locator('[data-blog-body-warning]')).toContainText('UNSUPPORTED MARKUP DETECTED');

  await page.locator('[data-blog-body-mode="source"]').click();
  await expect(source).not.toHaveValue(/<script/i);
  await expect(source).not.toHaveValue(/onclick=/i);
  await expect(source).not.toHaveValue(/<div/i);
});

test('rich Blog paste stays plain text and Media Library inserts a canonical image', async ({ page }) => {
  await loadHarness(page);
  await page.locator('#blog-new').click();

  const visual = page.locator('[data-blog-body-visual]');
  await visual.focus();
  await visual.evaluate(element => {
    const clipboard = new DataTransfer();
    clipboard.setData('text/plain', 'Plain <b>paste</b>');
    element.dispatchEvent(new ClipboardEvent('paste', {
      bubbles: true,
      cancelable: true,
      clipboardData: clipboard
    }));
  });

  await expect(visual).toContainText('Plain <b>paste</b>');
  await expect(visual.locator('b')).toHaveCount(0);

  await page.locator('[data-blog-body-media]').click();
  await page.getByRole('button', { name: /Editorial cover/i }).click();

  const image = visual.locator('img');
  await expect(image).toHaveCount(1);
  await expect(image).toHaveAttribute('src', '/uploads/media/2026/09/editorial.jpg');
  await expect(image).toHaveAttribute('alt', 'editorial');
});

test('rich Blog preview sanitizes unsafe source without executing it', async ({ page }) => {
  await loadHarness(page);
  await page.locator('#blog-new').click();
  await page.locator('[data-blog-body-mode="source"]').click();

  const source = page.locator('#blog_body');
  await source.fill('<p>Preview<script>window.__unsafePreview=true</script><a href="javascript:alert(1)">link</a></p>');
  await page.locator('[data-blog-body-preview]').click();

  const frame = page.locator('[data-blog-body-preview-frame]');
  await expect(frame).toBeVisible();
  const srcdoc = await frame.getAttribute('srcdoc');
  expect(srcdoc).not.toMatch(/<script/i);
  expect(srcdoc).not.toMatch(/javascript:/i);
  await expect(page.locator('[data-blog-body-warning]')).toBeVisible();
  expect(await page.evaluate(() => window.__unsafePreview)).toBeUndefined();
});

test('warning-aware create save rebinds the open editor to PUT', async ({ page }) => {
  await loadHarness(page, { warningOnCreate: true });
  await page.locator('#blog-new').click();
  await page.locator('#blog_title').fill('Sanitized Signal');
  await page.locator('[data-blog-body-visual]').fill('Unsafe source cleaned by server.');

  await page.locator('#saveBtn').click();
  await expect.poll(() => page.evaluate(() => window.__blogMutations?.length || 0)).toBe(1);
  let mutations = await page.evaluate(() => window.__blogMutations);
  expect(mutations[0].method).toBe('POST');
  await expect(page.locator('[data-blog-body-warning]')).toContainText('SAVED WITH CLEANUP');
  await expect(page.locator('#modal')).toHaveClass(/open/);

  await page.locator('#saveBtn').click();
  await expect.poll(() => page.evaluate(() => window.__blogMutations?.length || 0)).toBe(2);
  mutations = await page.evaluate(() => window.__blogMutations);
  expect(mutations.map(item => item.method)).toEqual(['POST', 'PUT']);
  expect(mutations[1].url).toContain('id=10');
  await expect(page.locator('#modal')).not.toHaveClass(/open/);
});


test('blog autosaves a local draft without a server mutation and restores after reload', async ({ page }) => {
  await loadHarness(page);
  await page.getByRole('button', { name: 'EDIT' }).click();
  await page.locator('#blog_excerpt').fill('Recovered local draft.');

  await expect(page.locator('#blog-draft-state')).toContainText('Draft saved locally');
  expect(await page.evaluate(() => window.__blogMutations?.length || 0)).toBe(0);
  expect(await page.evaluate(() => await BRVTALDrafts.load('blog','9')?.data?.excerpt)).toBe('Recovered local draft.');

  await page.reload();
  await page.getByRole('button', { name: 'EDIT' }).click();
  await expect(page.locator('#blog-draft-recovery')).toBeVisible();
  await page.locator('[data-blog-draft-restore]').click();

  await expect(page.locator('#blog_excerpt')).toHaveValue('Recovered local draft.');
  await expect(page.locator('#blog-draft-state')).toContainText('Unsaved');
  expect(await page.evaluate(() => window.__blogMutations?.length || 0)).toBe(0);
});

test('blog recovery reports a server revision conflict before restoring', async ({ page }) => {
  await loadHarness(page);
  await page.evaluate(async () => {
    return BRVTALDrafts.save('blog','9',{
      base_revision:'2026-09-10 10:00:00',
      data:{
        title:'Local conflicting title',
        slug:'local-conflicting-title',
        excerpt:'Local copy',
        body:'<p>Local body</p>',
        cover_image:'',
        seo_title:'',
        seo_description:'',
        status:'draft',
        featured:0,
        relations:[]
      }
    });
  });

  await page.getByRole('button', { name: 'EDIT' }).click();
  const recovery = page.locator('#blog-draft-recovery');
  await expect(recovery).toBeVisible();
  await expect(recovery).toHaveAttribute('data-conflict','1');
  await expect(recovery).toContainText('SERVER CHANGED');
  await expect(page.locator('#blog_title')).toHaveValue('BRVTAL Journal 001');

  await page.locator('[data-blog-draft-restore]').click();
  await expect(page.locator('#blog_title')).toHaveValue('Local conflicting title');
  expect(await page.evaluate(() => window.__blogMutations?.length || 0)).toBe(0);
});

test('manual Blog save clears a local draft while failed save keeps it recoverable', async ({ page }) => {
  await loadHarness(page);
  await page.getByRole('button', { name: 'EDIT' }).click();
  await page.locator('#blog_excerpt').fill('Saved server copy.');
  await expect(page.locator('#blog-draft-state')).toContainText('Draft saved locally');
  await page.locator('#saveBtn').click();
  await expect.poll(() => page.evaluate(() => window.__blogMutations?.length || 0)).toBe(1);
  expect(await page.evaluate(() => await BRVTALDrafts.load('blog','9'))).toBe(null);

  await page.reload();
  await page.unroute('**/api/blog.php**');
  await mockApi(page,{failMutation:true});
  await page.getByRole('button', { name: 'EDIT' }).click();
  await page.locator('#blog_excerpt').fill('Keep after failed save.');
  await expect(page.locator('#blog-draft-state')).toContainText('Draft saved locally');
  await page.locator('#saveBtn').click();
  await expect(page.locator('#blog-draft-state')).toContainText('Save failed');
  expect(await page.evaluate(() => await BRVTALDrafts.load('blog','9')?.data?.excerpt)).toBe('Keep after failed save.');
  await expect(page.locator('#modal')).toHaveClass(/open/);
});


test('Blog draft autosave surfaces local storage failure without losing visible input', async ({ page }) => {
  await loadHarness(page);
  await page.getByRole('button', { name: 'EDIT' }).click();
  await page.evaluate(() => {
    Storage.prototype.__brvtalOriginalSetItem = Storage.prototype.setItem;
    Storage.prototype.setItem = () => { throw new DOMException('Quota exceeded','QuotaExceededError'); };
  });

  await page.locator('#blog_excerpt').fill('Still visible after quota error.');
  await expect(page.locator('#blog-draft-state')).toContainText('Save failed');
  await expect(page.locator('#blog_excerpt')).toHaveValue('Still visible after quota error.');
  expect(await page.evaluate(() => window.__blogMutations?.length || 0)).toBe(0);
});
