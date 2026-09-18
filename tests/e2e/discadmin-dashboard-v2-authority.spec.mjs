import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const navJs = readFileSync(join(process.cwd(), 'discadmin/content-core-nav.js'), 'utf8');
const dashboardJs = readFileSync(join(process.cwd(), 'discadmin/dashboard-v2.js'), 'utf8');
const healthJs = readFileSync(join(process.cwd(), 'discadmin/content-health.js'), 'utf8');
const activityJs = readFileSync(join(process.cwd(), 'discadmin/admin-activity.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/dashboard-v2-authority-e2e.html';

const content = {
  public:{score:72,items:[{id:7,type:'events',title:'GENESIS',score:72,issues:['Missing SEO description']}]},
  drafts:{total:1,ready:0,needs_attention:1,items:[{id:8,type:'events',title:'Draft',score:40}]},
};
const activity = {
  total:1,
  items:[{id:11,resource:'events',resource_id:9,resource_label:'EVENT #9',action:'update',admin_name:'Admin',created_at:'2026-09-17 18:00:00'}],
};

async function mount(page, {overviewDelay = 0, waitForRender = true} = {}) {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body>
      <main class="main"><div class="top"><h1>DASHBOARD</h1><span class="status"></span></div></main>
      <script>
        var state={authed:true,section:'dashboard'};
        window.go=async function(section){window.__went=section;return section;};
        window.BRVTALContentCore={openEvent:async function(id){window.__openedRecordId=Number(id);return true;}};
        window.BRVTALFeedback={error:function(message){window.__feedback=message;}};
      </script>
      <script>${navJs}</script>
      <script>${dashboardJs}</script>
      <script>${healthJs}</script>
      <script>${activityJs}</script>
    </body></html>`,
  }));
  await page.route('**/api/dashboard-overview.php', async route => {
    if (overviewDelay) await new Promise(resolve => setTimeout(resolve, overviewDelay));
    await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{summary:{public_records:1,draft_records:1,active_events:0,media_assets:2},next_event:null}})});
  });
  await page.route('**/api/content-health.php', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:content})}));
  await page.route('**/api/index.php/health', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,database:'connected',php:'8.5',driver:'mysql'})}));
  await page.route('**/discadmin/storage-metrics.php', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{used:'1 MB',quota:'1 GB',used_percent:1,managed_files:2}})}));
  await page.route('**/api/admin-activity.php?limit=4', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:activity})}));
  await page.route('**/api/admin-activity.php?limit=12', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:activity})}));
  await page.goto(harness);
  if (waitForRender) await expect(page.locator('#brvtal-dashboard-v2 .dashboard-v2-hero')).toBeVisible();
}

test('Dashboard V2 reserves ownership while slow data is still loading', async ({page}) => {
  await mount(page, {overviewDelay:200, waitForRender:false});

  await page.waitForTimeout(100);
  await expect(page.locator('#brvtal-dashboard-v2')).toHaveCount(1);
  await expect(page.locator('#brvtal-content-health')).toHaveCount(0);
  await expect(page.locator('#brvtal-admin-activity')).toHaveCount(0);

  await expect(page.locator('#brvtal-dashboard-v2 .dashboard-v2-hero')).toBeVisible();
});

test('Dashboard V2 owns Health/Activity surfaces and keeps exact-record OPEN navigation', async ({page}) => {
  await mount(page);

  await expect(page.locator('#brvtal-content-health')).toHaveCount(0);
  await expect(page.locator('#brvtal-admin-activity')).toHaveCount(0);

  const attention = page.locator('.dashboard-v2-panel', {hasText:'NEEDS ATTENTION'});
  const healthOpen = attention.getByRole('button', {name:'OPEN'});
  await expect(healthOpen).toHaveAttribute('data-dashboard-resource','events');
  await expect(healthOpen).toHaveAttribute('data-dashboard-id','7');
  await healthOpen.click();
  await expect.poll(() => page.evaluate(() => window.__went)).toBe('events');
  await expect.poll(() => page.evaluate(() => window.__openedRecordId)).toBe(7);

  const recent = page.locator('.dashboard-v2-panel', {hasText:'RECENT CHANGES'});
  const activityOpen = recent.getByRole('button', {name:'OPEN'});
  await expect(activityOpen).toHaveAttribute('data-dashboard-resource','events');
  await expect(activityOpen).toHaveAttribute('data-dashboard-id','9');
  await page.evaluate(() => { window.__went = undefined; });
  await activityOpen.click();
  await expect.poll(() => page.evaluate(() => window.__went)).toBe('events');
  await expect.poll(() => page.evaluate(() => window.__openedRecordId)).toBe(9);

  await page.waitForTimeout(140);
  await expect(page.locator('#brvtal-content-health')).toHaveCount(0);
  await expect(page.locator('#brvtal-admin-activity')).toHaveCount(0);
  expect(await page.evaluate(() => window.__feedback || '')).toBe('');
});

test('legacy Health and Activity remain available as fallback when Dashboard V2 is absent', async ({page}) => {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body>
      <main class="main"><div class="top"><h1>DASHBOARD</h1></div></main>
      <script>var state={authed:true,section:'dashboard'};window.go=async()=>{};window.BRVTALFeedback={error:()=>{}};</script>
      <script>${healthJs}</script>
      <script>${activityJs}</script>
    </body></html>`,
  }));
  await page.route('**/api/content-health.php', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:content})}));
  await page.route('**/api/admin-activity.php?limit=12', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:activity})}));
  await page.goto(harness);

  await expect(page.locator('#brvtal-content-health')).toBeVisible();
  await expect(page.locator('#brvtal-admin-activity')).toBeVisible();
});
