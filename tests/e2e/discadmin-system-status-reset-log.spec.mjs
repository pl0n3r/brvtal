import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const systemStatusJs = readFileSync(join(process.cwd(), 'discadmin/system-status-v2.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/e2e-system-status-reset-log.html';
const JSON_TYPE = 'application/json';

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

async function mount(page, options = {}) {
  const {
    resetStatuses = [200],
    initialCsrf = 'csrf-token',
    authCsrf = 'fresh-token',
    holdFirstLog = false,
    holdReset = false,
  } = options;

  let resetCalls = 0;
  let authCalls = 0;
  let logCalls = 0;
  let cleared = false;
  const resetPosts = [];
  let releaseFirstLog = () => {};
  let firstLogGate = null;
  let releaseReset = () => {};
  let resetGate = null;

  if (holdFirstLog) {
    firstLogGate = new Promise(resolve => {
      releaseFirstLog = resolve;
    });
  }
  if (holdReset) {
    resetGate = new Promise(resolve => {
      releaseReset = resolve;
    });
  }

  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body><nav class="nav"><button class="active">SYSTEM STATUS</button></nav><main class="main"><div class="top"><h1>SYSTEM</h1></div></main><script>globalThis.csrf=${JSON.stringify(initialCsrf)};</script><script>${systemStatusJs}</script></body></html>`,
  }));
  await page.route('**/discadmin/technical.php?action=overview', route => route.fulfill({contentType:JSON_TYPE,body:JSON.stringify(overview)}));
  await page.route('**/api/content-health.php', route => route.fulfill({contentType:JSON_TYPE,body:JSON.stringify(health)}));
  await page.route('**/api/admin-activity.php?limit=5', route => route.fulfill({contentType:JSON_TYPE,body:JSON.stringify(activity)}));
  await page.route('**/api/index.php/auth', route => {
    authCalls++;
    return route.fulfill({contentType:JSON_TYPE,body:JSON.stringify({ok:true,authenticated:true,csrf:authCsrf})});
  });
  await page.route('**/discadmin/technical.php?action=logs', async route => {
    logCalls++;
    if (holdFirstLog && logCalls === 1) {
      await firstLogGate;
      return route.fulfill({
        contentType:JSON_TYPE,
        body:JSON.stringify({ok:true,file:'storage/logs/brvtal.log',bytes:16,lines:1,content:'before reset'}),
      });
    }
    return route.fulfill({
      contentType:JSON_TYPE,
      body:JSON.stringify(cleared
        ? {ok:true,file:'storage/logs/brvtal.log',bytes:0,lines:0,content:''}
        : {ok:true,file:'storage/logs/brvtal.log',bytes:16,lines:1,content:'before reset'})
    });
  });
  await page.route('**/discadmin/logs.php?action=clear&format=json', async route => {
    resetCalls++;
    const postData = route.request().postData() || '';
    resetPosts.push(postData);
    expect(route.request().method()).toBe('POST');
    if (holdReset && resetCalls === 1) {
      await resetGate;
    }
    const status = resetStatuses[Math.min(resetCalls - 1, resetStatuses.length - 1)];
    if (status !== 200) {
      const error = status === 419 ? 'CSRF' : 'LOG_CLEAR_FAILED';
      return route.fulfill({status,contentType:JSON_TYPE,body:JSON.stringify({ok:false,error})});
    }
    cleared = true;
    return route.fulfill({contentType:JSON_TYPE,body:JSON.stringify({ok:true,file:'storage/logs/brvtal.log',bytes:0,lines:0})});
  });

  await page.goto(harness);
  await expect(page.locator('#system-status-v2')).toBeVisible();
  await page.locator('.ssv2-advanced summary').click();

  return {
    resetCalls:() => resetCalls,
    authCalls:() => authCalls,
    resetPosts:() => [...resetPosts],
    releaseFirstLog,
    releaseReset,
  };
}

async function confirmReset(page, firstLabel = 'RESET LOG') {
  await page.getByRole('button',{name:firstLabel}).click();
  await expect(page.getByRole('button',{name:'CONFIRM RESET'})).toBeVisible();
  await page.getByRole('button',{name:'CONFIRM RESET'}).click();
}

test('RESET LOG confirms, posts CSRF, and refreshes visible log state', async ({page}) => {
  const state = await mount(page);
  await page.getByRole('button',{name:'LOAD RECENT LOGS'}).click();
  await expect(page.locator('#ssv2-logs')).toContainText('before reset');
  await expect(page.locator('#ssv2-log-meta')).toHaveText('1 LINES · 16 B');

  await confirmReset(page);

  await expect(page.locator('#ssv2-logs')).toHaveText('No log entries.');
  await expect(page.locator('#ssv2-log-meta')).toHaveText('0 LINES · 0 B');
  expect(state.resetCalls()).toBe(1);
  expect(state.resetPosts()[0]).toContain('csrf=csrf-token');
});

test('RESET LOG requires a second explicit activation before mutation', async ({page}) => {
  const state = await mount(page);
  await page.getByRole('button',{name:'RESET LOG'}).click();
  await expect(page.getByRole('button',{name:'CONFIRM RESET'})).toBeVisible();
  expect(state.resetCalls()).toBe(0);
});

test('RESET LOG failure preserves the current visible log', async ({page}) => {
  await mount(page,{resetStatuses:[500]});
  await page.getByRole('button',{name:'LOAD RECENT LOGS'}).click();
  await expect(page.locator('#ssv2-logs')).toContainText('before reset');

  await confirmReset(page);

  await expect(page.locator('#ssv2-logs')).toContainText('before reset');
  await expect(page.locator('#ssv2-log-meta')).toContainText('RESET FAILED · LOG_CLEAR_FAILED');
});

test('stale pre-reset log response cannot overwrite cleared state', async ({page}) => {
  const state = await mount(page,{holdFirstLog:true});

  await page.getByRole('button',{name:'LOAD RECENT LOGS'}).click();
  await confirmReset(page);
  await expect(page.locator('#ssv2-log-meta')).toHaveText('0 LINES · 0 B');
  await expect(page.locator('#ssv2-logs')).toHaveText('No log entries.');

  state.releaseFirstLog();
  await page.waitForTimeout(80);
  await expect(page.locator('#ssv2-log-meta')).toHaveText('0 LINES · 0 B');
  await expect(page.locator('#ssv2-logs')).toHaveText('No log entries.');
});

test('reset lock survives a System Status rerender while reset is in flight', async ({page}) => {
  const state = await mount(page,{holdReset:true});

  await page.getByRole('button',{name:'RESET LOG'}).click();
  await page.getByRole('button',{name:'CONFIRM RESET'}).click();
  await expect.poll(() => state.resetCalls()).toBe(1);

  await page.getByRole('button',{name:'REFRESH',exact:true}).click();
  const resetButton = page.locator('#ssv2-reset-logs');
  await expect(resetButton).toBeDisabled();

  await resetButton.dispatchEvent('click');
  expect(state.resetCalls()).toBe(1);

  state.releaseReset();
  await expect(resetButton).toBeEnabled();
  expect(state.resetCalls()).toBe(1);
});

test('failed reset after rerender updates the current controls and restores visible logs', async ({page}) => {
  const state = await mount(page,{holdReset:true,resetStatuses:[500]});

  await page.getByRole('button',{name:'LOAD RECENT LOGS'}).click();
  await expect(page.locator('#ssv2-logs')).toBeVisible();
  await expect(page.locator('#ssv2-logs')).toHaveText('before reset');
  await confirmReset(page);
  await expect.poll(() => state.resetCalls()).toBe(1);

  await page.getByRole('button',{name:'REFRESH',exact:true}).click();
  await expect(page.locator('#ssv2-reset-logs')).toBeDisabled();

  state.releaseReset();

  await expect(page.locator('#ssv2-reset-logs')).toBeEnabled();
  await expect(page.locator('#ssv2-reset-logs')).toHaveText('RETRY RESET');
  await expect(page.locator('#ssv2-log-meta')).toContainText('RESET FAILED · LOG_CLEAR_FAILED');
  await expect(page.locator('#ssv2-logs')).toBeVisible();
  await expect(page.locator('#ssv2-logs')).toHaveText('before reset');
  expect(state.resetCalls()).toBe(1);
});

test('CSRF failure clears cached token so retry fetches a fresh token', async ({page}) => {
  const state = await mount(page,{
    resetStatuses:[419,200],
    initialCsrf:'stale-token',
    authCsrf:'fresh-token',
  });

  await confirmReset(page);
  await expect(page.locator('#ssv2-log-meta')).toContainText('RESET FAILED · CSRF');
  expect(state.authCalls()).toBe(0);
  expect(state.resetPosts()[0]).toContain('csrf=stale-token');

  await confirmReset(page,'RETRY RESET');
  await expect(page.locator('#ssv2-log-meta')).toHaveText('0 LINES · 0 B');
  expect(state.authCalls()).toBe(1);
  expect(state.resetPosts()[1]).toContain('csrf=fresh-token');
});
