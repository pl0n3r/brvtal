import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const publicScript = readFileSync(join(process.cwd(), 'js/hero-slider.js'), 'utf8');
const publicCss = readFileSync(join(process.cwd(), 'css/hero-slider.css'), 'utf8');
const publicV2Css = readFileSync(join(process.cwd(), 'css/hero-slider-v2.css'), 'utf8');
const adminScript = readFileSync(join(process.cwd(), 'discadmin/hero-slider.js'), 'utf8');
const endpoint = readFileSync(join(process.cwd(), 'api/hero-slider.php'), 'utf8');
const harness = 'http://127.0.0.1:4173/hero-slider-v2-harness.html';

async function openHarness(page, data) {
  await page.route(harness, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><style>${publicCss}${publicV2Css}</style></head><body><main id="top"><section class="hero"><div id="static-hero">STATIC</div></section></main><script>${publicScript}</script></body></html>`
  }));
  await page.route('**/api/hero-slider.php', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({ ok:true, data })
  }));
  await page.goto(harness);
}

const baseSlide = {
  id:'v2', mediaType:'image', desktopSrc:'/hero.jpg', mobileSrc:'', poster:'', kicker:'', title:'', body:'', ctaLabel:'', ctaUrl:'', contentAlign:'left', overlay:30, transition:'fade'
};

test('v2 renders positioned text and CTA layers while replacing static hero', async ({ page }) => {
  await openHarness(page, {
    enabled:true, autoplay:false, interval:7000,
    slides:[{...baseSlide, layers:[
      {id:'headline',type:'text',name:'Headline',text:'RAVE TILL GRAVE',src:'',mobileSrc:'',url:'',x:25,y:35,width:50,mobileX:null,mobileY:null,mobileWidth:null,hiddenMobile:false,animation:'slide-up',delay:100,duration:650,align:'left'},
      {id:'cta',type:'cta',name:'CTA',text:'ENTER',src:'',mobileSrc:'',url:'#events',x:20,y:70,width:20,mobileX:null,mobileY:null,mobileWidth:null,hiddenMobile:false,animation:'fade',delay:250,duration:500,align:'left'}
    ]}]
  });
  await expect(page.locator('.hero')).toHaveClass(/hero-slider-active/);
  await expect(page.locator('#static-hero')).toBeHidden();
  await expect(page.locator('.brvtal-hero-layer.type-text')).toHaveText('RAVE TILL GRAVE');
  await expect(page.locator('.brvtal-hero-layer.type-cta')).toHaveAttribute('href','#events');
  await expect(page.locator('.brvtal-hero-layer.type-text')).toHaveAttribute('style', /left:25%/);
});

test('v2 honors mobile position overrides and hidden-mobile layers', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await openHarness(page, {
    enabled:true, autoplay:false, interval:7000,
    slides:[{...baseSlide, mobileSrc:'/hero-mobile.jpg', layers:[
      {id:'headline',type:'text',name:'Headline',text:'MOBILE',src:'',mobileSrc:'',url:'',x:10,y:20,width:60,mobileX:50,mobileY:40,mobileWidth:80,hiddenMobile:false,animation:'fade',delay:0,duration:650,align:'center'},
      {id:'desktop-only',type:'text',name:'Desktop only',text:'HIDE ME',src:'',mobileSrc:'',url:'',x:10,y:80,width:50,mobileX:null,mobileY:null,mobileWidth:null,hiddenMobile:true,animation:'fade',delay:0,duration:650,align:'left'}
    ]}]
  });
  await expect(page.locator('[data-hero-slide="0"] > img.brvtal-hero-media')).toHaveAttribute('src','/hero-mobile.jpg');
  const headline = page.locator('.brvtal-hero-layer.type-text');
  await expect(headline).toHaveCount(1);
  await expect(headline).toHaveAttribute('style', /left:50%/);
  await expect(headline).toHaveAttribute('style', /top:40%/);
  await expect(headline).toHaveAttribute('style', /width:80%/);
});

test('v2 admin and endpoint keep constraints explicit', async () => {
  expect(adminScript).toContain('const MAX_LAYERS = 12');
  expect(adminScript).toContain("['text','image','logo','cta']");
  expect(adminScript).toContain('pointerdown');
  expect(adminScript).toContain('data-duplicate-slide');
  expect(endpoint).toContain(', 0, 12');
  expect(endpoint).not.toContain('SELECT * FROM settings');
});

test('v2 admin creates and duplicates nonempty unique slide and layer IDs', async ({ page }) => {
  await page.setContent(`<!doctype html><html><head></head><body>
    <nav class="nav"><button type="button">EVENTS</button></nav>
    <main class="main"><div class="top"><span class="eyebrow"></span><h1></h1></div></main>
  </body></html>`);

  await page.evaluate(() => {
    window.state = { section: 'dashboard' };
    window.render = () => {};
    window.go = async () => {};
    window.req = async path => {
      if (path === '/settings') return { data: [] };
      if (path === '/media') return { data: [] };
      return { data: [] };
    };
  });
  await page.addScriptTag({ content: adminScript });
  await page.evaluate(() => window.go('hero-slider'));

  await page.locator('[data-add-slide]').click();
  await page.locator('[data-add-layer="text"]').click();

  const originalSlideId = await page.locator('[data-select-slide].active').getAttribute('data-select-slide');
  const originalLayerId = await page.locator('[data-select-layer].active').getAttribute('data-select-layer');
  expect(originalSlideId).toBeTruthy();
  expect(originalLayerId).toBeTruthy();

  await page.locator('[data-duplicate-slide]').click();

  const slideIds = await page.locator('[data-select-slide]').evaluateAll(nodes =>
    nodes.map(node => node.getAttribute('data-select-slide'))
  );
  const duplicateLayerId = await page.locator('[data-select-layer].active').getAttribute('data-select-layer');

  expect(slideIds).toHaveLength(2);
  expect(slideIds.every(Boolean)).toBe(true);
  expect(new Set(slideIds).size).toBe(2);
  expect(duplicateLayerId).toBeTruthy();
  expect(duplicateLayerId).not.toBe(originalLayerId);
});
