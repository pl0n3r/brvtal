import {test, expect, request as apiRequest} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';

const base = process.env.BRVTAL_REAL_STACK_URL || '';
const email = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const password = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';
const source = readFileSync('discadmin/index-core.php', 'utf8');
const script = source.match(/<script>\s*(const API=[\s\S]*?)<\/script>/)?.[1];
const css = source.match(/<style>([\s\S]*?)<\/style>/)?.[1];
const modalMarkup = source.match(/(<div class="modal" id="modal">[\s\S]*?<\/div>)\s*<script>/)?.[1];
if (!script || !css || !modalMarkup) throw new Error('Missing canonical DISCADMIN script/CSS/modal');

const fixtureUrl = 'http://127.0.0.1:4173/discadmin/integral-event-artist-set-harness.html';
async function responsiveHarness(page, width) {
  await page.setViewportSize({width, height: 900});
  await page.route(fixtureUrl, route => route.fulfill({contentType: 'text/html', body:
    '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>' +
    '<main id="app"></main>' + modalMarkup + '</body></html>'}));
  await page.goto(fixtureUrl);
  await page.addStyleTag({content: css});
  await page.addScriptTag({content: script});
  await page.evaluate(() => {
    csrf = 'synthetic-harness-csrf';
    state.authed = true;
    const artist = {id: 4, name: 'CI MEMBER', status: 'published', is_collective_member: 1};
    window.__integralHarness = {events: [{id: 17, title: 'CI DRAFT', status: 'draft'}],
      artists: [artist], lineup: [], requests: []};
    window.fetch = async (url, options = {}) => {
      const h = window.__integralHarness;
      const path = new URL(url, location.href).pathname;
      const method = options.method || 'GET';
      h.requests.push({path, method});
      let payload;
      if (path.endsWith('/events/17/lineup')) {
        if (method === 'POST') h.lineup = JSON.parse(options.body).lineup;
        payload = {ok: true, data: h.lineup};
      } else if (path.endsWith('/events')) payload = {ok: true, data: h.events};
      else if (path.endsWith('/artists')) {
        if (method === 'POST') {
          const data = JSON.parse(options.body);
          h.artists.push({id: 80, name: data.name, status: data.status,
            is_collective_member: data.is_collective_member});
          payload = {ok: true, id: 80};
        } else payload = {ok: true, data: h.artists};
      } else throw new Error('Unexpected synthetic harness route: ' + path);
      return new Response(JSON.stringify(payload), {status: method === 'POST' && path.endsWith('/artists') ? 201 : 200});
    };
  });
  await page.evaluate(() => openLineup(17));
}

test('AC-03: modal de lineup es usable con teclado y guardado visible a 390/1440', async ({page}) => {
  for (const width of [390, 1440]) {
    await responsiveHarness(page, width);
    const search = page.locator('#lineupArtistSearch');
    await expect(search).toBeVisible();
    await search.fill('CI EXTERNAL RESPONSIVE');
    await search.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#lineupCurrent .lineitem[data-id="80"]')).toBeVisible();
    await expect(search).toBeFocused();
    await expect(page.locator('.modalfoot .helper')).toBeVisible();
    await expect(page.getByRole('button', {name: 'CANCELAR'})).toBeVisible();
    await expect(page.locator('#saveBtn.btn.red')).toBeVisible();
    for (const selector of ['#saveBtn', '.footactions .btn.ghost:not([hidden])', '.modalfoot .helper']) {
      const dimensions = await page.locator(selector).evaluate(node => ({
        left: node.getBoundingClientRect().left,
        right: node.getBoundingClientRect().right,
        viewport: window.innerWidth
      }));
      expect(dimensions.left).toBeGreaterThanOrEqual(-1);
      expect(dimensions.right).toBeLessThanOrEqual(dimensions.viewport + 1);
    }
    await page.locator('#saveBtn').click();
    expect((await page.evaluate(() => window.__integralHarness.lineup)).map(row => row.artist_id)).toEqual([80]);
  }
});

function dbAudit(eventId, otherEventId, artistId, setId) {
  const env = process.env;
  const name = env.BRVTAL_TEST_DB_NAME || '';
  if (!/^brvtal_test[a-zA-Z0-9_]*$/.test(name)) throw new Error('Refusing non-test database');
  const client = env.BRVTAL_TEST_DB_CLI || execFileSync('sh',
    ['-c', 'command -v mariadb >/dev/null 2>&1 && printf mariadb || printf mysql'],
    {encoding: 'utf8', timeout: 3000}).trim();
  const query =
    'SELECT CONCAT(' +
    '(SELECT COUNT(*) FROM event_artists WHERE event_id=' + eventId + ' AND artist_id=' + artistId + '),' +
    '\':\',(SELECT COUNT(*) FROM event_artists WHERE event_id=' + otherEventId + '),' +
    '\':\',(SELECT COUNT(*) FROM set_artists WHERE set_id=' + setId + ' AND artist_id=' + artistId + '),' +
    '\':\',(SELECT is_collective_member FROM artists WHERE id=' + artistId + '),' +
    '\':\',(SELECT status FROM artists WHERE id=' + artistId + '),' +
    '\':\',(SELECT status FROM events WHERE id=' + eventId + '),' +
    '\':\',(SELECT status FROM sets_media WHERE id=' + setId + '))';
  return execFileSync(client, ['-N', '-B', '-h' + (env.BRVTAL_TEST_DB_HOST || '127.0.0.1'),
    '-P' + (env.BRVTAL_TEST_DB_PORT || '3306'), '-u' + (env.BRVTAL_TEST_DB_USER || 'root'),
    '-p' + (env.BRVTAL_TEST_DB_PASS || 'brvtal_root'), name, '-e', query],
  {encoding: 'utf8', timeout: 8000}).trim();
}

// Registered only when the disposable PHP/MariaDB stack exists. Never skip or mock the
// backend in its real-stack job; the runner explicitly lists this file.
if (base && password) {
  test('AC-01/02: Event → artista externo → lineup → Set persiste en MariaDB real', async ({page}, testInfo) => {
    test.setTimeout(120_000); // Two viewports, real authenticated PHP and MariaDB.
    const login = await page.request.post(base + '/api/index.php/auth', {
      data: {email, password}
    });
    expect(login.status()).toBe(200);
    const auth = await login.json();
    expect(auth.ok).toBe(true);
    const headers = {'X-CSRF-Token': auth.csrf};
    const noLogin = await apiRequest.newContext();
    try {
      for (const width of [390, 1440]) {
        await page.setViewportSize({width, height: 960});
        const key = String(Date.now()) + '-' + testInfo.workerIndex + '-' + width;
        const guestName = 'CI INTEGRAL GUEST ' + key;
        const setTitle = 'CI INTEGRAL SET ' + key;
        const events = [];
        const eventTitles = [];
        let artistId = 0, setId = 0;
        try {
          for (const suffix of ['primary', 'other']) {
            const eventTitle = 'CI INTEGRAL ' + suffix + ' ' + key;
            eventTitles.push(eventTitle);
            const create = await page.request.post(base + '/api/index.php/events', {
              headers, data: {title: eventTitle,
                slug: 'ci-integral-' + suffix + '-' + key, status: 'draft'}
            });
            expect(create.status()).toBe(201);
            events.push(Number((await create.json()).id));
          }
          const eventId = events[0];
          const forbidden = await noLogin.post(base + '/api/index.php/events/' + eventId + '/lineup',
            {data: {lineup: []}});
          expect([401, 403]).toContain(forbidden.status());
          const noCsrf = await page.request.post(base + '/api/index.php/events/' + eventId + '/lineup',
            {data: {lineup: []}});
          expect(noCsrf.status()).toBe(419);
          expect(await noCsrf.json()).toMatchObject({error: 'CSRF'});

          await page.goto(base + '/discadmin/', {waitUntil: 'domcontentloaded'});
          await page.waitForFunction(() => Boolean(window.state?.authed), null, {timeout: 10000});
          await page.evaluate(async id => { await openLineup(id); }, eventId);
          await expect(page.locator('#lineupArtistSearch')).toBeVisible();
          const memberId = await page.evaluate(() => Number(
            state.artists.find(artist => artist.name === 'PL0N3R SMOKE')?.id
          ));
          expect(memberId).toBeGreaterThan(0);
          const addMember = page.locator('#available .artistpick')
            .filter({hasText: 'PL0N3R SMOKE'}).getByRole('button', {name: 'ADD'});
          await addMember.click();
          const postArtist = page.waitForResponse(response =>
            response.url().endsWith('/api/index.php/artists') &&
            response.request().method() === 'POST');
          await page.locator('#lineupArtistSearch').fill(guestName);
          await page.locator('#lineupArtistSearch').press('Enter');
          const artistResponse = await postArtist;
          expect(artistResponse.status()).toBe(201);
          artistId = Number((await artistResponse.json()).id);
          expect(artistId).toBeGreaterThan(0);
          const newRow = page.locator('#lineupCurrent .lineitem[data-id="' + artistId + '"]');
          await expect(newRow).toBeVisible();
          await newRow.locator('input.role').fill('External guest');
          await expect(newRow.locator('.lineup-draft-warning')).toBeVisible();

          // Inject only a transport failure; the positive CRUD path above is real MariaDB.
          await page.route('**/api/index.php/artists', route =>
            route.request().method() === 'POST' ? route.abort('failed') : route.continue());
          await page.locator('#lineupArtistSearch').fill('CI FAILED ' + key);
          await page.locator('#lineupArtistSearch').press('Enter');
          await expect(page.locator('#lineupArtistError')).toContainText('No se pudo crear');
          await expect(newRow.locator('input.role')).toHaveValue('External guest');
          await expect(page.locator('#lineupCurrent .lineitem')).toHaveCount(2);
          await page.unroute('**/api/index.php/artists');

          const save = page.waitForResponse(response =>
            response.url().endsWith('/events/' + eventId + '/lineup') &&
            response.request().method() === 'POST');
          // Network-error toast may overlay the footer, but keyboard activation remains valid.
          await page.locator('#saveBtn').press('Enter');
          expect((await save).ok()).toBe(true);
          const lineup = (await (await page.request.get(base +
            '/api/index.php/events/' + eventId + '/lineup')).json()).data;
          expect(lineup).toHaveLength(2);
          expect(lineup.map(item => Number(item.artist_id))).toEqual([memberId, artistId]);
          expect(lineup[1].role).toBe('External guest');
          const otherLineup = (await (await page.request.get(base +
            '/api/index.php/events/' + events[1] + '/lineup')).json()).data;
          expect(otherLineup).toEqual([]);

          await page.reload({waitUntil: 'domcontentloaded'});
          await page.waitForFunction(() => Boolean(window.state?.authed), null, {timeout: 10000});
          await page.evaluate(async id => { await openLineup(id); }, eventId);
          expect(await page.locator('#lineupCurrent .lineitem').evaluateAll(
            rows => rows.map(row => Number(row.dataset.id))
          )).toEqual([memberId, artistId]);
          await expect(page.locator('#lineupCurrent .lineitem[data-id="' + artistId + '"] input.role'))
            .toHaveValue('External guest');

          const artists = (await (await page.request.get(base + '/api/index.php/artists')).json()).data;
          const guest = artists.find(row => Number(row.id) === artistId);
          expect(guest).toMatchObject({name: guestName, status: 'draft'});
          expect(Number(guest.is_collective_member)).toBe(0);
          // Different display name, same normalized slug: cover the actual editor error handler.
          const collisionName = guestName.replace('CI INTEGRAL', 'CI  INTEGRAL');
          const duplicateRequest = page.waitForResponse(response =>
            response.url().endsWith('/api/index.php/artists') &&
            response.request().method() === 'POST');
          await page.locator('#lineupArtistSearch').fill(collisionName);
          await page.locator('#lineupArtistSearch').press('Enter');
          const duplicate = await duplicateRequest;
          expect(duplicate.status()).toBe(409);
          expect(await duplicate.json()).toMatchObject({error: 'DUPLICATE_SLUG'});
          await expect(page.locator('#lineupArtistError')).toContainText('Ya existe un artista');
          expect(await page.locator('#lineupCurrent .lineitem').evaluateAll(
            rows => rows.map(row => Number(row.dataset.id))
          )).toEqual([memberId, artistId]);
          await expect(page.locator('#lineupCurrent .lineitem[data-id="' + artistId + '"] input.role'))
            .toHaveValue('External guest');

          // Persist Set through the real admin API, then reopen its actual editor.
          const createSet = await page.request.post(base + '/api/index.php/sets',
            {headers, data: {title: setTitle, slug: 'ci-integral-set-' + key,
              platform: 'soundcloud', status: 'draft',
              external_url: 'https://example.com/ci-integral-placeholder', event_id: eventId,
              artist_id: artistId, artist_ids: [artistId]}});
          const createdSet = await createSet.json();
          expect(createSet.status(), JSON.stringify(createdSet)).toBe(201);
          setId = Number(createdSet.id);
          expect(setId).toBeGreaterThan(0);
          const setList = (await (await page.request.get(base + '/api/index.php/sets')).json()).data;
          const persistedSet = setList.find(item => Number(item.id) === setId);
          expect(persistedSet).toBeTruthy();
          expect(Number(persistedSet.event_id)).toBe(eventId);
          expect(persistedSet.artist_ids.map(Number)).toContain(artistId);
          expect(dbAudit(eventId, events[1], artistId, setId))
            .toBe('1:0:1:0:draft:draft:draft');

          // The native grid may be paginated or have concurrent navigation updates.
          // Hydrate its editor cache from the authenticated, unpaginated real API.
          await page.evaluate(async id => {
            await go('sets');
            await loadRefs();
            const records = (await req('/sets')).data || [];
            const persisted = records.find(row => Number(row.id) === id);
            if (!persisted) throw new Error('Set missing from authenticated PHP API');
            state.rows = [persisted];
          }, setId);
          expect(await page.evaluate(id => state.rows.some(row => Number(row.id) === id), setId))
            .toBe(true);
          expect(await page.evaluate(id => state.events.some(row => Number(row.id) === id), eventId))
            .toBe(true);
          await page.evaluate(id => openModal('sets', id), setId);
          await expect(page.locator('#f_event_id')).toHaveValue(String(eventId));
          await expect(page.locator('#setArtistChoices input[value="' + artistId + '"]'))
            .toBeChecked();
          await expect(page.locator('#saveBtn')).toBeVisible();

          const publicReply = await page.request.get(base + '/api/public.php');
          expect(publicReply.ok()).toBe(true);
          const publicBody = await publicReply.text();
          expect(publicBody).not.toContain(guestName);
          expect(publicBody).not.toContain(setTitle);
          for (const eventTitle of eventTitles) expect(publicBody).not.toContain(eventTitle);
        } finally {
          if (setId) await page.request.delete(base + '/api/index.php/sets/' + setId, {headers}).catch(() => {});
          for (const eventId of events.reverse()) {
            await page.request.delete(base + '/api/index.php/events/' + eventId, {headers}).catch(() => {});
          }
          if (artistId) await page.request.delete(base + '/api/index.php/artists/' + artistId, {headers}).catch(() => {});
        }
      }
    } finally {
      await noLogin.dispose();
    }
  });
}
