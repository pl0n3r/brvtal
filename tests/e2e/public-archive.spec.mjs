import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const archiveJs = readFileSync(join(process.cwd(), 'js/archive.js'), 'utf8');
const archiveCss = readFileSync(join(process.cwd(), 'css/archive.css'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-archive-e2e.html';

test('public archive separates active lifecycle from historical nights and filters by year', async ({ page }) => {
  await page.route('**/api/public*', route => route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({
      ok: true,
      data: {
        events: [
          { id: 10, title: 'Next Night', event_date: '2026-10-03 21:00:00', city: 'Pereira', venue: 'Warehouse', description: 'BRVTAL', status: 'published', cover_image: '/next.jpg', ticket_url: 'https://tickets.example/next' },
          { id: 11, title: 'Tickets Live', event_date: '2026-10-24 21:00:00', city: 'Bogota', venue: 'Club', description: 'BRVTAL', status: 'tickets_available', cover_image: '/tickets.jpg', ticket_url: 'https://tickets.example/live' },
        ],
        archive: {
          years: [2026, 2025],
          counts: { events: 2, sets: 1, media: 9, releases: 3 },
          events: [
            { id: 20, title: 'Session Five', slug: 'session-five', event_date: '2026-08-07 21:00:00', archive_year: 2026, city: 'Pereira', venue: 'Studio', status: 'finished', cover_image: '/archive-2026.jpg', ticket_url: null, lineup: [{name:'PERKS'},{name:'DNL5'},{name:'OPENING ACT'},{name:'LATE ACT'},{name:'FINAL SIGNAL'}], related_sets: [{id:90,title:'Session Five Set'}] },
            { id: 21, title: 'Old Signal', slug: 'old-signal', event_date: '2025-05-01 21:00:00', archive_year: 2025, city: 'Bogotá', venue: 'Bunker', status: 'archived', cover_image: '/archive-2025.jpg', ticket_url: null, lineup: [], related_sets: [] },
          ],
        },
      },
    }),
  }));

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>
      <div class="events-track"><article class="event-card"><div class="event-info"><span class="event-status">ARCHIVE</span></div></article></div>
      <div class="event-archive" id="eventArchive">
        <div class="archive-summary"></div>
        <div class="archive-discovery"><label class="archive-search"><span>SEARCH HISTORY</span><input type="search" data-archive-search></label><div class="archive-relations"><button type="button" class="active" data-archive-relation="all">ALL RECORDS</button><button type="button" data-archive-relation="artists">WITH ARTISTS</button><button type="button" data-archive-relation="sets">WITH SETS</button><button type="button" data-archive-relation="memories">WITH MEMORIES</button></div></div>
        <div class="archive-years"></div>
        <div data-archive-results></div>
        <div data-archive-empty hidden><p>NO RECORDS MATCH THESE FILTERS.</p><button type="button" data-archive-reset>CLEAR FILTERS</button></div>
        <div class="archive-grid"></div>
      </div>
      <script>window.ScrollTrigger={refresh(){window.__archiveRefreshed=true;}};</script>
      <script>${archiveJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);

  await expect(page.locator('[data-public-event-id="10"]')).toBeVisible();
  await expect(page.locator('[data-public-event-id="10"] .event-status')).toHaveText('NEXT EXPERIENCE');
  await expect(page.locator('[data-public-event-id="11"] .event-status')).toHaveText('TICKETS AVAILABLE');
  await expect(page.locator('.events-track a[href="https://tickets.example/live"]')).toBeVisible();

  await expect(page.locator('[data-archive-id="20"]')).toBeVisible();
  await expect(page.locator('[data-archive-id="20"]')).toContainText('Session Five');
  await expect(page.locator('[data-archive-id="20"]')).toContainText('5 ARTISTS / 1 SET');
  await expect(page.locator('[data-archive-id="20"] a[href="/events/session-five"]')).toHaveText('OPEN RECORD ↗');
  const connections = page.locator('[data-archive-id="20"] [data-archive-connections]');
  await expect(connections).toHaveText('EXPLORE CONNECTIONS ↗');
  const connectionsHref = new URL(await connections.getAttribute('href'), page.url());
  expect(connectionsHref.searchParams.get('network_type')).toBe('events');
  expect(connectionsHref.searchParams.get('network_id')).toBe('20');
  expect(connectionsHref.hash).toBe('#network');
  await expect(page.locator('[data-archive-id="21"] [data-archive-connections]')).toHaveCount(0);
  await expect(page.locator('[data-archive-id="21"] .archive-event-relations')).toHaveText('HISTORICAL RECORD');
  await expect(page.locator('.archive-summary')).toHaveText('2 NIGHTS / 1 RELATED SETS / 9 VISUAL RECORDS');
  await expect(page.locator('[data-archive-results]')).toHaveText('2 RECORDS FOUND');
  await page.locator('[data-archive-search]').fill('final signal');
  await expect(page.locator('[data-archive-id="20"]')).toBeVisible();
  await expect(page.locator('[data-archive-id="21"]')).toBeHidden();
  await expect(page.locator('[data-archive-id="20"] .archive-event-copy p')).not.toContainText('FINAL SIGNAL');
  await page.locator('[data-archive-search]').fill('');

  await page.getByRole('button', { name: 'WITH SETS' }).click();
  await expect(page.locator('[data-archive-id="20"]')).toBeVisible();
  await expect(page.locator('[data-archive-id="21"]')).toBeHidden();
  await page.getByRole('button', { name: 'ALL RECORDS' }).click();

  await page.locator('[data-archive-search]').fill('bogota');
  await expect(page.locator('[data-archive-id="20"]')).toBeHidden();
  await expect(page.locator('[data-archive-id="21"]')).toBeVisible();
  await expect(page.locator('[data-archive-results]')).toHaveText('1 RECORD FOUND');
  await page.locator('[data-archive-search]').fill('Pereíra');
  await expect(page.locator('[data-archive-id="20"]')).toBeVisible();
  await expect(page.locator('[data-archive-id="21"]')).toBeHidden();
  await page.locator('[data-archive-search]').fill('nothing matches');
  await expect(page.locator('[data-archive-results]')).toHaveText('0 RECORDS FOUND');
  await expect(page.locator('[data-archive-empty]')).toBeVisible();
  await page.getByRole('button', { name: 'CLEAR FILTERS' }).click();
  await expect(page.locator('[data-archive-empty]')).toBeHidden();
  await expect(page.locator('[data-archive-results]')).toHaveText('2 RECORDS FOUND');
  await expect(page.locator('[data-archive-search]')).toHaveValue('');
  await expect(page.locator('[data-archive-search]')).toBeFocused();
  await page.locator('[data-archive-search]').fill('');

  await page.getByRole('button', { name: '2025' }).click();
  await expect(page.locator('[data-archive-id="20"]')).toBeHidden();
  await expect(page.locator('[data-archive-id="21"]')).toBeVisible();

  await page.getByRole('button', { name: 'ALL YEARS' }).click();
  await expect(page.locator('[data-archive-id="20"]')).toBeVisible();
  await expect(page.locator('[data-archive-id="21"]')).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.__archiveRefreshed === true)).toBe(true);
});

test('public archive treats explicit Event Memories as first-class archive connections', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await page.route('**/api/public*', route => route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({
      ok: true,
      data: {
        events: [],
        memories: [
          {
            id: 501,
            title: 'Floor Signal',
            relations: [
              {related_type:'event',related_id:77,route_type:'events',slug:'memory-only-night',label:'MEMORY ONLY NIGHT'},
            ],
          },
          {
            id: 502,
            title: 'Artist Only Memory',
            relations: [
              {related_type:'artist',related_id:9,route_type:'artists',slug:'pl0n3r',label:'PL0N3R'},
            ],
          },
        ],
        archive: {
          years: [2026],
          counts: {events:2,sets:0,media:2,releases:0},
          events: [
            {
              id:77,
              title:'Memory Only Night',
              slug:'memory-only-night',
              event_date:'2026-08-01 22:00:00',
              archive_year:2026,
              city:'Pereira',
              venue:'Warehouse',
              status:'finished',
              cover_image:'',
              ticket_url:null,
              lineup:[],
              related_sets:[],
            },
            {
              id:78,
              title:'Unrelated Night',
              slug:'unrelated-night',
              event_date:'2026-07-01 22:00:00',
              archive_year:2026,
              city:'Pereira',
              venue:'Bunker',
              status:'archived',
              cover_image:'',
              ticket_url:null,
              lineup:[],
              related_sets:[],
            },
          ],
        },
      },
    }),
  }));

  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html lang="en"><head><meta charset="utf-8"><style>${archiveCss}</style></head><body>
      <div class="events-track"></div>
      <div class="event-archive" id="eventArchive">
        <div class="archive-summary"></div>
        <div class="archive-discovery"><label class="archive-search"><span>SEARCH HISTORY</span><input type="search" data-archive-search></label><div class="archive-relations"><button type="button" class="active" data-archive-relation="all">ALL RECORDS</button><button type="button" data-archive-relation="artists">WITH ARTISTS</button><button type="button" data-archive-relation="sets">WITH SETS</button><button type="button" data-archive-relation="memories">WITH MEMORIES</button></div></div>
        <div class="archive-years"></div>
        <div data-archive-results></div>
        <div data-archive-empty hidden><p>NO RECORDS MATCH THESE FILTERS.</p><button type="button" data-archive-reset>CLEAR FILTERS</button></div>
        <div class="archive-grid"></div>
      </div>
      <script>${archiveJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);

  const memoryOnly = page.locator('[data-archive-id="77"]');
  const unrelated = page.locator('[data-archive-id="78"]');
  await expect(memoryOnly.locator('.archive-event-relations')).toHaveText('1 MEMORY');
  await expect(memoryOnly).toHaveAttribute('data-archive-memories','1');
  await expect(unrelated.locator('.archive-event-relations')).toHaveText('HISTORICAL RECORD');
  await expect(unrelated).toHaveAttribute('data-archive-memories','0');

  const connections = memoryOnly.locator('[data-archive-connections]');
  await expect(connections).toBeVisible();
  const connectionsHref = new URL(await connections.getAttribute('href'), page.url());
  expect(connectionsHref.searchParams.get('network_type')).toBe('events');
  expect(connectionsHref.searchParams.get('network_id')).toBe('77');
  expect(connectionsHref.hash).toBe('#network');

  const memoriesFilter = page.getByRole('button',{name:'WITH MEMORIES'});
  await memoriesFilter.click();
  await expect(memoryOnly).toBeVisible();
  await expect(unrelated).toBeHidden();
  const filterBox = await memoriesFilter.boundingBox();
  expect(filterBox.height).toBeGreaterThanOrEqual(44);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('public archive replaces a failed allowed cover image with the archive placeholder', async ({ page }) => {
  await page.route('**/api/public*', route => route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({
      ok: true,
      data: {
        events: [],
        archive: {
          years: [2026],
          counts: { events: 1, sets: 0, media: 0, releases: 0 },
          events: [{
            id: 44,
            title: 'Broken Cover',
            slug: 'broken-cover',
            event_date: '2026-08-01 22:00:00',
            archive_year: 2026,
            city: 'Pereira',
            venue: 'Warehouse',
            status: 'archived',
            cover_image: 'https://images.example.invalid/missing.jpg',
            ticket_url: null,
            lineup: [],
            related_sets: [],
          }],
        },
      },
    }),
  }));

  await page.route('https://images.example.invalid/missing.jpg', route => route.abort('failed'));

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>
      <div class="events-track"></div>
      <div class="event-archive" id="eventArchive">
        <div class="archive-summary"></div>
        <div class="archive-discovery"><label class="archive-search"><span>SEARCH HISTORY</span><input type="search" data-archive-search></label><div class="archive-relations"><button type="button" class="active" data-archive-relation="all">ALL RECORDS</button><button type="button" data-archive-relation="artists">WITH ARTISTS</button><button type="button" data-archive-relation="sets">WITH SETS</button><button type="button" data-archive-relation="memories">WITH MEMORIES</button></div></div>
        <div class="archive-years"></div>
        <div data-archive-results></div>
        <div data-archive-empty hidden><p>NO RECORDS MATCH THESE FILTERS.</p><button type="button" data-archive-reset>CLEAR FILTERS</button></div>
        <div class="archive-grid"></div>
      </div>
      <script>${archiveJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);

  const record = page.locator('[data-archive-id="44"]');
  await expect(record).toBeVisible();
  await expect(record.locator('.archive-event-image img')).toHaveCount(0);
  await expect(record.locator('.archive-event-placeholder')).toHaveText('BRVTAL / ARCHIVE');
});

test('public archive renders hostile API strings as text and rejects unsafe URL schemes', async ({ page }) => {
  const activeTitle = '<img id="archive-active-xss" src=x onerror="window.__archiveXss=1">';
  const activeDescription = '<script>window.__archiveXss=2</script>';
  const archivedTitle = '<svg id="archive-history-xss" onload="window.__archiveXss=3"></svg>';

  await page.route('**/api/public*', route => route.fulfill({
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify({
      ok: true,
      data: {
        events: [
          {
            id: 99,
            title: activeTitle,
            event_date: '2026-10-31 23:00:00',
            city: '<b>Pereira</b>',
            venue: '<em>Warehouse</em>',
            description: activeDescription,
            status: 'tickets_available',
            cover_image: 'javascript:window.__archiveImageXss=1',
            ticket_url: 'javascript:window.__archiveTicketXss=1',
          },
        ],
        archive: {
          years: [2026],
          counts: { events: 1, sets: 0, media: 0, releases: 0 },
          events: [
            {
              id: 199,
              title: archivedTitle,
              slug: 'hostile-record',
              event_date: '2026-08-01 22:00:00',
              archive_year: 2026,
              city: '<strong>Bogotá</strong>',
              venue: '<iframe src="javascript:window.__archiveXss=4"></iframe>',
              status: 'archived',
              cover_image: 'javascript:window.__archiveImageXss=2',
              ticket_url: null,
              lineup: [{ name: '<img id="archive-lineup-xss" src=x onerror="window.__archiveXss=5">' }],
              related_sets: [],
            },
          ],
        },
      },
    }),
  }));

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><head><meta charset="utf-8"></head><body>
      <div class="events-track"></div>
      <div class="event-archive" id="eventArchive">
        <div class="archive-summary"></div>
        <div class="archive-discovery"><label class="archive-search"><span>SEARCH HISTORY</span><input type="search" data-archive-search></label><div class="archive-relations"><button type="button" class="active" data-archive-relation="all">ALL RECORDS</button><button type="button" data-archive-relation="artists">WITH ARTISTS</button><button type="button" data-archive-relation="sets">WITH SETS</button><button type="button" data-archive-relation="memories">WITH MEMORIES</button></div></div>
        <div class="archive-years"></div>
        <div data-archive-results></div>
        <div data-archive-empty hidden><p>NO RECORDS MATCH THESE FILTERS.</p><button type="button" data-archive-reset>CLEAR FILTERS</button></div>
        <div class="archive-grid"></div>
      </div>
      <script>${archiveJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);

  await expect(page.locator('[data-public-event-id="99"] h3')).toHaveText(activeTitle);
  await expect(page.locator('[data-public-event-id="99"] p')).toContainText(activeDescription);
  await expect(page.locator('[data-public-event-id="99"] img')).toHaveCount(0);
  await expect(page.locator('[data-public-event-id="99"] .event-ticket')).toHaveCount(0);

  await expect(page.locator('[data-archive-id="199"] h3')).toHaveText(archivedTitle);
  await expect(page.locator('[data-archive-id="199"] .archive-event-copy p')).toContainText('<iframe src="javascript:window.__archiveXss=4"></iframe>');
  await expect(page.locator('[data-archive-id="199"] img')).toHaveCount(0);
  await expect(page.locator('[data-archive-id="199"] .archive-event-placeholder')).toHaveText('BRVTAL / ARCHIVE');

  await expect(page.locator('#archive-active-xss')).toHaveCount(0);
  await expect(page.locator('#archive-history-xss')).toHaveCount(0);
  await expect(page.locator('#archive-lineup-xss')).toHaveCount(0);
  await expect(page.locator('a[href^="javascript:"]')).toHaveCount(0);
  await expect(page.locator('script').filter({ hasText: 'window.__archiveXss=2' })).toHaveCount(0);

  expect(await page.evaluate(() => window.__archiveXss)).toBeUndefined();
  expect(await page.evaluate(() => window.__archiveImageXss)).toBeUndefined();
  expect(await page.evaluate(() => window.__archiveTicketXss)).toBeUndefined();
});
