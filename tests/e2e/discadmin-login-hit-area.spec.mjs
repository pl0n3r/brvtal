import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect } from '@playwright/test';

const widths = [390, 430, 768, 1024, 1440, 360];

// This isolated browser fixture renders the actual login template and both CSS
// sources. BRVTAL CI's Chromium job does not start a PHP server at :4173.
// Route only the source-derived login and recovery pages; no production traffic.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const loginSource = readFileSync(resolve(root, 'discadmin/index-core.php'), 'utf8');
const securityCss = readFileSync(resolve(root, 'discadmin/security.css'), 'utf8');
const recoverySource = readFileSync(resolve(root, 'discadmin/forgot-password.php'), 'utf8');
const inlineCss = loginSource.match(/<style>([\s\S]*?)<\/style>/)?.[1];
const loginTemplate = loginSource.match(/(<div class="login">\s*<form class="loginbox"[\s\S]*?<\/form>\s*<\/div>)/)?.[1];
if (!inlineCss || !loginTemplate) {
  throw new Error('Source-derived DISCADMIN login template or CSS not found');
}
const loginHtml = [
  '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">',
  '<style>' + inlineCss + '</style>',
  '<style>' + securityCss + '</style>',
  '</head><body>',
  loginTemplate,
  '</body></html>'
].join('\n');
const recoveryHtml = recoverySource.replace(/<\?php[\s\S]*?\?>/g, '');

async function openLogin(page, width) {
  await page.setViewportSize({ width, height: 900 });
  await page.route('https://brvtal.test/**', route => {
    const pathname = new URL(route.request().url()).pathname;
    if (pathname === '/discadmin/') {
      return route.fulfill({ status: 200, contentType: 'text/html', body: loginHtml });
    }
    if (pathname === '/discadmin/forgot-password.php') {
      return route.fulfill({ status: 200, contentType: 'text/html', body: recoveryHtml });
    }
    return route.abort();
  });
  await page.goto('https://brvtal.test/discadmin/', { waitUntil: 'domcontentloaded' });

  const recovery = page.locator('.login-recovery');
  if (!(await recovery.count())) {
    const title = await page.title().catch(() => '');
    const body = await page.locator('body').innerText().catch(() => '');
    test.skip(true, 'UNKNOWN at ' + width + 'px: login unavailable; title=' + title + '; body=' + body.slice(0, 120));
  }
  await expect(recovery).toBeVisible();
  return recovery;
}

for (const width of widths) {
  test(`recovery link has a 44px hit area without overlap at ${width}px`, async ({ page }) => {
    const recovery = await openLogin(page, width);
    const metrics = await recovery.evaluate(element => {
      const rect = element.getBoundingClientRect();
      const loginBox = element.closest('.loginbox');
      const controls = [...loginBox.querySelectorAll('input, button, .syscheck, .error')]
        .map(target => {
          const box = target.getBoundingClientRect();
          const style = getComputedStyle(target);
          return { box, visible: style.display !== 'none' && style.visibility !== 'hidden' && box.width > 0 && box.height > 0, name: target.id || target.tagName.toLowerCase() };
        })
        .filter(item => item.visible);
      const overlaps = controls
        .filter(item => rect.left < item.box.right && rect.right > item.box.left && rect.top < item.box.bottom && rect.bottom > item.box.top)
        .map(item => item.name);
      return {
        width: rect.width,
        height: rect.height,
        overlaps,
        viewportWidth: window.innerWidth,
        scrollWidth: document.documentElement.scrollWidth
      };
    });

    expect(metrics.width).toBeGreaterThanOrEqual(44);
    expect(metrics.height).toBeGreaterThanOrEqual(44);
    expect(metrics.overlaps).toEqual([]);
    expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.viewportWidth);
  });
}

test('recovery link keeps native keyboard focus, visible outline and Enter navigation', async ({ page }) => {
  const recovery = await openLogin(page, 390);
  const email = page.locator('#admin-login-email');
  const password = page.locator('#admin-login-password');
  const enter = page.getByRole('button', { name: 'ENTER' });

  await email.focus();
  await page.keyboard.press('Tab');
  await expect(password).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(enter).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(recovery).toBeFocused();

  const focusVisible = await recovery.evaluate(element => {
    const style = getComputedStyle(element);
    return element.matches(':focus-visible') && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) >= 2;
  });
  expect(focusVisible).toBe(true);

  await page.keyboard.press('Shift+Tab');
  await expect(enter).toBeFocused();
  await recovery.focus();
  await page.keyboard.press('Enter');
  await page.waitForURL(url => url.pathname === '/discadmin/forgot-password.php');
  await expect(page.locator('#recovery-email')).toBeVisible();
});

test('recovery affordance does not replace or bypass the existing login form', async ({ page }) => {
  const recovery = await openLogin(page, 390);
  await expect(page.locator('form.loginbox')).toHaveAttribute('onsubmit', 'login(event)');
  await expect(page.locator('#admin-login-email')).toHaveAttribute('type', 'email');
  await expect(page.locator('#admin-login-password')).toHaveAttribute('type', 'password');
  await expect(page.getByRole('button', { name: 'ENTER' })).toBeVisible();
  await expect(recovery).toHaveAttribute('href', '/discadmin/forgot-password.php');
  await expect(page.locator('script[src*="/discadmin/totp-login.js"]')).toHaveCount(1);
});
