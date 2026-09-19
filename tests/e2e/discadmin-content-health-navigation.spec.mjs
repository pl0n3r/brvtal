import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const recordNavigationJs = readFileSync(join(process.cwd(), 'discadmin/content-core-nav.js'), 'utf8');
const contentHealthJs = readFileSync(join(process.cwd(), 'discadmin/content-health.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/content-health-record-navigation-e2e.html';

test('Content Health OPEN preserves health routing when data-health-open is present but empty', async ({ page }) => {
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

  const openButton = page.getByRole('button', { name: 'OPEN' });
  await expect(openButton).toHaveAttribute('data-health-open', 'events');
  await openButton.evaluate(button => button.setAttribute('data-health-open', ''));
  await expect(openButton).toHaveAttribute('data-health-open', '');
  await openButton.click();

  await expect.poll(() => page.evaluate(() => ({
    section: window.__went,
    recordId: window.__openedRecordId,
    feedback: window.__feedback || '',
  }))).toEqual({section:'events',recordId:7,feedback:''});
});
