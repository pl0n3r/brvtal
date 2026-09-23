import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const studioJs = readFileSync(join(process.cwd(), 'discadmin/theme-studio-v2.js'), 'utf8');
const studioCss = readFileSync(join(process.cwd(), 'discadmin/theme-studio-v2.css'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/theme-studio-concept05-e2e.html';
const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC','base64');

const baseTheme = {
  name:'CUSTOM CORE',slug:'core',
  branding:{siteName:'CUSTOM BRVTAL',tagline:'CUSTOM TAGLINE',logo:'/custom-logo.png',mobileLogo:'/mobile-logo.png',favicon:'/favicon.png',preloaderLogo:''},
  colors:{bg:'#111111',surface:'#151515',text:'#eeeeee',muted:'#888888',primary:'#aa0000',accent:'#00ff88',border:'#333333'},
  typography:{display:'Arial, sans-serif',body:'Arial, sans-serif',mono:'monospace',h1:'80px',bodySize:'16px',tracking:'-0.02em'},
  navigation:{fixed:true,transparentHero:true,blur:true,menuStyle:'fullscreen',logoPosition:'left',sceneIndicator:true,soundToggle:true},
  effects:{grain:true,scanlines:true,glitch:true,cursor:true,magnetic:true,motion:'subtle'},
  sound:{enabled:true},
  seo:{siteTitle:'LEGACY SEO',description:'KEEP ME',ogImage:'/legacy-og.png'}
};

async function loadStudio(page) {
  await page.route('https://fonts.googleapis.com/**', route => route.fulfill({status:200,contentType:'text/css',body:''}));
  await page.route('**/custom-logo.png', route => route.fulfill({contentType:'image/png',body:pixel}));
  await page.route('**/mobile-logo.png', route => route.fulfill({contentType:'image/png',body:pixel}));
  await page.route('**/favicon.png', route => route.fulfill({contentType:'image/png',body:pixel}));
  const themeJson = JSON.stringify(baseTheme);
  const stateJson = JSON.stringify([
    {setting_key:'theme.active',setting_value:'core'},
    {setting_key:'theme.core',setting_value:themeJson}
  ]);
  const html = '<!doctype html><html><head><meta charset="utf-8"><style>' + studioCss + '</style></head><body>' +
    '<main><div id="theme-root"></div></main><script>' +
    'var state={theme:null,themeSettings:' + stateJson + ',themeMedia:[]};' +
    'var THEME_DEFAULT=' + themeJson + ';' +
    'function deepMergeTheme(base,extra){const out=JSON.parse(JSON.stringify(base||{}));const merge=(a,b)=>Object.keys(b||{}).forEach(k=>{if(b[k]&&typeof b[k]==="object"&&!Array.isArray(b[k])&&a[k]&&typeof a[k]==="object")merge(a[k],b[k]);else a[k]=b[k];});merge(out,extra||{});return out;}' +
    'async function req(path){if(path==="/settings")return {data:state.themeSettings};if(path==="/media")return {data:state.themeMedia};throw new Error("unexpected request "+path);}' +
    'window.BRVTALFeedback={progress(){},success(){},error(){}};' +
    '</script><script>' + studioJs + '</script></body></html>';
  await page.route(harnessUrl, route => route.fulfill({contentType:'text/html; charset=utf-8',body:html}));
  await page.goto(harnessUrl);
  await page.evaluate(() => window.BRVTALThemeStudioV2.load());
  await expect(page.locator('[data-theme-studio-v2]')).toBeVisible();
}

test('Concept 05 customizer exposes only authoritative controls and preserves hidden legacy values', async ({ page }) => {
  await loadStudio(page);
  await expect(page.locator('[data-theme-tab="seo"]')).toHaveCount(0);
  await expect(page.locator('#th_sceneIndicator')).toHaveCount(0);
  await expect(page.locator('#th_h1')).toHaveCount(0);
  await expect(page.locator('#th_tracking')).toHaveCount(0);
  await page.locator('[data-theme-tab="manage"]').click();
  await expect(page.getByRole('button',{name:'RESET VISUALS TO CONCEPT 05'})).toBeVisible();
  const before = await page.evaluate(() => window.BRVTALThemeStudioV2.currentTheme());
  expect(before.seo).toEqual({siteTitle:'LEGACY SEO',description:'KEEP ME',ogImage:'/legacy-og.png'});
  expect(before.navigation.sceneIndicator).toBe(true);
  expect(before.typography.h1).toBe('80px');
  expect(before.typography.tracking).toBe('-0.02em');
  await page.getByRole('button',{name:'RESET VISUALS TO CONCEPT 05'}).click();
  const reset = await page.evaluate(() => window.BRVTALThemeStudioV2.currentTheme());
  expect(reset.slug).toBe('core');
  expect(reset.branding.siteName).toBe('CUSTOM BRVTAL');
  expect(reset.branding.logo).toBe('/custom-logo.png');
  expect(reset.branding.mobileLogo).toBe('/mobile-logo.png');
  expect(reset.branding.favicon).toBe('/favicon.png');
  expect(reset.colors).toMatchObject({bg:'#050505',text:'#F4F1E8',primary:'#E31B23',accent:'#B6FF00'});
  expect(reset.typography.display).toContain('Space Grotesk');
  expect(reset.typography.mono).toContain('Space Mono');
  expect(reset.typography.h1).toContain('clamp(');
  expect(reset.typography.tracking).toBe('-0.055em');
  expect(reset.seo.description).toBe('KEEP ME');
});

test('Theme Studio previews curated typography and canonical 1440/390 frames', async ({ page }) => {
  await loadStudio(page);
  await page.locator('[data-theme-tab="type"]').click();
  await expect(page.locator('#th_display option')).toContainText(['Space Grotesk · recommended']);
  await page.locator('#th_display').selectOption({label:'Space Grotesk · recommended'});
  await page.locator('#th_mono').selectOption({label:'Space Mono · recommended'});
  const href = await page.locator('#brvtal-theme-studio-fonts').getAttribute('href');
  expect(href).toContain('Space+Grotesk');expect(href).toContain('Space+Mono');expect(href).toContain('display=swap');
  await page.getByRole('button',{name:'1440'}).click();
  await expect(page.locator('[data-preview-logo] img')).toHaveAttribute('src',/\/custom-logo\.png$/);
  await expect(page.locator('[data-preview-favicon] img')).toHaveAttribute('src',/\/favicon\.png$/);
  const desktop = await page.locator('.tsv2-device').evaluate(el=>{const r=el.getBoundingClientRect();return r.width/r.height;});
  expect(desktop).toBeGreaterThan(1.7);expect(desktop).toBeLessThan(1.81);
  await page.getByRole('button',{name:'390'}).click();
  await expect(page.locator('[data-preview-logo] img')).toHaveAttribute('src',/\/mobile-logo\.png$/);
  const mobile = await page.locator('.tsv2-device').evaluate(el=>{const r=el.getBoundingClientRect();return r.width/r.height;});
  expect(mobile).toBeGreaterThan(.45);expect(mobile).toBeLessThan(.48);
  await expect(page.locator('[data-preview-tagline]')).toHaveText('CUSTOM TAGLINE');
});

test('Theme Studio group resets are bounded and keep unrelated visual state', async ({ page }) => {
  await loadStudio(page);
  await page.locator('[data-theme-tab="palette"]').click();
  await page.getByRole('button',{name:'RESET PALETTE'}).click();
  let theme = await page.evaluate(() => window.BRVTALThemeStudioV2.currentTheme());
  expect(theme.colors.primary).toBe('#E31B23');
  expect(theme.typography.display).toBe('Arial, sans-serif');
  expect(theme.typography.h1).toBe('80px');
  expect(theme.typography.tracking).toBe('-0.02em');
  expect(theme.effects.motion).toBe('subtle');
  await page.locator('[data-theme-tab="type"]').click();
  await page.getByRole('button',{name:'RESET TYPE'}).click();
  theme = await page.evaluate(() => window.BRVTALThemeStudioV2.currentTheme());
  expect(theme.typography.display).toContain('Space Grotesk');
  expect(theme.effects.motion).toBe('subtle');
  expect(theme.branding.siteName).toBe('CUSTOM BRVTAL');
});

test('Theme Studio remains touch-sized and horizontally contained at 390px', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await loadStudio(page);
  const geometry = await page.evaluate(() => ({
    innerWidth:window.innerWidth,
    scrollWidth:document.documentElement.scrollWidth,
    buttons:[...document.querySelectorAll('.tsv2-footer .btn')].map(button => button.getBoundingClientRect().height)
  }));
  expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.innerWidth);
  expect(Math.min(...geometry.buttons)).toBeGreaterThanOrEqual(44);
});
test('Theme Studio contrast check flags insufficient PAPER / BLACK readability', async ({ page }) => {
  await loadStudio(page);
  await page.locator('[data-theme-tab="palette"]').click();
  await page.locator('#th_bg').fill('#777777');
  await page.locator('#th_text').fill('#777777');
  await expect(page.locator('[data-contrast-check]')).toHaveAttribute('data-low-contrast','');
  await expect(page.locator('[data-contrast-value]')).toContainText('REVIEW');
  await page.locator('#th_bg').fill('#050505');
  await page.locator('#th_text').fill('#F4F1E8');
  await expect(page.locator('[data-contrast-check]')).not.toHaveAttribute('data-low-contrast','');
  await expect(page.locator('[data-contrast-value]')).toContainText('PASS');
});
