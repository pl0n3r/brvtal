import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL_REAL_STACK_URL and admin credentials are required for the real-stack smoke');

test('Content Core rejects invalid identity and temporal values before MariaDB writes', async ({ page }) => {
  const login = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data: {email: adminEmail, password: adminPassword},
  });
  expect(login.ok()).toBeTruthy();
  const auth = await login.json();
  const headers = {'X-CSRF-Token': auth.csrf};

  const badEvent = await page.request.post(`${baseUrl}/api/index.php/events`, {
    headers,
    data: {title:'CI INVALID DATE', slug:'ci-invalid-date', status:'draft', event_date:'2026-02-31T21:00'},
  });
  expect(badEvent.status()).toBe(422);
  expect(await badEvent.json()).toMatchObject({error:'INVALID_DATE', field:'event_date'});

  const badArtist = await page.request.post(`${baseUrl}/api/index.php/artists`, {
    headers,
    data: {name:'CI INVALID ARTIST DATE', slug:'ci-invalid-artist-date', status:'draft', collective_joined_at:'16/09/2026'},
  });
  expect(badArtist.status()).toBe(422);
  expect(await badArtist.json()).toMatchObject({error:'INVALID_DATE', field:'collective_joined_at'});

  const missingPageIdentity = await page.request.post(`${baseUrl}/api/index.php/pages`, {
    headers,
    data: {title:'', slug:'', locale:'en', status:'draft', content_json:''},
  });
  expect(missingPageIdentity.status()).toBe(422);
  expect(await missingPageIdentity.json()).toMatchObject({error:'TITLE_REQUIRED', field:'title'});

  const pageTitle = 'CI AUTO SLUG PAGE';
  const autoSlugPage = await page.request.post(`${baseUrl}/api/index.php/pages`, {
    headers,
    data: {title:pageTitle, locale:'en', status:'draft', content_json:''},
  });
  expect(autoSlugPage.status()).toBe(201);
  const autoSlugPayload = await autoSlugPage.json();
  expect(Number(autoSlugPayload.id)).toBeGreaterThan(0);

  const pages = await page.request.get(`${baseUrl}/api/index.php/pages`);
  const pageRows = (await pages.json()).data;
  const persistedPage = pageRows.find(row => Number(row.id) === Number(autoSlugPayload.id));
  expect(persistedPage?.slug).toBe('ci-auto-slug-page');

  const clearPageTitle = await page.request.put(`${baseUrl}/api/index.php/pages/${autoSlugPayload.id}`, {
    headers,
    data: {title:''},
  });
  expect(clearPageTitle.status()).toBe(422);
  expect(await clearPageTitle.json()).toMatchObject({error:'TITLE_REQUIRED', field:'title'});

  const eventCreate = await page.request.post(`${baseUrl}/api/index.php/events`, {
    headers,
    data: {title:'CI TICKET WINDOW EVENT', slug:'ci-ticket-window-event', status:'draft', event_date:'2026-10-10T20:00'},
  });
  expect(eventCreate.status()).toBe(201);
  const eventPayload = await eventCreate.json();

  const invertedWindow = await page.request.post(`${baseUrl}/api/index.php/ticket_types`, {
    headers,
    data: {
      event_id:eventPayload.id,
      name:'CI INVALID WINDOW',
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
      event_id:eventPayload.id,
      name:'CI INVALID TICKET DATE',
      status:'draft',
      available_from:'not-a-date',
    },
  });
  expect(invalidTicketDate.status()).toBe(422);
  expect(await invalidTicketDate.json()).toMatchObject({error:'INVALID_DATE', field:'available_from'});
});
