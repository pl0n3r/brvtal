import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const themeConfigJs = readFileSync(join(process.cwd(), 'discadmin/theme-studio-configuration.js'), 'utf8');
const themeRuntimeJs = readFileSync(join(process.cwd(), 'js/public-theme-runtime.js'), 'utf8');
const brandingSyncJs = readFileSync(join(process.cwd(), 'js/public-theme-branding-sync.js'), 'utf8');
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
  await page.evaluate(() => {
    const input = document.querySelector('[data-theme-wordmark] input');
    input.value = '/assets/brvtal-wordmark.svg'; input.dispatchEvent(new Event('input',{bubbles:true}));
  });
  await expect.poll(() => page.evaluate(() => window.state.theme.branding.wordmark)).toBe('/assets/brvtal-wordmark.svg');
});

test('public wordmark replaces header and loader text only after the asset loads', async ({ page }) => {
  await page.route('**/wordmark.svg*', route => route.fulfill({status:200,contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="200" height="40"><rect width="200" height="40" fill="black"/><text x="4" y="28" fill="white">BRVTAL</text></svg>'}));
  await page.setContent(`<!doctype html><html><body>
    <header><a class="brand"><span data-site-name>BRVTAL</span><small data-site-tagline>RAVE TILL GRAVE</small></a></header>
    <div id="loader"><div class="loader-inner"><span class="loader-kicker">BOOT</span><div class="loader-mark">BRVTAL</div></div></div>
    <script>window.BRVTALPublicDataPromise=Promise.resolve({data:{settings:{theme:{slug:'core',branding:{siteName:'BRVTAL',wordmark:'${base}/wordmark.svg'}}}}});</script>
    <script>${themeRuntimeJs}</script>
  </body></html>`);
  await expect(page.locator('.brand')).toHaveAttribute('data-theme-logo','1');
  await expect(page.locator('.loader-inner')).toHaveAttribute('data-theme-wordmark','1');
  await expect(page.locator('.theme-brand-image')).toBeVisible();
  await expect(page.locator('.theme-preloader-logo')).toBeVisible();
  await expect(page.locator('.brand > span[data-site-name]')).toBeHidden();
  await expect(page.locator('.loader-mark')).toBeHidden();
});

test('broken wordmark asset leaves textual BRVTAL fallbacks intact', async ({ page }) => {
  await page.route('**/broken.svg*', route => route.fulfill({status:404,body:'missing'}));
  await page.setContent(`<!doctype html><html><body>
    <a class="brand"><span data-site-name>BRVTAL</span><small data-site-tagline>RAVE TILL GRAVE</small></a>
    <div class="loader-inner"><div class="loader-mark">BRVTAL</div></div>
    <script>window.BRVTALPublicDataPromise=Promise.resolve({data:{settings:{theme:{slug:'core',branding:{siteName:'BRVTAL',wordmark:'${base}/broken.svg'}}}}});</script>
    <script>${themeRuntimeJs}</script>
  </body></html>`);
  await page.evaluate(() => window.BRVTALThemeReady);
  await expect.poll(() => page.locator('.theme-brand-image').count()).toBe(0);
  await expect(page.locator('.brand')).not.toHaveAttribute('data-theme-logo','1');
  await expect(page.locator('.brand > span[data-site-name]')).toBeVisible();
  await expect(page.locator('.loader-mark')).toBeVisible();
  await expect(page.locator('.loader-inner')).not.toHaveAttribute('data-theme-wordmark','1');
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
      window.BRVTALPublicDataPromise=Promise.resolve({data:{settings:{
        theme:{slug:'core',colors:{primary:'#B6FF00'},branding:{siteName:'BRVTAL'},seo:{siteTitle:'LEGACY THEME TITLE',description:'LEGACY',ogImage:'${base}/legacy.webp'}},
        social:{spotify:'https://open.spotify.com/artist/test'}
      }}});
    </script>
    <script>${themeRuntimeJs}</script>
    <script>${brandingSyncJs}</script>
    <script>window.BRVTALThemeReady.then(()=>document.documentElement.style.setProperty('--red','#ff0000'));</script>
  </body></html>`);

  await expect.poll(() => page.evaluate(() => document.documentElement.style.getPropertyValue('--red'))).toBe('#B6FF00');
  expect(await page.title()).toBe('SERVER TITLE');
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content','SERVER DESCRIPTION');
  await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content',`${base}/server.webp`);
  await expect(page.locator('[data-social="spotify"]')).toBeVisible();
  await expect(page.locator('[data-social="spotify"]')).toHaveAttribute('href','https://open.spotify.com/artist/test');
});
