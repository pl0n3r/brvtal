import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const contentCoreJs = readFileSync(join(process.cwd(), 'discadmin/content-core.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/e2e-content-core-api-boundary.html';

function harnessHtml() {
  return `<!doctype html><html><body>
    <div id="root" data-admin-module="content-core">
      <div id="cc-notice"></div>
      <input id="eventSearch"><div id="eventsTable"></div>
      <input id="artistSearch"><div id="rosterList"></div>
      <div id="eventArtists"></div><div id="artistDetail"></div>
      <div id="eventModal"><div id="eventHeading"></div><div id="eventNotice" class="notice"></div>
        <div class="steps">
          <div class="step active" data-step="1"></div>
          <div class="step" data-step="2"></div>
          <div class="step" data-step="3"></div>
          <div class="step" data-step="4"></div>
          <div class="step" data-step="5"></div>
        </div>
        <div class="step-content active" data-content="1">
          <input id="e_title"><input id="e_slug"><textarea id="e_description"></textarea>
          <input id="e_cover_image"><input id="e_accent"><select id="e_featured"><option value="0">0</option><option value="1">1</option></select>
        </div>
        <div class="step-content" data-content="2"><input id="e_event_date"><input id="e_city"><input id="e_venue"><input id="e_archive_year"></div>
        <div class="step-content" data-content="3">
          <select id="e_status"><option value="draft">draft</option><option value="published">published</option></select>
          <textarea id="e_ticket_instructions"></textarea><input id="e_ticket_qr"><input id="e_ticket_url">
        </div>
        <div class="step-content" data-content="4"><div id="tickets"></div></div>
        <div class="step-content" data-content="5"></div>
        <button id="prevBtn"></button><button id="nextBtn"></button><button id="cc-saveBtn"></button>
      </div>
    </div>
    <script>${contentCoreJs}</script>
    <script>window.BRVTALContentCore.mount(document.getElementById('root'));</script>
  </body></html>`;
}

test('Content Core keeps failed admin mutations away from the public API', async ({ page }) => {
  const adminMutations = [];
  const publicRequests = [];

  await page.route('**/discadmin/e2e-content-core-api-boundary.html', route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:harnessHtml(),
  }));

  await page.route('**/api/public/**', route => {
    publicRequests.push({ method:route.request().method(), url:route.request().url() });
    return route.fulfill({
      status:405,
      contentType:'application/json',
      body:JSON.stringify({ok:true,data:{error:'METHOD_NOT_ALLOWED'}}),
    });
  });

  await page.route('**/api/index.php/**', route => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    const method = request.method();

    if (path.endsWith('/auth') && method === 'GET') {
      return route.fulfill({contentType:'application/json',body:JSON.stringify({authenticated:true,csrf:'csrf-test'})});
    }
    if (path.endsWith('/events') && method === 'GET') {
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[{id:42,title:'BOUNDARY EVENT',status:'draft'}]})});
    }
    if (path.endsWith('/artists') && method === 'GET') {
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[]})});
    }
    if (path.endsWith('/ticket_types') && method === 'GET') {
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[]})});
    }
    if (path.endsWith('/events/42') && method === 'PUT') {
      adminMutations.push({method,url:request.url(),body:request.postData()});
      return route.fulfill({status:502,contentType:'text/html',body:'<html>upstream failure</html>'});
    }

    return route.fulfill({status:404,contentType:'application/json',body:JSON.stringify({ok:false,error:'UNEXPECTED_ROUTE'})});
  });

  await page.goto(harnessUrl);
  await page.waitForFunction(() => typeof window.BRVTALContentCore?.saveEvent === 'function');
  await page.evaluate(() => window.BRVTALContentCore.openEvent(42));
  await expect(page.locator('#tickets')).toHaveAttribute('data-load-state', 'ready');
  await page.fill('#e_title', 'CHANGED TITLE');

  const saved = await page.evaluate(() => window.BRVTALContentCore.saveEvent());

  expect(saved).toBe(false);
  expect(adminMutations).toHaveLength(1);
  expect(publicRequests).toHaveLength(0);
  expect(contentCoreJs).toContain("const API='/api/index.php'");
  expect(contentCoreJs).not.toContain("'/api/public'");
  await expect(page.locator('#eventNotice')).toContainText('Save failed: API 502 returned invalid JSON');
  await expect(page.locator('#cc-notice')).not.toContainText('Event saved.');
});
