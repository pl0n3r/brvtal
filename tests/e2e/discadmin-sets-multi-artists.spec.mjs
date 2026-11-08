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


const soundcloudAdmin = readFileSync(join(root,'discadmin/soundcloud-artwork.js'),'utf8');

test('Sets SoundCloud oEmbed suggestions preserve authored fields and identify one artist', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await page.setContent(`<!doctype html><html><body>
    <div id="modal" class="open"><div id="mcontent"><div class="form">
      <div><input id="f_title" aria-label="Title"></div>
      <div><input id="f_slug" aria-label="Slug"></div>
      <div><select id="f_platform"><option value="soundcloud">SoundCloud</option></select></div>
      <div><input id="f_external_url" aria-label="Listening URL"></div>
      <div><input id="f_embed_url" aria-label="Embed URL"></div>
      <div><input id="f_cover_image" aria-label="Cover image"></div>
      <div><input id="f_duration_seconds" aria-label="Duration"></div>
      <div id="setArtistChoices">
        <label class="set-artist-choice" data-artist-name="PL0N3R"><input data-set-artist type="checkbox" value="5"></label>
        <label class="set-artist-choice" data-artist-name="CRIX"><input data-set-artist type="checkbox" value="6"></label>
      </div>
    </div></div></div>
  </body></html>`);
  await page.evaluate(() => {
    window.calls = [];
    window.fetch = async url => {
      window.calls.push(String(url));
      return new Response(JSON.stringify({ok:true,title:'BRVTAL Podcast #1 - PL0N3R',
        author_name:'pl0n3r', artwork_url:'https://i1.sndcdn.com/artworks-ABC-t500x500.jpg'}),
        {status:200,headers:{'Content-Type':'application/json'}});
    };
  });
  await page.addScriptTag({content:soundcloudAdmin});
  await page.evaluate(() => window.BRVTALAdminSoundCloud.bindSetEditor());
  await page.getByLabel('Listening URL').fill('https://soundcloud.com/pl0n3r/brvtal-podcast-1');
  await expect(page.getByLabel('Title')).toHaveValue('BRVTAL Podcast #1 - PL0N3R');
  await expect(page.getByLabel('Slug')).toHaveValue('brvtal-podcast-1-pl0n3r');
  await expect(page.getByLabel('Cover image')).toHaveValue('https://i1.sndcdn.com/artworks-ABC-t500x500.jpg');
  await expect(page.locator('#setArtistChoices input[value="5"]')).toBeChecked();
  await expect(page.getByLabel('Duration')).toHaveValue('');
  await expect(page.locator('.admin-soundcloud-meta-status')).toContainText('Metadatos disponibles');
  await page.getByLabel('Title').fill('Mi título manual');
  await page.getByLabel('Listening URL').fill('https://soundcloud.com/pl0n3r/another-track');
  await expect(page.locator('.admin-soundcloud-meta-status')).toContainText('SoundCloud consultado');
  await expect(page.getByLabel('Title')).toHaveValue('Mi título manual');
  expect(await page.evaluate(() => window.calls.length)).toBe(2);
});

test('Sets SoundCloud thumbnails are safe, memoized, and do not invent artwork', async ({page}) => {
  const asset='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl0PG0AAAAASUVORK5CYII=';
  await page.route('https://i1.sndcdn.com/**', route => route.fulfill({
    status:200,contentType:'image/png',body:Buffer.from(asset,'base64')
  }));
  await page.setContent(`<!doctype html><html><body>
    <div id="rows">
      <span class="admin-grid-thumb admin-grid-thumb-ph" data-soundcloud-url="https://soundcloud.com/pl0n3r/my-set" data-soundcloud-title="My Set">NO IMG</span>
      <span class="admin-grid-thumb admin-grid-thumb-ph" data-soundcloud-url="https://soundcloud.com/pl0n3r/my-set" data-soundcloud-title="My Set">NO IMG</span>
      <span class="admin-grid-thumb admin-grid-thumb-ph" data-soundcloud-url="https://evil.test/steal" data-soundcloud-title="Invalid">NO IMG</span>
    </div>
  </body></html>`);
  await page.evaluate(() => {
    window.calls = [];
    window.fetch = async url => {
      window.calls.push(String(url));
      return new Response(JSON.stringify({ok:true,artwork_url:'https://i1.sndcdn.com/artworks-ABC-t500x500.jpg'}),
        {status:200,headers:{'Content-Type':'application/json'}});
    };
  });
  await page.addScriptTag({content:soundcloudAdmin});
  await expect(page.locator('#rows img')).toHaveCount(2);
  await expect(page.locator('#rows [data-soundcloud-url="https://evil.test/steal"]')).toHaveText('NO IMG');
  expect(await page.evaluate(() => window.calls.length)).toBe(1);
  await page.evaluate(() => window.BRVTALAdminSoundCloud.hydrate(document.getElementById('rows')));
  expect(await page.evaluate(() => window.calls.length)).toBe(1);
});
