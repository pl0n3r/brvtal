import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { join } from 'node:path';

const root = process.cwd();
const css = [
  'css/style.css',
  'css/public-home-phase-a.css',
  'css/public-concept05-tokens.css',
  'css/public-concept05-home.css',
  'css/public-concept05-hero.css',
].map(path => readFileSync(join(root, path), 'utf8')).join('\n');
const runtime = readFileSync(join(root, 'js/public-concept05-hero.js'), 'utf8');

const fixture = `<!doctype html>
<html>
<head>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>${css}</style>
</head>
<body data-concept="05">
  <header class="nav">
    <a class="brand" href="#top"><span>BRVTAL</span><small>RAVE TILL GRAVE</small></a>
    <nav class="c5-header-nav" aria-label="Primary"><a href="#events">NIGHTS</a><a href="#artists">ARTISTS</a><a href="#sets">SOUND</a></nav>
    <div class="nav-right"><button class="menu">MENU</button></div>
  </header>
  <main id="top">
    <section class="hero scene home-phase-a-hero" data-scene="CORE" data-index="01">
      <div class="hero-grid"></div>
      <div class="hero-scan"></div>
      <div class="hero-glitch-lines"></div>
      <div class="hero-copy">
        <div class="eyebrow mono">UNDERGROUND ELECTRONIC CULTURE / PEREIRA / COLOMBIA</div>
        <h1 class="hero-title" data-text="BRVTAL">BRVTAL</h1>
        <div class="hero-sub"><span data-site-tagline>RAVE TILL GRAVE</span><span>EST. 2026</span></div>
        <div class="hero-declaration c5-hero-statement">
          <span class="mono" data-c5-hero-eyebrow data-c5-default-es="EVENTS / SOUND / ARTISTS / ARCHIVE" data-c5-default-en="EVENTS / SOUND / ARTISTS / ARCHIVE">EVENTS / SOUND / ARTISTS / ARCHIVE</span>
          <strong data-c5-default-es="MÁS QUE FIESTAS.&#10;UNA CULTURA EN MOVIMIENTO." data-c5-default-en="MORE THAN PARTIES.&#10;A CULTURE IN MOTION." data-c5-display-locale="es" data-c5-hero-manifesto>MÁS QUE FIESTAS. UNA CULTURA EN MOVIMIENTO.</strong>
          <p data-c5-hero-description>PEREIRA / COLOMBIA · UNDERGROUND ELECTRONIC CULTURE</p>
          <a class="c5-hero-explore magnetic" href="#genesis" data-cursor="EXPLORE" data-c5-hero-cta data-c5-default-es="EXPLORA BRVTAL" data-c5-default-en="EXPLORE BRVTAL">EXPLORA BRVTAL <span>↘</span></a>
        </div>
      </div>
      <figure class="c5-hero-documentary" data-c5-hero-documentary hidden>
        <img data-c5-hero-documentary-image src="" alt="" decoding="async" fetchpriority="low">
        <figcaption><span>DOCUMENT / MEDIA LIBRARY</span><span data-c5-hero-documentary-label>BRVTAL ARCHIVE</span></figcaption>
      </figure>
      <div class="hero-logo-wrap"></div>
      <div class="hero-bottom mono"><span>SCROLL TO ENTER</span><span>NOISE / SIGNAL / MUSIC</span></div>
    </section>
    <section id="genesis"></section>
    <section id="events"></section>
    <section id="artists"></section>
    <section id="sets"></section>
  </main>
  <nav class="c5-bottom-nav" aria-label="Primary mobile"><a href="#events">NIGHTS</a><a href="#artists">ARTISTS</a><a href="#sets">SOUND</a><a href="/releases">RECORDS</a><a href="#transmissions">JOURNAL</a></nav>
</body>
</html>`;

const payload = {
  payload: {
    data: {
      settings: {
        site: {
          tagline: 'RAVE TILL GRAVE',
          description: 'Independent electronic culture from Pereira.'
        }
      },
      media: [{
        id: 99,
        type: 'image',
        title: 'BRVTAL Session 05',
        file_path: 'https://example.test/night.jpg',
        alt_text: 'Crowd at a BRVTAL session'
      }],
      memories: [],
      events: [],
      archive: { events: [] }
    }
  },
  url: '/api/public.php'
};

async function mount(page, viewport) {
  await page.setViewportSize(viewport);
  await page.route('https://example.test/night.jpg', route => route.fulfill({
    status: 200,
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="black"/></svg>'
  }));
  await page.setContent(fixture);
  await page.evaluate(data => {
    window.BRVTALPublicDataPromise = Promise.resolve(data);
    window.BRVTALRuntimeReady = Promise.resolve({ mode:'test' });
  }, payload);
  await page.addScriptTag({ content: runtime });
  await expect(page.locator('[data-c5-hero-documentary]')).toBeVisible();
}

test('Concept 05 desktop Hero keeps authored 1440 composition inside the safe viewport', async ({ page }) => {
  await mount(page, { width:1440, height:900 });

  await expect(page.locator('[data-c5-hero-description]')).toHaveText(
    'Independent electronic culture from Pereira.'
  );
  await expect(page.locator('[data-c5-hero-documentary-image]')).toHaveAttribute(
    'src',
    'https://example.test/night.jpg'
  );
  await expect(page.locator('[data-c5-hero-documentary-image]')).toHaveAttribute(
    'alt',
    'Crowd at a BRVTAL session'
  );
  await expect(page.locator('[data-c5-hero-documentary-label]')).toHaveText('BRVTAL Session 05');
  await expect(page.locator('.c5-hero-explore')).toHaveAttribute('href', '#genesis');

  const geometry = await page.evaluate(() => {
    const rect = selector => document.querySelector(selector).getBoundingClientRect();
    const nav = rect('.nav');
    const hero = rect('.home-phase-a-hero');
    const copy = rect('.hero-copy');
    const title = rect('.hero-title');
    const statement = rect('.hero-declaration');
    const documentary = rect('.c5-hero-documentary');
    const cta = rect('.c5-hero-explore');
    return {
      navBottom:nav.bottom,
      heroRight:hero.right,
      heroBottom:hero.bottom,
      copyTop:copy.top,
      titleLeft:title.left,
      titleFont:parseFloat(getComputedStyle(document.querySelector('.hero-title')).fontSize),
      statementLeft:statement.left,
      statementRight:statement.right,
      statementBottom:statement.bottom,
      documentaryLeft:documentary.left,
      documentaryRight:documentary.right,
      documentaryBottom:documentary.bottom,
      ctaHeight:cta.height,
      scrollWidth:document.documentElement.scrollWidth,
      viewport:document.documentElement.clientWidth,
    };
  });

  expect(geometry.copyTop).toBeGreaterThanOrEqual(geometry.navBottom + 20);
  expect(geometry.titleFont).toBeGreaterThanOrEqual(130);
  expect(geometry.titleFont).toBeLessThanOrEqual(260);
  expect(geometry.statementLeft).toBeGreaterThan(geometry.titleLeft + 500);
  expect(geometry.statementRight).toBeLessThanOrEqual(geometry.heroRight - 18);
  expect(geometry.statementBottom).toBeLessThan(geometry.heroBottom - 30);
  expect(geometry.documentaryLeft).toBeLessThan(geometry.statementLeft);
  expect(geometry.documentaryRight).toBeGreaterThan(geometry.statementLeft - 80);
  expect(geometry.documentaryBottom).toBeLessThan(geometry.heroBottom - 30);
  expect(geometry.ctaHeight).toBeGreaterThanOrEqual(44);
  expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.viewport);
});

test('Concept 05 mobile Hero is an authored 390 composition with stacked identity and no overflow', async ({ page }) => {
  await mount(page, { width:390, height:844 });

  const geometry = await page.evaluate(() => {
    const rect = selector => document.querySelector(selector).getBoundingClientRect();
    const hero = rect('.home-phase-a-hero');
    const title = rect('.hero-title');
    const statement = rect('.hero-declaration');
    const documentary = rect('.c5-hero-documentary');
    const cta = rect('.c5-hero-explore');
    const bottomNav = rect('.c5-bottom-nav');
    const bottom = rect('.hero-bottom');
    return {
      heroLeft:hero.left,
      heroRight:hero.right,
      titleWidth:title.width,
      titleHeight:title.height,
      statementLeft:statement.left,
      statementRight:statement.right,
      statementBottom:statement.bottom,
      documentaryLeft:documentary.left,
      documentaryRight:documentary.right,
      documentaryBottom:documentary.bottom,
      ctaHeight:cta.height,
      bottomTop:bottom.top,
      navTop:bottomNav.top,
      scrollWidth:document.documentElement.scrollWidth,
      viewport:document.documentElement.clientWidth,
    };
  });

  expect(geometry.titleWidth).toBeLessThan(230);
  expect(geometry.titleHeight).toBeGreaterThan(150);
  expect(geometry.statementLeft).toBeGreaterThanOrEqual(geometry.heroLeft);
  expect(geometry.statementRight).toBeLessThanOrEqual(geometry.heroRight);
  expect(geometry.documentaryLeft).toBeGreaterThanOrEqual(geometry.heroLeft);
  expect(geometry.documentaryRight).toBeLessThanOrEqual(geometry.heroRight);
  expect(geometry.documentaryBottom).toBeLessThan(geometry.navTop);
  expect(geometry.statementBottom).toBeLessThan(geometry.navTop);
  expect(geometry.bottomTop).toBeLessThan(geometry.navTop);
  expect(geometry.ctaHeight).toBeGreaterThanOrEqual(44);
  expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.viewport);

  await expect(page.locator('[data-c5-hero-description]')).toBeVisible();
  await expect(page.locator('.c5-bottom-nav')).toBeVisible();
});

test('Concept 05 mobile Hero grows with managed copy instead of clipping the CTA', async ({ page }) => {
  const longPayload = structuredClone(payload);
  longPayload.payload.data.settings.site.description =
    'Independent electronic culture from Pereira built through nights, sound, artists, archives and long-form cultural documentation that must remain readable at enlarged text sizes.';

  await page.setViewportSize({ width:390, height:844 });
  await page.route('https://example.test/night.jpg', route => route.fulfill({
    status:200,
    contentType:'image/svg+xml',
    body:'<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"></svg>'
  }));
  await page.setContent(fixture);
  await page.evaluate(data => {
    document.documentElement.style.fontSize = '20px';
    window.BRVTALPublicDataPromise = Promise.resolve(data);
    window.BRVTALRuntimeReady = Promise.resolve({ mode:'test' });
  }, longPayload);
  await page.addScriptTag({ content: runtime });

  await expect(page.locator('.c5-hero-explore')).toBeVisible();
  const geometry = await page.evaluate(() => {
    const hero = document.querySelector('.home-phase-a-hero').getBoundingClientRect();
    const statement = document.querySelector('.hero-declaration').getBoundingClientRect();
    const cta = document.querySelector('.c5-hero-explore').getBoundingClientRect();
    return {
      heroBottom:hero.bottom,
      statementBottom:statement.bottom,
      ctaBottom:cta.bottom,
      scrollWidth:document.documentElement.scrollWidth,
      viewport:document.documentElement.clientWidth,
    };
  });
  expect(geometry.statementBottom).toBeLessThanOrEqual(geometry.heroBottom);
  expect(geometry.ctaBottom).toBeLessThanOrEqual(geometry.heroBottom);
  expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.viewport);
});

test('Concept 05 documentary frame stays hidden when managed media fails to load', async ({ page }) => {
  await page.setViewportSize({ width:1440, height:900 });
  await page.route('https://example.test/night.jpg', route => route.fulfill({ status:404, body:'missing' }));
  await page.setContent(fixture);
  await page.evaluate(data => {
    window.BRVTALPublicDataPromise = Promise.resolve(data);
    window.BRVTALRuntimeReady = Promise.resolve({ mode:'test' });
  }, payload);
  await page.addScriptTag({ content: runtime });

  await expect(page.locator('[data-c5-hero-documentary]')).toBeHidden();
  await expect(page.locator('[data-c5-hero-documentary-image]')).not.toHaveAttribute('src');
});

test('Concept 05 visual-test mode freezes Hero entrance motion deterministically', async ({ page }) => {
  await page.setViewportSize({ width:1440, height:900 });
  await page.route('https://example.test/night.jpg', route => route.fulfill({
    status:200,
    contentType:'image/svg+xml',
    body:'<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"></svg>'
  }));
  await page.setContent(fixture);
  await page.evaluate(data => {
    document.documentElement.classList.add('c5-visual-test');
    window.__heroMotionCalls = 0;
    window.gsap = {
      from: () => { window.__heroMotionCalls += 1; }
    };
    window.BRVTALPublicDataPromise = Promise.resolve(data);
    window.BRVTALRuntimeReady = Promise.resolve({ mode:'test' });
  }, payload);
  await page.addScriptTag({ content: runtime });
  await expect(page.locator('[data-c5-hero-documentary]')).toBeVisible();

  expect(await page.evaluate(() => window.__heroMotionCalls)).toBe(0);
});


test('Concept 05 owner reference v2 keeps the authored hero at 1440 and 390', async ({ page }) => {
  for (const viewport of [{ width:1440, height:900 }, { width:390, height:844 }]) {
    await page.setViewportSize(viewport);
    await page.setContent(fixture);
    await expect(page.locator('.hero-title')).toHaveText('BRVTAL');
    await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText('MÁS QUE FIESTAS. UNA CULTURA EN MOVIMIENTO.');
    await expect(page.locator('[data-c5-hero-description]')).toContainText('PEREIRA / COLOMBIA');
    await expect(page.locator('.c5-hero-explore')).toContainText('EXPLORA BRVTAL');
    const safe = await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
    expect(safe).toBe(true);
  }
});


test('Concept 05 CMS manifesto changes per locale and viewport without overflow', async ({ page }) => {
  for (const viewport of [{ width:390, height:844 }, { width:1440, height:900 }]) {
    await mount(page, viewport);
    for (const sample of [
      { locale:'es', value:'SONIDO. MEMORIA. COMUNIDAD.' },
      { locale:'en', value:'MORE THAN A SCENE. A CULTURE.' },
    ]) {
      const cms = structuredClone(payload);
      cms.payload.data.settings.site['hero_manifesto_' + sample.locale] = sample.value;
      await page.evaluate(({ data, locale }) => {
        document.documentElement.lang = locale;
        window.BRVTALConcept05Hero.projectManifesto(data.payload.data);
      }, { data:cms, locale:sample.locale });
      await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText(sample.value);
      await expect(page.locator('.c5-hero-explore')).toBeVisible();
      const noOverflow = await page.evaluate(() =>
        document.documentElement.scrollWidth <= document.documentElement.clientWidth
      );
      expect(noOverflow).toBe(true);
    }
    // A maximal accepted single-token CMS value must wrap without clipping.
    await page.evaluate(() => {
      document.documentElement.lang = 'es';
      window.BRVTALConcept05Hero.projectManifesto({
        settings: { site: { hero_manifesto_es:'X'.repeat(64) } }
      });
    });
    await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText('X'.repeat(64));
    const bounds = await page.evaluate(() => {
      const rect = selector => document.querySelector(selector).getBoundingClientRect();
      const hero = rect('.home-phase-a-hero');
      const statement = rect('.hero-declaration');
      const manifesto = rect('[data-c5-hero-manifesto]');
      const cta = rect('.c5-hero-explore');
      return { manifestoRight:manifesto.right, statementRight:statement.right,
        ctaBottom:cta.bottom, heroBottom:hero.bottom,
        scrollWidth:document.documentElement.scrollWidth,
        viewport:document.documentElement.clientWidth };
    });
    expect(bounds.manifestoRight).toBeLessThanOrEqual(bounds.statementRight + 1);
    expect(bounds.ctaBottom).toBeLessThanOrEqual(bounds.heroBottom + 1);
    expect(bounds.scrollWidth).toBeLessThanOrEqual(bounds.viewport);
    // Replay without resetting the DOM: language and rejected settings must
    // replace the previous CMS override with the correct trusted fallback.
    await page.evaluate(() => {
      document.documentElement.lang = 'en';
      window.BRVTALConcept05Hero.projectManifesto({
        settings: { site: { hero_manifesto_en:'' } }
      });
    });
    await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText(
      /MORE THAN PARTIES\.\s*A CULTURE IN MOTION\./
    );
    await page.evaluate(() => {
      document.documentElement.lang = 'es';
      window.BRVTALConcept05Hero.projectManifesto({
        settings: { site: { hero_manifesto_es:'SONIDO ACTIVO' } }
      });
      window.BRVTALConcept05Hero.projectManifesto({
        settings: { site: { hero_manifesto_es:'<script>alert(1)</script>' } }
      });
    });
    await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText(
      /MÁS QUE FIESTAS\.\s*UNA CULTURA EN MOVIMIENTO\./
    );
    await page.evaluate(() => {
      window.BRVTALConcept05Hero.projectManifesto({
        settings: { site: { hero_manifesto_es:'X'.repeat(65) } }
      });
    });
    await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText(
      /MÁS QUE FIESTAS\.\s*UNA CULTURA EN MOVIMIENTO\./
    );
  }
});


test('Concept 05 bootstraps CMS manifesto and tracks the public locale event', async ({ page }) => {
  const cms = structuredClone(payload);
  cms.payload.data.settings.site.hero_manifesto_es = 'PEREIRA CREA SU PROPIA CULTURA.';
  cms.payload.data.settings.site.hero_manifesto_en = 'PEREIRA CREATES ITS OWN CULTURE.';

  await page.setViewportSize({ width:390, height:844 });
  await page.route('https://example.test/night.jpg', route => route.fulfill({
    status:200,
    contentType:'image/svg+xml',
    body:'<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"></svg>',
  }));
  await page.setContent(fixture);
  await page.evaluate(data => {
    document.documentElement.lang = 'es';
    document.documentElement.dataset.locale = 'es';
    window.BRVTALPublicDataPromise = Promise.resolve(data);
    window.BRVTALRuntimeReady = Promise.resolve({ mode:'test' });
  }, cms);
  // No manual projectManifesto() call: the init path must hydrate from API.
  await page.addScriptTag({ content:runtime });
  await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText(
    'PEREIRA CREA SU PROPIA CULTURA.'
  );

  await page.evaluate(() => {
    document.documentElement.lang = 'en';
    document.documentElement.dataset.locale = 'en';
    window.dispatchEvent(new Event('brvtal:localechange'));
  });
  await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText(
    'PEREIRA CREATES ITS OWN CULTURE.'
  );

  await page.evaluate(() => {
    document.documentElement.lang = 'es';
    document.documentElement.dataset.locale = 'es';
    window.dispatchEvent(new Event('brvtal:localechange'));
  });
  await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText(
    'PEREIRA CREA SU PROPIA CULTURA.'
  );
});


test('Concept 05 authored hero stays visible with a published CMS slider at 390 and 1440', async ({ page }) => {
  const sliderJs = readFileSync(join(root, 'js/hero-slider.js'), 'utf8');
  const sliderCss = readFileSync(join(root, 'css/hero-slider.css'), 'utf8');
  const uri = 'http://127.0.0.1:4173/concept05-slider-precedence.html';
  const slidePayload = {ok:true,data:{enabled:true,autoplay:false,slides:[
    {id:'published',mediaType:'image',desktopSrc:'/published-event.jpg',
      title:'PUBLISHED CMS EVENT',contentAlign:'left',overlay:35,layers:[]}
  ]}};
  let apiRequests = 0;
  await page.route('**/api/hero-slider.php', route => {
    apiRequests += 1;
    return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(slidePayload)});
  });
  const htmlFor = concept => fixture.replace('data-concept="05"', 'data-concept="' + concept + '"')
    .replace('</head>', '<style>' + sliderCss + '</style></head>')
    .replace('</body>', '<script>' + sliderJs + '</script></body>');
  await page.route('**/concept05-slider-precedence.html', route => route.fulfill({
    status:200,contentType:'text/html; charset=utf-8',body:htmlFor('05')
  }));

  for (const viewport of [{width:390,height:844},{width:1440,height:900}]) {
    await page.setViewportSize(viewport);
    await page.goto(uri);
    await expect(page.locator('.home-phase-a-hero')).not.toHaveClass(/hero-slider-active/);
    await expect(page.locator('.brvtal-hero-slider')).toHaveCount(0);
    await expect(page.locator('.hero-title')).toBeVisible();
    await expect(page.locator('.c5-hero-explore')).toBeVisible();
    const noOverflow = await page.evaluate(() =>
      document.documentElement.scrollWidth <= document.documentElement.clientWidth
    );
    expect(noOverflow).toBe(true);
  }
  expect(apiRequests).toBe(0);

  // Existing tests/e2e/hero-slider.spec.mjs independently exercises the
  // slider on non-C05 routes, including its published CMS slides and controls.
  // Do not remount an unrelated route in this C05-specific test: that was a
  // brittle fixture transition and not part of this contract.
});


test('Concept 05 editable CTA and eyebrow hydrate safely and restore per locale', async ({ page }) => {
  await mount(page, {width:390,height:844});
  await page.evaluate(() => {
    document.documentElement.lang = 'es';
    document.documentElement.dataset.locale = 'es';
    window.BRVTALConcept05Hero.projectHeroEditorialCopy({
      settings:{site:{hero_cta_es:'EXPLORA LA ESCENA',hero_eyebrow_es:'NOCHES / ARTISTAS'}}
    });
  });
  const cta = page.locator('[data-c5-hero-cta]');
  const eyebrow = page.locator('[data-c5-hero-eyebrow]');
  await expect(cta).toContainText('EXPLORA LA ESCENA');
  await expect(cta).toHaveAttribute('href','#genesis');
  await expect(cta.locator('span')).toHaveText('↘');
  await expect(eyebrow).toHaveText('NOCHES / ARTISTAS');
  await page.evaluate(() => {
    document.documentElement.lang = 'en';
    document.documentElement.dataset.locale = 'en';
    window.BRVTALConcept05Hero.projectHeroEditorialCopy({
      settings:{site:{hero_cta_en:'<script>alert(1)</script>',hero_eyebrow_en:''}}
    });
  });
  await expect(cta).toContainText('EXPLORE BRVTAL');
  await expect(eyebrow).toHaveText('EVENTS / SOUND / ARTISTS / ARCHIVE');
  await expect(cta.locator('span')).toHaveText('↘');
  expect(await page.evaluate(() => document.querySelectorAll('script').length)).toBeGreaterThan(0);
  expect(await page.evaluate(() =>
    document.documentElement.scrollWidth <= document.documentElement.clientWidth
  )).toBe(true);
});


test('Concept 05 managed maximum editorial copy fits mobile viewport', async ({page}) => {
  await mount(page,{width:390,height:844});
  await page.evaluate(() => {
    document.documentElement.dataset.locale='es';
    window.BRVTALConcept05Hero.projectHeroEditorialCopy({
      settings:{site:{hero_cta_es:'X'.repeat(48),hero_eyebrow_es:'Y'.repeat(80)}}
    });
  });
  await expect(page.locator('[data-c5-hero-cta]')).toContainText('X'.repeat(48));
  await expect(page.locator('[data-c5-hero-eyebrow]')).toHaveText('Y'.repeat(80));
  await expect(page.locator('[data-c5-hero-cta]')).toHaveAttribute('data-c5-managed-cta','1');
  const bounds=await page.evaluate(() => {
    const rect=document.querySelector('[data-c5-hero-cta]').getBoundingClientRect();
    return {left:rect.left,right:rect.right,width:document.documentElement.clientWidth,
      scroll:document.documentElement.scrollWidth};
  });
  expect(bounds.left).toBeGreaterThanOrEqual(-1);
  expect(bounds.right).toBeLessThanOrEqual(bounds.width+1);
  expect(bounds.scroll).toBeLessThanOrEqual(bounds.width);
});


test('Concept 05 hydrates editable CMS hero fields on boot and locale change at 390/1440', async ({page}) => {
  for (const viewport of [{width:390,height:844},{width:1440,height:900}]) {
    const cms = structuredClone(payload);
    Object.assign(cms.payload.data.settings.site,{
      hero_manifesto_es:'CULTURA QUE NOS MUEVE.',
      hero_manifesto_en:'CULTURE THAT MOVES US.',
      hero_cta_es:'EXPLORA LA CULTURA',
      hero_cta_en:'EXPLORE OUR CULTURE',
      hero_eyebrow_es:'NOCHES / SONIDOS',
      hero_eyebrow_en:'NIGHTS / SOUNDS',
      description:'Cultura electrónica independiente.',
    });
    // Fresh document context also clears old locale-change listeners.
    await page.goto('about:blank');
    await page.setViewportSize(viewport);
    await page.route('https://example.test/night.jpg', route => route.fulfill({
      status:200,contentType:'image/svg+xml',
      body:'<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"></svg>',
    }));
    await page.setContent(fixture);
    await page.evaluate(source => {
      document.documentElement.lang='es';
      document.documentElement.dataset.locale='es';
      window.__heroCms = source;
      window.BRVTALPublicDataPromise=Promise.resolve(source);
      window.BRVTALRuntimeReady=Promise.resolve({mode:'test'});
    },cms);
    // No direct projectHeroEditorialCopy invocation: exercise the real startup.
    await page.addScriptTag({content:runtime});
    const cta=page.locator('[data-c5-hero-cta]');
    const eyebrow=page.locator('[data-c5-hero-eyebrow]');
    await expect(cta).toContainText('EXPLORA LA CULTURA');
    await expect(eyebrow).toHaveText('NOCHES / SONIDOS');
    await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText('CULTURA QUE NOS MUEVE.');
    await expect(page.locator('[data-c5-hero-description]'))
      .toHaveText('Cultura electrónica independiente.');
    await expect(cta).toHaveAttribute('href','#genesis');
    await expect(cta.locator('span')).toHaveText('↘');

    await page.evaluate(() => {
      document.documentElement.dataset.locale='en';
      document.documentElement.lang='en';
      window.dispatchEvent(new Event('brvtal:localechange'));
    });
    await expect(cta).toContainText('EXPLORE OUR CULTURE');
    await expect(eyebrow).toHaveText('NIGHTS / SOUNDS');
    await expect(page.locator('[data-c5-hero-manifesto]')).toHaveText('CULTURE THAT MOVES US.');
    await expect(cta.locator('span')).toHaveText('↘');

    // Even after a successful CMS override, invalid/missing values revert
    // to the current locale's trusted owner-v2 defaults, never to stale ES.
    await page.evaluate(() => {
      Object.assign(window.__heroCms.payload.data.settings.site,{
        hero_cta_en:'<script>alert(1)</script>',
        hero_eyebrow_en:'',
        hero_manifesto_en:'X'.repeat(65),
      });
      window.dispatchEvent(new Event('brvtal:localechange'));
    });
    await expect(cta).toContainText('EXPLORE BRVTAL');
    await expect(eyebrow).toHaveText('EVENTS / SOUND / ARTISTS / ARCHIVE');
    await expect(page.locator('[data-c5-hero-manifesto]'))
      .toHaveText(/MORE THAN PARTIES\.\s*A CULTURE IN MOTION\./);
    await expect(cta.locator('span')).toHaveText('↘');
    expect(await page.evaluate(() =>
      document.documentElement.scrollWidth <= document.documentElement.clientWidth
    )).toBe(true);
  }
});


test('Concept 05 PHP-rendered hero screenshot evidence at 390 and 1440', async ({page}) => {
  // Use the real PHP renderer and actual public HTML instead of a handcrafted
  // hero fixture. Editorial media is intentionally synthetic and disclosed in
  // the attached manifest: this is evidence, not final owner visual approval.
  const rendered = execFileSync('php', ['-r',
    "require 'config/public_home.php'; $_GET['locale']='es';"
    + "$html=file_get_contents('index.html'); echo brvtal_public_home_identity($html);"
  ], {cwd:root,encoding:'utf8',maxBuffer:12*1024*1024});
  expect(rendered).toContain('data-c5-hero-manifesto');
  expect(rendered).toContain('data-c5-hero-cta');
  expect(rendered).toContain('data-c5-hero-eyebrow');
  expect(rendered).toContain('data-concept="05"');

  const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900">'
    + '<rect width="1200" height="900" fill="#343434"/>'
    + '<path d="M0 600L1200 240M0 800L1200 440" stroke="#717171" stroke-width="75"/>'
    + '<text x="80" y="105" fill="#f5f5f5" font-size="28">SYNTHETIC CMS MEDIA — NOT AN OWNER PHOTO</text></svg>';
  const cms = structuredClone(payload);
  Object.assign(cms.payload.data.settings.site,{
    hero_manifesto_es:'MÁS QUE FIESTAS. UNA CULTURA EN MOVIMIENTO.',
    hero_cta_es:'EXPLORA BRVTAL',
    hero_eyebrow_es:'EVENTS / SOUND / ARTISTS / ARCHIVE',
    description:'PEREIRA / COLOMBIA · UNDERGROUND ELECTRONIC CULTURE',
  });
  cms.payload.data.media = [{
    type:'image',title:'Synthetic CMS evidence',
    file_path:'data:image/svg+xml;base64,'+Buffer.from(svg).toString('base64'),
    alt_text:'Synthetic monochrome editorial placeholder',
  }];
  const checkoutSha = execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim();
  expect(checkoutSha).toMatch(/^[0-9a-f]{40}$/);
  const evidence = {
    schemaVersion:1,checkoutSha,source:'PHP brvtal_public_home_identity(index.html)',
    fixture:'synthetic read-only CMS media (not real editorial photography)',
    reference:'docs/reference/home-concept05-owner-reference-v2.png',
    visualApproval:'PENDING_HUMAN_AB_COMPARISON',
    deviations:[
      'Media is a clearly labeled synthetic illustration, not representative owner photography',
      'Linked styles come from the exact checkout; external network assets are blocked',
      'Page scripts other than the hero runtime are stubbed: full-page functionality is not certified',
      'Real production CMS/Theme Studio appearance and full-page A/B still require separate review',
    ],
    captures:[],
  };
  // Linked stylesheets must match the checkout being reviewed. Returning empty
  // CSS made the former "PHP-rendered" screenshots visually non-representative.
  const html=rendered;
  const url='http://127.0.0.1:4173/concept05-php-evidence.html';
  await page.route('**/css/**', route => {
    const assetUrl=new URL(route.request().url());
    const pathname=assetUrl.pathname;
    if (assetUrl.origin!=='http://127.0.0.1:4173'
        || !/^\/css\/[a-zA-Z0-9_./-]+\.css$/.test(pathname)
        || pathname.split('/').includes('..')) return route.abort();
    try {
      return route.fulfill({
        status:200,contentType:'text/css; charset=utf-8',
        body:readFileSync(join(root,pathname.slice(1)),'utf8'),
      });
    } catch (_) {
      return route.abort();
    }
  });
  await page.route('**/*.js', route=>route.fulfill({status:200,contentType:'application/javascript',body:''}));
  await page.route('https://**/*',route=>route.abort());
  await page.route('**/concept05-php-evidence.html',route=>route.fulfill({
    status:200,contentType:'text/html; charset=utf-8',body:html,
  }));
  // Register only one bootstrap handler: Playwright retains addInitScript
  // registrations across navigations of the same Page.
  await page.addInitScript(source=>{
    window.BRVTALPublicDataPromise=Promise.resolve(source);
    window.BRVTALRuntimeReady=Promise.resolve({mode:'visual-evidence'});
  },cms);
  for (const viewport of [{width:390,height:844},{width:1440,height:900}]) {
    await page.setViewportSize(viewport);
    await page.goto(url,{waitUntil:'domcontentloaded'});
    await page.evaluate(async()=>{
      document.documentElement.classList.add('c5-visual-test');
      document.documentElement.dataset.locale='es';
      document.documentElement.lang='es';
      if(document.fonts?.ready)await document.fonts.ready;
    });
    await page.addScriptTag({content:runtime});
    const hero=page.locator('.home-phase-a-hero');
    await expect(hero).toBeVisible();
    await expect(page.locator('[data-c5-hero-cta]')).toContainText('EXPLORA BRVTAL');
    const media=page.locator('[data-c5-hero-documentary-image]');
    await expect(media).toHaveAttribute('alt','Synthetic monochrome editorial placeholder');
    await expect(page.locator('[data-c5-hero-documentary]')).toBeVisible();
    await expect(media).toHaveJSProperty('naturalWidth',1200);
    // Check that the PHP-linked CSS (not only the synthetic fixture CSS) loaded.
    const linkedStylesheets=await page.evaluate(()=>Array.from(document.styleSheets)
      .filter(sheet=>sheet.href && sheet.href.startsWith('http://127.0.0.1:4173/css/'))
      .map(sheet=>({
        path:new URL(sheet.href).pathname,
        ruleCount:sheet.cssRules.length,
      })));
    // A <link> can exist even when its CSS is an empty mocked response.
    // Require actual CSS rules from the real PHP-linked layout sheets.
    for (const required of ['/css/style.css','/css/public-concept05-home.css',
      '/css/public-concept05-hero.css']) {
      const stylesheet=linkedStylesheets.find(sheet=>sheet.path===required);
      expect(stylesheet, 'PHP-linked CSS missing: '+required).toBeDefined();
      expect(stylesheet.ruleCount, 'PHP-linked CSS empty: '+required).toBeGreaterThan(5);
    }
    const width=await page.evaluate(()=>({
      client:document.documentElement.clientWidth,
      scroll:document.documentElement.scrollWidth,
    }));
    expect(width.scroll).toBeLessThanOrEqual(width.client+1);
    const png=await page.screenshot({
      fullPage:true,animations:'disabled',caret:'hide',scale:'css',
    });
    expect(png.length).toBeGreaterThan(2000);
    await test.info().attach('concept05-php-cms-'+viewport.width+'-fullpage',{
      body:png,contentType:'image/png',
    });
    evidence.captures.push({
      viewport,sha256:createHash('sha256').update(png).digest('hex'),
      bytes:png.length,linkedStylesheets,
      documentaryMediaLoaded:true,
    });
  }
  expect(evidence.captures.map(x=>x.viewport.width)).toEqual([390,1440]);
  const owner=readFileSync(join(root,evidence.reference));
  expect(owner.length).toBeGreaterThan(2000);
  evidence.ownerReferenceSha256=createHash('sha256').update(owner).digest('hex');
  await test.info().attach('concept05-owner-reference-v2',{
    body:owner,contentType:'image/png',
  });
  await test.info().attach('concept05-php-cms-visual-review-manifest',{
    body:Buffer.from(JSON.stringify(evidence,null,2)),
    contentType:'application/json',
  });
});
