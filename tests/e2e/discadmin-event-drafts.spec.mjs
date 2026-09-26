import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const editorDraftsJs = readFileSync(join(process.cwd(), 'discadmin/editor-drafts.js'), 'utf8');
const legacyDraftsJs = readFileSync(join(process.cwd(), 'discadmin/legacy-editor-drafts.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/event-drafts-e2e.html';

const seedEvent = {
  id:3,
  title:'BRVTAL NIGHT',
  slug:'brvtal-night',
  description:'Server event description',
  cover_image:'/uploads/media/event.jpg',
  accent:'#ff2038',
  featured:1,
  event_date:'2026-10-30 22:00:00',
  city:'Pereira',
  venue:'Warehouse',
  archive_year:2026,
  status:'published',
  ticket_instructions:'Door policy',
  ticket_qr:'/uploads/media/ticket-qr.png',
  ticket_url:'https://tickets.example/event',
  updated_at:'2026-09-26 21:10:00'
};

function harness(record = seedEvent) {
  return `<!doctype html><html><body>
    <div class="modal open" id="eventModal">
      <form id="eventForm">
        <input id="e_title" value="${record.title}">
        <input id="e_slug" value="${record.slug}">
        <textarea id="e_description">${record.description}</textarea>
        <input id="e_cover_image" value="${record.cover_image}">
        <input id="e_accent" value="${record.accent}">
        <select id="e_featured"><option value="0">No</option><option value="1" selected>Yes</option></select>
        <input id="e_event_date" value="2026-10-30T22:00">
        <input id="e_city" value="${record.city}">
        <input id="e_venue" value="${record.venue}">
        <input id="e_archive_year" value="${record.archive_year}">
        <select id="e_status"><option value="draft">Draft</option><option value="published" selected>Published</option></select>
        <textarea id="e_ticket_instructions">${record.ticket_instructions}</textarea>
        <input id="e_ticket_qr" value="${record.ticket_qr}">
        <input id="e_ticket_url" value="${record.ticket_url}">
        <div id="tickets" data-load-state="ready"></div>
        <div id="eventArtists" data-load-state="ready">
          <label><input type="checkbox" data-artist="7" checked>PL0N3R</label>
          <label><input type="checkbox" data-artist="8">OTHER</label>
        </div>
      </form>
    </div>
    <script>
      window.__touches=0;window.__clean=0;
      window.BRVTALUnsavedChanges={
        touch(){window.__touches+=1},
        markClean(){window.__clean+=1}
      };
      window.BRVTALAdminColorField={sync(){}};
      window.BRVTALContentCore={
        addTicket(ticket={}){
          const row=document.createElement('div');
          row.className='ticket-row';
          if(ticket.id)row.dataset.id=String(ticket.id);
          row.innerHTML=
            '<input data-k="name" value="'+String(ticket.name||'')+'">'+
            '<textarea data-k="description">'+String(ticket.description||'')+'</textarea>'+
            '<input data-k="price" value="'+String(ticket.price??'')+'">'+
            '<input data-k="currency" value="'+String(ticket.currency||'COP')+'">'+
            '<select data-k="status"><option value="active">active</option><option value="draft">draft</option></select>'+
            '<input data-k="external_url" value="'+String(ticket.external_url||'')+'">'+
            '<textarea data-k="payment_instructions">'+String(ticket.payment_instructions||'')+'</textarea>'+
            '<input data-k="qr_image" value="'+String(ticket.qr_image||'')+'">'+
            '<input data-k="available_from" value="'+String(ticket.available_from||'')+'">'+
            '<input data-k="available_until" value="'+String(ticket.available_until||'')+'">';
          row.querySelector('[data-k="status"]').value=ticket.status||'active';
          document.getElementById('tickets').appendChild(row);
        }
      };
      window.BRVTALContentCore.addTicket({
        id:4,name:'Pre-sale',description:'Early access',price:'25000',currency:'COP',
        status:'active',external_url:'https://tickets.example/pre',payment_instructions:'Transfer',
        qr_image:'/uploads/media/pre-qr.png',available_from:'2026-09-01T00:00',
        available_until:'2026-10-01T00:00'
      });
    </script>
    <script>${editorDraftsJs}</script>
    <script>${legacyDraftsJs}</script>
    <script>
      window.__eventRecord=${JSON.stringify(record)};
      void window.BRVTALLegacyDrafts.bind('events',3,window.__eventRecord);
    </script>
  </body></html>`;
}

async function loadHarness(page) {
  await page.route(harnessUrl, route => route.fulfill({contentType:'text/html',body:harness()}));
  await page.goto(harnessUrl);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Saved to server');
}

test('Events autosaves and restores event, ticket and roster state', async ({page}) => {
  await loadHarness(page);
  await page.locator('#e_description').fill('Recovered event description');
  await page.locator('#tickets [data-k="price"]').fill('30000');
  await page.locator('#eventArtists [data-artist="7"]').uncheck();
  await page.locator('#eventArtists [data-artist="8"]').check();

  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  const stored=await page.evaluate(async () => (await BRVTALDrafts.load('events','3'))?.data);
  expect(stored.description).toBe('Recovered event description');
  expect(stored.tickets[0].price).toBe('30000');
  expect(stored.lineup).toEqual([{artist_id:8}]);

  await page.reload();
  await expect(page.locator('[data-legacy-draft-recovery]')).toBeVisible();
  await page.locator('[data-legacy-draft-restore]').click();
  await expect(page.locator('#e_description')).toHaveValue('Recovered event description');
  await expect(page.locator('#tickets [data-k="price"]')).toHaveValue('30000');
  await expect(page.locator('#eventArtists [data-artist="8"]')).toBeChecked();
  await expect(page.locator('#eventArtists [data-artist="7"]')).not.toBeChecked();
});

test('Events reports server revision drift before restore', async ({page}) => {
  await loadHarness(page);
  await page.evaluate(async () => {
    const data=BRVTALLegacyDrafts.snapshot('events');
    data.title='Local conflicting event';
    await BRVTALDrafts.save('events','3',{base_revision:'2026-09-25 10:00:00',data});
  });
  await page.reload();
  const recovery=page.locator('[data-legacy-draft-recovery]');
  await expect(recovery).toBeVisible();
  await expect(recovery).toHaveAttribute('data-conflict','1');
  await expect(recovery).toContainText('SERVER CHANGED');
});

test('Events updates base revision after a successful Save that keeps newer edits', async ({page}) => {
  await loadHarness(page);
  await page.locator('#e_description').fill('Submitted event description');
  const submitted=await page.evaluate(() => BRVTALLegacyDrafts.snapshot('events'));
  await page.locator('#e_description').fill('Newer event description');

  const result=await page.evaluate(async payload => BRVTALLegacyDrafts.serverSaved({
    type:'events',
    id:3,
    payload,
    result:{id:3,data:{id:3,updated_at:'2026-09-26 21:20:00'}}
  }),submitted);

  expect(result.keepOpen).toBe(true);
  const stored=await page.evaluate(async () => BRVTALDrafts.load('events','3'));
  expect(stored.base_revision).toBe('2026-09-26 21:20:00');
  expect(stored.data.description).toBe('Newer event description');
});

test('Events failed Save keeps the complete recoverable draft', async ({page}) => {
  await loadHarness(page);
  await page.locator('#e_description').fill('Retry event Save');
  await page.locator('#tickets [data-k="price"]').fill('41000');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');

  const kept=await page.evaluate(() => BRVTALLegacyDrafts.saveFailed({type:'events',id:3}));
  expect(kept).toBe(true);
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Save failed · local draft kept');
  const stored=await page.evaluate(async () => (await BRVTALDrafts.load('events','3'))?.data);
  expect(stored.tickets[0].price).toBe('41000');
});

test('Events drafts are removed at the session boundary', async ({page}) => {
  await loadHarness(page);
  await page.locator('#e_description').fill('Session-bound event draft');
  await expect(page.locator('[data-legacy-draft-state]')).toContainText('Draft saved locally');
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:auth-required')));
  await expect.poll(() => page.evaluate(() => BRVTALDrafts.load('events','3'))).toBe(null);
});
