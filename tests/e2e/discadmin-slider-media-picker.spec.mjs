import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const sliderScript = readFileSync(join(process.cwd(), 'discadmin/hero-slider.js'), 'utf8');
const libraryScript = readFileSync(join(process.cwd(), 'discadmin/media-library.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin-slider-media-picker-e2e.html';
const image = {id:11,type:'image',title:'Poster preview',file_path:'/uploads/media/poster.jpg',status:'published',created_at:'2026-11-18 12:00:00'};
const video = {id:12,type:'video',title:'Live performance',file_path:'/uploads/media/live.mp4',status:'published',created_at:'2026-11-18 12:00:00'};
const audio = {id:13,type:'audio',title:'DJ set',file_path:'/uploads/media/set.mp3',status:'published',created_at:'2026-11-18 12:00:00'};
const slide = {id:'slide-1',enabled:true,name:'First slide',mediaType:'image',desktopSrc:'',mobileSrc:'',poster:'',layers:[{id:'layer-1',name:'Logo',type:'image',src:'',mobileSrc:''}]};
const videoSlide = {id:'slide-2',enabled:true,name:'Video slide',mediaType:'video',desktopSrc:'',mobileSrc:'',poster:'',layers:[]};

async function openHarness(page, withSlider = true) {
  await page.route('**/api/media-library.php**', route => route.fulfill({
    contentType:'application/json',
    body:JSON.stringify({ok:true,data:[image,video,audio]}),
  }));
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:'<!doctype html><html><body><main class="main"><div class="top"><span class="eyebrow"></span><h1></h1></div></main><input id="f_cover_image"><input id="e_cover_image"></body></html>',
  }));
  await page.goto(harnessUrl);
  await page.evaluate(({ withSlider, slide, videoSlide, image, video }) => {
    window.state = {section:'dashboard'};
    window.go = async section => { window.state.section = section; return true; };
    window.render = () => {};
    window.csrf = 'synthetic-test';
    window.req = async path => {
      if (path === '/settings?key=home.hero.slider') return {data:[{setting_key:'home.hero.slider',setting_value:JSON.stringify({enabled:false,slides:[slide,videoSlide]})}]};
      if (path === '/media?view=hero-picker') return {data:[image,video]};
      return {data:[]};
    };
  }, {withSlider,slide,videoSlide,image,video});
  await page.addScriptTag({content:libraryScript});
  if (withSlider) {
    await page.addScriptTag({content:sliderScript});
    await page.evaluate(() => window.go('hero-slider'));
    await expect(page.locator('.hero-manager')).toBeVisible();
    await expect(page.locator('[data-media-picker="desktopSrc"]')).toBeEnabled();
  }
}

async function selectMedia(page, button, mediaId) {
  await button.click();
  const dialog = page.getByRole('dialog', {name:'SELECT MEDIA'});
  await expect(dialog).toBeVisible();
  await dialog.locator('[data-media-id="' + mediaId + '"]').click();
  await expect(dialog).toHaveCount(0);
}

test('Slider Manager uses Media modal on image, mobile and layer controls and refreshes preview', async ({page}) => {
  await openHarness(page);
  await expect(page.locator('.hero-manager select[data-media-picker], .hero-manager select[data-layer-media]')).toHaveCount(0);
  await expect(page.locator('.hero-manager [data-media-picker], .hero-manager [data-layer-media]')).toHaveCount(4);

  await selectMedia(page, page.locator('[data-media-picker="desktopSrc"]'), image.id);
  await expect(page.locator('input[data-field="desktopSrc"]')).toHaveValue(image.file_path);
  await expect(page.locator('.hero-preview-stage > img')).toHaveAttribute('src', image.file_path);
  await expect(page.locator('.hero-media-pair').first().locator('img')).toHaveCount(0);
  await expect(page.locator('.hero-manager')).toContainText('Sin guardar');

  await selectMedia(page, page.locator('[data-media-picker="mobileSrc"]'), image.id);
  await expect(page.locator('input[data-field="mobileSrc"]')).toHaveValue(image.file_path);
  await page.locator('[data-preview="mobile"]').click();
  await expect(page.locator('.hero-preview-stage > img')).toHaveAttribute('src', image.file_path);

  await selectMedia(page, page.locator('[data-layer-media="src"]'), image.id);
  await expect(page.locator('input[data-layer-field="src"]')).toHaveValue(image.file_path);
  await expect(page.locator('.hero-preview-layer img')).toHaveAttribute('src', image.file_path);
  await selectMedia(page, page.locator('[data-layer-media="mobileSrc"]'), image.id);
  await expect(page.locator('input[data-layer-field="mobileSrc"]')).toHaveValue(image.file_path);

  await page.locator('input[data-field="desktopSrc"]').fill('https://cdn.example.test/slide.jpg');
  await expect(page.locator('input[data-field="desktopSrc"]')).toHaveValue('https://cdn.example.test/slide.jpg');
});

test('Slider video fields filter to MP4 and poster filters to images', async ({page}) => {
  await openHarness(page);
  await page.locator('[data-select-slide="slide-2"]').click();
  await page.locator('[data-media-picker="desktopSrc"]').click();
  const dialog = page.getByRole('dialog',{name:'SELECT MEDIA'});
  await expect(dialog.locator('[data-media-id="12"]')).toBeVisible();
  await expect(dialog.locator('[data-media-id="11"]')).toHaveCount(0);
  await expect(dialog.locator('[data-media-id="13"]')).toHaveCount(0);
  await dialog.locator('[data-media-id="12"]').click();
  await expect(page.locator('input[data-field="desktopSrc"]')).toHaveValue(video.file_path);
  await expect(page.locator('.hero-preview-stage video source')).toHaveAttribute('src',video.file_path);
  await page.locator('[data-media-picker="poster"]').click();
  const posterDialog = page.getByRole('dialog',{name:'SELECT MEDIA'});
  await expect(posterDialog.locator('[data-media-id="11"]')).toBeVisible();
  await expect(posterDialog.locator('[data-media-id="12"]')).toHaveCount(0);
  await posterDialog.locator('[data-media-id="11"]').click();
  await expect(page.locator('input[data-field="poster"]')).toHaveValue(image.file_path);
  await expect(page.locator('.hero-preview-stage video')).toHaveAttribute('poster',image.file_path);
});

test('Blog and Releases continue to use image-only SELECT MEDIA picker', async ({page}) => {
  await openHarness(page,false);
  for (const id of ['f_cover_image','e_cover_image']) {
    const field = page.locator('#'+id);
    const picker = field.locator('xpath=following-sibling::button').first();
    await expect(picker).toHaveText('SELECT MEDIA');
    await picker.click();
    const dialog = page.getByRole('dialog',{name:'SELECT MEDIA'});
    await expect(dialog.locator('[data-media-id="11"]')).toBeVisible();
    await expect(dialog.locator('[data-media-id="12"]')).toHaveCount(0);
    await dialog.locator('[data-media-id="11"]').click();
    await expect(field).toHaveValue(image.file_path);
  }
});
