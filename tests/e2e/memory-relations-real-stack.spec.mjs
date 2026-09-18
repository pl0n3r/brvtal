import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL real-stack URL and E2E admin credentials are required');

async function login(page) {
  const response = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data:{email:adminEmail,password:adminPassword},
  });
  expect(response.ok(), `E2E admin login failed with HTTP ${response.status()}`).toBeTruthy();
  const payload = await response.json();
  expect(payload.ok).toBe(true);
  expect(payload.csrf).toBeTruthy();
  return payload;
}

test('E2E admin relates a curated Memory and the public boundary exposes the explicit link', async ({ page }, testInfo) => {
  const auth = await login(page);
  const runKey = `${Date.now().toString(36)}-${testInfo.workerIndex}`;
  const eventTitle = `E2E MEMORY EVENT ${runKey}`;
  const eventSlug = `e2e-memory-event-${runKey}`;
  const memoryTitle = `E2E MEMORY ${runKey}`;
  let eventId = 0;
  let mediaId = 0;
  let memoryId = 0;

  const headers = {'X-CSRF-Token':auth.csrf};
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
    'base64',
  );

  try {
    const eventResponse = await page.request.post(`${baseUrl}/api/event-workflow.php`, {
      headers,
      data:{
        event:{
          title:eventTitle,
          slug:eventSlug,
          description:'E2E Memory relation fixture.',
          event_date:'2026-11-07 21:00:00',
          city:'Pereira',
          venue:'E2E Warehouse',
          status:'tickets_available',
          ticket_url:'https://tickets.example/e2e-memory',
        },
        ticket_types:[],
        lineup:[],
      },
    });
    expect(eventResponse.status()).toBe(200);
    eventId = Number((await eventResponse.json())?.data?.event?.id || 0);
    expect(eventId).toBeGreaterThan(0);

    const upload = await page.request.post(`${baseUrl}/api/media-library.php?action=upload`, {
      headers,
      multipart:{
        title:memoryTitle,
        alt_text:'E2E Memory relation fixture',
        file:{name:'e2e-memory.png',mimeType:'image/png',buffer:png},
      },
    });
    expect(upload.status()).toBe(201);
    const uploaded = await upload.json();
    mediaId = Number(uploaded?.data?.id || 0);
    expect(mediaId).toBeGreaterThan(0);

    const publishMedia = await page.request.put(`${baseUrl}/api/media-library.php?action=update&id=${mediaId}`, {
      headers,
      data:{title:memoryTitle,alt_text:'E2E Memory relation fixture',status:'published'},
    });
    expect(publishMedia.ok()).toBeTruthy();

    const createMemory = await page.request.post(`${baseUrl}/api/memories.php?action=create`, {
      headers,
      data:{media_id:mediaId,title:memoryTitle,context:'E2E cultural archive',status:'published',sort_order:1},
    });
    expect(createMemory.status()).toBe(201);
    memoryId = Number((await createMemory.json())?.data?.id || 0);
    expect(memoryId).toBeGreaterThan(0);

    await page.goto(`${baseUrl}/discadmin/?module=media&view=memories`, {waitUntil:'domcontentloaded'});
    await expect(page.getByRole('heading',{name:'MEMORIES',level:2})).toBeVisible({timeout:10_000});
    const card = page.locator(`[data-memory-id="${memoryId}"]`);
    await expect(card).toBeVisible();
    const eventCheckbox = card.locator(`[data-memory-relation-type="event"][data-memory-relation-id="${eventId}"]`);
    await expect(eventCheckbox).toBeVisible();
    await eventCheckbox.check();

    const saveResponsePromise = page.waitForResponse(response =>
      response.request().method() === 'PUT'
      && response.url().includes('/api/memories.php')
      && response.url().includes(`id=${memoryId}`)
    );
    await card.getByRole('button',{name:'SAVE'}).click();
    const saveResponse = await saveResponsePromise;
    expect(saveResponse.ok()).toBeTruthy();

    const adminList = await (await page.request.get(`${baseUrl}/api/memories.php?action=list`)).json();
    const persisted = adminList.data.find(item => Number(item.id) === memoryId);
    expect(persisted?.relations).toEqual([{related_type:'event',related_id:eventId,sort_order:0}]);

    const publicPayload = await (await page.request.get(`${baseUrl}/api/public.php`)).json();
    const publicMemory = publicPayload.data.memories.find(item => Number(item.id) === memoryId);
    expect(publicMemory).toBeTruthy();
    expect(publicMemory.relations).toEqual([
      expect.objectContaining({related_type:'event',related_id:eventId,route_type:'events',slug:eventSlug,label:eventTitle}),
    ]);

    const eventPage = await page.request.get(`${baseUrl}/index.php?type=events&slug=${encodeURIComponent(eventSlug)}`);
    expect(eventPage.ok()).toBeTruthy();
    const eventHtml = await eventPage.text();
    expect(eventHtml).toContain('MEMORIES / 01');
    expect(eventHtml).toContain(memoryTitle);
  } finally {
    const cleanupErrors = [];
    const cleanupDelete = async (label, url) => {
      try {
        const response = await page.request.delete(url, {headers});
        if (!response.ok()) cleanupErrors.push(`${label} HTTP ${response.status()}`);
      } catch (error) {
        cleanupErrors.push(`${label}: ${error instanceof Error ? error.message : String(error)}`);
      }
    };

    if (memoryId > 0) {
      await cleanupDelete('Memory cleanup', `${baseUrl}/api/memories.php?id=${memoryId}`);
    }
    if (mediaId > 0) {
      await cleanupDelete('Media cleanup', `${baseUrl}/api/media-library.php?id=${mediaId}`);
    }
    if (eventId > 0) {
      await cleanupDelete('Event cleanup', `${baseUrl}/api/index.php/events/${eventId}`);
    }
    expect(cleanupErrors, `Cleanup failed: ${cleanupErrors.join(' | ')}`).toEqual([]);
  }
});
