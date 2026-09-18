import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const mediaJs = readFileSync(join(process.cwd(), 'js/public-media.js'), 'utf8');
const mediaCss = readFileSync(join(process.cwd(), 'css/public-media.css'), 'utf8');
const memoriesCss = readFileSync(join(process.cwd(), 'css/public-memories.css'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-media-e2e.html';

const items = [
  {id:1,media_id:101,type:'image',title:'Warehouse Memory',context:'Crowd pressure / Pereira',alt_text:'Crowd in Pereira',file_path:'/uploads/warehouse.jpg',sort_order:10,relations:[{related_type:'event',related_id:10,route_type:'events',slug:'genesis',label:'GENESIS'}]},
  {id:2,media_id:102,type:'audio',title:'Closing Signal',context:'Final minutes before lights on',file_path:'/uploads/closing.mp3',sort_order:20},
  {id:3,media_id:103,type:'video',title:'Red Strobe',context:'Thirty seconds from the floor',file_path:'/uploads/strobe.mp4',sort_order:30},
  {id:4,media_id:104,type:'image',title:'Afterhours',context:'Afterimage / 05:12',file_path:'/uploads/afterhours.jpg',sort_order:40},
];

async function mountHarness(page) {
  await page.route('**/api/public-image-delivery.php', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{}})}));
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html lang="en"><head><style>html,body{margin:0;background:#080808;color:#fff}${mediaCss}${memoriesCss}</style></head><body>
      <section class="media" aria-labelledby="memories-title">
        <div class="media-title"><span class="mono">VISUAL ARCHIVE / 06</span><h2 id="memories-title">MEMORIES</h2><div class="media-title-note"><strong>THE NIGHT REMAINS.</strong><p>A curated record of light, noise, bodies and afterimages.</p></div></div>
        <div class="public-media-tools"><label><span>SEARCH MEDIA</span><input type="search" data-public-media-search></label><div class="public-media-types"><button data-public-media-type="all">ALL</button><button data-public-media-type="image">IMAGES</button><button data-public-media-type="video">VIDEO</button><button data-public-media-type="audio">AUDIO</button></div></div>
        <div data-public-media-count></div><div data-public-media-empty hidden><p>NO MEMORIES MATCH THESE FILTERS.</p><button type="button" data-public-media-reset>CLEAR FILTERS</button></div><div class="media-grid"></div>
      </section><script>${mediaJs}</script></body></html>`,
  }));
  await page.goto(harnessUrl);
  await page.evaluate(entries => window.BRVTALPublicMedia.render(entries), items);
}

test('public Memories supports curated search/type discovery and an immersive multi-media viewer', async ({ page }) => {
  await page.setViewportSize({width:1280,height:900}); await mountHarness(page);
  await expect(page.locator('[data-public-media-item]')).toHaveCount(4);
  await expect(page.locator('[data-public-media-item] img').first()).toHaveAttribute('decoding','async');
  await expect(page.locator('[data-public-media-count]')).toHaveText('4 MEMORIES FOUND');
  await expect(page.getByText('Crowd pressure / Pereira')).toBeVisible();
  await expect(page.getByRole('link',{name:'EVENT / GENESIS'})).toHaveAttribute('href','/events/genesis');
  const cards=page.locator('[data-public-media-item]'),first=await cards.nth(0).boundingBox(),second=await cards.nth(1).boundingBox(); expect(first).not.toBeNull();expect(second).not.toBeNull();expect(first.width).toBeGreaterThan(second.width*1.25);expect(first.height).toBeGreaterThan(second.height*1.35);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
  await page.getByRole('button',{name:'AUDIO'}).click(); await expect(page.locator('[data-public-media-type-value="audio"]')).toBeVisible(); await expect(page.locator('[data-public-media-type-value="image"]').first()).toBeHidden(); await page.locator('[data-public-media-search]').fill('closing'); await expect(page.locator('[data-public-media-count]')).toHaveText('1 MEMORY FOUND');
  await page.getByRole('button',{name:'Open Closing Signal'}).click(); await expect(page.getByRole('dialog',{name:'Closing Signal'})).toBeVisible(); await expect(page.getByRole('dialog').locator('audio')).toHaveCount(1); await expect(page.getByRole('dialog')).toContainText('Final minutes before lights on'); await page.keyboard.press('Escape'); await expect(page.getByRole('button',{name:'Open Closing Signal'})).toBeFocused();
  await page.getByRole('button',{name:'ALL'}).click(); await page.locator('[data-public-media-search]').fill(''); await page.getByRole('button',{name:'Open Warehouse Memory'}).click(); await expect(page.getByRole('dialog',{name:'Warehouse Memory'})).toBeVisible(); await expect(page.getByRole('dialog').locator('img')).toHaveAttribute('alt','Crowd in Pereira'); await page.keyboard.press('ArrowRight'); await expect(page.getByRole('dialog',{name:'Closing Signal'})).toBeVisible(); await page.keyboard.press('ArrowRight'); await expect(page.getByRole('dialog',{name:'Red Strobe'})).toBeVisible();
  const viewerVideo=page.getByRole('dialog').locator('video'); await expect(viewerVideo).toHaveCount(1); await expect(viewerVideo).not.toHaveAttribute('autoplay',''); expect(await viewerVideo.evaluate(video=>video.autoplay)).toBe(false); await viewerVideo.focus(); await page.keyboard.press('ArrowLeft'); await expect(page.getByRole('dialog',{name:'Red Strobe'})).toBeVisible();
  await page.getByRole('button',{name:'Previous memory'}).click(); await expect(page.getByRole('dialog',{name:'Closing Signal'})).toBeVisible(); const viewerAudio=page.getByRole('dialog').locator('audio'); await viewerAudio.focus(); await page.keyboard.press('ArrowRight'); await expect(page.getByRole('dialog',{name:'Closing Signal'})).toBeVisible(); await page.getByRole('button',{name:'CLOSE ×'}).focus(); await page.keyboard.press('Shift+Tab'); await expect(page.getByRole('button',{name:'Next memory'})).toBeFocused(); await page.keyboard.press('Tab'); await expect(page.getByRole('button',{name:'CLOSE ×'})).toBeFocused(); await page.keyboard.press('Escape'); await expect(page.getByRole('button',{name:'Open Warehouse Memory'})).toBeFocused();
});

test('Memories renderer rejects raw Media Library records even when called directly', async ({ page }) => {
  await page.setViewportSize({width:900,height:800}); await mountHarness(page);
  await page.evaluate(() => window.BRVTALPublicMedia.render([{id:999,type:'image',title:'Raw library asset',file_path:'/uploads/raw-library.jpg'}]));
  await expect(page.locator('[data-public-media-item]')).toHaveCount(0);
  await expect(page.locator('[data-public-media-count]')).toHaveText('0 MEMORIES FOUND');
  await page.evaluate(() => window.BRVTALPublicMedia.render([{id:22,media_id:222,type:'image',title:'Curated Only',context:'Selected in DISCADMIN',file_path:'/uploads/curated.jpg'}]));
  await expect(page.getByText('Curated Only')).toBeVisible();
  await expect(page.locator('[data-public-media-item]')).toHaveCount(1);
});

test('Home Memories consumes only the curated collection from the canonical public-data event', async ({ page }) => {
  await page.setViewportSize({width:900,height:800}); await mountHarness(page);
  await page.evaluate(()=>{document.querySelector('.media-grid').replaceChildren();window.dispatchEvent(new CustomEvent('brvtal:public-data',{detail:{media:[{id:999,type:'image',title:'Uncurated Media',file_path:'/uploads/uncurated.jpg'}],memories:[{id:22,media_id:222,type:'image',title:'Curated Only',context:'Selected in DISCADMIN',file_path:'/uploads/curated.jpg'}]}}));});
  await expect(page.getByText('Curated Only')).toBeVisible(); await expect(page.getByText('Uncurated Media')).toHaveCount(0); await expect(page.locator('[data-public-media-item]')).toHaveCount(1);
});

test('Memories keeps a two-column mobile editorial rhythm with deliberate full-span records and no overflow', async ({ page }) => {
  await page.setViewportSize({width:390,height:844}); await mountHarness(page); const cards=page.locator('[data-public-media-item]'); await expect(cards).toHaveCount(4); const boxes=await Promise.all([0,1,2,3].map(index=>cards.nth(index).boundingBox())); for(const box of boxes)expect(box).not.toBeNull();
  expect(boxes[0].width).toBeGreaterThan(340);expect(boxes[1].width).toBeGreaterThan(160);expect(boxes[1].width).toBeLessThan(180);expect(Math.abs(boxes[1].y-boxes[2].y)).toBeLessThan(4);expect(boxes[2].x).toBeGreaterThan(boxes[1].x+boxes[1].width-2);expect(boxes[3].y).toBeGreaterThan(boxes[1].y+100);
  for(const button of await page.locator('[data-public-media-type]').all()){const box=await button.boundingBox();expect(box.height).toBeGreaterThanOrEqual(44);} const relationBox=await page.getByRole('link',{name:'EVENT / GENESIS'}).boundingBox();expect(relationBox.height).toBeGreaterThanOrEqual(44);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true); await expect(page.getByRole('heading',{name:'MEMORIES'})).toBeVisible(); await expect(page.getByText('THE NIGHT REMAINS.')).toBeVisible();
});
