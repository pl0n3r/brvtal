import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const load = path => readFileSync(join(root, path), 'utf8');
const oldInline = load('discadmin/index-core.php').match(/<style>([\s\S]*?)<\/style>/);
if (!oldInline) throw new Error('Cannot read canonical DISCADMIN stylesheet');
const styles = [
  oldInline[1],
  load('discadmin/admin-appearance.css'),
  load('discadmin/admin-shell.css'),
  load('discadmin/dashboard-v2.css'),
  load('discadmin/ticketing.css'),
  load('discadmin/admin-console.css'),
].join('\n');
const appearance = load('discadmin/admin-appearance.js');
const fixtureUrl = 'http://127.0.0.1:4173/discadmin-accent-contrast.html';

function markup() {
  return '<!doctype html><html lang="es" data-discadmin-appearance="dark"><head>'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<style>' + styles + '</style></head><body>'
    + '<div id="app"><div class="shell">'
    + '<aside class="side" aria-label="Navegación principal">'
    + '<strong class="logo">BRVTAL</strong><nav class="nav">'
    + '<button class="active" data-admin-nav="dashboard">Dashboard</button>'
    + '<button data-admin-nav="events">Events</button>'
    + '<button data-admin-nav="settings">Settings</button>'
    + '</nav><div class="sidefoot"><button type="button">Logout</button></div></aside>'
    + '<main class="main"><div class="top"><h1>DISCADMIN</h1></div>'
    + '<section class="dashboard-v2"><h2>Dashboard</h2>'
    + '<button data-primary="dashboard" class="dashboard-v2-button accent">+ NEW EVENT</button></section>'
    + '<section class="events"><h2>Events</h2>'
    + '<button data-primary="events" class="btn red">+ NEW EVENT</button></section>'
    + '<section class="settings-v2" data-settings-v2-root><div class="sv2-hero"><h2>Settings</h2></div>'
    + '<div class="sv2-layout"><div class="sv2-pane active">'
    + '<button data-primary="settings" class="btn red">SAVE GENERAL</button></div></div></section>'
    + '<section class="ticketing"><div class="ticketing-tabs" role="tablist">'
    + '<button role="tab" aria-selected="true">BOLETERÍA</button>'
    + '<button role="tab" aria-selected="false">REPORTES</button>'
    + '</div></section><div class="status"><i></i>OK</div>'
    + '</main></div></div>'
    + '<script>' + appearance + '</script></body></html>';
}

function luminance(color) {
  const rgb = String(color).match(/[\d.]+/g)?.slice(0,3).map(Number);
  if (!rgb || rgb.length !== 3) throw new Error('Unrecognized CSS color: ' + color);
  const channels = rgb.map(channel => {
    const v = channel / 255;
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}
function contrast(a,b) {
  const x = luminance(a), y = luminance(b);
  return (Math.max(x,y) + .05) / (Math.min(x,y) + .05);
}
async function colors(locator) {
  return locator.evaluate(el => {
    const s = getComputedStyle(el);
    return {bg:s.backgroundColor,fg:s.color};
  });
}

async function open(page) {
  await page.route(fixtureUrl, route => route.fulfill({contentType:'text/html; charset=utf-8',body:markup()}));
  await page.goto(fixtureUrl);
}

test('Dashboard, Events and Settings primary CTAs meet WCAG AA in every appearance', async ({ page }) => {
  await open(page);
  for (const mode of ['dark','light','glass']) {
    await page.evaluate(m => window.BRVTALAdminAppearance.setMode(m), mode);
    for (const route of ['dashboard','events','settings']) {
      const action = page.locator('[data-primary="' + route + '"]');
      await expect(action).toBeVisible();
      const {bg,fg} = await colors(action);
      expect(contrast(bg,fg),mode + ' / ' + route + ' / ' + bg + ' / ' + fg).toBeGreaterThanOrEqual(4.5);
      const computed = await action.evaluate(el => getComputedStyle(el).backgroundColor);
      expect(computed).toBe('rgb(196, 24, 50)');
    }
  }
});

test('sidebar active selection and ticketing selected tab use the SAME brand accent', async ({ page }) => {
  await open(page);
  for (const mode of ['dark','light','glass']) {
    await page.evaluate(m => window.BRVTALAdminAppearance.setMode(m), mode);
    const signal = await page.locator('.side .nav button.active').evaluate(el => getComputedStyle(el).boxShadow);
    expect(signal).toContain('rgb(196, 24, 50)');
    const selected = page.locator('.ticketing-tabs button[aria-selected="true"]');
    const {bg,fg} = await colors(selected);
    expect(bg).toBe('rgb(196, 24, 50)');
    expect(contrast(bg,fg)).toBeGreaterThanOrEqual(4.5);
  }
});

test('appearance is a user preference in Settings, never inside the sidebar', async ({ page }) => {
  await open(page);
  await expect(page.locator('.side .discadmin-appearance')).toHaveCount(0);
  const preferences = page.locator('.settings-v2 [data-appearance-selector="preferences"]');
  await expect(preferences).toBeVisible();
  await expect(preferences).toContainText('PREFERENCIAS DE APARIENCIA');
  await expect(preferences.getByRole('radio')).toHaveCount(3);
  await preferences.locator('button[data-discadmin-appearance="light"]').click();
  await expect(page.locator('html')).toHaveAttribute('data-discadmin-appearance','light');
  expect(await page.evaluate(() => localStorage.getItem('brvtal.discadmin.appearance'))).toBe('light');
  // Settings can be torn down and reconstructed without losing preference state.
  await page.evaluate(() => {
    const original = document.querySelector('.settings-v2');
    const fresh = original.cloneNode(false);
    fresh.innerHTML = '<div class="sv2-hero"><h2>Settings</h2></div><div class="sv2-layout"></div>';
    original.replaceWith(fresh);
  });
  const restored = page.locator('.settings-v2 [data-appearance-selector="preferences"]');
  await expect(restored).toBeVisible();
  await expect(restored.locator('button[data-discadmin-appearance="light"]')).toHaveAttribute('aria-checked','true');
});

test('before/after 390 and 1440 captures and no horizontal overflow', async ({page}, testInfo) => {
  await open(page);
  await page.evaluate(() => window.BRVTALAdminAppearance.setMode('dark'));
  // Visual baseline is a synthetic reapplication of the previous shipped colors,
  // not an asserted live production capture.
  const beforeCss = [
    'html[data-discadmin-appearance]{--red:#ff2038;}',
    'html[data-discadmin-appearance] #app .shell{--console-accent:#b8ff00;}',
    'html[data-discadmin-appearance] #app .side .nav button.active{box-shadow:inset 3px 0 0 #b8ff00;}',
    'html[data-discadmin-appearance] #app .ticketing-tabs button[aria-selected="true"]{background:#b20f29;border-color:#b20f29;}',
  ].join('\n');
  for (const width of [390,1440]) {
    await page.setViewportSize({width,height:width===390?844:900});
    const before = await page.addStyleTag({content:beforeCss});
    await testInfo.attach('before-synthetic-' + width + '.png',{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    const oldButton = await colors(page.locator('[data-primary="events"]'));
    expect(contrast(oldButton.bg,oldButton.fg)).toBeLessThan(4.5);
    await before.evaluate(node => node.remove());
    await expect(page.locator('.settings-v2 [data-appearance-selector="preferences"]')).toBeVisible();
    const bounds = await page.evaluate(() => ({
      body:document.documentElement.scrollWidth,
      viewport:document.documentElement.clientWidth,
    }));
    expect(bounds.body).toBeLessThanOrEqual(bounds.viewport + 1);
    const afterButton = await colors(page.locator('[data-primary="events"]'));
    expect(contrast(afterButton.bg,afterButton.fg)).toBeGreaterThanOrEqual(4.5);
    await testInfo.attach('after-cromo-' + width + '.png',{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
  }
});
