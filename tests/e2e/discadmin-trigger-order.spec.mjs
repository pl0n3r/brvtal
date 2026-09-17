import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const bulkActions = readFileSync(join(process.cwd(), 'discadmin/bulk-actions.js'), 'utf8');
const globalSearch = readFileSync(join(process.cwd(), 'discadmin/global-search.js'), 'utf8');

function shell(script, { withModule = false } = {}) {
  return `<!doctype html><html><body>
    ${withModule ? '<nav class="nav"><button class="active" data-admin-nav="events">Events</button></nav>' : ''}
    <main class="main"><div class="top"><h1>EVENTS</h1><div class="status">ONLINE</div></div></main>
    <script>${script}</script>
  </body></html>`;
}

test('Bulk Actions trigger stays immediately before the status indicator', async ({ page }) => {
  await page.setContent(shell(bulkActions, { withModule: true }));

  const status = page.locator('.main .top .status');
  await expect(page.locator('.brvtal-bulk-trigger')).toHaveCount(1);
  const triggerIsImmediatelyBeforeStatus = await status.evaluate(node =>
    node.previousElementSibling?.classList.contains('brvtal-bulk-trigger') === true
  );
  expect(triggerIsImmediatelyBeforeStatus).toBe(true);
});

test('Global Search trigger stays immediately before the status indicator', async ({ page }) => {
  await page.setContent(shell(globalSearch));

  const status = page.locator('.main .top .status');
  await expect(page.locator('.brvtal-global-search-trigger')).toHaveCount(1);
  const triggerIsImmediatelyBeforeStatus = await status.evaluate(node =>
    node.previousElementSibling?.classList.contains('brvtal-global-search-trigger') === true
  );
  expect(triggerIsImmediatelyBeforeStatus).toBe(true);
});
