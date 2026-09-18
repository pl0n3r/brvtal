import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const adminModulesJs = readFileSync(join(process.cwd(), 'discadmin/admin-modules.js'), 'utf8');
const mediaLibraryJs = readFileSync(join(process.cwd(), 'discadmin/media-library.js'), 'utf8');
const totpLoginJs = readFileSync(join(process.cwd(), 'discadmin/totp-login.js'), 'utf8');
const adminIaJs = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/e2e-initial-media.html';

const mediaFragment = `
<section data-admin-module="media" class="brvtal-media-library">
  <div class="media-toolbar">
    <div class="media-toolbar-main">
      <input id="media-search" class="search" type="search" placeholder="Search media…">
      <select id="media-type-filter"><option value="">ALL TYPES</option></select>
      <select id="media-month-filter"><option value="">ALL DATES</option></select>
    </div>
    <div class="media-toolbar-actions">
      <button id="media-register" type="button">REGISTER EXTERNAL</button>
      <button id="media-upload" type="button">+ UPLOAD MEDIA</button>
      <input id="media-file" type="file" hidden>
    </div>
  </div>
  <button id="media-dropzone" type="button"><strong>DROP FILES HERE</strong></button>
  <div id="media-status" role="status"></div>
  <div class="media-layout">
    <div><div id="media-summary"></div><div id="media-grid"></div></div>
    <aside id="media-inspector"><div class="media-inspector-empty">SELECT AN ASSET</div></aside>
  </div>
</section>`;

async function installRoutes(page, {loadIaAfterRestore = false} = {}) {
  await page.route('**/discadmin/e2e-initial-media.html**', route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html><head></head><body>
      <div id="app"></div>
      <script>
        var csrf = '';
        var state = { authed:false, section:'dashboard', rows:[] };
        window.__legacyRestoreCalled = false;
        window.__goCalls = [];
        function render() {
          document.getElementById('app').innerHTML = '<div class="shell"><aside class="side"><div class="nav"><button onclick="go(\\'dashboard\\')">DASHBOARD</button><button onclick="go(\\'artists\\')">ARTISTS</button><button onclick="go(\\'media\\')">MEDIA</button><div class="navgroup">TECHNICAL</div></div></aside><main class="main"><div class="top"><h1>'+String(state.section).toUpperCase()+'</h1></div><div class="toolbar"></div></main></div>';
        }
        async function req(path) {
          if (path === '/auth') return { authenticated:true, csrf:'csrf-token' };
          return { data:[] };
        }
        async function go(section) { window.__goCalls.push(section); state.section = section; render(); }
        async function tech(section) { window.__goCalls.push(section); state.section = section; render(); }
        function openModal() {}
        async function login() { return true; }
        async function restoreSession() { window.__legacyRestoreCalled = true; return false; }
        render();
      </script>
      <script>${adminModulesJs}</script>
      <script>window.BRVTALMediaPermissions.repair=async()=>({ok:true});</script>
      <script>${totpLoginJs}</script>
      <script>window.__restorePromise = window.restoreSession();</script>
      ${loadIaAfterRestore ? `<script>${adminIaJs}</script>` : ''}
    </body></html>`
  }));

  await page.route('**/discadmin/media-library.js**', route => route.fulfill({
    contentType: 'application/javascript', body: mediaLibraryJs
  }));
  await page.route('**/discadmin/releases.js**', route => route.fulfill({
    contentType: 'application/javascript', body: 'window.BRVTALReleases={mount(){}};'
  }));
  await page.route('**/discadmin/blog.js**', route => route.fulfill({
    contentType: 'application/javascript', body: 'window.BRVTALBlog={mount(){}};'
  }));
  await page.route('**/discadmin/*.css**', route => route.fulfill({ contentType: 'text/css', body: '' }));
  await page.route('**/discadmin/media-library.php', route => route.fulfill({ contentType: 'text/html', body: mediaFragment }));
  await page.route('**/api/media-permissions.php', route => route.fulfill({
    contentType: 'application/json', body: JSON.stringify({ ok:true, repaired:0 })
  }));
  await page.route('**/api/media-library.php**', route => route.fulfill({
    contentType: 'application/json', body: JSON.stringify({ ok:true, data:[] })
  }));
}

test('restored session mounts Media on the first direct navigation', async ({ page }) => {
  await installRoutes(page);
  await page.goto(harnessUrl + '?module=media');
  await page.evaluate(() => window.__restorePromise);

  await expect(page.locator('[data-admin-module="media"]')).toBeVisible();
  await expect(page.locator('#media-grid')).toBeVisible();
  expect(await page.evaluate(() => window.state.section)).toBe('media');
  expect(await page.evaluate(() => window.__legacyRestoreCalled)).toBe(false);
});

test('Media dropzone uses native button semantics and keyboard activation opens the file picker', async ({ page }) => {
  await installRoutes(page);
  await page.goto(harnessUrl + '?module=media');
  await page.evaluate(() => window.__restorePromise);

  const dropzone = page.getByRole('button', { name: 'DROP FILES HERE' });
  await expect(dropzone).toBeVisible();
  await expect(dropzone).toHaveAttribute('type', 'button');
  await expect(dropzone).not.toHaveAttribute('tabindex');

  await page.evaluate(() => {
    const input = document.getElementById('media-file');
    window.__mediaFileClicks = 0;
    input.click = () => { window.__mediaFileClicks += 1; };
  });

  await dropzone.focus();
  await page.keyboard.press('Enter');
  await expect.poll(() => page.evaluate(() => window.__mediaFileClicks)).toBe(1);
});

test('fast authenticated native deep-link waits for IA and never opens Dashboard first', async ({ page }) => {
  await installRoutes(page, {loadIaAfterRestore:true});
  await page.goto(harnessUrl + '?module=artists');
  await page.evaluate(() => window.__restorePromise);

  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('artists');
  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  expect(await page.evaluate(() => window.__goCalls)).toEqual(['artists']);
  expect(new URL(page.url()).searchParams.get('module')).toBe('artists');
  expect(await page.evaluate(() => window.__legacyRestoreCalled)).toBe(false);
});
