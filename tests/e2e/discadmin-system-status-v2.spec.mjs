import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'discadmin/system-status-v2.js'), 'utf8');
const styles = readFileSync(join(process.cwd(), 'discadmin/system-status-v2.css'), 'utf8');
const storageScript = readFileSync(join(process.cwd(), 'discadmin/system-status-storage.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/e2e-system-status-v2.html';

const recentIssues = [
  {number:409,title:'bug: System Status Attention Required does not reflect open GitHub Issues',url:'https://github.com/pl0n3r/brvtal/issues/409',labels:['bug','admin'],updated_at:'2026-09-16T19:05:58Z'},
  {number:398,title:'design: evolve public BRVTAL into a connected cultural archive',url:'https://github.com/pl0n3r/brvtal/issues/398',labels:['design'],updated_at:'2026-09-16T18:00:00Z'},
];

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
  storage:{total_bytes:1000,used_bytes:400,free_bytes:600,total:'6.93 TB',used:'5.14 TB',free:'1.79 TB',used_percent:74.2,uploads_items:18},
  database:{driver:'mysql',server:'11.8.0-MariaDB',counts:{events:12,artists:24,sets:9,releases:4,media:83,pages:6,blog:7}},
  runtime:{php:'8.5.0',sapi:'fpm-fcgi',memory_limit:'512M',upload_max_filesize:'25M',post_max_size:'32M',max_execution_time:'30',extensions:{}},
  deployment:{commit:'ad196b0f8ca564f45f40492cb8b320c620304433',short_commit:'ad196b0',source:'git_checkout',version:'0.1.0',environment:'PRODUCTION',release_date:'2026-09-11'},
  repository:{source_files:137,source_lines:28000,by_language:{PHP:{files:52,lines:14000},JavaScript:{files:41,lines:9000},CSS:{files:18,lines:3500},SQL:{files:6,lines:1500}},github:{ok:true,commits:142,merged_prs:35,open_issues:31,recent_issues:recentIssues,backlog_state:'fresh',source:'github_public_api',cache:'fresh'}},
  issues:[{severity:'error',title:'DEPLOYMENT',detail:'CHECK — review this service.'}],
  time:'2026-09-16T19:00:00-05:00',generated_ms:22.4
};

const managedStorage = {
  ok:true,
  scope:'brvtal_managed_data',
  quota_source:'hostinger_plan_fallback',
  quota_bytes:26843545600,
  quota:'25.00 GB',
  used_bytes:536870912,
  used:'512.00 MB',
  free_bytes:26306674688,
  free:'24.50 GB',
  used_percent:2,
  managed_files:21,
  directories:{uploads:{bytes:500000000,size:'476.84 MB',files:18},storage:{bytes:36870912,size:'35.16 MB',files:3}},
  host_filesystem:{total:'6.93 TB',free:'1.79 TB',diagnostic_only:true}
};

const health = {ok:true,data:{score:82,total:20,ready:16,needs_attention:4,missing_visuals:3,seo_gaps:2,by_type:{},items:[]}};
const activity = {ok:true,data:{total:44,limit:5,read_only:true,items:[
  {id:44,admin_name:'Felipe',action:'update',resource:'events',resource_id:8,resource_label:'GENESIS',created_at:'2026-09-16 18:00:00'},
  {id:43,admin_name:'Felipe',action:'seo_update',resource:'artists',resource_id:2,resource_label:'HAKKI',created_at:'2026-09-16 17:55:00'}
]}};

async function routeStatusSources(page, currentOverview=overview, currentHealth=health, currentActivity=activity) {
  await page.route('**/discadmin/technical.php?action=overview', route => route.fulfill({contentType:'application/json',body:JSON.stringify(currentOverview)}));
  await page.route('**/api/content-health.php', route => route.fulfill({contentType:'application/json',body:JSON.stringify(currentHealth)}));
  await page.route('**/api/admin-activity.php?limit=5', route => route.fulfill({contentType:'application/json',body:JSON.stringify(currentActivity)}));
}

const shellMarkup = extraScripts => `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${styles}</style></head><body><nav class="nav"><button class="active">SYSTEM STATUS</button></nav><main class="main"><div class="top"><div><h1>SYSTEM</h1></div></div><div id="legacy">legacy</div></main><script>${script}</script>${extraScripts || ''}</body></html>`;

test('System Status v2 mounts on production markup, shows GitHub backlog and replaces host disk with BRVTAL managed storage', async ({ page }) => {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:shellMarkup(`<script>${storageScript}</script>`)
  }));

  await routeStatusSources(page);
  await page.route('**/discadmin/storage-metrics.php*', route => route.fulfill({contentType:'application/json',body:JSON.stringify(managedStorage)}));
  await page.route('**/discadmin/technical.php?action=logs', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,content:'LOG OK'})}));

  await page.goto(harness);
  await expect(page.locator('#system-status-v2')).toBeVisible();
  await expect(page.locator('#legacy')).toHaveCount(0);
  await expect(page.locator('.ssv2-health-summary')).toContainText('88%');
  await expect(page.locator('.ssv2-health-summary')).toContainText('DEGRADED');
  await expect(page.locator('.ssv2-services .ssv2-service')).toHaveCount(8);

  const storage = page.locator('.ssv2-panel.storage');
  await expect(storage).toHaveAttribute('data-storage-scope', 'brvtal-managed-data');
  await expect(storage.locator('.ssv2-storage-ring')).toContainText('2.0%');
  await expect(storage).toContainText('BRVTAL DATA');
  await expect(storage).toContainText('512.00 MB / 25.00 GB');
  await expect(storage).toContainText('24.50 GB FREE');
  await expect(storage).toContainText('21 managed files');
  await expect(storage).not.toContainText('5.14 TB / 6.93 TB');

  await expect(page.locator('.ssv2-panel', {hasText:'DATABASE CONTENT'})).toContainText('MEDIA');
  await expect(page.locator('.ssv2-panel', {hasText:'DATABASE CONTENT'})).toContainText('83');

  const repo = page.locator('.ssv2-panel', {hasText:'REPOSITORY'});
  await expect(repo).toContainText('142');
  await expect(repo).toContainText('35');
  await expect(repo).toContainText('OPEN ISSUES');
  await expect(repo).toContainText('31');
  await expect(repo).toContainText('28,000');
  await expect(repo).toContainText('137 source files');
  await expect(repo).toContainText('PHP');

  await expect(page.locator('.ssv2-panel', {hasText:'EDITORIAL HEALTH'})).toContainText('82%');
  const attention = page.locator('.ssv2-panel', {hasText:'ATTENTION REQUIRED'});
  await expect(attention).toContainText('1 PLATFORM · 31 GITHUB');
  await expect(attention).toContainText('PLATFORM SIGNALS');
  await expect(attention).toContainText('DEPLOYMENT');
  await expect(attention).toContainText('GITHUB BACKLOG');
  await expect(attention).toContainText('GITHUB #409 · BACKLOG');
  await expect(attention).toContainText('System Status Attention Required does not reflect open GitHub Issues');
  await expect(attention).not.toContainText('NO ACTIVE ISSUES');
  await expect(attention.locator('a[href="https://github.com/pl0n3r/brvtal/issues/409"]')).toHaveCount(1);
  await expect(attention.locator('a[href="https://github.com/pl0n3r/brvtal/issues"]')).toContainText('VIEW ALL 31');

  await expect(page.locator('.ssv2-activity')).toContainText('GENESIS');
  await expect(page.locator('.ssv2-activity')).toContainText('Felipe');
  await expect(page.locator('.ssv2-advanced')).not.toHaveAttribute('open', '');

  await page.locator('.ssv2-advanced summary').click();
  await page.locator('#ssv2-load-logs').click();
  await expect(page.locator('#ssv2-logs')).toContainText('LOG OK');
});

test('healthy platform stays healthy with a non-empty GitHub backlog and remains mobile-safe', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  const healthyOverview = structuredClone(overview);
  healthyOverview.health = {score:100,status:'healthy',checks_ok:8,checks_total:8};
  healthyOverview.checks = healthyOverview.checks.map(check => ({...check,status:'ok',value:check.key === 'deployment' ? 'TRACKED' : check.value}));
  healthyOverview.issues = [];

  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:shellMarkup('')
  }));
  await routeStatusSources(page, healthyOverview);
  await page.goto(harness);

  await expect(page.locator('.ssv2-health-summary')).toContainText('100%');
  await expect(page.locator('.ssv2-health-summary')).toContainText('HEALTHY');
  const attention = page.locator('.ssv2-attention');
  await expect(attention).toContainText('0 PLATFORM · 31 GITHUB');
  await expect(attention).toContainText('NO ACTIVE PLATFORM SIGNALS');
  await expect(attention).toContainText('GITHUB #409 · BACKLOG');
  await expect(attention).not.toContainText('NO ACTIVE ISSUES');

  const linkHeight = await attention.locator('.ssv2-backlog-item').first().evaluate(node => node.getBoundingClientRect().height);
  expect(linkHeight).toBeGreaterThanOrEqual(44);
  const dimensions = await page.evaluate(() => ({viewport:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth}));
  expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.viewport);
});
