import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL_REAL_STACK_URL and admin credentials are required for the real-stack smoke');

test('Content Core rejects invalid identity and temporal values before MariaDB writes', async ({ page }, testInfo) => {
  const login = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data: {email: adminEmail, password: adminPassword},
  });
  expect(login.ok()).toBeTruthy();
  const auth = await login.json();
  const headers = {'X-CSRF-Token': auth.csrf};
  const runKey = `${Date.now().toString(36)}-${testInfo.workerIndex}`;
  const created = {
    ticket_types: [],
    sets: [],
    artists: [],
    events: [],
    pages: [],
  };

  const track = (resource, payload) => {
    const id = Number(payload?.id);
    if (id > 0) created[resource].push(id);
    return id;
  };

  const getOne = async (resource, id) => {
    const response = await page.request.get(`${baseUrl}/api/index.php/${resource}/${id}`);
    expect(response.ok(), `GET ${resource}/${id} failed with HTTP ${response.status()}`).toBeTruthy();
    return (await response.json()).data;
  };

  const cleanupResource = async (resource, id) => {
    try {
      const response = await page.request.delete(`${baseUrl}/api/index.php/${resource}/${id}`, {headers});
      if (!response.ok() && response.status() !== 404) {
        console.warn(`Cleanup failed for ${resource}/${id}: HTTP ${response.status()}`);
      }
    } catch (error) {
      console.warn(`Cleanup failed for ${resource}/${id}: ${error instanceof Error ? error.message : String(error)}`);
    }
  };

  try {
    const badEvent = await page.request.post(`${baseUrl}/api/index.php/events`, {
      headers,
      data: {title:`CI INVALID DATE ${runKey}`, slug:`ci-invalid-date-${runKey}`, status:'draft', event_date:'2026-02-31T21:00'},
    });
    expect(badEvent.status()).toBe(422);
    expect(await badEvent.json()).toMatchObject({error:'INVALID_DATE', field:'event_date'});

    const nonScalarIdentity = await page.request.post(`${baseUrl}/api/index.php/events`, {
      headers,
      data: {title:['CI ARRAY TITLE'], slug:`ci-array-title-${runKey}`, status:'draft'},
    });
    expect(nonScalarIdentity.status()).toBe(422);
    expect(await nonScalarIdentity.json()).toMatchObject({error:'INVALID_FIELD_TYPE', field:'title'});

    const nonScalarEvent = await page.request.post(`${baseUrl}/api/index.php/events`, {
      headers,
      data: {title:`CI NON SCALAR DATE ${runKey}`, slug:`ci-non-scalar-date-${runKey}`, status:'draft', event_date:['2026-10-31T21:00']},
    });
    expect(nonScalarEvent.status()).toBe(422);
    expect(await nonScalarEvent.json()).toMatchObject({error:'INVALID_DATE', field:'event_date'});

    const badArtist = await page.request.post(`${baseUrl}/api/index.php/artists`, {
      headers,
      data: {name:`CI INVALID ARTIST DATE ${runKey}`, slug:`ci-invalid-artist-date-${runKey}`, status:'draft', collective_joined_at:'16/09/2026'},
    });
    expect(badArtist.status()).toBe(422);
    expect(await badArtist.json()).toMatchObject({error:'INVALID_DATE', field:'collective_joined_at'});

    const missingPageIdentity = await page.request.post(`${baseUrl}/api/index.php/pages`, {
      headers,
      data: {title:'', slug:'', locale:'en', status:'draft', content_json:''},
    });
    expect(missingPageIdentity.status()).toBe(422);
    expect(await missingPageIdentity.json()).toMatchObject({error:'TITLE_REQUIRED', field:'title'});

    const pageTitle = `CI AUTO SLUG PAGE ${runKey}`;
    const expectedPageSlug = `ci-auto-slug-page-${runKey}`;
    const autoSlugPage = await page.request.post(`${baseUrl}/api/index.php/pages`, {
      headers,
      data: {title:pageTitle, locale:'en', status:'draft', content_json:''},
    });
    expect(autoSlugPage.status()).toBe(201);
    const autoSlugPayload = await autoSlugPage.json();
    const pageId = track('pages', autoSlugPayload);
    expect(pageId).toBeGreaterThan(0);

    const persistedPage = await getOne('pages', pageId);
    expect(persistedPage?.slug).toBe(expectedPageSlug);

    const clearPageTitle = await page.request.put(`${baseUrl}/api/index.php/pages/${pageId}`, {
      headers,
      data: {title:''},
    });
    expect(clearPageTitle.status()).toBe(422);
    expect(await clearPageTitle.json()).toMatchObject({error:'TITLE_REQUIRED', field:'title'});
    expect((await getOne('pages', pageId)).title).toBe(pageTitle);

    const eventTitle = `CI TICKET WINDOW EVENT ${runKey}`;
    const eventCreate = await page.request.post(`${baseUrl}/api/index.php/events`, {
      headers,
      data: {title:eventTitle, slug:`ci-ticket-window-event-${runKey}`, status:'draft', event_date:'2026-10-10T20:00'},
    });
    expect(eventCreate.status()).toBe(201);
    const eventPayload = await eventCreate.json();
    const eventId = track('events', eventPayload);
    expect(eventId).toBeGreaterThan(0);

    const clearEventTitle = await page.request.put(`${baseUrl}/api/index.php/events/${eventId}`, {
      headers,
      data: {title:''},
    });
    expect(clearEventTitle.status()).toBe(422);
    expect(await clearEventTitle.json()).toMatchObject({error:'TITLE_REQUIRED', field:'title'});
    expect((await getOne('events', eventId)).title).toBe(eventTitle);

    const artistName = `CI IDENTITY ARTIST ${runKey}`;
    const artistCreate = await page.request.post(`${baseUrl}/api/index.php/artists`, {
      headers,
      data: {name:artistName, slug:`ci-identity-artist-${runKey}`, status:'draft'},
    });
    expect(artistCreate.status()).toBe(201);
    const artistPayload = await artistCreate.json();
    const artistId = track('artists', artistPayload);
    expect(artistId).toBeGreaterThan(0);

    const clearArtistName = await page.request.put(`${baseUrl}/api/index.php/artists/${artistId}`, {
      headers,
      data: {name:''},
    });
    expect(clearArtistName.status()).toBe(422);
    expect(await clearArtistName.json()).toMatchObject({error:'NAME_REQUIRED', field:'name'});
    expect((await getOne('artists', artistId)).name).toBe(artistName);

    const setTitle = `CI IDENTITY SET ${runKey}`;
    const setCreate = await page.request.post(`${baseUrl}/api/index.php/sets`, {
      headers,
      data: {
        title:setTitle,
        slug:`ci-identity-set-${runKey}`,
        artist_id:artistId,
        event_id:eventId,
        platform:'other',
        status:'draft',
      },
    });
    expect(setCreate.status()).toBe(201);
    const setPayload = await setCreate.json();
    const setId = track('sets', setPayload);
    expect(setId).toBeGreaterThan(0);

    const clearSetTitle = await page.request.put(`${baseUrl}/api/index.php/sets/${setId}`, {
      headers,
      data: {title:''},
    });
    expect(clearSetTitle.status()).toBe(422);
    expect(await clearSetTitle.json()).toMatchObject({error:'TITLE_REQUIRED', field:'title'});
    expect((await getOne('sets', setId)).title).toBe(setTitle);

    const invertedWindow = await page.request.post(`${baseUrl}/api/index.php/ticket_types`, {
      headers,
      data: {
        event_id:eventId,
        name:`CI INVALID WINDOW ${runKey}`,
        status:'draft',
        available_from:'2026-10-10T20:00',
        available_until:'2026-10-09T20:00',
      },
    });
    expect(invertedWindow.status()).toBe(422);
    expect(await invertedWindow.json()).toMatchObject({error:'INVALID_AVAILABILITY_WINDOW', field:'available_until'});

    const invalidTicketDate = await page.request.post(`${baseUrl}/api/index.php/ticket_types`, {
      headers,
      data: {
        event_id:eventId,
        name:`CI INVALID TICKET DATE ${runKey}`,
        status:'draft',
        available_from:'not-a-date',
      },
    });
    expect(invalidTicketDate.status()).toBe(422);
    expect(await invalidTicketDate.json()).toMatchObject({error:'INVALID_DATE', field:'available_from'});

    const originalFrom = '2026-10-10 20:00:00';
    const originalUntil = '2026-10-10 23:00:00';
    const validTicket = await page.request.post(`${baseUrl}/api/index.php/ticket_types`, {
      headers,
      data: {
        event_id:eventId,
        name:`CI PARTIAL WINDOW ${runKey}`,
        status:'draft',
        available_from:'2026-10-10T20:00',
        available_until:'2026-10-10T23:00',
      },
    });
    expect(validTicket.status()).toBe(201);
    const ticketPayload = await validTicket.json();
    const ticketId = track('ticket_types', ticketPayload);
    expect(ticketId).toBeGreaterThan(0);

    const invalidPartialWindow = await page.request.put(`${baseUrl}/api/index.php/ticket_types/${ticketId}`, {
      headers,
      data: {available_from:'2026-10-11T00:00'},
    });
    expect(invalidPartialWindow.status()).toBe(422);
    expect(await invalidPartialWindow.json()).toMatchObject({error:'INVALID_AVAILABILITY_WINDOW', field:'available_until'});
    const persistedTicket = await getOne('ticket_types', ticketId);
    expect(persistedTicket.available_from).toBe(originalFrom);
    expect(persistedTicket.available_until).toBe(originalUntil);
  } finally {
    for (const resource of ['ticket_types', 'sets', 'artists', 'events', 'pages']) {
      for (const id of [...created[resource]].reverse()) {
        await cleanupResource(resource, id);
      }
    }
  }
});
