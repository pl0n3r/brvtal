import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const themeStudioJs = readFileSync(join(process.cwd(), 'discadmin/theme-studio-v2.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/theme-studio-slug-e2e.html';

async function loadHarness(page) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><head><meta charset="utf-8"></head><body>
      <input id="th_name" value="CORE">
      <input id="th_slug" value="core">
      <script>
        var state = { theme:{ name:'CORE', slug:'core' }, themeSettings:[], themeMedia:[] };
        var THEME_DEFAULT = {};
        function deepMergeTheme(base, extra) { return JSON.parse(JSON.stringify(extra || base || {})); }
      </script>
      <script>${themeStudioJs}</script>
    </body></html>`
  }));
  await page.goto(harnessUrl);
  await page.waitForFunction(() => Boolean(window.BRVTALThemeStudioV2));
}

test('Theme Studio slug cleanup trims edge dashes, keeps allowed underscores and caps length', async ({ page }) => {
  await loadHarness(page);

  const slugs = await page.evaluate(() => {
    const input = document.getElementById('th_slug');
    const readSlug = value => {
      input.value = value;
      return window.BRVTALThemeStudioV2.currentTheme().slug;
    };
    return [
      readSlug('---My Theme!!__---'),
      readSlug(`${'-'.repeat(120)}${'A'.repeat(80)}${'-'.repeat(120)}`),
      readSlug('---'),
    ];
  });

  expect(slugs).toEqual([
    'my-theme-__',
    'a'.repeat(60),
    'theme',
  ]);
});
