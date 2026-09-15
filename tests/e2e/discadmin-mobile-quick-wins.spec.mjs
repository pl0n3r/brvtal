import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const backupsCss = readFileSync(join(process.cwd(), 'discadmin/backups.css'), 'utf8');
const blogCss = readFileSync(join(process.cwd(), 'discadmin/blog.css'), 'utf8');
const systemStatusCss = readFileSync(join(process.cwd(), 'discadmin/system-status-v2.css'), 'utf8');

const mobile = { width: 390, height: 844 };

async function heightOf(locator) {
  const box = await locator.boundingBox();
  return box?.height || 0;
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
