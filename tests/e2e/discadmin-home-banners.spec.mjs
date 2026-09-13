import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = readFileSync(join(process.cwd(), 'discadmin/home-banners.css'), 'utf8');
const script = readFileSync(join(process.cwd(), 'discadmin/home-banners.js'), 'utf8');

test('DISCADMIN banner editor saves ordered published Media Library slides', async ({ page }) => {
  await page.setContent(`<!doctype html><html><head><style>${css}</style></head><body><div id="home-banners-root"></div></body></html>`);
  await page.addScriptTag({ content: script });
  await page.evaluate(() => {
    window.__savedBannerRequest = null;
    return window.BRVTALHomeBanners.load(async (path, options) => {
      if (options?.method === 'POST') { window.__savedBannerRequest = JSON.parse(options.body); return {ok:true}; }
      if (path === '/settings') return {data:[]};
      if (path === '/media') return {data:[{type:'image',status:'published',title:'Sample rave',file_path:'/uploads/media/2026/09/sample.png'},{type:'image',status:'draft',title:'Private',file_path:'/uploads/media/2026/09/private.png'}]};
      throw Error('Unexpected request');
    });
  });
  await page.getByRole('button', { name: /AÑADIR BANNER/ }).click();
  await page.locator('[data-hb-field="image"]').selectOption('/uploads/media/2026/09/sample.png');
  await page.locator('[data-hb-field="title"]').fill('SAMPLE RAVE');
  await page.locator('[data-hb-field="url"]').fill('/events/sample-rave');
  await page.locator('[data-hb-field="enabled"]').check();
  await page.getByRole('button', { name: 'GUARDAR BANNERS' }).click();
  await expect(page.locator('#hb-notice')).toContainText('Banners guardados');
  const saved = await page.evaluate(() => window.__savedBannerRequest);
  expect(saved.setting_key).toBe('home.hero.slides');
  expect(JSON.parse(saved.setting_value)).toMatchObject([{title:'SAMPLE RAVE',image:'/uploads/media/2026/09/sample.png',url:'/events/sample-rave',enabled:true}]);
  await expect(page.locator('[data-hb-field="image"] option')).toHaveCount(2);
});
