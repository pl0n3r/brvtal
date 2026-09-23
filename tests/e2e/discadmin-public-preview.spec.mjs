import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const previewJs = readFileSync(join(process.cwd(), 'discadmin/public-preview.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/public-preview-e2e.html';

async function loadHarness(page) {
  await page.route('**/api/public-preview.php', async route => {
    const request = route.request();
    const payload = request.postDataJSON?.() || JSON.parse(request.postData() || '{}');
    await page.evaluate(data => { window.__previewRequest = data; }, {
      payload,
      csrf:request.headers()['x-csrf-token'] || ''
    });
    await route.fulfill({
      contentType:'application/json',
      body:JSON.stringify({
        ok:true,
        data:{url:'/preview/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',expires_at:123}
      })
    });
  });
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html',
    body:`<!doctype html><html><body>
      <button id="previewBtn" hidden>PUBLIC PREVIEW</button>
      <input id="f_title" value="Unsaved GENESIS">
      <input id="f_slug" value="unsaved-genesis">
      <input id="f_event_date" value="2026-10-31T22:30">
      <input id="f_status" value="draft">
      <input id="f_venue" value="La Perla">
      <input id="f_city" value="Pereira">
      <textarea id="f_description">Draft copy that is not persisted.</textarea>
      <input id="f_cover_image" value="/uploads/events/genesis.jpg">
      <input id="f_accent" value="#b6ff00">
      <input id="f_ticket_url" value="https://tickets.example.test/genesis">
      <script>
        let csrf='csrf-token';
        window.__previewOpened='';
        window.open=()=>({
          closed:false,
          document:{
            title:'',
            body:{style:{cssText:''},replaceChildren:()=>{}},
            createElement:()=>({textContent:''})
          },
          location:{replace:url=>{window.__previewOpened=url}},
          close:()=>{}
        });
      </script>
      <script>${previewJs}</script>
      <script>BRVTALPublicPreview.bindLegacy('events',{id:42});</script>
    </body></html>`
  }));
  await page.goto(harnessUrl);
}

test('legacy editor preview posts current unsaved values and opens private token route', async ({ page }) => {
  await loadHarness(page);
  await expect(page.locator('#previewBtn')).toBeVisible();
  await page.locator('#f_title').fill('Unsaved long event title for composition testing');
  await page.locator('#previewBtn').click();

  await expect.poll(() => page.evaluate(() => window.__previewRequest || null)).not.toBeNull();
  const request = await page.evaluate(() => window.__previewRequest);
  expect(request.csrf).toBe('csrf-token');
  expect(request.payload.type).toBe('events');
  expect(request.payload.payload).toMatchObject({
    id:42,
    title:'Unsaved long event title for composition testing',
    slug:'unsaved-genesis',
    event_date:'2026-10-31 22:30',
    status:'draft',
    city:'Pereira',
    accent:'#b6ff00'
  });
  await expect.poll(() => page.evaluate(() => window.__previewOpened)).toBe(
    '/preview/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
  );
});

test('non-editor legacy modal cannot accidentally expose a public preview action', async ({ page }) => {
  await loadHarness(page);
  await page.evaluate(() => BRVTALPublicPreview.bindLegacy('media',{id:7}));
  await expect(page.locator('#previewBtn')).toBeHidden();
});
