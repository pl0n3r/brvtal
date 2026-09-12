import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const contentHealthJs = readFileSync(join(process.cwd(), 'discadmin/content-health.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/content-health-e2e.html';

test('Dashboard Content Health mounts read-only diagnostics and routes to canonical editor', async ({ page }) => {
  await page.route('**/api/content-health.php', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({
      ok: true,
      data: {
        score: 72,
        total: 6,
        ready: 3,
        needs_attention: 3,
        missing_visuals: 2,
        seo_gaps: 1,
        by_type: {},
        items: [
          { id: 10, type: 'events', title: 'Genesis', status: 'published', score: 52, issues: ['Useful description','Primary visual'], seo_supported: false, has_image: false },
          { id: 20, type: 'blog', title: 'BRVTAL Story', status: 'draft', score: 68, issues: ['SEO description'], seo_supported: true, has_image: true }
        ]
      }
    })
  }));

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html><head></head><body>
      <div id="app"><div class="shell"><aside class="side"></aside><main class="main"><div class="top"><h1>DASHBOARD</h1></div></main></div></div>
      <script>
        window.state = { authed: true, section: 'dashboard' };
        window.go = async section => { window.__healthRoute = section; window.state.section = section; };
        window.BRVTALFeedback = { error: message => { window.__healthError = message; } };
      </script>
      <script>${contentHealthJs}</script>
    </body></html>`
  }));

  await page.goto(harnessUrl);
  await expect(page.getByRole('heading', { name: 'CONTENT HEALTH' })).toBeVisible();
  await expect(page.locator('.content-health-score')).toContainText('72%');
  await expect(page.getByText('Genesis')).toBeVisible();
  await expect(page.getByText('Useful description · Primary visual')).toBeVisible();
  await expect(page.getByText('Read-only diagnostics. It never publishes or modifies content automatically.')).toBeVisible();

  await page.locator('[data-health-open="events"]').click();
  await expect.poll(() => page.evaluate(() => window.__healthRoute)).toBe('events');
});
