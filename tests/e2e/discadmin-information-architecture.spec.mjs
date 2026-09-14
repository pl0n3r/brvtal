import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const iaJs = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.js'), 'utf8');
const iaCss = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.css'), 'utf8');
const wrapper = readFileSync(join(process.cwd(), 'discadmin/index.php'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin-ia-e2e.html';

function harness(authed = true) {
  return `<!doctype html><html><head><style>${iaCss}</style></head><body><div id="app"></div><script>
    window.state={authed:${authed ? 'true' : 'false'},section:'dashboard'};
    window.__legacyOpen=[];
    window.__moduleLoadOptions=[];
    function navButton(label,handler,data=''){return '<button '+(data?'data-admin-nav="'+data+'" ':'')+'onclick="'+handler+'">'+label+'</button>'}
    window.__renderShell=function(section){
      state.section=section;
      const artistToolbar=section==='artists'?'<div class="toolbar"><input class="search"><button class="btn red">+ NEW ARTIST</button></div>':'<div class="native-view">NATIVE '+section.toUpperCase()+'</div>';
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
        +'</div></aside><main class="main"><div class="top"><h1>'+section.toUpperCase()+'</h1></div>'+artistToolbar+'</main></div>';
    };
    window.go=async function(section){window.__renderShell(section);};
    window.tech=async function(section){window.__renderShell(section);};
    window.openModal=function(type,id){window.__legacyOpen.push([type,id]);};
    window.BRVTALAdminModules={
      cancel(){},
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

test('Events is the single entry to the guided event editor', async ({ page }) => {
  await serveHarness(page);
  await page.goto(harnessUrl);

  await page.evaluate(() => window.go('events'));
  await expect(page.locator('.main .top h1')).toHaveText('EVENTS');
  await expect(page.locator('[data-admin-module="content-core"]')).toHaveAttribute('data-ia-context','events');
  await expect(page.locator('[data-admin-module="content-core"] .tabs')).toBeHidden();
  await expect(page.locator('#eventsTab')).toBeVisible();
  await expect(page.locator('#rosterTab')).toBeHidden();
  await expect(page.locator('#eventModal .ey')).toHaveText('EVENTS / EDITOR');
  await expect(page.getByText('Identity, date and place, lifecycle, tickets and lineup are managed here as one workflow.')).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.state.section)).toBe('events');
  await expect.poll(() => page.evaluate(() => window.__moduleLoadOptions.at(-1)?.syncUrl)).toBe(false);
  await expect.poll(() => new URL(page.url()).searchParams.get('module')).toBe('events');

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