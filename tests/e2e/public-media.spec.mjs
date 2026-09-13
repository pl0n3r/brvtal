import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const mediaJs = readFileSync(join(process.cwd(), 'js/public-media.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-media-e2e.html';

test('public media supports type/search discovery and an accessible image viewer', async ({ page }) => {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><body>
      <section class="media"><div class="public-media-tools"><label><span>SEARCH MEDIA</span><input type="search" data-public-media-search></label><div class="public-media-types"><button data-public-media-type="all">ALL</button><button data-public-media-type="image">IMAGES</button><button data-public-media-type="video">VIDEO</button><button data-public-media-type="audio">AUDIO</button></div></div><div data-public-media-count></div><div data-public-media-empty hidden><p>NO MEMORIES MATCH THESE FILTERS.</p><button type="button" data-public-media-reset>CLEAR FILTERS</button></div><div class="media-grid"></div></section>
      <script>${mediaJs}</script></body></html>`,
  }));
  await page.goto(harnessUrl);
  await page.evaluate(() => window.BRVTALPublicMedia.render([
    {id:1,type:'image',title:'Warehouse Memory',alt_text:'Crowd in Pereira',file_path:'/warehouse.jpg'},
    {id:2,type:'audio',title:'Señal de cierre',file_path:'/closing.mp3'},
    {id:3,type:'video',title:'Red Strobe',file_path:'/strobe.mp4'},
    {id:4,type:'image',title:'Afterhours',file_path:'/afterhours.jpg'},
  ]));

  await expect(page.locator('[data-public-media-item]')).toHaveCount(4);
  await expect(page.locator('[data-public-media-item] img').first()).toHaveAttribute('decoding', 'async');
  await expect(page.locator('[data-public-media-count]')).toHaveText('4 MEMORIES FOUND');
  await page.getByRole('button', {name:'AUDIO'}).click();
  await expect(page.locator('[data-public-media-type-value="audio"]')).toBeVisible();
  await expect(page.locator('[data-public-media-type-value="image"]').first()).toBeHidden();
  await page.locator('[data-public-media-search]').fill('senal');
  await expect(page.locator('[data-public-media-count]')).toHaveText('1 MEMORY FOUND');
  await page.locator('[data-public-media-search]').fill('nothing matches');
  await expect(page.locator('[data-public-media-count]')).toHaveText('0 MEMORIES FOUND');
  await expect(page.locator('[data-public-media-empty]')).toBeVisible();
  await page.getByRole('button', {name:'CLEAR FILTERS'}).click();
  await expect(page.locator('[data-public-media-empty]')).toBeHidden();
  await expect(page.locator('[data-public-media-count]')).toHaveText('4 MEMORIES FOUND');
  await expect(page.locator('[data-public-media-search]')).toHaveValue('');
  await expect(page.locator('[data-public-media-search]')).toBeFocused();

  await page.getByRole('button', {name:'ALL'}).click();
  await page.locator('[data-public-media-search]').fill('warehouse');
  await expect(page.locator('[data-public-media-count]')).toHaveText('1 MEMORY FOUND');
  await expect(page.locator('[data-public-media-type-value="image"]').first()).toBeVisible();
  await page.getByRole('button', {name:'Open Warehouse Memory'}).click();
  await expect(page.getByRole('dialog', {name:'Warehouse Memory'})).toBeVisible();
  await expect(page.getByRole('dialog').locator('img')).toHaveAttribute('alt', 'Crowd in Pereira');
  await expect(page.getByRole('button', {name:'Next image'})).toBeHidden();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(page.getByRole('button', {name:'Open Warehouse Memory'})).toBeFocused();

  await page.locator('[data-public-media-search]').fill('');
  await page.getByRole('button', {name:'Open Warehouse Memory'}).click();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('dialog', {name:'Afterhours'})).toBeVisible();
  await page.getByRole('button', {name:'Previous image'}).click();
  await expect(page.getByRole('dialog', {name:'Warehouse Memory'})).toBeVisible();
  await page.getByRole('button', {name:'CLOSE ×'}).focus();
  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('button', {name:'Next image'})).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', {name:'CLOSE ×'})).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('button', {name:'Open Warehouse Memory'})).toBeFocused();
});
