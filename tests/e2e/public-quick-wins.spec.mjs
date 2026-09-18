import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const runtime = readFileSync(join(root, 'js/public-quick-wins.js'), 'utf8');
const source = readFileSync(join(root, 'index.html'), 'utf8');
const heroRuntime = readFileSync(join(root, 'js/hero-slider.js'), 'utf8');
const appRuntime = readFileSync(join(root, 'js/app.js'), 'utf8');
const homeCss = [
  'css/style.css',
  'css/archive.css',
  'css/public-media.css',
  'css/public-controls.css',
  'css/public-legibility.css',
].map(path => readFileSync(join(root, path), 'utf8')).join('\n');
const contactCss = [
  'css/contact-social.css',
  'css/public-controls.css',
  'css/public-legibility.css',
].map(path => readFileSync(join(root, path), 'utf8')).join('\n');
const entityCss = [
  'css/public-entity.css',
  'css/public-controls.css',
  'css/public-legibility.css',
].map(path => readFileSync(join(root, path), 'utf8')).join('\n');

async function mount(page) {
  await page.setContent(`<!doctype html><html><body>
    <div class="brvtal-hero-slider">
      <article class="brvtal-hero-slide active" data-hero-slide="0" aria-hidden="false">
        <a id="hero-active" href="/active">ACTIVE CTA</a>
      </article>
      <article class="brvtal-hero-slide" data-hero-slide="1" aria-hidden="true">
        <a id="hero-hidden" href="/hidden">HIDDEN CTA</a>
      </article>
    </div>

    <a id="fallback-artist" class="artist" href="#">PLACEHOLDER ARTIST</a>
    <article class="set-item" id="fallback-set">
      <div class="set-main"><span>SOUNDCLOUD</span><h4>STATIC SET</h4></div>
      <a id="fallback-set-action" href="https://soundcloud.com/" target="_blank" rel="noopener" class="set-action magnetic">↗</a>
    </article>
    <article class="set-item" id="dynamic-set">
      <div class="set-main"><span>SPOTIFY</span><h4>NIGHT SIGNAL</h4></div>
      <a id="dynamic-set-action" href="https://example.com/listen" target="_blank" rel="noopener" class="set-action magnetic">↗</a>
    </article>
  </body></html>`);
  await page.addScriptTag({content: runtime});
}

const fontSize = async locator => locator.evaluate(element => Number.parseFloat(getComputedStyle(element).fontSize));
const height = async locator => locator.evaluate(element => element.getBoundingClientRect().height);

test('hidden Hero slides are inert and become interactive only when active', async ({ page }) => {
  await mount(page);

  await expect(page.locator('[data-hero-slide="0"]')).not.toHaveAttribute('inert', '');
  await expect(page.locator('[data-hero-slide="1"]')).toHaveAttribute('inert', '');

  await page.evaluate(() => {
    const first = document.querySelector('[data-hero-slide="0"]');
    const second = document.querySelector('[data-hero-slide="1"]');
    first.setAttribute('aria-hidden', 'true');
    second.setAttribute('aria-hidden', 'false');
  });

  await expect(page.locator('[data-hero-slide="0"]')).toHaveAttribute('inert', '');
  await expect(page.locator('[data-hero-slide="1"]')).not.toHaveAttribute('inert', '');
});

test('placeholder fallback actions are not keyboard-interactive', async ({ page }) => {
  await mount(page);

  await expect(page.locator('#fallback-artist')).not.toHaveAttribute('href', /.+/);
  await expect(page.locator('#fallback-artist')).toHaveAttribute('aria-disabled', 'true');

  await expect(page.locator('#fallback-set-action')).not.toHaveAttribute('href', /.+/);
  await expect(page.locator('#fallback-set-action')).toHaveAttribute('tabindex', '-1');
  await expect(page.locator('#fallback-set-action')).toHaveAttribute('aria-hidden', 'true');
});

test('real Set external actions receive a descriptive accessible name', async ({ page }) => {
  await mount(page);
  await expect(page.locator('#dynamic-set-action')).toHaveAttribute(
    'aria-label',
    'Listen to NIGHT SIGNAL on SPOTIFY'
  );
});

test('Home exposes no public CMS status or generic section counters', async () => {
  expect(source).not.toContain('class="scene-index"');
  expect(source).not.toContain('id="dynamicStatus"');
  expect(source).not.toContain('id="apiFallback"');
  expect(source).not.toContain('01 / 07');
  expect(source).toContain('<span id="sceneName">CORE</span><b>///</b><span id="sceneCount">01</span>');
  expect(source).toContain('css/public-controls.css');
  expect(source).toContain('css/public-legibility.css');
});

test('Hero counting remains tied to the configured slider total', async () => {
  expect(heroRuntime).toContain('class="brvtal-hero-counter mono"');
  expect(heroRuntime).toContain('data.slides.length');
  expect(heroRuntime).toContain('data-hero-current');
});

/**
 * Executes the public loader/canvas runtime and returns first-frame draw calls.
 */
async function runAppVisualHarness(page) {
  await page.setViewportSize({ width: 800, height: 600 });
  await page.setContent(`<!doctype html><html><body>
    <div id="loader"><span id="loadPct">00%</span><div class="loader-progress"><i></i></div></div>
    <button id="menuToggle" type="button"><strong>+</strong></button>
    <div id="menuPanel" class="menu-panel" aria-hidden="true"></div>
    <button id="soundToggle" type="button"><b>OFF</b></button>
    <canvas id="fxCanvas"></canvas>
  </body></html>`);

  await page.evaluate(() => {
    window.matchMedia = query => ({
      matches: false,
      media: query,
      onchange: null,
      addListener() {},
      removeListener() {},
      addEventListener() {},
      removeEventListener() {},
      dispatchEvent() { return false; },
    });
    window.ScrollTrigger = {
      create() {},
      refresh() {},
      update() {},
    };
    window.gsap = {
      registerPlugin() {},
      from() {},
      fromTo() {},
      to(target, options = {}) {
        if (typeof options.onComplete === 'function') {
          setTimeout(options.onComplete, 0);
        }
        return target;
      },
      ticker: {
        add() {},
        lagSmoothing() {},
      },
    };
    window.requestAnimationFrame = () => 0;
    window.__visualDraws = [];
    HTMLCanvasElement.prototype.getContext = function () {
      return {
        fillStyle: '',
        globalAlpha: 1,
        clearRect() {},
        fillRect(x, y, width, height) {
          window.__visualDraws.push([this.fillStyle, x, y, width, height]);
        },
      };
    };
  });

  await page.addScriptTag({ content: appRuntime });
  await expect(page.locator('#loader')).toHaveCount(0, { timeout: 2000 });
  return page.evaluate(() => window.__visualDraws);
}

test('public loader completes and decorative canvas first frame is reproducible', async ({ browser }) => {
  const firstPage = await browser.newPage();
  const secondPage = await browser.newPage();
  try {
    const firstDraws = await runAppVisualHarness(firstPage);
    const secondDraws = await runAppVisualHarness(secondPage);

    expect(firstDraws).toHaveLength(111);
    expect(secondDraws).toEqual(firstDraws);
    expect(firstDraws[0][0]).toMatch(/^#(?:fff|000)$/);
    expect(firstDraws.at(-1)?.slice(1)).toEqual([0, 2, 800, 1]);
  } finally {
    await Promise.all([firstPage.close(), secondPage.close()]);
  }
});

test('Home controls and meaningful microtext stay readable on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${homeCss}</style></head><body>
    <section class="events"><div class="section-head"><span class="mono">EVENTS / 03</span><h2>EVENTS</h2><span class="mono">DRAG →</span></div><a class="event-ticket mono" href="#tickets">TICKETS ↗</a></section>
    <footer class="footer"><div class="footer-main"><span class="mono">BRVTAL / PEREIRA / COLOMBIA</span><h2>RAVE<br><em>TILL GRAVE</em></h2><a class="footer-link" href="#contact">CONTACT ↗</a></div><div class="footer-bottom mono"><span>© 2026 BRVTAL</span><span>INSTAGRAM / SOUNDCLOUD</span><span>EN</span></div></footer>
  </body></html>`);

  await expect.poll(() => height(page.locator('.event-ticket'))).toBeGreaterThanOrEqual(48);
  await expect.poll(() => fontSize(page.locator('.event-ticket'))).toBeGreaterThanOrEqual(12);
  await expect.poll(() => fontSize(page.locator('.section-head>span').first())).toBeGreaterThanOrEqual(12);
  await expect.poll(() => fontSize(page.locator('.footer-bottom'))).toBeGreaterThanOrEqual(12);
  await expect.poll(() => height(page.locator('.footer-link'))).toBeGreaterThanOrEqual(48);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('Contact keeps functional copy readable and display type bounded', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${contactCss}</style></head><body class="brvtal-contact-page">
    <header class="contact-page-nav"><a class="contact-page-brand" href="/"><strong>BRVTAL</strong><span>RAVE TILL GRAVE</span></a><a class="contact-page-home mono" href="/">HOME ↙</a></header>
    <main><section class="contact-page-hero"><div class="contact-page-eyebrow mono">DIRECT CHANNEL / BRVTAL</div><h1>CONTACT</h1><p>BOOKINGS, COLLABORATIONS, EVENTS, MEDIA OR GENERAL INQUIRIES.</p><div class="contact-page-index mono">01 / CONTACT</div></section></main>
  </body></html>`);

  await expect.poll(() => fontSize(page.locator('.contact-page-eyebrow'))).toBeGreaterThanOrEqual(11);
  await expect.poll(() => fontSize(page.locator('.contact-page-hero p'))).toBeGreaterThanOrEqual(12);
  await expect.poll(() => height(page.locator('.contact-page-home'))).toBeGreaterThanOrEqual(48);
  await expect.poll(() => page.locator('.contact-page-hero h1').evaluate(el => el.getBoundingClientRect().width)).toBeLessThanOrEqual(390);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('Canonical entity actions use the shared public control system', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${entityCss}</style></head><body>
    <main><article class="entity-hero"><div class="entity-copy"><div class="entity-kicker">BRVTAL / EVENT</div><h1>GENESIS EXPERIENCE</h1><div class="entity-actions"><a href="#tickets">TICKETS ↗</a><a href="#network">EXPLORE CONNECTIONS ↗</a></div></div></article></main>
  </body></html>`);

  await expect.poll(() => height(page.locator('.entity-actions a').first())).toBeGreaterThanOrEqual(48);
  await expect.poll(() => fontSize(page.locator('.entity-actions a').first())).toBeGreaterThanOrEqual(12);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
