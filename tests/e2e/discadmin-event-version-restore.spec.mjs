import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL real-stack admin credentials are required');

async function login(page) {
  const response = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data:{email:adminEmail,password:adminPassword},
  });
  expect(response.ok(), `Admin login failed with HTTP ${response.status()}`).toBeTruthy();
  const payload = await response.json();
  expect(payload.ok).toBe(true);
  expect(payload.csrf).toBeTruthy();
  return payload.csrf;
}

async function fetchEvent(page,eventId) {
  const response = await page.request.get(`${baseUrl}/api/index.php/events`);
  expect(response.ok()).toBeTruthy();
  const payload = await response.json();
  return payload.data.find(row => Number(row.id) === Number(eventId));
}

async function fetchTickets(page,eventId) {
  const response = await page.request.get(`${baseUrl}/api/index.php/ticket_types`);
  expect(response.ok()).toBeTruthy();
  const payload = await response.json();
  return payload.data.filter(row => Number(row.event_id) === Number(eventId));
}

async function fetchLineup(page,eventId) {
  const response = await page.request.get(`${baseUrl}/api/index.php/events/${eventId}/lineup`);
  expect(response.ok()).toBeTruthy();
  return (await response.json()).data;
}

async function fetchTimetable(page,eventId) {
  const response = await page.request.get(`${baseUrl}/api/event-workflow.php?id=${eventId}`);
  expect(response.ok()).toBeTruthy();
  return (await response.json()).data.timetable;
}

async function fetchHistory(page,eventId) {
  const response = await page.request.get(
    `${baseUrl}/api/admin-activity.php?history=1&resource=events&resource_id=${eventId}&limit=50`
  );
  expect(response.ok()).toBeTruthy();
  return (await response.json()).data;
}

async function workflow(page,csrf,data) {
  const response = await page.request.post(`${baseUrl}/api/event-workflow.php`, {
    headers:{'X-CSRF-Token':csrf},
    data,
  });
  expect(response.status()).toBe(200);
  const payload = await response.json();
  expect(payload.ok).toBe(true);
  return payload.data;
}

function eventPayload(id,slug,title,description) {
  return {
    ...(id ? {id} : {}),
    title,
    slug,
    description,
    cover_image:'',
    accent:'',
    featured:0,
    event_date:'2026-10-31 21:00',
    city:'Pereira',
    venue:'CI Restore Warehouse',
    archive_year:2026,
    status:'sold_out',
    ticket_instructions:'Current ticket instructions',
    ticket_qr:'',
    ticket_url:'',
  };
}

function ticketPayload(id = 0) {
  return {
    ...(id ? {id} : {}),
    name:'VIP RESTORE',
    description:'Current ticket relation',
    price:'25000',
    currency:'COP',
    status:'active',
    external_url:null,
    payment_instructions:'Current payment instructions',
    qr_image:'',
    available_from:null,
    available_until:null,
    sort_order:0,
  };
}

function timetablePayload(artistId,id = 0) {
  return {
    ...(id ? {id} : {}),
    artist_id:artistId,
    label:null,
    starts_at:'2026-10-31 22:00',
    ends_at:'2026-10-31 23:00',
    timezone:'America/Bogota',
    status:'approved',
    sort_order:0,
  };
}

async function setupVersionedEvent(page,testInfo) {
  const csrf = await login(page);
  const artistsResponse = await page.request.get(`${baseUrl}/api/index.php/artists`);
  expect(artistsResponse.ok()).toBeTruthy();
  const artists = (await artistsResponse.json()).data;
  const artist = artists.find(row => row.name === 'PL0N3R SMOKE');
  expect(artist).toBeTruthy();
  const artistId = Number(artist.id);

  const runKey = `${Date.now().toString(36)}-${testInfo.workerIndex}-${Math.random().toString(36).slice(2,8)}`;
  const slug = `ci-event-restore-${runKey}`;

  let data = await workflow(page,csrf,{
    event:eventPayload(0,slug,`ORIGINAL ${runKey}`,'Original copy'),
    ticket_types:[ticketPayload()],
    lineup:[{artist_id:artistId,lineup_order:0,role:'HEADLINER'}],
  });
  const eventId = Number(data.event.id);
  const ticketId = Number(data.ticket_types[0].id);
  expect(eventId).toBeGreaterThan(0);
  expect(ticketId).toBeGreaterThan(0);

  data = await workflow(page,csrf,{
    event:eventPayload(eventId,slug,`HISTORICAL ${runKey}`,'Historical copy'),
    ticket_types:[ticketPayload(ticketId)],
    lineup:[{artist_id:artistId,lineup_order:0,role:'HEADLINER'}],
    timetable:[timetablePayload(artistId)],
  });
  const timetableId = Number(data.timetable[0].id);
  expect(timetableId).toBeGreaterThan(0);

  data = await workflow(page,csrf,{
    event:eventPayload(eventId,slug,`CURRENT ${runKey}`,'Current copy'),
    ticket_types:[ticketPayload(ticketId)],
    lineup:[{artist_id:artistId,lineup_order:0,role:'HEADLINER'}],
    timetable:[timetablePayload(artistId,timetableId)],
  });

  const history = await fetchHistory(page,eventId);
  expect(history.items.length).toBeGreaterThanOrEqual(3);
  const updates = history.items.filter(item => item.action === 'update');
  expect(updates.length).toBeGreaterThanOrEqual(2);
  expect(updates[1].after.title).toBe(`HISTORICAL ${runKey}`);

  return {
    csrf,
    eventId,
    artistId,
    ticketId,
    timetableId,
    slug,
    runKey,
    currentTitle:`CURRENT ${runKey}`,
    historicalTitle:`HISTORICAL ${runKey}`,
    current:data.event,
    history,
    historicalItem:updates[1],
  };
}

async function cleanupEvent(page,fixture) {
  if (!fixture?.eventId || !fixture?.csrf) return;
  await page.request.delete(`${baseUrl}/api/index.php/events/${fixture.eventId}`, {
    headers:{'X-CSRF-Token':fixture.csrf},
  }).catch(() => {});
}

async function openEventEditor(page,eventId) {
  await page.goto(`${baseUrl}/discadmin/?module=events`, {waitUntil:'domcontentloaded'});
  const core = page.locator('[data-admin-module="content-core"]');
  await expect(core).toBeAttached({timeout:10_000});
  await expect.poll(() => page.evaluate(() => typeof window.BRVTALContentCore?.openEvent)).toBe('function');
  await page.evaluate(async id => {
    await window.BRVTALContentCore.openEvent(id);
    await window.BRVTALContentCore.whenEventReady();
  }, eventId);
  await expect(page.locator('#eventModal')).toHaveClass(/open/);
  await expect(page.locator('#tickets')).toHaveAttribute('data-load-state','ready');
  await expect(page.locator('#eventArtists')).toHaveAttribute('data-load-state','ready');
  await expect(page.locator('#eventTimetable')).toHaveAttribute('data-load-state','ready');
}

async function ensureRestoreModule(page) {
  const loaded = await page.evaluate(async () => {
    const opened = await window.BRVTALContentCore.openEventHistory();
    const ready = typeof window.BRVTALEventVersionRestore?.buildPlan === 'function';
    window.BRVTALAdminActivity?.closeDetail?.();
    return {opened,ready};
  });
  expect(loaded).toEqual({opened:true,ready:true});
}

async function stageHistoricalVersion(page,item,eventId) {
  await ensureRestoreModule(page);
  page.once('dialog', prompt => prompt.accept());
  const staged = await page.evaluate(
    async ({historyItem,id}) => window.BRVTALContentCore.stageEventVersionRestore(
      historyItem,
      id,
      window.BRVTALEventVersionRestore
    ),
    {historyItem:item,id:eventId}
  );
  expect(staged).toBe(true);
}

test('staged restore saves through the canonical Event workflow and creates new history', async ({ page }, testInfo) => {
  let fixture;
  try {
    fixture = await setupVersionedEvent(page,testInfo);
    const beforeEvent = await fetchEvent(page,fixture.eventId);
    const beforeHistory = await fetchHistory(page,fixture.eventId);
    const beforeTickets = await fetchTickets(page,fixture.eventId);
    const beforeLineup = await fetchLineup(page,fixture.eventId);
    const beforeTimetable = await fetchTimetable(page,fixture.eventId);

    await openEventEditor(page,fixture.eventId);
    await stageHistoricalVersion(page,fixture.historicalItem,fixture.eventId);

    await expect(page.locator('#e_title')).toHaveValue(fixture.historicalTitle);
    await expect(page.locator('#e_description')).toHaveValue('Historical copy');
    await expect(page.locator('#e_status')).toHaveValue('sold_out');
    await expect(page.locator('#tickets [data-k="name"]').first()).toHaveValue('VIP RESTORE');
    await expect(page.locator('#eventArtists [data-artist]:checked')).toHaveCount(1);
    await expect(page.locator('#eventTimetable .timetable-row')).toHaveCount(1);
    expect(await page.evaluate(() =>
      window.BRVTALUnsavedChanges.isDirty(document.getElementById('eventModal'))
    )).toBe(true);

    const stagedEvent = await fetchEvent(page,fixture.eventId);
    const stagedHistory = await fetchHistory(page,fixture.eventId);
    expect(stagedEvent.title).toBe(fixture.currentTitle);
    expect(stagedEvent.description).toBe('Current copy');
    expect(stagedHistory.total).toBe(beforeHistory.total);

    const requestPromise = page.waitForRequest(request =>
      request.method() === 'POST'
      && new URL(request.url()).pathname === '/api/event-workflow.php'
    );
    const responsePromise = page.waitForResponse(response =>
      response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/api/event-workflow.php'
    );
    await page.locator('#cc-top-saveBtn').click();
    const request = await requestPromise;
    const response = await responsePromise;
    expect(response.status()).toBe(200);
    const body = request.postDataJSON();

    expect(request.headers()['x-csrf-token']).toBeTruthy();
    expect(body.event).toMatchObject({
      id:fixture.eventId,
      title:fixture.historicalTitle,
      description:'Historical copy',
      status:'sold_out',
    });
    expect(body.event).not.toHaveProperty('published_at');
    expect(body.ticket_types).toHaveLength(1);
    expect(Number(body.ticket_types[0].id)).toBe(fixture.ticketId);
    expect(body.ticket_types[0].name).toBe('VIP RESTORE');
    expect(body.lineup).toEqual([{artist_id:fixture.artistId,lineup_order:0,role:'HEADLINER'}]);
    expect(body.timetable).toHaveLength(1);
    expect(Number(body.timetable[0].id)).toBe(fixture.timetableId);

    await expect(page.locator('#eventNotice')).toContainText('saved together', {timeout:10_000});
    await page.evaluate(() => window.BRVTALContentCore.whenEventReady());

    const afterEvent = await fetchEvent(page,fixture.eventId);
    const afterHistory = await fetchHistory(page,fixture.eventId);
    const afterTickets = await fetchTickets(page,fixture.eventId);
    const afterLineup = await fetchLineup(page,fixture.eventId);
    const afterTimetable = await fetchTimetable(page,fixture.eventId);

    expect(afterEvent).toMatchObject({
      title:fixture.historicalTitle,
      description:'Historical copy',
      status:'sold_out',
      published_at:beforeEvent.published_at,
    });
    expect(afterHistory.total).toBe(beforeHistory.total + 1);
    expect(afterHistory.items[0]).toMatchObject({
      action:'update',
      resource:'events',
      resource_id:fixture.eventId,
    });
    expect(afterHistory.items[0].before.title).toBe(fixture.currentTitle);
    expect(afterHistory.items[0].after.title).toBe(fixture.historicalTitle);

    expect(Number(afterTickets[0].id)).toBe(Number(beforeTickets[0].id));
    expect(afterTickets[0].name).toBe(beforeTickets[0].name);
    expect(afterLineup.map(row => [Number(row.artist_id),row.role])).toEqual(
      beforeLineup.map(row => [Number(row.artist_id),row.role])
    );
    expect(afterTimetable.map(row => [Number(row.id),Number(row.artist_id),row.status])).toEqual(
      beforeTimetable.map(row => [Number(row.id),Number(row.artist_id),row.status])
    );
  } finally {
    await cleanupEvent(page,fixture);
  }
});

test('new, wrong, stale, closed, incompatible and empty restores fail closed without mutation', async ({ page }, testInfo) => {
  let fixture;
  try {
    fixture = await setupVersionedEvent(page,testInfo);
    await openEventEditor(page,fixture.eventId);
    const historyBefore = await fetchHistory(page,fixture.eventId);
    const older = historyBefore.items.filter(item => item.action === 'update')[1];
    expect(older).toBeTruthy();

    await ensureRestoreModule(page);

    const results = await page.evaluate(async ({older,eventId,currentTitle}) => {
      const core = window.BRVTALContentCore;
      const restore = window.BRVTALEventVersionRestore;
      const wrong = structuredClone(older);
      wrong.resource_id = eventId + 1;
      wrong.after = {...wrong.after,id:eventId + 1};

      const incompatible = structuredClone(older);
      incompatible.id = Number(incompatible.id) + 1000;
      incompatible.after = {...incompatible.after,metadata:{nested:true}};

      const empty = {
        ...structuredClone(older),
        id:Number(older.id) + 2000,
        after:{id:eventId,title:currentTitle,description:'Current copy'},
      };

      const wrongResult = await core.stageEventVersionRestore(wrong,eventId,restore);
      const staleResult = await core.stageEventVersionRestore(older,eventId + 1,restore);
      const incompatibleResult = await core.stageEventVersionRestore(incompatible,eventId,restore);
      const emptyResult = await core.stageEventVersionRestore(empty,eventId,restore);

      const originalConfirm = window.confirm;
      window.confirm = () => {
        document.getElementById('eventModal').classList.remove('open');
        return true;
      };
      const closedResult = await core.stageEventVersionRestore(older,eventId,restore);
      window.confirm = originalConfirm;

      await core.openEvent();
      await core.whenEventReady();
      const newEventResult = await core.openEventHistory();

      return {
        wrong:wrongResult,
        stale:staleResult,
        incompatible:incompatibleResult,
        empty:emptyResult,
        closed:closedResult,
        newEvent:newEventResult,
      };
    }, {
      older,
      eventId:fixture.eventId,
      currentTitle:fixture.currentTitle,
    });

    expect(results).toEqual({
      wrong:false,
      stale:false,
      incompatible:false,
      empty:false,
      closed:false,
      newEvent:false,
    });

    const serverEvent = await fetchEvent(page,fixture.eventId);
    const historyAfter = await fetchHistory(page,fixture.eventId);
    expect(serverEvent).toMatchObject({
      title:fixture.currentTitle,
      description:'Current copy',
      status:'sold_out',
    });
    expect(historyAfter.total).toBe(historyBefore.total);
  } finally {
    await cleanupEvent(page,fixture);
  }
});

test('cancel discards a staged restore while lifecycle and relations remain current', async ({ page }, testInfo) => {
  let fixture;
  try {
    fixture = await setupVersionedEvent(page,testInfo);
    const beforeHistory = await fetchHistory(page,fixture.eventId);
    const beforeTickets = await fetchTickets(page,fixture.eventId);
    const beforeLineup = await fetchLineup(page,fixture.eventId);
    const beforeTimetable = await fetchTimetable(page,fixture.eventId);

    await openEventEditor(page,fixture.eventId);
    await stageHistoricalVersion(page,fixture.historicalItem,fixture.eventId);
    await expect(page.locator('#e_title')).toHaveValue(fixture.historicalTitle);
    await expect(page.locator('#e_status')).toHaveValue('sold_out');

    page.once('dialog', prompt => prompt.accept());
    await page.locator('#eventModal').getByRole('button', {name:'CANCEL',exact:true}).click();
    await expect(page.locator('#eventModal')).not.toHaveClass(/open/);

    const serverEvent = await fetchEvent(page,fixture.eventId);
    const afterHistory = await fetchHistory(page,fixture.eventId);
    expect(serverEvent).toMatchObject({
      title:fixture.currentTitle,
      description:'Current copy',
      status:'sold_out',
    });
    expect(afterHistory.total).toBe(beforeHistory.total);

    await page.evaluate(async id => {
      await window.BRVTALContentCore.openEvent(id);
      await window.BRVTALContentCore.whenEventReady();
    }, fixture.eventId);
    await expect(page.locator('#e_title')).toHaveValue(fixture.currentTitle);
    await expect(page.locator('#e_description')).toHaveValue('Current copy');
    await expect(page.locator('#e_status')).toHaveValue('sold_out');

    const afterTickets = await fetchTickets(page,fixture.eventId);
    const afterLineup = await fetchLineup(page,fixture.eventId);
    const afterTimetable = await fetchTimetable(page,fixture.eventId);
    expect(Number(afterTickets[0].id)).toBe(Number(beforeTickets[0].id));
    expect(afterLineup.map(row => [Number(row.artist_id),row.role])).toEqual(
      beforeLineup.map(row => [Number(row.artist_id),row.role])
    );
    expect(afterTimetable.map(row => [Number(row.id),Number(row.artist_id),row.status])).toEqual(
      beforeTimetable.map(row => [Number(row.id),Number(row.artist_id),row.status])
    );
  } finally {
    await cleanupEvent(page,fixture);
  }
});
