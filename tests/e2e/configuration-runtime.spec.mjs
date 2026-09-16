import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const themeConfigJs = readFileSync(join(process.cwd(), 'discadmin/theme-studio-configuration.js'), 'utf8');
const wordmarkJs = readFileSync(join(process.cwd(), 'js/public-theme-wordmark.js'), 'utf8');
const harmonyJs = readFileSync(join(process.cwd(), 'js/public-settings-harmony.js'), 'utf8');
const base = 'http://127.0.0.1:4173';

test('Theme Studio exposes wordmark while moving duplicate SEO out of the primary theme editor', async ({ page }) => {
  await page.setContent(`<!doctype html><html><body>
    <div data-theme-studio-v2>
      <aside class="tsv2-tabs"><button data-theme-tab="brand">BRAND</button><button data-theme-tab="seo">SEO</button><button data-theme-tab="manage">MANAGE</button></aside>
      <section data-theme-pane="brand"><div class="tsv2-assets"></div></section>
      <section data-theme-pane="seo">LEGACY SEO</section>
      <section data-theme-pane="manage"><div class="tsv2-manage-grid"></div></section>
      <aside class="tsv2-preview"><div data-preview-brand>BRVTAL</div></aside>
    </div>
    <script>window.state={theme:{branding:{siteName:'BRVTAL',wordmark:''}},themeMedia:[{type:'image',title:'Wordmark',file_path:'/wordmark.svg'}]};</script>
    <script>${themeConfigJs}</script>
  </body></html>`);

  await expect(page.locator('[data-theme-wordmark]')).toBeVisible();
  await expect(page.locator('[data-theme-tab="seo"]')).toBeHidden();
  await expect(page.locator('[data-theme-pane="seo"]')).toBeHidden();
  await expect(page.getByText('PRESERVED / UNWIRED')).toBeVisible();
  await page.locator('#th_wordmark_custom').fill('/assets/brvtal-wordmark.svg');
  await expect.poll(() => page.evaluate(() => window.state.theme.branding.wordmark)).toBe('/assets/brvtal-wordmark.svg');
});

test('public wordmark replaces header and loader text only after the asset loads', async ({ page }) => {
  await page.route('**/wordmark.svg*', route => route.fulfill({status:200,contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="200" height="40"><rect width="200" height="40" fill="black"/><text x="4" y="28" fill="white">BRVTAL</text></svg>'}));
  await page.setContent(`<!doctype html><html><body>
    <header><a class="brand"><span data-site-name>BRVTAL</span><small>RAVE TILL GRAVE</small></a></header>
    <div id="loader"><div class="loader-inner"><span class="loader-kicker">BOOT</span><div class="loader-mark">BRVTAL</div></div></div>
    <script>window.BRVTALThemeReady=Promise.resolve(true);window.BRVTALThemeRuntime={resolveSettings:async()=>({theme:{branding:{siteName:'BRVTAL',wordmark:'${base}/wordmark.svg'}}})};</script>
    <script>${wordmarkJs}</script>
  </body></html>`);
  await expect(page.locator('.brand')).toHaveAttribute('data-theme-wordmark-ready','1');
  await expect(page.locator('.loader-inner')).toHaveAttribute('data-theme-wordmark-ready','1');
  await expect(page.locator('.theme-wordmark-image')).toBeVisible();
  await expect(page.locator('.theme-loader-wordmark')).toBeVisible();
  await expect(page.locator('.brand > span[data-site-name]')).toBeHidden();
  await expect(page.locator('.loader-mark')).toBeHidden();
});

test('broken wordmark asset leaves textual BRVTAL fallbacks intact', async ({ page }) => {
  await page.route('**/broken.svg*', route => route.fulfill({status:404,body:'missing'}));
  await page.setContent(`<!doctype html><html><body>
    <a class="brand"><span data-site-name>BRVTAL</span><small>RAVE TILL GRAVE</small></a>
    <div class="loader-inner"><div class="loader-mark">BRVTAL</div></div>
    <script>window.BRVTALThemeReady=Promise.resolve(true);window.BRVTALThemeRuntime={resolveSettings:async()=>({theme:{branding:{wordmark:'${base}/broken.svg'}}})};</script>
    <script>${wordmarkJs}</script>
  </body></html>`);
  await expect.poll(() => page.locator('.theme-wordmark-image').count()).toBe(0);
  await expect(page.locator('.brand > span[data-site-name]')).toBeVisible();
  await expect(page.locator('.loader-mark')).toBeVisible();
});

test('server SEO and active theme palette remain authoritative after legacy runtime passes', async ({ page }) => {
  await page.setContent(`<!doctype html><html><head>
    <title>SERVER TITLE</title>
    <meta name="description" content="SERVER DESCRIPTION">
    <meta property="og:title" content="SERVER TITLE">
    <meta property="og:description" content="SERVER DESCRIPTION">
    <meta property="og:image" content="${base}/server.webp">
    <meta name="twitter:image" content="${base}/server.webp">
    </head><body><a data-social="spotify" hidden>SPOTIFY</a>
    <script>
      window.__applied=0;
      window.BRVTALThemeRuntime={
        resolveSettings:async()=>({theme:{colors:{primary:'#B6FF00'}},social:{spotify:'https://open.spotify.com/artist/test'}}),
        apply:()=>{window.__applied++;document.title='LEGACY THEME TITLE';document.querySelector('meta[name=description]').content='LEGACY';document.querySelector('meta[property=og:image]').content='${base}/legacy.webp';}
      };
    </script>
    <script>${harmonyJs}</script>
  </body></html>`);
  await page.waitForTimeout(360);
  expect(await page.title()).toBe('SERVER TITLE');
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content','SERVER DESCRIPTION');
  await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content',`${base}/server.webp`);
  await expect(page.locator('[data-social="spotify"]')).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.__applied)).toBeGreaterThan(0);
});
