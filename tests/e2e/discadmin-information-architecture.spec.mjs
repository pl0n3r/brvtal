import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const iaJs = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.js'), 'utf8');
const iaCss = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.css'), 'utf8');
const wrapper = readFileSync(join(process.cwd(), 'discadmin/index.php'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin-ia-e2e.html';

function harness(authed = true) {
  return `<!doctype html><html><head><style>${iaCss}.legacy-test-modal{display:none}.legacy-test-modal.open{display:block}</style></head><body><div id="app"></div><div id="modal" class="legacy-test-modal"><button id="saveBtn" type="button">SAVE</button></div><script>
    window.state={authed:${authed ? 'true' : 'false'},section:'dashboard',rows:[],editing:null};
    window.__legacyOpen=[];
    window.__legacySaveCalls=0;
    window.__moduleLoadOptions=[];
    window.__nativeGo=[];
    window.__nativeRenders=[];
    window.__moduleCancels=0;
    window.__requestLog=[];
    window.__deferredRequests={};
    window.__requestResolvers={};
    window.__requestFailures={};
    window.__dynamicNavigationToken=0;
    window.req=function(path){
      window.__requestLog.push(path);
      if(window.__requestFailures[path]){
        return Promise.reject(new Error(window.__requestFailures[path]));
      }
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
        +navButton('SEO',"go('seo')",'seo')
        +navButton('BANNERS',"go('hero-slider')",'hero-slider')
        +navButton('BACKUPS',"go('backups')",'backups')
        +navButton('ACTIVITY',"go('activity')",'activity')
        +'</div></aside><main class="main"><div class="top"><h1>'+section.toUpperCase()+'</h1></div>'+sectionContent+'</main></div>';
      document.querySelector('[data-new-event]')?.addEventListener('click',()=>window.openModal('events'));
    };
    window.go=async function(section){
      if(['media','releases','blog','seo'].includes(section)){
        const token=++window.__dynamicNavigationToken;
        await window.BRVTALAdminModules.waitForSection(section);
        if(token!==window.__dynamicNavigationToken)return;
      }
      window.__nativeGo.push(section);
      state.section=section;
      if(['events','artists','sets','media','pages','settings'].includes(section)){
        const response=await req('/'+section);
        state.rows=response.data||[];
      }
      window.__renderShell(section);
    };
    window.tech=async function(section){window.__renderShell(section);};
    window.openModal=function(type,id){
      window.__legacyOpen.push([type,id]);
      state.editing=id;
      document.getElementById('saveBtn').onclick=()=>{window.__legacySaveCalls+=1;};
      document.getElementById('modal').classList.add('open');
    };
    window.closeModal=function(){
      document.getElementById('modal').classList.remove('open');
      state.editing=null;
      document.getElementById('saveBtn').onclick=null;
    };
    window.BRVTALAdminModules={
      cancel(){window.__moduleCancels+=1;window.__dynamicNavigationToken+=1;},
      async waitForSection(section){
        const dependencies={
          media:['brvtal-media-library-script'],
          releases:['brvtal-media-library-script','brvtal-releases-script'],
          blog:['brvtal-media-library-script','brvtal-blog-script'],
          seo:['brvtal-seo-workspace-script']
        }[section]||[];
        await Promise.all(dependencies.map(id=>{
          const script=document.getElementById(id);
          if(!script||script.dataset.ready==='1')return Promise.resolve();
          return new Promise((resolve,reject)=>{
            const onLoad=()=>{cleanup();resolve();};
            const onError=()=>{cleanup();reject(new Error('Unable to load '+id));};
            const cleanup=()=>{script.removeEventListener('load',onLoad);script.removeEventListener('error',onError);};
            script.addEventListener('load',onLoad,{once:true});
            script.addEventListener('error',onError,{once:true});
            if(script.dataset.ready==='1')onLoad();
          });
        }));
      },
      async load(section,options={}){
        if(section!=='content-core')return;
        window.__moduleLoadOptions.push(options);
        state.section=section;
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

test('sidebar exposes the canonical editorial and configuration hierarchy', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await expect(page.locator('.ia-navgroup')).toHaveText(['SITE / EDITORIAL','CONFIGURATION / TECHNICAL']);
  await expect(page.getByRole('button',{name:'CONTENT CORE'})).toBeHidden();
  await expect(page.getByRole('button',{name:'THEME STUDIO'})).toBeHidden();
  await expect(page.getByRole('button',{name:'SECURITY / 2FA'})).toBeHidden();

  const visibleLabels = await page.locator('.side .nav > button:not([data-ia-hidden="1"])').allTextContents();
  expect(visibleLabels).toEqual([
    'DASHBOARD',
    'BANNERS',
    'EVENTS',
    'ARTISTS',
    'RELEASES',
    'SETS',
    'MEDIA',
    'PAGES',
    'BLOG',
    'SEO',
    'SETTINGS',
    'SYSTEM STATUS'
  ]);
});

test('navigation label fallbacks preserve canonical and fuzzy destination keys', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  const keys = await page.evaluate(() => {
    const nav = document.querySelector('.side .nav');
    const labels = [
      'MEDIA LIBRARY',
      'PRIMARY HERO SLIDER',
      'BANNERS',
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
    'BANNERS':'hero-slider',
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

test('Memories stays directly after Media and owns the active state only in its view', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => {
    const nav = document.querySelector('.side .nav');
    const media = [...nav.querySelectorAll(':scope > button')].find(button => button.textContent.trim() === 'MEDIA');
    const memories = document.createElement('button');
    memories.type = 'button';
    memories.textContent = 'MEMORIES';
    memories.dataset.adminNav = 'media';
    memories.dataset.memoriesNav = '1';
    media.after(memories);

    window.state.section = 'media';
    history.replaceState({}, '', '?module=media&view=memories');
    window.BRVTALAdminIA.rebuildNavigation();
  });

  const media = page.getByRole('button',{name:'MEDIA',exact:true});
  const memories = page.locator('[data-memories-nav="1"]');
  await expect(memories).toHaveCount(1);
  expect(await media.evaluate((node) => node.nextElementSibling?.dataset.memoriesNav)).toBe('1');
  await expect(memories).toHaveClass(/active/);
  await expect(media).not.toHaveClass(/active/);

  await page.evaluate(() => {
    history.replaceState({}, '', '?module=media');
    window.BRVTALAdminIA.rebuildNavigation();
  });
  await expect(media).toHaveClass(/active/);
  await expect(memories).not.toHaveClass(/active/);
});


test('dirty Banners cancellation blocks dynamic navigation before URL or workspace commit', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => {
    window.__renderShell('hero-slider');
    history.replaceState({brvtalAdminRoute:'hero-slider'}, '', '?module=hero-slider');
    window.__guardRequests = [];
    window.__guardCommits = [];
    window.BRVTALHeroSliderGuard = {
      requestNavigation(section) {
        window.__guardRequests.push(section);
        return false;
      },
      commitNavigation(section) {
        window.__guardCommits.push(section);
      }
    };
  });

  const result = await page.evaluate(() => window.go('media'));

  expect(result).toBe(false);
  expect(await page.evaluate(() => window.state.section)).toBe('hero-slider');
  expect(new URL(page.url()).searchParams.get('module')).toBe('hero-slider');
  expect(await page.evaluate(() => window.__nativeGo.includes('media'))).toBe(false);
  expect(await page.evaluate(() => window.__guardRequests)).toEqual(['media']);
  expect(await page.evaluate(() => window.__guardCommits)).toEqual([]);
});

test('dirty Banners cancellation also blocks technical navigation', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => {
    window.__renderShell('hero-slider');
    history.replaceState({brvtalAdminRoute:'hero-slider'}, '', '?module=hero-slider');
    window.BRVTALHeroSliderGuard = {
      requestNavigation() { return false; },
      commitNavigation() { throw new Error('must not commit cancelled navigation'); }
    };
  });

  const result = await page.evaluate(() => window.tech('system'));

  expect(result).toBe(false);
  expect(await page.evaluate(() => window.state.section)).toBe('hero-slider');
  expect(new URL(page.url()).searchParams.get('module')).toBe('hero-slider');
});

test('browser Back restores the Banners URL when unsaved navigation is cancelled', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => {
    history.replaceState({brvtalAdminRoute:'artists'}, '', '?module=artists');
    window.__renderShell('hero-slider');
    history.pushState({brvtalAdminRoute:'hero-slider'}, '', '?module=hero-slider');
    window.BRVTALHeroSliderGuard = {
      requestNavigation() { return false; },
      commitNavigation() { throw new Error('must not commit cancelled navigation'); }
    };
  });

  await page.goBack();

  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('hero-slider');
  expect(await page.evaluate(() => window.state.section)).toBe('hero-slider');
  await expect(page.locator('.main .top h1')).toHaveText('HERO-SLIDER');
});

test('late dirty mutation rolls an applied workspace back to its previous route', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => {
    window.__renderShell('artists');
    history.replaceState({brvtalAdminRoute:'artists'}, '', '?module=artists');
    window.__commitCalls=0;
    window.BRVTALUnsavedChanges = {
      requestNavigation: () => 77,
      commitNavigation: token => {
        window.__commitToken=token;
        window.__commitCalls+=1;
        return false;
      },
      cancelNavigation: () => true
    };
  });

  const result = await page.evaluate(() => window.go('pages'));

  expect(result).toBe(false);
  expect(await page.evaluate(() => window.__commitToken)).toBe(77);
  expect(await page.evaluate(() => window.__commitCalls)).toBe(1);
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('artists');
  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  expect(new URL(page.url()).searchParams.get('module')).toBe('artists');
});

test('dynamic routes update the URL before readiness settles', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => {
    const script = document.createElement('script');
    script.id = 'brvtal-media-library-script';
    document.head.appendChild(script);
    window.__pendingMediaNavigation = window.go('media');
  });

  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('media');
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('dashboard');

  await page.evaluate(() => {
    const script = document.getElementById('brvtal-media-library-script');
    script.dataset.ready = '1';
    script.dispatchEvent(new Event('load'));
  });
  await page.evaluate(() => window.__pendingMediaNavigation);

  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('media');
  await expect(page.locator('.main .top h1')).toHaveText('MEDIA');
});

test('explicit Media navigation wins while initial route reconciliation is still pending', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => {
    history.replaceState({}, '', '?module=pages');
    window.state.section = 'dashboard';
    window.__unsavedNextToken = 0;
    window.__unsavedCommitTokens = [];
    window.__unsavedCancelTokens = [];
    window.BRVTALUnsavedChanges = {
      requestNavigation: () => ++window.__unsavedNextToken,
      commitNavigation: token => {
        window.__unsavedCommitTokens.push(token);
        return true;
      },
      cancelNavigation: token => {
        window.__unsavedCancelTokens.push(token);
        return true;
      }
    };
    window.__deferredRequests['/pages'] = true;
    window.__initialRoutePromise = window.BRVTALAdminIA.applyRoute();
  });
  await expect.poll(() => page.evaluate(() => window.__requestLog.includes('/pages'))).toBe(true);

  await page.evaluate(() => {
    const media = document.createElement('script');
    media.id = 'brvtal-media-library-script';
    document.head.appendChild(media);
    window.__pendingMediaNavigation = window.go('media');
  });

  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('media');

  await page.evaluate(() => {
    const media = document.getElementById('brvtal-media-library-script');
    media.dataset.ready = '1';
    media.dispatchEvent(new Event('load'));
  });
  await page.evaluate(() => window.__pendingMediaNavigation);
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('media');

  await page.evaluate(() => window.__resolveRequest('/pages'));
  await page.evaluate(() => window.__initialRoutePromise);

  await expect(page.locator('.main .top h1')).toHaveText('MEDIA');
  expect(await page.evaluate(() => window.state.section)).toBe('media');
  expect(new URL(page.url()).searchParams.get('module')).toBe('media');
  const tokenState = await page.evaluate(() => ({
    commits:window.__unsavedCommitTokens,
    cancels:window.__unsavedCancelTokens
  }));
  expect(tokenState.commits.length).toBeGreaterThan(0);
  const committedMediaToken = tokenState.commits.at(-1);
  expect(tokenState.cancels.every(token => token !== committedMediaToken)).toBe(true);
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

test('native Events EDIT uses the mounted guided editor without reloading navigation', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);
  await page.evaluate(() => window.go('events'));
  const baseline = await page.evaluate(() => ({
    loads: window.__moduleLoadOptions.length,
    native: window.__nativeGo.filter(section => section === 'events').length
  }));
  await page.evaluate(() => window.openModal('events', 42));
  expect(await page.evaluate(() => window.__openedEvent)).toBe(42);
  expect(await page.evaluate(() => ({
    loads: window.__moduleLoadOptions.length,
    native: window.__nativeGo.filter(section => section === 'events').length
  }))).toEqual(baseline);
  await expect(page.locator('#modal')).not.toHaveClass(/open/);
});

test('Artist membership remains inside the canonical Artists editor with no duplicate workflow', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => window.go('artists'));

  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  await expect(page.getByRole('button',{name:'COLLECTIVE STATUS'})).toHaveCount(0);
  await expect(page.locator('[data-admin-module="content-core"]')).toHaveCount(0);
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
  await expect(page.getByRole('button',{name:'COLLECTIVE STATUS'})).toHaveCount(0);
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
  await expect(page.getByRole('button',{name:'COLLECTIVE STATUS'})).toHaveCount(0);
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

test('failed current native navigation keeps the previous workspace, rows and URL coherent', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => window.go('artists'));
  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('artists');
  expect(await page.evaluate(() => window.state.rows[0]?.source)).toBe('/artists');

  await page.evaluate(() => {
    window.__requestFailures['/pages'] = 'PAGES_UNAVAILABLE';
    window.__failedPagesNavigation = window.go('pages').catch(error => {
      window.__navigationFailure = error.message;
    });
  });
  await page.evaluate(() => window.__failedPagesNavigation);

  expect(await page.evaluate(() => window.__navigationFailure)).toBe('PAGES_UNAVAILABLE');
  expect(await page.evaluate(() => window.state.section)).toBe('artists');
  expect(await page.evaluate(() => window.state.rows[0]?.source)).toBe('/artists');
  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  expect(new URL(page.url()).searchParams.get('module')).toBe('artists');
  expect(await page.evaluate(() => window.__nativeRenders.slice(-1)[0])).toBe('artists');
});

test('successful module navigation retires the previous legacy editor and save handler', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => window.go('artists'));
  await page.evaluate(() => window.openModal('artists', 7));
  await expect(page.locator('#modal')).toHaveClass(/open/);
  expect(await page.evaluate(() => window.state.editing)).toBe(7);

  await page.evaluate(() => window.go('pages'));

  await expect(page.locator('.main .top h1')).toHaveText('PAGES');
  await expect(page.locator('#modal')).not.toHaveClass(/open/);
  expect(await page.evaluate(() => window.state.editing)).toBeNull();
  await page.evaluate(() => document.getElementById('saveBtn').click());
  expect(await page.evaluate(() => window.__legacySaveCalls)).toBe(0);
});

test('failed module navigation preserves the current legacy editor context', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => window.go('artists'));
  await page.evaluate(() => {
    window.openModal('artists', 7);
    window.__requestFailures['/pages'] = 'PAGES_UNAVAILABLE';
    window.__failedEditorNavigation = window.go('pages').catch(error => {
      window.__editorNavigationFailure = error.message;
    });
  });
  await page.evaluate(() => window.__failedEditorNavigation);

  expect(await page.evaluate(() => window.__editorNavigationFailure)).toBe('PAGES_UNAVAILABLE');
  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  await expect(page.locator('#modal')).toHaveClass(/open/);
  expect(await page.evaluate(() => window.state.editing)).toBe(7);
  await page.evaluate(() => document.getElementById('saveBtn').click());
  expect(await page.evaluate(() => window.__legacySaveCalls)).toBe(1);
});

test('browser Back retires an editor from the workspace being left', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => window.go('artists'));
  await page.evaluate(() => window.go('pages'));
  await page.evaluate(() => window.openModal('pages', 9));
  await expect(page.locator('#modal')).toHaveClass(/open/);

  await page.goBack();

  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('artists');
  await expect(page.locator('.main .top h1')).toHaveText('ARTISTS');
  await expect(page.locator('#modal')).not.toHaveClass(/open/);
  expect(await page.evaluate(() => window.state.editing)).toBeNull();
  expect(new URL(page.url()).searchParams.get('module')).toBe('artists');
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
  expect(wrapper.indexOf('admin-information-architecture.js')).toBeLessThan(wrapper.indexOf('admin-route-aliases.js'));
  expect(wrapper.indexOf('admin-route-aliases.js')).toBeLessThan(wrapper.indexOf('dashboard-v2.js'));
  expect(wrapper.indexOf('settings-v2.js')).toBeLessThan(wrapper.indexOf('dashboard-v2.js'));
  expect(wrapper.indexOf('theme-studio-v2.js')).toBeLessThan(wrapper.indexOf('dashboard-v2.js'));
  expect(wrapper.indexOf('memories.js')).toBeLessThan(wrapper.indexOf('dashboard-v2.js'));
  expect(wrapper.indexOf('dashboard-v2.js')).toBeLessThan(wrapper.indexOf('data-admin-session-restore="1"'));
  expect(wrapper).toContain("str_replace($legacyRestoreBootstrap, '', $html, $restoreBootstrapCount)");
});