import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const settingsJs = readFileSync(join(process.cwd(), 'discadmin/settings-v2.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/settings-theme-active-e2e.html';

test('theme.active routes to Theme Studio instead of raw text editing', async ({page}) => {
  await page.route(harness, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><body>
      <main id="root"></main>
      <script>
        var state={rows:[
          {setting_key:'theme.active',setting_value:'core',is_json:0},
          {setting_key:'theme.core',setting_value:'{"name":"CORE"}',is_json:1},
          {setting_key:'custom.flag',setting_value:'yes',is_json:0}
        ]};
        window.openSettingByKey=function(key){window.__raw=key;};
        window.go=function(section){window.__went=section;};
        window.render=function(){};
      </script>
      <script>${settingsJs}</script>
      <script>document.getElementById('root').innerHTML=window.settingsHome(state.rows);window.BRVTALSettingsV2.activate('advanced');</script>
    </body></html>`,
  }));

  await page.goto(harness);

  const activeRow = page.locator('.sv2-raw-row', {hasText:'theme.active'});
  await expect(activeRow.getByRole('button', {name:'OPEN THEME STUDIO'})).toBeVisible();
  await expect(activeRow.getByRole('button', {name:'RAW EDIT'})).toHaveCount(0);
  await activeRow.getByRole('button', {name:'OPEN THEME STUDIO'}).click();
  await expect.poll(() => page.evaluate(() => window.__went || '')).toBe('theme');
  expect(await page.evaluate(() => window.__raw || '')).toBe('');

  const customRow = page.locator('.sv2-raw-row', {hasText:'custom.flag'});
  const rawEdit = customRow.getByRole('button', {name:'RAW EDIT'});
  await expect(rawEdit).toBeVisible();
  await rawEdit.click();
  await expect.poll(() => page.evaluate(() => window.__raw || '')).toBe('custom.flag');
});
