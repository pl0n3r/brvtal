import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const menuJs = readFileSync(join(process.cwd(), 'js/menu-accessibility.js'), 'utf8');
const menuScrollLockJs = readFileSync(join(process.cwd(), 'js/menu-scroll-lock.js'), 'utf8');
const inputCss = readFileSync(join(process.cwd(), 'css/input-accessibility.css'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-menu-accessibility-e2e.html';

function harness() {
  return `<!doctype html><html lang="en"><head><style>
    :root{--acid:#b8ff00;--fg:#fff;--bg:#050505}
    body{margin:0;background:#050505;color:#fff}.spacer{height:3000px}
    .nav{position:fixed;inset:0 0 auto;z-index:100;height:68px;display:flex;align-items:center;justify-content:space-between;padding:0 16px;background:#050505}
    .brand{width:70px}.nav-right{display:flex}.sound,.menu{background:none;border:0;color:#fff}.menu-panel{position:fixed;inset:0;z-index:90;background:#111;color:#fff;visibility:hidden;transform:translateY(-100%)}
    .menu-panel-inner{height:100%;display:flex;align-items:center;padding:80px 18px}.menu-panel nav{display:flex;flex-direction:column}.menu-panel a{color:#fff;font-size:48px}
    ${inputCss}
  </style></head><body>
    <header class="nav">
      <a class="brand" href="#top">BRVTAL</a>
      <div class="nav-right">
        <button class="sound" id="soundToggle">SOUND <b>OFF</b></button>
        <button class="menu" id="menuToggle" data-cursor="MENU">MENU <strong>+</strong></button>
      </div>
    </header>
    <aside class="menu-panel" id="menuPanel" aria-hidden="true">
      <div class="menu-panel-inner"><nav><a href="#one">ONE</a><a href="#two">TWO</a><a href="#three">THREE</a></nav></div>
    </aside>
    <main id="top"><div class="spacer"></div></main>
    <script>
      window.__lenisStats = { stops: 0, starts: 0, resizes: 0 };
      window.Lenis = class FakeLenis {
        constructor(){ this.isStopped = false; }
        stop(){ this.isStopped = true; window.__lenisStats.stops++; }
        start(){ this.isStopped = false; window.__lenisStats.starts++; }
        resize(){ window.__lenisStats.resizes++; }
      };
    </script>
    <script>${menuScrollLockJs}</script>
    <script>window.__lenisInstance = new Lenis();</script>
    <script>${menuJs}</script>
  </body></html>`;
}

async function routeHarness(page) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: harness(),
  }));
}

test('public menu traps focus, locks background scroll and restores position', async ({ page }) => {
  await routeHarness(page);
  await page.goto(harnessUrl);
  const toggle = page.locator('#menuToggle');
  const panel = page.locator('#menuPanel');

  await expect(toggle).toHaveAttribute('aria-controls', 'menuPanel');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toHaveAttribute('data-cursor', '');
  await expect(panel).toHaveAttribute('role', 'dialog');
  await expect(panel).toHaveAttribute('aria-modal', 'true');
  await expect(panel).toHaveAttribute('aria-label', 'Site navigation');
  await expect(panel).toHaveJSProperty('inert', true);

  await toggle.focus();
  await page.evaluate(() => window.scrollTo(0, 600));
  expect(await page.evaluate(() => window.scrollY)).toBe(600);

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(panel).toHaveAttribute('aria-hidden', 'false');
  await expect(panel).toHaveCSS('visibility', 'visible');
  await expect(panel).toHaveJSProperty('inert', false);
  await expect(page.getByRole('link', { name: 'ONE' })).toBeFocused();
  await expect(page.locator('html')).toHaveClass(/menu-scroll-locked/);
  await expect(page.locator('body')).toHaveClass(/menu-scroll-locked/);
  expect(await page.locator('body').evaluate(el => el.style.position)).toBe('fixed');
  expect(await page.locator('body').evaluate(el => el.style.top)).toBe('-600px');
  expect(await page.evaluate(() => window.__lenisStats.stops)).toBe(1);
  expect(await page.evaluate(() => window.__lenisInstance.isStopped)).toBe(true);

  await page.mouse.wheel(0, 900);
  expect(await page.locator('body').evaluate(el => el.style.top)).toBe('-600px');

  await page.getByRole('link', { name: 'THREE' }).focus();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'ONE' })).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('link', { name: 'THREE' })).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(panel).toHaveAttribute('aria-hidden', 'true');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toBeFocused();
  await expect(page.locator('html')).not.toHaveClass(/menu-scroll-locked/);
  await expect(page.locator('body')).not.toHaveClass(/menu-scroll-locked/);
  expect(await page.evaluate(() => window.scrollY)).toBe(600);
  expect(await page.evaluate(() => window.__lenisStats.starts)).toBe(1);
  expect(await page.evaluate(() => window.__lenisStats.resizes)).toBe(1);
  expect(await page.evaluate(() => window.__lenisInstance.isStopped)).toBe(false);
});

test('mobile menu opens repeatedly, closes on navigation and header controls do not overlap', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await routeHarness(page);
  await page.goto(harnessUrl);

  const toggle = page.locator('#menuToggle');
  const sound = page.locator('#soundToggle');
  const panel = page.locator('#menuPanel');

  for (let cycle = 0; cycle < 2; cycle++) {
    await toggle.click();
    await expect(panel).toHaveAttribute('aria-hidden', 'false');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('body')).toHaveClass(/menu-scroll-locked/);

    await toggle.click();
    await expect(panel).toHaveAttribute('aria-hidden', 'true');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('body')).not.toHaveClass(/menu-scroll-locked/);
  }

  await toggle.click();
  await page.getByRole('link', { name: 'TWO' }).click();
  await expect(panel).toHaveAttribute('aria-hidden', 'true');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(page.locator('body')).not.toHaveClass(/menu-scroll-locked/);

  const geometry = await page.evaluate(() => {
    const sound = document.querySelector('#soundToggle').getBoundingClientRect();
    const menu = document.querySelector('#menuToggle').getBoundingClientRect();
    const icon = document.querySelector('#menuToggle strong').getBoundingClientRect();
    return {
      soundRight: sound.right,
      menuLeft: menu.left,
      menuRight: menu.right,
      iconLeft: icon.left,
      iconRight: icon.right,
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
    };
  });

  expect(geometry.soundRight).toBeLessThanOrEqual(geometry.menuLeft + 0.5);
  expect(geometry.iconLeft).toBeGreaterThan(geometry.menuLeft);
  expect(geometry.iconRight).toBeLessThanOrEqual(geometry.menuRight + 0.5);
  expect(geometry.scrollWidth).toBeLessThanOrEqual(geometry.viewport);
  await expect(sound).toHaveCSS('min-height', '44px');
  await expect(toggle).toHaveCSS('min-height', '44px');
});
