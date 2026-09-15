import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const blogJs = readFileSync(join(process.cwd(), 'discadmin/blog.js'), 'utf8');
const mediaLibraryJs = readFileSync(join(process.cwd(), 'discadmin/media-library.js'), 'utf8');
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
  tags: [{ id: 1, name: 'Hard Techno', slug: 'hard-techno' }],
  relations: [{ related_type: 'artist', related_id: 7, sort_order: 0 }]
};

async function mockApi(page, options = {}) {
  const failedRelated = new Set(options.failedRelated || []);
  const emptyRelated = new Set(options.emptyRelated || []);
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
    await page.evaluate(({ method, body }) => { window.__blogMutation = { method, body }; }, { method: req.method(), body });
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: { ...seedPost, ...body, id: id ? Number(id) : 10 } }) });
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

test('new blog post uses Media Library, tags and related content', async ({ page }) => {
  await loadHarness(page);
  await page.locator('#blog-new').click();
  await expect(page.locator('#mtitle')).toHaveText('NEW BLOG POST');

  await page.locator('#blog_title').fill('Underground Signal');
  await expect(page.locator('#blog_slug')).toHaveValue('underground-signal');
  await page.locator('#blog_excerpt').fill('A new BRVTAL editorial signal.');
  await page.locator('#blog_body').fill('Long-form editorial content.');
  await page.locator('#blog_tags').fill('Hard Techno, Culture');
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
  expect(payload.tags).toEqual(['Hard Techno','Culture']);
  expect(payload.relations).toEqual([
    { related_type: 'artist', related_id: 7, sort_order: 0 },
    { related_type: 'release', related_id: 3, sort_order: 1 }
  ]);
});

test('editing a blog post sends PUT and keeps existing taxonomy', async ({ page }) => {
  await loadHarness(page);
  await page.getByRole('button', { name: 'EDIT' }).click();
  await expect(page.locator('#blog_tags')).toHaveValue('Hard Techno');
  await expect(page.locator('[data-blog-related-type="artist"][data-blog-related-id="7"]')).toBeChecked();
  await page.locator('#blog_excerpt').fill('Updated editorial excerpt.');
  await page.locator('#saveBtn').click();

  await expect.poll(() => page.evaluate(() => window.__blogMutation?.method)).toBe('PUT');
  const payload = await page.evaluate(() => window.__blogMutation.body);
  expect(payload.excerpt).toBe('Updated editorial excerpt.');
  expect(payload.tags).toEqual(['Hard Techno']);
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
