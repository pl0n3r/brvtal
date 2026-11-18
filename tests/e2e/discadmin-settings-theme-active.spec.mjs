import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const settingsJs = readFileSync(join(process.cwd(), 'discadmin/settings-v2.js'), 'utf8');
const harness = 'http://127.0.0.1:4173/discadmin/settings-theme-active-e2e.html';

test('Advanced routes to Theme Studio without exposing raw setting records', async ({page}) => {
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

  await expect(page.locator('.sv2-raw-row')).toHaveCount(0);
  await expect(page.getByText('RAW SETTINGS',{exact:true})).toHaveCount(0);
  await expect(page.getByRole('button',{name:'RAW EDIT'})).toHaveCount(0);
  const theme = page.getByRole('button',{name:'OPEN THEME STUDIO'});
  await expect(theme).toBeVisible();
  await theme.click();
  await expect.poll(() => page.evaluate(() => window.__went || '')).toBe('theme');
  expect(await page.evaluate(() => window.__raw || '')).toBe('');
});
