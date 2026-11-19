import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const html=readFileSync('discadmin/content-core.php','utf8').split('?>').slice(1).join('?>');
const source=readFileSync('discadmin/content-core.js','utf8');
const workflow=readFileSync('discadmin/event-workflow.js','utf8');
const styles=readFileSync('discadmin/content-core.css','utf8');
const url='http://127.0.0.1:4173/discadmin/e2e-event-editor-sales.html';

async function openEditor(page,options={}){
  const state={events:options.events||[],ticketTypes:options.ticketTypes||[],timetable:options.timetable||[],writes:[]};
  await page.route(url,route=>route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><style>'+styles+'</style></head><body>'+html+'</body></html>'}));
  await page.goto(url);
  await page.evaluate(data=>{
    window.__salesFixture=data;
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
        const id=Number(record.event.id||42);
        s.events=[{...record.event,id, published_at:record.event.publish_at||null}];
        s.ticketTypes=record.ticket_types.map((ticket,i)=>({...ticket,id:i+1,event_id:id}));
        s.timetable=record.timetable||[];
        return payload({ok:true,data:{event:{id,...record.event},tickets:s.ticketTypes}});
      }
      return payload({ok:false,error:'UNEXPECTED_ROUTE '+method+' '+path},404);
    };
  },state);
  await page.addScriptTag({content:source});
  await page.addScriptTag({content:workflow});
  await page.evaluate(()=>window.BRVTALContentCore.mount(document.querySelector('[data-admin-module="content-core"]')));
  await page.waitForFunction(()=>typeof window.BRVTALContentCore?.saveEvent==='function');
}

test('editor has five commercial steps, a single publication control and legacy mapping',async({page})=>{
  await openEditor(page,{events:[{id:42,title:'Noche','slug':'noche','status':'tickets_available',event_date:'2026-11-28 21:00:00',city:'Pereira'}]});
  await page.evaluate(()=>window.BRVTALContentCore.openEvent(42));
  await expect(page.locator('.event-sales-wizard .step')).toHaveCount(5);
  await expect(page.locator('#e_status option')).toHaveCount(4);
  await expect(page.locator('#e_status')).toHaveValue('published');
  await page.locator('[data-step="2"]').click();
  await expect(page.locator('[data-content="2"]')).toHaveClass(/active/);
  await page.evaluate(()=>window.BRVTALContentCore.addTicket({name:'General',status:'active',price:25000,currency:'COP'}));
  await expect(page.locator('#e_sales_state')).toContainText('disponible');
  await page.locator('[data-step="5"]').click();
  await expect(page.locator('#e_publish_checklist')).toContainText('Boleta con precio');
  await expect(page.locator('#e_publish_checklist')).toContainText('Descripción');
  await page.locator('#e_status').selectOption('scheduled');
  await expect(page.locator('#e_publish_at')).toBeEnabled();
});

test('night timetable uses hours, rolls into next day and keeps UTC input contract',async({page})=>{
  await openEditor(page);
  await page.evaluate(()=>window.BRVTALContentCore.openEvent());
  await page.locator('#e_title').fill('Noche de prueba');
  await page.locator('#e_event_date').fill('2026-11-28T21:00');
  await page.locator('#e_city').fill('Pereira');
  await page.evaluate(()=>window.BRVTALContentCore.addTimetableRow());
  const row=page.locator('#eventTimetable .timetable-row');
  await row.locator('[data-k="label"]').fill('Guest');
  await row.locator('[data-k="starts_at"]').fill('11:00 pm');
  await row.locator('[data-k="ends_at"]').fill('1:00 am');
  await expect(row.locator('[data-k="status"]')).toHaveCount(0);
  await expect(row.locator('[data-k="timezone"]')).toHaveCount(0);
  await page.locator('#e_status').selectOption('published');
  expect(await page.evaluate(()=>window.BRVTALContentCore.saveEvent())).toBe(true);
  const writes=await page.evaluate(()=>window.__salesFixture.writes);
  expect(writes).toHaveLength(1);
  expect(writes[0].event.status).toBe('published');
  expect(writes[0].timetable[0]).toMatchObject({
    starts_at:'2026-11-28T23:00',ends_at:'2026-11-29T01:00',
    timezone:'America/Bogota',status:'approved'
  });
});

test('promotion builds own public UTM URLs, without external social API calls',async({page})=>{
  await openEditor(page);
  await page.evaluate(()=>window.BRVTALContentCore.openEvent());
  await page.locator('#e_title').fill('Noche Pereira');
  await page.locator('[data-step="4"]').click();
  await page.locator('#e_description').fill('Gran noche con artistas invitados');
  await expect(page.locator('#e_share_preview_title')).toHaveText('Noche Pereira');
  await expect(page.locator('#e_description_count')).toContainText('caracteres');
  await expect(page.locator('#e_share_whatsapp')).toHaveAttribute('href',/utm_source=whatsapp/);
  await expect(page.locator('#e_share_instagram')).toHaveAttribute('href',/utm_source=instagram/);
  await expect(page.locator('#e_share_qr')).toHaveAttribute('href',/utm_source=qr/);
});
