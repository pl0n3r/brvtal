import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const recordNavigationJs = readFileSync(join(process.cwd(), 'discadmin/content-core-nav.js'), 'utf8');
const contentHealthJs = readFileSync(join(process.cwd(), 'discadmin/content-health.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/content-health-record-navigation-e2e.html';

test('Content Health OPEN routes through the canonical shell and opens the exact record', async ({ page }) => {
  await page.route('**/api/content-health.php', route => route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({
      ok: true,
      data: {
        score: 72,
        ready: 0,
        needs_attention: 1,
        missing_visuals: 0,
        seo_gaps: 1,
        items: [{
          id: 7,
          type: 'events',
          title: 'GENESIS',
          score: 72,
          issues: ['Missing SEO description'],
        }],
      },
    }),
  }));

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><meta charset="utf-8"></head><body>
      <main class="main"><div class="top"><h1>DASHBOARD</h1></div></main>
      <script>
        var state={authed:true,section:'dashboard'};
        window.go=async function(section){state.section=section;window.__went=section;return section;};
        window.BRVTALContentCore={openEvent:async function(id){window.__openedRecordId=Number(id);}};
        window.BRVTALFeedback={error:function(message){window.__feedback=message;}};
      </script>
      <script>${recordNavigationJs}</script>
      <script>${contentHealthJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);
  await expect(page.getByRole('heading', { name: 'CONTENT HEALTH' })).toBeVisible();
  await expect(page.locator('#brvtal-content-health')).toContainText('GENESIS');

  await page.getByRole('button', { name: 'OPEN' }).click();
  await expect.poll(() => page.evaluate(() => window.__went)).toBe('events');
  await expect.poll(() => page.evaluate(() => window.__openedRecordId)).toBe(7);
  await expect.poll(() => page.evaluate(() => window.__feedback || '')).toBe('');
});
