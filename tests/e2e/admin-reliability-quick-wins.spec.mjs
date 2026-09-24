import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const authBoundary = readFileSync(join(process.cwd(), 'discadmin/admin-auth-boundary.js'), 'utf8');
const adminReliability = readFileSync(join(process.cwd(), 'discadmin/admin-reliability.js'), 'utf8');
const heroAccessibility = readFileSync(join(process.cwd(), 'discadmin/hero-slider-accessibility.js'), 'utf8');
const storageStatus = readFileSync(join(process.cwd(), 'discadmin/system-status-storage.js'), 'utf8');
const backups = readFileSync(join(process.cwd(), 'discadmin/backups.js'), 'utf8');

test('same-origin admin 401 returns the shell to login state', async ({ page }) => {
  await page.route('https://www.brvtal.test/**', async route => {
    const url = route.request().url();
    if (url.includes('/api/media-library.php')) {
      await route.fulfill({status:401, contentType:'application/json', body:'{"ok":false,"error":"AUTH_REQUIRED"}'});
      return;
    }
    await route.fulfill({status:200, contentType:'text/html', body:`<!doctype html><html><body><script>
      window.csrf = 'csrf-token';
      window.state = {authed:true};
      var renderCount = 0;
      var closeCount = 0;
      var authEvents = 0;
      function render(){ renderCount += 1; }
      function closeModal(){ closeCount += 1; }
      addEventListener('brvtal:auth-required', () => authEvents += 1);
    </script></body></html>`});
  });

  await page.goto('https://www.brvtal.test/discadmin');
  await page.addScriptTag({content: authBoundary});
  const result = await page.evaluate(async () => {
    const response = await fetch('/api/media-library.php');
    return {status:response.status, authed:window.state.authed, csrf:window.csrf, renderCount, closeCount, authEvents};
  });

  expect(result).toEqual({status:401, authed:false, csrf:'', renderCount:1, closeCount:1, authEvents:1});
});

test('same-origin 401 preserves a dirty editor instead of rendering it away', async ({ page }) => {
  await page.route('https://www.brvtal.test/**', async route => {
    const url = route.request().url();
    if (url.includes('/api/media-library.php')) {
      await route.fulfill({status:401, contentType:'application/json', body:'{"ok":false,"error":"AUTH_REQUIRED"}'});
      return;
    }
    await route.fulfill({status:200, contentType:'text/html', body:`<!doctype html><html><body>
      <div id="modal" class="open"><input value="unsaved"></div>
      <script>
        window.csrf='csrf-token';
        window.state={authed:true};
        window.renderCount=0;
        window.closeCount=0;
        window.authEvents=0;
        window.render=()=>window.renderCount++;
        window.closeModal=()=>window.closeCount++;
        window.BRVTALUnsavedChanges={hasDirtyChanges:()=>true};
        addEventListener('brvtal:auth-required',()=>window.authEvents++);
      </script>
    </body></html>`});
  });

  await page.goto('https://www.brvtal.test/discadmin');
  await page.addScriptTag({content:authBoundary});
  const result = await page.evaluate(async () => {
    const response=await fetch('/api/media-library.php');
    return {
      status:response.status,
      authed:window.state.authed,
      csrf:window.csrf,
      renderCount:window.renderCount,
      closeCount:window.closeCount,
      authEvents:window.authEvents,
      deferred:document.documentElement.dataset.brvtalAuthRequired,
      notice:document.querySelectorAll('[data-brvtal-auth-required="1"]').length
    };
  });

  expect(result).toEqual({
    status:401,
    authed:false,
    csrf:'',
    renderCount:0,
    closeCount:0,
    authEvents:1,
    deferred:'unsaved',
    notice:1
  });
});

test('logout refuses dependent render when the confirmed editor changes before commit', async ({ page }) => {
  await page.setContent(`<!doctype html><html><body><div id="modal" class="open"></div><script>
    window.state={authed:true};
    window.csrf='csrf-token';
    window.renderCount=0;
    window.heroCommits=0;
    window.preservedAuth=0;
    window.req=async()=>({ok:true});
    window.render=()=>window.renderCount++;
    window.BRVTALUnsavedChanges={
      requestNavigation:()=>91,
      commitNavigation:token=>{window.commitToken=token;return false;},
      cancelNavigation:()=>true
    };
    window.BRVTALHeroSliderGuard={
      requestNavigation:()=>true,
      commitNavigation:()=>window.heroCommits++
    };
    window.BRVTALAdminAuthBoundary={
      preserveUnsavedAuthState:()=>{window.preservedAuth++;return true;},
      clearDeferredAuthState:()=>{}
    };
  </script></body></html>`);
  await page.addScriptTag({content:adminReliability});

  const result = await page.evaluate(async () => ({
    outcome:await window.logout(),
    authed:window.state.authed,
    csrf:window.csrf,
    commitToken:window.commitToken,
    renderCount:window.renderCount,
    heroCommits:window.heroCommits,
    preservedAuth:window.preservedAuth
  }));

  expect(result).toEqual({
    outcome:false,
    authed:false,
    csrf:'',
    commitToken:91,
    renderCount:0,
    heroCommits:0,
    preservedAuth:1
  });
});

test('Sets navigation renders before relation hydration and reuses one bounded hydration', async ({ page }) => {
  await page.setContent(`<!doctype html><html><body>
    <main id="main">DASHBOARD</main>
    <script>
      window.state={authed:true,artists:[],events:[]};
      window.__relationCalls=[];
      window.__relationResolvers=[];
      window.__opened=null;
      window.req=(path,options={})=>{
        if (!['/artists','/events'].includes(path)) return Promise.resolve({data:[]});
        window.__relationCalls.push({path,hasSignal:Boolean(options.signal)});
        return new Promise(resolve=>window.__relationResolvers.push(() => resolve({
          data:path==='/artists'?[{id:11,name:'Artist'}]:[{id:22,title:'Event'}]
        })));
      };
      window.go=async section=>{
        window.__navigated=section;
        document.getElementById('main').textContent=String(section).toUpperCase();
        return 'navigated';
      };
      window.openModal=(type,id)=>{window.__opened={type,id};return 'opened';};
    </script>
  </body></html>`);
  await page.addScriptTag({content:adminReliability});

  expect(await page.evaluate(() => window.go('sets'))).toBe('navigated');
  expect(await page.locator('#main').innerText()).toBe('SETS');
  expect(await page.evaluate(() => window.__relationCalls)).toEqual([
    {path:'/artists',hasSignal:true},
    {path:'/events',hasSignal:true}
  ]);

  const before = await page.evaluate(() => {
    window.__modalPromise = window.openModal('sets', 7);
    return {calls:window.__relationCalls.length,opened:window.__opened};
  });
  expect(before).toEqual({calls:2,opened:null});

  await page.evaluate(() => window.__relationResolvers.splice(0).forEach(resolve => resolve()));
  const after = await page.evaluate(async () => ({
    result:await window.__modalPromise,
    calls:window.__relationCalls.length,
    opened:window.__opened,
    artists:window.state.artists,
    events:window.state.events
  }));
  expect(after).toEqual({
    result:'opened',
    calls:2,
    opened:{type:'sets',id:7},
    artists:[{id:11,name:'Artist'}],
    events:[{id:22,title:'Event'}]
  });
});

test('Hero Slider rows expose and execute keyboard reorder shortcuts', async ({ page }) => {
  await page.setContent(`<!doctype html><html><body>
    <button type="button" class="hero-slide-row" data-select-slide="slide-1">
      <span>Slide one</span>
      <span><i data-move="up"></i><i data-move="down"></i></span>
    </button>
    <script>
      window.moves = [];
      document.querySelector('[data-move="up"]').addEventListener('click', () => moves.push('up'));
      document.querySelector('[data-move="down"]').addEventListener('click', () => moves.push('down'));
    </script>
  </body></html>`);
  await page.addScriptTag({content: heroAccessibility});

  const row = page.locator('.hero-slide-row');
  await expect(row).toHaveAttribute('aria-keyshortcuts', 'Alt+ArrowUp Alt+ArrowDown');
  await row.focus();
  await page.keyboard.press('Alt+ArrowUp');
  await page.keyboard.press('Alt+ArrowDown');
  expect(await page.evaluate(() => moves)).toEqual(['up','down']);
});

test('managed storage failure replaces host capacity with an explicit unavailable state', async ({ page }) => {
  await page.setContent(`<!doctype html><html><body>
    <div id="system-status-v2">
      <section class="ssv2-panel storage">
        <div class="ssv2-panel-head"><b>999 GB HOST</b></div>
        <div class="ssv2-storage-ring"><strong>75%</strong></div>
        <div class="ssv2-storage-copy"><strong>999 GB / 1 TB</strong><span>250 GB FREE</span><small>host filesystem</small></div>
      </section>
    </div>
    <script>window.fetch = async () => new Response('{}', {status:500, headers:{'Content-Type':'application/json'}});</script>
  </body></html>`);
  await page.addScriptTag({content: storageStatus});
  await page.evaluate(() => window.BRVTALManagedStorageStatus.applyUnavailable());

  const panel = page.locator('.ssv2-panel.storage');
  await expect(panel).toHaveAttribute('data-storage-scope', 'unavailable');
  await expect(panel.locator('.ssv2-panel-head b')).toHaveText('UNAVAILABLE · BRVTAL DATA');
  await expect(panel.locator('.ssv2-storage-copy strong')).toHaveText('MANAGED STORAGE UNAVAILABLE');
  await expect(panel).not.toContainText('999 GB');
});

test('Backups renders the full retained collection instead of truncating after eight', async ({ page }) => {
  await page.setContent('<!doctype html><html><body><div id="target"></div></body></html>');
  await page.addScriptTag({content: backups});
  const count = await page.evaluate(() => {
    const items = Array.from({length:10}, (_, index) => ({
      id:`backup-${index + 1}`,
      status:'complete',
      created_at:'2026-09-16T00:00:00Z',
      artifacts_size:'1 MB',
      deployment:{short_commit:'abc1234'},
      components:{}
    }));
    document.getElementById('target').innerHTML = window.BRVTALBackupsUI.backupRows(items);
    return document.querySelectorAll('.backup-row').length;
  });
  expect(count).toBe(10);
});
