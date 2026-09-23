import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const lineupJs = readFileSync(join(process.cwd(), 'discadmin/content-core-lineup.js'), 'utf8');
const seoMetadataJs = readFileSync(join(process.cwd(), 'discadmin/seo-metadata.js'), 'utf8');
const seoDefaultsJs = readFileSync(join(process.cwd(), 'discadmin/seo-editorial-defaults.js'), 'utf8');

const lineupHarness = 'http://127.0.0.1:4173/discadmin/e2e-lineup-regression.html';
const seoHarness = 'http://127.0.0.1:4173/discadmin/e2e-seo-dedupe.html';
const seoLiveHarness = 'http://127.0.0.1:4173/discadmin/e2e-seo-live-defaults.html';

test('Content Core lineup bridge uses the canonical index.php route for load and save', async ({ page }) => {
  const requests = [];

  await page.route(lineupHarness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body><script>${lineupJs}</script></body></html>`,
  }));

  await page.route('**/api/index.php/events/42/lineup', async route => {
    const request = route.request();
    requests.push({
      method:request.method(),
      path:new URL(request.url()).pathname,
      csrf:request.headers()['x-csrf-token'] || '',
      contentType:request.headers()['content-type'] || '',
      body:request.postData() ? JSON.parse(request.postData()) : null,
    });
    if (request.method() === 'GET') {
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[{artist_id:7,lineup_order:0,role:''}]})});
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true})});
  });

  await page.goto(lineupHarness);
  const result = await page.evaluate(async () => {
    const loaded = await window.BRVTALContentCoreLineup.load(42, 'csrf-lineup');
    const saved = await window.BRVTALContentCoreLineup.save(42, [{artist_id:7,lineup_order:0,role:''}], 'csrf-lineup');
    return {loaded,saved};
  });

  expect(result.loaded).toHaveLength(1);
  expect(result.saved.ok).toBe(true);
  expect(requests).toHaveLength(2);
  expect(requests.map(x => x.path)).toEqual([
    '/api/index.php/events/42/lineup',
    '/api/index.php/events/42/lineup',
  ]);
  expect(requests[1].method).toBe('POST');
  expect(requests[1].csrf).toBe('csrf-lineup');
  expect(requests[1].contentType).toContain('application/json');
  expect(requests[1].body).toEqual({lineup:[{artist_id:7,lineup_order:0,role:''}]});
});

test('Content Core keeps one SEO metadata section when async decoration races', async ({ page }) => {
  await page.route(seoHarness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body>
      <div id="eventModal" class="modal open">
        <div class="step-content" data-content="1">
          <input id="e_title" value="TEST DELETE">
          <input id="e_slug" value="test-delete">
          <textarea id="e_description">description</textarea>
        </div>
      </div>
      <script>window.csrf='csrf-test';window.BRVTALFeedback={success:()=>{},error:()=>{}};</script>
      <script>${seoMetadataJs}</script>
      <script>${seoDefaultsJs}</script>
    </body></html>`,
  }));

  await page.route('**/api/index.php/events', async route => {
    await new Promise(resolve => setTimeout(resolve, 140));
    return route.fulfill({
      contentType:'application/json',
      body:JSON.stringify({ok:true,data:[{id:42,title:'TEST DELETE',slug:'test-delete',description:'description',seo_title:'TEST DELETE',seo_description:'description'}]}),
    });
  });

  await page.goto(seoHarness);
  await page.waitForTimeout(45);
  await page.evaluate(() => {
    const modal = document.getElementById('eventModal');
    modal.classList.remove('open');
    modal.classList.add('open');
  });
  await page.waitForTimeout(350);

  await expect(page.locator('[data-seo-editor="content-core"]')).toHaveCount(1);
  await expect(page.locator('#e_seo_title')).toHaveValue('TEST DELETE');
  await expect(page.locator('#e_seo_description')).toHaveValue('description');
});

test('Content Core SEO keeps dynamic fallbacks until the editor makes a manual override', async ({ page }) => {
  await page.route(seoLiveHarness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body>
      <div id="eventModal" class="modal open">
        <div class="step-content" data-content="1">
          <input id="e_title" value="">
          <input id="e_slug" value="">
          <textarea id="e_description"></textarea>
        </div>
      </div>
      <script>window.csrf='csrf-test';window.BRVTALFeedback={success:()=>{},error:()=>{}};</script>
      <script>${seoDefaultsJs}</script>
      <script>${seoMetadataJs}</script>
    </body></html>`,
  }));

  await page.route('**/api/index.php/events', route => route.fulfill({
    contentType:'application/json',
    body:JSON.stringify({ok:true,data:[]}),
  }));

  await page.goto(seoLiveHarness);
  await expect(page.locator('[data-seo-editor="content-core"]')).toHaveCount(1);

  await page.fill('#e_title', 'test');
  await page.fill('#e_description', 'gt');
  await expect(page.locator('#e_seo_title')).toHaveValue('');
  await expect(page.locator('#e_seo_title')).toHaveAttribute('placeholder','test');
  await expect(page.locator('#e_seo_description')).toHaveValue('');
  await expect(page.locator('#e_seo_description')).toHaveAttribute('placeholder','gt');

  await page.fill('#e_seo_title', 'CUSTOM SEARCH TITLE');
  await page.fill('#e_title', 'test changed');
  await expect(page.locator('#e_seo_title')).toHaveValue('CUSTOM SEARCH TITLE');
  await expect(page.locator('#e_seo_description')).toHaveValue('');
  await expect(page.locator('#e_seo_description')).toHaveAttribute('placeholder','gt');

  await page.fill('#e_seo_title', '');
  await expect(page.locator('#e_seo_title')).toHaveValue('');
  await expect(page.locator('#e_seo_title')).toHaveAttribute('placeholder','test changed');
});
