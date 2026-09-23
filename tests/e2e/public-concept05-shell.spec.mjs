import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const runtime = readFileSync(join(root, 'js/public-concept05-shell.js'), 'utf8');
const css = [
  'css/public-concept05-tokens.css',
  'css/public-concept05-home.css',
  'css/public-concept05-shell.css',
].map(path => readFileSync(join(root, path), 'utf8')).join('\n');
const url = 'http://127.0.0.1:4173/concept05-shell.html';

function markup(pages = [{slug:'privacy-policy'}], ticket = true) {
  const payload = JSON.stringify({pages}).replace(/</g, '\\u003c');
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
    + '<button class="menu">MENU <strong>+</strong></button></div></header>'
    + '<main id="top"><section id="events" style="height:700px">EVENTS</section>'
    + '<section id="artists" style="height:700px">ARTISTS</section>'
    + '<section id="sets" style="height:700px">SETS</section>'
    + '<section id="media" style="height:700px">MEDIA</section>'
    + '<section id="transmissions" style="height:700px">JOURNAL</section>'
    + '<section id="connected" style="height:700px">CONNECTED</section>'
    + '<footer class="footer scene c5-footer" id="site-footer"><div class="c5-footer-grid">'
    + '<div class="c5-footer-brand"><span class="mono">PEREIRA / COLOMBIA</span>'
    + '<h2 data-site-name>BRVTAL</h2><p><span data-site-tagline>RAVE TILL GRAVE</span></p></div>'
    + '<nav class="c5-footer-nav mono"><a href="#events">NIGHTS</a><a href="#artists">ARTISTS</a></nav>'
    + '<div class="c5-footer-contact"><span class="mono">TRANSMIT</span>'
    + '<a href="/contact">CONTACT ↗</a><a href="/contact">COLLABORATE ↗</a></div>'
    + '<div class="c5-footer-socials mono"><a data-social="instagram" hidden>INSTAGRAM</a></div>'
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

async function openShell(page, pages = [], viewport = {width:390,height:844}, ticket = true) {
  await page.setViewportSize(viewport);
  await page.route(url, route => route.fulfill({contentType:'text/html; charset=utf-8',body:markup(pages,ticket)}));
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
  const brand = await page.locator('.c5-footer-brand h2').boundingBox();
  expect(brand?.width || 0).toBeGreaterThan(500);
  const noOverflow = await page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth
  );
  expect(noOverflow).toBe(true);
});

test('footer keeps privacy fail-closed when no compatible public Page exists', async ({page}) => {
  await openShell(page, [{slug:'about'}]);
  const privacy = page.locator('[data-footer-privacy]');
  await expect(privacy).toBeHidden();
  await expect(privacy).not.toHaveAttribute('href', /.+/);
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
