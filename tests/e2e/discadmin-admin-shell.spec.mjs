import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = readFileSync(join(process.cwd(), 'discadmin/admin-shell.css'), 'utf8');
const js = readFileSync(join(process.cwd(), 'discadmin/admin-shell.js'), 'utf8');

async function mount(page) {
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>
    <div id="app"><div class="shell">
      <button class="admin-nav-scrim" aria-label="Close navigation" onclick="closeAdminNav()"></button>
      <aside class="side" id="admin-primary-nav" aria-label="Primary navigation">
        <div class="nav"><button class="active" onclick="window.selected='dashboard'">DASHBOARD</button><button onclick="window.selected='security'">SECURITY / 2FA</button></div>
      </aside>
      <main class="main"><div class="top"><button class="admin-menu-toggle" aria-controls="admin-primary-nav" aria-expanded="false" onclick="toggleAdminNav()">MENU</button><h1>DASHBOARD</h1></div></main>
    </div></div><script>${js}</script></body></html>`);
}

test('mobile navigation uses the existing sidebar and closes after choosing a module', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mount(page);
  const menu = page.getByRole('button', { name: 'MENU' });
  const nav = page.getByRole('complementary', { name: 'Primary navigation' });
  await expect(menu).toBeVisible();
  await expect(nav).toBeHidden();
  await menu.click();
  await expect(menu).toHaveAttribute('aria-expanded', 'true');
  await expect(nav).toBeVisible();
  await nav.getByRole('button', { name: 'SECURITY / 2FA' }).click();
  await expect(nav).toBeHidden();
  await expect(menu).toHaveAttribute('aria-expanded', 'false');
  expect(await page.evaluate(() => window.selected)).toBe('security');
});

test('Escape closes the mobile menu and returns focus; desktop sidebar stays visible', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mount(page);
  const menu = page.getByRole('button', { name: 'MENU' });
  const nav = page.getByRole('complementary', { name: 'Primary navigation' });
  await menu.click();
  await page.keyboard.press('Escape');
  await expect(nav).toBeHidden();
  await expect(menu).toBeFocused();
  await page.setViewportSize({ width: 1280, height: 800 });
  await expect(nav).toBeVisible();
  await expect(menu).toBeHidden();
});
