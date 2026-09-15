import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const accessibilitySource = readFileSync(join(process.cwd(), 'discadmin/admin-modal-accessibility.js'), 'utf8');
const indexSource = readFileSync(join(process.cwd(), 'discadmin/index.php'), 'utf8');

test('shared keyboard accessibility loads after the admin shell', async () => {
  expect(indexSource).toContain('admin-modal-accessibility.js');
  expect(indexSource.indexOf('admin-shell.js')).toBeLessThan(indexSource.indexOf('admin-modal-accessibility.js'));
  expect(indexSource.indexOf('global-search.js')).toBeLessThan(indexSource.indexOf('admin-modal-accessibility.js'));
  expect(indexSource.indexOf('bulk-actions.js')).toBeLessThan(indexSource.indexOf('admin-modal-accessibility.js'));
  expect(indexSource.indexOf('admin-activity.js')).toBeLessThan(indexSource.indexOf('admin-modal-accessibility.js'));
});

test('mobile sidebar traps focus, closes with Escape and restores MENU focus', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(`
    <div id="app">
      <div class="shell">
        <button class="admin-menu-toggle" aria-expanded="false">MENU</button>
        <aside class="side"><nav class="nav"><button class="active">EVENTS</button><button>ARTISTS</button></nav><div class="sidefoot"><button>LOGOUT</button></div></aside>
        <main class="main"><button id="background">BACKGROUND</button></main>
      </div>
    </div>
  `);
  await page.evaluate(() => {
    window.closeAdminNav = () => {
      document.querySelector('.shell')?.classList.remove('admin-nav-open');
      document.body.classList.remove('admin-nav-open');
    };
  });
  await page.addScriptTag({ content: accessibilitySource });

  const menu = page.getByRole('button', { name: 'MENU' });
  await menu.focus();
  await page.evaluate(() => document.querySelector('.shell')?.classList.add('admin-nav-open'));
  await expect(page.getByRole('button', { name: 'EVENTS' })).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('button', { name: 'LOGOUT' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'EVENTS' })).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(menu).toBeFocused();
  await expect(page.locator('.shell')).not.toHaveClass(/admin-nav-open/);
});

test('Ctrl+K belongs only to Global Search and its dialog traps/restores focus', async ({ page }) => {
  await page.setContent(`
    <button id="origin">ORIGIN</button>
    <button id="background">BACKGROUND</button>
    <div id="brvtal-global-search" class="brvtal-global-search-overlay" aria-hidden="true">
      <div class="brvtal-global-search-dialog" role="dialog" aria-modal="true">
        <input type="search" class="brvtal-global-search-input" aria-label="Search all DISCADMIN content">
        <button class="brvtal-global-search-close">CLOSE</button>
      </div>
    </div>
  `);
  await page.evaluate(() => {
    window.themeNavigations = 0;
    document.addEventListener('keydown', event => {
      if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') window.themeNavigations += 1;
    });
    const overlay = document.getElementById('brvtal-global-search');
    const open = () => { overlay.classList.add('open'); overlay.setAttribute('aria-hidden', 'false'); };
    const close = () => { overlay.classList.remove('open'); overlay.setAttribute('aria-hidden', 'true'); };
    window.BRVTALGlobalSearch = { open, close };
    overlay.querySelector('.brvtal-global-search-close').addEventListener('click', close);
  });
  await page.addScriptTag({ content: accessibilitySource });

  const origin = page.getByRole('button', { name: 'ORIGIN' });
  await origin.focus();
  await page.keyboard.press('Control+K');
  await expect(page.locator('#brvtal-global-search')).toHaveClass(/open/);
  expect(await page.evaluate(() => window.themeNavigations)).toBe(0);
  await expect(page.getByRole('searchbox')).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('button', { name: 'CLOSE' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('searchbox')).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(origin).toBeFocused();
  await expect(page.locator('#brvtal-global-search')).not.toHaveClass(/open/);
});

test('Bulk Actions traps focus during loading and restores its trigger', async ({ page }) => {
  await page.setContent(`
    <button id="bulk-origin">BULK ACTIONS</button>
    <button id="background">BACKGROUND</button>
    <div id="brvtal-bulk-actions" class="brvtal-bulk-overlay" aria-hidden="true">
      <div class="brvtal-bulk-dialog" role="dialog" aria-modal="true">
        <button class="brvtal-bulk-close">CLOSE</button>
        <input class="brvtal-bulk-search" aria-label="Filter bulk action items">
        <button class="brvtal-bulk-apply">APPLY STATUS</button>
      </div>
    </div>
  `);
  await page.evaluate(() => {
    const overlay = document.getElementById('brvtal-bulk-actions');
    overlay.querySelector('.brvtal-bulk-close').addEventListener('click', () => {
      overlay.classList.remove('open');
      overlay.setAttribute('aria-hidden', 'true');
    });
  });
  await page.addScriptTag({ content: accessibilitySource });

  const origin = page.getByRole('button', { name: 'BULK ACTIONS' });
  await origin.focus();
  await page.evaluate(() => {
    const overlay = document.getElementById('brvtal-bulk-actions');
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
  });
  await expect(page.getByRole('button', { name: 'CLOSE' })).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('button', { name: 'APPLY STATUS' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(origin).toBeFocused();
});

test('Admin Activity detail/history modal gets initial focus, trap, Escape and restoration', async ({ page }) => {
  await page.setContent(`<button id="activity-origin">DETAIL</button><button id="background">BACKGROUND</button>`);
  await page.addScriptTag({ content: accessibilitySource });

  const origin = page.getByRole('button', { name: 'DETAIL' });
  await origin.focus();
  await page.evaluate(() => {
    const modal = document.createElement('div');
    modal.className = 'activity-modal';
    modal.innerHTML = '<div class="activity-modal-card" role="dialog" aria-modal="true"><button data-activity-close>CLOSE</button><button>VERSION</button></div>';
    modal.querySelector('[data-activity-close]').addEventListener('click', () => modal.remove());
    document.body.appendChild(modal);
  });
  await expect(page.getByRole('button', { name: 'CLOSE' })).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('button', { name: 'VERSION' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'CLOSE' })).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(page.locator('.activity-modal')).toHaveCount(0);
  await expect(origin).toBeFocused();
});
