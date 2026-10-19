import { test, expect } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const css = [
  'css/style.css',
  'css/public-home-phase-a.css',
  'css/public-concept05-tokens.css',
  'css/public-concept05-home.css',
  'css/public-concept05-experience.css',
].map(path => readFileSync(join(root, path), 'utf8')).join('\n');
const runtime = readFileSync(join(root, 'js/public-concept05-experience.js'), 'utf8');

function fixture({
  title = 'GENESIS',
  note = 'BRVTAL × RANDOM KORE',
  artwork = 'https://example.test/genesis.jpg',
} = {}) {
  const image = artwork
    ? `<img src="${artwork}" alt="${title} event artwork" loading="lazy" decoding="async"><span class="c5-experience-artwork-label mono">BRVTAL / NEXT EXPERIENCE</span>`
    : '';

  return `<!doctype html>
<html class="c5-visual-test">
<head>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>${css}</style>
</head>
<body data-concept="05">
  <main>
    <section class="genesis scene home-phase-a-experience c5-experience-authored c5-numbered"
      id="genesis" data-scene="EXPERIENCE" data-c5-experience>
      <div class="genesis-bg c5-experience-artwork" data-c5-experience-artwork style="background-image:none">${image}</div>
      <div class="genesis-noise"></div>
      <div class="genesis-scanline"></div>
      <div class="genesis-copy">
        <div class="eyebrow mono">NEXT EXPERIENCE / BRVTAL</div>
        <h2 data-text="${title}">${title}</h2>
        <p class="genesis-tag">LAST TICKETS.</p>
        <div class="genesis-data mono">
          <span data-c5-fact="date" data-label="DATE">14.08.2026</span>
          <span data-c5-fact="time" data-label="TIME">21:00</span>
          <span data-c5-fact="location" data-label="LOCATION">PEREIRA / LA PERLA</span>
        </div>
        <div class="c5-experience-ticket-signal" aria-label="Ticket status">
          <div class="c5-experience-ticket" data-ticket-status="active"><span class="mono">PREVENTA</span><strong>20.000 COP</strong></div>
          <div class="c5-experience-ticket" data-ticket-status="active"><span class="mono">DOOR</span><strong>25.000 COP</strong></div>
        </div>
        <p class="c5-experience-note">${note}</p>
        <div class="experience-lineup"><span class="mono">LINEUP</span><p>HAKKI <i>/</i> CRIXXES'T <i>/</i> THEMIIME <i>/</i> DNL5 <i>/</i> SANTY KORE <i>/</i> PL0N3R</p></div>
        <div class="experience-actions">
          <a class="enter magnetic" href="/events/genesis">ENTER EXPERIENCE <span>↗</span></a>
          <a class="ticket-cta magnetic" href="https://tickets.example.test/genesis">TICKETS <span>↗</span></a>
        </div>
      </div>
      <div class="genesis-eyes"></div>
      <div class="genesis-orbit"></div>
    </section>
  </main>
  <nav class="c5-bottom-nav" aria-label="Primary mobile">
    <a href="#events">NIGHTS</a><a href="#artists">ARTISTS</a><a href="#sets">SOUND</a><a href="/releases">RECORDS</a><a href="#transmissions">JOURNAL</a>
  </nav>
</body>
</html>`;
}

async function mount(page, viewport, options = {}) {
  await page.setViewportSize(viewport);
  if (options.artwork !== null) {
    await page.route('https://example.test/genesis.jpg', route => route.fulfill({
      status: options.mediaStatus ?? 200,
      contentType: 'image/svg+xml',
      body: options.mediaStatus === 404
        ? 'missing'
        : '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="1200"><rect width="900" height="1200" fill="black"/></svg>',
    }));
  }
  await page.setContent(fixture(options));
  await page.addScriptTag({ content: runtime });
}

test('Concept 05 Next Experience authors a framed 1440 event takeover from canonical content', async ({ page }) => {
  await mount(page, { width:1440, height:900 });

  await expect(page.locator('[data-c5-experience-artwork] img')).toBeVisible();
  await expect(page.locator('.c5-experience-ticket').first()).toContainText('20.000 COP');
  await expect(page.locator('.experience-lineup')).toContainText('PL0N3R');
  await expect(page.locator('.ticket-cta')).toHaveAttribute('href', 'https://tickets.example.test/genesis');

  const geometry = await page.evaluate(() => {
    const rect = selector => document.querySelector(selector).getBoundingClientRect();
    const section = rect('[data-c5-experience]');
    const art = rect('[data-c5-experience-artwork]');
    const copy = rect('.genesis-copy');
    const title = rect('.genesis-copy h2');
    const actions = rect('.experience-actions');
    return {
      sectionLeft:section.left,
      sectionRight:section.right,
      sectionBottom:section.bottom,
      artLeft:art.left,
      artRight:art.right,
      artTop:art.top,
      copyLeft:copy.left,
      copyRight:copy.right,
      copyTop:copy.top,
      titleRight:title.right,
      titleFont:parseFloat(getComputedStyle(document.querySelector('.genesis-copy h2')).fontSize),
      actionsBottom:actions.bottom,
      scrollWidth:document.documentElement.scrollWidth,
      viewport:document.documentElement.clientWidth,
    };
  });

  expect(geometry.artLeft).toBeGreaterThanOrEqual(geometry.sectionLeft + 20);
  expect(geometry.copyLeft).toBeGreaterThanOrEqual(geometry.artLeft + 300);
  expect(geometry.artRight).toBeGreaterThan(geometry.copyLeft - 2);
  expect(geometry.copyRight).toBeLessThanOrEqual(geometry.sectionRight - 20);
  expect(geometry.copyTop).toBeGreaterThan(geometry.artTop);
  expect(geometry.titleRight).toBeLessThanOrEqual(geometry.copyRight);
  expect(geometry.titleFont).toBeGreaterThanOrEqual(70);
  expect(geometry.actionsBottom).toBeLessThanOrEqual(geometry.sectionBottom);
  expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.viewport);
});

test('Concept 05 Next Experience is separately authored at 390 with artwork, facts and actions in flow', async ({ page }) => {
  await mount(page, { width:390, height:844 });

  const geometry = await page.evaluate(() => {
    const rect = selector => document.querySelector(selector).getBoundingClientRect();
    const section = rect('[data-c5-experience]');
    const art = rect('[data-c5-experience-artwork]');
    const copy = rect('.genesis-copy');
    const title = rect('.genesis-copy h2');
    const ticket = rect('.ticket-cta');
    const nav = rect('.c5-bottom-nav');
    return {
      sectionLeft:section.left,
      sectionRight:section.right,
      sectionBottom:section.bottom,
      artLeft:art.left,
      artRight:art.right,
      artBottom:art.bottom,
      copyLeft:copy.left,
      copyRight:copy.right,
      copyTop:copy.top,
      titleRight:title.right,
      ticketLeft:ticket.left,
      ticketRight:ticket.right,
      ticketHeight:ticket.height,
      navTop:nav.top,
      scrollWidth:document.documentElement.scrollWidth,
      viewport:document.documentElement.clientWidth,
    };
  });

  expect(geometry.artLeft).toBeGreaterThanOrEqual(geometry.sectionLeft);
  expect(geometry.artRight).toBeLessThanOrEqual(geometry.sectionRight);
  expect(geometry.copyTop).toBeLessThan(geometry.artBottom);
  expect(geometry.copyLeft).toBeGreaterThanOrEqual(geometry.sectionLeft);
  expect(geometry.copyRight).toBeLessThanOrEqual(geometry.sectionRight);
  expect(geometry.titleRight).toBeLessThanOrEqual(geometry.copyRight);
  expect(geometry.ticketLeft).toBeGreaterThanOrEqual(geometry.copyLeft);
  expect(geometry.ticketRight).toBeLessThanOrEqual(geometry.copyRight);
  expect(geometry.ticketHeight).toBeGreaterThanOrEqual(48);
  expect(geometry.sectionBottom).toBeGreaterThan(geometry.navTop);
  expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.viewport);
});

test('long canonical Event copy at enlarged text remains contained without horizontal scrolling', async ({ page }) => {
  await mount(page, { width:390, height:844 }, {
    title: 'AN EXTREMELY LONG UNDERGROUND EXPERIENCE',
    note: 'A deliberately long administrable event description that must remain readable when people enlarge text instead of colliding with ticketing, lineup or navigation.',
  });
  await page.evaluate(() => {
    document.documentElement.style.fontSize = '20px';
  });

  const geometry = await page.evaluate(() => {
    const section = document.querySelector('[data-c5-experience]').getBoundingClientRect();
    const copy = document.querySelector('.genesis-copy').getBoundingClientRect();
    const title = document.querySelector('.genesis-copy h2').getBoundingClientRect();
    const actions = document.querySelector('.experience-actions').getBoundingClientRect();
    return {
      sectionBottom:section.bottom,
      copyRight:copy.right,
      titleRight:title.right,
      actionsBottom:actions.bottom,
      scrollWidth:document.documentElement.scrollWidth,
      viewport:document.documentElement.clientWidth,
    };
  });

  expect(geometry.titleRight).toBeLessThanOrEqual(geometry.copyRight);
  expect(geometry.actionsBottom).toBeLessThanOrEqual(geometry.sectionBottom);
  expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.viewport);
});

test('offscreen native lazy artwork loads after entering the viewport', async ({ page }) => {
  let requested = 0;
  // Keep the image genuinely pending until the viewport transition. Native
  // lazy-loading heuristics may prefetch a distant image before this assertion.
  let releaseArtworkResponse;
  const artworkResponseReady = new Promise(resolve => {
    releaseArtworkResponse = resolve;
  });
  await page.setViewportSize({ width:390, height:844 });
  await page.route('https://example.test/lazy-artwork.jpg', async route => {
    requested += 1;
    await artworkResponseReady;
    return route.fulfill({
      status:200, contentType:'image/svg+xml',
      body:'<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800"></svg>',
    });
  });
  await page.setContent(`<!doctype html><html><head>
    <style>img[hidden]{display:none!important}</style></head><body>
    <div style="height:3600px"></div>
    <div data-c5-experience-artwork style="width:250px;height:250px">
      <img loading="lazy" src="https://example.test/lazy-artwork.jpg"
        width="250" height="250" alt="Event artwork">
    </div></body></html>`, { waitUntil:'domcontentloaded' });
  const artwork = page.locator('[data-c5-experience-artwork] img');
  try {
    await page.addScriptTag({ content:runtime });
    await expect(artwork).toBeHidden();
    expect(await artwork.evaluate(image => image.hidden)).toBe(false);
    // Assert observable concealment rather than a browser-specific inline style.
    // Native lazy loading may keep style.visibility unset while the image is hidden.

    await page.evaluate(() => window.scrollTo(0, 3400));
    // A pending response must remain visually hidden even when the artwork
    // enters the viewport; only a successful image load can reveal it.
    // Confirm the request has actually started before checking the pending state.
    // This catches a regression that reveals the image as soon as loading begins.
    await expect.poll(() => requested, { timeout:10000 }).toBeGreaterThan(0);
    await expect(artwork).toBeHidden();
    releaseArtworkResponse();
    await expect(artwork).toBeVisible({ timeout:10000 });
    await expect(artwork).toHaveJSProperty('naturalWidth', 600);
    await expect(artwork).toHaveAttribute('src', 'https://example.test/lazy-artwork.jpg');
    await expect(page.locator('[data-c5-experience-artwork]')).not.toHaveClass(/is-media-missing/);
  } finally {
    // Avoid a dangling route when a browser assertion fails.
    releaseArtworkResponse();
  }
});

test('managed experience artwork fails closed instead of exposing a broken image', async ({ page }) => {
  await mount(page, { width:390, height:844 }, { mediaStatus:404 });

  const artwork = page.locator('[data-c5-experience-artwork]');
  await expect(artwork).toHaveClass(/is-media-missing/);
  await expect(artwork.locator('img')).toBeHidden();
  await expect(artwork.locator('img')).not.toHaveAttribute('src');
});

test('reused CMS artwork host rebinds replacement images and ignores detached events', async ({ page }) => {
  await page.route('https://example.test/new-artwork.jpg', route => route.fulfill({
    status: 200,
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="1200"></svg>',
  }));
  await page.route('https://example.test/missing-artwork.jpg', route => route.fulfill({
    status: 404, body: 'missing',
  }));
  await mount(page, { width:390, height:844 });
  const host = page.locator('[data-c5-experience-artwork]');
  await expect(host.locator('img')).toBeVisible();

  const immediate = await page.evaluate(() => {
    const node = document.querySelector('[data-c5-experience-artwork]');
    const old = node.querySelector('img');
    const fresh = document.createElement('img');
    fresh.src = 'https://example.test/new-artwork.jpg';
    fresh.alt = 'Updated artwork';
    node.replaceChildren(fresh);
    window.BRVTAL_CONCEPT05_EXPERIENCE_INIT();
    old.dispatchEvent(new Event('error')); // A detached load failure is irrelevant.
    return { newSrc: fresh.getAttribute('src'), hidden: fresh.hidden };
  });
  expect(immediate.newSrc).toBe('https://example.test/new-artwork.jpg');
  // Image may already have loaded; in either case its original source survives.
  await expect(host.locator('img')).toBeVisible();
  await expect(host).not.toHaveClass(/is-media-missing/);

  await page.evaluate(() => {
    const node = document.querySelector('[data-c5-experience-artwork]');
    const old = node.querySelector('img');
    const broken = document.createElement('img');
    broken.src = 'https://example.test/missing-artwork.jpg';
    node.replaceChildren(broken);
    window.BRVTAL_CONCEPT05_EXPERIENCE_INIT();
    old.dispatchEvent(new Event('error'));
  });
  await expect(host).toHaveClass(/is-media-missing/);
  await expect(host.locator('img')).toBeHidden();
  await expect(host.locator('img')).not.toHaveAttribute('src');

  await page.evaluate(() => {
    const node = document.querySelector('[data-c5-experience-artwork]');
    const repaired = document.createElement('img');
    repaired.src = 'https://example.test/new-artwork.jpg';
    node.replaceChildren(repaired);
    window.BRVTAL_CONCEPT05_EXPERIENCE_INIT();
  });
  await expect(host.locator('img')).toBeVisible();
  await expect(host).not.toHaveClass(/is-media-missing/);
});

test('same CMS artwork image recovers after success, failure and source reassignment', async ({ page }) => {
  await page.route('https://example.test/new-artwork.jpg', route => route.fulfill({
    status:200, contentType:'image/svg+xml',
    body:'<svg xmlns="http://www.w3.org/2000/svg" width="900" height="1200"></svg>',
  }));
  await page.route('https://example.test/missing-artwork.jpg', route => route.fulfill({
    status:404, body:'missing',
  }));
  await mount(page, {width:390,height:844});
  const host = page.locator('[data-c5-experience-artwork]');
  const artwork = host.locator('img');
  await expect(artwork).toBeVisible();
  await expect(artwork).toHaveJSProperty('complete', true);

  await artwork.evaluate(image => {
    image.src = 'https://example.test/missing-artwork.jpg';
    window.BRVTAL_CONCEPT05_EXPERIENCE_INIT();
  });
  await expect(host).toHaveClass(/is-media-missing/);
  await expect(artwork).toBeHidden();
  await expect(artwork).not.toHaveAttribute('src');

  // Keep the exact same <img> DOM node rather than replacing the child.
  await artwork.evaluate(image => {
    image.src = 'https://example.test/new-artwork.jpg';
    window.BRVTAL_CONCEPT05_EXPERIENCE_INIT();
  });
  await expect(artwork).toBeVisible();
  await expect(artwork).toHaveAttribute('src', 'https://example.test/new-artwork.jpg');
  await expect(host).not.toHaveClass(/is-media-missing/);
});

test('same CMS artwork URL can recover after its one-shot load and error', async ({ page }) => {
  await mount(page, { width:390, height:844 });
  const host = page.locator('[data-c5-experience-artwork]');
  const artwork = host.locator('img');
  await expect(artwork).toBeVisible();
  await expect(artwork).toHaveJSProperty('naturalWidth', 900);
  // Consume the original once:true load handler even if the browser loaded
  // the image before this runtime was injected. Without this explicit event,
  // the old implementation could pass falsely through its stale load listener.
  await artwork.evaluate(image => image.dispatchEvent(new Event('load')));

  // A later request for the *same* CMS URL can fail after the first load.
  // Fire the real error listener deterministically, without external media.
  await artwork.evaluate(image => image.dispatchEvent(new Event('error')));
  await expect(host).toHaveClass(/is-media-missing/);
  await expect(artwork).toBeHidden();
  await expect(artwork).not.toHaveAttribute('src');

  // The retry uses the same DOM image and the same original URL. The previous
  // once:true listener has already fired and must not suppress the new bind.
  await artwork.evaluate(image => {
    image.setAttribute('src', 'https://example.test/genesis.jpg');
    window.BRVTAL_CONCEPT05_EXPERIENCE_INIT();
  });
  await expect(artwork).toBeVisible();
  await expect(host).not.toHaveClass(/is-media-missing/);
  await expect(artwork).toHaveAttribute('src', 'https://example.test/genesis.jpg');

  // Another failure should still close the artwork safely after this retry.
  await artwork.evaluate(image => image.dispatchEvent(new Event('error')));
  await expect(host).toHaveClass(/is-media-missing/);
  await expect(artwork).not.toHaveAttribute('src');
});

test('experience runtime does not claim GSAP motion ownership in deterministic visual-test mode', async ({ page }) => {
  await page.setViewportSize({ width:390, height:844 });
  await page.route('https://example.test/genesis.jpg', route => route.fulfill({
    status:200,
    contentType:'image/svg+xml',
    body:'<svg xmlns="http://www.w3.org/2000/svg" width="900" height="1200"></svg>',
  }));
  await page.setContent(fixture());
  await page.evaluate(() => {
    window.__experienceGsapCalls = 0;
    window.gsap = { from: () => { window.__experienceGsapCalls += 1; } };
  });
  await page.addScriptTag({ content:runtime });

  expect(await page.evaluate(() => window.__experienceGsapCalls)).toBe(0);
  await expect(page.locator('[data-c5-experience-artwork] img')).toBeVisible();
});


/**
 * #970 AC-03: browser-produced evidence at the owner's two viewport targets.
 * These images are attached to CI for the human owner-v2 A/B comparison.
 * A passing geometry check does not automatically approve visual parity.
 */
test('Next Experience attaches owner-review captures at 390 and 1440', async ({ page }, info) => {
  for (const viewport of [{ width:390, height:844 }, { width:1440, height:900 }]) {
    await mount(page, viewport);
    const section = page.locator('[data-c5-experience]');
    await expect(section).toBeVisible();
    await expect(section.locator('.genesis-copy h2')).toHaveText('GENESIS');
    await expect(section.locator('[data-c5-fact="date"]')).toHaveText('14.08.2026');
    await expect(section.locator('.experience-lineup')).toContainText('PL0N3R');
    await expect(section.locator('.ticket-cta')).toHaveAttribute(
      'href', 'https://tickets.example.test/genesis'
    );
    const geometry = await page.evaluate(() => {
      const node = document.querySelector('[data-c5-experience]');
      const rect = node.getBoundingClientRect();
      return {
        width:document.documentElement.clientWidth,
        scrollWidth:document.documentElement.scrollWidth,
        sectionHeight:rect.height,
      };
    });
    expect(geometry.width).toBe(viewport.width);
    expect(geometry.scrollWidth).toBeLessThanOrEqual(viewport.width);
    expect(geometry.sectionHeight).toBeGreaterThan(400);
    const image = await page.screenshot({
      fullPage:true, animations:'disabled', caret:'hide',
    });
    await info.attach('next-experience-' + viewport.width + '-fullpage', {
      body:image, contentType:'image/png',
    });
  }
});


/**
 * #970 — mount the actual PHP Home template rather than a hand-written fixture.
 * Synthetic editorial records only: no production CMS or network mutation.
 * Full-page PNGs still require visual comparison against the owner reference.
 */
function canonicalPhpHome({ published = true } = {}) {
  const event = published
    ? "['id'=>44,'title'=>'UNDERGROUND SIGNAL','slug'=>'underground-signal'," +
      "'status'=>'tickets_available','event_date'=>'2026-12-12 21:00:00'," +
      "'city'=>'Pereira','venue'=>'Sala Central','description'=>'LIVE CULTURE'," +
      "'cover_image'=>'','public_ticket_url'=>'https://tickets.example.test/signal'," +
      "'ticket_types'=>[" +
        "['name'=>'PREVENTA','status'=>'active','price'=>22000,'currency'=>'COP']," +
        "['name'=>'DOOR','status'=>'active','price'=>30000,'currency'=>'COP']]," +
      "'lineup'=>[['name'=>'DJ SIGNAL'],['name'=>'DJ NIGHTS']]]"
    : 'null';
  const phpCode = 'require "config/public_home.php"; ' +
    '$event = ' + event + '; ' +
    'echo brvtal_public_render_next_experience(file_get_contents("index.html"), $event);';
  const run = spawnSync('php', ['-r', phpCode], {
    cwd:root, encoding:'utf8', timeout:15000, maxBuffer:3_000_000,
  });
  if (run.error || run.status !== 0) {
    throw new Error('Canonical PHP renderer failed: ' +
      String(run.error?.message || run.stderr || run.status).slice(0,4000));
  }
  // Browser sandbox disables scripts and all remote requests; never regex-filter HTML.
  return run.stdout.replace('</head>',
    '<style>' + css + '\n#loader,.cursor,.cursor-label,#fxCanvas{display:none!important}' + '</style></head>');
}

test('PHP-rendered canonical Next Experience keeps published facts and captures 390/1440', async ({ page: originalPage }, info) => {
  const browser = originalPage.context().browser();
  if (!browser) throw new Error('Browser context is required for inert HTML tests');
  const inertContext = await browser.newContext({ javaScriptEnabled:false });
  const page = await inertContext.newPage();
  // Block remote CSS, images, scripts and redirects from the raw PHP document.
  await page.route('**/*', route => route.abort());
  try {
  for (const viewport of [{width:390,height:844},{width:1440,height:900}]) {
    await page.setViewportSize(viewport);
    await page.setContent(canonicalPhpHome());
    const section = page.locator('[data-c5-experience]');
    await expect(section).toBeVisible();
    await expect(section.locator('h2')).toHaveText('UNDERGROUND SIGNAL');
    await expect(section.locator('[data-c5-fact="date"]')).toHaveText('12.12.2026');
    await expect(section.locator('[data-c5-fact="location"]')).toContainText('PEREIRA');
    await expect(section.locator('.experience-lineup')).toContainText('DJ SIGNAL');
    await expect(section.locator('.c5-experience-ticket')).toHaveCount(2);
    await expect(section.locator('.c5-experience-ticket').first()).toContainText('22.000 COP');
    await expect(section.locator('.c5-experience-ticket').last()).toContainText('30.000 COP');
    await expect(section.locator('.ticket-cta')).toHaveAttribute(
      'href', 'https://tickets.example.test/signal'
    );
    const rects = await section.evaluate(node => {
      const outer = node.getBoundingClientRect();
      const actions = node.querySelector('.experience-actions').getBoundingClientRect();
      return {left:outer.left,right:outer.right,bottom:outer.bottom,
        actionsBottom:actions.bottom,scroll:node.scrollWidth,client:node.clientWidth};
    });
    expect(rects.left).toBeGreaterThanOrEqual(-1);
    expect(rects.right).toBeLessThanOrEqual(viewport.width+1);
    expect(rects.actionsBottom).toBeLessThanOrEqual(rects.bottom+2);
    expect(rects.scroll).toBeLessThanOrEqual(rects.client+2);
    const image = await page.screenshot({fullPage:true,animations:'disabled',caret:'hide'});
    await info.attach('canonical-php-next-experience-'+viewport.width, {
      body:image,contentType:'image/png',
    });
    const sectionImage = await section.screenshot({animations:'disabled',caret:'hide'});
    await info.attach('canonical-php-next-experience-'+viewport.width+'-section', {
      body:sectionImage,contentType:'image/png',
    });
  }
  await page.setViewportSize({width:390,height:844});
  await page.setContent(canonicalPhpHome({published:false}));
  const section = page.locator('[data-c5-experience]');
  await expect(section.locator('h2')).toHaveText('NEXT SIGNAL');
  await expect(section.locator('.ticket-cta')).toHaveCount(0);
  await expect(section.locator('.c5-experience-ticket')).toHaveCount(0);
  await expect(section.locator('.c5-experience-artwork img')).toHaveCount(0);
  await expect(section.locator('[data-c5-fact="date"]')).toHaveText('DATE TBA');
  } finally { await inertContext.close(); }
});
