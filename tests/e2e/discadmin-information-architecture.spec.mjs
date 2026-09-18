import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const iaJs = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.js'), 'utf8');
const iaCss = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.css'), 'utf8');
const wrapper = readFileSync(join(process.cwd(), 'discadmin/index.php'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin-ia-e2e.html';

function harness(authed = true) {
  return `<!doctype html><html><head><style>${iaCss}</style></head><body><div id="app"></div><script>
    window.state={authed:${authed ? 'true' : 'false'},section:'dashboard',rows:[]};
    window.__legacyOpen=[];
    window.__moduleLoadOptions=[];
    window.__nativeGo=[];
    window.__nativeRenders=[];
    window.__moduleCancels=0;
    window.__requestLog=[];
    window.__deferredRequests={};
    window.__requestResolvers={};
    window.req=function(path){
      window.__requestLog.push(path);
      if(window.__deferredRequests[path]){
        return new Promise(resolve=>{window.__requestResolvers[path]=resolve;});
      }
      return Promise.resolve({data:[{source:path}]});
    };
    window.__resolveRequest=function(path,data=[{source:path}]){
      const resolve=window.__requestResolvers[path];
      delete window.__deferredRequests[path];
      delete window.__requestResolvers[path];
      if(resolve)resolve({data});
    };
    function navButton(label,handler,data=''){return '<button '+(data?'data-admin-nav="'+data+'" ':'')+'onclick="'+handler+'">'+label+'</button>'}
    window.__renderShell=function(section){
      state.section=section;
      window.__nativeRenders.push(section);
      let sectionContent='<div class="native-view">NATIVE '+section.toUpperCase()+'</div>';
      if(section==='events'){
        sectionContent='<div data-canonical-events-list="1"><div class="toolbar"><input class="search" placeholder="Search events..."><button class="btn red" data-new-event>+ NEW EVENT</button></div><div class="table" data-events-table="canonical">EVENTS TABLE</div></div>';
      }else if(section==='artists'){
        sectionContent='<div class="toolbar"><input class="search"><button class="btn red">+ NEW ARTIST</button></div>';
      }
      document.getElementById('app').innerHTML='<div class="shell"><aside class="side"><div class="nav">'
        +navButton('DASHBOARD',"go('dashboard')")
        +navButton('EVENTS',"go('events')")
        +navButton('ARTISTS',"go('artists')")
        +navButton('SETS',"go('sets')")
        +navButton('MEDIA',"go('media')")
        +navButton('PAGES',"go('pages')")
        +navButton('CONTENT CORE',"go('content-core')")
        +navButton('THEME STUDIO',"go('theme')")
        +navButton('SETTINGS',"go('settings')")
        +navButton('SECURITY / 2FA',"go('security')")
        +navButton('SYSTEM STATUS',"tech('system')")
        +navButton('RELEASES',"go('releases')",'releases')
        +navButton('BLOG',"go('blog')",'blog')
        +navButton('HERO SLIDER',"go('hero-slider')",'hero-slider')
        +navButton('BACKUPS',"go('backups')",'backups')
        +navButton('ACTIVITY',"go('activity')",'activity')
        +'</div></aside><main class="main"><div class="top"><h1>'+section.toUpperCase()+'</h1></div>'+sectionContent+'</main></div>';
      document.querySelector('[data-new-event]')?.addEventListener('click',()=>window.openModal('events'));
    };
    window.go=async function(section){
      window.__nativeGo.push(section);
      state.section=section;
      if(['events','artists','sets','media','pages','settings'].includes(section)){
        const response=await req('/'+section);
        state.rows=response.data||[];
      }
      window.__renderShell(section);
    };
    window.tech=async function(section){window.__renderShell(section);};
    window.openModal=function(type,id){window.__legacyOpen.push([type,id]);};
    window.BRVTALAdminModules={
      cancel(){window.__moduleCancels+=1;},
      async load(section,options={}){
        if(section!=='content-core')return;
        window.__moduleLoadOptions.push(options);
        state.section=section;
        window.__renderShell(section);
        let host=document.getElementById('admin-module-host');
        if(!host){
          host=document.createElement('div');
          host.id='admin-module-host';
          document.querySelector('.main').appendChild(host);
        }
        host.innerHTML='<section data-admin-module="content-core"><div class="wrap"><div class="tabs"><button data-tab="events" class="active">EVENT EDITOR</button><button data-tab="roster">COLLECTIVE ROSTER</button></div><section id="eventsTab">EVENTS TABLE</section><section id="rosterTab" style="display:none">ROSTER TABLE</section></div><div id="eventModal"><div class="ey">CONTENT CORE / EVENT</div></div></section>';
        const root=host.firstElementChild;
        root.querySelector('[data-tab="roster"]').addEventListener('click',()=>{root.querySelector('#eventsTab').style.display='none';root.querySelector('#rosterTab').style.display='block';});
        window.BRVTALContentCore={openEvent(id){window.__openedEvent=id??'new';}};
      }
    };
    window.__renderShell('dashboard');
  </script><script>${iaJs}</script></body></html>`;
}

async function serveHarness(page, {authed = true} = {}) {
  await page.route('**/discadmin-ia-e2e.html*', route => route.fulfill({contentType:'text/html; charset=utf-8',body:harness(authed)}));
}

test('sidebar exposes destinations while Content Core stays internal', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await expect(page.locator('.ia-navgroup')).toHaveText(['CONTENT','MEDIA','SITE','SYSTEM']);
  await expect(page.getByRole('button',{name:'CONTENT CORE'})).toBeHidden();

  const visibleLabels = await page.locator('.side .nav > button:not([data-ia-hidden="1"])').allTextContents();
  expect(visibleLabels.indexOf('EVENTS')).toBeLessThan(visibleLabels.indexOf('MEDIA'));
  expect(visibleLabels.indexOf('MEDIA')).toBeLessThan(visibleLabels.indexOf('THEME STUDIO'));
  expect(visibleLabels.indexOf('THEME STUDIO')).toBeLessThan(visibleLabels.indexOf('SYSTEM STATUS'));
});

test('navigation label fallbacks preserve canonical and fuzzy destination keys', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  const keys = await page.evaluate(() => {
    const nav = document.querySelector('.side .nav');
    const labels = [
      'MEDIA LIBRARY',
      'PRIMARY HERO SLIDER',
      'VISUAL THEME TOOLS',
      'ACCOUNT 2FA',
      'SYSTEM STATUS / HOST',
      'BACKUP VAULT',
      'ADMIN ACTIVITY LOG',
      'LEGACY CONTENT CORE',
      'SEO TOOLS',
      'CUSTOM TOOL'
    ];
    const buttons = labels.map(label => {
      const button = document.createElement('button');
      button.textContent = label;
      nav.appendChild(button);
      return button;
    });
    window.BRVTALAdminIA.rebuildNavigation();
    return Object.fromEntries(buttons.map(button => [button.textContent, button.dataset.iaKey]));
  });

  expect(keys).toEqual({
    'MEDIA LIBRARY':'media',
    'PRIMARY HERO SLIDER':'hero-slider',
    'VISUAL THEME TOOLS':'theme',
    'ACCOUNT 2FA':'security',
    'SYSTEM STATUS / HOST':'system',
    'BACKUP VAULT':'backups',
    'ADMIN ACTIVITY LOG':'activity',
    'LEGACY CONTENT CORE':'content-core',
    'SEO TOOLS':'seo',
    'CUSTOM TOOL':'other:custom tool'
  });
  await expect(page.getByRole('button',{name:'LEGACY CONTENT CORE'})).toBeHidden();
});

test('Events is the single entry to the guided event editor', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => window.go('events'));
  await expect(page.locator('.main .top h1')).toHaveText('EVENTS');
  await expect(page.locator('[data-admin-module="content-core"]')).toHaveAttribute('data-ia-context','events');
  await expect(page.locator('[data-canonical-events-list]')).toBeVisible();
  await expect(page.locator('.main .toolbar .search:visible')).toHaveCount(1);
  await expect(page.locator('[data-admin-module="content-core"] .wrap')).toBeHidden();
  await expect(page.locator('[data-admin-module="content-core"] .wrap')).toHaveAttribute('data-ia-internal-only','1');
  await expect(page.locator('#eventsTab')).toBeHidden();
  await expect(page.locator('#rosterTab')).toBeHidden();
  await expect(page.locator('#eventModal .ey')).toHaveText('EVENTS / EDITOR');
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('events');
  await expect.poll(() => page.evaluate(() => window.__moduleLoadOptions.at(-1)?.syncUrl)).toBe(false);
  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('events');

  await page.getByRole('button',{name:'+ NEW EVENT',exact:true}).click();
  await expect.poll(() => page.evaluate(() => window.__openedEvent)).toBe('new');
  await page.evaluate(() => window.openModal('events', 42));
  await expect.poll(() => page.evaluate(() => window.__openedEvent)).toBe(42);
  await expect.poll(() => page.evaluate(() => window.__legacyOpen.length)).toBe(0);
});

test('collective membership is an Artists sub-workflow instead of a top-level module', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => window.go('artists'));
  const collective = page.getByRole('button',{name:'COLLECTIVE STATUS'});
  await expect(collective).toBeVisible();
  await collective.click();

  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  await expect(page.locator('[data-admin-module="content-core"]')).toHaveAttribute('data-ia-context','artists-roster');
  await expect(page.locator('#eventsTab')).toBeHidden();
  await expect(page.locator('#rosterTab')).toBeVisible();
  await expect(page.getByRole('button',{name:'← ARTIST PROFILES'})).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('artists');
  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('artists');
});

test('DISCADMIN destinations persist in URL and browser Back restores the workspace', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => window.go('artists'));
  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('artists');
  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');

  await page.evaluate(() => window.go('pages'));
  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('pages');
  await expect(page.locator('.main .top h1')).toHaveText('PAGES');

  await page.goBack();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('artists');
  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  expect(new URL(page.url()).searchParams.get('module')).toBe('artists');
});

test('direct native destination URL restores after the IA layer boots', async ({ page }) => {
  await serveHarness(page);
  await page.goto(`${harnessUrl}?module=artists`);

  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('artists');
  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  await expect(page.getByRole('button',{name:'COLLECTIVE STATUS'})).toBeVisible();
  expect(new URL(page.url()).searchParams.get('module')).toBe('artists');
});

test('direct Events URL restores the guided editor rather than exposing Content Core', async ({ page }) => {
  await serveHarness(page);
  await page.goto(`${harnessUrl}?module=events`);

  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('events');
  await expect(page.locator('[data-admin-module="content-core"]')).toHaveAttribute('data-ia-context','events');
  await expect(page.getByRole('button',{name:'CONTENT CORE'})).toBeHidden();
  expect(new URL(page.url()).searchParams.get('module')).toBe('events');
});

test('deep link survives authentication redirect to Dashboard', async ({ page }) => {
  await serveHarness(page, {authed:false});
  await page.goto(`${harnessUrl}?module=artists`);

  await page.waitForTimeout(80);
  await page.evaluate(async () => {
    window.state.authed = true;
    await window.go('dashboard');
  });

  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('artists');
  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  await expect(page.getByRole('button',{name:'COLLECTIVE STATUS'})).toBeVisible();
  expect(new URL(page.url()).searchParams.get('module')).toBe('artists');
});

test('latest navigation wins when an older dynamic module dependency resolves late', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => {
    const media = document.createElement('script');
    media.id = 'brvtal-media-library-script';
    media.dataset.ready = '1';
    document.head.appendChild(media);

    const releases = document.createElement('script');
    releases.id = 'brvtal-releases-script';
    document.head.appendChild(releases);

    window.__staleReleaseNavigation = window.go('releases');
  });

  await expect.poll(() => page.evaluate(() => window.__nativeGo.includes('releases'))).toBe(false);

  await page.evaluate(() => window.go('media'));
  await expect(page.locator('.main .top h1')).toHaveText('MEDIA');
  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('media');

  await page.evaluate(() => {
    const releases = document.getElementById('brvtal-releases-script');
    releases.dataset.ready = '1';
    releases.dispatchEvent(new Event('load'));
  });
  await page.evaluate(() => window.__staleReleaseNavigation);

  await expect(page.locator('.main .top h1')).toHaveText('MEDIA');
  expect(await page.evaluate(() => window.state.section)).toBe('media');
  expect(new URL(page.url()).searchParams.get('module')).toBe('media');
  expect(await page.evaluate(() => window.__nativeGo)).toEqual(['media']);
  expect(await page.evaluate(() => window.__moduleCancels)).toBeGreaterThanOrEqual(2);
});

test('stale native response cannot overwrite the latest destination rows or render', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => {
    window.__deferredRequests['/artists'] = true;
    window.__slowArtistsNavigation = window.go('artists');
  });
  await expect.poll(() => page.evaluate(() => window.__requestLog.includes('/artists'))).toBe(true);

  await page.evaluate(() => window.go('pages'));
  await expect(page.locator('.main .top h1')).toHaveText('PAGES');
  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('pages');
  expect(await page.evaluate(() => window.state.rows[0]?.source)).toBe('/pages');

  await page.evaluate(() => window.__resolveRequest('/artists'));
  await page.evaluate(() => window.__slowArtistsNavigation);

  await expect(page.locator('.main .top h1')).toHaveText('PAGES');
  expect(await page.evaluate(() => window.state.section)).toBe('pages');
  expect(await page.evaluate(() => window.state.rows[0]?.source)).toBe('/pages');
  expect(new URL(page.url()).searchParams.get('module')).toBe('pages');
  expect(await page.evaluate(() => window.__nativeRenders.slice(-1)[0])).toBe('pages');
});

test('system destination participates in the same URL state', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => window.tech('system'));
  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('system');
  await expect(page.locator('.main .top h1')).toHaveText('SYSTEM');
});

test('DISCADMIN wrapper loads the IA layer after existing enhancements', async () => {
  expect(wrapper).toContain('/discadmin/admin-information-architecture.css');
  expect(wrapper).toContain('/discadmin/admin-information-architecture.js');
  expect(wrapper.indexOf('admin-appearance.js')).toBeLessThan(wrapper.indexOf('admin-information-architecture.js'));
});