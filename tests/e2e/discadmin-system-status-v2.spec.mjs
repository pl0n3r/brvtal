import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'discadmin/system-status-v2.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/e2e-system-status-v2.html';

const overview = {
  ok:true,
  health:{score:88,status:'degraded',checks_ok:7,checks_total:8},
  checks:[
    {key:'api',label:'API',status:'ok',value:'ONLINE'},
    {key:'database',label:'DATABASE',status:'ok',value:'CONNECTED'},
    {key:'uploads',label:'MEDIA STORAGE',status:'ok',value:'WRITABLE'},
    {key:'logs',label:'LOGS',status:'ok',value:'WRITABLE'},
    {key:'runtime',label:'PHP RUNTIME',status:'ok',value:'READY'},
    {key:'totp',label:'2FA FOUNDATION',status:'ok',value:'READY'},
    {key:'activity',label:'ACTIVITY HISTORY',status:'ok',value:'READY'},
    {key:'deployment',label:'DEPLOYMENT',status:'error',value:'CHECK'}
  ],
  storage:{total_bytes:1000,used_bytes:400,free_bytes:600,total:'1.00 GB',used:'400 MB',free:'600 MB',used_percent:40,uploads_items:18},
  database:{driver:'mysql',server:'11.8.0-MariaDB',counts:{events:12,artists:24,sets:9,releases:4,media:83,pages:6,blog:7}},
  runtime:{php:'8.3.33',sapi:'fpm-fcgi',memory_limit:'512M',upload_max_filesize:'25M',post_max_size:'32M',max_execution_time:'30',extensions:{}},
  deployment:{commit:'ad196b0f8ca564f45f40492cb8b320c620304433',short_commit:'ad196b0',source:'git_checkout',version:'0.1.0',environment:'PRODUCTION',release_date:'2026-09-11'},
  repository:{source_files:137,source_lines:28000,by_language:{PHP:{files:52,lines:14000},JavaScript:{files:41,lines:9000},CSS:{files:18,lines:3500},SQL:{files:6,lines:1500}},github:{ok:true,commits:142,merged_prs:35,source:'github_public_api',cache:'fresh'}},
  issues:[{severity:'error',title:'DEPLOYMENT',detail:'CHECK — review this service.'}],
  time:'2026-09-12T00:00:00-05:00',generated_ms:22.4
};

const health = {ok:true,data:{score:82,total:20,ready:16,needs_attention:4,missing_visuals:3,seo_gaps:2,by_type:{},items:[]}};
const activity = {ok:true,data:{total:44,limit:5,read_only:true,items:[
  {id:44,admin_name:'Felipe',action:'update',resource:'events',resource_id:8,resource_label:'GENESIS',created_at:'2026-09-12 05:00:00'},
  {id:43,admin_name:'Felipe',action:'seo_update',resource:'artists',resource_id:2,resource_label:'HAKKI',created_at:'2026-09-12 04:55:00'}
]}};

test('System Status v2 renders visual operational, repository and editorial signals', async ({ page }) => {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body><main class="main"><div class="top"><div><h1>SYSTEM STATUS</h1></div></div><div id="legacy">legacy</div></main><script>${script}</script></body></html>`
  }));

  await page.route('**/discadmin/technical.php?action=overview', route => route.fulfill({contentType:'application/json',body:JSON.stringify(overview)}));
  await page.route('**/api/content-health.php', route => route.fulfill({contentType:'application/json',body:JSON.stringify(health)}));
  await page.route('**/api/admin-activity.php?limit=5', route => route.fulfill({contentType:'application/json',body:JSON.stringify(activity)}));
  await page.route('**/discadmin/technical.php?action=logs', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,content:'LOG OK'})}));

  await page.goto(harness);
  await expect(page.locator('#system-status-v2')).toBeVisible();
  await expect(page.locator('#legacy')).toHaveCount(0);
  await expect(page.locator('.ssv2-health-summary')).toContainText('88%');
  await expect(page.locator('.ssv2-health-summary')).toContainText('DEGRADED');
  await expect(page.locator('.ssv2-services .ssv2-service')).toHaveCount(8);
  await expect(page.locator('.ssv2-storage-ring')).toContainText('40%');
  await expect(page.locator('.ssv2-panel', {hasText:'DATABASE CONTENT'})).toContainText('MEDIA');
  await expect(page.locator('.ssv2-panel', {hasText:'DATABASE CONTENT'})).toContainText('83');

  const repo = page.locator('.ssv2-panel', {hasText:'REPOSITORY'});
  await expect(repo).toContainText('142');
  await expect(repo).toContainText('35');
  await expect(repo).toContainText('28,000');
  await expect(repo).toContainText('137 source files');
  await expect(repo).toContainText('PHP');

  await expect(page.locator('.ssv2-panel', {hasText:'EDITORIAL HEALTH'})).toContainText('82%');
  await expect(page.locator('.ssv2-panel', {hasText:'ATTENTION REQUIRED'})).toContainText('DEPLOYMENT');
  await expect(page.locator('.ssv2-activity')).toContainText('GENESIS');
  await expect(page.locator('.ssv2-activity')).toContainText('Felipe');
  await expect(page.locator('.ssv2-advanced')).not.toHaveAttribute('open', '');

  await page.locator('.ssv2-advanced summary').click();
  await page.locator('#ssv2-load-logs').click();
  await expect(page.locator('#ssv2-logs')).toContainText('LOG OK');
});
