import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const runtimeJs = readFileSync(join(process.cwd(), 'js/public-theme-runtime.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-theme-runtime-e2e.html';
const pixel = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
  'base64',
);

function harness(theme) {
  return `<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="theme-color" content="#050505"></head>
<body>
  <a class="brand" href="/"><span data-site-name>STATIC BRAND</span></a>
  <div class="hero-title" data-text="STATIC BRAND">STATIC BRAND</div>
  <div data-site-tagline>STATIC TAGLINE</div>
  <picture><source><img class="hero-logo" alt="Static logo"></picture>
  <div class="loader-inner"><div class="loader-mark" data-text="STATIC BRAND">STATIC BRAND</div></div>
  <div id="menuPanel" aria-hidden="true"></div>
  <script>
    (() => {
      const listeners = [];
      const query = {
        matches:false,
        media:'(max-width: 900px)',
        addEventListener(type, listener){ if (type === 'change') listeners.push(listener); },
        removeEventListener(){}
      };
      window.matchMedia = () => query;
      window.__setThemeMobile = matches => {
        query.matches = Boolean(matches);
        listeners.forEach(listener => listener({matches:query.matches,media:query.media}));
      };
      window.BRVTALPublicDataPromise = Promise.resolve({
        payload:{data:{settings:{theme:${JSON.stringify(theme)}}}}
      });
    })();
  </script>
  <script>${runtimeJs}</script>
</body>
</html>`;
}

test('public theme runtime applies branding and switches to the mobile logo without a second API request', async ({ page }) => {
  let publicApiRequests = 0;
  await page.route('**/api/public.php', route => {
    publicApiRequests += 1;
    return route.fulfill({status:500,body:'unexpected'});
  });
  await page.route('**/*.png', route => route.fulfill({contentType:'image/png',body:pixel}));
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:harness({
      slug:'night-shift',
      branding:{
        siteName:'NIGHT SHIFT',
        tagline:'RAVE TILL GRAVE',
        logo:'/brand-main.png',
        mobileLogo:'/brand-mobile.png',
        favicon:'/favicon.png',
        preloaderLogo:'/preloader.png',
      },
      colors:{bg:'#101010',text:'#f4f4f0',primary:'#a00',accent:'#b8ff00'},
      typography:{display:'Arial',body:'Arial',mono:'monospace',h1:'120px',bodySize:'16px',tracking:'-0.04em'},
      navigation:{fixed:true,transparentHero:true,blur:true,menuStyle:'fullscreen',logoPosition:'left',sceneIndicator:true,soundToggle:true},
      effects:{grain:true,scanlines:true,glitch:true,cursor:true,magnetic:true,motion:'brvtal'},
      sound:{enabled:true},
    }),
  }));

  await page.goto(harnessUrl);
  await page.evaluate(() => window.BRVTALThemeReady);

  await expect(page.locator('html')).toHaveAttribute('data-brvtal-theme','night-shift');
  await expect(page.locator('[data-site-name]')).toHaveText('NIGHT SHIFT');
  await expect(page.locator('[data-site-tagline]')).toHaveText('RAVE TILL GRAVE');
  await expect(page.locator('.hero-title')).toHaveText('NIGHT SHIFT');
  await expect(page.locator('.brand')).toHaveAttribute('data-theme-logo','1');
  await expect(page.locator('.theme-brand-image')).toHaveAttribute('src',/\/brand-main\.png$/);
  await expect(page.locator('.hero-logo')).toHaveAttribute('src',/\/brand-main\.png$/);
  await expect(page.locator('picture source')).toHaveAttribute('srcset',/\/brand-main\.png$/);
  await expect(page.locator('.theme-preloader-logo')).toHaveAttribute('src',/\/preloader\.png$/);
  await expect(page.locator('link[rel~="icon"][data-theme-favicon]')).toHaveAttribute('href',/\/favicon\.png$/);
  expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--bg').trim())).toBe('#101010');
  expect(publicApiRequests).toBe(0);

  await page.evaluate(() => window.__setThemeMobile(true));
  await expect(page.locator('.theme-brand-image')).toHaveAttribute('src',/\/brand-mobile\.png$/);
  await expect(page.locator('.hero-logo')).toHaveAttribute('src',/\/brand-mobile\.png$/);
  await expect(page.locator('picture source')).toHaveAttribute('srcset',/\/brand-mobile\.png$/);
});

test('public theme runtime keeps wordmark priority while the hero uses the visual logo', async ({ page }) => {
  await page.route('**/*.png', route => route.fulfill({contentType:'image/png',body:pixel}));
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:harness({
      slug:'wordmark-priority',
      branding:{
        siteName:'BRVTAL',
        wordmark:'/brand-wordmark.png',
        logo:'/brand-visual.png',
        preloaderLogo:'/brand-preloader.png',
      },
      colors:{},
      typography:{},
      navigation:{},
      effects:{},
      sound:{},
    }),
  }));

  await page.goto(harnessUrl);
  await page.evaluate(() => window.BRVTALThemeReady);

  await expect(page.locator('.theme-brand-image')).toHaveAttribute('src',/\/brand-wordmark\.png$/);
  await expect(page.locator('.theme-preloader-logo')).toHaveAttribute('src',/\/brand-wordmark\.png$/);
  await expect(page.locator('.loader-inner')).toHaveAttribute('data-theme-wordmark','1');
  await expect(page.locator('.hero-logo')).toHaveAttribute('src',/\/brand-visual\.png$/);
});

test('public theme runtime falls back to the valid main logo when preferred assets are unsafe', async ({ page }) => {
  await page.route('**/*.png', route => route.fulfill({contentType:'image/png',body:pixel}));
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:harness({
      slug:'safe-branding-fallback',
      branding:{
        siteName:'SAFE FALLBACK',
        logo:'/brand-main.png',
        mobileLogo:'javascript:alert(1)',
        preloaderLogo:'data:image/png;base64,AAAA',
      },
      colors:{},
      typography:{},
      navigation:{},
      effects:{},
      sound:{},
    }),
  }));

  await page.goto(harnessUrl);
  await page.evaluate(() => window.BRVTALThemeReady);

  await expect(page.locator('.theme-preloader-logo')).toHaveAttribute('src',/\/brand-main\.png$/);
  await expect(page.locator('.theme-brand-image')).toHaveAttribute('src',/\/brand-main\.png$/);
  await expect(page.locator('.hero-logo')).toHaveAttribute('src',/\/brand-main\.png$/);

  await page.evaluate(() => window.__setThemeMobile(true));
  await expect(page.locator('.theme-brand-image')).toHaveAttribute('src',/\/brand-main\.png$/);
  await expect(page.locator('.hero-logo')).toHaveAttribute('src',/\/brand-main\.png$/);
  await expect(page.locator('a[href^="javascript:"]')).toHaveCount(0);
});

test('public theme runtime falls back from failed branding images and recovers the hero on a later valid update', async ({ page }) => {
  await page.route('**/broken.png', route => route.abort('failed'));
  await page.route('**/brand-recovered.png', route => route.fulfill({contentType:'image/png',body:pixel}));
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:harness({
      slug:'broken-branding',
      branding:{siteName:'STATIC FALLBACK',logo:'/broken.png'},
      colors:{},
      typography:{},
      navigation:{},
      effects:{},
      sound:{},
    }),
  }));

  await page.goto(harnessUrl);
  await page.evaluate(() => window.BRVTALThemeReady);

  await expect.poll(() => page.locator('.theme-brand-image').count()).toBe(0);
  await expect(page.locator('.brand')).not.toHaveAttribute('data-theme-logo','1');
  await expect(page.locator('[data-site-name]')).toHaveText('STATIC FALLBACK');
  await expect(page.locator('[data-site-name]')).toBeVisible();
  await expect(page.locator('.hero-logo')).toBeHidden();
  await expect(page.locator('.hero-logo')).not.toHaveAttribute('src', /.+/);
  await expect(page.locator('picture source')).not.toHaveAttribute('srcset', /.+/);

  await page.evaluate(() => window.BRVTALThemeRuntime.apply({
    slug:'recovered-branding',
    branding:{siteName:'RECOVERED BRANDING',logo:'/brand-recovered.png'},
    colors:{},
    typography:{},
    navigation:{},
    effects:{},
    sound:{},
  }));

  await expect(page.locator('.hero-logo')).toBeVisible();
  await expect(page.locator('.hero-logo')).toHaveAttribute('src',/\/brand-recovered\.png$/);
  await expect(page.locator('picture source')).toHaveAttribute('srcset',/\/brand-recovered\.png$/);
  await expect(page.locator('[data-site-name]')).toHaveText('RECOVERED BRANDING');

  await page.evaluate(() => window.BRVTALThemeRuntime.apply({
    slug:'text-only-branding',
    branding:{siteName:'TEXT ONLY'},
    colors:{},
    typography:{},
    navigation:{},
    effects:{},
    sound:{},
  }));

  await expect(page.locator('.hero-logo')).toBeHidden();
  await expect(page.locator('.hero-logo')).not.toHaveAttribute('src', /.+/);
  await expect(page.locator('picture source')).not.toHaveAttribute('srcset', /.+/);
  await expect(page.locator('[data-site-name]')).toHaveText('TEXT ONLY');
});
