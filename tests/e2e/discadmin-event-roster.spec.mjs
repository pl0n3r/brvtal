import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const contentCoreJs = readFileSync(join(process.cwd(), 'discadmin/content-core.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/event-roster-catalog-e2e.html';
const fields = [
  'e_title', 'e_slug', 'e_description', 'e_cover_image', 'e_accent', 'e_featured',
  'e_event_date', 'e_city', 'e_venue', 'e_archive_year', 'e_publish_at',
  'e_ticket_instructions', 'e_ticket_qr', 'e_ticket_url'
];

function harness() {
  return [
    '<!doctype html><html><head><meta charset="utf-8"></head><body>',
    '<input id="eventSearch"><div id="eventsTable"></div>',
    '<div id="cc-notice"></div><div id="eventNotice"></div>',
    '<div id="eventModal" class="modal"><h2 id="eventHeading"></h2>',
    fields.map(id => '<input id="' + id + '">').join(''),
    '<select id="e_status"><option value="draft">draft</option></select>',
    '<div id="e_publish_schedule_field"></div><div id="e_publish_at_help"></div>',
    '<div id="tickets"></div><div id="eventArtists"></div>',
    '<div class="step" data-step="1"></div><div class="step-content" data-content="1"></div>',
    '<button id="prevBtn"></button><button id="nextBtn"></button><button id="cc-saveBtn"></button>',
    '</div></body></html>'
  ].join('');
}

async function loadEditor(page) {
  await page.route(harnessUrl, route => route.fulfill({ contentType: 'text/html', body: harness() }));
  await page.goto(harnessUrl);
  await page.evaluate(() => {
    window.__rosterFixture = {
      event: { id: 9, title: 'MIXED LINEUP', slug: 'mixed-lineup', status: 'draft' },
      artists: [
        { id: 1, name: 'COLLECTIVE MEMBER', is_collective_member: 1, sort_order: 1 },
        { id: 2, name: 'FIRST-TIME GUEST', is_collective_member: 0, sort_order: 2, status: 'draft' },
        { id: 3, name: 'HISTORICAL GUEST', is_collective_member: 0, sort_order: 3, status: 'draft' }
      ],
      lineup: [{ artist_id: 3, lineup_order: 0, role: 'Guest Set' }]
    };
    window.BRVTALAdminAuthBoundary = { auth: async () => ({ authenticated: true, csrf: 'test-csrf' }) };
    window.BRVTALEventInsights = { mount() {}, async load() {}, reset() {} };
    window.BRVTALContentCoreLineup = {
      async load() { return structuredClone(window.__rosterFixture.lineup); },
      async save(_eventId, lineup) { window.__rosterFixture.lineup = structuredClone(lineup); return { ok: true }; }
    };
    window.fetch = async (url, options = {}) => {
      const path = new URL(url, location.href).pathname;
      const fixture = window.__rosterFixture;
      let payload;
      if (path === '/api/index.php/artists') payload = { ok: true, data: fixture.artists };
      else if (path === '/api/index.php/events' && (!options.method || options.method === 'GET')) {
        payload = { ok: true, data: [fixture.event] };
      } else if (path === '/api/index.php/events/9' && options.method === 'PUT') {
        fixture.event = { ...fixture.event, ...JSON.parse(options.body) };
        payload = { ok: true, id: 9 };
      } else if (path === '/api/index.php/ticket_types') payload = { ok: true, data: [] };
      else throw new Error('Unexpected private API request ' + (options.method || 'GET') + ' ' + path);
      return new Response(JSON.stringify(payload), { status: 200, headers: { 'Content-Type': 'application/json' } });
    };
  });
  await page.addScriptTag({ content: contentCoreJs });
  await page.evaluate(() => window.BRVTALContentCore.mount(document.body));
}

test('event roster lists all catalog artists and preserves mixed participation after save/reopen', async ({ page }) => {
  await loadEditor(page);
  await page.evaluate(() => window.BRVTALContentCore.openEvent());
  await expect(page.locator('#eventArtists [data-artist]')).toHaveCount(3);
  await expect(page.locator('#eventArtists [data-artist="2"]')).not.toBeChecked();
  await expect(page.locator('#eventArtists')).toContainText('BRVTAL / MEMBER');
  await expect(page.locator('#eventArtists')).toContainText('EXTERNAL / EVENT');

  await page.evaluate(async () => {
    await window.BRVTALContentCore.openEvent(9);
    await window.BRVTALContentCore.whenEventReady();
  });
  await expect(page.locator('#eventArtists [data-artist]')).toHaveCount(3);
  await expect(page.locator('#eventArtists [data-artist="3"]')).toBeChecked();
  await page.locator('#eventArtists [data-artist="1"]').check();
  await page.locator('#eventArtists [data-artist="2"]').check();
  const saved = await page.evaluate(() => window.BRVTALContentCore.saveEvent());
  expect(saved).toBe(true);
  expect(await page.evaluate(() => window.__rosterFixture.lineup)).toEqual([
    { artist_id: 3, lineup_order: 0, role: 'Guest Set' },
    { artist_id: 1, lineup_order: 1, role: '' },
    { artist_id: 2, lineup_order: 2, role: '' }
  ]);

  await page.evaluate(async () => {
    await window.BRVTALContentCore.openEvent(9);
    await window.BRVTALContentCore.whenEventReady();
  });
  for (const id of [1, 2, 3]) {
    await expect(page.locator('#eventArtists [data-artist="' + id + '"]')).toBeChecked();
  }
  expect(await page.evaluate(() => window.__rosterFixture.artists.map(a => a.is_collective_member))).toEqual([1, 0, 0]);
  expect(await page.evaluate(() => window.__rosterFixture.event.status)).toBe('draft');
});
