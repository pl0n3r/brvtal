import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const activityJs = readFileSync(join(process.cwd(), 'discadmin/admin-activity.js'), 'utf8');
const restoreJs = readFileSync(join(process.cwd(), 'discadmin/event-version-restore.js'), 'utf8');
const unsavedJs = readFileSync(join(process.cwd(), 'discadmin/admin-unsaved-changes.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/event-version-restore-e2e.html';

const currentEvent = Object.freeze({
  id: 9,
  title: 'CURRENT TITLE',
  description: 'Current copy',
  status: 'sold_out',
  published_at: '2026-10-05 01:00:00',
  cancelled_at: null,
  finished_at: null,
  ticket_types: [{id: 21, name: 'VIP'}],
  lineup: [{artist_id: 7, role: 'HEADLINER'}],
  timetable: [{id: 31, label: 'PL0N3R', start_at: '2026-10-05 03:00:00'}],
});

function historicalItems() {
  return [
    {
      id: 102,
      action: 'update',
      resource: 'events',
      resource_id: 9,
      resource_label: 'GENESIS',
      changed_fields: ['title','description'],
      before: {id:9,title:'HISTORICAL TITLE',description:'Historical copy',status:'draft',published_at:null},
      after: {id:9,title:'CURRENT TITLE',description:'Current copy',status:'sold_out',published_at:'2026-10-05 01:00:00'},
      created_at: '2026-10-05 02:00:00',
    },
    {
      id: 101,
      action: 'update',
      resource: 'events',
      resource_id: 9,
      resource_label: 'GENESIS',
      changed_fields: ['title','description','status'],
      before: {id:9,title:'ORIGINAL TITLE',description:'Original copy',status:'draft',published_at:null},
      after: {id:9,title:'HISTORICAL TITLE',description:'Historical copy',status:'draft',published_at:null},
      created_at: '2026-10-05 01:30:00',
    },
  ];
}

async function setupHarness(page) {
  let serverEvent = structuredClone(currentEvent);
  const historyItems = historicalItems();
  const putBodies = [];

  await page.route('**/api/admin-activity.php*', route => {
    const url = new URL(route.request().url());
    if (url.searchParams.get('history') !== '1') {
      return route.fulfill({
        contentType: 'application/json; charset=utf-8',
        body: JSON.stringify({ok:true,data:{items:[],total:0,limit:12,read_only:true}}),
      });
    }
    return route.fulfill({
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({
        ok:true,
        data:{
          items:historyItems,
          total:historyItems.length,
          limit:50,
          next_cursor:null,
          has_more:false,
          read_only:true,
          mode:'content_history',
        },
      }),
    });
  });

  await page.route('**/api/index.php/events/9', route => {
    expect(route.request().method()).toBe('PUT');
    const body = route.request().postDataJSON();
    putBodies.push(body);
    const before = {
      id:serverEvent.id,
      title:serverEvent.title,
      description:serverEvent.description,
      status:serverEvent.status,
      published_at:serverEvent.published_at,
    };
    serverEvent = {...serverEvent,...body};
    historyItems.unshift({
      id: 103,
      action: 'update',
      resource: 'events',
      resource_id: 9,
      resource_label: 'GENESIS',
      changed_fields: ['description','title'],
      before,
      after:{
        id:serverEvent.id,
        title:serverEvent.title,
        description:serverEvent.description,
        status:serverEvent.status,
        published_at:serverEvent.published_at,
      },
      created_at: '2026-10-05 02:30:00',
    });
    return route.fulfill({
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({ok:true,changed:1}),
    });
  });

  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><meta charset="utf-8"></head><body>
      <div id="eventModal" class="modal open" data-event-id="9">
        <input id="e_title" value="CURRENT TITLE">
        <textarea id="e_description">Current copy</textarea>
        <select id="e_status">
          <option value="draft">draft</option>
          <option value="sold_out" selected>sold_out</option>
        </select>
        <div id="tickets"><div class="ticket-row" data-id="21"><input value="VIP"></div></div>
        <div id="eventArtists"><div data-artist-id="7"><input value="HEADLINER"></div></div>
        <div id="eventTimetable"><div data-id="31"><input value="PL0N3R"></div></div>
        <button id="save" type="button">SAVE EVENT</button>
        <button id="cancel" type="button">CANCEL</button>
      </div>
      <script>
        var state={authed:true,section:'events'};
        window.BRVTALFeedback={error:function(message){window.__feedback=message;}};
      </script>
      <script>${unsavedJs}</script>
      <script>${restoreJs}</script>
      <script>${activityJs}</script>
      <script>
        window.__currentEvent=${JSON.stringify(currentEvent)};
        window.__serverEvent=structuredClone(window.__currentEvent);

        function editorSnapshot(){
          return {
            id:Number(window.__currentEvent?.id||0),
            title:document.getElementById('e_title').value,
            description:document.getElementById('e_description').value
          };
        }

        function applyServerEvent(){
          document.getElementById('e_title').value=window.__serverEvent.title;
          document.getElementById('e_description').value=window.__serverEvent.description;
          document.getElementById('e_status').value=window.__serverEvent.status;
        }

        window.__stageRestore=async function(item){
          const modal=document.getElementById('eventModal');
          const eventId=Number(window.__currentEvent?.id||0);
          if(eventId<1||!modal.classList.contains('open'))return false;
          if(Number(item?.resource_id||0)!==eventId)return false;
          const plan=window.BRVTALEventVersionRestore.buildPlan(item,editorSnapshot());
          if(!plan?.ok||!Array.isArray(plan.changes)||!plan.changes.length)return false;
          if(!window.confirm('Load historical Event fields into the editor? Nothing is saved automatically.'))return false;
          if(Number(window.__currentEvent?.id||0)!==eventId||!modal.classList.contains('open'))return false;
          const controls={title:'e_title',description:'e_description'};
          for(const change of plan.changes){
            const id=controls[change.field];
            if(!id)return false;
            const control=document.getElementById(id);
            control.value=change.to==null?'':String(change.to);
            control.dispatchEvent(new Event('input',{bubbles:true}));
            control.dispatchEvent(new Event('change',{bubbles:true}));
          }
          window.BRVTALUnsavedChanges.touch(modal);
          return true;
        };

        window.__openHistory=function(){
          return window.BRVTALAdminActivity.openHistory(
            'events',
            9,
            'GENESIS',
            {onRestore:item=>window.__stageRestore(item)}
          );
        };

        document.getElementById('save').addEventListener('click',async()=>{
          const payload={
            title:document.getElementById('e_title').value,
            description:document.getElementById('e_description').value,
            status:document.getElementById('e_status').value
          };
          const response=await fetch('/api/index.php/events/9',{
            method:'PUT',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(payload)
          });
          if(!response.ok)return;
          window.__serverEvent={...window.__serverEvent,...payload};
          window.__currentEvent={...window.__currentEvent,...payload};
          window.BRVTALUnsavedChanges.markClean(document.getElementById('eventModal'));
        });

        document.getElementById('cancel').addEventListener('click',()=>{
          const modal=document.getElementById('eventModal');
          window.BRVTALUnsavedChanges.requestClose(modal,()=>modal.classList.remove('open'));
        });

        window.__reopen=function(){
          const modal=document.getElementById('eventModal');
          window.__currentEvent=structuredClone(window.__serverEvent);
          applyServerEvent();
          modal.classList.add('open');
          window.BRVTALUnsavedChanges.begin(modal);
        };

        window.BRVTALUnsavedChanges.begin(document.getElementById('eventModal'));
      </script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);
  return {historyItems, putBodies, serverEvent:()=>serverEvent};
}

test('stages an older Event version without persisting until canonical Save and records new history', async ({ page }) => {
  const {putBodies, historyItems} = await setupHarness(page);

  await page.evaluate(() => window.__openHistory());
  await expect(page.getByRole('dialog', {name:'Editorial version history'})).toBeVisible();
  await page.locator('[data-history-index="1"]').click();

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', {name:'LOAD INTO EDITOR'}).click();

  await expect(page.locator('#e_title')).toHaveValue('HISTORICAL TITLE');
  await expect(page.locator('#e_description')).toHaveValue('Historical copy');
  await expect(page.locator('#e_status')).toHaveValue('sold_out');
  await expect(page.locator('#tickets')).toContainText('VIP');
  await expect(page.locator('#eventArtists')).toContainText('HEADLINER');
  await expect(page.locator('#eventTimetable')).toContainText('PL0N3R');
  expect(putBodies).toHaveLength(0);
  expect(historyItems).toHaveLength(2);
  expect(await page.evaluate(() => window.BRVTALUnsavedChanges.isDirty(document.getElementById('eventModal')))).toBe(true);

  await page.getByRole('button', {name:'SAVE EVENT'}).click();
  await expect.poll(() => putBodies.length).toBe(1);
  expect(putBodies[0]).toEqual({
    title:'HISTORICAL TITLE',
    description:'Historical copy',
    status:'sold_out',
  });
  expect(putBodies[0]).not.toHaveProperty('published_at');
  expect(putBodies[0]).not.toHaveProperty('ticket_types');
  expect(putBodies[0]).not.toHaveProperty('lineup');
  expect(putBodies[0]).not.toHaveProperty('timetable');
  expect(historyItems).toHaveLength(3);

  await page.evaluate(() => window.__openHistory());
  await expect(page.getByRole('dialog', {name:'Editorial version history'})).toContainText('3 recorded versions');
  await expect(page.locator('[data-history-diff]')).toContainText('CURRENT TITLE');
  await expect(page.locator('[data-history-diff]')).toContainText('HISTORICAL TITLE');
});

test('wrong, stale, closed, incompatible and empty restore plans fail closed without mutation', async ({ page }) => {
  const {putBodies} = await setupHarness(page);
  const result = await page.evaluate(async () => {
    const originalTitle=document.getElementById('e_title').value;
    const older={
      id:201,action:'update',resource:'events',resource_id:9,
      after:{id:9,title:'OTHER TITLE',description:'Other copy'}
    };

    const wrong=await window.__stageRestore({...older,resource_id:8,after:{id:8,title:'WRONG'}});
    window.__currentEvent={...window.__currentEvent,id:10};
    const stale=await window.__stageRestore(older);
    window.__currentEvent={...window.__currentEvent,id:9};
    document.getElementById('eventModal').classList.remove('open');
    const closed=await window.__stageRestore(older);
    document.getElementById('eventModal').classList.add('open');
    const incompatible=window.BRVTALEventVersionRestore.buildPlan(
      {...older,after:{id:9,title:'OTHER TITLE',metadata:{nested:true}}},
      {id:9,title:originalTitle,description:'Current copy'}
    );
    const empty=window.BRVTALEventVersionRestore.buildPlan(
      {...older,after:{id:9,title:originalTitle,description:'Current copy'}},
      {id:9,title:originalTitle,description:'Current copy'}
    );
    window.__currentEvent={...window.__currentEvent,id:0};
    const fresh=await window.__stageRestore(older);

    return {
      wrong,stale,closed,fresh,
      incompatible:incompatible.code,
      empty:empty.code,
      title:document.getElementById('e_title').value
    };
  });

  expect(result).toEqual({
    wrong:false,
    stale:false,
    closed:false,
    fresh:false,
    incompatible:'INCOMPATIBLE_SNAPSHOT',
    empty:'NO_SAFE_CHANGES',
    title:'CURRENT TITLE',
  });
  expect(putBodies).toHaveLength(0);
});

test('cancel discards a staged restore while lifecycle and relations stay current', async ({ page }) => {
  const {putBodies} = await setupHarness(page);

  await page.evaluate(() => window.__openHistory());
  await page.locator('[data-history-index="1"]').click();
  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', {name:'LOAD INTO EDITOR'}).click();

  await expect(page.locator('#e_title')).toHaveValue('HISTORICAL TITLE');
  await expect(page.locator('#e_status')).toHaveValue('sold_out');
  await expect(page.locator('#tickets')).toContainText('VIP');
  await expect(page.locator('#eventArtists')).toContainText('HEADLINER');
  await expect(page.locator('#eventTimetable')).toContainText('PL0N3R');

  page.once('dialog', dialog => dialog.accept());
  await page.getByRole('button', {name:'CANCEL'}).click();
  await expect(page.locator('#eventModal')).not.toHaveClass(/open/);
  expect(putBodies).toHaveLength(0);

  await page.evaluate(() => window.__reopen());
  await expect(page.locator('#e_title')).toHaveValue('CURRENT TITLE');
  await expect(page.locator('#e_description')).toHaveValue('Current copy');
  await expect(page.locator('#e_status')).toHaveValue('sold_out');
  await expect(page.locator('#tickets')).toContainText('VIP');
  await expect(page.locator('#eventArtists')).toContainText('HEADLINER');
  await expect(page.locator('#eventTimetable')).toContainText('PL0N3R');
});
