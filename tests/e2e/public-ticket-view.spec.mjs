import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

// #1945: the door pass renders the QR from the URL fragment and never sends it.
const root = process.cwd();
const page = readFileSync(join(root, 'buyer/ticket.php'), 'utf8').replace(/^<\?php[\s\S]*?\?>/, '');
const assets = {
  '/css/buyer-access.css': ['text/css', readFileSync(join(root, 'css/buyer-access.css'))],
  '/css/public-ticket-view.css': ['text/css', readFileSync(join(root, 'css/public-ticket-view.css'))],
  '/js/vendor/qrcode-generator.js': ['text/javascript', readFileSync(join(root, 'js/vendor/qrcode-generator.js'))],
  '/js/public-ticket-view.js': ['text/javascript', readFileSync(join(root, 'js/public-ticket-view.js'))],
  '/assets/fonts/BrokenGlass.ttf': ['font/ttf', readFileSync(join(root, 'assets/fonts/BrokenGlass.ttf'))],
};
const base = 'http://127.0.0.1:4173';
const id = '0123456789abcdef0123456789abcdef';
const token = 'Synthetic_Ticket_Token_Only_For_Tests_01234';
const payload = `BRVTAL:T1:${id}:${token}`;
const evidence = join(root, 'visual-evidence');

async function setup(browserPage) {
  const requests = [];
  browserPage.on('request', request => requests.push(request.url()));
  await browserPage.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.pathname === '/mi-cuenta/boleta') {
      return route.fulfill({ contentType: 'text/html; charset=utf-8', body: page });
    }
    const asset = assets[url.pathname];
    if (asset) return route.fulfill({ contentType: asset[0], body: asset[1] });
    return route.fulfill({ status: 404, body: '' });
  });
  return requests;
}

async function capture(browserPage, testInfo, name) {
  mkdirSync(evidence, { recursive: true });
  const png = await browserPage.screenshot({ fullPage: true });
  writeFileSync(join(evidence, name + '.png'), png);
  await testInfo.attach(name, { body: png, contentType: 'image/png' });
}

for (const [label, size] of [['390', { width: 390, height: 844 }], ['1440', { width: 1440, height: 900 }]]) {
  test(`door pass renders the QR locally at ${label}px`, async ({ page: browserPage }, testInfo) => {
    await browserPage.setViewportSize(size);
    const requests = await setup(browserPage);
    await browserPage.goto(`${base}/mi-cuenta/boleta#${payload}`);
    const pass = browserPage.locator('#ticket-pass');
    await expect(pass).toBeVisible();
    await expect(browserPage.locator('#ticket-error')).toBeHidden();
    await expect(browserPage.locator('#ticket-ref')).toHaveText(id.slice(0, 8).toUpperCase());
    const qr = browserPage.locator('#ticket-code img');
    await expect(qr).toBeVisible();
    const box = await browserPage.locator('#ticket-code').boundingBox();
    expect(box.width).toBeGreaterThanOrEqual(220);
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(size.width);
    // The secret never leaves the page: not in any request, not in a tooltip.
    for (const url of requests) expect(url).not.toContain(token);
    expect(await browserPage.locator('#ticket-code [title], #ticket-code[title]').count()).toBe(0);
    const scrollWidth = await browserPage.evaluate(() => document.documentElement.scrollWidth);
    expect(scrollWidth).toBeLessThanOrEqual(size.width);
    await capture(browserPage, testInfo, `ticket-view-despues-${label}`);
  });
}

test('an incomplete link explains how to recover', async ({ page: browserPage }, testInfo) => {
  await browserPage.setViewportSize({ width: 390, height: 844 });
  await setup(browserPage);
  await browserPage.goto(`${base}/mi-cuenta/boleta#BRVTAL:T1:${id}:corto`);
  await expect(browserPage.locator('#ticket-error')).toBeVisible();
  await expect(browserPage.locator('#ticket-pass')).toBeHidden();
  await expect(browserPage.getByRole('link', { name: 'Ir a Mi cuenta' })).toHaveAttribute('href', '/mi-cuenta');
  await capture(browserPage, testInfo, 'ticket-view-error-390');
  // A later valid fragment (e.g. the next ticket link) re-renders without reload.
  await browserPage.evaluate(value => { location.hash = value; }, payload);
  await expect(browserPage.locator('#ticket-pass')).toBeVisible();
});
