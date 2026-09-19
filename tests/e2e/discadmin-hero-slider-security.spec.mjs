import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const heroJs = readFileSync(join(process.cwd(), 'discadmin/hero-slider.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin-hero-slider-security-e2e.html';
const payload = '<img src=x onerror="window.__heroXss += 1">';

function harness({ failLoad = false, media = [], settingsDelay = 0, mediaDelay = 0 } = {}) {
  const slider = {
    enabled: false,
    autoplay: true,
    interval: 7000,
    slides: [{
      id: 'slide-security',
      enabled: true,
      name: payload,
      mediaType: 'image',
      desktopSrc: '',
      mobileSrc: '',
      poster: '',
      kicker: '',
      title: '',
      body: '',
      ctaLabel: '',
      ctaUrl: '',
      contentAlign: 'left',
      overlay: 35,
      transition: 'fade',
      layers: [{
        id: 'layer-security',
        type: 'text',
        name: 'Security layer',
        text: payload,
        src: '',
        mobileSrc: '',
        url: '',
        x: 12,
        y: 50,
        width: 56,
        mobileX: null,
        mobileY: null,
        mobileWidth: null,
        hiddenMobile: false,
        animation: 'fade',
        delay: 0,
        duration: 650,
        align: 'left'
      }]
    }]
  };

  return `<!doctype html><html><body>
    <aside class="side"><div class="nav"><button type="button">DASHBOARD</button></div></aside>
    <main class="main"><div class="top"><div class="eyebrow">BRVTAL / DISCADMIN</div><h1>DASHBOARD</h1></div><div>NATIVE</div></main>
    <script>
      window.__heroXss = 0;
      window.__heroRequests = [];
      window.__heroRequestOptions = [];
      window.__heroMediaResolved = false;
      window.state = {section:'dashboard'};
      window.render = function(){
        document.querySelector('.main').innerHTML = '<div class="top"><div class="eyebrow">BRVTAL / DISCADMIN</div><h1>'+state.section.toUpperCase()+'</h1></div><div>NATIVE</div>';
      };
      window.go = async function(section){ state.section = section; render(); };
      window.BRVTALFeedback = {success(){},error(){}};
      window.req = async function(path, options = {}){
        window.__heroRequests.push(path);
        window.__heroRequestOptions.push({path,cache:options.cache || null});
        if (${failLoad ? 'true' : 'false'}) throw new Error(${JSON.stringify(payload)});
        if (path === '/settings?key=home.hero.slider') {
          await new Promise(resolve => setTimeout(resolve, ${settingsDelay}));
          return {data:[{setting_key:'home.hero.slider',setting_value:${JSON.stringify(JSON.stringify(slider))}}]};
        }
        if (path === '/media?view=hero-picker') {
          await new Promise(resolve => setTimeout(resolve, ${mediaDelay}));
          window.__heroMediaResolved = true;
          return {data:${JSON.stringify(media)}};
        }
        return {data:[]};
      };
    </script>
    <script>${heroJs}</script>
  </body></html>`;
}

async function open(page, options = {}) {
  await page.route('**/discadmin-hero-slider-security-e2e.html*', route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:harness(options)
  }));
  await page.goto(harnessUrl);
  await page.evaluate(() => window.go('hero-slider'));
}

test('Banners preview renders editorial HTML-like text without creating executable DOM', async ({ page }) => {
  await open(page);
  const preview = page.locator('.hero-preview-frame');
  await expect(preview).toContainText(payload);
  await expect(preview.locator('img[src="x"]')).toHaveCount(0);
  expect(await page.evaluate(() => window.__heroXss)).toBe(0);
});

test('Banners load errors are rendered as text instead of HTML', async ({ page }) => {
  await open(page, {failLoad:true});
  const error = page.locator('.hero-slider-error');
  await expect(error).toContainText(payload);
  await expect(error.locator('img')).toHaveCount(0);
  expect(await page.evaluate(() => window.__heroXss)).toBe(0);
});

test('Banners media pickers render remote Media Library values as inert option data', async ({ page }) => {
  const mediaPath = '"><img src=x onerror="window.__heroXss += 1">';
  const mediaTitle = '<svg onload="window.__heroXss += 1">MEDIA</svg>';
  await open(page, {media:[{id:77,type:'image',file_path:mediaPath,title:mediaTitle}]});

  const picker = page.locator('[data-media-picker="desktopSrc"]');
  const option = picker.locator('option').nth(1);
  await expect(option).toHaveText(mediaTitle);
  expect(await option.getAttribute('value')).toBe(mediaPath);
  await expect(page.locator('.hero-manager img[src="x"]')).toHaveCount(0);
  await expect(page.locator('.hero-manager svg')).toHaveCount(0);
  expect(await page.evaluate(() => window.__heroXss)).toBe(0);
});

test('Banners renders from the scoped setting before Media Library hydration finishes', async ({ page }) => {
  await open(page, {
    settingsDelay:20,
    mediaDelay:500,
    media:[{id:1,type:'image',file_path:'/uploads/hero.jpg',title:'Hero image',status:'published'}],
  });

  await expect(page.locator('.hero-manager')).toBeVisible();
  const picker = page.locator('[data-media-picker="desktopSrc"]');
  await expect(picker).toBeDisabled();
  await expect(picker.locator('option')).toHaveText('Loading Media Library…');
  await expect(page.locator('[data-save-slider]')).toBeDisabled();

  const stateAtFirstRender = await page.evaluate(() => ({
    requests:window.__heroRequests.slice(),
    mediaResolved:window.__heroMediaResolved,
  }));
  expect(stateAtFirstRender.requests).toEqual([
    '/media?view=hero-picker',
    '/settings?key=home.hero.slider',
  ]);
  expect(stateAtFirstRender.mediaResolved).toBe(false);
  expect(await page.evaluate(() => window.__heroRequestOptions)).toEqual([
    {path:'/media?view=hero-picker',cache:'no-store'},
    {path:'/settings?key=home.hero.slider',cache:'no-store'},
  ]);

  await expect(picker).toBeEnabled({timeout:1500});
  await expect(picker.locator('option').nth(1)).toHaveText('Hero image');
  await expect(page.locator('[data-save-slider]')).toBeEnabled();
  expect(await page.evaluate(() => window.__heroMediaResolved)).toBe(true);
});
