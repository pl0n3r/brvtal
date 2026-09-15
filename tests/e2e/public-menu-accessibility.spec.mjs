import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const menuJs = readFileSync(join(process.cwd(), 'js/menu-accessibility.js'), 'utf8');
const menuScrollLockJs = readFileSync(join(process.cwd(), 'js/menu-scroll-lock.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-menu-accessibility-e2e.html';

test('public menu traps focus, locks background scroll and restores position', async ({ page }) => {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><head><style>
      body{margin:0}.spacer{height:3000px}
      #menuToggle{position:fixed;top:10px;left:10px;z-index:3}
      #menuPanel{position:fixed;inset:0;z-index:2;background:#111;color:#fff}
    </style></head><body>
      <button id="menuToggle">MENU <strong>+</strong></button>
      <aside id="menuPanel" aria-hidden="true">
        <nav><a href="#one">ONE</a><a href="#two">TWO</a><a href="#three">THREE</a></nav>
      </aside>
      <div class="spacer"></div>
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
      <script>
        window.__lenisInstance = new Lenis();
        const toggle = document.getElementById('menuToggle');
        const panel = document.getElementById('menuPanel');
        toggle.addEventListener('click', () => {
          const open = panel.getAttribute('aria-hidden') === 'true';
          panel.setAttribute('aria-hidden', String(!open));
        });
      </script>
      <script>${menuJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);
  const toggle = page.locator('#menuToggle');
  const panel = page.locator('#menuPanel');

  await expect(toggle).toHaveAttribute('aria-controls', 'menuPanel');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(panel).toHaveAttribute('role', 'dialog');
  await expect(panel).toHaveAttribute('aria-modal', 'true');
  await expect(panel).toHaveAttribute('aria-label', 'Site navigation');

  await toggle.focus();
  await page.evaluate(() => window.scrollTo(0, 600));
  expect(await page.evaluate(() => window.scrollY)).toBe(600);

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
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
