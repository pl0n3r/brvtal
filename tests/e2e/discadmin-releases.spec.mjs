import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const releasesJs = readFileSync(join(process.cwd(), 'discadmin/releases.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/releases-e2e.html';

const artist = { id: 7, name: 'PL0N3R', slug: 'pl0n3r', photo: '/uploads/media/pl0n3r.jpg', status: 'published' };
const release = {
  id: 4,
  title: 'Industrial Signal',
  slug: 'industrial-signal',
  release_type: 'single',
  catalog_number: 'BRVTAL001',
  release_date: '2026-09-11',
  description: 'Test release',
  artwork: '/uploads/media/industrial-signal.jpg',
  spotify_url: 'https://open.spotify.com/test',
  soundcloud_url: '', bandcamp_url: '', youtube_url: '', beatport_url: '',
  status: 'published', featured: 1, sort_order: 0,
  artists: [{ artist_id: 7, name: 'PL0N3R', slug: 'pl0n3r', role: 'Primary', sort_order: 0 }]
};

async function loadHarness(page) {
  await page.route('**/api/index.php/auth', route => route.fulfill({
    contentType: 'application/json', body: JSON.stringify({ authenticated: true, csrf: 'csrf-token' })
  }));
  await page.route('**/api/index.php/artists', route => route.fulfill({
    contentType: 'application/json', body: JSON.stringify({ ok: true, data: [artist] })
  }));
  await page.route('**/api/releases.php**', async route => {
    const req = route.request();
    const url = new URL(req.url());
    if (req.method() === 'GET') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: [release] }) });
    }
    const body = req.postDataJSON?.() || JSON.parse(req.postData() || '{}');
    await page.evaluate(payload => { window.__releaseMutation = payload; }, {
      method: req.method(), id: url.searchParams.get('id'), body
    });
    return route.fulfill({
      status: req.method() === 'POST' ? 201 : 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, data: { ...release, ...body, id: Number(url.searchParams.get('id') || 9) } })
    });
  });

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html><body>
      <div class="main"><div class="top"></div><div id="admin-module-host">
        <section data-admin-module="releases" class="brvtal-releases">
          <button id="release-new" type="button">+ NEW RELEASE</button>
          <input id="release-search"><select id="release-status-filter"><option value="">ALL</option></select>
          <div id="release-status"></div><b id="release-total"></b><b id="release-published"></b><b id="release-drafts"></b><b id="release-featured"></b>
          <div id="release-grid"></div>
        </section>
      </div></div>
      <div class="modal" id="modal"><div id="notice" class="notice"></div><h2 id="mtitle"></h2><div id="mcontent"></div><button id="saveBtn">GUARDAR</button></div>
      <script>
        window.csrf = 'csrf-token';
        window.closeModal = () => document.getElementById('modal').classList.remove('open');
        window.BRVTALFeedback = { error: message => { window.__feedbackError = message; } };
        window.BRVTALMediaLibrary = { openPicker: input => {
          input.value = '/uploads/media/new-release.jpg';
          input.dispatchEvent(new Event('input',{bubbles:true}));
          input.dispatchEvent(new Event('change',{bubbles:true}));
        }};
      </script>
      <script>${releasesJs}</script>
      <script>window.BRVTALReleases.mount(document.querySelector('[data-admin-module="releases"]'));</script>
    </body></html>`
  }));
  await page.goto(harnessUrl);
}

test('releases module renders catalog and linked artists', async ({ page }) => {
  await loadHarness(page);
  await expect(page.getByText('Industrial Signal')).toBeVisible();
  await expect(page.getByText('PL0N3R')).toBeVisible();
  await expect(page.locator('#release-total')).toHaveText('1');
  await expect(page.locator('#release-published')).toHaveText('1');
});

test('new release uses Media Library artwork and persists artist relationship', async ({ page }) => {
  await loadHarness(page);
  await page.locator('#release-new').click();
  await expect(page.locator('#mtitle')).toHaveText('NEW RELEASE');

  const artistRow = page.locator('.release-artist-item', { hasText: 'PL0N3R' });
  await expect(artistRow).toHaveCount(1);
  expect(await artistRow.evaluate(element => element.tagName)).toBe('DIV');
  await expect(artistRow.locator('#release_artist_7')).toHaveAttribute('type', 'checkbox');
  await expect(artistRow.locator('label[for="release_artist_7"]')).toContainText('PL0N3R');

  await page.locator('#release_title').fill('New Signal');
  await expect(page.locator('#release_slug')).toHaveValue('new-signal');
  await page.locator('#release_catalog').fill('BRVTAL002');
  await page.locator('#release-artwork-picker').click();
  await expect(page.locator('#release_artwork')).toHaveValue('/uploads/media/new-release.jpg');
  await page.locator('[data-release-artist="7"]').check();
  await page.locator('[data-release-role="7"]').fill('Primary');
  await page.locator('#saveBtn').click();

  await expect.poll(() => page.evaluate(() => window.__releaseMutation || null)).not.toBeNull();
  const mutation = await page.evaluate(() => window.__releaseMutation);
  expect(mutation.method).toBe('POST');
  expect(mutation.body.title).toBe('New Signal');
  expect(mutation.body.slug).toBe('new-signal');
  expect(mutation.body.artwork).toBe('/uploads/media/new-release.jpg');
  expect(mutation.body.artists).toEqual([{ artist_id: 7, role: 'Primary', sort_order: 0 }]);
});

test('editing a release sends PUT to the selected release id', async ({ page }) => {
  await loadHarness(page);
  await page.getByRole('button', { name: 'EDIT' }).click();
  await expect(page.locator('#mtitle')).toHaveText('EDIT RELEASE');
  await page.locator('#release_description').fill('Updated description');
  await page.locator('#saveBtn').click();

  await expect.poll(() => page.evaluate(() => window.__releaseMutation || null)).not.toBeNull();
  const mutation = await page.evaluate(() => window.__releaseMutation);
  expect(mutation.method).toBe('PUT');
  expect(mutation.id).toBe('4');
  expect(mutation.body.description).toBe('Updated description');
});
