import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const heroJs = readFileSync(join(process.cwd(), 'discadmin/hero-slider.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin-hero-slider-security-e2e.html';
const payload = '<img src=x onerror="window.__heroXss += 1">';

function harness({ failLoad = false } = {}) {
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
      window.state = {section:'dashboard'};
      window.render = function(){
        document.querySelector('.main').innerHTML = '<div class="top"><div class="eyebrow">BRVTAL / DISCADMIN</div><h1>'+state.section.toUpperCase()+'</h1></div><div>NATIVE</div>';
      };
      window.go = async function(section){ state.section = section; render(); };
      window.BRVTALFeedback = {success(){},error(){}};
      window.req = async function(path){
        if (${failLoad ? 'true' : 'false'}) throw new Error(${JSON.stringify(payload)});
        if (path === '/settings') return {data:[{setting_key:'home.hero.slider',setting_value:${JSON.stringify(JSON.stringify(slider))}}]};
        if (path === '/media') return {data:[]};
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
