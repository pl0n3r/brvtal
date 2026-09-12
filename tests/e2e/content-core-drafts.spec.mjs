import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const contentCoreJs = readFileSync(join(process.cwd(), 'discadmin/content-core.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/e2e-content-core-drafts.html';

function harnessHtml() {
  return `<!doctype html><html><body>
    <div id="root">
      <div id="cc-notice"></div>
      <input id="eventSearch"><div id="eventsTable"></div>
      <input id="artistSearch"><div id="rosterList"></div>
      <div id="eventArtists"></div><div id="artistDetail"></div>
      <div id="eventModal"></div><div id="eventHeading"></div><div id="eventNotice"></div>
      <input id="e_title"><input id="e_slug"><textarea id="e_description"></textarea>
      <input id="e_cover_image"><input id="e_accent"><select id="e_featured"><option value="0">0</option><option value="1">1</option></select>
      <input id="e_event_date" type="datetime-local"><input id="e_city"><input id="e_venue"><input id="e_archive_year">
      <select id="e_status"><option value="draft">draft</option><option value="published">published</option><option value="upcoming">upcoming</option></select>
      <textarea id="e_ticket_instructions"></textarea><input id="e_ticket_qr"><input id="e_ticket_url">
      <div id="tickets"></div>
    </div>
    <script>${contentCoreJs}</script>
    <script>window.BRVTALContentCore.mount(document.getElementById('root'));</script>
  </body></html>`;
}

async function installHarness(page, eventPosts) {
  await page.route('**/discadmin/e2e-content-core-drafts.html', route => route.fulfill({
    contentType: 'text/html',
    body: harnessHtml()
  }));

  await page.route('**/api/index.php/**', async route => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    const method = route.request().method();

    if (path.endsWith('/auth') && method === 'GET') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ authenticated:true, csrf:'csrf-test' }) });
    }
    if (path.endsWith('/events') && method === 'GET') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok:true, data:[] }) });
    }
    if (path.endsWith('/artists') && method === 'GET') {
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok:true, data:[] }) });
    }
    if (path.endsWith('/events') && method === 'POST') {
      eventPosts.push(JSON.parse(route.request().postData() || '{}'));
      return route.fulfill({ status:201, contentType:'application/json', body: JSON.stringify({ ok:true, id:101 }) });
    }

    return route.fulfill({ status:404, contentType:'application/json', body: JSON.stringify({ ok:false, error:'UNEXPECTED_ROUTE' }) });
  });

  await page.goto(harnessUrl);
  await page.waitForFunction(() => typeof window.BRVTALContentCore?.saveEvent === 'function');
}

test('Content Core saves an incomplete event while it remains draft', async ({ page }) => {
  const posts = [];
  await installHarness(page, posts);

  await page.fill('#e_title', 'QA CONTENT CORE - DELETE ME');
  await page.selectOption('#e_status', 'draft');
  await page.evaluate(() => window.BRVTALContentCore.saveEvent());

  await expect.poll(() => posts.length).toBe(1);
  expect(posts[0].title).toBe('QA CONTENT CORE - DELETE ME');
  expect(posts[0].status).toBe('draft');
  expect(posts[0].event_date).toBeNull();
  expect(posts[0].city).toBe('');
  await expect(page.locator('#eventNotice')).toContainText('Event saved.');
});

test('Content Core requires date and city before an event leaves draft', async ({ page }) => {
  const posts = [];
  await installHarness(page, posts);

  await page.fill('#e_title', 'QA PUBLIC EVENT');
  await page.selectOption('#e_status', 'published');
  await page.evaluate(() => window.BRVTALContentCore.saveEvent());

  expect(posts).toHaveLength(0);
  await expect(page.locator('#eventNotice')).toContainText('Name, date and city are required before leaving draft.');
});
