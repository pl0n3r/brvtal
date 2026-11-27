import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';

const iaJs = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.js'), 'utf8');
const iaCss = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.css'), 'utf8');
const moduleCss = readFileSync(join(process.cwd(), 'discadmin/admin-modules.css'), 'utf8');
const wrapper = readFileSync(join(process.cwd(), 'discadmin/index.php'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin-ia-e2e.html';

function harness(authed = true) {
  return `<!doctype html><html><head><style>.shell{display:grid;grid-template-columns:245px minmax(0,1fr)}${moduleCss}
${iaCss}.side .nav{display:flex;flex-direction:column;gap:2px}.side .nav button{min-height:44px}.legacy-test-modal{display:none}.legacy-test-modal.open{display:block}</style></head><body><div id="app"></div><div id="modal" class="legacy-test-modal"><button id="saveBtn" type="button">SAVE</button></div><script>
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
    window.__dashboardMount=null;
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
      if(section==='dashboard' && typeof window.__dashboardMount==='function'){
        window.__nativeGo.push(section);
        state.section=section;
        window.__renderShell(section);
        return window.__dashboardMount();
      }
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
        window.BRVTALContentCore={openEvent(id){window.__openedEvent=id??'new';const editor=root.querySelector('#eventModal');editor.dataset.eventId=id==null?'new':String(id);editor.classList.add('open');}};
      }
    };
    window.__renderShell('dashboard');
  </script><script>${iaJs}</script></body></html>`;
}

async function serveHarness(page, {authed = true} = {}) {
  await page.route('**/discadmin-ia-e2e.html*', route => route.fulfill({contentType:'text/html; charset=utf-8',body:harness(authed)}));
}

const V3_ROOTS = ['ESCRITORIO','EVENTOS','ARTISTAS','MÚSICA','BOLETERÍA','CORREOS','SITIO WEB','CONFIGURACIÓN'];
const V3_CHILDREN = {
  music:['SETS','RELEASES'],
  ticketing:['PEDIDOS','BOLETAS','COMPRADORES','CONTROL DE ACCESO','CORTESÍAS','PROMOCIONES','PROMOTORES','FINANZAS','DEVOLUCIONES'],
  mail:['HISTORIAL DE ENVÍOS','PLANTILLAS','PRUEBAS DE ENVÍO'],
  website:['PÁGINAS','BLOG','MULTIMEDIA','BANNERS','APARIENCIA','SEO'],
  settings:['GENERAL','REDES SOCIALES Y CONTACTO','INTEGRACIONES','SEGURIDAD','ESTADO DEL SISTEMA','COPIAS DE SEGURIDAD']
};
const ALL_TICKETING_TABS = ['dashboard','orders','entries','buyers','door','finance','promos','promoters','mail','settings'];

// El servidor filtra las pestañas de Boletería por rol en este fragmento existente.
async function serveTicketingTabs(page, tabs = ALL_TICKETING_TABS) {
  await page.route('**/discadmin/ticketing.php', route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:'<section data-admin-module="ticketing"><div role="tablist">'
      + tabs.map(tab => '<button role="tab" data-ticketing-tab="' + tab + '">' + tab + '</button>').join('')
      + '</div><div data-ticketing-panel></div></section>'
  }));
}

// Orden visual (no solo DOM) de las raíces, como exige #1892.
async function visualRootLabels(page) {
  return page.locator('.side .nav').evaluate(nav => [...nav.children]
    .filter(node => !node.hidden && node.getClientRects().length > 0)
    .map(node => {
      const head = node.matches('button') ? node : node.querySelector('.ia-navhead > button');
      return {label:head.childNodes[0].textContent.trim(), top:node.getBoundingClientRect().top};
    })
    .sort((a,b) => a.top - b.top)
    .map(item => item.label));
}

for (const viewport of [{width:390,height:844},{width:1440,height:900}]) {
  test(`#1892 menú V3: exactamente 8 raíces en el orden aprobado a ${viewport.width}px`, async ({ page }) => {
    await page.setViewportSize(viewport);
    await serveTicketingTabs(page);
    await serveHarness(page);
    await page.goto(harnessUrl);
    await expect(page.locator('.side .nav[data-ia-tree="v3"]')).toBeVisible();
    await expect.poll(() => visualRootLabels(page)).toEqual(V3_ROOTS);
    for (const [group, labels] of Object.entries(V3_CHILDREN)) {
      const children = page.locator(`#brvtal-nav-${group} > button`);
      await expect.poll(() => children.evaluateAll(nodes => nodes.map(n => n.childNodes[0].textContent.trim())))
        .toEqual(labels);
    }
    // Escritorio, Eventos y Artistas navegan directo: sin desplegable ni «Añadir».
    for (const key of ['dashboard','events','artists']) {
      await expect(page.locator(`.side .nav > button[data-ia-key="${key}"]`)).toHaveCount(1);
    }
    await expect(page.locator('.side .nav')).not.toContainText(/AÑADIR|VER TODOS|RESUMEN DE VENTAS|CONTENT CORE|ACTIVITY/);
  });
}

test('#1892 los desplegables funcionan con teclado y conservan el foco', async ({ page }) => {
  await serveTicketingTabs(page);
  await serveHarness(page);
  await page.goto(harnessUrl);
  const toggle = page.locator('[data-ia-toggle="music"]');
  await expect(toggle).toHaveAttribute('aria-expanded','false');
  await expect(toggle).toHaveAttribute('aria-controls','brvtal-nav-music');
  await expect(page.locator('#brvtal-nav-music')).toBeHidden();
  await toggle.focus();
  await page.keyboard.press('Enter');
  await expect(toggle).toHaveAttribute('aria-expanded','true');
  await expect(page.locator('#brvtal-nav-music')).toBeVisible();
  await expect(toggle).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.locator('[data-ia-key="sets"]')).toBeFocused();
  await page.keyboard.press('Enter');
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('sets');
  await expect(page.locator('[data-ia-key="sets"]')).toHaveClass(/active/);
  await expect(page.locator('[data-ia-key="sets"]')).toHaveAttribute('aria-current','page');
  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('sets');
  await page.locator('[data-ia-toggle="music"]').click();
  await expect(page.locator('#brvtal-nav-music')).toBeHidden();
});

test('#1892 la hoja activa abre su raíz y sobrevive al re-render y a Memorias tardío', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await serveTicketingTabs(page);
  await serveHarness(page);
  await page.goto(harnessUrl);
  await page.evaluate(() => window.go('hero-slider'));
  await expect(page.locator('[data-ia-toggle="website"]')).toHaveAttribute('aria-expanded','true');
  await expect(page.locator('[data-ia-key="hero-slider"]')).toHaveClass(/active/);
  await page.evaluate(() => {
    window.__renderShell('hero-slider');
    const nav = document.querySelector('.side .nav');
    const late = document.createElement('button');
    late.textContent = 'MEMORIES'; late.dataset.memoriesNav = '1';
    nav.appendChild(late);
  });
  await expect.poll(() => visualRootLabels(page)).toEqual(V3_ROOTS);
  await expect(page.locator('[data-ia-key="hero-slider"]')).toHaveClass(/active/);
  // Memorias vive dentro de Multimedia: nunca aparece como entrada visible del menú.
  await expect(page.locator('.side .nav [data-memories-nav]:visible')).toHaveCount(0);
});

test('#1892 Boletería solo muestra las pestañas que el servidor permite al rol Puerta', async ({ page }) => {
  await serveTicketingTabs(page, ['dashboard','door']);
  await serveHarness(page);
  await page.goto(harnessUrl);
  await expect(page.locator('#brvtal-nav-ticketing > button')).toHaveCount(1);
  await expect(page.locator('[data-ia-key="ticketing:door"]')).toHaveCount(1);
  for (const hidden of ['orders','entries','buyers','finance','refunds','promos','promoters']) {
    await expect(page.locator(`[data-ia-key="ticketing:${hidden}"]`)).toHaveCount(0);
  }
  await expect(page.locator('[data-ia-key="mail:history"]')).toHaveCount(0);
});

test('#1892 Boletería oculta sus hojas si no puede verificar permisos', async ({ page }) => {
  await page.route('**/discadmin/ticketing.php', route => route.fulfill({status:403, body:'no'}));
  await serveHarness(page);
  await page.goto(harnessUrl);
  await expect(page.locator('.side .nav > button[data-ia-key="ticketing"]')).toHaveCount(1);
  await expect(page.locator('[data-ia-key^="ticketing:"]')).toHaveCount(0);
});

test('#1892 «Próximamente» se anuncia deshabilitado y no navega', async ({ page }) => {
  await serveTicketingTabs(page);
  await serveHarness(page);
  await page.goto(harnessUrl);
  const soon = page.locator('[data-ia-key="mail:templates"]');
  // La hoja depende de permisos de Boletería cargados de forma asíncrona.
  // No abrir el grupo antes de que el servidor haya autorizado esta hoja.
  await expect(soon).toHaveCount(1, {timeout: 20000});
  await page.locator('[data-ia-toggle="mail"]').click();
  await expect(soon).toHaveAttribute('aria-disabled','true');
  await expect(soon).toContainText('Próximamente');
  const before = await page.evaluate(() => [window.state.section, location.search]);
  await soon.click({force:true});
  expect(await page.evaluate(() => [window.state.section, location.search])).toEqual(before);
});

test('#1892 Configuración enlaza a su pestaña y Atrás/Adelante la conservan', async ({ page }) => {
  await serveTicketingTabs(page);
  await serveHarness(page);
  await page.goto(harnessUrl);
  await page.locator('[data-ia-toggle="settings"]').click();
  await page.locator('[data-ia-key="settings:integrations"]').click();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('settings');
  await expect.poll(() => new URL(page.url()).searchParams.get('settings')).toBe('integrations');
  await expect(page.locator('[data-ia-key="settings:integrations"]')).toHaveClass(/active/);
  await page.locator('[data-ia-toggle="music"]').click();
  await page.locator('[data-ia-key="sets"]').click();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('sets');
  await expect.poll(() => new URL(page.url()).searchParams.get('settings')).toBe(null);
  await page.goBack();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('settings');
  await expect.poll(() => new URL(page.url()).searchParams.get('settings')).toBe('integrations');
  await expect(page.locator('[data-ia-key="settings:integrations"]')).toHaveClass(/active/);
  await page.goForward();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('sets');
});

test('successful Dashboard navigation resolves only after canonical active nav is synchronized', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  const result = await page.evaluate(async () => {
    window.__renderShell('hero-slider');
    history.replaceState({brvtalAdminRoute:'hero-slider'}, '', '?module=hero-slider');
    window.BRVTALAdminIA.rebuildNavigation();

    const committed = await window.go('dashboard');
    const dashboard = document.querySelector('.side .nav [data-ia-key="dashboard"]');

    return {
      committed,
      section:window.state.section,
      dashboardActive:Boolean(dashboard?.classList.contains('active')),
      route:new URL(location.href).searchParams.get('module'),
    };
  });

  expect(result).toEqual({
    committed:true,
    section:'dashboard',
    dashboardActive:true,
    route:null,
  });
});

test('successful Dashboard navigation preserves Hero as the previous browser history entry', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => {
    window.__renderShell('hero-slider');
    history.replaceState({brvtalAdminRoute:'hero-slider'}, '', '?module=hero-slider');
    window.BRVTALAdminIA.rebuildNavigation();
  });

  expect(await page.evaluate(() => window.go('dashboard'))).toBe(true);
  expect(new URL(page.url()).searchParams.get('module')).toBe(null);

  await page.goBack();

  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('hero-slider');
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('hero-slider');
});

test('Dashboard precommits its canonical URL while the async mount is pending', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  const pending = await page.evaluate(async () => {
    window.__renderShell('hero-slider');
    history.replaceState({brvtalAdminRoute:'hero-slider'}, '', '?module=hero-slider');
    window.BRVTALAdminIA.rebuildNavigation();

    let releaseMount;
    window.__dashboardMount = () => new Promise(resolve => { releaseMount = resolve; });
    window.__releaseDashboardMount = value => releaseMount(value);

    const navigation = window.go('dashboard');
    while(typeof window.__releaseDashboardMount !== 'function') await new Promise(resolve => setTimeout(resolve,0));
    const during = {
      section:window.state.section,
      route:new URL(location.href).searchParams.get('module')
    };
    window.__releaseDashboardMount(true);
    const result = await navigation;
    return {result,during,route:new URL(location.href).searchParams.get('module')};
  });

  expect(pending).toEqual({
    result:true,
    during:{section:'dashboard',route:null},
    route:null
  });
});

test('failed current Dashboard mount restores the previous Hero workspace', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  const result = await page.evaluate(async () => {
    window.__renderShell('hero-slider');
    history.replaceState({brvtalAdminRoute:'hero-slider'}, '', '?module=hero-slider');
    window.BRVTALAdminIA.rebuildNavigation();
    window.__dashboardMount = async () => false;

    const historyLengthBefore = history.length;
    const committed = await window.go('dashboard');
    const banners = document.querySelector('.side .nav [data-ia-key="hero-slider"]');

    return {
      committed,
      section:window.state.section,
      bannersActive:Boolean(banners?.classList.contains('active')),
      route:new URL(location.href).searchParams.get('module'),
      historyDelta:history.length-historyLengthBefore,
      diagnostic:window.BRVTALAdminIA.navigationDiagnostics()
    };
  });

  expect(result).toMatchObject({
    committed:false,
    section:'hero-slider',
    bannersActive:true,
    route:'hero-slider',
    historyDelta:0,
    diagnostic:{
      status:'failed',
      reason:'original-go-false',
      currentSection:'hero-slider',
      route:'hero-slider'
    }
  });
});

test('rejected Dashboard mount restores Hero without duplicating browser history', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  const result = await page.evaluate(async () => {
    window.__renderShell('hero-slider');
    history.replaceState({brvtalAdminRoute:'hero-slider'}, '', '?module=hero-slider');
    window.BRVTALAdminIA.rebuildNavigation();
    window.__dashboardMount = async () => { throw new Error('DASHBOARD_MOUNT_FAILED'); };

    const historyLengthBefore = history.length;
    const message = await window.go('dashboard').then(
      () => '',
      error => String(error?.message || error)
    );

    return {
      message,
      section:window.state.section,
      route:new URL(location.href).searchParams.get('module'),
      historyDelta:history.length-historyLengthBefore,
      diagnostic:window.BRVTALAdminIA.navigationDiagnostics()
    };
  });

  expect(result).toMatchObject({
    message:'DASHBOARD_MOUNT_FAILED',
    section:'hero-slider',
    route:'hero-slider',
    historyDelta:0,
    diagnostic:{
      status:'failed',
      reason:'original-go-rejected',
      currentSection:'hero-slider',
      route:'hero-slider'
    }
  });
});

test('failed stale Dashboard mount never overwrites a newer navigation', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  const result = await page.evaluate(async () => {
    window.__renderShell('hero-slider');
    history.replaceState({brvtalAdminRoute:'hero-slider'}, '', '?module=hero-slider');
    window.BRVTALAdminIA.rebuildNavigation();

    let releaseMount;
    window.__dashboardMount = () => new Promise(resolve => {
      releaseMount = resolve;
    });

    const staleDashboard = window.go('dashboard');
    while(typeof releaseMount !== 'function') await new Promise(resolve => setTimeout(resolve,0));

    const newer = await window.go('artists');
    releaseMount(false);
    const staleResult = await staleDashboard;

    const artists = document.querySelector('.side .nav [data-ia-key="artists"]');

    return {
      staleResult,
      newer,
      section:window.state.section,
      artistsActive:Boolean(artists?.classList.contains('active')),
      route:new URL(location.href).searchParams.get('module'),
    };
  });

  expect(result).toEqual({
    staleResult:false,
    newer:true,
    section:'artists',
    artistsActive:true,
    route:'artists',
  });
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
  await expect(page.locator('.main .top h1')).toHaveText('EVENTOS');
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

test('mounted Events EDIT preserves unsaved changes across record switches and rejects pending navigation', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);
  await page.evaluate(() => window.go('events'));
  await page.evaluate(() => {
    window.BRVTALUnsavedChanges = {
      isDirty: () => true,
      requestClose(editor, close) {
        window.__discardRequests = (window.__discardRequests || 0) + 1;
        if (!window.__confirmDiscard) return false;
        close();
        return true;
      }
    };
    window.__confirmDiscard = false;
  });
  await page.evaluate(() => window.openModal('events', 42));
  expect(await page.evaluate(() => window.__openedEvent)).toBe(42);
  // Reopening the same record never wipes the existing unsaved form.
  expect(await page.evaluate(() => window.openModal('events', 42))).toBe(true);
  expect(await page.evaluate(() => window.__discardRequests || 0)).toBe(0);
  expect(await page.evaluate(() => window.openModal('events', 43))).toBe(false);
  expect(await page.evaluate(() => window.__openedEvent)).toBe(42);
  await expect(page.locator('#eventModal')).toHaveClass(/open/);
  expect(await page.evaluate(() => window.__discardRequests)).toBe(1);
  await page.evaluate(() => { document.getElementById('eventModal').inert = true; });
  expect(await page.evaluate(() => window.openModal('events', 43))).toBe(false);
  expect(await page.evaluate(() => window.__discardRequests)).toBe(1);
  await page.evaluate(() => { document.getElementById('eventModal').inert = false; window.__confirmDiscard = true; });
  expect(await page.evaluate(() => window.openModal('events', 43))).toBe(true);
  expect(await page.evaluate(() => window.__openedEvent)).toBe(43);
  expect(await page.evaluate(() => window.__discardRequests)).toBe(2);
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
const finance2054Base='6c98800ea1a8e4cafbc9771a20369f46541c60f2';
function finance2054Read(path,baseline=false) {
  if(!baseline)return readFileSync(path,'utf8');
  const baselineRoot=process.env.BRVTAL_FINANCE_VISUAL_BASE_DIR;
  if(baselineRoot)return readFileSync(join(baselineRoot,path),'utf8');
  const ref=finance2054Base+':'+path;
  try { return execFileSync('git',['show',ref],{encoding:'utf8',timeout:20000}); }
  catch {
    execFileSync('git',['fetch','--no-tags','--depth=1','origin',finance2054Base],{timeout:60000});
    return execFileSync('git',['show',ref],{encoding:'utf8',timeout:20000});
  }
}
for(const width of [390,1440]){
  test('#2054 real Finance mount, channel filters, history names, CSV and A/B '+width,async({page},testInfo)=>{
    const calls=[];
    let mode='ok';
    const common={event_id:6,gross_cop:65000,discount_cop:0,approved_cop:35000,
      pos_cop:30000,pos_orders:1,paid_orders:4,refund_cop:null,refund_in_period_cop:null,
      net_after_refunds_cop:null,payout_cop:35000,tickets:7,unverified_orders:1,
      types:[{ticket_type_id:8,quantity:4,gross_cop:35000,ticket_names:['General','Preventa'],unnamed_quantity:1},
        {ticket_type_id:9,quantity:3,gross_cop:30000,ticket_names:['VIP'],unnamed_quantity:0}]};
    const pos={...common,gross_cop:30000,approved_cop:0,pos_cop:30000,paid_orders:1,
      tickets:3,payout_cop:0,types:[common.types[1]]};
    const wompi={...common,gross_cop:35000,approved_cop:35000,pos_cop:0,pos_orders:0,
      paid_orders:3,tickets:4,types:[common.types[0]]};
    async function setup(target,baseline){
      await target.setViewportSize({width,height:900});
      const php=finance2054Read('discadmin/ticketing-finance.php',baseline);
      const catalog={ok:true,events:[{id:6,title:'Evento sintético',ticket_types:[]}]};
      const fragment=php.slice(php.indexOf('<section data-finance-module')).replace(
        /<script type="application\/json" data-finance-catalog>[\s\S]*?<\/script>/,
        '<script type="application/json" data-finance-catalog>'+JSON.stringify(catalog)+'</script>');
      const rootUrl='http://127.0.0.1:4173/discadmin/finance2054.html';
      await target.route(rootUrl,route=>route.fulfill({contentType:'text/html; charset=utf-8',
        body:'<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="margin:0;padding:16px;background:#050505;color:#f4f5f6;font:14px system-ui"><div id="admin-module-host"></div></body></html>'}));
      await target.route('**/discadmin/ticketing.php',route=>route.fulfill({contentType:'text/html; charset=utf-8',
        body:'<section class="ticketing" data-admin-module="ticketing" data-ticketing-role="admin"><div role="tablist"><button role="tab" id="finance" data-ticketing-tab="finance" aria-selected="true">Finanzas</button></div><div data-ticketing-panel></div></section>'}));
      await target.route('**/discadmin/ticketing-finance.php',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:fragment}));
      await target.route('**/discadmin/ticketing-finance.js*',route=>route.fulfill({contentType:'text/javascript; charset=utf-8',
        body:finance2054Read('discadmin/ticketing-finance.js',baseline)}));
      await target.route('**/api/ticketing/admin-finances.php?*',route=>{
        const params=new URL(route.request().url()).searchParams;
        calls.push({channel:params.get('channel'),format:params.get('format')});
        if(mode==='denied')return route.fulfill({status:403,json:{ok:false}});
        if(mode==='error')return route.fulfill({status:503,json:{ok:false}});
        if(params.get('format')==='csv')return route.fulfill({contentType:'text/csv; charset=utf-8',body:'event_id,pos_cop\n6,30000\n'});
        return route.fulfill({json:{ok:true,rows:mode==='empty'?[]:[params.get('channel')==='pos'?pos:params.get('channel')==='wompi'?wompi:common]}});
      });
      await target.goto(rootUrl);
      await target.addStyleTag({content:finance2054Read('discadmin/ticketing.css',baseline)});
      await target.evaluate(()=>{window.state={authed:true,section:'ticketing'};});
      await target.addScriptTag({content:finance2054Read('discadmin/ticketing.js',baseline)});
      await target.evaluate(()=>window.BRVTALTicketing.mount());
      const root=target.locator('[data-finance-module]');
      await expect(root.locator('.tk-table tbody tr')).toHaveCount(1);
      await root.getByText('Ver desglose',{exact:true}).click();
      expect(await target.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      return root;
    }
    const before=await page.context().newPage();
    try{
      const old=await setup(before,true);
      await expect(old.locator('#tk-finance-channel option[value="pos"]')).toHaveCount(0);
      await expect(old.getByText(/Boletas vendidas por tipo: Evento sintético/)).toBeVisible();
      await testInfo.attach('2054-before-'+width+'.png',{body:await before.screenshot({fullPage:true}),contentType:'image/png'});
    }finally{await before.close();}
    const root=await setup(page,false);
    await expect(root.getByText(/Boletas vendidas por tipo: General \/ Preventa/)).toBeVisible();
    await expect(root.getByText(/Tipo #8 \(sin nombre histórico\)/)).toBeVisible();
    await expect(root.getByText(/VIP · 3 boletas/)).toBeVisible();
    await expect(root.getByText('Pedidos sin verificar: 1')).toBeVisible();
    await testInfo.attach('2054-after-'+width+'.png',{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await root.locator('#tk-finance-channel').selectOption('pos');
    await root.getByRole('button',{name:'Consultar',exact:true}).click();
    await expect(root.locator('td[data-label="Ingresos verificados"]')).toContainText(/POS:.*30\.000/);
    await root.getByText('Ver desglose',{exact:true}).click();
    await expect(root.getByText(/Boletas vendidas por tipo:/)).not.toContainText('General');
    const download=page.waitForEvent('download');
    await root.getByRole('button',{name:'Exportar CSV'}).click();
    expect((await download).suggestedFilename()).toBe('brvtal-finanzas.csv');
    expect(calls.at(-1)).toEqual({channel:'pos',format:'csv'});
    await root.locator('#tk-finance-channel').selectOption('wompi');
    await root.getByRole('button',{name:'Consultar',exact:true}).click();
    await expect(root.locator('td[data-label="Ingresos verificados"]')).toContainText(/Wompi:.*35\.000/);
    mode='empty';
    await root.getByRole('button',{name:'Consultar',exact:true}).click();
    await expect(root.getByText('No hay movimientos para los filtros seleccionados.')).toBeVisible();
    mode='denied';
    await root.getByRole('button',{name:'Consultar',exact:true}).click();
    await expect(root.locator('[data-finance-status]')).toHaveText('No tienes permiso para consultar finanzas.');
    await expect(root.locator('.tk-table')).toHaveCount(0);
    mode='error';
    await root.getByRole('button',{name:'Consultar',exact:true}).click();
    await expect(root.locator('[data-finance-status]')).toHaveText('No fue posible consultar finanzas.');
    await expect(root.locator('.tk-table')).toHaveCount(0);
  });
}
