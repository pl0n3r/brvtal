import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const contentCoreJs = readFileSync(join(process.cwd(), 'discadmin/content-core.js'), 'utf8');
const contentCoreNavJs = readFileSync(join(process.cwd(), 'discadmin/content-core-nav.js'), 'utf8');
const seoMetadataJs = readFileSync(join(process.cwd(), 'discadmin/seo-metadata.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/e2e-content-core-drafts.html';

function harnessHtml(withSeo = false) {
  return `<!doctype html><html><body>
    <div id="root" data-admin-module="content-core">
      <div id="cc-notice"></div>
      <input id="eventSearch"><div id="eventsTable"></div>
      <input id="artistSearch"><div id="rosterList"></div>
      <div id="eventArtists"></div><div id="artistDetail"></div>
      <div id="eventModal"><div id="eventHeading"></div><div id="eventNotice" class="notice"></div>
        <button id="cc-top-saveBtn" onclick="BRVTALContentCore.saveEvent()">SAVE</button>
        <div class="steps">
          <div role="button" tabindex="0" class="step active" data-step="1">01 · IDENTITY</div>
          <div role="button" tabindex="0" class="step" data-step="2">02 · DATE & PLACE</div>
          <div role="button" tabindex="0" class="step" data-step="3">03 · LIFECYCLE</div>
          <div role="button" tabindex="0" class="step" data-step="4">04 · TICKETS</div>
          <div role="button" tabindex="0" class="step" data-step="5">05 · ROSTER</div>
        </div>
        <div class="step-content active" data-content="1">
          <input id="e_title"><input id="e_slug"><textarea id="e_description"></textarea>
          <input id="e_cover_image"><input id="e_accent"><select id="e_featured"><option value="0">0</option><option value="1">1</option></select>
        </div>
        <div class="step-content" data-content="2"><input id="e_event_date" type="datetime-local"><input id="e_city"><input id="e_venue"><input id="e_archive_year"></div>
        <div class="step-content" data-content="3">
          <select id="e_status"><option value="draft">draft</option><option value="published">published</option><option value="upcoming">upcoming</option></select>
          <textarea id="e_ticket_instructions"></textarea><input id="e_ticket_qr"><input id="e_ticket_url">
        </div>
        <div class="step-content" data-content="4"><div id="tickets"></div></div>
        <div class="step-content" data-content="5"></div>
        <button id="prevBtn">BACK</button><button id="nextBtn">NEXT</button><button id="cc-saveBtn">SAVE DRAFT</button>
      </div>
    </div>
    <script>${contentCoreJs}</script>
    <script>${contentCoreNavJs}</script>
    ${withSeo ? `<script>${seoMetadataJs}</script>` : ''}
    <script>window.BRVTALContentCore.mount(document.getElementById('root'));</script>
  </body></html>`;
}

async function installHarness(page, eventPosts, options = {}) {
  await page.route('**/discadmin/e2e-content-core-drafts.html', route => route.fulfill({
    contentType: 'text/html',
    body: harnessHtml(options.withSeo)
  }));

  await page.route('**/api/index.php/**', async route => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    const method = route.request().method();

    if (path.endsWith('/auth') && method === 'GET') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ authenticated:true, csrf:'csrf-test' }) });
    }
    if (path.endsWith('/events') && method === 'GET') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok:true, data:options.events || [] }) });
    }
    if (path.endsWith('/artists') && method === 'GET') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok:true, data:[] }) });
    }
    if (path.endsWith('/ticket_types') && method === 'GET') {
      if (options.ticketDelay) await new Promise(resolve => setTimeout(resolve, options.ticketDelay));
      return route.fulfill({ status:options.ticketLoadStatus || 200, contentType:'application/json', body: JSON.stringify(options.ticketLoadStatus ? {ok:false,error:'TICKET_LOAD_FAILED'} : {ok:true,data:options.ticketTypes || []}) });
    }
    if (path.endsWith('/events') && method === 'POST') {
      const headers = route.request().headers();
      eventPosts.push({
        body: JSON.parse(route.request().postData() || '{}'),
        contentType: headers['content-type'] || '',
        csrf: headers['x-csrf-token'] || '',
      });
      return route.fulfill({ status:201, contentType:'application/json', body: JSON.stringify({ ok:true, id:101 }) });
    }
    if (path.endsWith('/events/42') && method === 'PUT') {
      options.eventPuts?.push(JSON.parse(route.request().postData() || '{}'));
      return route.fulfill({ status:options.putStatus || 200, contentType:'application/json', body: JSON.stringify(options.putStatus === 500 ? { ok:false, error:'SAVE_FAILED' } : { ok:true, id:42 }) });
    }
    if (path.endsWith('/events/101') && method === 'PUT') {
      options.eventPuts?.push(JSON.parse(route.request().postData() || '{}'));
      return route.fulfill({ contentType:'application/json', body: JSON.stringify({ok:true,id:101}) });
    }
    if (path.endsWith('/ticket_types') && method === 'POST') {
      options.ticketPosts?.push(JSON.parse(route.request().postData() || '{}'));
      const failed = options.failFirstTicket && options.ticketPosts.length === 1;
      return route.fulfill({ status:failed?500:201, contentType:'application/json', body: JSON.stringify(failed?{ok:false,error:'TICKET_SAVE_FAILED'}:{ok:true,id:17}) });
    }
    if (path.endsWith('/events/42/lineup') && method === 'POST') {
      options.lineupPosts?.push(JSON.parse(route.request().postData() || '{}'));
      return route.fulfill({ contentType:'application/json', body: JSON.stringify({ ok:true }) });
    }
    if (path.endsWith('/ticket_types/7') && method === 'PUT') {
      options.ticketPuts?.push(JSON.parse(route.request().postData() || '{}'));
      return route.fulfill({ contentType:'application/json', body: JSON.stringify({ok:true,changed:1}) });
    }

    return route.fulfill({ status:404, contentType:'application/json', body: JSON.stringify({ ok:false, error:'UNEXPECTED_ROUTE' }) });
  });

  await page.goto(harnessUrl);
  await page.waitForFunction(() => typeof window.BRVTALContentCore?.saveEvent === 'function');
}

test('Content Core reloads SEO metadata when switching between existing events', async ({ page }) => {
  const eventPuts = [];
  await installHarness(page, [], {
    withSeo:true,eventPuts,
    events:[
      {id:42,title:'FIRST EVENT',slug:'first-event',status:'draft',seo_title:'FIRST SEO',seo_description:'First search description'},
      {id:43,title:'SECOND EVENT',slug:'second-event',status:'draft',seo_title:'SECOND SEO',seo_description:'Second search description'},
    ],
  });
  await page.evaluate(() => window.BRVTALContentCore.openEvent(42));
  await expect(page.locator('#e_seo_title')).toHaveValue('FIRST SEO');
  const immediateSave = await page.evaluate(async () => {
    window.BRVTALContentCore.closeEvent();
    window.BRVTALContentCore.openEvent(43);
    return window.BRVTALContentCore.saveEvent();
  });
  expect(immediateSave).toBe(false);
  expect(eventPuts).toHaveLength(0);
  await expect(page.locator('#e_seo_title')).toHaveValue('SECOND SEO');
  await expect(page.locator('#e_seo_description')).toHaveValue('Second search description');
  await expect(page.locator('[data-seo-editor="content-core"]')).toHaveCount(1);
});

test('Content Core loads existing ticket types before editing and updates their IDs', async ({ page }) => {
  const eventPuts = [], ticketPuts = [];
  await installHarness(page, [], {
    events:[{id:42,title:'EXISTING EVENT',status:'draft'}], eventPuts, ticketPuts,
    ticketTypes:[{id:7,event_id:42,name:'EARLY',price:'25.00',status:'active',sort_order:0},{id:8,event_id:99,name:'OTHER EVENT',status:'active'}],
  });
  await page.evaluate(() => window.BRVTALContentCore.openEvent(42));
  await expect(page.locator('#tickets .ticket-row')).toHaveCount(1);
  await expect(page.locator('#tickets [data-k="name"]')).toHaveValue('EARLY');
  await page.locator('#tickets [data-k="price"]').fill('30');
  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(true);
  expect(eventPuts).toHaveLength(1);
  expect(ticketPuts).toHaveLength(1);
  expect(ticketPuts[0]).toMatchObject({event_id:42,name:'EARLY',price:'30'});
});

test('Content Core retries tickets on the created event after a partial save', async ({ page }) => {
  const eventPosts = [], eventPuts = [], ticketPosts = [];
  await installHarness(page, eventPosts, {eventPuts,ticketPosts,failFirstTicket:true});
  await page.fill('#e_title', 'NEW NIGHT');
  await page.evaluate(() => window.BRVTALContentCore.addTicket({name:'GENERAL'}));

  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(false);
  expect(eventPosts).toHaveLength(1);
  await expect(page.locator('#eventNotice')).toContainText('Event saved, but tickets could not be saved');

  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(true);
  expect(eventPosts).toHaveLength(1);
  expect(eventPuts).toHaveLength(1);
  expect(ticketPosts).toHaveLength(2);
  expect(ticketPosts[1].event_id).toBe(101);
  await expect(page.locator('#tickets .ticket-row')).toHaveAttribute('data-id', '17');
});

test('Content Core catches an unnamed ticket before creating its event', async ({ page }) => {
  const eventPosts = [], ticketPosts = [];
  await installHarness(page, eventPosts, {ticketPosts});
  await page.fill('#e_title', 'NIGHT WITH TICKETS');
  await page.evaluate(() => window.BRVTALContentCore.addTicket());

  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(false);
  expect(eventPosts).toHaveLength(0);
  expect(ticketPosts).toHaveLength(0);
  await expect(page.locator('#eventNotice')).toContainText('Ticket 1 needs a name before saving.');
  await expect(page.locator('.step-content[data-content="4"]')).toHaveClass(/active/);
  await expect(page.locator('#tickets [data-k="name"]')).toBeFocused();
  await expect(page.locator('#e_title')).toHaveValue('NIGHT WITH TICKETS');

  await page.locator('#tickets [data-k="name"]').fill('GENERAL');
  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(true);
  expect(eventPosts).toHaveLength(1);
  expect(ticketPosts).toHaveLength(1);
});

test('Content Core checks ticket price and purchase URL before creating its event', async ({ page }) => {
  const eventPosts = [], ticketPosts = [];
  await installHarness(page, eventPosts, {ticketPosts});
  await page.fill('#e_title', 'NEW TICKETED NIGHT');
  await page.evaluate(() => window.BRVTALContentCore.addTicket({name:'GENERAL'}));
  await page.locator('#tickets [data-k="price"]').fill('-1');

  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(false);
  expect(eventPosts).toHaveLength(0);
  await expect(page.locator('#eventNotice')).toContainText('Ticket 1 needs a valid non-negative price.');
  await expect(page.locator('#tickets [data-k="price"]')).toBeFocused();

  await page.locator('#tickets [data-k="price"]').fill('25');
  await page.locator('#tickets [data-k="external_url"]').fill('javascript:alert(1)');
  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(false);
  expect(eventPosts).toHaveLength(0);
  await expect(page.locator('#eventNotice')).toContainText('Ticket 1 needs a valid http(s) purchase URL.');
  await expect(page.locator('#tickets [data-k="external_url"]')).toBeFocused();

  await page.locator('#tickets [data-k="external_url"]').fill('https://tickets.example/general');
  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(true);
  expect(eventPosts).toHaveLength(1);
  expect(ticketPosts).toHaveLength(1);
});

test('Content Core refuses an existing-event save while ticket types are unavailable', async ({ page }) => {
  const eventPuts = [];
  await installHarness(page, [], {
    events:[{id:42,title:'EXISTING EVENT',status:'draft'}], eventPuts, ticketLoadStatus:500,
  });
  await page.evaluate(() => window.BRVTALContentCore.openEvent(42));
  await expect(page.locator('#eventNotice')).toContainText('Could not load ticket types');
  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(false);
  expect(eventPuts).toHaveLength(0);
  await expect(page.locator('#eventNotice')).toContainText('Reload ticket types before saving');
});

test('Content Core does not save participation when editing the event fails', async ({ page }) => {
  const eventPuts = [];
  await installHarness(page, [], {
    events:[{ id:42, title:'EXISTING EVENT', status:'draft' }],
    putStatus:500, eventPuts,
  });
  await page.evaluate(() => {
    window.__lineupSaves = [];
    window.BRVTALContentCoreLineup = {save: async (...args) => { window.__lineupSaves.push(args); return {ok:true}; }};
  });
  await page.evaluate(() => window.BRVTALContentCore.openEvent(42));
  await expect(page.locator('#tickets')).toHaveAttribute('data-load-state', 'ready');
  await page.fill('#e_title', 'CHANGED TITLE');
  const saved = await page.evaluate(() => window.BRVTALContentCore.saveEvent());
  expect(saved).toBe(false);
  expect(eventPuts).toHaveLength(1);
  expect(await page.evaluate(() => window.__lineupSaves)).toHaveLength(0);
  await expect(page.locator('#eventNotice')).toContainText('Save failed: SAVE_FAILED');
  await expect(page.locator('#cc-notice')).not.toContainText('Event participation saved.');
});

test('Content Core saves participation after an event save succeeds', async ({ page }) => {
  const eventPuts = [];
  await installHarness(page, [], {
    events:[{ id:42, title:'EXISTING EVENT', status:'draft' }], eventPuts,
  });
  await page.evaluate(() => {
    window.__lineupSaves = [];
    window.BRVTALContentCoreLineup = {save: async (...args) => { window.__lineupSaves.push(args); return {ok:true}; }};
    window.BRVTALContentCore.openEvent(42);
  });
  await expect(page.locator('#tickets')).toHaveAttribute('data-load-state', 'ready');
  await page.fill('#e_title', 'CHANGED TITLE');
  const saved = await page.evaluate(() => window.BRVTALContentCore.saveEvent());
  expect(saved).toBe(true);
  expect(eventPuts).toHaveLength(1);
  expect(await page.evaluate(() => window.__lineupSaves)).toHaveLength(1);
});

test('Content Core saves an incomplete event while it remains draft with JSON and CSRF headers', async ({ page }) => {
  const posts = [];
  await installHarness(page, posts);

  await page.fill('#e_title', 'QA CONTENT CORE - DELETE ME');
  await page.selectOption('#e_status', 'draft');
  await page.evaluate(() => window.BRVTALContentCore.saveEvent());

  await expect.poll(() => posts.length).toBe(1);
  expect(posts[0].body.title).toBe('QA CONTENT CORE - DELETE ME');
  expect(posts[0].body.status).toBe('draft');
  expect(posts[0].body.event_date).toBeNull();
  expect(posts[0].body.city).toBe('');
  expect(posts[0].contentType).toContain('application/json');
  expect(posts[0].csrf).toBe('csrf-test');
  await expect(page.locator('#cc-notice')).toContainText('Event saved.');
});

test('Content Core requires date and city before an event leaves draft', async ({ page }) => {
  const posts = [];
  await installHarness(page, posts);

  await page.fill('#e_title', 'QA PUBLIC EVENT');
  await page.selectOption('#e_status', 'published');
  await page.evaluate(() => window.BRVTALContentCore.saveEvent());

  expect(posts).toHaveLength(0);
  await expect(page.locator('#eventNotice')).toContainText('Name, date and city are required before leaving draft.');
});

test('Content Core supports direct clickable step navigation and a top save action', async ({ page }) => {
  const posts = [];
  await installHarness(page, posts);

  await page.click('.step[data-step="2"]');
  await expect(page.locator('.step[data-step="1"]')).toHaveClass(/active/);
  await expect(page.locator('#eventNotice')).toContainText('Event name is required before continuing.');

  await page.fill('#e_title', 'QA DIRECT NAV');
  await page.click('.step[data-step="4"]');
  await expect(page.locator('.step[data-step="4"]')).toHaveClass(/active/);
  await expect(page.locator('.step-content[data-content="4"]')).toHaveClass(/active/);

  await page.click('.step[data-step="2"]');
  await expect(page.locator('.step[data-step="2"]')).toHaveClass(/active/);
  await expect(page.locator('.step-content[data-content="2"]')).toHaveClass(/active/);

  await page.click('#cc-top-saveBtn');
  await expect.poll(() => posts.length).toBe(1);
  expect(posts[0].body.title).toBe('QA DIRECT NAV');
});

test('Content Core public preview uses current unsaved Event and Ticket values without saving', async ({ page }) => {
  const eventPosts = [];
  await installHarness(page, eventPosts);

  await page.fill('#e_title', 'UNSAVED PREVIEW EVENT');
  await page.fill('#e_slug', 'unsaved-preview-event');
  await page.fill('#e_city', 'Pereira');
  await page.fill('#e_venue', 'La Perla');
  await page.fill('#e_event_date', '2026-10-31T22:30');
  await page.evaluate(() => {
    window.__publicPreviewCall = null;
    window.BRVTALPublicPreview = {
      open: async (type, payload) => {
        window.__publicPreviewCall = {type, payload};
        return {url:'/preview/test'};
      }
    };
    window.BRVTALContentCore.addTicket();
  });
  await page.locator('#tickets [data-k="name"]').fill('PREVENTA');
  await page.locator('#tickets [data-k="price"]').fill('20000');
  await page.locator('#tickets [data-k="external_url"]').fill('https://tickets.example.test/genesis');

  await page.evaluate(() => window.BRVTALContentCore.previewEvent());
  const preview = await page.evaluate(() => window.__publicPreviewCall);

  expect(eventPosts).toHaveLength(0);
  expect(preview.type).toBe('events');
  expect(preview.payload).toMatchObject({
    title:'UNSAVED PREVIEW EVENT',
    slug:'unsaved-preview-event',
    city:'Pereira',
    venue:'La Perla',
    event_date:'2026-10-31 22:30',
  });
  expect(preview.payload.ticket_types).toHaveLength(1);
  expect(preview.payload.ticket_types[0]).toMatchObject({
    name:'PREVENTA',
    price:'20000',
    external_url:'https://tickets.example.test/genesis',
  });
  expect(preview.payload.lineup).toEqual([]);
});
