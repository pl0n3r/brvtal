import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';

const iaJs = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.js'), 'utf8');
const iaCss = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.css'), 'utf8');
const moduleCss = readFileSync(join(process.cwd(), 'discadmin/admin-modules.css'), 'utf8');
const shellCss = readFileSync(join(process.cwd(), 'discadmin/admin-shell.css'), 'utf8');
const shellJs = readFileSync(join(process.cwd(), 'discadmin/admin-shell.js'), 'utf8');
const ticketJs = readFileSync(join(process.cwd(), 'discadmin/ticketing.js'), 'utf8');
const wrapper = readFileSync(join(process.cwd(), 'discadmin/index.php'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin-ia-e2e.html';

function harness(authed = true, legacy = false) {
  return `<!doctype html><html><head><style>.shell{display:grid;grid-template-columns:245px minmax(0,1fr)}${moduleCss}
${shellCss}
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
    window.BRVTALSettingsV2={activate(id){window.__activatedSetting=id;}};
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
      if(section==='ticketing'){
        sectionContent='<div id="admin-module-host"></div>';
      }else if(section==='events'){
        sectionContent='<div data-canonical-events-list="1"><div class="toolbar"><input class="search" placeholder="Search events..."><button class="btn red" data-new-event>+ NEW EVENT</button></div><div class="table" data-events-table="canonical">EVENTS TABLE</div></div>';
      }else if(section==='artists'){
        sectionContent='<div class="toolbar"><input class="search"><button class="btn red">+ NEW ARTIST</button></div>';
      }
      document.getElementById('app').innerHTML='<div class="shell"><button class="admin-nav-scrim" aria-label="Cerrar navegación" onclick="closeAdminNav()"></button><aside class="side" id="admin-primary-nav"><div class="nav">'
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
      if(section==='ticketing'){
        window.__nativeGo.push(section);
        state.section=section;
        window.__renderShell(section);
        return window.BRVTALTicketing?.mount();
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
  </script>${legacy ? '' : '<script>'+ticketJs+'</script><script>'+shellJs+'</script><script>'+iaJs+'</script>'}</body></html>`;
}

function ticketingFragment(role) {
  const tabs = role === 'door' ? ['door'] : role === 'admin'
    ? ['dashboard','orders','entries','buyers','door','finance','promos','promoters','mail']
    : ['dashboard','orders','entries','buyers','door','finance','promos','promoters','mail','settings'];
  return '<section data-admin-module="ticketing" data-ticketing-role="' + role + '">'
    + '<div role="tablist">' + tabs.map((tab,index) =>
      '<button id="ticketing-tab-' + tab + '" role="tab" data-ticketing-tab="' + tab
      + '" aria-selected="' + (index === 0) + '">' + tab + '</button>').join('')
    + '</div><div data-ticketing-panel></div></section>';
}

async function serveHarness(page, {authed = true, role = 'superadmin'} = {}) {
  await page.route('**/discadmin/ticketing.php', route => route.fulfill({
    status:role === 'denied' ? 403 : 200,
    contentType:'text/html; charset=utf-8',
    body:role === 'denied' ? 'Acceso denegado' : ticketingFragment(role)
  }));
  await page.route('**/discadmin-ia-e2e.html*', route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:harness(authed, new URL(route.request().url()).searchParams.get('legacy') === '1')
  }));
}

// A root is a module/area, not a button to mutate a business record.
const rootLabels = ['Escritorio','Eventos','Artistas','Música','Boletería','Correos','Sitio web','Configuración'];
async function expectRootHierarchy(page) {
  const roots = page.locator('.side .nav > [data-ia-root]');
  await expect(roots).toHaveCount(8);
  await expect.poll(async () => roots.locator(':scope > .ia-root-row > .ia-root-button').allTextContents())
    .toEqual(rootLabels);
  await expect(page.locator('.side .nav .ia-navdivider,.side .nav .navgroup')).toHaveCount(0);
}

// Test visual flex ordering, not only textContent/DOM order (which missed #1480).
async function visualNavLabels(page) {
  return page.locator('.side .nav').evaluate(nav => [...nav.children]
    .filter(button => button.tagName === 'BUTTON'
      && !button.dataset.iaToggle
      && button.getClientRects().length > 0
      && getComputedStyle(button).display !== 'none')
    .map(button => ({label:button.textContent.trim(),top:button.getBoundingClientRect().top}))
    .sort((a,b) => a.top - b.top)
    .map(button => button.label));
}

test('AC-01: ocho raíces en orden DOM y visual, sin acciones repetidas', async ({ page }) => {
  await serveHarness(page);
  await page.setViewportSize({width:1440,height:900});
  await page.goto(harnessUrl);
  await expect(page.locator('[data-ia-root-button="ticketing"]')).toBeEnabled();
  await expectRootHierarchy(page);
  await expect(page.locator('[data-ia-root="dashboard"] .ia-subnav,[data-ia-root="events"] .ia-subnav,[data-ia-root="artists"] .ia-subnav')).toHaveCount(0);
  expect(await page.locator('.side .nav').innerText()).not.toMatch(/Añadir evento|Añadir artista|Ver todos|Resumen de ventas|Herramientas avanzadas/i);
  const order = await page.locator('.side .nav [data-ia-root]').evaluateAll(els => els.map(e => ({key:e.dataset.iaRoot,y:e.getBoundingClientRect().top})));
  expect(order.map(x=>x.key)).toEqual(['dashboard','events','artists','music','ticketing','mail','website','settings']);
  expect(order.map(x=>x.y)).toEqual([...order.map(x=>x.y)].sort((a,b)=>a-b));
});

test('AC-01: los cinco desplegables contienen solo destinos canónicos', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);
  await expect(page.locator('[data-ia-root-button="ticketing"]')).toBeEnabled();
  const expected = {
    music:['Sets','Releases'],
    ticketing:['Pedidos','Boletas','Compradores','Control de acceso','Cortesías','Promociones','Promotores','Finanzas','Devoluciones'],
    mail:['Historial de envíos','Plantillas · Próximamente','Pruebas de envío · Próximamente'],
    website:['Páginas','Blog','Multimedia','Banners','Apariencia','SEO'],
    settings:['General','Redes sociales y contacto','Integraciones','Seguridad','Estado del sistema','Copias de seguridad']
  };
  for (const [root, labels] of Object.entries(expected)) {
    await page.locator('[data-ia-disclosure="' + root + '"]').click();
    const submenu = page.locator('#brvtal-menu-' + root);
    await expect(submenu).toBeVisible();
    expect(await submenu.locator('button').allTextContents()).toEqual(labels);
  }
  await expect(page.locator('[data-ia-child="mail-templates"]')).toBeDisabled();
  await expect(page.locator('[data-ia-child="mail-test"]')).toBeDisabled();
});

test('AC-02: teclas, ARIA, foco y sesión restauran la raíz desplegada', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);
  const root = page.locator('[data-ia-root-button="music"]');
  await root.focus();
  await page.keyboard.press('Enter');
  await expect(root).toHaveAttribute('aria-expanded','true');
  await expect(page.locator('#brvtal-menu-music')).toBeVisible();
  await expect(root).toHaveAttribute('aria-controls','brvtal-menu-music');
  await expect(root).toBeFocused();
  expect(await page.evaluate(() => JSON.parse(sessionStorage.getItem('brvtal.discadmin.nav-v3')))).toContain('music');
  await page.reload();
  await expect(page.locator('#brvtal-menu-music')).toBeVisible();
  await page.locator('[data-ia-disclosure="music"]').focus();
  await page.keyboard.press('Space');
  await expect(page.locator('#brvtal-menu-music')).toBeHidden();
});

test('AC-02: móvil 390 px mantiene panel, desplegables y Escape restituye foco', async ({ page }) => {
  await serveHarness(page);
  await page.setViewportSize({width:390,height:844});
  await page.goto(harnessUrl);
  await expect(page.locator('[data-ia-root-button="ticketing"]')).toBeEnabled();
  await expectRootHierarchy(page);
  const opener = page.locator('.admin-menu-toggle');
  await opener.click();
  await expect(page.locator('.shell')).toHaveClass(/admin-nav-open/);
  await page.locator('[data-ia-disclosure="website"]').click();
  await expect(page.locator('.shell')).toHaveClass(/admin-nav-open/);
  await expect(page.locator('#brvtal-menu-website')).toBeVisible();
  await expect(page.locator('[data-ia-disclosure="website"]')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.locator('.shell')).not.toHaveClass(/admin-nav-open/);
  await expect(opener).toBeFocused();
  await opener.click();
  await expect(page.locator('#brvtal-menu-website')).toBeVisible();
  const nav = await page.locator('.side').boundingBox();
  expect(nav.x).toBeGreaterThanOrEqual(0);
  expect(nav.width).toBeLessThanOrEqual(390);
});

test('AC-03: el rol Puerta no ve Pedidos, Correos, Finanzas ni Cortesías', async ({ page }) => {
  await serveHarness(page,{role:'door'});
  await page.goto(harnessUrl);
  await expect(page.locator('[data-ia-root-button="ticketing"]')).toBeEnabled();
  await expect(page.locator('[data-ia-root="mail"]')).toHaveCount(0);
  await page.locator('[data-ia-disclosure="ticketing"]').click();
  expect(await page.locator('#brvtal-menu-ticketing button').allTextContents()).toEqual(['Control de acceso']);
  await expect(page.locator('[data-ia-child="finance"],[data-ia-child="orders"],[data-ia-child="courtesy"],[data-ia-child="refunds"]')).toHaveCount(0);
});

test('AC-03: rol Admin no recibe entradas de cortesías ni devoluciones', async ({ page }) => {
  await serveHarness(page,{role:'admin'});
  await page.goto(harnessUrl);
  await expect(page.locator('[data-ia-root-button="ticketing"]')).toBeEnabled();
  await page.locator('[data-ia-disclosure="ticketing"]').click();
  await expect(page.locator('[data-ia-child="orders"]')).toBeVisible();
  await expect(page.locator('[data-ia-child="courtesy"],[data-ia-child="refunds"]')).toHaveCount(0);
});

test('AC-03: sin permiso de Boletería se omiten módulos restringidos', async ({ page }) => {
  await serveHarness(page,{role:'denied'});
  await page.goto(harnessUrl);
  await expect(page.locator('[data-ia-root="ticketing"],[data-ia-root="mail"]')).toHaveCount(0);
  await expect(page.locator('[data-ia-root="events"]')).toHaveCount(1);
});

test('AC-04: Música y Sitio web navegan a sus módulos nativos sin duplicar editores', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);
  await page.locator('[data-ia-disclosure="music"]').click();
  await page.locator('[data-ia-child="sets"]').click();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('sets');
  expect(new URL(page.url()).searchParams.get('module')).toBe('sets');
  await page.locator('[data-ia-disclosure="website"]').click();
  await page.locator('[data-ia-child="theme"]').click();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('theme');
  expect(new URL(page.url()).searchParams.get('module')).toBe('theme');
  await expect(page.locator('[data-ia-root]')).toHaveCount(8);
});

test('AC-04: Memorias persiste como vista del workspace Multimedia existente', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl + '?module=media&view=memories');
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('media');
  await expect(page.locator('[data-ia-root-button="website"]')).toHaveClass(/active/);
  await expect(page.locator('#brvtal-menu-website')).toBeVisible();
  await expect(page.locator('[data-ia-child="media"]')).toHaveClass(/active/);
  expect(new URL(page.url()).searchParams.get('view')).toBe('memories');
});

test('AC-02/04: configuración profunda selecciona tab real y Back restaura URL', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);
  await page.locator('[data-ia-disclosure="settings"]').click();
  await page.locator('[data-ia-child="integrations"]').click();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('settings');
  await expect(page.locator('[data-ia-child="integrations"]')).toHaveClass(/active/);
  expect(new URL(page.url()).searchParams.get('settings')).toBe('integrations');
  await page.locator('[data-ia-child="security"]').click();
  await expect(page.locator('[data-ia-child="security"]')).toHaveClass(/active/);
  expect(new URL(page.url()).searchParams.get('settings')).toBe('advanced');
  await page.goBack();
  await expect.poll(() => new URL(page.url()).searchParams.get('settings')).toBe('integrations');
});

test('AC-02/04: deep link en Boletería conserva tab en navegación y recarga', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl + '?module=ticketing&tab=buyers');
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('ticketing');
  await expect(page.locator('[data-ticketing-tab="buyers"]')).toHaveAttribute('aria-selected','true');
  await expect(page.locator('[data-ia-root-button="ticketing"]')).toHaveClass(/active/);
  expect(new URL(page.url()).searchParams.get('tab')).toBe('buyers');
  await page.locator('[data-ia-disclosure="ticketing"]').click();
  await page.locator('[data-ia-child="orders"]').click();
  await expect(page.locator('[data-ticketing-tab="orders"]')).toHaveAttribute('aria-selected','true');
  expect(new URL(page.url()).searchParams.get('tab')).toBe('orders');
  await page.goBack();
  await expect.poll(() => new URL(page.url()).searchParams.get('tab')).toBe('buyers');
  await expect(page.locator('[data-ticketing-tab="buyers"]')).toHaveAttribute('aria-selected','true');
});

test('AC-05: Correos lleva a historial real y no ofrece editor futuro engañoso', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);
  await expect(page.locator('[data-ia-root-button="mail"]')).toBeEnabled();
  await page.locator('[data-ia-disclosure="mail"]').click();
  await expect(page.locator('[data-ia-child="mail-templates"]')).toBeDisabled();
  await expect(page.locator('[data-ia-child="mail-test"]')).toBeDisabled();
  await page.locator('[data-ia-child="mail-history"]').click();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('ticketing');
  await expect(page.locator('[data-ticketing-tab="mail"]')).toHaveAttribute('aria-selected','true');
  expect(new URL(page.url()).searchParams.get('tab')).toBe('mail');
  await expect(page.locator('[data-ia-root-button="mail"]')).toHaveClass(/active/);
});

test('AC-02: re-render de shell y llegada tardía mantienen exactamente las ocho raíces', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);
  await expectRootHierarchy(page);
  await page.evaluate(() => window.__renderShell('artists'));
  await expectRootHierarchy(page);
  await page.evaluate(() => {
    const late = document.createElement('button');
    late.textContent = 'HERRAMIENTA LEGACY';
    late.dataset.adminNav = 'activity';
    document.querySelector('.side .nav').appendChild(late);
  });
  await expectRootHierarchy(page);
  await expect(page.getByText('HERRAMIENTA LEGACY')).toHaveCount(0);
});

for (const width of [390,1440]) {
  test('AC-06: capturas comparables antes/después a ' + width + ' px', async ({ page }) => {
    await serveHarness(page);
    mkdirSync(join(process.cwd(),'test-results'),{recursive:true});
    await page.setViewportSize({width,height:900});
    await page.goto(harnessUrl + '?legacy=1');
    if (width === 390) await page.locator('.admin-menu-toggle').click();
    await page.screenshot({path:join(process.cwd(),'test-results','discadmin-v3-before-'+width+'.png'),fullPage:true});
    await page.goto(harnessUrl);
    await expect(page.locator('[data-ia-root-button="ticketing"]')).toBeEnabled();
    await expectRootHierarchy(page);
    if (width === 390) await page.locator('.admin-menu-toggle').click();
    await page.screenshot({path:join(process.cwd(),'test-results','discadmin-v3-after-'+width+'.png'),fullPage:true});
    const rootSize = await page.locator('[data-ia-root-button="dashboard"]').boundingBox();
    expect(rootSize.height).toBeGreaterThanOrEqual(44);
  });
}

test('successful Dashboard navigation resolves only after canonical active nav is synchronized', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  const result = await page.evaluate(async () => {
    window.__renderShell('hero-slider');
    history.replaceState({brvtalAdminRoute:'hero-slider'}, '', '?module=hero-slider');
    window.BRVTALAdminIA.rebuildNavigation();

    const committed = await window.go('dashboard');
    const dashboard = [...document.querySelectorAll('.side .nav button')]
      .find(button => button.textContent.trim() === 'Escritorio');

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
    const banners = [...document.querySelectorAll('.side .nav button')]
      .find(button => button.textContent.trim() === 'Banners');

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

    const artists = [...document.querySelectorAll('.side .nav button')]
      .find(button => button.textContent.trim() === 'Artistas');

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