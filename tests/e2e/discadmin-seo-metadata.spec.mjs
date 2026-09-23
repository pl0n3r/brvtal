import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const seoJs = readFileSync(join(process.cwd(), 'discadmin/seo-metadata.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/seo-metadata-e2e.html';

async function loadHarness(page, body) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><meta charset="utf-8"></head><body>${body}<script>${seoJs}</script></body></html>`
  }));
  await page.goto(harnessUrl);
}

test('legacy Event editor loads SEO fields and persists them after canonical save', async ({ page }) => {
  let seoRequest = null;
  await page.route('**/api/index.php/events/42', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, changed: 1 })
  }));
  await page.route('**/api/seo-metadata.php**', async route => {
    seoRequest = {
      url: route.request().url(),
      method: route.request().method(),
      body: route.request().postDataJSON()
    };
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: { id: 42 } }) });
  });

  await loadHarness(page, `
    <div id="modal" class="modal open"><div id="mcontent"><div class="form"><input id="f_title" value="Genesis"><input id="f_slug" value="genesis"></div></div></div>
    <script>
      window.state = { authed:true, section:'events', editing:42, rows:[{id:42,title:'Genesis',slug:'genesis',seo_title:'Genesis Techno Rave',seo_description:'Underground techno event in Pereira with BRVTAL.'}] };
      window.csrf = 'csrf-token';
      window.BRVTALFeedback = { success:()=>{}, error:()=>{} };
    </script>
  `);

  await expect(page.locator('#f_seo_title')).toHaveValue('Genesis Techno Rave');
  await expect(page.locator('#f_seo_description')).toHaveValue('Underground techno event in Pereira with BRVTAL.');
  await expect(page.getByText('SEARCH PREVIEW')).toBeVisible();

  await page.locator('#f_seo_title').fill('Genesis — BRVTAL');
  await page.locator('#f_seo_description').fill('BRVTAL underground techno event in Pereira, Colombia.');
  await page.evaluate(async () => {
    await fetch('/api/index.php/events/42', {
      method:'PUT',
      headers:{'Content-Type':'application/json','X-CSRF-Token':'csrf-token'},
      body:JSON.stringify({title:'Genesis'})
    });
  });

  await expect.poll(() => seoRequest).not.toBeNull();
  expect(seoRequest.method).toBe('PUT');
  expect(seoRequest.url).toContain('resource=events');
  expect(seoRequest.url).toContain('id=42');
  expect(seoRequest.body.seo_title).toBe('Genesis — BRVTAL');
  expect(seoRequest.body.seo_description).toBe('BRVTAL underground techno event in Pereira, Colombia.');
});

test('Release editor persists SEO metadata using returned release id', async ({ page }) => {
  let seoRequest = null;
  await page.route('**/api/releases.php', async route => {
    if (route.request().method() === 'GET') {
      return route.fulfill({ contentType:'application/json', body:JSON.stringify({ok:true,data:[]}) });
    }
    return route.fulfill({ contentType:'application/json', body:JSON.stringify({ok:true,data:{id:99,title:'BRVTAL 001',slug:'brvtal-001'}}) });
  });
  await page.route('**/api/seo-metadata.php**', async route => {
    seoRequest = {
      url: route.request().url(),
      method: route.request().method(),
      body: route.request().postDataJSON()
    };
    await route.fulfill({ contentType:'application/json', body:JSON.stringify({ok:true,data:{id:99}}) });
  });

  await loadHarness(page, `
    <div id="modal" class="modal open"><div id="mcontent"><div class="form"><input id="release_title" value="BRVTAL 001"><input id="release_slug" value="brvtal-001"></div></div></div>
    <script>
      window.csrf = 'csrf-token';
      window.BRVTALFeedback = { success:()=>{}, error:()=>{} };
    </script>
  `);

  await expect(page.locator('#release_seo_title')).toBeVisible();
  await page.locator('#release_seo_title').fill('BRVTAL 001 — Official Release');
  await page.locator('#release_seo_description').fill('Official BRVTAL release available across selected music platforms.');

  await page.evaluate(async () => {
    await fetch('/api/releases.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':'csrf-token'},
      body:JSON.stringify({title:'BRVTAL 001',slug:'brvtal-001'})
    });
  });

  await expect.poll(() => seoRequest).not.toBeNull();
  expect(seoRequest.url).toContain('resource=releases');
  expect(seoRequest.url).toContain('id=99');
  expect(seoRequest.body.seo_title).toBe('BRVTAL 001 — Official Release');
});


test('SEO failure is a recoverable partial save and cannot duplicate a Release POST', async ({ page }) => {
  let releaseWrites = 0;
  let seoWrites = 0;

  await page.route('**/api/releases.php', async route => {
    if (route.request().method() === 'GET') {
      return route.fulfill({ contentType:'application/json', body:JSON.stringify({ok:true,data:[]}) });
    }
    releaseWrites += 1;
    return route.fulfill({
      status:201,
      contentType:'application/json',
      body:JSON.stringify({ok:true,data:{id:99,title:'BRVTAL PARTIAL',slug:'brvtal-partial'}})
    });
  });
  await page.route('**/api/seo-metadata.php**', async route => {
    seoWrites += 1;
    if (seoWrites === 1) {
      return route.fulfill({
        status:503,
        contentType:'application/json',
        body:JSON.stringify({ok:false,error:'SEO_DOWN'})
      });
    }
    return route.fulfill({
      contentType:'application/json',
      body:JSON.stringify({ok:true,data:{id:99}})
    });
  });

  await loadHarness(page, `
    <div id="modal" class="modal open"><div id="mcontent"><div class="form"><input id="release_title" value="BRVTAL PARTIAL"><input id="release_slug" value="brvtal-partial"></div></div></div>
    <script>
      window.csrf = 'csrf-token';
      window.__seoResolved = null;
      window.BRVTALFeedback = { success:()=>{}, error:()=>{} };
      window.addEventListener('brvtal:seo-partial-resolved', event => { window.__seoResolved = event.detail; });
    </script>
  `);

  await expect(page.locator('#release_seo_title')).toBeVisible();
  await page.locator('#release_seo_title').fill('Recover this SEO title');
  await page.locator('#release_seo_description').fill('These edits must remain available after the split save fails.');

  const first = await page.evaluate(async () => {
    const response = await fetch('/api/releases.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':'csrf-token'},
      body:JSON.stringify({title:'BRVTAL PARTIAL',slug:'brvtal-partial'})
    });
    return {status:response.status,body:await response.json()};
  });

  expect(first.status).toBe(409);
  expect(first.body).toMatchObject({
    ok:false,
    error:'SEO_METADATA_SAVE_FAILED',
    partial_save:true,
    content_saved:true,
    content_id:99,
    resource:'releases'
  });
  expect(releaseWrites).toBe(1);
  expect(seoWrites).toBe(1);
  await expect(page.locator('[data-seo-recovery]')).toContainText('PARTIAL SAVE');
  await expect(page.locator('[data-seo-retry]')).toBeVisible();
  await expect(page.locator('#release_seo_title')).toHaveValue('Recover this SEO title');

  const duplicate = await page.evaluate(async () => {
    const response = await fetch('/api/releases.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':'csrf-token'},
      body:JSON.stringify({title:'BRVTAL PARTIAL',slug:'brvtal-partial'})
    });
    return {status:response.status,body:await response.json()};
  });

  expect(duplicate.status).toBe(409);
  expect(duplicate.body.error).toBe('SEO_METADATA_RETRY_REQUIRED');
  expect(releaseWrites).toBe(1);
  expect(seoWrites).toBe(1);

  await page.locator('[data-seo-retry]').click();
  await expect.poll(() => seoWrites).toBe(2);
  expect(releaseWrites).toBe(1);
  await expect.poll(() => page.evaluate(() => window.__seoResolved)).toEqual({resource:'releases',id:99});
  await expect(page.locator('[data-seo-recovery]')).toContainText('SEO RECOVERED');
});
