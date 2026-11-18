import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';

const mediaJs = readFileSync(join(process.cwd(),'js/public-media.js'),'utf8');
const mediaCss = readFileSync(join(process.cwd(),'css/public-media.css'),'utf8');
const memoriesCss = readFileSync(join(process.cwd(),'css/public-memories.css'),'utf8');
const harness = 'http://127.0.0.1:4173/public-youtube-memory-harness.html';
const youtubeId = 'Ab3deF_90-x';

test('YouTube memory is a first-party cover until play; nocookie iframe stops and returns focus on Escape', async ({page}) => {
  const network = [];
  page.on('request', request => {
    if (/youtu(?:be|be-nocookie)\.com/i.test(request.url())) network.push(request.url());
  });
  await page.route('**/www.youtube-nocookie.com/**', route => route.fulfill({
    contentType:'text/html',body:'<!doctype html><html><body>Video player fixture</body></html>',
  }));
  await page.route('**/api/public-image-delivery.php', route => route.fulfill({
    contentType:'application/json',body:JSON.stringify({ok:true,data:{}}),
  }));
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><head><style>${mediaCss}${memoriesCss}</style></head><body>
      <div class="public-media-tools"><button data-public-media-type="all">ALL</button><button data-public-media-type="video">VIDEO</button></div>
      <div data-public-media-count></div><div data-public-media-empty hidden></div>
      <section class="media"><div class="media-grid" data-public-media-grid></div></section>
      <script>${mediaJs}</script></body></html>`,
  }));
  await page.goto(harness);
  await page.evaluate(id => window.BRVTALPublicMedia.render([
    {id:7,media_id:17,youtube_id:id,type:'image',status:'published',title:'Ritual nocturno',
      file_path:'/uploads/ritual-cover.jpg',alt_text:'BRVTAL event cover'},
    {id:8,media_id:18,type:'image',title:'Other memory',file_path:'/uploads/other.jpg'},
  ]), youtubeId);
  await expect(page.locator('[data-public-media-item]')).toHaveCount(2);
  await expect(page.getByRole('button',{name:'Play Ritual nocturno'}).locator('img'))
    .toHaveAttribute('src','/uploads/ritual-cover.jpg');
  await expect(page.locator('iframe')).toHaveCount(0);
  expect(network).toEqual([]);

  await page.getByRole('button',{name:'VIDEO'}).click();
  await expect(page.locator('[data-public-media-item]:not([hidden])')).toHaveCount(1);
  const play = page.getByRole('button',{name:'Play Ritual nocturno'});
  await play.click();
  const dialog = page.getByRole('dialog',{name:'Ritual nocturno'});
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('iframe')).toHaveAttribute('src',
    `https://www.youtube-nocookie.com/embed/${youtubeId}?autoplay=1&rel=0&playsinline=1`);
  await expect.poll(() => network.length).toBeGreaterThan(0);
  expect(network.every(url => url.startsWith('https://www.youtube-nocookie.com/embed/'))).toBe(true);
  await page.keyboard.press('Escape');
  await expect(page.locator('iframe')).toHaveCount(0);
  await expect(dialog).toHaveCount(0);
  await expect(play).toBeFocused();
});
