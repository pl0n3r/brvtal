import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const html=readFileSync('discadmin/content-core.php','utf8').split('?>').slice(1).join('?>');
const source=readFileSync('discadmin/content-core.js','utf8');
const workflow=readFileSync('discadmin/event-workflow.js','utf8');
const styles=readFileSync('discadmin/content-core.css','utf8');
const url='http://127.0.0.1:4173/discadmin/e2e-event-visibility.html';

async function openEditor(page,options={}){
  const state={events:options.events||[],ticketTypes:options.ticketTypes||[],timetable:options.timetable||[],writes:[]};
  await page.route(url,route=>route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><style>'+styles+'</style></head><body>'+html+'</body></html>'}));
  await page.goto(url);
  await page.evaluate(data=>{
    window.__salesFixture=data;
    window.__copied=[];
    Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>window.__copied.push(text)}});
    window.BRVTALAdminAuthBoundary={auth:async()=>({authenticated:true,csrf:'ci-token'}),csrfToken:async()=> 'ci-token'};
    window.BRVTALEventInsights={load:async()=>({}),reset(){},mount(){}};
    window.fetch=async (raw,options={})=>{
      const path=new URL(raw,location.href).pathname,method=options.method||'GET',s=window.__salesFixture;
      const payload=(data,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
      if(path==='/api/index.php/auth')return payload({authenticated:true,csrf:'ci-token'});
      if(path==='/api/index.php/events'&&method==='GET')return payload({ok:true,data:s.events});
      if(path==='/api/index.php/artists')return payload({ok:true,data:[]});
      if(path==='/api/index.php/ticket_types')return payload({ok:true,data:s.ticketTypes});
      if(path.endsWith('/lineup')&&method==='GET')return payload({ok:true,data:[]});
      if(path==='/api/event-workflow.php'&&method==='GET')return payload({ok:true,data:{timetable:s.timetable}});
      if(path==='/api/event-workflow.php'&&method==='POST'){
        const record=JSON.parse(options.body);s.writes.push(record);
        if(s.failSave)return payload({ok:false,error:'INVALID_VISIBILITY'},422);
        if(options.headers['X-CSRF-Token']!=='ci-token')return payload({ok:false,error:'CSRF'},403);
        const id=Number(record.event.id||42);
        s.events=[{...record.event,id, published_at:record.event.publish_at||null}];
        s.ticketTypes=record.ticket_types.map((ticket,i)=>({...ticket,id:i+1,event_id:id}));
        s.timetable=record.timetable||[];
        return payload({ok:true,data:{event:{...record.event,id},tickets:s.ticketTypes}});
      }
      return payload({ok:false,error:'UNEXPECTED_ROUTE '+method+' '+path},404);
    };
  },state);
  await page.addScriptTag({content:source});
  await page.addScriptTag({content:workflow});
  await page.evaluate(()=>window.BRVTALContentCore.mount(document.querySelector('[data-admin-module="content-core"]')));
  await page.waitForFunction(()=>typeof window.BRVTALContentCore?.saveEvent==='function');
}

for(const width of [390,1440]){
  test.describe('event visibility at '+width+'px',()=>{
    test.use({viewport:{width,height:900}});
    async function openPublic(page){
      await openEditor(page,{events:[{id:42,title:'Noche de prueba',slug:'noche-de-prueba',status:'draft',visibility:'public'}]});
      await expect(page.locator('#eventsTable')).toContainText('Noche de prueba');
      await page.evaluate(()=>window.BRVTALContentCore.openEvent(42));
      await page.evaluate(()=>window.BRVTALContentCore.whenEventReady());
      await page.locator('[data-step="5"]').click();
    }
    test('private save, copy canonical URL, badge, reload and public transition',async({page},testInfo)=>{
      await openPublic(page);
      await expect(page.locator('#e_visibility')).toHaveValue('public');
      await expect(page.locator('#e_private_share')).toBeHidden();
      await page.locator('#e_visibility').selectOption('unlisted');
      await expect(page.locator('#e_private_copy')).toBeDisabled();
      expect(await page.evaluate(()=>window.BRVTALContentCore.saveEvent())).toBe(true);
      await expect(page.locator('#eventsTable .pill', {hasText:'Privado'})).toHaveCount(1);
      await page.evaluate(()=>window.BRVTALContentCore.whenEventReady());
      await page.locator('[data-step="5"]').click();
      await expect(page.locator('#e_visibility')).toHaveValue('unlisted');
      const canonical=new URL('/events/noche-de-prueba',page.url()).href;
      await expect(page.locator('#e_private_url')).toHaveValue(canonical);
      await page.locator('#e_private_copy').click();
      await expect(page.locator('#e_private_feedback')).toHaveText('Enlace copiado');
      expect(await page.evaluate(()=>window.__copied)).toEqual([canonical]);
      await testInfo.attach('private-editor-'+width,{body:await page.screenshot(),contentType:'image/png'});
      await page.locator('#e_visibility').selectOption('public');
      await expect(page.locator('#e_private_share')).toBeHidden();
      expect(await page.evaluate(()=>window.BRVTALContentCore.saveEvent())).toBe(true);
      await expect(page.locator('#eventsTable .pill', {hasText:'Privado'})).toHaveCount(0);
      expect(await page.evaluate(()=>window.__salesFixture.writes.map(x=>x.event.visibility))).toEqual(['unlisted','public']);
    });
    test('failed save does not advertise private link or badge',async({page})=>{
      await openPublic(page);
      await page.locator('#e_visibility').selectOption('unlisted');
      await page.evaluate(()=>{window.__salesFixture.failSave=true;});
      expect(await page.evaluate(()=>window.BRVTALContentCore.saveEvent())).toBe(false);
      await expect(page.locator('#eventNotice')).toContainText('Selecciona una visibilidad válida.');
      await expect(page.locator('#e_private_copy')).toBeDisabled();
      await expect(page.locator('#eventsTable .pill', {hasText:'Privado'})).toHaveCount(0);
    });
  });
}
