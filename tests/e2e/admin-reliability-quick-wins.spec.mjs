import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const authBoundary = readFileSync(join(process.cwd(), 'discadmin/admin-auth-boundary.js'), 'utf8');
const heroAccessibility = readFileSync(join(process.cwd(), 'discadmin/hero-slider-accessibility.js'), 'utf8');
const storageStatus = readFileSync(join(process.cwd(), 'discadmin/system-status-storage.js'), 'utf8');
const backups = readFileSync(join(process.cwd(), 'discadmin/backups.js'), 'utf8');

test('same-origin admin 401 returns the shell to login state', async ({ page }) => {
  await page.route('https://www.brvtal.test/**', async route => {
    const url = route.request().url();
    if (url.includes('/api/media-library.php')) {
      await route.fulfill({status:401, contentType:'application/json', body:'{"ok":false,"error":"AUTH_REQUIRED"}'});
      return;
    }
    await route.fulfill({status:200, contentType:'text/html', body:`<!doctype html><html><body><script>
      window.csrf = 'csrf-token';
      window.state = {authed:true};
      var renderCount = 0;
      var closeCount = 0;
      var authEvents = 0;
      function render(){ renderCount += 1; }
      function closeModal(){ closeCount += 1; }
      addEventListener('brvtal:auth-required', () => authEvents += 1);
    </script></body></html>`});
  });

  await page.goto('https://www.brvtal.test/discadmin');
  await page.addScriptTag({content: authBoundary});
  const result = await page.evaluate(async () => {
    const response = await fetch('/api/media-library.php');
    return {status:response.status, authed:window.state.authed, csrf:window.csrf, renderCount, closeCount, authEvents};
  });

  expect(result).toEqual({status:401, authed:false, csrf:'', renderCount:1, closeCount:1, authEvents:1});
});

test('Hero Slider rows expose and execute keyboard reorder shortcuts', async ({ page }) => {
  await page.setContent(`<!doctype html><html><body>
    <button type="button" class="hero-slide-row" data-select-slide="slide-1">
      <span>Slide one</span>
      <span><i data-move="up"></i><i data-move="down"></i></span>
    </button>
    <script>
      window.moves = [];
      document.querySelector('[data-move="up"]').addEventListener('click', () => moves.push('up'));
      document.querySelector('[data-move="down"]').addEventListener('click', () => moves.push('down'));
    </script>
  </body></html>`);
  await page.addScriptTag({content: heroAccessibility});

  const row = page.locator('.hero-slide-row');
  await expect(row).toHaveAttribute('aria-keyshortcuts', 'Alt+ArrowUp Alt+ArrowDown');
  await row.focus();
  await page.keyboard.press('Alt+ArrowUp');
  await page.keyboard.press('Alt+ArrowDown');
  expect(await page.evaluate(() => moves)).toEqual(['up','down']);
});

test('managed storage failure replaces host capacity with an explicit unavailable state', async ({ page }) => {
  await page.setContent(`<!doctype html><html><body>
    <div id="system-status-v2">
      <section class="ssv2-panel storage">
        <div class="ssv2-panel-head"><b>999 GB HOST</b></div>
        <div class="ssv2-storage-ring"><strong>75%</strong></div>
        <div class="ssv2-storage-copy"><strong>999 GB / 1 TB</strong><span>250 GB FREE</span><small>host filesystem</small></div>
      </section>
    </div>
    <script>window.fetch = async () => new Response('{}', {status:500, headers:{'Content-Type':'application/json'}});</script>
  </body></html>`);
  await page.addScriptTag({content: storageStatus});
  await page.evaluate(() => window.BRVTALManagedStorageStatus.applyUnavailable());

  const panel = page.locator('.ssv2-panel.storage');
  await expect(panel).toHaveAttribute('data-storage-scope', 'unavailable');
  await expect(panel.locator('.ssv2-panel-head b')).toHaveText('UNAVAILABLE · BRVTAL DATA');
  await expect(panel.locator('.ssv2-storage-copy strong')).toHaveText('MANAGED STORAGE UNAVAILABLE');
  await expect(panel).not.toContainText('999 GB');
});

test('Backups renders the full retained collection instead of truncating after eight', async ({ page }) => {
  await page.setContent('<!doctype html><html><body><div id="target"></div></body></html>');
  await page.addScriptTag({content: backups});
  const count = await page.evaluate(() => {
    const items = Array.from({length:10}, (_, index) => ({
      id:`backup-${index + 1}`,
      status:'complete',
      created_at:'2026-09-16T00:00:00Z',
      artifacts_size:'1 MB',
      deployment:{short_commit:'abc1234'},
      components:{}
    }));
    document.getElementById('target').innerHTML = window.BRVTALBackupsUI.backupRows(items);
    return document.querySelectorAll('.backup-row').length;
  });
  expect(count).toBe(10);
});
