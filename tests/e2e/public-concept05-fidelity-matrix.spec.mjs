import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const cssFiles = [
  'css/style.css',
  'css/input-accessibility.css',
  'css/public-controls.css',
  'css/public-media.css',
  'css/public-memories.css',
  'css/public-roster.css',
  'css/public-sets-library.css',
  'css/public-transmissions.css',
  'css/public-concept05-tokens.css',
  'css/public-concept05-home.css',
  'css/public-concept05-hero.css',
  'css/public-concept05-experience.css',
  'css/public-concept05-nights-artists.css',
  'css/public-concept05-sound-memories.css',
  'css/public-concept05-journal-connected.css',
  'css/public-concept05-shell.css',
];
const css = cssFiles.map(file => readFileSync(join(root, file), 'utf8')).join('\n');
const motion = readFileSync(join(root, 'js/public-concept05-motion.js'), 'utf8');

const fixture = `<!doctype html>
<html class="c5-visual-test">
<head>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    *{box-sizing:border-box}
    html,body{margin:0;width:100%;background:#050505;color:#e8e6df}
    img{display:block;max-width:100%}
    ${css}
  </style>
</head>
<body data-concept="05">
  <header class="nav" data-fidelity-block>
    <a class="brand" href="#top"><span>BRVTAL</span><small>RAVE TILL GRAVE</small></a>
    <nav class="c5-header-nav" aria-label="Primary">
      <a href="#events">NIGHTS</a><a href="#artists">ARTISTS</a><a href="#sets">SOUND</a>
      <a href="/releases">RECORDS</a><a href="#transmissions">JOURNAL</a><a href="#connected">CONNECTED</a>
    </nav>
    <span class="c5-header-origin mono">PEREIRA / COLOMBIA</span>
    <a class="c5-header-ticket" href="#genesis">TICKETS <span>↗</span></a>
    <div class="nav-right"><button class="sound" type="button">SOUND</button><button class="menu" type="button">MENU +</button></div>
  </header>

  <main id="top">
    <section class="hero scene home-phase-a-hero" data-fidelity-block>
      <div class="hero-grid"></div><div class="hero-scan"></div><div class="hero-glitch-lines"></div>
      <div class="hero-copy">
        <div class="eyebrow mono">UNDERGROUND ELECTRONIC CULTURE / PEREIRA / COLOMBIA</div>
        <h1 class="hero-title" data-text="BRVTAL">BRVTAL</h1>
        <div class="hero-sub"><span>RAVE TILL GRAVE</span><span>EST. 2026</span></div>
        <div class="hero-declaration">
          <span class="mono">EVENTS / SOUND / ARTISTS / ARCHIVE</span>
          <strong>UNA CULTURA EN MOVIMIENTO.</strong>
          <p>Independent electronic culture from Pereira, Colombia.</p>
          <a class="c5-hero-explore" href="#genesis">EXPLORA BRVTAL <span>↘</span></a>
        </div>
      </div>
      <figure class="c5-hero-documentary" hidden><figcaption><span>DOCUMENT</span><span>BRVTAL ARCHIVE</span></figcaption></figure>
      <div class="hero-logo-wrap"></div>
      <div class="hero-bottom mono"><span>SCROLL TO ENTER</span><span>NOISE / SIGNAL / MUSIC</span></div>
    </section>

    <section class="genesis scene c5-experience-authored c5-numbered" id="genesis" data-fidelity-block>
      <div class="genesis-bg c5-experience-artwork is-media-missing"><span class="c5-experience-artwork-label mono">BRVTAL / NEXT EXPERIENCE</span></div>
      <div class="genesis-noise"></div><div class="genesis-scanline"></div>
      <div class="genesis-copy">
        <div class="eyebrow mono">NEXT EXPERIENCE / BRVTAL</div>
        <h2 data-fidelity-title>PSYCHOTIC INDUSTRIAL CEREMONY WITH A VERY LONG NAME</h2>
        <p class="genesis-tag">LAST TICKETS.</p>
        <div class="genesis-data mono">
          <span data-label="DATE">14.08.2026</span><span data-label="TIME">21:00</span>
          <span data-label="LOCATION">PEREIRA / LA PERLA / INDUSTRIAL FLOOR</span>
        </div>
        <div class="c5-experience-ticket-signal">
          <div class="c5-experience-ticket"><span class="mono">PREVENTA</span><strong>20.000 COP</strong></div>
          <div class="c5-experience-ticket"><span class="mono">DOOR</span><strong>25.000 COP</strong></div>
        </div>
        <p class="c5-experience-note">One night. Nine sounds. No decorative data pretending to be editorial truth.</p>
        <div class="experience-lineup"><span class="mono">LINEUP</span><p>HAKKI / CRIXXES'T / THEMIIME / DNL5 / SANTY KORE / PL0N3R</p></div>
        <div class="experience-actions"><a class="enter" href="/events/genesis">ENTER EXPERIENCE <span>↗</span></a><a class="ticket-cta" href="#tickets">TICKETS ↗</a></div>
      </div>
      <div class="genesis-eyes"></div><div class="genesis-orbit"></div>
    </section>

    <section class="events scene c5-numbered" id="events" data-fidelity-block>
      <div class="section-head"><span class="mono">BRVTAL NIGHTS / 02</span><h2>NIGHTS</h2><span class="mono">DRAG / SWIPE →</span></div>
      <div class="events-track">
        <article class="event-card c5-night-card" data-c5-lifecycle="active">
          <div class="event-img is-media-missing"></div>
          <div class="event-info"><span class="mono">14.08.2026 / PEREIRA</span><h3 data-fidelity-title>VERY LONG NIGHT TITLE THAT MUST WRAP WITHOUT ESCAPING THE CARD</h3><p>WAREHOUSE / PEREIRA</p><span class="event-status">ACTIVE</span><div class="c5-night-actions"><a class="event-record mono" href="/events/night">VIEW RECORD ↗</a></div></div>
        </article>
        <article class="event-card c5-night-card"><div class="event-img is-media-missing"></div><div class="event-info"><span class="mono">07.08.2026 / PEREIRA</span><h3>SESSION 05</h3><p>BRVTAL</p><span class="event-status">ARCHIVE</span></div></article>
        <article class="event-card c5-night-card"><div class="event-img is-media-missing"></div><div class="event-info"><span class="mono">01.08.2026 / PEREIRA</span><h3>AFTERIMAGE</h3><p>BRVTAL</p><span class="event-status">ARCHIVE</span></div></article>
        <article class="event-card c5-night-card"><div class="event-img is-media-missing"></div><div class="event-info"><span class="mono">01.07.2026 / PEREIRA</span><h3>NO SIGNAL</h3><p>BRVTAL</p><span class="event-status">ARCHIVE</span></div></article>
      </div>
      <a class="c5-section-route" href="/events">VER TODAS LAS NOCHES →</a>
    </section>

    <section class="artists scene c5-numbered" id="artists" data-fidelity-block>
      <div class="section-head"><span class="mono">BRVTAL ARTISTS / 03</span><h2>ARTISTS</h2><span class="mono">CORE / ALUMNI / COLLABORATORS</span></div>
      <div class="artist-list">
        <section class="roster-group roster-group--active"><div class="roster-group-head mono"><span>CORE / ACTIVE</span><b>02</b></div>
          <a class="artist c5-artist-card" href="/artists/pl0n3r"><span class="c5-artist-index">01</span><strong>PL0N3R</strong><i>BRVTAL / ACTIVE</i></a>
          <a class="artist c5-artist-card" href="/artists/long-name"><span class="c5-artist-index">02</span><strong data-fidelity-title>VERY LONG ARTIST NAME THAT MUST WRAP WITHOUT OVERFLOW</strong><i>ARTIST / COLLABORATOR</i></a>
        </section>
      </div>
      <a class="c5-section-route" href="/artists">VER ARTISTAS →</a>
    </section>

    <section class="sets scene c5-numbered" id="sets" data-fidelity-block>
      <div class="section-head"><span class="mono">BRVTAL SOUND / 04</span><h2>SOUND</h2><span class="mono">SETS / LISTEN / ARCHIVE</span></div>
      <div class="sets-intro"><p class="mono">SOUND IS NOT BACKGROUND.</p><h3>IT IS THE<br><em>EXPERIENCE.</em></h3></div>
      <div class="set-list">
        <article class="set-library-item c5-sound-record c5-sound-feature">
          <span class="set-num mono">01</span><div class="set-library-cover is-media-missing"></div>
          <div class="set-main"><a class="set-record-link" href="/sets/signal"><h4 data-fidelity-title>INDUSTRIAL CLOSING TRANSMISSION WITH A LONG TITLE</h4></a><div class="set-library-relations"><a href="/artists/pl0n3r">PL0N3R</a> / <a href="/events/genesis">GENESIS</a></div><div class="c5-sound-signal"></div></div>
          <a class="set-listen-action" href="https://example.test/sound">LISTEN ↗</a>
        </article>
      </div>
    </section>

    <section class="media scene c5-numbered" id="media" data-fidelity-block>
      <div class="media-title"><span class="mono">BRVTAL MEMORIES / 05</span><h2>MEMORIES</h2><div class="media-title-note"><strong>THE NIGHT REMAINS.</strong><p>A curated record.</p></div></div>
      <div class="public-media-tools"><label><span class="mono">SEARCH MEDIA</span><input type="search" value=""></label><div class="public-media-types"><button type="button">ALL</button><button type="button">IMAGES</button><button type="button">VIDEO</button></div></div>
      <div class="media-grid public-media-grid">
        <button class="m c5-memory-cell public-media-open" type="button"><span class="c5-memory-annotation">01 / WAREHOUSE PRESSURE</span></button>
        <button class="m c5-memory-cell public-media-open" type="button"><span class="c5-memory-annotation">02 / RED AFTERIMAGE</span></button>
        <button class="m c5-memory-cell public-media-open" type="button"><span class="c5-memory-annotation">03 / ROOM TONE</span></button>
      </div>
    </section>

    <section class="scene transmissions c5-numbered" id="transmissions" data-fidelity-block>
      <div class="section-head"><span class="mono">BRVTAL JOURNAL / 06</span><h2>JOURNAL</h2><span class="mono">SIGNAL ARCHIVE</span></div>
      <div class="transmissions-intro"><h3>FIELD<br>NOTES.</h3><p>Dispatches from BRVTAL culture and the rooms that keep the signal alive.</p></div>
      <div class="transmissions-list">
        <article class="transmission-card transmission-feature"><div class="transmission-cover is-media-missing"></div><div class="transmission-copy"><a class="transmission-link" href="/blog/night"><h3 data-fidelity-title>THE NIGHT DOES NOT END WHEN THE LIGHTS TURN ON</h3></a><p>Editorial field note.</p></div><a class="transmission-open" href="/blog/night">READ ↗</a></article>
        <article class="transmission-card transmission-indexed"><span class="mono">02</span><div class="transmission-copy"><a class="transmission-link" href="/blog/signal"><h3>FIELD SIGNAL</h3></a></div><span class="transmission-meta mono">2026</span></article>
      </div>
      <a class="transmissions-route mono" href="/blog">OPEN JOURNAL →</a>
    </section>

    <section class="connected scene c5-numbered" id="connected" data-fidelity-block>
      <div class="c5-connected-graph">
        <div class="c5-connected-kicker mono">PUBLIC RELATIONAL SYSTEM / VERIFIED EDGES ONLY</div>
        <ul class="c5-connected-nodes">
          <li><a href="#events"><span class="mono">EVENTS</span><strong>08</strong></a></li>
          <li><a href="#artists"><span class="mono">ARTISTS</span><strong>06</strong></a></li>
          <li><a href="#sets"><span class="mono">SOUND</span><strong>05</strong></a></li>
          <li><a href="/releases"><span class="mono">RECORDS</span><strong>03</strong></a></li>
          <li><a href="#media"><span class="mono">MEMORIES</span><strong>21</strong></a></li>
        </ul>
        <div class="c5-connected-ledger"></div><p class="c5-connected-edges mono">EVENTS ↔ ARTISTS ↔ SOUND ↔ MEMORIES</p><p class="c5-connected-tagline">TODO CONECTADO.</p>
      </div>
    </section>
  </main>

  <footer class="c5-footer" data-fidelity-block>
    <div class="c5-footer-grid">
      <div class="c5-footer-brand"><span class="mono">PEREIRA / COLOMBIA</span><h2 data-fidelity-title>BRVTAL</h2><p>UNDERGROUND ELECTRONIC CULTURE / RAVE TILL GRAVE</p></div>
      <nav class="c5-footer-nav"><a href="#events">NIGHTS</a><a href="#artists">ARTISTS</a><a href="#sets">SOUND</a><a href="/releases">RECORDS</a><a href="#transmissions">JOURNAL</a><a href="#connected">CONNECTED</a></nav>
      <div class="c5-footer-contact"><span class="mono">CONTACT</span><a href="mailto:contact@example.test">CONTACT ↗</a></div>
      <div class="c5-footer-socials"><a href="https://example.test">INSTAGRAM ↗</a><a href="https://example.test">SOUNDCLOUD ↗</a></div>
      <div class="c5-footer-legal"><span>© 2026 BRVTAL</span><a href="/privacy-policy">PRIVACY</a><span>CO / 05</span></div>
    </div>
  </footer>

  <nav class="c5-bottom-nav" aria-label="Primary mobile">
    <a href="#events"><span class="c5-bottom-icon" data-icon="nights"></span>NIGHTS</a>
    <a href="#artists"><span class="c5-bottom-icon" data-icon="artists"></span>ARTISTS</a>
    <a href="#sets"><span class="c5-bottom-icon" data-icon="sound"></span>SOUND</a>
    <a href="/releases"><span class="c5-bottom-icon" data-icon="records"></span>RECORDS</a>
    <a href="#transmissions"><span class="c5-bottom-icon" data-icon="journal"></span>JOURNAL</a>
  </nav>
</body>
</html>`;

const matrix = [
  { width:390, height:844 },
  { width:430, height:900 },
  { width:768, height:1024 },
  { width:1024, height:900 },
  { width:1280, height:900 },
  { width:1440, height:900 },
  { width:1728, height:1000 },
  { width:1920, height:1080 },
];

async function mount(page, viewport) {
  await page.setViewportSize(viewport);
  await page.setContent(fixture, { waitUntil:'domcontentloaded' });
}

for (const viewport of matrix) {
  test(`Concept 05 integrated composition stays contained at ${viewport.width}px`, async ({ page }) => {
    await mount(page, viewport);

    const geometry = await page.evaluate(() => {
      const visible = el => {
        const style = getComputedStyle(el);
        const rect = el.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
      };
      const blocks = [...document.querySelectorAll('[data-fidelity-block]')].filter(visible).map(el => {
        const rect = el.getBoundingClientRect();
        return { tag:el.tagName, className:el.className, left:rect.left, right:rect.right, width:rect.width };
      });
      const titles = [...document.querySelectorAll('[data-fidelity-title]')].filter(visible).map(el => ({
        text:el.textContent.trim(),
        clientWidth:el.clientWidth,
        scrollWidth:el.scrollWidth,
      }));
      const headerChildren = [...document.querySelector('.nav').children].filter(visible).map(el => {
        const rect = el.getBoundingClientRect();
        return { className:el.className, left:rect.left, right:rect.right };
      }).sort((a,b) => a.left - b.left);
      const bottom = document.querySelector('.c5-bottom-nav');
      const bottomStyle = getComputedStyle(bottom);
      return {
        viewport:document.documentElement.clientWidth,
        scrollWidth:document.documentElement.scrollWidth,
        blocks,
        titles,
        headerChildren,
        bottomDisplay:bottomStyle.display,
        bottomHeights:[...bottom.querySelectorAll('a')].map(el => el.getBoundingClientRect().height),
      };
    });

    expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.viewport + 1);
    for (const block of geometry.blocks) {
      expect(block.left, `${block.className} starts outside viewport`).toBeGreaterThanOrEqual(-1);
      expect(block.right, `${block.className} escapes viewport`).toBeLessThanOrEqual(viewport.width + 1);
      expect(block.width).toBeGreaterThan(0);
    }
    for (const title of geometry.titles) {
      expect(title.scrollWidth, `title overflows: ${title.text}`).toBeLessThanOrEqual(title.clientWidth + 2);
    }
    for (let index = 1; index < geometry.headerChildren.length; index += 1) {
      expect(
        geometry.headerChildren[index].left,
        `header collision between ${geometry.headerChildren[index - 1].className} and ${geometry.headerChildren[index].className}`
      ).toBeGreaterThanOrEqual(geometry.headerChildren[index - 1].right - 1);
    }

    if (viewport.width <= 900) {
      expect(geometry.bottomDisplay).toBe('flex');
      geometry.bottomHeights.forEach(height => expect(height).toBeGreaterThanOrEqual(44));
    } else {
      expect(geometry.bottomDisplay).toBe('none');
    }
  });
}

test('Concept 05 canonical 390 and 1440 preserve distinct authored compositions', async ({ page }) => {
  await mount(page, { width:390, height:844 });
  const mobile = await page.evaluate(() => ({
    heroTitle:Number.parseFloat(getComputedStyle(document.querySelector('.hero-title')).fontSize),
    experienceColumns:getComputedStyle(document.querySelector('.c5-experience-authored')).gridTemplateColumns.split(' ').length,
    footerColumns:getComputedStyle(document.querySelector('.c5-footer-grid')).gridTemplateColumns.split(' ').length,
    headerNav:getComputedStyle(document.querySelector('.c5-header-nav')).display,
    experienceTitleWidth:document.querySelector('#genesis h2').getBoundingClientRect().width,
  }));

  expect(mobile.heroTitle).toBeGreaterThanOrEqual(86);
  expect(mobile.heroTitle).toBeLessThanOrEqual(126);
  expect(mobile.experienceColumns).toBe(1);
  expect(mobile.footerColumns).toBe(1);
  expect(mobile.headerNav).toBe('none');
  expect(mobile.experienceTitleWidth).toBeLessThanOrEqual(390 - 24);

  await page.setViewportSize({ width:1440, height:900 });
  const desktop = await page.evaluate(() => ({
    heroTitle:Number.parseFloat(getComputedStyle(document.querySelector('.hero-title')).fontSize),
    experienceColumns:getComputedStyle(document.querySelector('.c5-experience-authored')).gridTemplateColumns.split(' ').length,
    footerColumns:getComputedStyle(document.querySelector('.c5-footer-grid')).gridTemplateColumns.split(' ').length,
    headerNav:getComputedStyle(document.querySelector('.c5-header-nav')).display,
    heroBackground:getComputedStyle(document.querySelector('.home-phase-a-hero')).backgroundImage,
  }));

  expect(desktop.heroTitle).toBeGreaterThan(200);
  expect(desktop.experienceColumns).toBe(2);
  expect(desktop.footerColumns).toBe(12);
  expect(desktop.headerNav).toBe('flex');
  expect(desktop.heroBackground).toContain('linear-gradient');
});

test('Concept 05 critical actions remain touch-safe and keyboard focus remains visible', async ({ page }) => {
  await mount(page, { width:430, height:900 });

  const heights = await page.locator(
    '.nav-right button,.experience-actions a,.c5-section-route,.transmissions-route,.c5-bottom-nav a'
  ).evaluateAll(elements => elements.filter(el => getComputedStyle(el).display !== 'none').map(el => ({
    label:el.textContent.trim(),
    height:el.getBoundingClientRect().height,
  })));
  for (const target of heights) {
    expect(target.height, `touch target too small: ${target.label}`).toBeGreaterThanOrEqual(44);
  }

  await page.keyboard.press('Tab');
  const focus = await page.evaluate(() => {
    const el = document.activeElement;
    const style = getComputedStyle(el);
    return {
      tag:el.tagName,
      outlineStyle:style.outlineStyle,
      outlineWidth:style.outlineWidth,
      width:el.getBoundingClientRect().width,
      height:el.getBoundingClientRect().height,
    };
  });
  expect(focus.tag).toBe('A');
  expect(focus.outlineStyle).toBe('solid');
  expect(Number.parseFloat(focus.outlineWidth)).toBeGreaterThanOrEqual(2);
  expect(focus.width).toBeGreaterThan(0);
  expect(focus.height).toBeGreaterThan(0);
});

test('Concept 05 integrated reduced-motion mode suppresses GSAP without removing content', async ({ page }) => {
  await page.emulateMedia({ reducedMotion:'reduce' });
  await mount(page, { width:1440, height:900 });
  await page.evaluate(() => {
    window.__c5MotionCalls = 0;
    window.ScrollTrigger = {};
    window.gsap = {
      registerPlugin:() => { window.__c5MotionCalls += 1; },
      from:() => { window.__c5MotionCalls += 1; },
      timeline:() => {
        window.__c5MotionCalls += 1;
        return { fromTo:() => {} };
      },
    };
  });
  await page.addScriptTag({ content:motion });
  await page.evaluate(() => window.BRVTAL_CONCEPT05_MOTION_INIT());

  expect(await page.evaluate(() => window.__c5MotionCalls)).toBe(0);
  await expect(page.locator('#genesis h2')).toBeVisible();
  await expect(page.locator('#events .event-card').first()).toBeVisible();
  await expect(page.locator('#transmissions .transmission-card').first()).toBeVisible();

  const transitions = await page.evaluate(() => ({
    ticket:getComputedStyle(document.querySelector('.c5-header-ticket span')).transitionDuration,
    bottom:getComputedStyle(document.querySelector('.c5-bottom-nav')).transitionDuration,
  }));
  expect(transitions.ticket).toMatch(/^0s/);
  expect(transitions.bottom).toMatch(/^0s/);
});
