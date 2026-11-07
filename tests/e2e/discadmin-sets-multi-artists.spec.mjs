import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const index = readFileSync(join(root,'discadmin/index-core.php'),'utf8');
const picker = 'function selectedSetArtistIds() {' + index.split('function selectedSetArtistIds() {',2)[1].split('function setForm(r) {',1)[0];
const ordering = readFileSync(join(root,'discadmin/content-ordering.js'),'utf8');
const orderingCss = readFileSync(join(root,'discadmin/content-ordering.css'),'utf8');
const bulk = readFileSync(join(root,'discadmin/bulk-actions.js'),'utf8');

test('multi-DJ selection, chips, keyboard and search work at 390/1440', async ({page}) => {
  for (const width of [390,1440]) {
    await page.setViewportSize({width,height:900});
    await page.goto('data:text/html,<!doctype html><html><body><div id="chooser"></div></body></html>');
    await page.addScriptTag({content: `
      const state={artists:[{id:2,name:'DJ Uno'},{id:4,name:'DJ Dos'},{id:7,name:'DJ Tres'}]};
      const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
      ${picker}
      document.getElementById('chooser').innerHTML=setArtistsPickerMarkup({artist_ids:[7,4]});
      bindSetArtistPicker();
    `});
    await expect(page.locator('.set-artist-chip')).toHaveCount(2);
    expect(await page.evaluate(() => selectedSetArtistIds())).toEqual([7,4]);
    await page.locator('#setArtistSearch').fill('Tres');
    await expect(page.locator('.set-artist-choice:not([hidden])')).toHaveCount(1);
    const chosen = page.locator('.set-artist-choice:not([hidden]) input');
    await chosen.uncheck();
    await chosen.check();
    expect(await page.evaluate(() => selectedSetArtistIds())).toEqual([4,7]);
    await page.locator('#setArtistSearch').fill('');
    await page.locator('#setArtistChoices input[value="2"]').check();
    expect(await page.evaluate(() => selectedSetArtistIds())).toEqual([4,7,2]);
    await page.getByRole('button',{name:'Remove DJ Dos'}).click();
    expect(await page.evaluate(() => selectedSetArtistIds())).toEqual([7,2]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth<=document.documentElement.clientWidth+1)).toBe(true);
  }
});

test('Sets visual up/down controls honor boundaries and rollback on request failure', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await page.setContent('<!doctype html><html><head><style>'+orderingCss+'</style></head><body><div class="table"><div id="rows" data-order-resource="sets" data-order-enabled="1">'+
    [1,2,3].map(i=>'<div data-order-id="'+i+'" class="tr"><div class="title">Set '+i+'</div><span data-order-position></span></div>').join('')+
    '</div></div></body></html>');
  await page.evaluate(() => {
    window.requests=[];window.failOrder=false;
    window.BRVTALAdminAuthBoundary={csrfToken:async()=>'csrf-test'};
    window.BRVTALFeedback={success(){},error(){}};
    window.fetch=async(url,opts) => {
      window.requests.push(JSON.parse(opts.body));
      return new Response(JSON.stringify(window.failOrder?{ok:false,error:'ORDER_SAVE_FAILED'}:{ok:true}),
        {status:window.failOrder?500:200,headers:{'Content-Type':'application/json'}});
    };
  });
  await page.addScriptTag({content:ordering});
  const rows=page.locator('#rows > [data-order-id]');
  await expect(rows).toHaveCount(3);
  await expect(page.locator('[data-order-id="1"] [data-order-move="up"]')).toBeDisabled();
  await page.locator('[data-order-id="1"] [data-order-move="down"]').click();
  await expect.poll(()=>page.evaluate(()=>window.requests.length)).toBe(1);
  expect(await rows.evaluateAll(nodes=>nodes.map(n=>Number(n.dataset.orderId)))).toEqual([2,1,3]);
  expect(await page.evaluate(()=>window.requests[0])).toEqual({resource:'sets',ids:[2,1,3],previous_ids:[1,2,3]});
  await page.evaluate(()=>{window.failOrder=true});
  await page.locator('[data-order-id="1"] [data-order-move="down"]').click();
  await expect.poll(()=>page.evaluate(()=>window.requests.length)).toBe(2);
  await expect.poll(()=>rows.evaluateAll(nodes=>nodes.map(n=>Number(n.dataset.orderId)))).toEqual([2,1,3]);
  await expect(page.locator('.content-order-status')).toContainText('Could not save order');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth+1)).toBe(true);
});

test('Sets bulk delete confirms IDs and names, includes CSRF and respects reference failures', async ({page}) => {
  await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>'+
    '<div class="nav"><button class="active" onclick="go(\'sets\')">SETS</button></div><div class="main"><div class="top"><h1>SETS</h1><span class="status">ONLINE</span></div></div></body></html>');
  await page.evaluate(() => {
    window.saved=[];window.confirmMessages=[];window.failDelete=true;window.refreshes=0;
    window.confirm=message=>{window.confirmMessages.push(message);return true;};
    window.BRVTALAdminAuthBoundary={csrfToken:async()=>'csrf-delete'};
    window.BRVTALFeedback={success(){},error(){}};
    window.BRVTALDataGrid={clearSelection(){}};
    window.go=async()=>{window.refreshes++};
    window.fetch=async(url,opts={}) => {
      if(String(url).includes('bulk-catalog.php')) {
        return new Response(JSON.stringify({ok:true,data:{resource:'sets',q:'',items:[
          {id:3,label:'Sunny Vibes',status:'published',slug:'sunny-vibes'},
          {id:4,label:'Halloween',status:'draft',slug:'halloween'}
        ],pagination:{limit:50,returned:2,total:2,has_more:false,next_cursor:null,
          snapshot_complete:true,range:{after_id:0,last_id:4}}}}),
          {status:200,headers:{'Content-Type':'application/json'}});
      }
      const body=JSON.parse(opts.body||'{}');
      window.saved.push({body,headers:opts.headers});
      return new Response(JSON.stringify(window.failDelete?{ok:false,error:'BULK_SET_REFERENCED'}:
        {ok:true,data:{deleted:2,matched:2,ids:[3,4]}}),
        {status:window.failDelete?409:200,headers:{'Content-Type':'application/json'}});
    };
  });
  await page.addScriptTag({content:bulk});
  await page.getByRole('button',{name:'Open bulk actions for SETS'}).click();
  const dialog=page.getByRole('dialog');
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button',{name:'SELECT PAGE'}).click();
  await dialog.getByRole('button',{name:'DELETE SELECTED'}).click();
  await expect.poll(()=>page.evaluate(()=>window.saved.length)).toBe(1);
  await expect(dialog).toBeVisible();
  await expect(page.locator('.brvtal-bulk-count')).toHaveText('2 SELECTED');
  expect(await page.evaluate(()=>window.confirmMessages[0])).toContain('Sunny Vibes');
  expect(await page.evaluate(()=>window.saved[0].body)).toEqual({action:'delete_selected',resource:'sets',ids:[3,4]});
  expect(await page.evaluate(()=>window.saved[0].headers['X-CSRF-Token'])).toBe('csrf-delete');
  await page.evaluate(()=>{window.failDelete=false});
  await dialog.getByRole('button',{name:'DELETE SELECTED'}).click();
  await expect.poll(()=>page.evaluate(()=>window.refreshes)).toBe(1);
  await expect(dialog).toBeHidden();
});
