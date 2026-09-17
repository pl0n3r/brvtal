import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'discadmin/media-relations.js'), 'utf8');
const styles = readFileSync(join(process.cwd(), 'discadmin/media-relations.css'), 'utf8');

const markup = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${styles}</style></head><body>
<div id="media-grid"><button class="media-card active" type="button" data-media-id="9">MEMORY</button></div>
<div id="media-inspector"><div class="media-inspector-body">
  <input id="media-edit-title" value="ORIGINAL MEMORY">
  <input id="media-edit-alt" value="Original alt">
  <select id="media-edit-status"><option value="published" selected>PUBLISHED</option><option value="draft">DRAFT</option></select>
  <div class="media-usage"><h4>USED BY / 0</h4></div>
  <div class="media-inspector-actions"><button id="media-save" type="button">SAVE</button></div>
</div></div>
</body></html>`;

const context = {
  ok:true,
  data:{
    media_id:9,
    relations_ready:true,
    relations:[{related_type:'event',related_id:4,sort_order:0}],
    relation_catalog:{
      event:[{id:4,label:'GENESIS',status:'finished'}],
      artist:[{id:7,label:'PL0N3R',status:'published'}],
      set:[{id:12,label:'GENESIS LIVE',status:'published'}],
      release:[{id:21,label:'BRVTAL 001',status:'published'}],
    },
  },
};

test('Media inspector saves metadata and cultural relations atomically through the context endpoint', async ({ page }) => {
  await page.setContent(markup);
  await page.evaluate(() => {
    window.__legacySaveClicks = 0;
    document.getElementById('media-save').addEventListener('click', () => { window.__legacySaveClicks++; });
    window.BRVTALMediaLibrary = {
      notify(){},
      async refresh(id){ window.__refreshId = id; },
    };
  });

  await page.route('**/api/media-context.php?id=9', async route => {
    if (route.request().method() === 'GET') {
      return route.fulfill({contentType:'application/json',body:JSON.stringify(context)});
    }
    const body = route.request().postDataJSON();
    await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{media_id:9,relations_ready:true,relations:body.relations}})});
    await page.evaluate(payload => { window.__savedPayload = payload; }, body);
  });
  await page.route('**/api/auth', route => route.fulfill({contentType:'application/json',body:JSON.stringify({authenticated:true,csrf:'test-csrf'})}));

  await page.addScriptTag({content:script});
  await expect(page.locator('.media-cultural-context')).toBeVisible();
  await expect(page.locator('[data-media-cultural-group="event"] input')).toBeChecked();
  await expect(page.locator('[data-media-cultural-group="artist"]')).toContainText('PL0N3R');

  await page.locator('#media-edit-title').fill('UPDATED MEMORY');
  await page.locator('[data-media-cultural-group="artist"] input').check();
  await page.locator('#media-save').click();

  await expect.poll(() => page.evaluate(() => window.__refreshId || 0)).toBe(9);
  const saved = await page.evaluate(() => window.__savedPayload);
  expect(saved.title).toBe('UPDATED MEMORY');
  expect(saved.status).toBe('published');
  expect(saved.relations).toEqual([
    {related_type:'event',related_id:4,sort_order:0},
    {related_type:'artist',related_id:7,sort_order:1},
  ]);
  expect(await page.evaluate(() => window.__legacySaveClicks)).toBe(0);
});

test('Media inspector degrades honestly before the migration and leaves legacy metadata SAVE usable', async ({ page }) => {
  await page.setContent(markup);
  await page.evaluate(() => {
    window.__legacySaveClicks = 0;
    document.getElementById('media-save').addEventListener('click', () => { window.__legacySaveClicks++; });
    window.BRVTALMediaLibrary = {notify(){},refresh(){}};
  });
  await page.route('**/api/media-context.php?id=9', route => route.fulfill({
    contentType:'application/json',
    body:JSON.stringify({ok:true,data:{media_id:9,relations_ready:false,relations:[],relation_catalog:{}}}),
  }));

  await page.addScriptTag({content:script});
  await expect(page.locator('.media-cultural-context')).toContainText('RELATIONS MIGRATION REQUIRED');
  await page.locator('#media-save').click();
  expect(await page.evaluate(() => window.__legacySaveClicks)).toBe(1);
});

test('Media cultural context remains touch-safe and avoids horizontal overflow on mobile', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await page.setContent(markup);
  await page.evaluate(() => { window.BRVTALMediaLibrary = {notify(){},refresh(){}}; });
  await page.route('**/api/media-context.php?id=9', route => route.fulfill({contentType:'application/json',body:JSON.stringify(context)}));
  await page.addScriptTag({content:script});

  const option = page.locator('.media-cultural-option').first();
  await expect(option).toBeVisible();
  expect(await option.evaluate(node => node.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
  const dimensions = await page.evaluate(() => ({viewport:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth}));
  expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.viewport);
});
