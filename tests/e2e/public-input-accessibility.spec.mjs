import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const homeCss = readFileSync(join(process.cwd(), 'css/style.css'), 'utf8');
const css = readFileSync(join(process.cwd(), 'css/input-accessibility.css'), 'utf8');
const script = readFileSync(join(process.cwd(), 'js/input-accessibility.js'), 'utf8');
const publicEntry = readFileSync(join(process.cwd(), 'index.php'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-input-accessibility-e2e.html';

async function openHarness(page, withGsap) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><style>:root{--acid:#b8ff00;--fg:#f2f2ef;--bg:#050505}${homeCss}${css}</style></head><body>
      <a class="skip-link mono" href="#content">SKIP TO CONTENT</a>
      <a href="#content">LINK</a><button type="button">BUTTON</button>
      <a class="brand" href="#content"><span>BRVTAL</span><small>RAVE TILL GRAVE</small></a>
      <button class="sound" type="button">SOUND</button><button class="menu" type="button">MENU</button>
      <a class="enter" href="#content">ENTER EXPERIENCE</a><a class="event-ticket" href="#content">TICKETS</a><a class="footer-link" href="#content">CONTACT</a>
      <div class="cursor"></div><div class="cursor-label"></div><main id="content" tabindex="-1">CONTENT</main>
      ${withGsap ? '<script>window.gsap={};</script>' : ''}<script>${script}</script>
    </body></html>`,
  }));
  await page.goto(harnessUrl);
}

test('public home preserves native cursor when custom cursor dependencies are unavailable', async ({ page }) => {
  await openHarness(page, false);
  await expect(page.locator('body')).not.toHaveClass(/cursor-enhanced/);
  await expect(page.locator('.cursor')).toHaveCSS('display', 'none');
  await expect(page.locator('body')).toHaveCSS('cursor', 'auto');
});

test('public home exposes visible keyboard focus', async ({ page }) => {
  await openHarness(page, false);
  const link = page.getByRole('link', { name: 'LINK' });
  await link.focus();
  await expect(link).toHaveCSS('outline-style', 'solid');
  await expect(link).toHaveCSS('outline-width', '2px');
});

test('public home provides keyboard skip navigation to the main content', async ({ page }) => {
  expect(publicEntry).toContain('SKIP TO CONTENT');
  expect(publicEntry).toContain('<main id="top" tabindex="-1">');

  await openHarness(page, false);
  const skip = page.getByRole('link', { name: 'SKIP TO CONTENT' });
  await expect(skip).toHaveCSS('transform', /matrix/);

  await page.keyboard.press('Tab');
  await expect(skip).toBeFocused();
  await expect.poll(() => skip.evaluate(element => getComputedStyle(element).transform)).toMatch(/^(none|matrix\(1, 0, 0, 1, 0, 0\))$/);

  await page.keyboard.press('Enter');
  await expect(page.locator('#content')).toBeFocused();
});

test('public home primary mobile controls meet minimum touch target height', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await openHarness(page, false);
  for (const selector of ['.brand','.sound','.menu','.enter','.event-ticket','.footer-link']) {
    const height = await page.locator(selector).evaluate(el => el.getBoundingClientRect().height);
    expect(height, `${selector} touch target`).toBeGreaterThanOrEqual(44);
  }
  await expect(page.locator('.event-ticket')).toHaveCSS('font-size', '11px');
});
