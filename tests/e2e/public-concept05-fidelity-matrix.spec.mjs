import { test, expect } from '@playwright/test';
import { createHash } from 'node:crypto';
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
    <div class="nav-right">
      <fieldset class="language-selector">
        <legend>Idioma / Language</legend>
        <button type="button" class="language-option magnetic" data-locale="es" aria-pressed="true" data-cursor="ES">ES</button>
        <span class="language-separator" aria-hidden="true">/</span>
        <button type="button" class="language-option magnetic" data-locale="en" aria-pressed="false" data-cursor="EN" disabled>EN</button>
      </fieldset>
      <button class="menu magnetic" type="button">MENU +</button>
    </div>
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

/** Mount the deterministic integrated Concept 05 fixture at one target viewport. */
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
      const titles = [...document.querySelectorAll('[data-fidelity-title]')].filter(visible).map(el => {
        const rect = el.getBoundingClientRect();
        const owner = el.closest('.genesis-copy,.event-card,.c5-artist-card,.set-main,.transmission-copy,.c5-footer-brand') || el.parentElement;
        const ownerRect = owner.getBoundingClientRect();
        return {
          text:el.textContent.trim(),
          left:rect.left,
          right:rect.right,
          width:rect.width,
          ownerLeft:ownerRect.left,
          ownerRight:ownerRect.right,
        };
      });
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
      expect(title.width, `title collapsed: ${title.text}`).toBeGreaterThan(0);
      expect(title.left, `title starts outside its owner: ${title.text}`).toBeGreaterThanOrEqual(title.ownerLeft - 1);
      expect(title.right, `title escapes its owner: ${title.text}`).toBeLessThanOrEqual(title.ownerRight + 1);
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


for (const viewport of [
  { width:390, height:844 },
  { width:1024, height:900 },
  { width:1440, height:900 },
]) {
  test(`Concept 05 CONNECTED tagline renders every line inside its graph at ${viewport.width}px`, async ({ page }) => {
    await mount(page, viewport);
    const bounds = await page.evaluate(() => {
      const tagline = document.querySelector('#connected .c5-connected-tagline');
      const graph = document.querySelector('#connected .c5-connected-graph');
      const range = document.createRange();
      range.selectNodeContents(tagline);
      const owner = graph.getBoundingClientRect();
      const ink = [...range.getClientRects()].map(rect => ({
        left:rect.left, right:rect.right, top:rect.top, bottom:rect.bottom,
      }));
      return {
        owner:{ left:owner.left, right:owner.right, top:owner.top, bottom:owner.bottom },
        paddingBottom:Number.parseFloat(getComputedStyle(graph).paddingBottom),
        ink,
      };
    });
    if (viewport.width <= 900) {
      expect(bounds.paddingBottom, 'CONNECTED mobile graph must preserve bottom ink clearance').toBeGreaterThanOrEqual(20);
    }
    expect(bounds.ink.length).toBeGreaterThan(0);
    for (const line of bounds.ink) {
      expect(line.left, 'CONNECTED text starts outside graph').toBeGreaterThanOrEqual(bounds.owner.left - 1);
      expect(line.right, 'CONNECTED text clips at graph right edge').toBeLessThanOrEqual(bounds.owner.right + 1);
      expect(line.top, 'CONNECTED text starts above graph').toBeGreaterThanOrEqual(bounds.owner.top - 1);
      expect(line.bottom, 'CONNECTED text clips at graph bottom edge').toBeLessThanOrEqual(bounds.owner.bottom + 1);
    }
  });
}


// Verify *painted* glyph ink in addition to Range text-fragment bounds.
// A clipped/unclipped raster difference reveals ink hidden by overflow:hidden,
// even when document.scrollWidth and Range.getClientRects() both pass.
async function connectedPaintDiff(page) {
  await page.locator('#connected .c5-connected-tagline').scrollIntoViewIfNeeded();
  await page.evaluate(() => {
    const graph = document.querySelector('#connected .c5-connected-graph');
    for (const child of graph.children) {
      child.style.visibility = child.classList.contains('c5-connected-tagline') ? 'visible' : 'hidden';
    }
  });
  const clip = await page.evaluate(() => {
    const graph = document.querySelector('#connected .c5-connected-graph').getBoundingClientRect();
    const section = document.querySelector('#connected').getBoundingClientRect();
    const text = document.querySelector('#connected .c5-connected-tagline').getBoundingClientRect();
    const x = Math.ceil(graph.right);
    const y = Math.max(0, Math.floor(text.top));
    return {
      x,
      y,
      width: Math.max(0, Math.min(20, Math.floor(Math.min(section.right, window.innerWidth)) - x)),
      height: Math.max(0, Math.ceil(Math.min(window.innerHeight, text.bottom)) - y),
    };
  });
  expect(clip.width, 'insufficient right-side raster sampling area').toBeGreaterThanOrEqual(4);
  expect(clip.height, 'tagline must be visible for screenshot sampling').toBeGreaterThan(0);
  const clipped = await page.screenshot({ clip, animations:'disabled' });
  await page.evaluate(() => {
    document.querySelector('#connected .c5-connected-graph').style.overflow = 'visible';
  });
  const revealed = await page.screenshot({ clip, animations:'disabled' });
  await page.evaluate(() => {
    document.querySelector('#connected .c5-connected-graph').style.removeProperty('overflow');
  });
  return page.evaluate(async ({ clipped, revealed }) => {
    const raster = async base64 => {
      const image = new Image();
      image.src = 'data:image/png;base64,' + base64;
      await image.decode();
      const canvas = document.createElement('canvas');
      canvas.width = image.naturalWidth;
      canvas.height = image.naturalHeight;
      const context = canvas.getContext('2d', { willReadFrequently:true });
      context.drawImage(image, 0, 0);
      return context.getImageData(0, 0, canvas.width, canvas.height).data;
    };
    const a = await raster(clipped);
    const b = await raster(revealed);
    let changed = 0;
    for (let i = 0; i < a.length; i += 4) {
      if (Math.abs(a[i] - b[i]) + Math.abs(a[i + 1] - b[i + 1]) + Math.abs(a[i + 2] - b[i + 2]) > 40) {
        changed += 1;
      }
    }
    return changed;
  }, { clipped:clipped.toString('base64'), revealed:revealed.toString('base64') });
}

for (const viewport of [
  { width:390, height:844 },
  { width:1024, height:900 },
  { width:1440, height:900 },
]) {
  test(`Concept 05 CONNECTED painted-ink boundary differential at ${viewport.width}px`, async ({ page }) => {
    await mount(page, viewport);
    expect(await connectedPaintDiff(page), 'normal tagline loses painted glyphs at graph edge').toBe(0);
    if (viewport.width === 1440) {
      // Positive control: the comparison must detect a real intentionally clipped line.
      await page.evaluate(() => {
        const tagline = document.querySelector('#connected .c5-connected-tagline');
        tagline.textContent = 'TODOCONECTADO'.repeat(12);
        tagline.style.whiteSpace = 'nowrap';
        tagline.style.overflowWrap = 'normal';
      });
      expect(await connectedPaintDiff(page), 'raster comparison fails to detect forced clipping').toBeGreaterThan(3);
    }
  });
}

test('Concept 05 canonical 390 and 1440 preserve distinct authored compositions', async ({ page }) => {
  await mount(page, { width:390, height:844 });
  const mobile = await page.evaluate(() => {
    const experienceStyle = getComputedStyle(document.querySelector('.c5-experience-authored'));
    return {
      heroTitle:Number.parseFloat(getComputedStyle(document.querySelector('.hero-title')).fontSize),
      experienceDisplay:experienceStyle.display,
      experienceDirection:experienceStyle.flexDirection,
      footerColumns:getComputedStyle(document.querySelector('.c5-footer-grid')).gridTemplateColumns.split(' ').length,
      headerNav:getComputedStyle(document.querySelector('.c5-header-nav')).display,
      experienceTitleWidth:document.querySelector('#genesis h2').getBoundingClientRect().width,
    };
  });

  expect(mobile.heroTitle).toBeGreaterThanOrEqual(86);
  expect(mobile.heroTitle).toBeLessThanOrEqual(126);
  expect(mobile.experienceDisplay).toBe('flex');
  expect(mobile.experienceDirection).toBe('column');
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
  expect(await page.evaluate(() => matchMedia('(prefers-reduced-motion: reduce)').matches)).toBe(true);
  await page.evaluate(() => {
    document.documentElement.classList.remove('c5-visual-test');
    document.documentElement.removeAttribute('data-theme-motion');
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

test('Concept 05 motion foundation skips nonessential GSAP on coarse pointers', async ({ page }) => {
  await page.emulateMedia({ reducedMotion:'no-preference' });
  await mount(page, { width:430, height:900 });
  await page.evaluate(() => {
    document.documentElement.classList.remove('c5-visual-test');
    document.documentElement.removeAttribute('data-theme-motion');
    const nativeMatchMedia = window.matchMedia.bind(window);
    window.matchMedia = query => query === '(pointer: coarse)'
      ? {
          matches:true,
          media:query,
          onchange:null,
          addListener:()=>{},
          removeListener:()=>{},
          addEventListener:()=>{},
          removeEventListener:()=>{},
          dispatchEvent:()=>false,
        }
      : nativeMatchMedia(query);
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
  expect(await page.evaluate(() => matchMedia('(pointer: coarse)').matches)).toBe(true);
  await page.addScriptTag({ content:motion });
  await page.evaluate(() => window.BRVTAL_CONCEPT05_MOTION_INIT());
  expect(await page.evaluate(() => window.__c5MotionCalls)).toBe(0);
});

test('Concept 05 motion foundation still animates fine pointers when motion is allowed', async ({ page }) => {
  await page.emulateMedia({ reducedMotion:'no-preference' });
  await mount(page, { width:1440, height:900 });
  await page.evaluate(() => {
    document.documentElement.classList.remove('c5-visual-test');
    document.documentElement.removeAttribute('data-theme-motion');
    window.__c5MotionCalls = 0;
    window.ScrollTrigger = {};
    window.gsap = {
      registerPlugin:() => { window.__c5MotionCalls += 1; },
      from:() => { window.__c5MotionCalls += 1; },
      timeline:() => {
        window.__c5MotionCalls += 1;
        return {
          fromTo:() => { window.__c5MotionCalls += 1; },
        };
      },
    };
  });
  expect(await page.evaluate(() => matchMedia('(prefers-reduced-motion: reduce)').matches)).toBe(false);
  expect(await page.evaluate(() => matchMedia('(pointer: coarse)').matches)).toBe(false);
  await page.addScriptTag({ content:motion });
  expect(await page.evaluate(() => window.__c5MotionCalls)).toBeGreaterThan(0);
});


/**
 * #976: a full-page capture must not depend on ScrollTrigger firing for
 * every below-the-fold section. Simulate the paused initial GSAP state.
 * Screenshots are CI artifacts, not an automated owner-reference approval.
 */
for (const viewport of [{ width:390, height:844 }, { width:1440, height:900 }]) {
  test(`Concept 05 full-page pre-scroll content remains visible at ${viewport.width}px`, async ({ page }, info) => {
    await page.emulateMedia({ reducedMotion:'no-preference' });
    await mount(page, viewport);
    await page.evaluate(() => {
      document.documentElement.classList.remove('c5-visual-test');
      const module = document.createElement('div');
      module.className = 'c5-module';
      module.textContent = 'VISIBLE EVEN WITHOUT SCROLLTRIGGER';
      document.querySelector('#events').appendChild(module);
      window.__c5PreScrollTweens = [];
      window.ScrollTrigger = {};
      window.gsap = {
        registerPlugin:() => {},
        from:(element, opts) => {
          window.__c5PreScrollTweens.push(Object.keys(opts));
          // Model GSAP's from-state before any ScrollTrigger callback fires.
          if ('opacity' in opts) element.style.opacity = String(opts.opacity);
          if ('clipPath' in opts) element.style.clipPath = opts.clipPath;
        },
        timeline:() => ({ fromTo:() => {} }),
      };
    });
    await page.addScriptTag({ content:motion });
    const beforeScroll = await page.evaluate(() => {
      const selectors = ['#genesis','#events','#artists','#sets','#media','#transmissions','#connected','.c5-module'];
      return {
        scrollY:window.scrollY,
        tweens:window.__c5PreScrollTweens,
        regions:selectors.map(selector => {
          const el=document.querySelector(selector);
          const css=getComputedStyle(el), rect=el.getBoundingClientRect();
          return {
            selector, display:css.display, visibility:css.visibility,
            opacity:Number(css.opacity), clip:css.clipPath,
            height:rect.height,
          };
        }),
      };
    });
    expect(beforeScroll.scrollY).toBe(0);
    expect(beforeScroll.tweens.length).toBeGreaterThanOrEqual(8);
    for (const keys of beforeScroll.tweens) {
      expect(keys, 'deferred GSAP tween must not hide content').not.toContain('opacity');
      expect(keys, 'deferred GSAP tween must not clip a section').not.toContain('clipPath');
    }
    for (const region of beforeScroll.regions) {
      expect(region.display, region.selector).not.toBe('none');
      expect(region.visibility, region.selector).toBe('visible');
      expect(region.opacity, region.selector).toBeGreaterThan(0.99);
      expect(region.clip, region.selector).not.toContain('100%');
      expect(region.height, region.selector).toBeGreaterThan(0);
    }
    const png = await page.screenshot({ fullPage:true, animations:'disabled', caret:'hide' });
    await info.attach(`concept05-no-gaps-${viewport.width}-fullpage`, {
      body:png, contentType:'image/png',
    });
  });
}

const canonicalVisualRegions = [
  '.home-phase-a-hero',
  '#genesis',
  '#events',
  '#artists',
  '#sets',
  '#media',
  '#transmissions',
  '#connected',
  '.c5-footer',
];

/*
 * These are fingerprints of the synthetic long-copy/media-empty stress
 * fixture, NOT proof of visual parity with owner Concept 05 v2 or real CMS.
 * Manual refresh only: inspect the exact-HEAD Chromium screenshots and
 * document the reason and source hashes under docs/reference/ before editing.
 * Keep structural and palette assertions fail-closed for future drift.
 * Never auto-update these values in CI.
 * See docs/reference/concept05-v2-qa-baseline-2026-10-10.md.
 */
const canonicalScreenshotBaselines = {
  390: {
    structure: '0e22155ed8bedcd6a2ce5b995d95cddf5125c15d4c0a5481e4af0e58ae4a8ea9',
    color: 'b0757d4cc5ea9209533351994b8c437ce8ccbdcc7c2f1d8763e820d42dc810e1',
  },
  1440: {
    structure: '0ca474d4581303cf1e8c8f7bdd826ead1eb4754d7347ecf85b8b40d07646317f',
    color: '133a12fe27127708da420145fd60877727bdddd4ecacd895330fb9a44ed7792f',
  },
};

/**
 * Reduce one real PNG screenshot to low-noise structural and palette signals.
 * The structural dHash tolerates antialiasing while retaining composition;
 * the coarse RGB grid protects the Concept 05 black/paper/red/green hierarchy.
 */
async function screenshotSignature(page, png) {
  return page.evaluate(async base64 => {
    const image = new Image();
    image.src = `data:image/png;base64,${base64}`;
    await image.decode();

    const structureCanvas = document.createElement('canvas');
    structureCanvas.width = 17;
    structureCanvas.height = 16;
    const structureContext = structureCanvas.getContext('2d', { willReadFrequently:true });
    structureContext.drawImage(image, 0, 0, 17, 16);
    const structurePixels = structureContext.getImageData(0, 0, 17, 16).data;

    const luminance = offset =>
      (structurePixels[offset] * 299 + structurePixels[offset + 1] * 587 + structurePixels[offset + 2] * 114) / 1000;

    let bits = '';
    for (let y = 0; y < 16; y += 1) {
      for (let x = 0; x < 16; x += 1) {
        const left = (y * 17 + x) * 4;
        const right = left + 4;
        bits += luminance(left) > luminance(right) ? '1' : '0';
      }
    }
    let structure = '';
    for (let index = 0; index < bits.length; index += 4) {
      structure += Number.parseInt(bits.slice(index, index + 4), 2).toString(16);
    }

    const colorCanvas = document.createElement('canvas');
    colorCanvas.width = 12;
    colorCanvas.height = 12;
    const colorContext = colorCanvas.getContext('2d', { willReadFrequently:true });
    colorContext.drawImage(image, 0, 0, 12, 12);
    const colorPixels = colorContext.getImageData(0, 0, 12, 12).data;
    const colorGrid = [];
    for (let offset = 0; offset < colorPixels.length; offset += 4) {
      const r = Math.min(3, Math.floor(colorPixels[offset] / 64));
      const g = Math.min(3, Math.floor(colorPixels[offset + 1] / 64));
      const b = Math.min(3, Math.floor(colorPixels[offset + 2] / 64));
      colorGrid.push((r << 4) | (g << 2) | b);
    }

    return {
      structure,
      color: btoa(String.fromCharCode(...colorGrid)),
    };
  }, png.toString('base64'));
}

/**
 * Capture all canonical Concept 05 modules and aggregate perceptual screenshot
 * signatures into compact baselines committed in this spec.
 */
async function captureCanonicalVisualFingerprint(page) {
  const regions = [];
  for (const selector of canonicalVisualRegions) {
    const locator = page.locator(selector).first();
    await expect(locator).toBeVisible();
    const png = await locator.screenshot({
      animations:'disabled',
      caret:'hide',
      scale:'css',
    });
    regions.push({ selector, ...(await screenshotSignature(page, png)) });
  }

  return {
    structure:createHash('sha256')
      .update(regions.map(region => `${region.selector}:${region.structure}`).join('|'))
      .digest('hex'),
    color:createHash('sha256')
      .update(regions.map(region => `${region.selector}:${region.color}`).join('|'))
      .digest('hex'),
    regions,
  };
}

for (const viewport of [{ width:390, height:844 }, { width:1440, height:900 }]) {
  test(`Concept 05 canonical ${viewport.width}px screenshot fingerprint stays approved`, async ({ page }) => {
    await mount(page, viewport);
    const actual = await captureCanonicalVisualFingerprint(page);
    const expected = canonicalScreenshotBaselines[viewport.width];

    if (expected.structure === 'PENDING_CALIBRATION') {
      console.log(`CONCEPT05_SCREENSHOT_BASELINE_${viewport.width}=${JSON.stringify(actual)}`);
    }

    // Preserve visual evidence of this public synthetic fixture for review.
    // The assertion below remains fail-closed until the baseline is approved.
    const fingerprintMismatch =
      actual.structure !== expected.structure || actual.color !== expected.color;

    // Keep the failing assertion: capture both affected viewports and the
    // approved owner source in Playwright test-results for a real A/B review.
    if (viewport.width === 390 && fingerprintMismatch) {
      const screenshotPath = test.info().outputPath('concept05-390-fullpage.png');
      await page.screenshot({path:screenshotPath, fullPage:true, animations:'disabled', caret:'hide'});
      await test.info().attach('concept05-390-fullpage', {
        path:screenshotPath,
        contentType:'image/png',
      });
    }
    if (viewport.width === 1440 && fingerprintMismatch) {
      const screenshotPath = test.info().outputPath('concept05-1440-fullpage.png');
      await page.screenshot({path:screenshotPath, fullPage:true, animations:'disabled', caret:'hide'});
      await test.info().attach('concept05-1440-fullpage', {
        path:screenshotPath,
        contentType:'image/png',
      });
    }
    if (fingerprintMismatch) {
      await test.info().attach('concept05-owner-reference-v2', {
        path:'docs/reference/home-concept05-owner-reference-v2.png',
        contentType:'image/png',
      });
    }

    expect(
      { structure:actual.structure, color:actual.color },
      'Intentional Concept 05 visual changes require explicit baseline refresh after review.'
    ).toEqual(expected);
  });
}

test('Concept 05 owner v2 pairs blocks 02 through 07 on desktop and stacks them on mobile', async ({ page }) => {
  await mount(page, { width:1440, height:900 });
  const desktop = await page.evaluate(() => {
    const box = id => document.getElementById(id).getBoundingClientRect();
    const events=box('events'), artists=box('artists'), sets=box('sets'), media=box('media');
    const journal=box('transmissions'), connected=box('connected');
    return {
      nightsArtists:Math.abs(events.top-artists.top),
      soundMemories:Math.abs(sets.top-media.top),
      journalConnected:Math.abs(journal.top-connected.top),
      noOverflow:document.documentElement.scrollWidth <= document.documentElement.clientWidth,
    };
  });
  expect(desktop.nightsArtists).toBeLessThan(80);
  expect(desktop.soundMemories).toBeLessThan(80);
  expect(desktop.journalConnected).toBeLessThan(80);
  expect(desktop.noOverflow).toBe(true);

  await mount(page, { width:390, height:844 });
  const mobile = await page.evaluate(() => {
    const ids=['events','artists','sets','media','transmissions','connected'];
    const tops=ids.map(id => document.getElementById(id).getBoundingClientRect().top);
    return {
      ordered:tops.every((value,index) => index === 0 || value > tops[index-1]),
      noOverflow:document.documentElement.scrollWidth <= document.documentElement.clientWidth,
    };
  });
  expect(mobile.ordered).toBe(true);
  expect(mobile.noOverflow).toBe(true);
});
