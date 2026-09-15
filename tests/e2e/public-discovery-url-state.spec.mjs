import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const urlStateJs = readFileSync(join(process.cwd(), 'js/public-discovery-url-state.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/public-discovery-url-state.html';

function body() {
  return `<!doctype html><html><body>
    <section id="eventArchive">
      <button data-archive-filter="all" class="active">ALL YEARS</button>
      <button data-archive-filter="2025">2025</button>
      <button data-archive-filter="2026">2026</button>
      <button data-archive-relation="all" class="active">ALL RECORDS</button>
      <button data-archive-relation="artists">WITH ARTISTS</button>
      <button data-archive-relation="sets">WITH SETS</button>
      <input data-archive-search>
      <button data-archive-reset>CLEAR ARCHIVE</button>
    </section>
    <section id="network">
      <button data-related-mode="artists" class="active" aria-selected="true">ARTISTS</button>
      <button data-related-mode="events" aria-selected="false">EVENTS</button>
      <button data-related-mode="sets" aria-selected="false">SETS</button>
      <button data-related-mode="releases" aria-selected="false">RELEASES</button>
      <button data-related-select data-related-type="artists" data-related-id="11" class="active">ARTIST 11</button>
      <button data-related-select data-related-type="artists" data-related-id="12">ARTIST 12</button>
      <button data-related-select data-related-type="events" data-related-id="22">EVENT 22</button>
      <button data-related-select data-related-type="sets" data-related-id="33">SET 33</button>
      <button data-related-select data-related-type="releases" data-related-id="44">RELEASE 44</button>
    </section>
    <section id="media">
      <button data-public-media-type="all" class="active">ALL</button>
      <button data-public-media-type="image">IMAGES</button>
      <button data-public-media-type="video">VIDEO</button>
      <button data-public-media-type="audio">AUDIO</button>
      <input data-public-media-search>
      <button data-public-media-reset>CLEAR MEDIA</button>
    </section>
    <script>
      document.querySelectorAll('[data-archive-filter]').forEach(button => button.addEventListener('click', () => {
        document.querySelectorAll('[data-archive-filter]').forEach(item => item.classList.toggle('active', item === button));
      }));
      document.querySelectorAll('[data-archive-relation]').forEach(button => button.addEventListener('click', () => {
        document.querySelectorAll('[data-archive-relation]').forEach(item => item.classList.toggle('active', item === button));
      }));
      document.querySelectorAll('[data-public-media-type]').forEach(button => button.addEventListener('click', () => {
        document.querySelectorAll('[data-public-media-type]').forEach(item => item.classList.toggle('active', item === button));
      }));
      document.querySelector('[data-archive-reset]').addEventListener('click', () => {
        document.querySelector('[data-archive-filter="all"]').click();
        document.querySelector('[data-archive-relation="all"]').click();
        const input = document.querySelector('[data-archive-search]'); input.value = ''; input.dispatchEvent(new Event('input',{bubbles:true}));
      });
      document.querySelector('[data-public-media-reset]').addEventListener('click', () => {
        document.querySelector('[data-public-media-type="all"]').click();
        const input = document.querySelector('[data-public-media-search]'); input.value = ''; input.dispatchEvent(new Event('input',{bubbles:true}));
      });

      function selectNetwork(type, id) {
        const target = [...document.querySelectorAll('[data-related-select]')]
          .find(button => button.dataset.relatedType === type && Number(button.dataset.relatedId) === Number(id));
        if (!target) return false;
        document.querySelectorAll('[data-related-mode]').forEach(button => {
          const active = button.dataset.relatedMode === type;
          button.classList.toggle('active', active);
          button.setAttribute('aria-selected', String(active));
        });
        document.querySelectorAll('[data-related-select]').forEach(button => {
          button.classList.toggle('active', button === target);
        });
        return true;
      }
      document.querySelectorAll('[data-related-mode]').forEach(button => button.addEventListener('click', () => {
        const type = button.dataset.relatedMode;
        const first = document.querySelector('[data-related-select][data-related-type="' + type + '"]');
        if (first) selectNetwork(type, Number(first.dataset.relatedId));
      }));
      document.querySelectorAll('[data-related-select]').forEach(button => button.addEventListener('click', () => {
        selectNetwork(button.dataset.relatedType, Number(button.dataset.relatedId));
      }));
      window.BRVTALRelatedContent = { select: selectNetwork };
    </script>
    <script>${urlStateJs}</script>
  </body></html>`;
}

test('public discovery filters restore from URL and stay shareable', async ({ page }) => {
  await page.route('**/public-discovery-url-state.html*', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: body()
  }));

  await page.goto(`${harness}?archive_year=2025&archive_relation=sets&archive_q=old%20signal&media_type=video&media_q=warehouse&network_type=events&network_id=22#events`);
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:public-data', { detail: {} })));

  await expect(page.locator('[data-archive-filter="2025"]')).toHaveClass(/active/);
  await expect(page.locator('[data-archive-relation="sets"]')).toHaveClass(/active/);
  await expect(page.locator('[data-archive-search]')).toHaveValue('old signal');
  await expect(page.locator('[data-public-media-type="video"]')).toHaveClass(/active/);
  await expect(page.locator('[data-public-media-search]')).toHaveValue('warehouse');
  await expect(page.locator('[data-related-mode="events"]')).toHaveClass(/active/);
  await expect(page.locator('[data-related-select][data-related-id="22"]')).toHaveClass(/active/);
  expect(new URL(page.url()).hash).toBe('#events');

  await page.locator('[data-archive-filter="2026"]').click();
  await expect.poll(() => new URL(page.url()).searchParams.get('archive_year')).toBe('2026');
  expect(new URL(page.url()).searchParams.get('archive_relation')).toBe('sets');
  expect(new URL(page.url()).searchParams.get('network_type')).toBe('events');
  expect(new URL(page.url()).searchParams.get('network_id')).toBe('22');

  await page.locator('[data-public-media-type="audio"]').click();
  await expect.poll(() => new URL(page.url()).searchParams.get('media_type')).toBe('audio');

  await page.locator('[data-archive-search]').fill('Pereira');
  await expect.poll(() => new URL(page.url()).searchParams.get('archive_q')).toBe('Pereira');

  await page.locator('[data-archive-reset]').click();
  await expect.poll(() => new URL(page.url()).searchParams.has('archive_year')).toBe(false);
  expect(new URL(page.url()).searchParams.has('archive_relation')).toBe(false);
  expect(new URL(page.url()).searchParams.has('archive_q')).toBe(false);
  expect(new URL(page.url()).searchParams.get('network_type')).toBe('events');
  expect(new URL(page.url()).searchParams.get('network_id')).toBe('22');
  expect(new URL(page.url()).hash).toBe('#events');
});

test('invalid discovery URL state canonicalizes to the visible fallbacks', async ({ page }) => {
  await page.route('**/public-discovery-url-state.html*', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: body()
  }));

  await page.goto(`${harness}?archive_year=2099&network_type=artists&network_id=999999#network`);
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:public-data', { detail: {} })));

  await expect(page.locator('[data-archive-filter="all"]')).toHaveClass(/active/);
  await expect(page.locator('[data-related-mode="artists"]')).toHaveClass(/active/);
  await expect(page.locator('[data-related-select][data-related-id="11"]')).toHaveClass(/active/);
  await expect.poll(() => new URL(page.url()).searchParams.has('archive_year')).toBe(false);
  expect(new URL(page.url()).searchParams.get('network_type')).toBe('artists');
  expect(new URL(page.url()).searchParams.get('network_id')).toBe('11');
  expect(new URL(page.url()).hash).toBe('#network');
});

test('canonicalization on popstate replaces invalid state without adding another history entry', async ({ page }) => {
  await page.route('**/public-discovery-url-state.html*', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: body()
  }));

  await page.goto(`${harness}#network`);
  const historyLength = await page.evaluate(() => history.length);
  await page.evaluate(() => {
    history.pushState({}, '', '?archive_year=2099&network_type=artists&network_id=999999#network');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });

  await expect(page.locator('[data-archive-filter="all"]')).toHaveClass(/active/);
  await expect(page.locator('[data-related-select][data-related-id="11"]')).toHaveClass(/active/);
  await expect.poll(() => new URL(page.url()).searchParams.has('archive_year')).toBe(false);
  expect(new URL(page.url()).searchParams.get('network_type')).toBe('artists');
  expect(new URL(page.url()).searchParams.get('network_id')).toBe('11');
  expect(await page.evaluate(() => history.length)).toBe(historyLength + 1);
});

test('browser back restores discrete discovery filter state', async ({ page }) => {
  await page.route('**/public-discovery-url-state.html*', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: body()
  }));

  await page.goto(`${harness}#media`);
  await page.locator('[data-public-media-type="video"]').click();
  await expect.poll(() => new URL(page.url()).searchParams.get('media_type')).toBe('video');
  await page.locator('[data-public-media-type="audio"]').click();
  await expect.poll(() => new URL(page.url()).searchParams.get('media_type')).toBe('audio');

  await page.goBack();
  await expect(page.locator('[data-public-media-type="video"]')).toHaveClass(/active/);
  await expect.poll(() => new URL(page.url()).searchParams.get('media_type')).toBe('video');
  expect(new URL(page.url()).hash).toBe('#media');
});

test('CONNECTED selection is shareable and browser history restores the graph path', async ({ page }) => {
  await page.route('**/public-discovery-url-state.html*', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: body()
  }));

  await page.goto(`${harness}#network`);
  await page.locator('[data-related-mode="events"]').click();
  await expect.poll(() => new URL(page.url()).searchParams.get('network_type')).toBe('events');
  expect(new URL(page.url()).searchParams.get('network_id')).toBe('22');

  await page.locator('[data-related-select][data-related-id="44"]').click();
  await expect.poll(() => new URL(page.url()).searchParams.get('network_type')).toBe('releases');
  expect(new URL(page.url()).searchParams.get('network_id')).toBe('44');
  await expect(page.locator('[data-related-select][data-related-id="44"]')).toHaveClass(/active/);

  await page.goBack();
  await expect(page.locator('[data-related-mode="events"]')).toHaveClass(/active/);
  await expect(page.locator('[data-related-select][data-related-id="22"]')).toHaveClass(/active/);
  await expect.poll(() => new URL(page.url()).searchParams.get('network_type')).toBe('events');

  await page.goBack();
  await expect(page.locator('[data-related-mode="artists"]')).toHaveClass(/active/);
  await expect(page.locator('[data-related-select][data-related-id="11"]')).toHaveClass(/active/);
  expect(new URL(page.url()).searchParams.has('network_type')).toBe(false);
  expect(new URL(page.url()).searchParams.has('network_id')).toBe(false);
  expect(new URL(page.url()).hash).toBe('#network');
});
