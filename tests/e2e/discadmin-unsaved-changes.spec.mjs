import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const source = readFileSync(join(process.cwd(),'discadmin/admin-unsaved-changes.js'),'utf8');
const adminModulesSource = readFileSync(join(process.cwd(),'discadmin/admin-modules.js'),'utf8');

async function harness(page) {
  await page.setContent(`<!doctype html><html><body>
    <div id="modal" class="modal open">
      <input id="title" value="Initial">
      <div id="lineupCurrent"><div class="lineitem" data-id="1"><input class="role" value="OPEN"></div></div>
    </div>
    <div id="eventModal" class="modal">
      <input id="e_title" value="Event">
      <div id="tickets"><div class="ticket-row"><input value="General"></div></div>
    </div>
  </body></html>`);
  await page.addScriptTag({content:source});
  await page.evaluate(() => BRVTALUnsavedChanges.syncRoots());
}

test('unchanged legacy editor closes without a discard prompt', async ({page}) => {
  await harness(page);
  await page.evaluate(() => {
    window.__confirmCalls=0;
    window.confirm=()=>{window.__confirmCalls++;return false;};
    const modal=document.getElementById('modal');
    window.__closed=BRVTALUnsavedChanges.requestClose(modal,()=>modal.classList.remove('open'));
  });
  expect(await page.evaluate(()=>window.__closed)).toBe(true);
  expect(await page.evaluate(()=>window.__confirmCalls)).toBe(0);
});

test('dirty legacy editor blocks discard when confirmation is rejected', async ({page}) => {
  await harness(page);
  await page.locator('#title').fill('Changed');
  await expect(page.locator('#modal')).toHaveAttribute('data-unsaved-changes','dirty');
  await page.evaluate(() => {
    window.__confirmCalls=0;
    window.confirm=()=>{window.__confirmCalls++;return false;};
    const modal=document.getElementById('modal');
    window.__closed=BRVTALUnsavedChanges.requestClose(modal,()=>modal.classList.remove('open'));
  });
  expect(await page.evaluate(()=>window.__closed)).toBe(false);
  await expect(page.locator('#modal')).toHaveClass(/open/);
  expect(await page.evaluate(()=>window.__confirmCalls)).toBe(1);
});

test('accepted discard closes the dirty editor', async ({page}) => {
  await harness(page);
  await page.locator('#title').fill('Changed');
  await page.evaluate(() => {
    window.confirm=()=>true;
    const modal=document.getElementById('modal');
    window.__closed=BRVTALUnsavedChanges.requestClose(modal,()=>modal.classList.remove('open'));
  });
  expect(await page.evaluate(()=>window.__closed)).toBe(true);
  await expect(page.locator('#modal')).not.toHaveClass(/open/);
});

test('lineup structural edits count as unsaved changes', async ({page}) => {
  await harness(page);
  await page.evaluate(() => {
    const modal=document.getElementById('modal');
    BRVTALUnsavedChanges.touch(modal);
    document.querySelector('#lineupCurrent .lineitem').remove();
  });
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('modal')))).toBe(true);
});

test('Content Core async hydration can settle before user edits, then detects tickets', async ({page}) => {
  await harness(page);
  await page.evaluate(() => {
    const modal=document.getElementById('eventModal');
    modal.classList.add('open');
    BRVTALUnsavedChanges.syncRoots();
    document.getElementById('tickets').innerHTML='<div class="ticket-row"><input value="VIP"></div>';
  });
  await page.waitForTimeout(0);
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('eventModal')))).toBe(false);
  await page.locator('#eventModal #e_title').fill('Changed event');
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('eventModal')))).toBe(true);
});

test('successful save baseline prevents a false warning', async ({page}) => {
  await harness(page);
  await page.locator('#title').fill('Saved value');
  await page.evaluate(() => BRVTALUnsavedChanges.markClean(document.getElementById('modal')));
  await page.evaluate(() => {
    window.__confirmCalls=0;
    window.confirm=()=>{window.__confirmCalls++;return false;};
    const modal=document.getElementById('modal');
    window.__closed=BRVTALUnsavedChanges.requestClose(modal,()=>modal.classList.remove('open'));
  });
  expect(await page.evaluate(()=>window.__closed)).toBe(true);
  expect(await page.evaluate(()=>window.__confirmCalls)).toBe(0);
});

test('dirty editor blocks workspace navigation and keeps the baseline active', async ({page}) => {
  await harness(page);
  await page.locator('#title').fill('Changed before navigation');
  await page.evaluate(() => {
    window.__confirmCalls=0;
    window.confirm=()=>{window.__confirmCalls++;return false;};
    window.__navigationAccepted=BRVTALUnsavedChanges.requestNavigation('blog');
  });
  expect(await page.evaluate(()=>window.__navigationAccepted)).toBe(0);
  expect(await page.evaluate(()=>window.__confirmCalls)).toBe(1);
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('modal')))).toBe(true);
});

test('accepted navigation locks dirty editors and failed navigation unlocks them', async ({page}) => {
  await harness(page);
  await page.locator('#title').fill('Pending navigation');
  await page.evaluate(() => {
    window.confirm=()=>true;
    window.__accepted=BRVTALUnsavedChanges.requestNavigation('blog');
  });
  expect(await page.evaluate(()=>Number.isInteger(window.__accepted))).toBe(true);
  expect(await page.evaluate(()=>document.getElementById('modal').inert)).toBe(true);
  await page.evaluate(() => BRVTALUnsavedChanges.cancelNavigation(window.__accepted));
  expect(await page.evaluate(()=>document.getElementById('modal').inert)).toBe(false);
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('modal')))).toBe(true);
});

test('navigation commit refuses a root changed after confirmation', async ({page}) => {
  await harness(page);
  await page.locator('#title').fill('Confirmed snapshot');
  await page.evaluate(() => {
    window.confirm=()=>true;
    window.__token=BRVTALUnsavedChanges.requestNavigation('blog');
    document.getElementById('title').value='Programmatic late change';
    window.__committed=BRVTALUnsavedChanges.commitNavigation(window.__token);
  });
  expect(await page.evaluate(()=>window.__committed)).toBe(false);
  expect(await page.evaluate(()=>document.getElementById('modal').inert)).toBe(false);
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('modal')))).toBe(true);
});

test('stale navigation tokens cannot release a newer navigation lock', async ({page}) => {
  await harness(page);
  await page.locator('#title').fill('Concurrent navigation');
  await page.evaluate(() => {
    window.confirm=()=>true;
    window.__firstToken=BRVTALUnsavedChanges.requestNavigation('media');
    window.__secondToken=BRVTALUnsavedChanges.requestNavigation('logout');
    window.__staleCancel=BRVTALUnsavedChanges.cancelNavigation(window.__firstToken);
    window.__staleCommit=BRVTALUnsavedChanges.commitNavigation(window.__firstToken);
  });
  expect(await page.evaluate(()=>window.__secondToken > window.__firstToken)).toBe(true);
  expect(await page.evaluate(()=>window.__staleCancel)).toBe(false);
  expect(await page.evaluate(()=>window.__staleCommit)).toBe(null);
  expect(await page.evaluate(()=>document.getElementById('modal').inert)).toBe(true);
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('modal')))).toBe(true);
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.commitNavigation(window.__secondToken))).toBe(true);
  expect(await page.evaluate(()=>document.getElementById('modal').inert)).toBe(false);
});

test('accepted workspace navigation ends every tracked editor only after commit', async ({page}) => {
  await harness(page);
  await page.locator('#title').fill('Legacy change');
  await page.evaluate(() => {
    const eventModal=document.getElementById('eventModal');
    eventModal.classList.add('open');
    BRVTALUnsavedChanges.syncRoots();
  });
  await page.locator('#eventModal #e_title').fill('Content Core change');
  await page.evaluate(() => {
    window.confirm=()=>true;
    window.__accepted=BRVTALUnsavedChanges.requestNavigation('media');
  });
  expect(await page.evaluate(()=>Number.isInteger(window.__accepted))).toBe(true);
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('modal')))).toBe(true);
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('eventModal')))).toBe(true);
  await page.evaluate(() => BRVTALUnsavedChanges.commitNavigation(window.__accepted));
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('modal')))).toBe(false);
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('eventModal')))).toBe(false);
});

test('failed production save stays dirty while successful production save clears it', async ({page}) => {
  await harness(page);
  await page.evaluate(() => {
    const modal=document.getElementById('modal');
    modal.innerHTML='<input id="f_title" value="Initial"><input id="f_slug" value="initial"><button id="saveBtn">SAVE</button>';
    BRVTALUnsavedChanges.begin(modal);
    window.state={rows:[],editing:null};
    window.csrf='test-csrf';
    window.closeModal=force=>BRVTALUnsavedChanges.requestClose(modal,()=>modal.classList.remove('open'),{force});
    window.go=async()=>true;
    window.show=()=>{};
    window.readableError=()=> 'SAVE FAILED';
  });
  await page.addScriptTag({content:adminModulesSource});
  await page.locator('#f_title').fill('Pending save');
  await page.evaluate(() => {
    window.req=async()=>{throw new Error('SAVE_FAILED');};
  });
  await page.evaluate(() => window.save('pages',1));
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('modal')))).toBe(true);
  await expect(page.locator('#modal')).toHaveClass(/open/);

  await page.evaluate(() => {
    window.req=async()=>({ok:true});
  });
  await page.evaluate(() => window.save('pages',1));
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('modal')))).toBe(false);
  await expect(page.locator('#modal')).not.toHaveClass(/open/);
});

test('lineup, release and blog-style structural controls remain part of the shared baseline', async ({page}) => {
  await harness(page);
  await page.evaluate(() => {
    const modal=document.getElementById('modal');
    const content=document.createElement('div');
    content.innerHTML='<div data-artist-id="7"><input data-field="role" value="Primary"></div><div data-id="9"><input data-k="status" value="draft"></div>';
    modal.appendChild(content);
    BRVTALUnsavedChanges.markClean(modal);
  });
  await page.locator('[data-artist-id="7"] input').fill('Guest');
  expect(await page.evaluate(()=>BRVTALUnsavedChanges.isDirty(document.getElementById('modal')))).toBe(true);
});

