import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const systemStatusJs = readFileSync(join(process.cwd(), 'discadmin/system-status-v2.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/e2e-system-status-reset-log.html';

const overview = {
  ok:true,
  health:{score:100,status:'healthy',checks_ok:2,checks_total:2},
  checks:[{key:'api',label:'API',status:'ok',value:'ONLINE'},{key:'logs',label:'LOGS',status:'ok',value:'WRITABLE'}],
  storage:{total:'25 GB',used:'1 GB',free:'24 GB',used_percent:4,uploads_items:0},
  database:{driver:'mysql',server:'11.8',counts:{}},
  runtime:{php:'8.5',sapi:'fpm-fcgi',memory_limit:'512M',upload_max_filesize:'25M'},
  deployment:{commit:'abc',short_commit:'abc',source:'git_checkout',environment:'TEST'},
  repository:{source_files:1,source_lines:1,by_language:{},github:{ok:true,commits:1,merged_prs:1,open_issues:0,recent_issues:[],backlog_state:'fresh',cache:'fresh'}},
  issues:[],repository_diagnostics:[],
};
const health = {ok:true,data:{score:100,total:0,ready:0,needs_attention:0,missing_visuals:0,seo_gaps:0,items:[]}};
const activity = {ok:true,data:{total:0,limit:5,read_only:true,items:[]}};

async function mount(page, resetStatus=200) {
  let resetCalls = 0;
  let cleared = false;
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body><nav class="nav"><button class="active">SYSTEM STATUS</button></nav><main class="main"><div class="top"><h1>SYSTEM</h1></div></main><script>window.csrf='csrf-token';</script><script>${systemStatusJs}</script></body></html>`,
  }));
  await page.route('**/discadmin/technical.php?action=overview', route => route.fulfill({contentType:'application/json',body:JSON.stringify(overview)}));
  await page.route('**/api/content-health.php', route => route.fulfill({contentType:'application/json',body:JSON.stringify(health)}));
  await page.route('**/api/admin-activity.php?limit=5', route => route.fulfill({contentType:'application/json',body:JSON.stringify(activity)}));
  await page.route('**/discadmin/technical.php?action=logs', route => route.fulfill({
    contentType:'application/json',
    body:JSON.stringify(cleared ? {ok:true,file:'storage/logs/brvtal.log',bytes:0,lines:0,content:''} : {ok:true,file:'storage/logs/brvtal.log',bytes:16,lines:1,content:'before reset'})
  }));
  await page.route('**/discadmin/logs.php?action=clear&format=json', async route => {
    resetCalls++;
    expect(route.request().method()).toBe('POST');
    expect(route.request().postData()).toContain('csrf=csrf-token');
    if (resetStatus !== 200) return route.fulfill({status:resetStatus,contentType:'application/json',body:JSON.stringify({ok:false,error:'LOG_CLEAR_FAILED'})});
    cleared = true;
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,file:'storage/logs/brvtal.log',bytes:0,lines:0})});
  });
  await page.goto(harness);
  await expect(page.locator('#system-status-v2')).toBeVisible();
  await page.locator('.ssv2-advanced summary').click();
  return {resetCalls:()=>resetCalls};
}

test('RESET LOG confirms, posts CSRF, and refreshes visible log state', async ({page}) => {
  const state = await mount(page);
  await page.getByRole('button',{name:'LOAD RECENT LOGS'}).click();
  await expect(page.locator('#ssv2-logs')).toContainText('before reset');
  await expect(page.locator('#ssv2-log-meta')).toHaveText('1 LINES · 16 B');

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button',{name:'RESET LOG'}).click();

  await expect(page.locator('#ssv2-logs')).toHaveText('No log entries.');
  await expect(page.locator('#ssv2-log-meta')).toHaveText('0 LINES · 0 B');
  expect(state.resetCalls()).toBe(1);
});

test('RESET LOG cancellation sends no mutation', async ({page}) => {
  const state = await mount(page);
  page.once('dialog', dialog => dialog.dismiss());
  await page.getByRole('button',{name:'RESET LOG'}).click();
  expect(state.resetCalls()).toBe(0);
});

test('RESET LOG failure preserves the current visible log', async ({page}) => {
  await mount(page, 500);
  await page.getByRole('button',{name:'LOAD RECENT LOGS'}).click();
  await expect(page.locator('#ssv2-logs')).toContainText('before reset');

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button',{name:'RESET LOG'}).click();

  await expect(page.locator('#ssv2-logs')).toContainText('before reset');
  await expect(page.locator('#ssv2-log-meta')).toContainText('RESET FAILED · LOG_CLEAR_FAILED');
});
