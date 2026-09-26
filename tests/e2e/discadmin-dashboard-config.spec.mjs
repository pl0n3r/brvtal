import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const dashboardJs = readFileSync(join(process.cwd(),'discadmin/dashboard-v2.js'),'utf8');
const harness='http://127.0.0.1:4173/discadmin/dashboard-config-e2e.html';

const defaults = {
  modules:[
    {id:'next_event',width:2,height:1,visible:true},
    {id:'attention',width:2,height:1,visible:true},
    {id:'drafts',width:2,height:1,visible:true},
    {id:'operations',width:2,height:1,visible:true},
    {id:'activity',width:2,height:1,visible:true},
    {id:'quick_create',width:2,height:1,visible:true},
    {id:'analytics',width:2,height:1,visible:false},
  ],
};

test('Dashboard persists layout controls and loads Recent Changes five at a time', async ({page}) => {
  let preferences=structuredClone(defaults);
  const saved=[];
  let activePosts=0;
  let maxConcurrentPosts=0;
  await page.route(harness,route=>route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body><main class="main"><div class="top"><span class="status"></span></div></main>
      <script>var state={authed:true,section:'dashboard'};window.BRVTALAdminAuthBoundary={csrfToken:async()=>{window.__csrfCalls=(window.__csrfCalls||0)+1;return 'boundary-csrf'}};window.go=s=>{window.__went=s};window.tech=()=>{};window.openModal=()=>{};</script>
      <script>${dashboardJs}</script></body></html>`,
  }));
  await page.route('**/api/dashboard-overview.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{summary:{public_records:7,draft_records:2,active_events:3,media_assets:9},next_event:null}})}));
  await page.route('**/api/content-health.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{public:{score:100,items:[]},drafts:{total:2,ready:1,needs_attention:1,items:[]}}})}));
  await page.route('**/api/index.php/health',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,database:'connected',php:'8.5.0',driver:'mysql'})}));
  await page.route('**/discadmin/storage-metrics.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{used:'1 GB',quota:'10 GB',used_percent:10,managed_files:4}})}));
  await page.route('**/api/admin-dashboard-preferences.php',async route=>{
    const request=route.request();
    if(request.method()==='POST'){
      expect(request.headers()['x-csrf-token']).toBe('boundary-csrf');
      activePosts+=1;
      maxConcurrentPosts=Math.max(maxConcurrentPosts,activePosts);
      const submitted=request.postDataJSON();
      saved.push(structuredClone(submitted));
      await new Promise(resolve=>setTimeout(resolve,saved.length===1?120:10));
      preferences=structuredClone(submitted);
      activePosts-=1;
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:preferences})});
    }
    await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:preferences})});
  });
  await page.route('**/api/admin-activity.php?**',route=>{
    const url=new URL(route.request().url());
    const cursor=url.searchParams.get('cursor');
    const start=cursor?6:1;
    const count=cursor?3:5;
    const items=Array.from({length:count},(_,i)=>({id:start+i,resource:'events',resource_id:start+i,resource_label:`EVENT #${start+i}`,action:'update',admin_name:'Admin',created_at:'2026-09-26 12:00:00'}));
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{items,total:8,limit:5,returned:count,has_more:!cursor,next_cursor:cursor?null:'5'}})});
  });

  await page.goto(harness);
  const root=page.locator('#brvtal-dashboard-v2');
  await expect(root).toBeVisible();
  await expect(root.locator('[data-dashboard-module]')).toHaveCount(6);
  await expect(root.getByRole('button',{name:/ANALYTICS/})).toBeVisible();

  const next=root.locator('[data-dashboard-module="next_event"]');
  await next.locator('[data-dashboard-resize="wider"]').click();
  await root.locator('[data-dashboard-module="quick_create"] [data-dashboard-hide]').click();
  await expect(root.locator('[data-dashboard-module="next_event"]')).toHaveAttribute('data-dashboard-width','3');
  await expect(root.getByRole('button',{name:/QUICK CREATE/})).toBeVisible();
  await expect.poll(()=>saved.length).toBeGreaterThanOrEqual(2);
  expect(saved.at(-1).modules.find(item=>item.id==='next_event').width).toBe(3);
  expect(saved.at(-1).modules.find(item=>item.id==='quick_create').visible).toBe(false);
  expect(maxConcurrentPosts).toBe(1);
  expect(await page.evaluate(()=>window.__csrfCalls)).toBeGreaterThanOrEqual(2);

  await root.locator('[data-dashboard-module="activity"] [data-dashboard-move="up"]').click();
  expect(saved.at(-1).modules.findIndex(item=>item.id==='activity')).toBe(3);

  await expect(root.locator('[data-dashboard-activity-list] .dashboard-v2-row')).toHaveCount(5);
  await root.locator('[data-dashboard-activity-more]').click();
  await expect(root.locator('[data-dashboard-activity-list] .dashboard-v2-row')).toHaveCount(8);
  await expect(root.locator('[data-dashboard-activity-more]')).toHaveCount(0);

  await root.locator('[data-dashboard-reset]').click();
  await expect(root.locator('[data-dashboard-module="next_event"]')).toHaveAttribute('data-dashboard-width','2');
  expect(saved.at(-1).modules.find(item=>item.id==='analytics').visible).toBe(false);

  await root.locator('.dashboard-v2-summary-action[data-dashboard-go="events"]').click();
  expect(await page.evaluate(()=>window.__went)).toBe('events');
});
