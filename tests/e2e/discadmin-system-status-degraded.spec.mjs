import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const systemStatusJs = readFileSync(join(process.cwd(), 'discadmin/system-status-v2.js'), 'utf8');
const legacyStatusPhp = readFileSync(join(process.cwd(), 'discadmin/status.php'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/e2e-system-status-degraded.html';

const overview = {
  ok:true,
  health:{score:100,status:'healthy',checks_ok:8,checks_total:8},
  checks:[
    {key:'api',label:'API',status:'ok',value:'ONLINE'},
    {key:'database',label:'DATABASE',status:'ok',value:'CONNECTED'},
  ],
  storage:{total:'25.00 GB',used:'1.00 GB',free:'24.00 GB',used_percent:4,uploads_items:2},
  database:{driver:'mysql',server:'11.8.0-MariaDB',counts:{}},
  runtime:{php:'8.5.0',sapi:'fpm-fcgi',memory_limit:'512M',upload_max_filesize:'25M'},
  deployment:{commit:'29ad6620d1b03b331debd8201e3306bc6bcbf29a',short_commit:'29ad662',source:'git_checkout',environment:'PRODUCTION'},
  repository:{source_files:1,source_lines:1,by_language:{PHP:{files:1,lines:1}},github:{ok:true,commits:1,merged_prs:1,cache:'fresh'}},
  issues:[],
};

test('legacy status summary treats warnings as degraded instead of healthy', async () => {
  expect(legacyStatusPhp).toContain("$warningStates = ['DEGRADED', 'READ-ONLY', 'UNKNOWN'];");
  expect(legacyStatusPhp).toContain("count($warnings) > 0 ? 'DEGRADED' : 'HEALTHY'");
});

test('System Status v2 exposes Content Health and Activity failures instead of fake zeros', async ({ page }) => {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body><nav class="nav"><button class="active">SYSTEM STATUS</button></nav><main class="main"><div class="top"><h1>SYSTEM</h1></div></main><script>${systemStatusJs}</script></body></html>`,
  }));
  await page.route('**/discadmin/technical.php?action=overview', route => route.fulfill({
    contentType:'application/json',
    body:JSON.stringify(overview),
  }));
  await page.route('**/api/content-health.php', route => route.fulfill({
    status:503,
    contentType:'application/json',
    body:JSON.stringify({ok:false,error:'CONTENT_HEALTH_DOWN'}),
  }));
  await page.route('**/api/admin-activity.php?limit=5', route => route.fulfill({
    status:503,
    contentType:'application/json',
    body:JSON.stringify({ok:false,error:'ACTIVITY_DOWN'}),
  }));

  await page.goto(harness);

  await expect(page.locator('#system-status-v2')).toBeVisible();
  await expect(page.locator('.ssv2-health-summary')).toContainText('DEGRADED');

  const editorial = page.locator('.ssv2-panel', {hasText:'EDITORIAL HEALTH'});
  await expect(editorial).toContainText('CONTENT HEALTH UNAVAILABLE');
  await expect(editorial).not.toContainText('0 ready');

  const activity = page.locator('.ssv2-panel', {hasText:'RECENT ADMIN ACTIVITY'});
  await expect(activity.locator('.ssv2-panel-head b')).toHaveText('UNAVAILABLE');
  await expect(activity).toContainText('ADMIN ACTIVITY UNAVAILABLE');
  await expect(activity).not.toContainText('NO RECENT ACTIVITY');
  await expect(activity).not.toContainText('0 TOTAL');

  const attention = page.locator('.ssv2-panel', {hasText:'ATTENTION REQUIRED'});
  await expect(attention).toContainText('2 SIGNALS');
  await expect(attention).toContainText('CONTENT HEALTH');
  await expect(attention).toContainText('ADMIN ACTIVITY');
});
