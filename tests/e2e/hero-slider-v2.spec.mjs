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


/**
 * Mounts the real Hero Slider admin runtime with optional Web Crypto removal.
 */
async function openAdminUidHarness(page, { disableCrypto = false, mediaItems = [] } = {}) {
  await page.setContent(`<!doctype html><html><head></head><body>
    <nav class="nav"><button type="button">EVENTS</button></nav>
    <main class="main"><div class="top"><span class="eyebrow"></span><h1></h1></div></main>
  </body></html>`);

  await page.evaluate(({ disableCrypto, mediaItems }) => {
    window.state = { section: 'dashboard' };
    window.render = () => {};
    window.__heroNativeGo = [];
    window.__heroNativeNavigationFailure = '';
    window.go = async section => {
      if (window.__heroNativeNavigationFailure) {
        throw new Error(window.__heroNativeNavigationFailure);
      }
      window.__heroNativeGo.push(section);
      window.state.section = section;
      return true;
    };
    window.__heroSavePayloads = [];
    window.__heroSettingsReadFailure = '';
    window.__heroSettingsReadFailures = 0;
    window.req = async (path, options = {}) => {
      if (path === '/settings' && options.method === 'POST') {
        window.__heroSavePayloads.push(JSON.parse(options.body));
        return { data: [] };
      }
      if (path === '/settings?key=home.hero.slider') {
        if (window.__heroSettingsReadFailures > 0) {
          window.__heroSettingsReadFailures -= 1;
          throw new Error(window.__heroSettingsReadFailure || 'SETTINGS_UNAVAILABLE');
        }
        if (window.__heroSettingsReadFailure) throw new Error(window.__heroSettingsReadFailure);
        return { data: [] };
      }
      if (path === '/media?view=hero-picker') return { data: mediaItems };
      return { data: [] };
    };
    if (disableCrypto) {
      Object.defineProperty(globalThis.crypto, 'getRandomValues', {
        configurable: true,
        value: undefined,
      });
    }
  }, { disableCrypto, mediaItems });

  await page.addScriptTag({ content: adminScript });
  await page.evaluate(() => window.go('hero-slider'));
}

/**
 * Exercises UID creation and duplication and returns every generated ID.
 */
async function exerciseAdminUidFlow(page) {
  await page.locator('[data-add-slide]').click();
  await page.locator('[data-add-layer="text"]').click();

  const originalSlideId = await page.locator('[data-select-slide].active').getAttribute('data-select-slide');
  const originalLayerId = await page.locator('[data-select-layer].active').getAttribute('data-select-layer');

  await page.locator('[data-duplicate-slide]').click();

  const slideIds = await page.locator('[data-select-slide]').evaluateAll(nodes =>
    nodes.map(node => node.getAttribute('data-select-slide'))
  );
  const duplicateLayerId = await page.locator('[data-select-layer].active').getAttribute('data-select-layer');

  return [...slideIds, originalLayerId, duplicateLayerId];
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
  expect(adminScript).toContain('function bindSlideControls(host)');
  expect(adminScript).toContain('function bindLayerControls(host)');
  expect(adminScript).toContain('function bindEditorControls(host)');
  expect(adminScript).toContain('function bindGlobalControls(host)');
  expect(endpoint).toContain(', 0, 12');
  expect(endpoint).not.toContain('SELECT * FROM settings');
});


test('v2 admin warns before discarding dirty Banners navigation and preserves state on cancel', async ({ page }) => {
  await openAdminUidHarness(page);
  await page.locator('[data-add-slide]').click();
  expect(await page.evaluate(() => window.BRVTALHeroSliderGuard.hasUnsavedChanges())).toBe(true);

  page.once('dialog', dialog => dialog.dismiss());
  const result = await page.evaluate(() => window.go('events'));

  expect(result).toBe(false);
  expect(await page.evaluate(() => window.__heroNativeGo)).toEqual([]);
  expect(await page.evaluate(() => window.state.section)).toBe('hero-slider');
  expect(await page.evaluate(() => window.BRVTALHeroSliderGuard.hasUnsavedChanges())).toBe(true);
});

test('v2 admin clears dirty state only after save or successful confirmed navigation', async ({ page }) => {
  await openAdminUidHarness(page);
  await page.locator('[data-add-slide]').click();
  await page.locator('[data-field="desktopSrc"]').fill('https://cdn.example.test/hero.jpg');
  expect(await page.evaluate(() => window.BRVTALHeroSliderGuard.hasUnsavedChanges())).toBe(true);

  await page.locator('[data-save-slider]').click();
  await expect.poll(() => page.evaluate(() => window.__heroSavePayloads.length)).toBe(1);
  expect(await page.evaluate(() => window.BRVTALHeroSliderGuard.hasUnsavedChanges())).toBe(false);

  await page.locator('[data-field="name"]').fill('Changed again');
  expect(await page.evaluate(() => window.BRVTALHeroSliderGuard.hasUnsavedChanges())).toBe(true);

  page.once('dialog', dialog => dialog.accept());
  await page.evaluate(() => window.go('events'));

  expect(await page.evaluate(() => window.__heroNativeGo)).toEqual(['events']);
  expect(await page.evaluate(() => window.BRVTALHeroSliderGuard.hasUnsavedChanges())).toBe(false);
});

test('v2 admin keeps dirty state when confirmed navigation fails', async ({ page }) => {
  await openAdminUidHarness(page);
  await page.locator('[data-add-slide]').click();
  await page.evaluate(() => { window.__heroNativeNavigationFailure = 'EVENTS_UNAVAILABLE'; });

  page.once('dialog', dialog => dialog.accept());
  const message = await page.evaluate(() => window.go('events').catch(error => error.message));

  expect(message).toBe('EVENTS_UNAVAILABLE');
  expect(await page.evaluate(() => window.BRVTALHeroSliderGuard.hasUnsavedChanges())).toBe(true);
  expect(await page.evaluate(() => window.state.section)).toBe('hero-slider');
});

test('v2 admin preserves dirty state when confirmed Banners reload fails', async ({ page }) => {
  await openAdminUidHarness(page);
  await page.locator('[data-add-slide]').click();
  await page.evaluate(() => { window.__heroSettingsReadFailure = 'SETTINGS_UNAVAILABLE'; });

  page.once('dialog', dialog => dialog.accept());
  const result = await page.evaluate(() => window.go('hero-slider'));

  expect(result).toBe(false);
  expect(await page.evaluate(() => window.BRVTALHeroSliderGuard.hasUnsavedChanges())).toBe(true);
  await expect(page.locator('.hero-slider-error')).toContainText('SETTINGS_UNAVAILABLE');
});

test('v2 admin reopens Banners across repeated Dashboard cycles without sticking on loading', async ({ page }) => {
  await openAdminUidHarness(page);

  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await expect(page.locator('#hero-slider-root')).toBeVisible();
    await expect(page.locator('.hero-manager')).toBeVisible();
    await expect(page.locator('.hero-slider-loading')).toHaveCount(0);
    await expect(page.locator('.hero-slider-error')).toHaveCount(0);

    if (attempt < 3) {
      expect(await page.evaluate(() => window.go('dashboard'))).toBe(true);
      expect(await page.evaluate(() => window.state.section)).toBe('dashboard');
      expect(await page.evaluate(() => window.go('hero-slider'))).toBe(true);
      expect(await page.evaluate(() => window.state.section)).toBe('hero-slider');
    }
  }

  expect(await page.evaluate(() => window.__heroNativeGo)).toEqual(['dashboard','dashboard']);
});

test('v2 admin retries one transient Settings read when reopening Banners', async ({ page }) => {
  await openAdminUidHarness(page);

  await page.evaluate(() => {
    window.__heroSettingsReadFailures = 1;
  });
  expect(await page.evaluate(() => window.go('dashboard'))).toBe(true);
  expect(await page.evaluate(() => window.go('hero-slider'))).toBe(true);

  await expect(page.locator('.hero-manager')).toBeVisible();
  expect(await page.evaluate(() => window.BRVTALHeroSliderDiagnostics.lastLoad())).toMatchObject({
    status:'loaded',
    settingsAttempts:2,
    hostRecovered:false
  });
});

test('v2 admin recreates a removed Hero host while Banners remains the active workspace', async ({ page }) => {
  await openAdminUidHarness(page);

  await page.evaluate(() => {
    const nativeReq = window.req;
    let removeHostOnce = true;
    window.req = async (path, options = {}) => {
      const result = await nativeReq(path, options);
      if (path === '/settings?key=home.hero.slider' && removeHostOnce) {
        removeHostOnce = false;
        document.getElementById('hero-slider-root')?.remove();
      }
      return result;
    };
  });

  expect(await page.evaluate(() => window.go('dashboard'))).toBe(true);
  expect(await page.evaluate(() => window.go('hero-slider'))).toBe(true);

  await expect(page.locator('#hero-slider-root .hero-manager')).toBeVisible();
  expect(await page.evaluate(() => window.BRVTALHeroSliderDiagnostics.lastLoad())).toMatchObject({
    status:'loaded',
    settingsAttempts:1,
    hostRecovered:true
  });
});

test('v2 admin registers a beforeunload guard only while Banners is dirty', async ({ page }) => {
  await openAdminUidHarness(page);

  const cleanPrevented = await page.evaluate(() => {
    const event = new Event('beforeunload', {cancelable:true});
    window.dispatchEvent(event);
    return event.defaultPrevented;
  });
  expect(cleanPrevented).toBe(false);

  await page.locator('[data-add-slide]').click();
  const dirtyPrevented = await page.evaluate(() => {
    const event = new Event('beforeunload', {cancelable:true});
    window.dispatchEvent(event);
    return event.defaultPrevented;
  });
  expect(dirtyPrevented).toBe(true);
});

test('v2 admin creates and duplicates nonempty unique slide and layer IDs', async ({ page }) => {
  await openAdminUidHarness(page);
  const ids = await exerciseAdminUidFlow(page);

  expect(ids).toHaveLength(4);
  expect(ids.every(Boolean)).toBe(true);
  expect(new Set(ids).size).toBe(ids.length);
});

test('v2 admin UID fallback stays unique when Web Crypto is unavailable', async ({ page }) => {
  await openAdminUidHarness(page, { disableCrypto: true });
  const ids = await exerciseAdminUidFlow(page);

  expect(ids).toHaveLength(4);
  expect(ids.every(Boolean)).toBe(true);
  expect(new Set(ids).size).toBe(ids.length);
});

test('v2 admin blocks enabled slides without valid primary media before save', async ({ page }) => {
  await openAdminUidHarness(page);
  await page.locator('[data-add-slide]').click();
  await page.locator('[data-config-field="enabled"]').check();
  await page.locator('[data-save-slider]').click();

  await expect.poll(() => page.evaluate(() => window.__heroSavePayloads.length)).toBe(0);
  await expect(page.locator('[data-save-slider]')).toBeEnabled();
  await expect(page.locator('[data-save-slider]')).toHaveText('SAVE');
});

test('v2 admin accepts a published Media Library asset as primary media', async ({ page }) => {
  await openAdminUidHarness(page, {
    mediaItems:[{
      id:1,
      type:'image',
      status:'published',
      title:'CI HERO',
      file_path:'/uploads/ci/hero-integrity.jpg',
    }],
  });
  await page.locator('[data-add-slide]').click();
  await page.locator('[data-config-field="enabled"]').check();
  await page.locator('[data-field="desktopSrc"]').fill('/uploads/ci/hero-integrity.jpg');
  await page.locator('[data-save-slider]').click();

  await expect.poll(() => page.evaluate(() => window.__heroSavePayloads.length)).toBe(1);
});

test('v2 admin bindings preserve preview, config, save and deletion behavior', async ({ page }) => {
  await openAdminUidHarness(page);

  await page.locator('[data-add-slide]').click();
  await page.locator('[data-add-layer="text"]').click();

  await page.locator('[data-preview="mobile"]').click();
  await expect(page.locator('.hero-preview-frame')).toHaveClass(/mobile/);

  await page.locator('[data-config-field="enabled"]').check();
  await page.locator('[data-config-field="autoplay"]').uncheck();
  await page.locator('[data-config-field="interval"]').selectOption('9000');
  await page.locator('[data-field="desktopSrc"]').fill('https://cdn.example.test/hero.jpg');
  await page.locator('[data-save-slider]').click();

  await expect.poll(() => page.evaluate(() => window.__heroSavePayloads.length)).toBe(1);
  const saved = await page.evaluate(() => window.__heroSavePayloads.at(-1));
  const payload = JSON.parse(saved.setting_value);
  expect(payload.enabled).toBe(true);
  expect(payload.autoplay).toBe(false);
  expect(payload.interval).toBe(9000);
  expect(payload.slides).toHaveLength(1);
  expect(payload.slides[0].layers).toHaveLength(1);

  await page.locator('[data-delete-layer]').click();
  await expect(page.locator('[data-select-layer]')).toHaveCount(0);

  await page.locator('[data-delete-slide]').click();
  await expect(page.locator('[data-select-slide]')).toHaveCount(0);
});
