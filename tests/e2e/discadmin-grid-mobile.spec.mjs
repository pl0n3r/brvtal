import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = readFileSync(join(process.cwd(), 'discadmin/admin-data-grid.css'), 'utf8');
const js = readFileSync(join(process.cwd(), 'discadmin/admin-data-grid.js'), 'utf8');
const deleteHandler = readFileSync(join(process.cwd(), 'discadmin/index-core.php'), 'utf8');
// Genuine previous CSS, unchanged above the #1866 scoped block.
const baseline = css.split('/* #1866 mobile event cards:')[0];
const events = [
  {id:9,title:'Events en vivo · edición industrial',slug:'events-9',event_date:'2026-11-22 22:00:00',city:'Pereira',status:'draft'},
  {id:3,title:'Alpha',slug:'alpha',event_date:'2026-11-23 21:00:00',city:'Bogotá',status:'published'}
];

async function mount(page, stylesheet){
  await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+stylesheet+'</style></head><body style="margin:8px;background:#090b0c;color:#f2f2f2;font-family:Arial,sans-serif"><div id="events"></div></body></html>');
  await page.evaluate(()=>{
    window.csrf='fixture';
    window.__deleteCalls=[];
    window.__opened=[];
    window.BRVTALFeedback={error(){},success(){}};
    window.BRVTALBulkActions={supports:()=>true,open(){}};
    window.openLineup=id=>window.__opened.push(['lineup',id]);
    window.openModal=(name,id)=>window.__opened.push(['edit',name,id]);
    // The real window.del in index-core.php owns the confirmation.
    window.del=(type,id)=>{if(!window.confirm('Eliminar este registro?'))return;window.__deleteCalls.push([type,id]);};
    window.fetch=async()=>new Response(JSON.stringify({ok:true,data:{columns:['primary','date','location','status']}}),{status:200,headers:{'Content-Type':'application/json'}});
  });
  await page.evaluate(source=>window.eval(source),js);
  await page.evaluate(rows=>BRVTALDataGrid.render('events',document.getElementById('events'),rows,{allRows:rows}),events);
  await expect(page.locator('#events [data-grid-row-id]')).toHaveCount(2);
}

test('390px Events cards do not scroll horizontally and expose status and actions', async ({page})=>{
  await page.setViewportSize({width:390,height:844});
  await mount(page,css);
  const width=await page.locator('#events .admin-data-grid-scroll').evaluate(el=>({client:el.clientWidth,scroll:el.scrollWidth}));
  expect(width.scroll).toBeLessThanOrEqual(width.client+1);
  const card=page.locator('#events [data-grid-row-id="9"]');
  await expect(card.locator('[data-grid-cell="primary"] .title')).toHaveText('Events en vivo · edición industrial');
  for(const key of ['date','status'])await expect(card.locator('[data-grid-cell="'+key+'"]')).toBeVisible();
  for(const target of [
    card.locator('.admin-grid-select-hit'),
    card.locator('[data-grid-action="lineup"]'),
    card.locator('[data-grid-action="edit"]'),
    card.locator('[data-grid-action="delete"]')
  ]){
    const box=await target.boundingBox();
    expect(box.width).toBeGreaterThanOrEqual(44);
    expect(box.height).toBeGreaterThanOrEqual(44);
    expect(box.x+box.width).toBeLessThanOrEqual(390);
  }
  const edit=await card.locator('[data-grid-action="edit"]').boundingBox();
  const del=await card.locator('[data-grid-action="delete"]').boundingBox();
  expect(del.y).toBeGreaterThan(edit.y+edit.height+4);
  await card.locator('.admin-grid-select-hit').click();
  await expect(card.locator('[data-grid-select="9"]')).toBeChecked();
});

test('1440px and 600px retain original table and header behavior',async ({page})=>{
  await page.setViewportSize({width:1440,height:900});
  await mount(page,css);
  await expect(page.locator('#events .admin-data-grid-head')).toBeVisible();
  for(const key of ['primary','date','location','status'])
    await expect(page.locator('#events [data-grid-column-key="'+key+'"]')).toBeVisible();
  const layout=await page.locator('[data-grid-row-id="9"]').evaluate(el=>getComputedStyle(el).gridTemplateColumns);
  expect(layout.trim().split(/\s+/).length).toBeGreaterThanOrEqual(6);
  await page.setViewportSize({width:600,height:800});
  const scroll=await page.locator('#events .admin-data-grid-scroll').evaluate(el=>({client:el.clientWidth,scroll:el.scrollWidth}));
  expect(scroll.scroll).toBeGreaterThan(scroll.client);
});

test('DELETE remains protected by the canonical admin confirmation',async ({page})=>{
  expect(deleteHandler).toMatch(/async function del\(type,id\)\{if\(!confirm\(/);
  await page.setViewportSize({width:390,height:844});
  await mount(page,css);
  const del=page.locator('#events [data-grid-row-id="9"] [data-grid-action="delete"]');
  page.once('dialog',dialog=>dialog.dismiss());
  await del.click();
  expect(await page.evaluate(()=>window.__deleteCalls)).toEqual([]);
  page.once('dialog',dialog=>dialog.accept());
  await del.click();
  expect(await page.evaluate(()=>window.__deleteCalls)).toEqual([['events',9]]);
});

test('browser captures real baseline and after at 390px and 1440px',async ({page},testInfo)=>{
  for(const width of [390,1440]){
    await page.setViewportSize({width,height:900});
    await mount(page,baseline);
    await testInfo.attach('before-'+width+'.png',{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await mount(page,css);
    await testInfo.attach('after-'+width+'.png',{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
  }
});
