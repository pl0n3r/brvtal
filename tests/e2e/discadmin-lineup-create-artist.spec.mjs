import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const source=readFileSync(join(process.cwd(),'discadmin/index-core.php'),'utf8');
const inline=source.match(/<script>\s*(const API=[\s\S]*?)<\/script>/);
if(!inline)throw new Error('DISCADMIN inline source missing');
const harness='http://127.0.0.1:4173/discadmin/lineup-create-artist-harness.html';

async function setup(page){
 await page.route(harness,route=>route.fulfill({contentType:'text/html',body:
 '<!doctype html><html><body><main id="app"></main><div id="modal" class="modal">'+
 '<div id="mtitle"></div><div id="notice"></div><div id="mcontent"></div>'+
 '<button id="saveBtn" type="button">GUARDAR</button></div></body></html>'}));
 await page.goto(harness);
 await page.addScriptTag({content:inline[1]});
 await page.evaluate(()=>{
  csrf='fixture-csrf';
  state.authed=true;
  window.lineupFixture={
   artists:[{id:4,name:'SAMPLE ARTIST',is_collective_member:1,status:'published'}],
   event:{id:17,title:'Genesis'},
   otherEvent:{id:18,title:'Nocturno'},
   lineup:[{artist_id:4,name:'SAMPLE ARTIST',role:'DJ'}],
   requests:[],nextId:80,failure:null
  };
  window.fetch=async(url,options={})=>{
   const f=window.lineupFixture;
   const path=new URL(url,location.href).pathname;
   const method=options.method||'GET';
   f.requests.push({path,method,body:options.body?JSON.parse(options.body):null,csrf:options.headers?.['X-CSRF-Token']});
   if(path.endsWith('/events/18/lineup'))return new Response(JSON.stringify({ok:true,data:[]}),{status:200});
   if(path.endsWith('/events/17/lineup')){
    if(method==='POST'){
     f.lineup=JSON.parse(options.body).lineup.map(x=>({...x,name:f.artists.find(a=>a.id===x.artist_id)?.name}));
     return new Response(JSON.stringify({ok:true}),{status:200});
    }
    return new Response(JSON.stringify({ok:true,data:f.lineup}),{status:200});
   }
   if(path.endsWith('/artists')){
    if(method==='POST'){
     if(f.failure==='network')throw new Error('NETWORK_ERROR');
     if(f.failure==='delay')await new Promise(resolve=>{f.releaseArtist=resolve});
     if(f.failure==='duplicate')return new Response(JSON.stringify({error:'DUPLICATE_SLUG'}),{status:409});
     const body=JSON.parse(options.body),id=f.nextId++;
     f.artists.push({id,name:body.name,status:body.status,is_collective_member:body.is_collective_member});
     return new Response(JSON.stringify({ok:true,id}),{status:201});
    }
    return new Response(JSON.stringify({ok:true,data:f.artists}),{status:200});
   }
   if(path.endsWith('/events'))return new Response(JSON.stringify({ok:true,data:[f.event,f.otherEvent]}),{status:200});
   throw new Error('Unexpected API '+method+' '+path);
  };
 });
 await page.evaluate(()=>openLineup(17));
}
test('creates draft external artist, retains draft lineup, saves and reloads',async({page})=>{
 await setup(page);
 await page.locator('#lineupArtistSearch').fill('NUEVO INVITADO');
 await expect(page.locator('#lineupCreateArtist')).toBeVisible();
 await page.locator('#lineupArtistSearch').press('Enter');
 await expect(page.locator('#lineupCurrent .lineitem')).toHaveCount(2);
 await expect(page.locator('#lineupCurrent')).toContainText('NUEVO INVITADO');
 await expect(page.locator('#lineupArtistSearch')).toBeFocused();
 const payload=await page.evaluate(()=>window.lineupFixture.requests.find(x=>x.path.endsWith('/artists')&&x.method==='POST'));
 expect(payload).toMatchObject({csrf:'fixture-csrf',body:{name:'NUEVO INVITADO',slug:'',status:'draft',is_collective_member:0}});
 await page.locator('#saveBtn').click();
 await page.evaluate(()=>openLineup(17));
 await expect(page.locator('#lineupCurrent .lineitem')).toHaveCount(2);
 const f=await page.evaluate(()=>window.lineupFixture);
 expect(f.artists.find(a=>a.name==='NUEVO INVITADO')).toMatchObject({status:'draft',is_collective_member:0});
 expect(f.lineup.map(x=>x.artist_id)).toEqual([4,80]);
});
test('filters, reports name/duplicate/network errors without discarding lineup',async({page})=>{
 await setup(page);
 await page.locator('#lineupArtistSearch').fill('SAMPLE');
 await expect(page.locator('#lineupCreateArtist')).toBeVisible();
 await page.locator('#lineupArtistSearch').fill('SAMPLE ARTIST');
 await expect(page.locator('#lineupCreateArtist')).toBeHidden();
 await page.locator('#lineupArtistSearch').fill('');
 await page.locator('#lineupArtistSearch').press('Enter');
 await expect(page.locator('#lineupArtistError')).toContainText('Escribe el nombre');
 await page.evaluate(()=>{window.lineupFixture.failure='duplicate'});
 await page.locator('#lineupArtistSearch').fill('Guest');
 await page.locator('#lineupCreateArtist').click();
 await expect(page.locator('#lineupArtistError')).toContainText('Ya existe un artista');
 await expect(page.locator('#lineupCurrent .lineitem')).toHaveCount(1);
 await page.evaluate(()=>{window.lineupFixture.failure='network'});
 await page.locator('#lineupCreateArtist').click();
 await expect(page.locator('#lineupArtistError')).toContainText('NETWORK_ERROR');
 await expect(page.locator('#lineupCurrent .lineitem')).toHaveCount(1);
 await expect(page.locator('#lineupArtistSearch')).toBeFocused();
});

test('rapid Enter presses and save during artist creation never write partial lineup',async({page})=>{
 await setup(page);
 await page.evaluate(()=>{window.lineupFixture.failure='delay'});
 await page.locator('#lineupArtistSearch').fill('LATE GUEST');
 await page.locator('#lineupArtistSearch').press('Enter');
 await expect.poll(()=>page.evaluate(()=>window.lineupFixture.requests.filter(x=>x.path.endsWith('/artists')&&x.method==='POST').length)).toBe(1);
 await page.locator('#lineupArtistSearch').press('Enter');
 await page.locator('#saveBtn').click();
 const pre=await page.evaluate(()=>window.lineupFixture.requests);
 expect(pre.filter(x=>x.path.endsWith('/artists')&&x.method==='POST')).toHaveLength(1);
 expect(pre.filter(x=>x.path.endsWith('/events/17/lineup')&&x.method==='POST')).toHaveLength(0);
 await expect(page.locator('#notice')).toContainText('Espera a que termine');
 await page.evaluate(()=>window.lineupFixture.releaseArtist());
 await expect(page.locator('#lineupCurrent .lineitem')).toHaveCount(2);
 await page.locator('#saveBtn').click();
 await expect.poll(()=>page.evaluate(()=>window.lineupFixture.lineup.map(x=>x.artist_id))).toEqual([4,80]);
});
test('delayed artist creation updates catalog but never mutates a replacement lineup editor',async({page})=>{
 await setup(page);
 await page.evaluate(()=>{window.lineupFixture.failure='delay'});
 await page.locator('#lineupArtistSearch').fill('SLOW GUEST');
 await page.locator('#lineupCreateArtist').click({noWaitAfter:true});
 await expect.poll(()=>page.evaluate(()=>window.lineupFixture.requests.filter(x=>x.path.endsWith('/artists')&&x.method==='POST').length)).toBe(1);
 await page.evaluate(()=>openLineup(18));
 await expect(page.locator('#mtitle')).toHaveText('LINEUP / Nocturno');
 await page.evaluate(()=>window.lineupFixture.releaseArtist());
 await expect.poll(()=>page.evaluate(()=>state.artists.some(x=>x.name==='SLOW GUEST'))).toBe(true);
 await expect(page.locator('#lineupCurrent .lineitem')).toHaveCount(0);
 await expect(page.locator('#lineupCurrent')).not.toContainText('SLOW GUEST');
 await expect(page.locator('#lineupArtistSearch')).toHaveValue('');
});
