import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const blogJs = readFileSync(join(process.cwd(), 'discadmin/blog.js'), 'utf8');
const mediaJs = readFileSync(join(process.cwd(), 'discadmin/media-library.js'), 'utf8');
const bulkJs = readFileSync(join(process.cwd(), 'discadmin/bulk-actions.js'), 'utf8');
const activityJs = readFileSync(join(process.cwd(), 'discadmin/admin-activity.js'), 'utf8');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

const fakeAbortScript = `
  const NativeAbortController = window.AbortController;
  window.AbortController = class {
    constructor(){ this.signal = new NativeAbortController().signal; }
    abort(){}
  };
`;

function json(route, data) {
  return route.fulfill({ contentType: 'application/json; charset=utf-8', body: JSON.stringify(data) });
}

test('Blog editor keeps the last requested post when an older detail resolves later', async ({ page }) => {
  const posts = [
    {id:1,title:'Old intent',slug:'old-intent',excerpt:'A',body:'A',status:'draft',tags:[],relations:[]},
    {id:2,title:'Latest intent',slug:'latest-intent',excerpt:'B',body:'B',status:'draft',tags:[],relations:[]},
  ];
  let savedId = null;

  await page.route('**/api/blog.php**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const id = Number(url.searchParams.get('id') || 0);
    if (request.method() === 'GET' && id) {
      await delay(id === 1 ? 180 : 20);
      return json(route, {ok:true,data:posts.find(post => post.id === id)});
    }
    if (request.method() === 'GET') return json(route, {ok:true,data:posts});
    savedId = id;
    return json(route, {ok:true,data:{...posts.find(post => post.id === id),...request.postDataJSON()}});
  });
  for (const path of ['events','artists','sets']) {
    await page.route(`**/api/index.php/${path}`, route => json(route, {ok:true,data:[]}));
  }
  await page.route('**/api/releases.php', route => json(route, {ok:true,data:[]}));

  const url = 'http://127.0.0.1:4173/discadmin/latest-blog.html';
  await page.route(url, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><body>
      <section data-admin-module="blog"><div id="blog-status"></div><button id="blog-new"></button><span id="blog-total"></span><span id="blog-published"></span><span id="blog-drafts"></span><span id="blog-featured"></span><input id="blog-search"><select id="blog-status-filter"><option value=""></option></select><div id="blog-grid"></div></section>
      <div id="modal"><h2 id="mtitle"></h2><div id="notice"></div><div id="mcontent"></div><button id="saveBtn">GUARDAR</button></div>
      <script>window.csrf='ci';window.closeModal=()=>{};${fakeAbortScript}</script>
      <script>${blogJs}</script>
      <script>BRVTALBlog.mount(document.querySelector('[data-admin-module="blog"]'));</script>
    </body>`,
  }));
  await page.goto(url);
  await expect(page.locator('[data-blog-id]')).toHaveCount(2);

  await page.evaluate(() => {
    BRVTALBlog.openEditor(1);
    setTimeout(() => BRVTALBlog.openEditor(2), 10);
  });
  await expect(page.locator('#blog_title')).toHaveValue('Latest intent');
  await delay(240);
  await expect(page.locator('#blog_title')).toHaveValue('Latest intent');
  await page.locator('#saveBtn').click();
  await expect.poll(() => savedId).toBe(2);
});

test('Media Library keeps the latest asset selected and mutations stay on that asset', async ({ page }) => {
  const assets = [
    {id:11,type:'image',title:'Old asset',file_path:'/uploads/old.jpg',mime_type:'image/jpeg',file_size:100,status:'published',created_at:'2026-09-15 10:00:00',engine:{status:'ready',variants:{}},usage:[]},
    {id:12,type:'image',title:'Latest asset',file_path:'/uploads/latest.jpg',mime_type:'image/jpeg',file_size:200,status:'published',created_at:'2026-09-15 10:01:00',engine:{status:'ready',variants:{}},usage:[]},
  ];
  let updatedId = null;

  await page.route('**/uploads/**', route => route.fulfill({status:200,contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg"></svg>'}));
  await page.route('**/api/auth', route => json(route, {authenticated:true,csrf:'ci'}));
  await page.route('**/api/media-library.php**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const action = url.searchParams.get('action') || 'list';
    const id = Number(url.searchParams.get('id') || 0);
    if (action === 'list') return json(route, {ok:true,data:assets,engine:{gd:true,webp:true}});
    if (action === 'detail') {
      await delay(id === 11 ? 180 : 20);
      return json(route, {ok:true,data:assets.find(asset => asset.id === id)});
    }
    if (action === 'update') {
      updatedId = id;
      return json(route, {ok:true,data:{...assets.find(asset => asset.id === id),...request.postDataJSON()}});
    }
    return json(route, {ok:true,data:assets.find(asset => asset.id === id) || assets[1]});
  });

  const url = 'http://127.0.0.1:4173/discadmin/latest-media.html';
  await page.route(url, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><body>
      <section data-admin-module="media"><input id="media-search"><select id="media-type-filter"><option value=""></option></select><select id="media-month-filter"></select><button id="media-upload"></button><input id="media-file" type="file"><button id="media-register"></button><div id="media-dropzone"></div><div id="media-status"></div><div id="media-summary"></div><div id="media-grid"></div><aside id="media-inspector"></aside></section>
      <script>${fakeAbortScript}</script><script>${mediaJs}</script><script>BRVTALMediaLibrary.mount(document.querySelector('[data-admin-module="media"]'));</script>
    </body>`,
  }));
  await page.goto(url);
  await expect(page.locator('[data-media-id]')).toHaveCount(2);

  await page.evaluate(() => {
    document.querySelector('[data-media-id="11"]').click();
    setTimeout(() => document.querySelector('[data-media-id="12"]').click(), 10);
  });
  await expect(page.locator('#media-inspector h3')).toHaveText('Latest asset');
  await delay(240);
  await expect(page.locator('#media-inspector h3')).toHaveText('Latest asset');
  await page.locator('#media-edit-title').fill('Latest asset edited');
  await page.getByRole('button', {name:'SAVE', exact:true}).click();
  await expect.poll(() => updatedId).toBe(12);
});

test('Bulk Actions ignores an older module load and posts only IDs from the current catalog', async ({ page }) => {
  let mutation = null;
  await page.route('**/api/index.php/events', async route => {
    await delay(180);
    return json(route, {ok:true,data:[{id:11,title:'Genesis',status:'draft'}]});
  });
  await page.route('**/api/index.php/artists', async route => {
    await delay(20);
    return json(route, {ok:true,data:[{id:21,name:'PL0N3R',status:'draft'}]});
  });
  await page.route('**/api/bulk-actions.php', route => {
    mutation = route.request().postDataJSON();
    return json(route, {ok:true,data:{matched:1,changed:1}});
  });

  const url = 'http://127.0.0.1:4173/discadmin/latest-bulk.html';
  await page.route(url, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><body>
      <div class="nav"><button class="active" data-admin-nav="events">EVENTS</button></div><div class="main"><div class="top"><h1>EVENTS</h1><span class="status"></span></div></div>
      <script>window.csrf='ci';window.go=async()=>{};window.BRVTALFeedback={success:()=>{},error:m=>window.__bulkError=m};${fakeAbortScript}</script><script>${bulkJs}</script>
    </body>`,
  }));
  page.on('dialog', dialog => dialog.accept());
  await page.goto(url);

  await page.evaluate(() => {
    BRVTALBulkActions.open('events');
    setTimeout(() => { BRVTALBulkActions.close(); BRVTALBulkActions.open('artists'); }, 10);
  });
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByText('PL0N3R', {exact:true})).toBeVisible();
  await delay(240);
  await expect(dialog.getByText('PL0N3R', {exact:true})).toBeVisible();
  await expect(dialog.getByText('Genesis', {exact:true})).toHaveCount(0);

  await dialog.locator('[data-bulk-id="21"]').check();
  await dialog.getByRole('combobox', {name:'Bulk status action'}).selectOption('published');
  await dialog.getByRole('button', {name:'APPLY STATUS'}).click();
  await expect.poll(() => mutation).toEqual({action:'set_status',resource:'artists',status:'published',ids:[21]});
});

test('Admin Activity keeps the latest detail/history intent when an older request resolves later', async ({ page }) => {
  const detail = {id:41,resource:'events',resource_id:9,resource_label:'GENESIS',action:'update',changed_fields:['status'],before:{status:'draft'},after:{status:'published'},created_at:'2026-09-15 10:00:00'};
  const historyItem = {id:42,resource:'artists',resource_id:12,resource_label:'PL0N3R',action:'update',changed_fields:['name'],before:{name:'Old'},after:{name:'PL0N3R'},created_at:'2026-09-15 10:01:00'};
  await page.route('**/api/admin-activity.php*', async route => {
    const url = new URL(route.request().url());
    if (url.searchParams.get('id') === '41') {
      await delay(180);
      return json(route, {ok:true,data:detail});
    }
    if (url.searchParams.get('history') === '1') {
      await delay(20);
      return json(route, {ok:true,data:{items:[historyItem],total:1}});
    }
    return json(route, {ok:true,data:{items:[detail],total:1,limit:12}});
  });

  const url = 'http://127.0.0.1:4173/discadmin/latest-activity.html';
  await page.route(url, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><body><main class="main"><div class="top"><h1>DASHBOARD</h1></div></main><script>var state={authed:true,section:'dashboard'};window.go=async()=>{};window.BRVTALFeedback={error:m=>window.__activityError=m};${fakeAbortScript}</script><script>${activityJs}</script></body>`,
  }));
  await page.goto(url);
  await expect(page.getByRole('heading', {name:'ADMIN ACTIVITY'})).toBeVisible();

  await page.evaluate(() => {
    BRVTALAdminActivity.openDetail(41);
    setTimeout(() => BRVTALAdminActivity.openHistory('artists', 12, 'PL0N3R'), 10);
  });
  await expect(page.getByRole('dialog', {name:'Editorial version history'})).toBeVisible();
  await expect(page.getByRole('dialog')).toContainText('PL0N3R');
  await delay(240);
  await expect(page.getByRole('dialog', {name:'Editorial version history'})).toBeVisible();
  await expect(page.getByRole('dialog')).toContainText('PL0N3R');
  await expect(page.locator('#brvtal-activity-modal')).not.toContainText('ADMIN HISTORY / #41');
});
