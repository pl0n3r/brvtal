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
    </script>
    <script>${urlStateJs}</script>
  </body></html>`;
}

test('public discovery filters restore from URL and stay shareable', async ({ page }) => {
  await page.route('**/public-discovery-url-state.html*', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: body()
  }));

  await page.goto(`${harness}?archive_year=2025&archive_relation=sets&archive_q=old%20signal&media_type=video&media_q=warehouse#events`);
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:public-data', { detail: {} })));

  await expect(page.locator('[data-archive-filter="2025"]')).toHaveClass(/active/);
  await expect(page.locator('[data-archive-relation="sets"]')).toHaveClass(/active/);
  await expect(page.locator('[data-archive-search]')).toHaveValue('old signal');
  await expect(page.locator('[data-public-media-type="video"]')).toHaveClass(/active/);
  await expect(page.locator('[data-public-media-search]')).toHaveValue('warehouse');
  expect(new URL(page.url()).hash).toBe('#events');

  await page.locator('[data-archive-filter="2026"]').click();
  await expect.poll(() => new URL(page.url()).searchParams.get('archive_year')).toBe('2026');
  expect(new URL(page.url()).searchParams.get('archive_relation')).toBe('sets');

  await page.locator('[data-public-media-type="audio"]').click();
  await expect.poll(() => new URL(page.url()).searchParams.get('media_type')).toBe('audio');

  await page.locator('[data-archive-search]').fill('Pereira');
  await expect.poll(() => new URL(page.url()).searchParams.get('archive_q')).toBe('Pereira');

  await page.locator('[data-archive-reset]').click();
  await expect.poll(() => new URL(page.url()).searchParams.has('archive_year')).toBe(false);
  expect(new URL(page.url()).searchParams.has('archive_relation')).toBe(false);
  expect(new URL(page.url()).searchParams.has('archive_q')).toBe(false);
  expect(new URL(page.url()).hash).toBe('#events');
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
