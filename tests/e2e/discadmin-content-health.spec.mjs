import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const recordNavigationJs = readFileSync(join(process.cwd(), 'discadmin/content-core-nav.js'), 'utf8');
const contentHealthJs = readFileSync(join(process.cwd(), 'discadmin/content-health.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/content-health-e2e.html';

test('Dashboard Content Health separates public readiness from draft completeness and routes to canonical editor', async ({ page }) => {
  await page.route('**/api/content-health.php', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({
      ok: true,
      data: {
        score: 52,
        total: 1,
        ready: 0,
        needs_attention: 1,
        missing_visuals: 1,
        seo_gaps: 0,
        by_type: {},
        items: [
          { id: 10, type: 'events', title: 'Genesis', status: 'published', score: 52, issues: ['Useful description','Primary visual'], seo_supported: false, has_image: false, is_public: true, is_draft: false }
        ],
        public: {
          score: 52,
          total: 1,
          ready: 0,
          needs_attention: 1,
          missing_visuals: 1,
          empty_visuals: 0,
          broken_visuals: 1,
          seo_gaps: 0,
          items: [
            { id: 10, type: 'events', title: 'Genesis', status: 'published', score: 52, issues: ['Useful description','Primary visual'], seo_supported: false, has_image: false, is_public: true, is_draft: false }
          ]
        },
        drafts: {
          score: 68,
          total: 1,
          ready: 0,
          needs_attention: 1,
          missing_visuals: 0,
          empty_visuals: 0,
          broken_visuals: 0,
          seo_gaps: 1,
          items: [
            { id: 20, type: 'blog', title: 'BRVTAL Story', status: 'draft', score: 68, issues: ['SEO description'], seo_supported: true, has_image: true, is_public: false, is_draft: true }
          ]
        },
        inventory_total: 2
      }
    })
  }));

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><meta charset="utf-8"></head><body>
      <div id="app"><div class="shell"><aside class="side"></aside><main class="main"><div class="top"><h1>DASHBOARD</h1></div></main></div></div>
      <script>
        window.state = { authed: true, section: 'dashboard' };
        window.go = async section => { window.__healthRoute = section; window.state.section = section; };
        window.BRVTALContentCore = { openEvent: async id => { window.__openedRecordId = Number(id); } };
        window.BRVTALFeedback = { error: message => { window.__healthError = message; } };
      </script>
      <script>${recordNavigationJs}</script>
      <script>${contentHealthJs}</script>
    </body></html>`
  }));

  await page.goto(harnessUrl);
  await expect(page.getByRole('heading', { name: 'CONTENT HEALTH' })).toBeVisible();
  await expect(page.locator('.content-health-score')).toContainText('52%');
  await expect(page.locator('.content-health-score')).toContainText('PUBLIC SCORE');
  await expect(page.getByText('Genesis')).toBeVisible();
  await expect(page.getByText('PUBLIC EMPTY VISUALS', { exact: true })).toBeVisible();
  await expect(page.getByText('PUBLIC BROKEN VISUALS', { exact: true })).toBeVisible();
  await expect(page.getByText('BRVTAL Story')).toHaveCount(0);
  await expect(page.locator('.content-health-row').first().locator('.content-health-issues')).toContainText('Useful description');
  await expect(page.locator('.content-health-row').first().locator('.content-health-issues')).toContainText('Primary visual');
  await expect(page.getByText('Read-only diagnostics. Public readiness and draft completeness are intentionally separate.')).toBeVisible();
  await expect(page.getByText('DRAFT BACKLOG', { exact: true })).toBeVisible();
  await expect(page.getByText('Drafts may be incomplete without lowering the public health score. Completeness remains visible as editorial progress.')).toBeVisible();

  await page.locator('[data-health-open="events"]').click();
  await expect.poll(() => page.evaluate(() => ({
    section: window.__healthRoute,
    recordId: window.__openedRecordId,
    feedback: window.__healthError || '',
  }))).toEqual({section:'events',recordId:10,feedback:''});
});
