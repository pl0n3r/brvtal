import { test, expect } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { extname, join, resolve, sep } from 'node:path';

const root = process.cwd();
const runtime = readFileSync(join(root, 'js/public-concept05-shell.js'), 'utf8');
const appJs = readFileSync(join(root, 'js/app.js'), 'utf8');
const css = [
  'css/public-concept05-tokens.css',
  'css/public-concept05-home.css',
  'css/public-concept05-shell.css',
].map(path => readFileSync(join(root, path), 'utf8')).join('\n');
const url = 'http://127.0.0.1:4173/concept05-shell.html';

function markup(pages = [{slug:'privacy-policy'}], ticket = true, social = {}) {
  const payload = JSON.stringify({pages,settings:{social}}).replace(/</g, '\\u003c');
  const ticketMarkup = ticket
    ? '<a class="c5-header-ticket magnetic" href="https://tickets.example/night" '
      + 'target="_blank" rel="noopener">TICKETS <span>→</span></a>'
    : '';
  return '<!doctype html><html data-concept="05"><head>'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<style>*{box-sizing:border-box}html,body{margin:0;background:#050505;color:#e8e6df}' + css + '</style></head>'
    + '<body data-concept="05"><header class="nav">'
    + '<a class="brand" href="#top"><span>BRVTAL</span><small>RAVE TILL GRAVE</small></a>'
    + '<nav class="c5-header-nav" aria-label="Primary">'
    + '<a href="#events" data-c5-nav-section="events">NIGHTS</a>'
    + '<a href="#artists" data-c5-nav-section="artists">ARTISTS</a>'
    + '<a href="#sets" data-c5-nav-section="sets">SOUND</a>'
    + '<a href="/releases" data-c5-nav-section="releases">RECORDS</a>'
    + '<a href="#transmissions" data-c5-nav-section="transmissions">JOURNAL</a>'
    + '<a href="#connected" data-c5-nav-section="connected">CONNECTED</a></nav>'
    + '<span class="c5-header-origin mono">PEREIRA / COLOMBIA</span>'
    + '<div class="nav-center mono"><span>CORE</span><b>///</b><span>01</span></div>'
    + ticketMarkup
    + '<div class="nav-right"><button class="sound">SOUND <b>OFF</b></button>'
    + '<button class="menu" id="menuToggle">MENU <strong>+</strong></button></div></header>'
    + '<aside class="menu-panel" id="menuPanel" aria-hidden="true"></aside>'
    + '<main id="top"><section id="events" style="height:700px">EVENTS</section>'
    + '<section id="artists" style="height:700px">ARTISTS</section>'
    + '<section id="sets" style="height:700px">SETS</section>'
    + '<section id="media" style="height:700px">MEDIA</section>'
    + '<section id="transmissions" style="height:700px">JOURNAL</section>'
    + '<section id="connected" style="height:700px">CONNECTED</section>'
    + '<footer class="footer scene c5-footer" id="site-footer"><div class="c5-footer-grid">'
    + '<div class="c5-footer-brand"><span class="mono">PEREIRA / COLOMBIA</span>'
    + '<h2 data-site-name>BRVTAL</h2><p><span data-site-tagline>RAVE TILL GRAVE</span></p></div>'
    + '<nav class="c5-footer-nav mono"><a href="#events">NIGHTS</a><a href="#artists">ARTISTS</a>'
    + '<a href="#sets">SOUND</a><a href="/releases">RECORDS</a>'
    + '<a href="#transmissions">JOURNAL</a><a href="#connected">CONNECTED</a></nav>'
    + '<div class="c5-footer-contact"><span class="mono">TRANSMIT</span>'
    + '<a href="/contact">CONTACT ↗</a><a href="/contact">COLLABORATE ↗</a></div>'
    + '<div class="c5-footer-socials mono">'
    + '<a href="#" data-social="instagram" hidden>INSTAGRAM</a>'
    + '<a href="#" data-social="soundcloud" hidden>SOUNDCLOUD</a>'
    + '<a href="#" data-social="youtube" hidden>YOUTUBE</a>'
    + '<a href="#" data-social="spotify" hidden>SPOTIFY</a>'
    + '<a href="#" data-social="website" hidden>WEBSITE</a></div>'
    + '<div class="c5-footer-legal mono"><span>© <span data-footer-year>2026</span> BRVTAL</span>'
    + '<a data-footer-privacy hidden>PRIVACY ↗</a><span>EN</span></div></div></footer></main>'
    + '<nav class="c5-bottom-nav" aria-label="Primary mobile">'
    + '<a href="#events" data-c5-nav-section="events">'
    + '<span class="c5-bottom-icon" data-icon="nights"></span><span>NIGHTS</span></a>'
    + '<a href="#artists" data-c5-nav-section="artists">'
    + '<span class="c5-bottom-icon" data-icon="artists"></span><span>ARTISTS</span></a>'
    + '<a href="#sets" data-c5-nav-section="sets">'
    + '<span class="c5-bottom-icon" data-icon="sound"></span><span>SOUND</span></a>'
    + '<a href="/releases" data-c5-nav-section="releases">'
    + '<span class="c5-bottom-icon" data-icon="records"></span><span>RECORDS</span></a>'
    + '<a href="#transmissions" data-c5-nav-section="transmissions">'
    + '<span class="c5-bottom-icon" data-icon="journal"></span><span>JOURNAL</span></a>'
    + '</nav><script>window.__reads=0;window.BRVTALPublicDataPromise=Promise.resolve({payload:{data:'
    + payload + '}}).then(value=>{window.__reads+=1;return value;});</script></body></html>';
}

async function openShell(page, pages = [], viewport = {width:390,height:844}, ticket = true, social = {}) {
  await page.setViewportSize(viewport);
  await page.route(url, route => route.fulfill({contentType:'text/html; charset=utf-8',body:markup(pages,ticket,social)}));
  await page.goto(url);
  await page.addScriptTag({content:runtime});
  await page.waitForFunction(() => document.documentElement.dataset.publicShell === 'concept05');
}

test('mobile shell exposes five touch-safe icon destinations and tracks hash state', async ({page}) => {
  await openShell(page, [{slug:'privacy-policy'}]);
  await expect(page.locator('.c5-bottom-nav a')).toHaveCount(5);
  await expect(page.locator('.c5-bottom-icon')).toHaveCount(5);
  for (const link of await page.locator('.c5-bottom-nav a').all()) {
    const box = await link.boundingBox();
    expect(box?.height || 0).toBeGreaterThanOrEqual(44);
  }
  await page.evaluate(() => {
    location.hash = '#artists';
    window.dispatchEvent(new HashChangeEvent('hashchange'));
  });
  await expect(page.locator('.c5-bottom-nav [data-c5-nav-section="artists"]'))
    .toHaveAttribute('aria-current','location');
  await expect(page.locator('.c5-header-nav [data-c5-nav-section="artists"]'))
    .toHaveAttribute('aria-current','location');
  const noOverflow = await page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth
  );
  expect(noOverflow).toBe(true);
});

test('menu lock makes persistent mobile navigation non-interactive and restores it', async ({page}) => {
  await openShell(page);
  const nav = page.locator('.c5-bottom-nav');
  await page.evaluate(() => document.documentElement.classList.add('menu-scroll-locked'));
  await expect(nav).toHaveAttribute('aria-hidden','true');
  await expect(nav).toHaveAttribute('data-menu-state','hidden');
  expect(await nav.evaluate(node => node.inert)).toBe(true);
  await page.evaluate(() => document.documentElement.classList.remove('menu-scroll-locked'));
  await expect(nav).toHaveAttribute('aria-hidden','false');
  await expect(nav).toHaveAttribute('data-menu-state','visible');
  expect(await nav.evaluate(node => node.inert)).toBe(false);
});

test('footer reveals only a real public privacy page from the shared payload', async ({page}) => {
  await openShell(page, [{slug:'privacy-policy'}], {width:1440,height:900});
  const privacy = page.locator('[data-footer-privacy]');
  await expect(privacy).toBeVisible();
  await expect(privacy).toHaveAttribute('href','/pages/privacy-policy');
  expect(await page.evaluate(() => window.__reads)).toBe(1);
  const footer = await page.locator('.c5-footer').boundingBox();
  const brand = await page.locator('.c5-footer-brand h2').boundingBox();
  expect(footer).not.toBeNull();
  expect(footer.height).toBeGreaterThanOrEqual(100);
  expect(footer.height).toBeLessThanOrEqual(150);
  expect(brand).not.toBeNull();
  expect(brand.height).toBeLessThanOrEqual(80);
  expect(brand.width).toBeGreaterThan(180);
  const noOverflow = await page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth
  );
  expect(noOverflow).toBe(true);
});

test('desktop footer keeps canonical grid and 44px interactive targets', async ({page}) => {
  await openShell(
    page,
    [{slug:'privacy-policy'}],
    {width:1440,height:900},
    true,
    {spotifyUrl:'https://open.spotify.com/artist/brvtal'},
  );
  await page.addScriptTag({content:appJs});
  await page.evaluate(() => window.dispatchEvent(new Event('load')));
  await page.waitForFunction(() => document.documentElement.dataset.api === 'online');

  const geometry = await page.evaluate(() => {
    const grid = document.querySelector('.c5-footer-grid');
    const footer = document.querySelector('.c5-footer');
    const selectors = [
      '.c5-footer-brand',
      '.c5-footer-nav',
      '.c5-footer-contact',
      '.c5-footer-socials',
      '.c5-footer-legal',
    ];
    const rects = selectors.map(selector => {
      const node = document.querySelector(selector);
      const box = node.getBoundingClientRect();
      const style = getComputedStyle(node);
      return {
        left:box.left,
        right:box.right,
        columnStart:style.gridColumnStart,
        columnEnd:style.gridColumnEnd,
      };
    });
    const targetHeights = [...document.querySelectorAll('.c5-footer a')]
      .filter(node => {
        const box = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return !node.hidden
          && style.display !== 'none'
          && style.visibility !== 'hidden'
          && box.width > 0
          && box.height > 0;
      })
      .map(node => node.getBoundingClientRect().height);
    return {
      columns:getComputedStyle(grid).gridTemplateColumns.split(/\s+/).filter(Boolean).length,
      rects,
      targetHeights,
      footerHeight:footer.getBoundingClientRect().height,
    };
  });

  expect(geometry.columns).toBe(12);
  expect(
    geometry.rects.map(({columnStart,columnEnd}) => `${columnStart}/${columnEnd}`)
  ).toEqual(['1/4','4/7','7/9','9/11','11/-1']);
  expect(geometry.targetHeights.length).toBeGreaterThan(0);
  for (const height of geometry.targetHeights) {
    expect(height).toBeGreaterThanOrEqual(44);
  }
  for (let index=1;index<geometry.rects.length;index++) {
    expect(geometry.rects[index-1].right).toBeLessThanOrEqual(geometry.rects[index].left + 1);
  }
  expect(geometry.footerHeight).toBeLessThanOrEqual(150);
});

test('footer keeps privacy fail-closed when no compatible public Page exists', async ({page}) => {
  await openShell(page, [{slug:'about'}]);
  const privacy = page.locator('[data-footer-privacy]');
  await expect(privacy).toBeHidden();
  await expect(privacy).not.toHaveAttribute('href', /.+/);
});

test('footer reveals configured Spotify while unsafe or missing socials stay hidden', async ({page}) => {
  await openShell(
    page,
    [{slug:'privacy-policy'}],
    {width:1440,height:900},
    true,
    {
      spotifyUrl:'https://open.spotify.com/artist/brvtal',
      instagram:'javascript:alert(1)',
      website:'',
    },
  );
  await page.addScriptTag({content:appJs});
  await page.evaluate(() => window.dispatchEvent(new Event('load')));
  await page.waitForFunction(() => document.documentElement.dataset.api === 'online');

  const spotify = page.locator('[data-social="spotify"]');
  await expect(spotify).toBeVisible();
  await expect(spotify).toHaveAttribute('href', /^https:\/\/open\.spotify\.com\/artist\/brvtal\/?$/);
  await expect(page.locator('[data-social="instagram"]')).toBeHidden();
  await expect(page.locator('[data-social="website"]')).toBeHidden();
  await expect(page.locator('a[href^="javascript:"]')).toHaveCount(0);
  expect(await page.evaluate(() => window.__reads)).toBe(1);
});

async function expectDesktopHeaderFits(page, width) {
  await openShell(page, [], {width,height:900});
  const geometry = await page.evaluate(() => {
    const nodes = ['.brand','.c5-header-nav','.c5-header-origin','.c5-header-ticket','.nav-right']
      .map(selector => document.querySelector(selector))
      .filter(Boolean)
      .filter(node => {
        const box = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return style.display !== 'none' && style.visibility !== 'hidden' && box.width > 0 && box.height > 0;
      });
    return {
      items:nodes.map(node => {
        const box=node.getBoundingClientRect();
        return {left:box.left,right:box.right};
      }),
      width:document.documentElement.clientWidth,
      scrollWidth:document.documentElement.scrollWidth,
    };
  });
  for (let i=1;i<geometry.items.length;i++) {
    expect(geometry.items[i-1].right).toBeLessThanOrEqual(geometry.items[i].left + 1);
  }
  expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.width);
  await expect(page.locator('.nav-center')).toBeHidden();
  await expect(page.locator('.c5-header-ticket')).toHaveAttribute(
    'href',
    'https://tickets.example/night'
  );
}

test('desktop shell keeps ticket CTA and utilities collision-free', async ({page}) => {
  await expectDesktopHeaderFits(page, 1440);
});

test('compact desktop shell remains collision-free above the mobile breakpoint', async ({page}) => {
  await expectDesktopHeaderFits(page, 1024);
});

test('reduced motion keeps the authored shell complete and static', async ({page}) => {
  await page.emulateMedia({reducedMotion:'reduce'});
  await openShell(page);
  await expect(page.locator('.c5-footer')).toBeVisible();
  await expect(page.locator('.c5-bottom-nav')).toBeVisible();
  const transition = await page.locator('.c5-bottom-nav').evaluate(node => getComputedStyle(node).transitionDuration);
  expect(transition).toBe('0s');
});

// Use the real server-side PHP partial composer. The historical markup() above
// deliberately remains an isolated fixture and cannot prove the public DOM.
test('real PHP partial composition captures desktop and mobile against owner reference', async ({page}, testInfo) => {
  const { spawnSync } = await import('node:child_process');
  const { mkdirSync } = await import('node:fs');
  const visualDir = join(root, 'test-results', 'public-concept05-fidelity-shell');
  mkdirSync(visualDir, {recursive:true});
  const source = [
    "require 'config/public_home.php';",
    "$html = file_get_contents('index.html');",
    "foreach (['nav','hero','experience','nights','artists','sound','memories','journal','connected','footer'] as $part) {",
    "  $marker = '<!-- BRVTAL-HOME-PARTIAL:' . $part . ' -->';",
    "  if (substr_count($html,$marker) !== 1) { throw new RuntimeException('bad partial'); }",
    "  $html = str_replace($marker,file_get_contents('partials/home/' . $part . '.html'),$html);",
    "}",
    "echo brvtal_public_home_identity($html);",
  ].join('\n');
  const rendered = spawnSync('php', ['-r', source], {cwd:root, encoding:'utf8', timeout:30000});
  expect(rendered.status, rendered.stderr).toBe(0);
  expect(rendered.stdout).toContain('class="c5-bottom-nav"');
  const realUrl = 'http://127.0.0.1:4173/real-c05-home.html';
  // index.php puts data-concept on BODY, not on the documentElement.
  // Keep the PHP-composed markup and apply precisely its server-side marker.
  const realHtml = rendered.stdout.replace(
    '<body data-scene="CORE">', '<body data-scene="CORE" data-concept="05">'
  );
  expect(realHtml).toContain('<body data-scene="CORE" data-concept="05">');
  // This is the PHP-composed DOM with its own declared assets, not the
  // synthetic markup() and not JS/CSS manually injected by the test.
  expect(realHtml.match(/href="css\/public-concept05-shell\.css"/g) || []).toHaveLength(1);
  expect(realHtml.match(/src="js\/public-concept05-shell\.js"/g) || []).toHaveLength(1);
  let loadedShellModules = 0;
  await page.route('**/*', route => {
    const requested = new URL(route.request().url());
    if (requested.href === realUrl) {
      return route.fulfill({contentType:'text/html; charset=utf-8',body:realHtml});
    }
    // Serve real local asset bytes at the same origin. No external traffic,
    // uploads, production APIs or hand-written JS/CSS fixture replacements.
    if (requested.origin !== 'http://127.0.0.1:4173') return route.abort();
    let pathname;
    try {
      pathname = resolve(root, decodeURIComponent(requested.pathname).replace(/^\/+/, ''));
    } catch {
      return route.abort();
    }
    const ext = extname(pathname);
    if (!pathname.startsWith(root + sep) || !['.js', '.css'].includes(ext) || !existsSync(pathname)) {
      return route.abort();
    }
    if (requested.pathname.endsWith('/js/public-concept05-shell.js')) loadedShellModules += 1;
    return route.fulfill({
      contentType: ext === '.js' ? 'application/javascript' : 'text/css',
      body: readFileSync(pathname)
    });
  });
  await page.setViewportSize({width:1440,height:900});
  await page.goto(realUrl, {waitUntil:'domcontentloaded'});
  await page.waitForFunction(() => document.documentElement.dataset.publicShell === 'concept05');
  expect(loadedShellModules).toBe(1);
  await expect(page.locator('.c5-header-nav')).toHaveCount(1);
  await expect(page.locator('.c5-header-nav')).toBeVisible();
  await expect(page.locator('.c5-header-nav a')).toHaveCount(6);
  for (const link of await page.locator('.c5-header-nav a').all()) {
    await expect(link).toBeVisible();
  }
  await expect(page.locator('.c5-footer')).toHaveCount(1);
  await expect(page.locator('.c5-bottom-nav')).toHaveCount(1);
  await expect(page.locator('.c5-header-search')).toHaveCount(1);
  const phpDesktopScreenshot = await page.screenshot({path:join(visualDir, 'c05-php-desktop-1440.png'), fullPage:true});
  await testInfo.attach('php-c05-desktop-1440', {body:phpDesktopScreenshot, contentType:'image/png'});
  await page.setViewportSize({width:390,height:844});
  await expect(page.locator('.c5-bottom-nav')).toBeVisible();
  await expect(page.locator('.c5-bottom-nav a')).toHaveCount(5);
  for (const anchor of await page.locator('.c5-bottom-nav a').all()) {
    await expect(anchor).toBeVisible();
    const box = await anchor.boundingBox();
    expect(box?.height || 0).toBeGreaterThanOrEqual(44);
  }
  // A present/mobile footer must actually fit the screen, not only exist in DOM.
  const horizontalOverflow = await page.evaluate(() =>
    document.documentElement.scrollWidth - window.innerWidth);
  expect(horizontalOverflow).toBeLessThanOrEqual(1);
  await page.evaluate(() => {
    document.documentElement.classList.add('menu-scroll-locked');
  });
  await expect(page.locator('.c5-bottom-nav')).toHaveAttribute('aria-hidden','true');
  expect(await page.locator('.c5-bottom-nav').evaluate(element => element.inert)).toBe(true);
  await page.evaluate(() => document.documentElement.classList.remove('menu-scroll-locked'));
  await expect(page.locator('.c5-bottom-nav')).toHaveAttribute('aria-hidden','false');
  await expect(page.locator('.c5-bottom-nav')).toBeVisible();
  const phpMobileScreenshot = await page.screenshot({path:join(visualDir, 'c05-php-mobile-390.png'), fullPage:true});
  await testInfo.attach('php-c05-mobile-390', {body:phpMobileScreenshot, contentType:'image/png'});
  await testInfo.attach('php-c05-source-note', {
    body:Buffer.from('PHP_COMPOSED_LOCAL_ASSETS_SYNTHETIC_DATA_ONLY'),
    contentType:'text/plain'
  });
  // Screenshots are evidence candidates, not an automatic visual approval.
});
