import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const php = readFileSync(join(process.cwd(), 'discadmin/seo-workspace.php'), 'utf8');
const markup = php.split('?>').slice(1).join('?>');
const js = readFileSync(join(process.cwd(), 'discadmin/seo-workspace.js'), 'utf8');
const css = readFileSync(join(process.cwd(), 'discadmin/seo-workspace.css'), 'utf8');
const url = 'http://127.0.0.1:4173/seo-workspace-e2e.html';

function items() {
  return [
    {
      kind:'static',key:'home',resource:'static',id:null,type:'HOME',label:'Home',path:'/',
      canonical:'https://www.brvtal.com.co/',status:'public',public:true,mode:'AUTO',
      seo_title:'',seo_description:'',share_image:'',
      automatic_title:'BRVTAL — Rave till Grave',
      automatic_description:'BRVTAL — Rave till Grave. Underground electronic music, experiences and events from Colombia.',
      automatic_image:'https://www.brvtal.com.co/assets/brvtal-logo.jpeg',
      effective_title:'BRVTAL — Rave till Grave',
      effective_description:'BRVTAL — Rave till Grave. Underground electronic music, experiences and events from Colombia.',
      effective_image:'https://www.brvtal.com.co/assets/brvtal-logo.jpeg',
      fallback_title_source:'AUTO FROM ROUTE DEFAULT',
      fallback_description_source:'AUTO FROM ROUTE DEFAULT',
      warnings:[]
    },
    {
      kind:'entity',key:'events:42',resource:'events',id:42,type:'EVENT',label:'Genesis',path:'/events/genesis',
      canonical:'https://www.brvtal.com.co/events/genesis',status:'draft',public:false,mode:'MIXED',
      seo_title:'Genesis Search',seo_description:'',share_image:'',
      automatic_title:'Genesis — BRVTAL',
      automatic_description:'Underground techno in Pereira.',
      automatic_image:'https://www.brvtal.com.co/uploads/genesis.webp',
      effective_title:'Genesis Search — BRVTAL',
      effective_description:'Underground techno in Pereira.',
      effective_image:'https://www.brvtal.com.co/uploads/genesis.webp',
      fallback_title_source:'AUTO FROM EVENT TITLE',
      fallback_description_source:'AUTO FROM EVENT CONTENT',
      warnings:['PRIVATE_WITH_OVERRIDE']
    },
    {
      kind:'entity',key:'blog:7',resource:'blog',id:7,type:'BLOG',label:'Signal Notes',path:'/blog/signal-notes',
      canonical:'https://www.brvtal.com.co/blog/signal-notes',status:'published',public:true,mode:'MANUAL',
      seo_title:'Signal Notes BRVTAL',seo_description:'Editorial transmission.',share_image:'',
      automatic_title:'Signal Notes — BRVTAL',
      automatic_description:'Editorial transmission from Pereira.',
      automatic_image:'https://www.brvtal.com.co/uploads/signal.webp',
      effective_title:'Signal Notes BRVTAL',
      effective_description:'Editorial transmission.',
      effective_image:'https://www.brvtal.com.co/uploads/signal.webp',
      fallback_title_source:'AUTO FROM BLOG TITLE',
      fallback_description_source:'AUTO FROM BLOG CONTENT',
      warnings:[]
    }
  ];
}

function harness() {
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">
  <style>body{margin:0;background:#050505;color:#fff;font-family:Arial}.btn{border:1px solid #444;background:#111;color:#fff;padding:10px}.btn.red{background:#ff2038}.btn.ghost{background:transparent}.search{background:#090a0b;color:#fff}</style>
  <style>${css}</style></head><body><main class="main">${markup}</main><script>
    window.csrf='seo-csrf';
    window.__seoItems=${JSON.stringify(items())};
    window.__seoWrites=[];
    window.__seoFail=false;
    window.__feedback=[];
    window.BRVTALFeedback={
      success(message){window.__feedback.push(['success',message])},
      error(message){window.__feedback.push(['error',message])}
    };
    window.fetch=async function(input,options={}){
      const target=String(input);
      if(target==='/api/seo-workspace.php' && (!options.method || options.method==='GET')){
        const rows=window.__seoItems;
        return new Response(JSON.stringify({ok:true,data:rows,summary:{
          total:rows.length,
          auto:rows.filter(row=>row.mode==='AUTO').length,
          manual:rows.filter(row=>row.mode!=='AUTO').length,
          issues:rows.filter(row=>row.warnings.length).length
        }}),{status:200,headers:{'Content-Type':'application/json'}});
      }
      if(target==='/api/seo-workspace.php' && options.method==='PUT'){
        const body=JSON.parse(options.body);
        window.__seoWrites.push({body,csrf:options.headers['X-CSRF-Token']});
        if(window.__seoFail){
          return new Response(JSON.stringify({ok:false,error:'SEO_WRITE_FAILED'}),{status:503,headers:{'Content-Type':'application/json'}});
        }
        return new Response(JSON.stringify({ok:true,data:body}),{status:200,headers:{'Content-Type':'application/json'}});
      }
      return new Response(JSON.stringify({authenticated:true,csrf:'seo-csrf'}),{status:200,headers:{'Content-Type':'application/json'}});
    };
  </script><script>${js}</script><script>BRVTALSEOWorkspace.mount(document.querySelector('[data-admin-module="seo"]'));</script></body></html>`;
}

async function open(page, viewport={width:1280,height:900}) {
  await page.setViewportSize(viewport);
  await page.route('**/seo-workspace-e2e.html*', route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:harness()
  }));
  await page.goto(url);
  await expect(page.locator('.seo-workspace-row')).toHaveCount(3);
}

test('central SEO inventory exposes static and entity destinations with useful filters', async ({ page }) => {
  await open(page);
  await expect(page.locator('#seo-workspace-total')).toHaveText('3');
  await expect(page.locator('#seo-workspace-auto')).toHaveText('1');
  await expect(page.locator('#seo-workspace-manual')).toHaveText('2');
  await expect(page.getByText('Home',{exact:true})).toBeVisible();
  await expect(page.getByText('Genesis',{exact:true})).toBeVisible();
  await expect(page.getByText('Signal Notes',{exact:true})).toBeVisible();

  await page.locator('#seo-workspace-type').selectOption('EVENT');
  await expect(page.locator('.seo-workspace-row')).toHaveCount(1);
  await expect(page.getByText('Genesis',{exact:true})).toBeVisible();

  await page.locator('#seo-workspace-type').selectOption('');
  await page.locator('#seo-workspace-search').fill('signal');
  await expect(page.locator('.seo-workspace-row')).toHaveCount(1);
  await expect(page.getByText('Signal Notes',{exact:true})).toBeVisible();

  await page.locator('#seo-workspace-search').fill('');
  await page.locator('#seo-workspace-health').selectOption('issues');
  await expect(page.locator('.seo-workspace-row')).toHaveCount(1);
  await expect(page.getByText('Genesis',{exact:true})).toBeVisible();
});

test('static destination supports AUTO preview, manual overrides and per-field reset', async ({ page }) => {
  await open(page);
  await page.locator('[data-seo-open="home"]').click();

  const editor = page.locator('#seo-workspace-editor');
  await expect(editor).toBeVisible();
  await expect(page.locator('#seo-editor-title-input')).toHaveValue('');
  await expect(page.locator('#seo-editor-title-input')).toHaveAttribute('placeholder','BRVTAL — Rave till Grave');
  await expect(page.locator('#seo-editor-mode')).toHaveText('AUTO');
  await expect(page.locator('#seo-editor-image-field')).toBeVisible();

  await page.locator('#seo-editor-title-input').fill('BRVTAL Underground');
  await expect(page.locator('#seo-editor-mode')).toHaveText('MIXED');
  await expect(page.locator('#seo-editor-preview-title')).toHaveText('BRVTAL Underground');
  await page.locator('[data-seo-reset="title"]').click();
  await expect(page.locator('#seo-editor-mode')).toHaveText('AUTO');
  await expect(page.locator('#seo-editor-preview-title')).toHaveText('BRVTAL — Rave till Grave');

  await page.locator('#seo-editor-title-input').fill('BRVTAL Search');
  await page.locator('#seo-editor-description-input').fill('Canonical home description.');
  await page.locator('#seo-editor-image-input').fill('/uploads/share.webp');
  await page.locator('#seo-workspace-form').evaluate(form => form.requestSubmit());

  await expect.poll(() => page.evaluate(() => window.__seoWrites.length)).toBe(1);
  const write = await page.evaluate(() => window.__seoWrites[0]);
  expect(write.csrf).toBe('seo-csrf');
  expect(write.body).toMatchObject({
    kind:'static',key:'home',
    seo_title:'BRVTAL Search',
    seo_description:'Canonical home description.',
    share_image:'/uploads/share.webp'
  });
  await expect(editor).toBeHidden();
  await expect.poll(() => page.evaluate(() => window.__feedback.at(-1))).toEqual(['success','SEO metadata saved.']);
});

test('entity reset sends blanks through the shared persistence boundary without an image override', async ({ page }) => {
  await open(page);
  await page.locator('[data-seo-open="events:42"]').click();

  await expect(page.locator('#seo-editor-title-input')).toHaveValue('Genesis Search');
  await expect(page.locator('#seo-editor-image-field')).toBeHidden();
  await page.locator('[data-seo-reset="title"]').click();
  await expect(page.locator('#seo-editor-preview-title')).toHaveText('Genesis — BRVTAL');
  await page.locator('#seo-workspace-form').evaluate(form => form.requestSubmit());

  const write = await expect.poll(async () => page.evaluate(() => window.__seoWrites.at(-1))).not.toBeNull();
  const payload = await page.evaluate(() => window.__seoWrites.at(-1).body);
  expect(payload.kind).toBe('entity');
  expect(payload.resource).toBe('events');
  expect(payload.id).toBe(42);
  expect(payload.seo_title).toBe('');
  expect(payload.seo_description).toBe('');
  expect(payload).not.toHaveProperty('share_image');
});

test('failed save remains visible and preserves authored input for retry', async ({ page }) => {
  await open(page);
  await page.locator('[data-seo-open="blog:7"]').click();
  await page.locator('#seo-editor-description-input').fill('Unsaved retry description.');
  await page.evaluate(() => { window.__seoFail = true; });
  await page.locator('#seo-workspace-form').evaluate(form => form.requestSubmit());

  await expect(page.locator('#seo-workspace-editor')).toBeVisible();
  await expect(page.locator('#seo-editor-description-input')).toHaveValue('Unsaved retry description.');
  await expect(page.locator('#seo-workspace-status')).toContainText('SEO_WRITE_FAILED');
  await expect.poll(() => page.evaluate(() => window.__feedback.at(-1))).toEqual(['error','SEO save failed: SEO_WRITE_FAILED']);
});

test('SEO workspace remains usable at 390px without horizontal overflow', async ({ page }) => {
  await open(page,{width:390,height:844});
  const metrics = await page.evaluate(() => ({
    width:window.innerWidth,
    scrollWidth:document.documentElement.scrollWidth,
    filters:[...document.querySelectorAll('.seo-workspace-toolbar input,.seo-workspace-toolbar select')]
      .map(node => node.getBoundingClientRect().height),
    edits:[...document.querySelectorAll('.seo-workspace-edit')].map(node => node.getBoundingClientRect().height),
  }));
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.width + 1);
  expect(Math.min(...metrics.filters)).toBeGreaterThanOrEqual(44);
  expect(Math.min(...metrics.edits)).toBeGreaterThanOrEqual(38);
});
