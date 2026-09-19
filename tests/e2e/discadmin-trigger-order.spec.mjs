import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const bulkActions = readFileSync(join(process.cwd(), 'discadmin/bulk-actions.js'), 'utf8');
const globalSearch = readFileSync(join(process.cwd(), 'discadmin/global-search.js'), 'utf8');

/** Build a minimal DISCADMIN shell for trigger-order browser assertions. */
function shell(script, { withModule = false } = {}) {
  return `<!doctype html><html><body>
    ${withModule ? '<nav class="nav"><button class="active" data-admin-nav="events">Events</button></nav>' : ''}
    <aside class="side"><div class="sidefoot"><button data-admin-logout>LOG OUT</button></div></aside>
    <main class="main"><div class="top"><h1>EVENTS</h1></div></main>
    <script>${script}</script>
  </body></html>`;
}

test('Bulk Actions trigger remains a top-bar action without a decorative status badge', async ({ page }) => {
  await page.setContent(shell(bulkActions, { withModule: true }));

  const trigger = page.locator('.brvtal-bulk-trigger');
  await expect(trigger).toHaveCount(1);
  expect(await trigger.evaluate(node => node.parentElement?.classList.contains('top'))).toBe(true);
  expect(await trigger.evaluate(node => node.nextElementSibling === null)).toBe(true);
});

test('Global Search keeps top and sidebar entry points without a decorative status badge', async ({ page }) => {
  await page.setContent(shell(globalSearch));

  const top = page.locator('[data-global-search-location="top"]');
  const sidebar = page.locator('[data-global-search-location="sidebar"]');
  await expect(top).toHaveCount(1);
  await expect(sidebar).toHaveCount(1);
  expect(await top.evaluate(node => node.parentElement?.classList.contains('top'))).toBe(true);
  expect(await sidebar.evaluate(node => node.nextElementSibling?.hasAttribute('data-admin-logout'))).toBe(true);
});
