import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const publicScript = readFileSync(join(process.cwd(), 'js/hero-slider.js'), 'utf8');
const publicCss = readFileSync(join(process.cwd(), 'css/hero-slider.css'), 'utf8');
const adminScript = readFileSync(join(process.cwd(), 'discadmin/hero-slider.js'), 'utf8');
const adminCss = readFileSync(join(process.cwd(), 'discadmin/hero-slider.css'), 'utf8');
const publicEndpoint = readFileSync(join(process.cwd(), 'api/hero-slider.php'), 'utf8');

const harness = 'http://127.0.0.1:4173/hero-slider-harness.html';

async function openHarness(page, payload, status = 200) {
  await page.route(harness, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><style>.hero{min-height:600px}${publicCss}</style></head><body><main id="top"><section class="hero"><div id="static-hero">STATIC HERO</div></section></main><script>${publicScript}</script></body></html>`
  }));
  await page.route('**/api/hero-slider.php', route => route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(payload)
  }));
  await page.goto(harness);
}

test('public hero keeps the art-directed fallback when slider delivery is unavailable', async ({ page }) => {
  await openHarness(page, { ok:false }, 500);
  await expect(page.locator('.hero')).not.toHaveClass(/hero-slider-active/);
  await expect(page.locator('#static-hero')).toBeVisible();
});

test('published slider uses mobile media override and touch-sized controls', async ({ page }) => {
  await page.setViewportSize({ width:390, height:844 });
  await openHarness(page, {
    ok:true,
    data:{
      enabled:true,
      autoplay:false,
      interval:7000,
      slides:[
        {id:'one',mediaType:'image',desktopSrc:'/desktop.jpg',mobileSrc:'/mobile.jpg',poster:'',kicker:'BRVTAL',title:'MOBILE',body:'',ctaLabel:'',ctaUrl:'',contentAlign:'left',overlay:35},
        {id:'two',mediaType:'image',desktopSrc:'/two.jpg',mobileSrc:'',poster:'',kicker:'',title:'SECOND',body:'',ctaLabel:'',ctaUrl:'',contentAlign:'center',overlay:35}
      ]
    }
  });
  await expect(page.locator('.hero')).toHaveClass(/hero-slider-active/);
  await expect(page.locator('[data-hero-slide="0"] img')).toHaveAttribute('src','/mobile.jpg');
  await expect(page.locator('[data-hero-slide="0"] img')).toHaveAttribute('alt', '');
  await expect(page.locator('#static-hero')).toBeHidden();
  const dot = page.locator('[data-hero-dot="0"]');
  const box = await dot.boundingBox();
  expect(box?.width).toBeGreaterThanOrEqual(44);
  expect(box?.height).toBeGreaterThanOrEqual(44);
});

test('public hero reapplies responsive media and layer overrides after crossing breakpoint', async ({ page }) => {
  await page.setViewportSize({ width:900, height:800 });
  await openHarness(page, {
    ok:true,
    data:{
      enabled:true,
      autoplay:false,
      interval:7000,
      slides:[
        {id:'one',mediaType:'image',desktopSrc:'/one.jpg',mobileSrc:'/one-mobile.jpg',title:'FIRST',contentAlign:'left',overlay:20,layers:[]},
        {id:'two',mediaType:'image',desktopSrc:'/two-desktop.jpg',mobileSrc:'/two-mobile.jpg',title:'SECOND',contentAlign:'left',overlay:20,layers:[
          {id:'art',type:'image',src:'/layer-desktop.png',mobileSrc:'/layer-mobile.png',x:10,y:20,width:30,mobileX:35,mobileY:45,mobileWidth:55,hiddenMobile:false,animation:'fade',delay:0,duration:650,align:'left'},
          {id:'mobile-hide',type:'text',text:'HIDE ME',x:15,y:25,width:40,hiddenMobile:true,animation:'fade',delay:0,duration:650,align:'left'}
        ]}
      ]
    }
  });

  await page.locator('[data-hero-next]').click();
  const second = page.locator('[data-hero-slide="1"]');
  const media = second.locator('.brvtal-hero-media');
  const art = second.locator('.brvtal-hero-layer.type-image');
  await expect(second).toHaveClass(/active/);
  await expect(media).toHaveAttribute('src','/two-desktop.jpg');
  await expect(art.locator('img')).toHaveAttribute('src','/layer-desktop.png');
  await expect(art.locator('img')).toHaveAttribute('alt', '');
  await expect.poll(() => art.evaluate(node => [node.style.left,node.style.top,node.style.width])).toEqual(['10%','20%','30%']);
  await expect(second.getByText('HIDE ME')).toHaveCount(1);

  await page.setViewportSize({ width:390, height:844 });
  await expect(second).toHaveClass(/active/);
  await expect(media).toHaveAttribute('src','/two-mobile.jpg');
  await expect(art.locator('img')).toHaveAttribute('src','/layer-mobile.png');
  await expect.poll(() => art.evaluate(node => [node.style.left,node.style.top,node.style.width])).toEqual(['35%','45%','55%']);
  await expect(second.getByText('HIDE ME')).toHaveCount(0);
  await expect(page.locator('[data-hero-current]')).toHaveText('02');

  await page.setViewportSize({ width:900, height:800 });
  await expect(second).toHaveClass(/active/);
  await expect(media).toHaveAttribute('src','/two-desktop.jpg');
  await expect(art.locator('img')).toHaveAttribute('src','/layer-desktop.png');
  await expect.poll(() => art.evaluate(node => [node.style.left,node.style.top,node.style.width])).toEqual(['10%','20%','30%']);
  await expect(second.getByText('HIDE ME')).toHaveCount(1);
});

test('published desktop text layers stay inside the fixed-header safe area', async ({ page }) => {
  await page.setViewportSize({width:1440,height:800});
  await openHarness(page, {
    ok:true,
    data:{
      enabled:true,
      autoplay:false,
      interval:7000,
      slides:[{
        id:'safe',
        mediaType:'image',
        desktopSrc:'/safe.jpg',
        title:'',
        contentAlign:'left',
        overlay:20,
        layers:[
          {id:'top',type:'text',text:'TOP SIGNAL',x:30,y:0,width:45,hiddenMobile:false,animation:'none',delay:0,duration:650,align:'left'},
          {id:'bottom',type:'text',text:'BOTTOM SIGNAL',x:30,y:100,width:45,hiddenMobile:false,animation:'none',delay:0,duration:650,align:'left'}
        ]
      }]
    }
  });

  const geometry = await page.locator('.brvtal-hero-slide.active').evaluate(slide => {
    const hero = slide.getBoundingClientRect();
    const layers = [...slide.querySelectorAll('.brvtal-hero-layer.type-text')].map(node => node.getBoundingClientRect());
    return {heroTop:hero.top,heroBottom:hero.bottom,top:layers[0].top,bottom:layers[1].bottom};
  });
  expect(geometry.top).toBeGreaterThanOrEqual(geometry.heroTop + 78);
  expect(geometry.bottom).toBeLessThanOrEqual(geometry.heroBottom - 40);
});

test('admin manager contract remains mobile-first and public endpoint is allowlisted', async () => {
  expect(adminScript).toContain("const KEY = 'home.hero.slider'");
  expect(adminScript).toContain("previewMode = 'desktop'");
  expect(adminScript).toContain("data-preview=\"mobile\"");
  expect(adminScript).toContain("mobileSrc");
  expect(adminCss).toMatch(/min-height:44px/);
  expect(adminCss).toMatch(/@media\(max-width:760px\)/);
  expect(publicEndpoint).toContain("WHERE setting_key = ? LIMIT 1");
  expect(publicEndpoint).toContain("['home.hero.slider']");
  expect(publicEndpoint).not.toContain('SELECT * FROM settings');
});

test('inactive banner images wait for selection and autoplay can be paused', async ({ page }) => {
  await openHarness(page, {ok:true,data:{enabled:true,autoplay:true,interval:2500,slides:[
    {id:'one',mediaType:'image',desktopSrc:'/one.jpg',title:'FIRST',overlay:0},
    {id:'two',mediaType:'image',desktopSrc:'/two.jpg',title:'SECOND',overlay:0}
  ]}});
  const first = page.locator('[data-hero-slide="0"]');
  const secondImage = page.locator('[data-hero-slide="1"] img');
  await expect(first).toHaveCSS('--hero-overlay', '0');
  await expect(secondImage).not.toHaveAttribute('src', /.+/);
  const pause = page.locator('[data-hero-pause]');
  await pause.click();
  await expect(pause).toHaveAttribute('aria-pressed', 'true');
  await page.waitForTimeout(2700);
  await expect(first).toHaveClass(/active/);
  await page.locator('[data-hero-next]').click();
  await expect(secondImage).toHaveAttribute('src', '/two.jpg');
});
