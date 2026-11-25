import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const recordNavigationJs = readFileSync(join(process.cwd(), 'discadmin/content-core-nav.js'), 'utf8');
const contentHealthJs = readFileSync(join(process.cwd(), 'discadmin/content-health.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/content-health-record-navigation-e2e.html';

test('Content Health OPEN preserves health routing when data-health-open is present but empty', async ({ page }) => {
  let healthRequests = 0;
  await page.route('**/api/content-health.php', route => {
    healthRequests += 1;
    return route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({
      ok: true,
      data: {
        score: 72,
        ready: 0,
        needs_attention: 1,
        missing_visuals: 0,
        seo_gaps: 1,
        items: [{
          id: 7,
          type: 'events',
          title: 'GENESIS',
          score: 72,
          issues: ['Missing SEO description'],
        }],
      },
    }),
  });
  });

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><meta charset="utf-8"></head><body>
      <main class="main"><div class="top"><h1>DASHBOARD</h1></div></main>
      <script>
        var state={authed:true,section:'dashboard'};
        window.go=async function(section){state.section=section;window.__went=section;return section;};
        window.BRVTALContentCore={openEvent:async function(id){window.__openedRecordId=Number(id);}};
        window.BRVTALFeedback={error:function(message){window.__feedback=message;}};
        window.__nativeSetTimeout=window.setTimeout.bind(window);
        window.setTimeout=function(callback,delay,...args){
          if(Number(delay)===50){window.__healthFallback=()=>callback(...args);return 817;}
          return window.__nativeSetTimeout(callback,delay,...args);
        };
      </script>
      <script>${recordNavigationJs}</script>
      <script>${contentHealthJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);
  await expect(page.getByRole('heading', { name: 'CONTENT HEALTH' })).toBeVisible();
  await expect(page.locator('#brvtal-content-health')).toContainText('GENESIS');

  const openButton = page.getByRole('button', { name: 'OPEN' });
  await expect(openButton).toHaveAttribute('data-health-open', 'events');
  await expect.poll(() => healthRequests).toBe(1);
  await page.evaluate(() => window.BRVTALContentHealth.refresh());
  await expect.poll(() => healthRequests).toBe(2);
  await expect(page.locator('#brvtal-content-health')).toContainText('GENESIS');
  await openButton.evaluate(button => button.setAttribute('data-health-open', ''));
  await expect(openButton).toHaveAttribute('data-health-open', '');
  await page.evaluate(() => window.__healthFallback());
  await expect(openButton).toHaveAttribute('data-health-open', '');
  await openButton.click();

  await expect.poll(() => page.evaluate(() => ({
    section: window.__went,
    recordId: window.__openedRecordId,
    feedback: window.__feedback || '',
  }))).toEqual({section:'events',recordId:7,feedback:''});
});

test('Memories smoke uses real Media subview while hidden sentinel remains inaccessible', async ({ page }) => {
  const smokeSource = readFileSync(join(process.cwd(), 'tests/e2e/production-authenticated-smoke.mjs'), 'utf8');
  const memoriesJs = readFileSync(join(process.cwd(), 'discadmin/memories.js'), 'utf8');
  const start = smokeSource.indexOf('// BEGIN_MEMORIES_NAVIGATION_CONTRACT');
  const end = smokeSource.indexOf('// END_MEMORIES_NAVIGATION_CONTRACT');
  expect(start).toBeGreaterThan(-1);
  expect(end).toBeGreaterThan(start);
  // The fixture invokes the actual smoke helper and the real Memories module.
  const openMemoriesWorkspace = new Function(smokeSource.slice(start, end) + '\nreturn openMemoriesWorkspace;')();
  const fixtureUrl = 'http://127.0.0.1:4173/memories-smoke-navigation-e2e.html';
  await page.route(fixtureUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: "<!doctype html><html><body><div class='side'><div class='nav'><button type='button' hidden data-memories-nav='1' data-ia-hidden='1' aria-hidden='true'></button></div></div><div class='main'><div class='top'><h1>MEDIA</h1></div></div><div id='admin-module-host'></div><script>var state={authed:true,section:'dashboard'};window.go=async function(section){if(section!=='media')return false;state.section='media';return true;};</script></body></html>"
  }));
  const methods = [];
  await page.route(/\/api\/memories\.php\?/, async route => {
    methods.push(route.request().method());
    const action = new URL(route.request().url()).searchParams.get('action');
    return route.fulfill({
      status: 200, contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({ok:true, data: action === 'catalog' ? {} : [], relations_ready:false})
    });
  });
  await page.goto(fixtureUrl);
  await page.addScriptTag({content: memoriesJs});
  await expect(page.locator('[data-memories-nav]')).toBeHidden();
  await openMemoriesWorkspace(page);
  await expect(page.locator('#admin-module-host [data-admin-module="memories"]')).toBeVisible();
  await expect(page.locator('[data-memories-grid]')).toBeAttached();
  expect(new URL(page.url()).searchParams.get('view')).toBe('memories');
  expect(await page.evaluate(() => state.section)).toBe('media');
  expect(methods).toEqual(['GET', 'GET', 'GET']);

  // A fake/failed transition must never pass merely because an old grid exists.
  await page.evaluate(() => {
    state.section = 'dashboard';
    window.history.replaceState({}, '', '/memories-smoke-navigation-e2e.html');
    window.go = async () => false;
  });
  await expect(openMemoriesWorkspace(page)).rejects.toThrow(
    'Memories navigation did not commit the canonical Media subview.'
  );
});

test('Pages smoke commits canonical sidebar state and fails closed on backend 503', async ({ page }) => {
  const smokeSource = readFileSync(join(process.cwd(), 'tests/e2e/production-authenticated-smoke.mjs'), 'utf8');
  const start = smokeSource.indexOf('// BEGIN_ADMIN_NAVIGATION_CONTRACT');
  const end = smokeSource.indexOf('// END_ADMIN_NAVIGATION_CONTRACT');
  expect(start).toBeGreaterThan(-1);
  expect(end).toBeGreaterThan(start);
  const navigate = new Function('runOperation', 'operationTimeoutMs',
    smokeSource.slice(start, end) + '\nreturn navigate;')(
      async (_name, operation) => operation(), 1200
    );
  const fixtureUrl = 'http://127.0.0.1:4173/pages-smoke-navigation-e2e.html';
  await page.route(fixtureUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: "<!doctype html><html><body><div class='side'><div class='nav'><button type='button' data-admin-nav='pages' onclick=\"window.go('pages')\">PÁGINAS</button></div></div><div class='main'><div id='rows'></div></div><script>var state={authed:true,section:'hero-slider'};window.go=async function(section){const response=await fetch('/api/index.php/'+section);if(!response.ok)return false;state.section=section;document.querySelector('#rows').textContent='PAGES';return true;};</script></body></html>"
  }));
  let status = 200;
  const requests = [];
  await page.route('**/api/index.php/pages', route => {
    requests.push(route.request().method());
    return route.fulfill({
      status, contentType: 'application/json; charset=utf-8',
      body: JSON.stringify(status === 200 ? {ok:true,data:[]} :
        {ok:false,error:'AUTH_REVALIDATION_UNAVAILABLE',code:'DB_CONNECT_UNAVAILABLE'})
    });
  });
  await page.goto(fixtureUrl);
  await navigate(page, 'pages');
  await expect(page.locator('#rows')).toHaveText('PAGES');
  expect(await page.evaluate(() => state.section)).toBe('pages');
  status = 503;
  await page.evaluate(() => {state.section = 'hero-slider';});
  await expect(navigate(page, 'pages')).rejects.toThrow(/Timeout/);
  expect(await page.evaluate(() => state.section)).toBe('hero-slider');
  expect(requests).toEqual(['GET', 'GET']);
});
