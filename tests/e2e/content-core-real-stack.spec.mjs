import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL_REAL_STACK_URL and admin credentials are required for the real-stack smoke');

async function login(page) {
  const response = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data: {email:adminEmail,password:adminPassword},
  });
  expect(response.ok(), `Admin login failed with HTTP ${response.status()}`).toBeTruthy();
  const payload = await response.json();
  expect(payload.ok).toBe(true);
  expect(payload.csrf).toBeTruthy();
  return payload;
}

test('Content Core saves Event, Tickets, roster and SEO through one atomic workflow', async ({ page }, testInfo) => {
  const auth = await login(page);
  const runKey = `${Date.now().toString(36)}-${testInfo.workerIndex}`;
  const title = `CI ATOMIC EVENT ${runKey}`;
  const slug = `ci-atomic-event-${runKey}`;
  let eventId = 0;

  try {
    await page.goto(`${baseUrl}/discadmin/?module=events`, {waitUntil:'domcontentloaded'});
    const core = page.locator('[data-admin-module="content-core"]');
    await expect(core).toBeAttached({timeout:10_000});
    await expect(page.locator('.main .top h1')).toHaveText('EVENTS');
    await expect(page.locator('.main .toolbar .search:visible')).toHaveCount(1);
    await expect(core.locator('.wrap')).toBeHidden();

    const step = number => page.locator(`#eventModal [data-step="${number}"]`);
    await page.getByRole('button', {name:'+ NEW EVENT', exact:true}).click();
    await expect(page.locator('#eventModal')).toHaveClass(/open/);
    await page.locator('#e_title').fill(title);
    await page.locator('#e_slug').fill(slug);
    await page.locator('#e_description').fill('Atomic Content Core persistence smoke.');
    await expect(page.locator('[data-admin-color-field]')).toBeVisible();
    await page.locator('#e_accent').fill('B6FF00');
    await page.locator('#e_accent').blur();
    await expect(page.locator('#e_accent')).toHaveValue('#b6ff00');
    await expect(page.locator('#e_accent_picker')).toHaveValue('#b6ff00');

    await expect(page.locator('#e_seo_title')).toBeVisible({timeout:5_000});
    await page.locator('#e_seo_title').fill('CI Atomic Event | BRVTAL');
    await page.locator('#e_seo_description').fill('Atomic Event, Ticket, roster and SEO real-stack regression.');

    await step(2).click();
    await page.locator('#e_event_date').fill('2026-10-31T21:00');
    await page.locator('#e_city').fill('Pereira');
    await page.locator('#e_venue').fill('CI Warehouse');

    await step(3).click();
    await page.locator('#e_status').selectOption('tickets_available');
    await page.locator('#e_ticket_url').fill('https://tickets.example/ci-atomic');

    await step(4).click();
    await page.getByRole('button', {name:'+ ADD TICKET'}).click();
    const ticket = page.locator('#tickets .ticket-row').first();
    await ticket.locator('[data-k="name"]').fill('PREVENTA');
    await ticket.locator('[data-k="price"]').fill('20000');
    await ticket.locator('[data-k="status"]').selectOption('active');
    await ticket.locator('[data-k="external_url"]').fill('https://tickets.example/ci-preventa');

    await step(5).click();
    const artistRow = page.locator('#eventArtists .artist').filter({hasText:'PL0N3R SMOKE'});
    await expect(artistRow).toBeVisible();
    await artistRow.locator('[data-artist]').check();

    const workflowCreate = page.waitForResponse(response =>
      response.request().method() === 'POST' && response.url().endsWith('/api/event-workflow.php')
    );
    await page.locator('#cc-top-saveBtn').click();
    const createResponse = await workflowCreate;
    expect(createResponse.status()).toBe(200);
    const createPayload = await createResponse.json();
    eventId = Number(createPayload?.data?.event?.id || 0);
    expect(eventId).toBeGreaterThan(0);
    expect(createPayload.data.ticket_types).toHaveLength(1);
    expect(createPayload.data.lineup).toHaveLength(1);
    await expect(page.locator('#eventNotice')).toContainText('saved together', {timeout:10_000});

    const events = await (await page.request.get(`${baseUrl}/api/index.php/events`)).json();
    const persisted = events.data.find(row => Number(row.id) === eventId);
    expect(persisted).toMatchObject({
      title,
      slug,
      status:'tickets_available',
      city:'Pereira',
      venue:'CI Warehouse',
      accent:'#b6ff00',
      seo_title:'CI Atomic Event | BRVTAL',
    });
    expect(persisted.seo_description).toContain('Atomic Event, Ticket, roster and SEO');

    const tickets = await (await page.request.get(`${baseUrl}/api/index.php/ticket_types`)).json();
    const persistedTicket = tickets.data.find(row => Number(row.event_id) === eventId);
    expect(persistedTicket).toBeTruthy();
    expect(persistedTicket.name).toBe('PREVENTA');
    expect(Number(persistedTicket.price)).toBe(20000);

    const lineupResponse = await page.request.get(`${baseUrl}/api/index.php/events/${eventId}/lineup`);
    expect(lineupResponse.ok()).toBeTruthy();
    const lineup = await lineupResponse.json();
    expect(lineup.data.map(row => row.name)).toContain('PL0N3R SMOKE');

    await expect(page.locator('#tickets')).toHaveAttribute('data-load-state','ready',{timeout:10_000});
    await step(3).click();
    await page.locator('#e_status').selectOption('sold_out');
    await step(4).click();
    await page.locator('#tickets [data-k="price"]').fill('25000');
    await step(1).click();
    await page.locator('#e_seo_description').fill('Updated atomically: sold out with a new persisted ticket price.');

    const workflowUpdate = page.waitForResponse(response =>
      response.request().method() === 'POST' && response.url().endsWith('/api/event-workflow.php')
    );
    await page.locator('#cc-top-saveBtn').click();
    const updateResponse = await workflowUpdate;
    expect(updateResponse.status()).toBe(200);
    const updatePayload = await updateResponse.json();
    expect(updatePayload.data.event.status).toBe('sold_out');
    expect(Number(updatePayload.data.ticket_types[0].price)).toBe(25000);
    expect(updatePayload.data.event.seo_description).toContain('Updated atomically');

    const partialSlug = `ci-atomic-rollback-${runKey}`;
    const failed = await page.request.post(`${baseUrl}/api/event-workflow.php`, {
      headers:{'X-CSRF-Token':auth.csrf},
      data:{
        event:{title:`CI ROLLBACK ${runKey}`,slug:partialSlug,status:'draft'},
        ticket_types:[{name:'SHOULD ROLLBACK',price:'10000',status:'active'}],
        lineup:[{artist_id:999999,lineup_order:0,role:'Ghost'}],
      },
    });
    expect(failed.status()).toBe(422);
    expect(await failed.json()).toMatchObject({ok:false,error:'LINEUP_ARTIST_NOT_FOUND'});
    const afterFailure = await (await page.request.get(`${baseUrl}/api/index.php/events`)).json();
    expect(afterFailure.data.some(row => row.slug === partialSlug)).toBe(false);

    const incomplete = await page.request.post(`${baseUrl}/api/event-workflow.php`, {
      headers:{'X-CSRF-Token':auth.csrf},
      data:{
        event:{title:`CI INCOMPLETE ${runKey}`,slug:`ci-incomplete-${runKey}`,status:'published',city:'Pereira'},
        ticket_types:[],
        lineup:[],
      },
    });
    expect(incomplete.status()).toBe(422);
    expect(await incomplete.json()).toMatchObject({ok:false,error:'EVENT_DATE_REQUIRED'});

    const invalidAccent = await page.request.post(`${baseUrl}/api/event-workflow.php`, {
      headers:{'X-CSRF-Token':auth.csrf},
      data:{
        event:{title:`CI INVALID ACCENT ${runKey}`,slug:`ci-invalid-accent-${runKey}`,status:'draft',accent:'#12'},
        ticket_types:[],
        lineup:[],
      },
    });
    expect(invalidAccent.status()).toBe(422);
    expect(await invalidAccent.json()).toMatchObject({ok:false,error:'INVALID_ACCENT'});

    const rejectedPage = await page.request.post(`${baseUrl}/api/index.php/pages`, {
      headers:{'X-CSRF-Token':auth.csrf},
      data:{title:`CI ES PAGE ${runKey}`,slug:`ci-es-page-${runKey}`,locale:'es',status:'published',content_json:'{}'},
    });
    expect(rejectedPage.status()).toBe(422);
    expect(await rejectedPage.json()).toMatchObject({error:'PAGE_PUBLIC_LOCALE_MUST_BE_EN'});
  } finally {
    if (eventId > 0) {
      await page.request.delete(`${baseUrl}/api/index.php/events/${eventId}`, {
        headers:{'X-CSRF-Token':auth.csrf},
      }).catch(() => {});
    }
  }
});

test('Content Core media references fail closed at public-state mutation and stay visible as health debt', async ({ page }, testInfo) => {
  const auth = await login(page);
  const headers = {'X-CSRF-Token':auth.csrf};
  const runKey = `${Date.now().toString(36)}-${testInfo.workerIndex}`;
  const missingImage = `/uploads/media/ci-missing-${runKey}.png`;
  const created = {sets:[], artists:[], events:[]};

  const create = async (resource, data) => {
    const response = await page.request.post(`${baseUrl}/api/index.php/${resource}`, {headers,data});
    expect(response.status(), `create ${resource}`).toBe(201);
    const payload = await response.json();
    const id = Number(payload.id || 0);
    expect(id).toBeGreaterThan(0);
    created[resource].push(id);
    return id;
  };

  const getOne = async (resource, id) => {
    const response = await page.request.get(`${baseUrl}/api/index.php/${resource}/${id}`);
    expect(response.ok()).toBeTruthy();
    return (await response.json()).data;
  };

  try {
    const malformed = await page.request.post(`${baseUrl}/api/index.php/events`, {
      headers,
      data:{
        title:`CI INVALID MEDIA ${runKey}`,
        slug:`ci-invalid-media-${runKey}`,
        status:'draft',
        cover_image:'javascript:alert(1)',
      },
    });
    expect(malformed.status()).toBe(422);
    expect(await malformed.json()).toMatchObject({error:'INVALID_MEDIA_REFERENCE',field:'cover_image'});

    const eventId = await create('events', {
      title:`CI BROKEN EVENT ${runKey}`,
      slug:`ci-broken-event-${runKey}`,
      status:'draft',
      event_date:'2026-12-01T21:00',
      city:'Pereira',
      cover_image:missingImage,
    });
    const publishEvent = await page.request.put(`${baseUrl}/api/index.php/events/${eventId}`, {
      headers,
      data:{status:'published'},
    });
    expect(publishEvent.status()).toBe(422);
    expect(await publishEvent.json()).toMatchObject({error:'MEDIA_REFERENCE_UNRESOLVABLE',field:'cover_image'});
    expect((await getOne('events',eventId)).status).toBe('draft');

    const artistId = await create('artists', {
      name:`CI BROKEN ARTIST ${runKey}`,
      slug:`ci-broken-artist-${runKey}`,
      status:'draft',
      photo:missingImage,
    });
    const publishArtist = await page.request.put(`${baseUrl}/api/index.php/artists/${artistId}`, {
      headers,
      data:{status:'published'},
    });
    expect(publishArtist.status()).toBe(422);
    expect(await publishArtist.json()).toMatchObject({error:'MEDIA_REFERENCE_UNRESOLVABLE',field:'photo'});
    expect((await getOne('artists',artistId)).status).toBe('draft');

    const setId = await create('sets', {
      title:`CI BROKEN SET ${runKey}`,
      slug:`ci-broken-set-${runKey}`,
      artist_id:artistId,
      platform:'other',
      external_url:'https://soundcloud.com/brvtal/ci-media-contract',
      status:'draft',
      cover_image:missingImage,
    });
    const publishSet = await page.request.put(`${baseUrl}/api/index.php/sets/${setId}`, {
      headers,
      data:{status:'published'},
    });
    expect(publishSet.status()).toBe(422);
    expect(await publishSet.json()).toMatchObject({error:'MEDIA_REFERENCE_UNRESOLVABLE',field:'cover_image'});
    expect((await getOne('sets',setId)).status).toBe('draft');

    const externalSetId = await create('sets', {
      title:`CI EXTERNAL MEDIA SET ${runKey}`,
      slug:`ci-external-media-set-${runKey}`,
      artist_id:artistId,
      platform:'other',
      external_url:'https://soundcloud.com/brvtal/ci-external-media',
      status:'published',
      cover_image:'https://cdn.example.test/ci-cover.jpg',
    });
    expect((await getOne('sets',externalSetId)).status).toBe('published');

    const healthResponse = await page.request.get(`${baseUrl}/api/content-health.php`);
    expect(healthResponse.ok()).toBeTruthy();
    const health = (await healthResponse.json()).data;
    const draftItems = Array.isArray(health?.drafts?.items) ? health.drafts.items : [];
    for (const [type,id] of [['events',eventId],['artists',artistId],['sets',setId]]) {
      const item = draftItems.find(row => row.type === type && Number(row.id) === id);
      expect(item, `Content Health missing ${type} ${id}`).toBeTruthy();
      expect(item).toMatchObject({
        has_image:false,
        image_reference_kind:'local_missing',
      });
      expect(item.issues).toContain('Broken primary visual');
    }
    expect(Number(health?.drafts?.broken_visuals || 0)).toBeGreaterThanOrEqual(3);
  } finally {
    for (const resource of ['sets','artists','events']) {
      for (const id of [...created[resource]].reverse()) {
        await page.request.delete(`${baseUrl}/api/index.php/${resource}/${id}`, {headers}).catch(() => {});
      }
    }
  }
});

async function expectMediaMounted(page, timeout = 10_000) {
  try {
    await expect(page.locator('[data-admin-module="media"]')).toBeVisible({timeout});
  } catch (error) {
    const snapshot = await page.evaluate(() => ({
      href: location.href,
      section: window.state?.section ?? null,
      title: document.querySelector('.main .top h1')?.textContent?.trim() ?? null,
      hostText: document.getElementById('admin-module-host')?.textContent?.trim().slice(0, 500) ?? null,
      hostHtml: document.getElementById('admin-module-host')?.innerHTML?.slice(0, 1000) ?? null,
      mediaGlobal: Boolean(window.BRVTALMediaLibrary),
      moduleHostConnected: Boolean(document.getElementById('admin-module-host')?.isConnected),
      mediaNodeCount: document.querySelectorAll('[data-admin-module="media"]').length,
    }));
    throw new Error(`Media navigation state: ${JSON.stringify(snapshot)}\n${error.message}`);
  }
}

test('Artists and Sets renderer emits canonical ordering containers through the shared grid', async ({ page }) => {
  await login(page);

  await page.goto(`${baseUrl}/discadmin/`, {waitUntil:'domcontentloaded'});
  await page.evaluate(async () => {
    await restoreSession();
  });

  for (const section of ['artists','sets']) {
    const result = await page.evaluate(currentSection => {
      const previousSection = state.section;
      const previousRows = state.rows;
      const probe = document.createElement('div');
      probe.id = 'grid-order-probe';
      document.body.appendChild(probe);
      try {
        state.section = currentSection;
        state.rows = [];
        const rendered = content();
        BRVTALDataGrid.render(currentSection,probe,[],{allRows:[],orderingEnabled:true});
        const body = probe.querySelector('.admin-data-grid-body');
        return {
          rendered,
          resource: body?.dataset.orderResource || '',
          enabled: body?.dataset.orderEnabled || '',
        };
      } finally {
        probe.remove();
        state.section = previousSection;
        state.rows = previousRows;
      }
    }, section);

    expect(result.rendered).toContain('id="rows"');
    expect(result.rendered).toContain(`data-admin-grid-host="${section}"`);
    expect(result.resource).toBe(section);
    expect(result.enabled).toBe('1');
  }
});

test('Media remains mounted when a stale Dashboard navigation completes', async ({ page }) => {
  await login(page);

  await page.goto(`${baseUrl}/discadmin/`, {waitUntil:'domcontentloaded'});
  await expect(page.locator('.main .top h1')).toHaveText('DASHBOARD', {timeout:10_000});

  let releaseStaleDashboard;
  let markStaleDashboardStarted;
  const staleDashboardStarted = new Promise(resolve => { markStaleDashboardStarted = resolve; });
  const staleDashboardRelease = new Promise(resolve => { releaseStaleDashboard = resolve; });
  await page.route('**/api/index.php/dashboard', async route => {
    markStaleDashboardStarted();
    await staleDashboardRelease;
    await route.continue();
  }, {times:1});

  await page.evaluate(() => {
    window.__staleDashboardNavigation = window.go('dashboard');
  });
  await staleDashboardStarted;

  await page.getByRole('button', {name:'MEDIA LIBRARY', exact:true}).click();
  await expectMediaMounted(page);
  await expect(page.locator('#media-grid')).toBeVisible();
  await expect(page.getByRole('button', {name:/REGISTER EXTERNAL/i})).toHaveCount(0);

  releaseStaleDashboard();
  await page.evaluate(() => window.__staleDashboardNavigation);
  await expectMediaMounted(page, 5_000);
  await expect(page.locator('.main .top h1')).toHaveText('MEDIA');
});

test('Media mounts from its canonical deep link', async ({ page }) => {
  await login(page);

  await page.goto(`${baseUrl}/discadmin/?module=media`, {waitUntil:'domcontentloaded'});
  await expectMediaMounted(page);
  await expect(page.locator('.main .top h1')).toHaveText('MEDIA');
  await expect(page.getByRole('button', {name:/REGISTER EXTERNAL/i})).toHaveCount(0);
});

test('Media writes stay behind the canonical integrity boundary in the real stack', async ({ page }) => {
  const auth = await login(page);
  const headers = {'X-CSRF-Token':auth.csrf};
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
    'base64',
  );
  let mediaId = 0;

  try {
    const legacyUpload = await page.request.post(`${baseUrl}/api/index.php/upload`, {headers});
    expect(legacyUpload.status()).toBe(404);
    expect(await legacyUpload.json()).toMatchObject({ok:false,error:'NOT_FOUND'});

    const legacyCreate = await page.request.post(`${baseUrl}/api/index.php/media`, {
      headers,
      data:{type:'image',title:'LEGACY MEDIA MUST STAY CLOSED',file_path:'/uploads/legacy.png',status:'published'},
    });
    expect(legacyCreate.status()).toBe(404);

    const upload = await page.request.post(`${baseUrl}/api/media-library.php?action=upload`, {
      headers,
      multipart:{
        title:'CI MEDIA INTEGRITY',
        alt_text:'CI media integrity fixture',
        file:{name:'ci-media-integrity.png',mimeType:'image/png',buffer:png},
      },
    });
    expect(upload.status()).toBe(201);
    const uploaded = await upload.json();
    mediaId = Number(uploaded?.data?.id || 0);
    const filePath = String(uploaded?.data?.file_path || '');
    expect(mediaId).toBeGreaterThan(0);
    expect(filePath).toMatch(/^\/uploads\/media\//);
    expect(uploaded?.data?.status).toBe('draft');

    const duplicate = await page.request.post(`${baseUrl}/api/media-library.php?action=register`, {
      headers,
      data:{type:'image',title:'CI DUPLICATE OWNER',file_path:filePath,status:'draft'},
    });
    expect(duplicate.status()).toBe(409);
    expect(await duplicate.json()).toMatchObject({ok:false,error:'LOCAL_MEDIA_ALREADY_REGISTERED',media_id:mediaId});

    const canonicalDelete = await page.request.delete(`${baseUrl}/api/media-library.php?id=${mediaId}`, {headers});
    expect(canonicalDelete.ok()).toBeTruthy();
    expect(await canonicalDelete.json()).toMatchObject({ok:true,deleted:1});
    mediaId = 0;
  } finally {
    if (mediaId > 0) {
      await page.request.delete(`${baseUrl}/api/media-library.php?id=${mediaId}`, {headers}).catch(() => {});
    }
  }
});
