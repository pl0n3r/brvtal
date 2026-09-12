import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const activityJs = readFileSync(join(process.cwd(), 'discadmin/admin-activity.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/admin-activity-e2e.html';

const eventItem = {
  id: 41,
  admin_id: 7,
  admin_name: 'Felipe Admin',
  admin_email: 'admin@brvtal.test',
  action: 'update',
  resource: 'events',
  resource_id: 9,
  resource_label: 'GENESIS',
  changed_fields: ['description','status'],
  request_id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  created_at: '2026-09-12 02:30:00',
};

const artistItem = {
  id: 42,
  admin_id: 7,
  admin_name: 'Felipe Admin',
  admin_email: 'admin@brvtal.test',
  action: 'create',
  resource: 'artists',
  resource_id: 12,
  resource_label: 'PL0N3R',
  changed_fields: ['name','status'],
  request_id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
  created_at: '2026-09-12 02:31:00',
};

const earlierEventItem = {
  ...eventItem,
  id: 40,
  action: 'create',
  changed_fields: ['title','status'],
  created_at: '2026-09-11 22:10:00',
};

test('Dashboard activity panel filters history and opens read-only before/after detail', async ({ page }) => {
  await page.route('**/api/admin-activity.php*', route => {
    const url = new URL(route.request().url());
    const id = url.searchParams.get('id');
    if (id === '41') {
      return route.fulfill({
        contentType: 'application/json; charset=utf-8',
        body: JSON.stringify({ok:true,data:{...eventItem,before:{id:9,title:'GENESIS',description:'Old copy',status:'draft'},after:{id:9,title:'GENESIS',description:'New copy',status:'published'},meta:{source:'core_api'}}}),
      });
    }
    if (url.searchParams.get('history') === '1') {
      return route.fulfill({
        contentType: 'application/json; charset=utf-8',
        body: JSON.stringify({ok:true,data:{items:[
          {...eventItem,before:{description:'Old copy',status:'draft'},after:{description:'New copy',status:'published'}},
          {...earlierEventItem,before:null,after:{title:'GENESIS',status:'draft'}},
        ],total:2,limit:50,read_only:true,mode:'content_history'}}),
      });
    }
    const resource = url.searchParams.get('resource') || '';
    const items = resource === 'artists' ? [artistItem] : [eventItem];
    return route.fulfill({
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({ok:true,data:{items,total:items.length,limit:12,read_only:true}}),
    });
  });

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><meta charset="utf-8"></head><body>
      <main class="main"><div class="top"><h1>DASHBOARD</h1></div></main>
      <script>
        var state={authed:true,section:'dashboard'};
        window.go=async function(section){state.section=section;window.__went=section;return section;};
        window.BRVTALFeedback={error:function(message){window.__feedback=message;}};
      </script>
      <script>${activityJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);

  await expect(page.getByRole('heading', { name: 'ADMIN ACTIVITY' })).toBeVisible();
  await expect(page.locator('#brvtal-admin-activity')).toContainText('GENESIS');
  await expect(page.locator('#brvtal-admin-activity')).toContainText('Felipe Admin');
  await expect(page.locator('#brvtal-admin-activity')).toContainText('description · status');
  await expect(page.getByRole('button', { name: /restore/i })).toHaveCount(0);

  await page.getByRole('button', { name: 'HISTORY' }).click();
  await expect(page.getByRole('dialog', { name: 'Editorial version history' })).toBeVisible();
  await expect(page.getByRole('dialog')).toContainText('2 recorded versions');
  await expect(page.locator('[data-history-diff]')).toContainText('Old copy');
  await expect(page.locator('[data-history-diff]')).toContainText('New copy');
  await page.locator('[data-history-index="1"]').click();
  await expect(page.locator('[data-history-diff]')).toContainText('GENESIS');
  await page.getByRole('button', { name: 'CLOSE' }).click();

  await page.locator('[data-activity-filter]').selectOption('artists');
  await expect(page.locator('#brvtal-admin-activity')).toContainText('PL0N3R');
  await expect(page.locator('#brvtal-admin-activity')).not.toContainText('GENESIS');

  await page.locator('[data-activity-filter]').selectOption('');
  await expect(page.locator('#brvtal-admin-activity')).toContainText('GENESIS');
  await page.getByRole('button', { name: 'DETAIL' }).click();
  await expect(page.locator('#brvtal-activity-modal')).toBeVisible();
  await expect(page.locator('[data-activity-before]')).toContainText('draft');
  await expect(page.locator('[data-activity-after]')).toContainText('published');
  await expect(page.locator('[data-activity-after]')).toContainText('New copy');

  await page.getByRole('button', { name: 'CLOSE' }).click();
  await page.getByRole('button', { name: 'OPEN' }).click();
  await expect.poll(() => page.evaluate(() => window.__went)).toBe('events');
});
