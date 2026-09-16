import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const mediaJs = readFileSync(join(process.cwd(), 'js/public-media.js'), 'utf8');
const mediaCss = readFileSync(join(process.cwd(), 'css/public-media.css'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-media-e2e.html';

const items = [
  {id:1,type:'image',title:'Warehouse Memory',alt_text:'Crowd in Pereira',file_path:'/warehouse.jpg'},
  {id:2,type:'audio',title:'Señal de cierre',file_path:'/closing.mp3'},
  {id:3,type:'video',title:'Red Strobe',file_path:'/strobe.mp4'},
  {id:4,type:'image',title:'Afterhours',file_path:'/afterhours.jpg'},
];

async function mountHarness(page) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><head><style>html,body{margin:0;background:#080808;color:#fff}${mediaCss}</style></head><body>
      <section class="media" aria-labelledby="memories-title">
        <div class="media-title"><span class="mono">VISUAL ARCHIVE / 06</span><h2 id="memories-title">MEMORIES</h2><div class="media-title-note"><strong>THE NIGHT REMAINS.</strong><p>A curated record of light, noise, bodies and afterimages.</p></div></div>
        <div class="public-media-tools"><label><span>SEARCH MEDIA</span><input type="search" data-public-media-search></label><div class="public-media-types"><button data-public-media-type="all">ALL</button><button data-public-media-type="image">IMAGES</button><button data-public-media-type="video">VIDEO</button><button data-public-media-type="audio">AUDIO</button></div></div>
        <div data-public-media-count></div><div data-public-media-empty hidden><p>NO MEMORIES MATCH THESE FILTERS.</p><button type="button" data-public-media-reset>CLEAR FILTERS</button></div><div class="media-grid"></div>
      </section>
      <script>${mediaJs}</script></body></html>`,
  }));
  await page.goto(harnessUrl);
  await page.evaluate(entries => window.BRVTALPublicMedia.render(entries), items);
}

test('public media supports type/search discovery and an accessible image viewer', async ({ page }) => {
  await page.setViewportSize({width:1280,height:900});
  await mountHarness(page);

  await expect(page.locator('[data-public-media-item]')).toHaveCount(4);
  await expect(page.locator('[data-public-media-item] img').first()).toHaveAttribute('decoding', 'async');
  await expect(page.locator('[data-public-media-count]')).toHaveText('4 MEMORIES FOUND');

  const cards = page.locator('[data-public-media-item]');
  const first = await cards.nth(0).boundingBox();
  const second = await cards.nth(1).boundingBox();
  const fourth = await cards.nth(3).boundingBox();
  expect(first).not.toBeNull();
  expect(second).not.toBeNull();
  expect(fourth).not.toBeNull();
  expect(first.width).toBeGreaterThan(second.width * 1.25);
  expect(first.height).toBeGreaterThan(second.height * 1.35);
  expect(fourth.y).toBeGreaterThan(first.y + first.height - 4);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);

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

test('Memories collapses to an ordered touch-friendly mobile archive without overflow', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await mountHarness(page);

  const cards = page.locator('[data-public-media-item]');
  await expect(cards).toHaveCount(4);
  const boxes = await Promise.all([0,1,2,3].map(index => cards.nth(index).boundingBox()));
  for (const box of boxes) {
    expect(box).not.toBeNull();
    expect(box.width).toBeGreaterThan(340);
    expect(box.width).toBeLessThanOrEqual(354);
  }
  expect(boxes[1].y).toBeGreaterThan(boxes[0].y + boxes[0].height);
  expect(boxes[2].y).toBeGreaterThan(boxes[1].y + boxes[1].height);
  expect(boxes[3].y).toBeGreaterThan(boxes[2].y + boxes[2].height);

  for (const button of await page.locator('[data-public-media-type]').all()) {
    const box = await button.boundingBox();
    expect(box.height).toBeGreaterThanOrEqual(44);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await expect(page.getByRole('heading', {name:'MEMORIES'})).toBeVisible();
  await expect(page.getByText('THE NIGHT REMAINS.')).toBeVisible();
});
