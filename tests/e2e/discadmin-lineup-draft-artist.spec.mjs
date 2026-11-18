import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../discadmin/index-core.php',import.meta.url),'utf8');
const script = source.match(/<script>\s*(const API=[\s\S]*?)<\/script>/)?.[1];
const css = source.match(/<style>([\s\S]*?)<\/style>/)?.[1];
if (!script || !css) throw new Error('DISCADMIN lineup HTML source missing');
const harness = 'http://127.0.0.1:4173/discadmin/lineup-draft-artist-harness.html';

async function setup(page) {
 await page.route(harness, route => route.fulfill({contentType:'text/html',body:
  '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="app"></div>'+
  '<div id="modal" class="modal"><div class="modalbox"><h2 id="mtitle"></h2><div id="notice" class="notice"></div><div id="mcontent"></div>'+
  '<div class="modalfoot"><button type="button" id="saveBtn">GUARDAR</button></div></div></div></body></html>'
 }));
 await page.goto(harness);
 await page.addStyleTag({content:css});
 await page.addScriptTag({content:script});
 await page.evaluate(() => {
  csrf='test-lineup-csrf';
  state.authed=true;
  window.fixture = {
   artists:[
    {id:4,name:'PUBLICADO',status:'published',is_collective_member:1},
    {id:5,name:'INVITADO BORRADOR',status:'draft',is_collective_member:0}
   ],
   event:{id:17,title:'Genesis',status:'published'},
   lineup:[{artist_id:4,name:'PUBLICADO',role:'Headliner'}, {artist_id:5,name:'INVITADO BORRADOR',role:'Guest'}],
   requests:[],publishFailure:false
  };
  window.fetch = async (url, options={}) => {
   const f=window.fixture,path=new URL(url,location.href).pathname,method=options.method||'GET';
   f.requests.push({path,method,csrf:options.headers?.['X-CSRF-Token'],body:options.body?JSON.parse(options.body):null});
   if(path.endsWith('/events'))return new Response(JSON.stringify({ok:true,data:[f.event]}),{status:200});
   if(path.endsWith('/artists'))return new Response(JSON.stringify({ok:true,data:f.artists}),{status:200});
   if(path.endsWith('/events/17/lineup')){
    if(method==='POST'){
     f.lineup=JSON.parse(options.body).lineup.map(x=>({...x,name:f.artists.find(a=>a.id===x.artist_id)?.name}));
     return new Response(JSON.stringify({ok:true}),{status:200});
    }
    return new Response(JSON.stringify({ok:true,data:f.lineup}),{status:200});
   }
   if(path.endsWith('/artists/5') && method==='PUT'){
    if(f.publishFailure)return new Response(JSON.stringify({ok:false,error:'ARTIST_PUBLICATION_BLOCKED'}),{status:422});
    f.artists.find(a=>a.id===5).status='published';
    return new Response(JSON.stringify({ok:true,changed:1}),{status:200});
   }
   throw Error('Unexpected API '+method+' '+path);
  };
 });
 await page.evaluate(()=>openLineup(17));
}

test('AC-01: shows the accessible draft notice only on unpublished artists', async ({page}) => {
 await setup(page);
 const draft=page.locator('#lineupCurrent .lineitem[data-id="5"]');
 const published=page.locator('#lineupCurrent .lineitem[data-id="4"]');
 await expect(draft.locator('.lineup-draft-warning[role=status]')).toHaveText('Borrador: no sale en el cartel público');
 await expect(draft.getByRole('button',{name:'Publicar artista'})).toBeVisible();
 await expect(published.locator('.lineup-draft-warning')).toHaveCount(0);
 await expect(published.locator('.lineup-publish-artist')).toHaveCount(0);
});

test('AC-02: publishes through authenticated CSRF API without reloading', async ({page}) => {
 await setup(page);
 const draft=page.locator('#lineupCurrent .lineitem[data-id="5"]');
 await draft.getByRole('button',{name:'Publicar artista'}).click();
 await expect(draft.locator('.lineup-draft-warning')).toHaveCount(0);
 await expect(draft.getByRole('status')).toContainText('Artista publicado');
 const data=await page.evaluate(()=>window.fixture);
 expect(data.requests.find(x=>x.method==='PUT'&&x.path.endsWith('/artists/5'))).toMatchObject({
  csrf:'test-lineup-csrf',body:{status:'published'}
 });
 expect(data.artists.find(a=>a.id===5).status).toBe('published');
});

test('AC-02: API failure preserves the draft warning and permits keyboard retry', async ({page}) => {
 await setup(page);
 await page.evaluate(()=>{window.fixture.publishFailure=true});
 const draft=page.locator('#lineupCurrent .lineitem[data-id="5"]');
 const publish=draft.getByRole('button',{name:'Publicar artista'});
 await publish.focus();
 await page.keyboard.press('Enter');
 await expect(draft.locator('[role=alert]')).toContainText('No se pudo publicar');
 await expect(draft.locator('.lineup-draft-warning')).toBeVisible();
 await expect(publish).toBeEnabled();
 await page.evaluate(()=>{window.fixture.publishFailure=false});
 await publish.focus();
 await page.keyboard.press('Enter');
 await expect(draft.locator('.lineup-draft-warning')).toHaveCount(0);
});

test('published Event save warns about draft artists without blocking persistence', async ({page}) => {
 await setup(page);
 await page.locator('#saveBtn').click();
 await expect(page.locator('#lineupSaveWarning[role=status]')).toContainText('1 artista en borrador no saldrá');
 await expect(page.locator('#modal')).not.toHaveClass(/\bopen\b/);
 const data=await page.evaluate(()=>window.fixture);
 expect(data.requests.some(x=>x.method==='POST'&&x.path.endsWith('/events/17/lineup'))).toBe(true);
 expect(data.lineup.map(x=>x.artist_id)).toEqual([4,5]);
});

test('mobile 390px: draft publishing is visible, keyboard operable and stays within viewport', async ({page}) => {
 await page.setViewportSize({width:390,height:844});
 await setup(page);
 const draft=page.locator('#lineupCurrent .lineitem[data-id="5"]');
 const button=draft.getByRole('button',{name:'Publicar artista'});
 await expect(button).toBeVisible();
 const metrics=await draft.evaluate(el=>({
  viewport:window.innerWidth,
  rowRight:el.getBoundingClientRect().right,
  buttonRight:el.querySelector('button.lineup-publish-artist').getBoundingClientRect().right,
  documentWidth:document.documentElement.scrollWidth
 }));
 expect(metrics.rowRight).toBeLessThanOrEqual(metrics.viewport+1);
 expect(metrics.buttonRight).toBeLessThanOrEqual(metrics.viewport+1);
 expect(metrics.documentWidth).toBeLessThanOrEqual(metrics.viewport+2);
 await button.focus();
 await page.keyboard.press('Enter');
 await expect(draft.locator('.lineup-draft-warning')).toHaveCount(0);
});

// A real PHP + MariaDB smoke runs under the disposable real-stack harness only.
// It uses synthetic records and never publishes real data or touches live payments.
test('AC-03: real PHP/MariaDB public poster reflects lineup artist publication', async ({page},testInfo) => {
 const base=process.env.BRVTAL_REAL_STACK_URL||'',password=process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD||'';
 test.skip(!base||!password,'Disposable real-stack server and test credentials required');
 const login=await page.request.post(base+'/api/index.php/auth',{data:{
  email:process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL||'ci-admin@brvtal.test',password
 }});
 expect(login.ok()).toBe(true);
 const headers={'X-CSRF-Token':(await login.json()).csrf};
 const key=Date.now().toString(36)+'-'+testInfo.workerIndex;
 let eventId=0,artistId=0;
 try{
  const artist=await page.request.post(base+'/api/index.php/artists',{headers,data:{
   name:'CI LINEUP GUEST '+key,slug:'ci-lineup-guest-'+key,status:'draft',is_collective_member:0
  }});
  expect(artist.status()).toBe(201);
  artistId=Number((await artist.json()).id);
  const event=await page.request.post(base+'/api/index.php/events',{headers,data:{
   title:'CI LINEUP EVENT '+key,slug:'ci-lineup-event-'+key,status:'draft'
  }});
  expect(event.status()).toBe(201);
  eventId=Number((await event.json()).id);
  const published=await page.request.put(base+'/api/index.php/events/'+eventId,{headers,data:{
   event_date:'2026-12-21T21:00',city:'Pereira',description:'Synthetic published lineup test.',status:'published'
  }});
  expect(published.ok()).toBe(true);
  const lineup=await page.request.post(base+'/api/index.php/events/'+eventId+'/lineup',{headers,data:{
   lineup:[{artist_id:artistId,role:'Guest',sort_order:0}]
  }});
  expect(lineup.ok()).toBe(true);
  const publicUrl=base+'/?type=events&slug=ci-lineup-event-'+key;
  const before=await page.request.get(publicUrl);
  expect(before.ok()).toBe(true);
  expect(await before.text()).not.toContain('CI LINEUP GUEST '+key);
  await page.goto(base+'/discadmin/',{waitUntil:'domcontentloaded'});
  await page.waitForFunction(()=>Boolean(window.state?.authed),null,{timeout:10_000});
  await page.evaluate(async id=>{await openLineup(id)},eventId);
  const draftRow=page.locator('#lineupCurrent .lineitem[data-id="'+artistId+'"]');
  await expect(draftRow.locator('.lineup-draft-warning')).toBeVisible();
  await draftRow.getByRole('button',{name:'Publicar artista'}).click();
  await expect(draftRow.locator('.lineup-draft-warning')).toHaveCount(0);
  const after=await page.request.get(publicUrl+'&_='+Date.now());
  expect(after.ok()).toBe(true);
  expect(await after.text()).toContain('CI LINEUP GUEST '+key);
 }finally{
  if(eventId)await page.request.delete(base+'/api/index.php/events/'+eventId,{headers}).catch(()=>{});
  if(artistId)await page.request.delete(base+'/api/index.php/artists/'+artistId,{headers}).catch(()=>{});
 }
});
