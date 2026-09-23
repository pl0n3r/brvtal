import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL real-stack URL and admin credentials are required');

test('private public preview renders an unsaved draft canonically without publishing it', async ({ page, browser }, testInfo) => {
  const login = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data:{email:adminEmail,password:adminPassword},
  });
  expect(login.ok(), `Admin login failed with HTTP ${login.status()}`).toBeTruthy();
  const auth = await login.json();
  const runKey = `${Date.now().toString(36)}-${testInfo.workerIndex}`;
  const slug = `ci-private-preview-${runKey}`;
  const title = `CI PRIVATE PREVIEW ${runKey}`;

  const create = await page.request.post(`${baseUrl}/api/public-preview.php`, {
    headers:{'X-CSRF-Token':auth.csrf},
    data:{
      type:'events',
      payload:{
        title,
        slug,
        status:'draft',
        event_date:'2026-10-31 22:30',
        city:'Pereira',
        venue:'La Perla',
        description:'Unsaved draft rendered through the canonical public entity pipeline.',
        accent:'#b6ff00',
        ticket_types:[{
          name:'PREVENTA',
          price:20000,
          currency:'COP',
          status:'active',
          external_url:'https://tickets.example.test/private-preview',
          sort_order:0,
        }],
        lineup:[{artist_id:1,role:'LIVE',sort_order:0}],
      },
    },
  });
  expect(create.status()).toBe(200);
  const created = await create.json();
  expect(created).toMatchObject({ok:true});
  expect(created.data.url).toMatch(/^\/preview\/[a-f0-9]{48}$/);
  const token = created.data.url.split('/').pop();

  const shell = await page.request.get(`${baseUrl}/preview.php?token=${token}`);
  expect(shell.status()).toBe(200);
  expect(shell.headers()['cache-control']).toContain('no-store');
  expect(shell.headers()['x-robots-tag']).toBe('noindex, nofollow');
  const shellHtml = await shell.text();
  expect(shellHtml).toContain('data-width="1440"');
  expect(shellHtml).toContain('data-width="390"');

  const rendered = await page.request.get(`${baseUrl}/preview.php?token=${token}&render=1`);
  expect(rendered.status()).toBe(200);
  expect(rendered.headers()['cache-control']).toContain('no-store');
  expect(rendered.headers()['x-robots-tag']).toBe('noindex, nofollow');
  const renderedHtml = await rendered.text();
  expect(renderedHtml).toContain(title);
  expect(renderedHtml).toContain('data-public-preview="1"');
  expect(renderedHtml).toContain('/css/public-entity.css');
  expect(renderedHtml).toContain('PREVENTA');
  expect(renderedHtml).toContain('20,000 COP');
  expect(renderedHtml).toContain('PL0N3R SMOKE');

  const adminEvents = await page.request.get(`${baseUrl}/api/index.php/events`);
  expect(adminEvents.ok()).toBeTruthy();
  const eventRows = (await adminEvents.json()).data || [];
  expect(eventRows.some(event => event.slug === slug)).toBe(false);

  const isolated = await browser.newContext();
  try {
    const unauthorized = await isolated.request.get(
      `${baseUrl}/preview.php?token=${token}&render=1`
    );
    expect(unauthorized.status()).toBe(401);
    expect(unauthorized.headers()['x-robots-tag']).toBe('noindex, nofollow');
  } finally {
    await isolated.close();
  }
});

test('preview creation rejects unsafe raw draft URLs before rendering', async ({ page }) => {
  const login = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data:{email:adminEmail,password:adminPassword},
  });
  expect(login.ok()).toBeTruthy();
  const auth = await login.json();

  const response = await page.request.post(`${baseUrl}/api/public-preview.php`, {
    headers:{'X-CSRF-Token':auth.csrf},
    data:{
      type:'events',
      payload:{
        title:'Unsafe preview',
        slug:'unsafe-preview',
        status:'draft',
        cover_image:'javascript:alert(1)',
      },
    },
  });
  expect(response.status()).toBe(422);
  expect(await response.json()).toMatchObject({ok:false,error:'INVALID_PREVIEW_MEDIA'});
});
