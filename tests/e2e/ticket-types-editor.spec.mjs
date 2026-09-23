import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const contentCoreJs = readFileSync(join(process.cwd(), 'discadmin/content-core.js'), 'utf8');
const eventWorkflowJs = readFileSync(join(process.cwd(), 'discadmin/event-workflow.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/e2e-ticket-types-editor.html';

function harnessHtml() {
  return `<!doctype html><html><body>
    <div id="root" data-admin-module="content-core">
      <div id="cc-notice"></div><input id="eventSearch"><div id="eventsTable"></div>
      <input id="artistSearch"><div id="rosterList"></div><div id="artistDetail"></div>
      <div id="eventArtists" data-load-state="ready"></div>
      <div id="eventModal" class="modal"><div id="eventHeading"></div><div id="eventNotice" class="notice"></div>
        <div class="step active" data-step="1"></div><div class="step" data-step="2"></div><div class="step" data-step="3"></div><div class="step" data-step="4"></div><div class="step" data-step="5"></div>
        <div class="step-content active" data-content="1"><input id="e_title"><input id="e_slug"><textarea id="e_description"></textarea><input id="e_cover_image"><input id="e_accent"><select id="e_featured"><option value="0">0</option></select></div>
        <div class="step-content" data-content="2"><input id="e_event_date"><input id="e_city"><input id="e_venue"><input id="e_archive_year"></div>
        <div class="step-content" data-content="3"><select id="e_status"><option value="draft">draft</option></select><textarea id="e_ticket_instructions"></textarea><input id="e_ticket_qr"><input id="e_ticket_url"></div>
        <div class="step-content" data-content="4"><div id="tickets" data-load-state="ready"></div></div>
        <div class="step-content" data-content="5"></div>
        <button id="prevBtn"></button><button id="nextBtn"></button><button id="cc-saveBtn">SAVE</button><button id="cc-top-saveBtn">SAVE EVENT</button>
      </div>
    </div>
    <script>${contentCoreJs}</script><script>${eventWorkflowJs}</script>
    <script>window.BRVTALContentCore.mount(document.getElementById('root'));</script>
  </body></html>`;
}

test('Ticket Types editor sends complete metadata and blocks inverted availability', async ({ page }) => {
  const workflows = [];
  let savedEvent = null;

  await page.route('**/discadmin/e2e-ticket-types-editor.html', route => route.fulfill({contentType:'text/html; charset=utf-8',body:harnessHtml()}));
  await page.route('**/api/index.php/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/auth')) return route.fulfill({contentType:'application/json',body:JSON.stringify({authenticated:true,csrf:'csrf-ticket'})});
    if (path.endsWith('/events')) return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:savedEvent?[savedEvent]:[]})});
    if (path.endsWith('/artists')) return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[]})});
    if (path.endsWith('/ticket_types')) return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[]})});
    if (path.includes('/lineup')) return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[]})});
    return route.fulfill({status:404,contentType:'application/json',body:JSON.stringify({ok:false,error:'UNEXPECTED_ROUTE'})});
  });
  await page.route('**/api/event-workflow.php', async route => {
    const body = JSON.parse(route.request().postData() || '{}');
    workflows.push(body);
    savedEvent = {...body.event,id:88};
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{event:savedEvent,ticket_types:body.ticket_types,lineup:[]}})});
  });

  await page.goto(harnessUrl);
  await page.waitForFunction(() => typeof window.BRVTALContentCore?.saveEvent === 'function');
  await page.evaluate(() => window.BRVTALContentCore.openEvent());
  await page.fill('#e_title', 'TICKET CONTRACT');
  await page.evaluate(() => window.BRVTALContentCore.addTicket());

  const ticket = page.locator('#tickets .ticket-row').first();
  await ticket.locator('[data-k="name"]').fill('PREVENTA');
  await ticket.locator('[data-k="description"]').fill('Ingreso general antes de medianoche.');
  await ticket.locator('[data-k="price"]').fill('20000');
  await ticket.locator('[data-k="currency"]').fill('cop');
  await ticket.locator('[data-k="status"]').selectOption('active');
  await ticket.locator('[data-k="external_url"]').fill('https://tickets.example/preventa');
  await ticket.locator('[data-k="payment_instructions"]').fill('Paga por Nequi y conserva el comprobante.');
  await ticket.locator('[data-k="qr_image"]').fill('/uploads/qr/preventa.webp');
  await ticket.locator('[data-k="available_from"]').fill('2026-10-01T08:00');
  await ticket.locator('[data-k="available_until"]').fill('2026-09-30T23:00');

  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(false);
  expect(workflows).toHaveLength(0);
  await expect(page.locator('#eventNotice')).toContainText('availability end must not be before its start');

  await ticket.locator('[data-k="available_until"]').fill('2026-10-31T20:00');
  expect(await page.evaluate(() => window.BRVTALContentCore.saveEvent())).toBe(true);
  expect(workflows).toHaveLength(1);
  expect(workflows[0].ticket_types).toEqual([{
    name:'PREVENTA',
    description:'Ingreso general antes de medianoche.',
    price:'20000',
    currency:'COP',
    status:'active',
    external_url:'https://tickets.example/preventa',
    payment_instructions:'Paga por Nequi y conserva el comprobante.',
    qr_image:'/uploads/qr/preventa.webp',
    available_from:'2026-10-01T08:00',
    available_until:'2026-10-31T20:00',
    sort_order:0,
  }]);
});
