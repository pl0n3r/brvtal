import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || 'brvtal-ci-password';

// The normal browser suite runs against lightweight page harnesses. This smoke is
// opt-in because it requires the PHP application and MariaDB to be running.
test.skip(!baseUrl, 'BRVTAL_REAL_STACK_URL is required for the real-stack smoke');

test('Content Core persists create, edit, lifecycle, tickets, roster and SEO through the real PHP/MariaDB stack', async ({ page }) => {
  const login = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data: { email: adminEmail, password: adminPassword },
  });
  expect(login.ok(), `Admin login failed with HTTP ${login.status()}`).toBeTruthy();
  const loginPayload = await login.json();
  expect(loginPayload.ok).toBe(true);
  expect(loginPayload.csrf).toBeTruthy();

  await page.goto(`${baseUrl}/discadmin/?module=content-core`, { waitUntil: 'domcontentloaded' });
  const core = page.locator('[data-admin-module="content-core"]');
  await expect(core).toBeVisible({ timeout: 10_000 });
  await expect(core.locator('#eventsTable')).toBeVisible();

  const title = 'CI REAL STACK NIGHT';
  const slug = 'ci-real-stack-night';
  const step = number => page.locator(`#eventModal [data-step="${number}"]`);

  await core.getByRole('button', { name: '+ NEW EVENT' }).click();
  await expect(page.locator('#eventModal')).toHaveClass(/open/);
  await page.locator('#e_title').fill(title);
  await page.locator('#e_slug').fill(slug);
  await page.locator('#e_description').fill('Full-stack Content Core persistence smoke.');

  await expect(page.locator('#e_seo_title')).toBeVisible({ timeout: 5_000 });
  await page.locator('#e_seo_title').fill('CI Real Stack Night | BRVTAL');
  await page.locator('#e_seo_description').fill('Real MariaDB and PHP persistence smoke for the BRVTAL Content Core event editor.');

  await step(2).click();
  await page.locator('#e_event_date').fill('2026-10-31T21:00');
  await page.locator('#e_city').fill('Pereira');
  await page.locator('#e_venue').fill('CI Warehouse');

  await step(3).click();
  await page.locator('#e_status').selectOption('tickets_available');
  await page.locator('#e_ticket_instructions').fill('External checkout only.');
  await page.locator('#e_ticket_url').fill('https://tickets.example/ci-real-stack-night');

  await step(4).click();
  await page.getByRole('button', { name: '+ ADD TICKET' }).click();
  const ticket = page.locator('#tickets .ticket-row').first();
  await ticket.locator('[data-k="name"]').fill('PREVENTA');
  await ticket.locator('[data-k="price"]').fill('20000');
  await ticket.locator('[data-k="status"]').selectOption('active');
  await ticket.locator('[data-k="external_url"]').fill('https://tickets.example/ci-preventa');

  await step(5).click();
  const artistRow = page.locator('#eventArtists .artist').filter({ hasText: 'PL0N3R SMOKE' });
  await expect(artistRow).toBeVisible();
  await artistRow.locator('[data-artist]').check();

  const participationSaved = page.locator('#cc-notice');
  await page.locator('#cc-top-saveBtn').click();
  await expect(participationSaved).toContainText('Event participation saved.', { timeout: 10_000 });

  const eventListResponse = await page.request.get(`${baseUrl}/api/index.php/events`);
  expect(eventListResponse.ok()).toBeTruthy();
  const eventList = await eventListResponse.json();
  const created = eventList.data.find(row => row.slug === slug);
  expect(created).toBeTruthy();
  expect(created.status).toBe('tickets_available');
  expect(created.city).toBe('Pereira');
  expect(created.venue).toBe('CI Warehouse');
  expect(created.seo_title).toBe('CI Real Stack Night | BRVTAL');
  expect(created.seo_description).toContain('Real MariaDB and PHP persistence smoke');

  const ticketsResponse = await page.request.get(`${baseUrl}/api/index.php/ticket_types`);
  expect(ticketsResponse.ok()).toBeTruthy();
  const ticketsPayload = await ticketsResponse.json();
  const createdTicket = ticketsPayload.data.find(row => Number(row.event_id) === Number(created.id));
  expect(createdTicket).toBeTruthy();
  expect(createdTicket.name).toBe('PREVENTA');
  expect(Number(createdTicket.price)).toBe(20000);

  const lineupResponse = await page.request.get(`${baseUrl}/api/index.php/events/${created.id}/lineup`);
  expect(lineupResponse.ok()).toBeTruthy();
  const lineupPayload = await lineupResponse.json();
  expect(lineupPayload.data.map(row => row.name)).toContain('PL0N3R SMOKE');

  await page.locator('#eventModal').getByRole('button', { name: 'CLOSE' }).click();
  await expect(page.locator('#eventModal')).not.toHaveClass(/open/);

  const eventRow = page.locator('#eventsTable .tr').filter({ hasText: title });
  await expect(eventRow).toBeVisible();
  await eventRow.getByRole('button', { name: 'EDIT' }).click();
  await expect(page.locator('#tickets')).toHaveAttribute('data-load-state', 'ready', { timeout: 10_000 });
  await expect(page.locator('#e_seo_title')).toHaveValue('CI Real Stack Night | BRVTAL', { timeout: 5_000 });
  await expect(page.locator('#e_status')).toHaveValue('tickets_available');
  await expect(page.locator('#e_event_date')).toHaveValue('2026-10-31T21:00');
  await expect(page.locator('#tickets [data-k="name"]')).toHaveValue('PREVENTA');
  expect(Number(await page.locator('#tickets [data-k="price"]').inputValue())).toBe(20000);

  await step(5).click();
  const reopenedArtist = page.locator('#eventArtists .artist').filter({ hasText: 'PL0N3R SMOKE' });
  await expect(reopenedArtist.locator('[data-artist]')).toBeChecked({ timeout: 5_000 });

  // Exercise the update path after hydration from the database.
  await step(3).click();
  await page.locator('#e_status').selectOption('sold_out');
  await step(4).click();
  await page.locator('#tickets [data-k="price"]').fill('25000');
  await step(1).click();
  await page.locator('#e_seo_description').fill('Updated full-stack smoke: lifecycle is sold out and the persisted ticket price changed.');
  await page.locator('#cc-top-saveBtn').click();
  await expect(participationSaved).toContainText('Event participation saved.', { timeout: 10_000 });

  await page.locator('#eventModal').getByRole('button', { name: 'CLOSE' }).click();
  const updatedRow = page.locator('#eventsTable .tr').filter({ hasText: title });
  await updatedRow.getByRole('button', { name: 'EDIT' }).click();
  await expect(page.locator('#tickets')).toHaveAttribute('data-load-state', 'ready', { timeout: 10_000 });
  await expect(page.locator('#e_status')).toHaveValue('sold_out');
  expect(Number(await page.locator('#tickets [data-k="price"]').inputValue())).toBe(25000);
  await expect(page.locator('#e_seo_description')).toHaveValue('Updated full-stack smoke: lifecycle is sold out and the persisted ticket price changed.', { timeout: 5_000 });
  await step(5).click();
  await expect(page.locator('#eventArtists .artist').filter({ hasText: 'PL0N3R SMOKE' }).locator('[data-artist]')).toBeChecked({ timeout: 5_000 });

  const finalEventsResponse = await page.request.get(`${baseUrl}/api/index.php/events`);
  const finalEvents = await finalEventsResponse.json();
  const persisted = finalEvents.data.find(row => row.slug === slug);
  expect(persisted.status).toBe('sold_out');
  expect(persisted.seo_description).toContain('lifecycle is sold out');

  const finalTicketsResponse = await page.request.get(`${baseUrl}/api/index.php/ticket_types`);
  const finalTickets = await finalTicketsResponse.json();
  const persistedTicket = finalTickets.data.find(row => Number(row.event_id) === Number(persisted.id));
  expect(Number(persistedTicket.price)).toBe(25000);
});
