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
  repository:{source_files:1,source_lines:1,by_language:{PHP:{files:1,lines:1}},github:{ok:true,commits:1,merged_prs:1,open_issues:0,recent_issues:[],backlog_state:'fresh',cache:'fresh'}},
  issues:[],
};

const healthySource = {ok:true,data:{score:100,total:0,ready:0,needs_attention:0,missing_visuals:0,seo_gaps:0,items:[]}};
const healthyActivity = {ok:true,data:{total:0,limit:5,read_only:true,items:[]}};

async function mountStatus(page, payload=overview) {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body><nav class="nav"><button class="active">SYSTEM STATUS</button></nav><main class="main"><div class="top"><h1>SYSTEM</h1></div></main><script>${systemStatusJs}</script></body></html>`,
  }));
  await page.route('**/discadmin/technical.php?action=overview', route => route.fulfill({contentType:'application/json',body:JSON.stringify(payload)}));
}

test('legacy status summary treats warnings as degraded instead of healthy', async () => {
  expect(legacyStatusPhp).toContain("$warningStates = ['DEGRADED', 'READ-ONLY', 'UNKNOWN'];");
  expect(legacyStatusPhp).toContain("count($warnings) > 0 ? 'DEGRADED' : 'HEALTHY'");
});

test('System Status v2 exposes Content Health and Activity failures instead of fake zeros', async ({ page }) => {
  await mountStatus(page);
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
  await expect(attention).toContainText('2 PLATFORM · 0 GITHUB');
  await expect(attention).toContainText('CONTENT HEALTH');
  await expect(attention).toContainText('ADMIN ACTIVITY');
  await expect(attention).toContainText('NO OPEN GITHUB ISSUES');
});

test('GitHub backlog outage is unavailable rather than a fake zero and does not lower platform health score', async ({ page }) => {
  const unavailable = structuredClone(overview);
  unavailable.repository.github = {ok:false,commits:null,merged_prs:null,open_issues:null,recent_issues:[],backlog_state:'unavailable',cache:'unavailable'};
  unavailable.issues = [{severity:'info',title:'GITHUB METRICS',detail:'GitHub metrics are temporarily unavailable; platform health is unaffected.'}];

  await mountStatus(page, unavailable);
  await page.route('**/api/content-health.php', route => route.fulfill({contentType:'application/json',body:JSON.stringify(healthySource)}));
  await page.route('**/api/admin-activity.php?limit=5', route => route.fulfill({contentType:'application/json',body:JSON.stringify(healthyActivity)}));
  await page.goto(harness);

  await expect(page.locator('.ssv2-health-summary')).toContainText('100%');
  await expect(page.locator('.ssv2-health-summary')).toContainText('HEALTHY');
  const repo = page.locator('.ssv2-panel', {hasText:'REPOSITORY'});
  await expect(repo.locator('a', {hasText:'OPEN ISSUES'}).locator('strong')).toHaveText('—');
  const attention = page.locator('.ssv2-attention');
  await expect(attention).toContainText('1 PLATFORM · GITHUB —');
  await expect(attention).toContainText('GITHUB METRICS');
  await expect(attention).toContainText('GITHUB BACKLOG UNAVAILABLE');
  await expect(attention).not.toContainText('NO OPEN GITHUB ISSUES');
  await expect(attention).not.toContainText('0 GITHUB');
});
