import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const appJs = readFileSync(join(process.cwd(), 'js/app.js'), 'utf8');
const archiveJs = readFileSync(join(process.cwd(), 'js/archive.js'), 'utf8');
const mediaJs = readFileSync(join(process.cwd(), 'js/public-media.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-runtime-fallback-e2e.html';
const escapedCmsTitle = 'CMS <EVENT> & "RAVE" \'NIGHT\'';
const escapedCmsImage = '/uploads/event-"hero"&<cut>.jpg';

for (const missing of ['GSAP', 'ScrollTrigger']) {
  test(`public navigation and CMS work without ${missing}`, async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/api/public.php', route => route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ok:true,data:{events:[{id:1,title:escapedCmsTitle,status:'published',cover_image:escapedCmsImage}],artists:[],sets:[],media:[],settings:{}}}),
    }));
    await page.route(harnessUrl, route => route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: `<!doctype html><html lang="en"><body>
        <div id="loader"></div><button id="menuToggle">MENU <strong>+</strong></button>
        <aside id="menuPanel" aria-hidden="true"><a href="#events">EVENTS</a></aside>
        <button id="soundToggle">SOUND <b>OFF</b></button>
        <span id="dynamicStatus"></span><div id="apiFallback"></div>
        <main><div class="events-track"></div></main>
        ${missing === 'ScrollTrigger' ? '<script>window.gsap={registerPlugin(){},ticker:{add(){},lagSmoothing(){}},from(){},to(){},fromTo(){}};</script>' : ''}
        <script>${appJs}</script>
      </body></html>`,
    }));

    await page.goto(harnessUrl);
    await expect(page.locator('#loader')).toHaveCount(0);
    await page.getByRole('button', {name:/MENU/}).click();
    await expect(page.locator('#menuPanel')).toHaveAttribute('aria-hidden', 'false');
    await expect(page.locator('.events-track h3')).toHaveText(escapedCmsTitle);
    await expect(page.locator('.events-track event')).toHaveCount(0);
    await expect(page.locator('.event-img img')).toHaveAttribute('src', escapedCmsImage);
    await expect(page.locator('#dynamicStatus')).toHaveText('LIVE / CMS CONNECTED');
    expect(errors).toEqual([]);
  });
}

test('public modules share one CMS request while rendering archive and curated Memories', async ({ page }) => {
  let requests = 0;
  await page.route('**/api/public.php', route => {
    requests++;
    return route.fulfill({
      contentType:'application/json',
      body:JSON.stringify({ok:true,data:{
        events:[{id:1,title:'NEXT NIGHT',status:'published'}],artists:[],sets:[],settings:{},
        media:[{id:3,type:'image',title:'Raw library asset',file_path:'/uploads/raw-library.jpg'}],
        memories:[{id:8,media_id:3,type:'image',title:'Crowd Memory',context:'Archive floor',file_path:'/uploads/memory.jpg'}],
        archive:{years:[2025],counts:{events:1,sets:0,media:1},events:[{id:2,title:'PAST NIGHT',slug:'past-night',archive_year:2025,status:'archived'}]},
      }}),
    });
  });
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html lang="en"><body>
      <div id="loader"></div><button id="menuToggle">MENU <strong>+</strong></button><aside id="menuPanel" aria-hidden="true"></aside>
      <button id="soundToggle">SOUND <b>OFF</b></button><span id="dynamicStatus"></span><div id="apiFallback"></div>
      <div class="events-track"></div><section id="eventArchive"><div class="archive-years"></div><div class="archive-summary"></div><div class="archive-grid"></div></section>
      <section class="media"><div class="media-grid"></div></section>
      <script>${appJs}</script><script>${archiveJs}</script><script>${mediaJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);
  await expect(page.locator('#dynamicStatus')).toHaveText('LIVE / CMS CONNECTED');
  await expect(page.locator('[data-archive-event]')).toHaveCount(1);
  await expect(page.locator('[data-public-media-item]')).toHaveCount(1);
  await expect(page.locator('[data-public-media-item]')).toContainText('Crowd Memory');
  await expect(page.locator('[data-public-media-item]')).not.toContainText('Raw library asset');
  expect(requests).toBe(1);
});


test('dynamic CMS nodes keep cursor, magnetic and artist-preview behavior after interaction refactor', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));

  await page.route('**/api/public.php', route => route.fulfill({
    contentType:'application/json',
    body:JSON.stringify({ok:true,data:{
      events:[],
      artists:[{
        id:7,
        name:'PL0N3R',
        photo:'/uploads/pl0n3r.jpg',
        website_url:'https://example.com/pl0n3r',
      }],
      sets:[{
        id:9,
        title:'BRVTAL SET 09',
        artist_name:'PL0N3R',
        external_url:'https://example.com/set-09',
        platform:'soundcloud',
      }],
      media:[],
      settings:{},
    }}),
  }));

  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html lang="en"><body>
      <button id="menuToggle">MENU <strong>+</strong></button>
      <aside id="menuPanel" aria-hidden="true"></aside>
      <button id="soundToggle">SOUND <b>OFF</b></button>
      <div class="cursor"></div><div class="cursor-label"></div>
      <span id="dynamicStatus"></span><div id="apiFallback"></div>
      <div class="events-track"></div>
      <div class="artist-list"></div><div class="artist-preview"><img alt=""></div>
      <div class="set-list"></div><div class="media-grid"></div>
      <script>
        window.__gsapCalls=[];
        window.ScrollTrigger={update(){},refresh(){},create(){}};
        window.gsap={
          registerPlugin(){},
          ticker:{add(){},lagSmoothing(){}},
          from(){},
          fromTo(){},
          to(target,vars){window.__gsapCalls.push({target:target.className||target.tagName,vars});}
        };
      </script>
      <script>${appJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);
  await expect(page.locator('#dynamicStatus')).toHaveText('LIVE / CMS CONNECTED');

  const artist = page.locator('.artist');
  await expect(artist).toHaveAttribute('data-dynamic-bound', '1');
  await expect(artist).toHaveAttribute('data-dynamic-artist-bound', '1');
  await artist.hover();
  await expect(page.locator('.cursor-label')).toHaveText('PROFILE');
  await expect(page.locator('.artist-preview img')).toHaveAttribute('src', '/uploads/pl0n3r.jpg');

  const magnetic = page.locator('.set-action');
  await expect(magnetic).toHaveAttribute('data-dynamic-magnetic', '1');
  await magnetic.dispatchEvent('pointermove', {clientX:24,clientY:18});
  expect(await page.evaluate(() => window.__gsapCalls.some(call => (
    String(call.target || '').includes('set-action')
    && typeof call.vars.x === 'number'
    && typeof call.vars.y === 'number'
  )))).toBe(true);

  expect(errors).toEqual([]);
});
