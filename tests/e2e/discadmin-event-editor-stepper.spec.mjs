import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const html = readFileSync('discadmin/content-core.php', 'utf8').split('?>').slice(1).join('?>');
const css = readFileSync('discadmin/content-core.css', 'utf8');
const source = readFileSync('discadmin/content-core.js', 'utf8');
const workflow = readFileSync('discadmin/event-workflow.js', 'utf8');
const url = 'http://127.0.0.1:4173/discadmin/e2e-event-editor-stepper.html';

async function openEditor(page) {
  const state = {
    events: [{id:42,title:'Noche de prueba',slug:'noche-de-prueba',status:'draft',event_date:'2026-12-05 21:00:00',city:'Pereira'}],
    ticketTypes: [], timetable: [], writes: []
  };
  await page.route(url, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
      + css + '</style></head><body>' + html + '</body></html>'
  }));
  await page.goto(url);
  await page.evaluate(data => {
    window.__stepperFixture = data;
    window.BRVTALAdminAuthBoundary = {
      auth: async () => ({authenticated:true,csrf:'ci-token'}),
      csrfToken: async () => 'ci-token'
    };
    window.BRVTALEventInsights = {load:async()=>({}),reset(){},mount(){}};
    window.fetch = async (raw,options={}) => {
      const path = new URL(raw,location.href).pathname;
      const method = options.method || 'GET';
      const state = window.__stepperFixture;
      const reply = (data,status=200) => new Response(JSON.stringify(data),{
        status,headers:{'Content-Type':'application/json'}
      });
      if(path==='/api/index.php/auth')return reply({authenticated:true,csrf:'ci-token'});
      if(path==='/api/index.php/events'&&method==='GET')return reply({ok:true,data:state.events});
      if(path==='/api/index.php/artists')return reply({ok:true,data:[]});
      if(path==='/api/index.php/ticket_types')return reply({ok:true,data:state.ticketTypes});
      if(path.endsWith('/lineup')&&method==='GET')return reply({ok:true,data:[]});
      if(path==='/api/event-workflow.php'&&method==='GET')return reply({ok:true,data:{timetable:state.timetable}});
      if(path==='/api/event-workflow.php'&&method==='POST'){
        const record=JSON.parse(options.body);
        state.writes.push(record);
        state.events=[{...record.event,id:Number(record.event.id||42)}];
        return reply({ok:true,data:{event:state.events[0],tickets:state.ticketTypes}});
      }
      return reply({ok:false,error:'UNEXPECTED_ROUTE '+method+' '+path},404);
    };
  },state);
  await page.addScriptTag({content:source});
  await page.addScriptTag({content:workflow});
  await page.evaluate(() => window.BRVTALContentCore.mount(document.querySelector('[data-admin-module="content-core"]')));
  await page.waitForFunction(() => typeof window.BRVTALContentCore?.openEvent === 'function');
  await page.evaluate(() => window.BRVTALContentCore.openEvent(42));
  await expect(page.locator('.event-sales-wizard .step')).toHaveCount(5);
}

for (const width of [390, 768, 1024, 1440]) {
  test('events stepper stays legible and above the form at '+width+'px', async ({page}) => {
    await page.setViewportSize({width,height:900});
    await openEditor(page);
    const metrics = await page.locator('.event-sales-wizard').evaluate(wizard => {
      const nav=wizard.querySelector('.steps');
      const main=wizard.querySelector('.wizard-main');
      const bounds=el => {
        const r=el.getBoundingClientRect();
        return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height};
      };
      return {
        wizard:bounds(wizard),nav:bounds(nav),main:bounds(main),
        navScroll:nav.scrollWidth,navClient:nav.clientWidth,
        buttons:[...nav.querySelectorAll('.step')].map(el => ({
          ...bounds(el),text:el.textContent.trim(),scroll:el.scrollWidth,client:el.clientWidth,
          font:parseFloat(getComputedStyle(el).fontSize),line:parseFloat(getComputedStyle(el).lineHeight),
          whiteSpace:getComputedStyle(el).whiteSpace
        }))
      };
    });
    expect(metrics.main.top).toBeGreaterThanOrEqual(metrics.nav.bottom - 1);
    expect(metrics.nav.left).toBeGreaterThanOrEqual(metrics.wizard.left - 1);
    expect(metrics.nav.right).toBeLessThanOrEqual(metrics.wizard.right + 1);
    expect(metrics.navScroll).toBeLessThanOrEqual(metrics.navClient + 1);
    expect(metrics.buttons).toHaveLength(5);
    for(const b of metrics.buttons){
      expect(b.text.length).toBeGreaterThan(5);
      expect(b.width).toBeGreaterThanOrEqual(44);
      expect(b.height).toBeGreaterThanOrEqual(44);
      expect(b.font).toBeGreaterThanOrEqual(14);
      expect(b.line).toBeGreaterThanOrEqual(18);
      expect(b.whiteSpace).not.toBe('nowrap');
      expect(b.scroll).toBeLessThanOrEqual(b.client + 1);
      expect(b.left).toBeGreaterThanOrEqual(metrics.nav.left - 1);
      expect(b.right).toBeLessThanOrEqual(metrics.nav.right + 1);
    }
    for(let i=0;i<5;i++)for(let j=i+1;j<5;j++){
      const a=metrics.buttons[i],b=metrics.buttons[j];
      const overlapW=Math.min(a.right,b.right)-Math.max(a.left,b.left);
      const overlapH=Math.min(a.bottom,b.bottom)-Math.max(a.top,b.top);
      expect(overlapW > 1 && overlapH > 1, 'steps '+(i+1)+' and '+(j+1)+' overlap').toBe(false);
    }
    if(width===1440){
      expect(Math.max(...metrics.buttons.map(b=>b.top))-Math.min(...metrics.buttons.map(b=>b.top))).toBeLessThan(2);
    }
  });
}

test('keyboard and Next/Back navigation preserve event fields and SAVE DRAFT', async ({page}) => {
  await page.setViewportSize({width:1440,height:900});
  await openEditor(page);
  const step = n => page.locator('.event-sales-wizard [data-step="'+n+'"]');
  await expect(step(1)).toHaveClass(/active/);
  await page.locator('#e_title').fill('Noche guardada');
  await page.locator('#e_event_date').fill('2026-12-05T21:00');
  await page.locator('#e_city').fill('Pereira');
  await step(4).focus();
  await expect(step(4)).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('[data-content="4"]')).toHaveClass(/active/);
  await page.locator('#e_description').fill('Descripción temporal que debe conservarse');
  await step(1).click();
  await expect(page.locator('#e_title')).toHaveValue('Noche guardada');
  await page.locator('#nextBtn').click();
  await expect(page.locator('[data-content="2"]')).toHaveClass(/active/);
  await page.locator('#prevBtn').click();
  await expect(page.locator('[data-content="1"]')).toHaveClass(/active/);
  await step(5).click();
  await expect(page.locator('[data-content="5"]')).toHaveClass(/active/);
  await page.locator('#cc-saveBtn').click();
  await expect.poll(() => page.evaluate(() => window.__stepperFixture.writes.length)).toBe(1);
  const [saved] = await page.evaluate(() => window.__stepperFixture.writes);
  expect(saved.event).toMatchObject({title:'Noche guardada',city:'Pereira',status:'draft'});
  expect(saved.event.description).toBe('Descripción temporal que debe conservarse');
});
