import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const gridCss = readFileSync(join(process.cwd(), 'discadmin/admin-data-grid.css'), 'utf8');
const mediaCss = readFileSync(join(process.cwd(), 'discadmin/media-library.css'), 'utf8');
const gridJs = readFileSync(join(process.cwd(), 'discadmin/admin-data-grid.js'), 'utf8');

// Mirrors the actual Media PHP structure and uses the shared grid renderer.
async function mountMedia(page) {
  await page.setContent(
    '<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' +
    gridCss + '\n' + mediaCss +
    '</style></head><body style="margin:0">' +
    '<main style="max-width:1200px;margin:auto;padding:16px;box-sizing:border-box">' +
    '<section class="brvtal-media-library"><div class="media-layout">' +
    '<div><div id="media-grid" class="media-grid"></div></div>' +
    '<aside id="media-inspector" class="media-inspector">SELECT AN ASSET</aside>' +
    '</div></section></main></body></html>'
  );
  await page.evaluate(() => {
    window.csrf = 'fixture';
    window.fetch = async () => new Response(
      JSON.stringify({ ok:true, data:{ columns:['primary','type','mime','size','status'] } }),
      {status:200, headers:{'Content-Type':'application/json'}}
    );
  });
  await page.evaluate(source => window.eval(source), gridJs);
  await page.evaluate(() => {
    const records = [{
      id: 1,
      title: 'A very long underground media filename for the media library',
      file_path: '/uploads/press-kit.pdf',
      type: 'document',
      mime_type: 'application/pdf',
      file_size: 2048,
      status: 'published'
    }];
    window.BRVTALDataGrid.render('media', document.querySelector('#media-grid'), records, {
      allRows: records,
      orderingEnabled: false
    });
  });
  await expect(page.locator('#media-grid > .admin-data-grid-shell')).toBeVisible();
}

test('Media table spans its full workspace and inspector stays on the right at 1440px', async ({page}) => {
  await page.setViewportSize({width:1440,height:900});
  await mountMedia(page);
  const bounds = await page.evaluate(() => {
    const grid = document.querySelector('#media-grid').getBoundingClientRect();
    const shell = document.querySelector('#media-grid > .admin-data-grid-shell').getBoundingClientRect();
    const inspector = document.querySelector('#media-inspector').getBoundingClientRect();
    return {gridWidth:grid.width,shellWidth:shell.width,gridRight:grid.right,inspectorLeft:inspector.left};
  });
  expect(bounds.gridWidth).toBeGreaterThan(700);
  expect(Math.abs(bounds.shellWidth - bounds.gridWidth)).toBeLessThan(2);
  expect(bounds.inspectorLeft).toBeGreaterThan(bounds.gridRight);
});

test('Media table scrolls internally and inspector stacks below at 390px', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await mountMedia(page);
  const sizes = await page.evaluate(() => {
    const grid = document.querySelector('#media-grid').getBoundingClientRect();
    const shell = document.querySelector('#media-grid > .admin-data-grid-shell').getBoundingClientRect();
    const inspector = document.querySelector('#media-inspector').getBoundingClientRect();
    const scroller = document.querySelector('#media-grid .admin-data-grid-scroll');
    return {
      gridWidth:grid.width,shellWidth:shell.width,gridBottom:grid.bottom,
      inspectorTop:inspector.top,viewportWidth:window.innerWidth,
      pageWidth:document.documentElement.scrollWidth,
      scrollClient:scroller.clientWidth,scrollContent:scroller.scrollWidth
    };
  });
  expect(Math.abs(sizes.shellWidth - sizes.gridWidth)).toBeLessThan(2);
  expect(sizes.scrollContent).toBeGreaterThan(sizes.scrollClient);
  expect(sizes.pageWidth).toBeLessThanOrEqual(sizes.viewportWidth + 1);
  expect(sizes.inspectorTop).toBeGreaterThanOrEqual(sizes.gridBottom);
});

test('The legacy Media card fallback still has two mobile columns', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await mountMedia(page);
  await page.locator('#media-grid').evaluate(grid => {
    grid.innerHTML = Array.from({length:3},(_,index) =>
      '<button class="media-card" type="button">Fallback ' + (index + 1) + '</button>'
    ).join('');
  });
  const top = await page.locator('#media-grid > .media-card').evaluateAll(cards =>
    cards.map(card => card.getBoundingClientRect().top)
  );
  expect(top).toHaveLength(3);
  expect(top[0]).toBe(top[1]);
  expect(top[2]).toBeGreaterThan(top[1]);
});
