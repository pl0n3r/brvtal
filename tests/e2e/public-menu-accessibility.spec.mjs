import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const menuJs = readFileSync(join(process.cwd(), 'js/menu-accessibility.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-menu-accessibility-e2e.html';

test('public menu exposes state, traps focus, closes with Escape and restores focus', async ({ page }) => {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="en"><body>
      <button id="menuToggle">MENU <strong>+</strong></button>
      <aside id="menuPanel" aria-hidden="true">
        <nav><a href="#one">ONE</a><a href="#two">TWO</a><a href="#three">THREE</a></nav>
      </aside>
      <script>
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
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('link', { name: 'ONE' })).toBeFocused();

  await page.getByRole('link', { name: 'THREE' }).focus();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'ONE' })).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('link', { name: 'THREE' })).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(panel).toHaveAttribute('aria-hidden', 'true');
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(toggle).toBeFocused();
});
