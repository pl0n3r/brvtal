import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';

const sliderScript=readFileSync('discadmin/hero-slider.js','utf8');
const mediaScript=readFileSync('discadmin/media-library.js','utf8');
const serverSource=readFileSync('api/media-library.php','utf8');
const url='http://127.0.0.1:4173/discadmin/media-publish-default-harness.html';
const image=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=','base64');
const path='/uploads/media/2026/11/ci-banners.png';

async function boot(page) {
  await page.route(url, route=>route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:'<!doctype html><html lang="es"><body><main class="main"><div class="top"><span class="eyebrow"></span><h1></h1></div></main></body></html>'
  }));
  const uploads=[];
  await page.route('**/api/media-library.php**', route=>{
    const action=new URL(route.request().url()).searchParams.get('action');
    if(action==='upload'){
      uploads.push({body:route.request().postDataBuffer(),method:route.request().method()});
      return route.fulfill({status:201,contentType:'application/json',body:JSON.stringify({
        ok:true,duplicate:false,reused:false,data:{
          id:72,title:'Imagen de prueba',type:'image',file_path:path,status:'published',
          mime_type:'image/png',file_size:image.length,created_at:'2026-11-22 00:00:00'
        }
      })});
    }
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[]})});
  });
  await page.goto(url);
  await page.evaluate(()=>{
    window.state={section:'dashboard'};
    window.go=async section=>{window.state.section=section;return true};
    window.render=()=>{};
    window.csrf='synthetic-csrf';
    window.__posted=[];
    window.__failureField='';
    window.__toasts=[];
    window.BRVTALFeedback={success:(s)=>window.__toasts.push(s),error:(s)=>window.__toasts.push(s)};
    window.req=async (route, options={})=>{
      if(route==='/settings?key=home.hero.slider')return {data:[{
        setting_key:'home.hero.slider',
        setting_value:JSON.stringify({enabled:true,slides:[{
          id:'slide-1',enabled:true,name:'SAVØR - SESSION 01',mediaType:'image',
          desktopSrc:'',mobileSrc:'',poster:'',layers:[{
            id:'layer-1',type:'image',name:'Logo',src:'',mobileSrc:''
          }]
        }]})
      }]};
      if(route==='/media?view=hero-picker')return {data:[]};
      if(route==='/settings'&&options.method==='POST'){
        if(window.__failureField){
          const error=new Error('HERO_SLIDER_MEDIA_FILE_MISSING · '+window.__failureField);
          error.code='HERO_SLIDER_MEDIA_FILE_MISSING';
          error.field=window.__failureField;
          throw error;
        }
        window.__posted.push(JSON.parse(options.body));
        return {ok:true,data:[]};
      }
      return {data:[]};
    };
  });
  await page.addScriptTag({content:mediaScript});
  await page.addScriptTag({content:sliderScript});
  await page.evaluate(()=>window.go('hero-slider'));
  await expect(page.locator('.hero-manager')).toBeVisible();
  await expect(page.locator('[data-media-picker="desktopSrc"]')).toBeEnabled();
  return uploads;
}

test('AC-01: the real upload implementation stores published media; editor exposes no Publish step',async()=>{
  // Verifies the production endpoint source contract even without PHP/MariaDB in the browser harness.
  expect(serverSource).toContain("$contentHash, $alt, 'published']");
  expect(serverSource).toContain("$size, $alt, 'published']");
  const inspector=readFileSync('discadmin/media-library.js','utf8');
  expect(inspector).not.toContain('id="media-edit-status"');
});

test('AC-02: uploading a synthetic image in Banners selects and saves without publishing separately',async({page})=>{
  const uploads=await boot(page);
  await page.locator('[data-media-picker="desktopSrc"]').click();
  const modal=page.getByRole('dialog',{name:'SELECT MEDIA'});
  await expect(modal).toBeVisible();
  await modal.locator('[data-media-file]').setInputFiles({
    name:'ci-banners.png',mimeType:'image/png',buffer:image
  });
  await expect(modal).toHaveCount(0);
  await expect(page.locator('input[data-field="desktopSrc"]')).toHaveValue(path);
  expect(uploads).toHaveLength(1);
  expect(uploads[0].method).toBe('POST');
  expect(uploads[0].body?.includes(image)).toBe(true);
  await page.locator('[data-save-slider]').click();
  await expect.poll(()=>page.evaluate(()=>window.__posted.length)).toBe(1);
  const stored=await page.evaluate(()=>JSON.parse(window.__posted[0].setting_value));
  expect(stored.slides[0].desktopSrc).toBe(path);
  await expect(page.locator('.hero-manager')).toBeVisible();
});

test('AC-02: server rejection tells the slide and mobile or layer field in Spanish',async({page})=>{
  await boot(page);
  await page.locator('input[data-field="desktopSrc"]').fill('https://cdn.example.test/valid.jpg');
  await page.locator('input[data-field="mobileSrc"]').fill('/uploads/media/missing.png');
  await page.evaluate(()=>{window.__failureField='slides.0.mobileSrc';});
  await page.locator('[data-save-slider]').click();
  await expect(page.locator('[data-hero-draft-state]')).toContainText('Diapositiva 1');
  await expect(page.locator('[data-hero-draft-state]')).toContainText('imagen móvil');
  await expect(page.locator('[data-hero-draft-state]')).toContainText('no existe');
  expect(await page.evaluate(()=>window.__posted)).toEqual([]);
  await page.evaluate(()=>{window.__failureField='slides.0.layers.0.src';});
  await page.locator('[data-save-slider]').click();
  await expect(page.locator('[data-hero-draft-state]')).toContainText('capa 1');
  await expect(page.locator('[data-hero-draft-state]')).toContainText('imagen de escritorio');
});
