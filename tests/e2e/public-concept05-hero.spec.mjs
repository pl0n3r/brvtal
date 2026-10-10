import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
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
          <span class="mono">EVENTS / SOUND / ARTISTS / ARCHIVE</span>
          <strong data-c5-default-es="MÁS QUE FIESTAS.&#10;UNA CULTURA EN MOVIMIENTO." data-c5-default-en="MORE THAN PARTIES.&#10;A CULTURE IN MOTION." data-c5-display-locale="es" data-c5-hero-manifesto>MÁS QUE FIESTAS. UNA CULTURA EN MOVIMIENTO.</strong>
          <p data-c5-hero-description>PEREIRA / COLOMBIA · UNDERGROUND ELECTRONIC CULTURE</p>
          <a class="c5-hero-explore magnetic" href="#genesis" data-cursor="EXPLORE">EXPLORA BRVTAL <span>↘</span></a>
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

  // Regression: other themes still consume published CMS banners.
  await page.unroute('**/concept05-slider-precedence.html');
  await page.route('**/concept05-slider-precedence.html', route => route.fulfill({
    status:200,contentType:'text/html; charset=utf-8',body:htmlFor('04')
  }));
  await page.goto(uri);
  await expect(page.locator('.home-phase-a-hero')).toHaveClass(/hero-slider-active/);
  await expect(page.locator('[data-hero-slide="0"]')).toBeVisible();
  expect(apiRequests).toBe(1);
});
