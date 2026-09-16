import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL_REAL_STACK_URL and admin credentials are required for the real-stack smoke');

test('generic Event API and Bulk Actions reject incomplete non-draft state without mutation', async ({ page }, testInfo) => {
  const login = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data: { email: adminEmail, password: adminPassword },
  });
  expect(login.ok()).toBeTruthy();
  const auth = await login.json();
  const headers = { 'X-CSRF-Token': auth.csrf };
  const runKey = `${Date.now().toString(36)}-${testInfo.workerIndex}`;
  const createdIds = [];

  const getEvent = async id => {
    const response = await page.request.get(`${baseUrl}/api/index.php/events/${id}`);
    expect(response.ok()).toBeTruthy();
    return (await response.json()).data;
  };

  const createDraft = async suffix => {
    const response = await page.request.post(`${baseUrl}/api/index.php/events`, {
      headers,
      data: {
        title: `CI EVENT INVARIANT ${suffix} ${runKey}`,
        slug: `ci-event-invariant-${suffix}-${runKey}`,
        status: 'draft',
      },
    });
    expect(response.status()).toBe(201);
    const id = Number((await response.json()).id || 0);
    expect(id).toBeGreaterThan(0);
    createdIds.push(id);
    return id;
  };

  try {
    const rejectedCreate = await page.request.post(`${baseUrl}/api/index.php/events`, {
      headers,
      data: {
        title: `CI INVALID PUBLISHED ${runKey}`,
        slug: `ci-invalid-published-${runKey}`,
        status: 'published',
      },
    });
    expect(rejectedCreate.status()).toBe(422);
    expect(await rejectedCreate.json()).toMatchObject({ error: 'EVENT_DATE_REQUIRED', field: 'event_date' });

    const eventId = await createDraft('crud');

    const publishWithoutDate = await page.request.put(`${baseUrl}/api/index.php/events/${eventId}`, {
      headers,
      data: { status: 'published' },
    });
    expect(publishWithoutDate.status()).toBe(422);
    expect(await publishWithoutDate.json()).toMatchObject({ error: 'EVENT_DATE_REQUIRED', field: 'event_date' });
    expect((await getEvent(eventId)).status).toBe('draft');

    const publishWithoutCity = await page.request.put(`${baseUrl}/api/index.php/events/${eventId}`, {
      headers,
      data: { event_date: '2026-10-31T21:00', status: 'published' },
    });
    expect(publishWithoutCity.status()).toBe(422);
    expect(await publishWithoutCity.json()).toMatchObject({ error: 'EVENT_CITY_REQUIRED', field: 'city' });
    const afterCityReject = await getEvent(eventId);
    expect(afterCityReject.status).toBe('draft');
    expect(afterCityReject.event_date).toBeNull();

    const validPublish = await page.request.put(`${baseUrl}/api/index.php/events/${eventId}`, {
      headers,
      data: { event_date: '2026-10-31T21:00', city: 'Pereira', status: 'published' },
    });
    expect(validPublish.ok()).toBeTruthy();
    const published = await getEvent(eventId);
    expect(published.status).toBe('published');
    expect(published.event_date).toBe('2026-10-31 21:00:00');
    expect(published.city).toBe('Pereira');

    const clearCity = await page.request.put(`${baseUrl}/api/index.php/events/${eventId}`, {
      headers,
      data: { city: '' },
    });
    expect(clearCity.status()).toBe(422);
    expect(await clearCity.json()).toMatchObject({ error: 'EVENT_CITY_REQUIRED', field: 'city' });
    const afterClearReject = await getEvent(eventId);
    expect(afterClearReject.status).toBe('published');
    expect(afterClearReject.city).toBe('Pereira');

    const bulkEventId = await createDraft('bulk');
    const bulkReject = await page.request.post(`${baseUrl}/api/bulk-actions.php`, {
      headers,
      data: {
        resource: 'events',
        action: 'set_status',
        status: 'published',
        ids: [bulkEventId],
      },
    });
    expect(bulkReject.status()).toBe(422);
    expect(await bulkReject.json()).toMatchObject({ error: 'EVENT_DATE_REQUIRED' });
    expect((await getEvent(bulkEventId)).status).toBe('draft');

    const completeBulkDraft = await page.request.put(`${baseUrl}/api/index.php/events/${bulkEventId}`, {
      headers,
      data: { event_date: '2026-11-01T22:00', city: 'Pereira' },
    });
    expect(completeBulkDraft.ok()).toBeTruthy();

    const bulkPublish = await page.request.post(`${baseUrl}/api/bulk-actions.php`, {
      headers,
      data: {
        resource: 'events',
        action: 'set_status',
        status: 'published',
        ids: [bulkEventId],
      },
    });
    expect(bulkPublish.ok()).toBeTruthy();
    expect((await getEvent(bulkEventId)).status).toBe('published');
  } finally {
    for (const id of createdIds.reverse()) {
      await page.request.delete(`${baseUrl}/api/index.php/events/${id}`, { headers }).catch(() => {});
    }
  }
});
