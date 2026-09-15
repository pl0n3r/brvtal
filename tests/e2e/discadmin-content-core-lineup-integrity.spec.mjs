import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const contentCoreJs = readFileSync(join(process.cwd(), 'discadmin/content-core.js'), 'utf8');
const lineupJs = readFileSync(join(process.cwd(), 'discadmin/content-core-lineup.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/e2e-content-core-lineup-integrity.html';

const events = [
  {id:42,title:'NIGHT 42',slug:'night-42',description:'desc',status:'draft',city:'Pereira',venue:'Warehouse',event_date:'2026-11-21 22:00:00'},
  {id:43,title:'NIGHT 43',slug:'night-43',description:'desc',status:'draft',city:'Bogota',venue:'Bodega',event_date:'2026-12-01 22:00:00'},
];
const artists = [
  {id:1,name:'ARTIST A',collective_status:'active',collective_order:1},
  {id:2,name:'ARTIST B',collective_status:'active',collective_order:2},
];

function html() {
  const fields = [
    'e_title','e_slug','e_description','e_cover_image','e_accent','e_event_date','e_city','e_venue',
    'e_archive_year','e_ticket_instructions','e_ticket_qr','e_ticket_url',
  ].map(id => id === 'e_description' || id === 'e_ticket_instructions'
    ? `<textarea id="${id}"></textarea>`
    : `<input id="${id}">`).join('');
  return `<!doctype html><html><body><div id="root">
    <div id="cc-notice"></div><div id="eventNotice"></div>
    <input id="eventSearch"><div id="eventsTable"></div>
    <input id="artistSearch"><div id="rosterList"></div><div id="artistDetail"></div>
    <div id="eventsTab"></div><div id="rosterTab"></div>
    <div id="eventModal"><div id="eventHeading"></div>${fields}
      <select id="e_featured"><option value="0">0</option><option value="1">1</option></select>
      <select id="e_status"><option value="draft">draft</option><option value="published">published</option></select>
      <div id="tickets"></div><div id="eventArtists"></div>
      <div class="step" data-step="1"></div><div class="step-content" data-content="1"></div>
      <button id="prevBtn"></button><button id="nextBtn"></button><button id="cc-saveBtn"></button>
    </div>
  </div>
  <script>${lineupJs}</script><script>${contentCoreJs}</script>
  <script>window.BRVTALContentCore.mount(document.getElementById('root'));</script>
  </body></html>`;
}

async function installHarness(page) {
  await page.route(harness, route => route.fulfill({contentType:'text/html; charset=utf-8',body:html()}));
}

function json(route, payload) {
  return route.fulfill({contentType:'application/json',body:JSON.stringify(payload)});
}

test('existing Event blocks save until lineup hydration and preserves role plus lineup_order', async ({ page }) => {
  let eventWrites = 0;
  const lineupWrites = [];
  await installHarness(page);
  await page.route('**/api/index.php/**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    const method = request.method();
    if (path.endsWith('/auth')) return json(route,{authenticated:true,csrf:'csrf-test'});
    if (path.endsWith('/artists')) return json(route,{ok:true,data:artists});
    if (path.endsWith('/ticket_types')) return json(route,{ok:true,data:[]});
    if (path.endsWith('/events/42/lineup') && method === 'GET') {
      await new Promise(resolve => setTimeout(resolve, 220));
      return json(route,{ok:true,data:[
        {artist_id:2,lineup_order:0,role:'LIVE SET'},
        {artist_id:1,lineup_order:4,role:'HEADLINER'},
      ]});
    }
    if (path.endsWith('/events/42/lineup') && method === 'POST') {
      lineupWrites.push(JSON.parse(request.postData() || '{}'));
      return json(route,{ok:true});
    }
    if (path.endsWith('/events/42') && method === 'PUT') {
      eventWrites += 1;
      return json(route,{ok:true,id:42});
    }
    if (path.endsWith('/events') && method === 'GET') return json(route,{ok:true,data:events});
    return json(route,{ok:true});
  });

  await page.goto(harness);
  await expect(page.locator('#eventsTable')).toContainText('NIGHT 42');
  await page.evaluate(() => window.BRVTALContentCore.openEvent(42));
  await expect(page.locator('#tickets')).toHaveAttribute('data-load-state','ready');
  await expect(page.locator('#eventArtists')).toHaveAttribute('data-load-state','loading');

  const blocked = await page.evaluate(() => window.BRVTALContentCore.saveEvent());
  expect(blocked).toBe(false);
  expect(eventWrites).toBe(0);
  expect(lineupWrites).toHaveLength(0);
  await expect(page.locator('#eventNotice')).toContainText('Wait for event participation to load');

  await expect(page.locator('#eventArtists')).toHaveAttribute('data-load-state','ready');
  await expect(page.locator('#eventArtists [data-artist]:checked')).toHaveCount(2);
  const saved = await page.evaluate(() => window.BRVTALContentCore.saveEvent());
  expect(saved).toBe(true);
  expect(eventWrites).toBe(1);
  expect(lineupWrites).toEqual([{lineup:[
    {artist_id:2,lineup_order:0,role:'LIVE SET'},
    {artist_id:1,lineup_order:4,role:'HEADLINER'},
  ]}]);
});

test('lineup hydration is latest-wins when the editor switches Events', async ({ page }) => {
  await installHarness(page);
  await page.route('**/api/index.php/**', async route => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path.endsWith('/auth')) return json(route,{authenticated:true,csrf:'csrf-test'});
    if (path.endsWith('/artists')) return json(route,{ok:true,data:artists});
    if (path.endsWith('/ticket_types')) return json(route,{ok:true,data:[]});
    if (path.endsWith('/events/42/lineup')) {
      await new Promise(resolve => setTimeout(resolve, 180));
      return json(route,{ok:true,data:[{artist_id:1,lineup_order:0,role:'OLD'}]});
    }
    if (path.endsWith('/events/43/lineup')) {
      await new Promise(resolve => setTimeout(resolve, 20));
      return json(route,{ok:true,data:[{artist_id:2,lineup_order:0,role:'CURRENT'}]});
    }
    if (path.endsWith('/events')) return json(route,{ok:true,data:events});
    return json(route,{ok:true});
  });

  await page.goto(harness);
  await expect(page.locator('#eventsTable')).toContainText('NIGHT 42');
  await page.evaluate(() => {
    window.BRVTALContentCore.openEvent(42);
    window.BRVTALContentCore.openEvent(43);
  });
  await expect(page.locator('#eventModal')).toHaveAttribute('data-event-id','43');
  await expect(page.locator('#eventArtists')).toHaveAttribute('data-load-state','ready');
  await page.waitForTimeout(230);

  const checked = await page.locator('#eventArtists [data-artist]:checked').evaluateAll(nodes => nodes.map(node => Number(node.dataset.artist)));
  expect(checked).toEqual([2]);
  await expect(page.locator('#eventHeading')).toHaveText('EDIT EVENT');
});
