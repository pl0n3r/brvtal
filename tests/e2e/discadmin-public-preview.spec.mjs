import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const previewJs = readFileSync(join(process.cwd(), 'discadmin/public-preview.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/public-preview-e2e.html';

async function loadHarness(page, { popupBlocked = false } = {}) {
  await page.route('**/api/public-preview.php', async route => {
    const request = route.request();
    const payload = request.postDataJSON?.() || JSON.parse(request.postData() || '{}');
    await page.evaluate(data => { window.__previewRequest = data; }, {
      payload,
      csrf:request.headers()['x-csrf-token'] || ''
    });
    if (payload.type === 'memories') {
      await route.fulfill({
        status:422,
        contentType:'application/json',
        body:JSON.stringify({ok:false,error:'PREVIEW_TYPE_NOT_ALLOWED'})
      });
      return;
    }
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
        window.__previewWindowArgs=null;
        window.__previewFeedback=[];
        window.__popupBlocked=${popupBlocked ? 'true' : 'false'};
        window.BRVTALFeedback={error:(message,context)=>window.__previewFeedback.push({message,context})};
        window.open=(url,target,features)=>{
          window.__previewWindowArgs={url,target,features:features||''};
          if (window.__popupBlocked) return null;
          return ({
            closed:false,
            document:{
              title:'',
              body:{style:{cssText:''},replaceChildren:()=>{}},
              createElement:()=>({textContent:''})
            },
            location:{replace:url=>{window.__previewOpened=url}},
            close:()=>{}
          });
        };
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
  expect(await page.evaluate(() => window.__previewWindowArgs)).toEqual({
    url:'about:blank',
    target:'_blank',
    features:''
  });
});

test('server remains the canonical authority for unsupported preview types', async ({ page }) => {
  await loadHarness(page);
  expect(await page.evaluate(() => typeof BRVTALPublicPreview.canonicalTypes)).toBe('undefined');

  const unsupported = await page.evaluate(async () => {
    try {
      await BRVTALPublicPreview.create('memories',{title:'Not a standalone public route'});
      return 'accepted';
    } catch (error) {
      return error.message;
    }
  });
  expect(unsupported).toBe('PREVIEW_TYPE_NOT_ALLOWED');
});

test('non-editor legacy modal cannot accidentally expose a public preview action', async ({ page }) => {
  await loadHarness(page);
  await page.evaluate(() => BRVTALPublicPreview.bindLegacy('media',{id:7}));
  await expect(page.locator('#previewBtn')).toBeHidden();
  await expect(page.locator('#previewBtn')).toBeDisabled();
});

test('blocked popup keeps DISCADMIN in place and does not create a snapshot', async ({ page }) => {
  await loadHarness(page, { popupBlocked:true });
  const originalUrl = page.url();

  const error = await page.evaluate(async () => {
    try {
      await BRVTALPublicPreview.open('events',{title:'Blocked popup draft'});
      return 'accepted';
    } catch (caught) {
      return caught.message;
    }
  });

  expect(error).toBe('PREVIEW_POPUP_BLOCKED');
  expect(page.url()).toBe(originalUrl);
  expect(await page.evaluate(() => window.__previewRequest || null)).toBeNull();
  expect(await page.evaluate(() => window.__previewFeedback)).toEqual([
    {message:'PREVIEW POPUP BLOCKED',context:'public-preview'}
  ]);
});
