import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const memoriesJs = readFileSync(join(process.cwd(), 'discadmin/memories.js'), 'utf8');
const memoriesCss = readFileSync(join(process.cwd(), 'discadmin/memories.css'), 'utf8');
const modalAccessibilityJs = readFileSync(join(process.cwd(), 'discadmin/admin-modal-accessibility.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin-memories-e2e.html';

const initialMemory = {
  id: 7,
  media_id: 12,
  title: 'Existing Memory',
  context: 'Selected archive record',
  status: 'published',
  sort_order: 10,
  media_type: 'image',
  media_title: 'Existing source',
  file_path: '/uploads/media/existing.jpg',
  mime_type: 'image/jpeg',
  file_size: 1200,
  alt_text: 'Existing memory image',
  media_status: 'published',
};

async function mount(page) {
  let memories = [{...initialMemory}];
  let available = [
    {id:11,type:'video',title:'New floor clip',file_path:'/uploads/media/new.mp4',mime_type:'video/mp4',file_size:2200,alt_text:'',status:'published',memory_id:null},
    {id:12,type:'image',title:'Existing source',file_path:'/uploads/media/existing.jpg',mime_type:'image/jpeg',file_size:1200,alt_text:'Existing memory image',status:'published',memory_id:7},
    {id:13,type:'audio',title:'Draft source',file_path:'/uploads/media/draft.mp3',mime_type:'audio/mpeg',file_size:800,alt_text:'',status:'draft',memory_id:null},
  ];
  const requests = [];

  await page.route('**/uploads/media/**', route => route.fulfill({status:200,contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=','base64')}));
  await page.route('**/api/memories.php**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const action = url.searchParams.get('action') || 'list';
    const id = Number(url.searchParams.get('id') || 0);
    const method = request.method();
    let body = null;
    try { body = request.postDataJSON(); } catch (_) {}
    requests.push({action,id,method,body,csrf:request.headers()['x-csrf-token'] || ''});

    if (method === 'GET' && action === 'list') return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:memories})});
    if (method === 'GET' && action === 'available') return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:available})});
    if (method === 'POST' && action === 'create') {
      const source = available.find(item => item.id === Number(body.media_id));
      const next = {
        id:21,media_id:source.id,title:body.title,context:body.context,status:body.status,sort_order:body.sort_order,
        media_type:source.type,media_title:source.title,file_path:source.file_path,mime_type:source.mime_type,file_size:source.file_size,
        alt_text:source.alt_text,media_status:source.status,
      };
      memories.push(next);
      available = available.map(item => item.id === source.id ? {...item,memory_id:next.id} : item);
      return route.fulfill({status:201,contentType:'application/json',body:JSON.stringify({ok:true,data:next})});
    }
    if (method === 'PUT' && action === 'update') {
      memories = memories.map(item => item.id === id ? {...item,...body,media_type:item.media_type,media_title:item.media_title,file_path:item.file_path,mime_type:item.mime_type,file_size:item.file_size,alt_text:item.alt_text,media_status:item.media_status} : item);
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:memories.find(item => item.id === id)})});
    }
    if (method === 'DELETE') {
      const removed = memories.find(item => item.id === id);
      memories = memories.filter(item => item.id !== id);
      available = available.map(item => item.id === removed?.media_id ? {...item,memory_id:null} : item);
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,deleted_id:id})});
    }
    return route.fulfill({status:400,contentType:'application/json',body:JSON.stringify({ok:false,error:'UNEXPECTED_REQUEST'})});
  });

  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><head><style>:root{--red:#ff2038}${memoriesCss}</style></head><body>
      <div class="shell"><aside class="side"><div class="nav"><button data-admin-nav="dashboard">DASHBOARD</button><div class="ia-navgroup">MEDIA</div><button data-admin-nav="media">MEDIA LIBRARY</button><button data-admin-nav="hero-slider">HERO SLIDER</button></div></aside><main class="main"><div class="top"><h1>DASHBOARD</h1></div><div id="admin-module-host"></div></main></div>
      <script>
        window.state={authed:true,section:'dashboard'};
        window.csrf='csrf-memory';
        window.BRVTALFeedback={success:(m)=>window.__feedback=m,error:(m)=>window.__error=m};
        window.go=async section=>{window.state.section=section;document.querySelector('.main>.top h1').textContent=section.toUpperCase();let h=document.getElementById('admin-module-host');if(!h){h=document.createElement('div');h.id='admin-module-host';document.querySelector('.main').appendChild(h);}h.replaceChildren();return true;};
      </script>
      <script>${modalAccessibilityJs}</script>
      <script>${memoriesJs}</script>
    </body></html>`,
  }));
  await page.goto(harnessUrl);
  return {requests};
}

test('Memories stays inside the DISCADMIN shell and curates existing Media Library assets', async ({ page }) => {
  const state = await mount(page);

  const navLabels = await page.locator('.side .nav > button').allTextContents();
  expect(navLabels).toEqual(['DASHBOARD','MEDIA LIBRARY','MEMORIES','HERO SLIDER']);
  const memoriesNav = page.getByRole('button', {name:'MEMORIES'});
  await expect(memoriesNav).toBeVisible();
  await memoriesNav.click();
  await expect(page.getByRole('heading', {name:'MEMORIES', level:2})).toBeVisible();
  await expect(page.locator('.shell')).toHaveCount(1);
  await expect(page.locator('.side')).toHaveCount(1);
  await expect(page).toHaveURL(/module=media&view=memories/);
  await expect(page.locator('[data-memory-id]')).toHaveCount(1);
  await expect(page.locator('[data-memory-id="7"] [data-memory-title]')).toHaveValue('Existing Memory');

  const add = page.getByRole('button', {name:'+ ADD MEMORY'});
  await add.focus();
  await add.click();
  await expect(page.getByRole('dialog', {name:'Select media for a Memory'})).toBeVisible();
  await expect(page.locator('[data-memories-picker-search]')).toBeFocused();
  await page.getByRole('button', {name:'CLOSE ×'}).focus();
  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('button', {name:/Draft source/})).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', {name:'CLOSE ×'})).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(add).toBeFocused();

  await add.click();
  await expect(page.getByRole('button', {name:/Existing source/})).toBeDisabled();
  await page.getByRole('button', {name:/New floor clip/}).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(page.locator('[data-memory-id]')).toHaveCount(2);
  await expect(page.locator('[data-memory-id="21"] [data-memory-title]')).toHaveValue('New floor clip');

  const create = state.requests.find(entry => entry.method === 'POST' && entry.action === 'create');
  expect(create).toBeTruthy();
  expect(create.csrf).toBe('csrf-memory');
  expect(create.body.media_id).toBe(11);
  expect(create.body.status).toBe('draft');

  await page.evaluate(() => {
    history.pushState({}, '', '?module=media');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });
  await expect(page.getByRole('heading', {name:'MEMORIES', level:2})).toHaveCount(0);
  await expect(page.locator('.main > .top h1')).toHaveText('MEDIA');
});

test('Memories title/order/status edits persist and removing curation keeps the source asset available', async ({ page }) => {
  const state = await mount(page);
  await page.getByRole('button', {name:'MEMORIES'}).click();

  const card = page.locator('[data-memory-id="7"]');
  await card.locator('[data-memory-title]').fill('Edited public title');
  await card.locator('[data-memory-context]').fill('Updated context');
  await card.locator('[data-memory-order]').fill('3');
  await card.locator('[data-memory-status]').selectOption('draft');
  await card.getByRole('button', {name:'SAVE'}).click();
  await expect(page.locator('[data-memory-id="7"] [data-memory-title]')).toHaveValue('Edited public title');

  const update = state.requests.find(entry => entry.method === 'PUT' && entry.id === 7);
  expect(update).toBeTruthy();
  expect(update.body).toMatchObject({media_id:12,title:'Edited public title',context:'Updated context',sort_order:3,status:'draft'});
  expect(update.csrf).toBe('csrf-memory');

  page.once('dialog', dialog => dialog.accept());
  await page.locator('[data-memory-id="7"]').getByRole('button', {name:'REMOVE'}).click();
  await expect(page.locator('[data-memory-id="7"]')).toHaveCount(0);
  const deletion = state.requests.find(entry => entry.method === 'DELETE' && entry.id === 7);
  expect(deletion).toBeTruthy();
  expect(deletion.csrf).toBe('csrf-memory');

  await page.getByRole('button', {name:'+ ADD MEMORY'}).click();
  await expect(page.getByRole('button', {name:/Existing source/})).toBeEnabled();
});
