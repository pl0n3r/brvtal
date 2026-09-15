import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const backupsCss = readFileSync(join(process.cwd(), 'discadmin/backups.css'), 'utf8');
const blogCss = readFileSync(join(process.cwd(), 'discadmin/blog.css'), 'utf8');
const systemStatusCss = readFileSync(join(process.cwd(), 'discadmin/system-status-v2.css'), 'utf8');
const adminShellCss = readFileSync(join(process.cwd(), 'discadmin/admin-shell.css'), 'utf8');

function injectedCss(path) {
  const source = readFileSync(join(process.cwd(), path), 'utf8');
  return source.match(/style\.textContent\s*=\s*`([\s\S]*?)`;/)?.[1] || '';
}

const contentHealthCss = injectedCss('discadmin/content-health.js');
const globalSearchCss = injectedCss('discadmin/global-search.js');
const bulkActionsCss = injectedCss('discadmin/bulk-actions.js');
const adminActivityCss = injectedCss('discadmin/admin-activity.js');
const mobile = { width: 390, height: 844 };

async function heightOf(locator) {
  const box = await locator.boundingBox();
  return box?.height || 0;
}

async function sizeOf(locator) {
  return await locator.boundingBox() || { width: 0, height: 0 };
}

test('Backups keeps primary and download actions touch-friendly on mobile', async ({ page }) => {
  await page.setViewportSize(mobile);
  await page.setContent(`
    <div class="backup-error"><button>RETRY</button></div>
    <div class="backup-actions"><div><button>CREATE BACKUP</button></div><button class="backup-refresh">REFRESH</button></div>
    <div class="backup-components"><a href="#sql">DATABASE SQL</a><a href="#manifest">MANIFEST</a></div>
  `);
  await page.addStyleTag({ content: backupsCss });

  for (const control of [
    page.getByRole('button', { name: 'RETRY' }),
    page.getByRole('button', { name: 'CREATE BACKUP' }),
    page.getByRole('button', { name: 'REFRESH' }),
    page.getByRole('link', { name: 'DATABASE SQL' }),
    page.getByRole('link', { name: 'MANIFEST' }),
  ]) {
    expect(await heightOf(control)).toBeGreaterThanOrEqual(44);
  }
});

test('Blog keeps publication status visible in the mobile record list', async ({ page }) => {
  await page.setViewportSize(mobile);
  await page.setContent(`
    <div class="blog-row">
      <div class="blog-cover-ph">IMG</div>
      <div><div class="blog-title">Genesis recap</div></div>
      <div class="blog-date">2026-09-15</div>
      <div class="blog-status-wrap"><span class="blog-status-pill published">published</span></div>
      <div class="blog-actions"><button>EDIT</button></div>
    </div>
  `);
  await page.addStyleTag({ content: blogCss });

  const status = page.locator('.blog-status-wrap');
  await expect(status).toBeVisible();
  await expect(status).toContainText('published');
  expect(await status.evaluate(el => getComputedStyle(el).gridColumnStart)).toBe('2');
});

test('System Status actions keep a 44px mobile touch target', async ({ page }) => {
  await page.setViewportSize(mobile);
  await page.setContent(`
    <div id="system-status-v2">
      <div class="ssv2-fatal"><button>RETRY</button></div>
      <div class="ssv2-hero-actions"><button>REFRESH</button></div>
      <div class="ssv2-advanced-actions"><button>LOAD RECENT LOGS</button></div>
    </div>
  `);
  await page.addStyleTag({ content: systemStatusCss });

  for (const control of [
    page.getByRole('button', { name: 'RETRY' }),
    page.getByRole('button', { name: 'REFRESH' }),
    page.getByRole('button', { name: 'LOAD RECENT LOGS' }),
  ]) {
    expect(await heightOf(control)).toBeGreaterThanOrEqual(44);
  }
});

test('shared shell enforces 44px mobile targets over injected module styles', async ({ page }) => {
  await page.setViewportSize(mobile);
  await page.setContent(`
    <button class="content-health-open">OPEN</button>
    <button class="brvtal-global-search-trigger">SEARCH</button>
    <button class="brvtal-global-search-close">ESC / CLOSE</button>
    <button class="brvtal-global-search-item"><span>RESULT</span></button>
    <button class="brvtal-bulk-trigger">BULK ACTIONS</button>
    <button class="brvtal-bulk-close">ESC / CLOSE</button>
    <input class="brvtal-bulk-search" aria-label="Bulk search">
    <select class="brvtal-bulk-status" aria-label="Bulk status"><option>STATUS</option></select>
    <button class="brvtal-bulk-select-all">SELECT ALL</button>
    <label class="brvtal-bulk-row"><input type="checkbox" aria-label="Select record"><span>RECORD</span></label>
    <button class="brvtal-bulk-apply">APPLY STATUS</button>
    <select class="activity-filter" aria-label="Activity filter"><option>ALL CONTENT</option></select>
    <button class="activity-btn" data-kind="detail">DETAIL</button>
    <button class="activity-btn" data-kind="history">HISTORY</button>
    <button class="activity-btn" data-kind="open">OPEN</button>
    <button class="activity-btn" data-kind="refresh">REFRESH</button>
    <button class="activity-btn" data-kind="close">CLOSE</button>
  `);
  await page.addStyleTag({ content: adminShellCss });
  for (const css of [contentHealthCss, globalSearchCss, bulkActionsCss, adminActivityCss]) {
    expect(css.length).toBeGreaterThan(0);
    await page.addStyleTag({ content: css });
  }

  const controls = [
    '.content-health-open',
    '.brvtal-global-search-trigger',
    '.brvtal-global-search-close',
    '.brvtal-global-search-item',
    '.brvtal-bulk-trigger',
    '.brvtal-bulk-close',
    '.brvtal-bulk-search',
    '.brvtal-bulk-status',
    '.brvtal-bulk-select-all',
    '.brvtal-bulk-row',
    '.brvtal-bulk-apply',
    '.activity-filter',
    '.activity-btn[data-kind="detail"]',
    '.activity-btn[data-kind="history"]',
    '.activity-btn[data-kind="open"]',
    '.activity-btn[data-kind="refresh"]',
    '.activity-btn[data-kind="close"]',
  ];
  for (const selector of controls) {
    expect(await heightOf(page.locator(selector))).toBeGreaterThanOrEqual(44);
  }

  const checkbox = await sizeOf(page.locator('.brvtal-bulk-row input'));
  expect(checkbox.width).toBeGreaterThanOrEqual(22);
  expect(checkbox.height).toBeGreaterThanOrEqual(22);
});
