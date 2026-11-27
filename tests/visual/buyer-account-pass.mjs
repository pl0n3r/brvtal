import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { chromium, expect } from '@playwright/test';

const [baseDir, headDir, outputDir] = process.argv.slice(2).map(p => resolve(p));
const { BASE_SHA, HEAD_SHA, CAPTURE_MODE } = process.env;
assert.match(BASE_SHA ?? '', /^[a-f0-9]{40}$/);
assert.match(HEAD_SHA ?? '', /^[a-f0-9]{40}$/);
assert.ok(['baseline', 'comparison'].includes(CAPTURE_MODE));
assert.ok(baseDir && headDir && outputDir && baseDir !== headDir);
const origin = 'http://127.0.0.1:4173';
const ticket = 'abcdefabcdefabcdefabcdefabcdefab';
const csrf = 'synthetic-csrf';
const entry = { name: 'General', quantity: 1, ticket_id: ticket, status: 'issued',
  credential_active: true, qr_requires_original_token: true, qr_stored: true };
const paid = { public_id: '1'.repeat(32), order_number: 77, event_id: 13,
  event_title: 'Noche sintética', event_date: '2026-12-12 21:00:00',
  venue: 'Club sintético', status: 'paid', payment_status: 'approved',
  created_at_utc: '2026-11-01 21:00:00', currency: 'COP', total_cop: 25000,
  items: [entry, { ...entry, ticket_id: 'b'.repeat(32), status: 'used' },
    { ...entry, ticket_id: 'c'.repeat(32), status: 'revoked', credential_active: false }] };
const orders = [paid, { ...paid, public_id: '2'.repeat(32), order_number: 78,
  status: 'cancelled', items: [{ ...entry, ticket_id: 'd'.repeat(32) }] }];
const assets = new Map([
  ['/css/buyer-access.css', 'text/css'],
  ['/css/buyer-account.css', 'text/css'],
  ['/assets/fonts/BrokenGlass.ttf', 'font/ttf'],
]);
const actionName = 'Descargar pase (PDF)';
const widths = [390, 1440];
const captures = [];

async function source(root) {
  let html = await readFile(join(root, 'buyer/index.php'), 'utf8');
  const bootstrap = "<?php require_once __DIR__ . '/../config/buyer_auth.php'; $checkoutScopePurchases = brvtalBuyerCheckoutAccountScope(); ?>";
  const scope = '<?= json_encode($checkoutScopePurchases, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>';
  for (const part of [bootstrap, scope]) assert.equal(html.split(part).length, 2);
  // Render actual markup and inline JS; substitute only authenticated fixture bootstrap.
  html = html.replace(bootstrap, '').replace(scope, '[]');
  assert.ok(!html.includes('<?'), 'Unexpected PHP fragment; cannot render baseline safely');
  const bodies = new Map();
  for (const path of assets.keys()) bodies.set(path, await readFile(join(root, path.slice(1))));
  return { html, bodies };
}

async function capture(page, filename, width, sha, media) {
  const png = await page.screenshot({ fullPage: true, animations: 'disabled' });
  assert.equal(png.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
  assert.equal(png.readUInt32BE(16), width);
  assert.ok(png.readUInt32BE(20) >= 900);
  await writeFile(join(outputDir, filename), png, { flag: 'wx' });
  captures.push({ filename, width, height: png.readUInt32BE(20), sha, media,
    sha256: createHash('sha256').update(png).digest('hex') });
}

async function render(browser, data, width, { after = false, save = true, sha }) {
  const context = await browser.newContext({ viewport: { width, height: 900 },
    locale: 'es-CO', timezoneId: 'America/Bogota', deviceScaleFactor: 1, serviceWorkers: 'block' });
  const unexpected = [];
  const loaded = new Set();
  let qrRequests = 0;
  const json = (route, body) => route.fulfill({ contentType: 'application/json', json: body });
  try {
    await context.route('**/*', async route => {
      const request = route.request();
      const url = new URL(request.url());
      const path = url.pathname;
      const method = request.method();
      if (url.origin === origin) {
        if (method === 'GET' && path === '/buyer/index.php') {
          return route.fulfill({ contentType: 'text/html; charset=utf-8', body: data.html });
        }
        if (method === 'GET' && assets.has(path)) {
          loaded.add(path);
          return route.fulfill({ contentType: assets.get(path), body: data.bodies.get(path) });
        }
        if (method === 'GET' && path === '/api/buyer/auth.php') {
          return json(route, { ok: true, authenticated: true, csrf });
        }
        if (method === 'GET' && path === '/api/buyer/account.php') {
          return json(route, { ok: true, data: { orders, has_more: false, page: 0 } });
        }
        if (method === 'GET' && path === '/api/buyer/refunds.php') {
          return json(route, { ok: true, data: [] });
        }
        if (method === 'POST' && path === '/api/buyer/account.php' && after) {
          const body = request.postDataJSON();
          if (body?.action === 'qr_stored' && body.ticket_id === ticket &&
              request.headers()['x-csrf-token'] === csrf) {
            qrRequests++;
            return json(route, { ok: true, qr: 'BRVTAL:T1:' + ticket + ':' + 'A'.repeat(43) });
          }
        }
      }
      // No network fallback, credentials, or third-party requests.
      unexpected.push(method + ' ' + url.origin + path);
      return route.abort();
    });
    const page = await context.newPage();
    page.on('pageerror', () => unexpected.push('pageerror'));
    await page.goto(origin + '/buyer/index.php');
    await expect(page.getByRole('heading', { name: 'Mis compras' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ver QR', exact: true })).toHaveCount(1);
    await expect(page.getByText('Sin solicitudes de devolución.', { exact: true })).toBeVisible();
    await page.evaluate(() => document.fonts.ready);
    for (const path of ['/css/buyer-access.css', '/css/buyer-account.css']) assert.ok(loaded.has(path));
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    const action = page.getByRole('button', { name: actionName, exact: true });
    if (!after) {
      await expect(action).toHaveCount(0);
      assert.equal(qrRequests, 0);
      if (save) await capture(page, 'before-' + width + '.png', width, sha, 'screen');
    } else {
      await expect(action).toHaveCount(1);
      await expect(action).toBeVisible();
      await expect(page.locator('#pass-details')).toBeHidden();
      await page.evaluate(() => { window.__prints = 0; window.print = () => { window.__prints++; }; });
      await action.click();
      const print = page.getByRole('button', { name: 'Imprimir / guardar PDF' });
      await expect(print).toBeVisible();
      const details = page.locator('#pass-details');
      for (const label of ['Noche sintética', '12 de diciembre de 2026', 'Club sintético', 'General', 'ABCDEFAB']) {
        await expect(details).toContainText(label);
      }
      const svg = page.locator('#qr-output svg');
      await expect(svg).toBeVisible();
      assert.ok(await svg.locator('rect').count() > 400);
      assert.equal(qrRequests, 1);
      await print.focus();
      await print.press('Enter');
      assert.equal(await page.evaluate(() => window.__prints), 1);
      await page.emulateMedia({ media: 'print' });
      await expect(details).toBeVisible();
      await expect(svg).toBeVisible();
      await expect(page.locator('#orders')).toBeHidden();
      await expect(page.locator('#qr-form')).toBeHidden();
      await expect(print).toBeHidden();
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
      await capture(page, 'after-' + width + '.png', width, sha, 'print');
    }
    assert.deepEqual(unexpected, [], 'Unexpected browser requests or script errors');
  } finally {
    await context.close();
  }
}

const base = await source(baseDir);
const head = await source(headDir);
const comparison = CAPTURE_MODE === 'comparison';
await mkdir(outputDir, { recursive: true });
assert.deepEqual(await readdir(outputDir), [], 'Evidence directory must be empty');
const browser = await chromium.launch();
try {
  for (const width of widths) {
    await render(browser, base, width, { sha: BASE_SHA });
    await render(browser, head, width, { after: comparison, save: comparison, sha: HEAD_SHA });
  }
} finally {
  await browser.close();
}
assert.equal(captures.length, comparison ? 4 : 2);
const expectedFiles = widths.flatMap(width => comparison
  ? ['before-' + width + '.png', 'after-' + width + '.png'] : ['before-' + width + '.png']);
assert.deepEqual((await readdir(outputDir)).sort(), expectedFiles.sort());
const receipt = comparison ? 'ACCOUNT_PASS_AB_PASS' : 'ACCOUNT_PASS_BASELINE_ONLY';
await writeFile(join(outputDir, 'manifest.json'), JSON.stringify({
  issue: 2184, wiring_issue: 2232, base_sha: BASE_SHA, head_sha: HEAD_SHA,
  mode: CAPTURE_MODE, widths, captures, receipt, production_observed: false,
}, null, 2) + '\n', { flag: 'wx' });
console.log(receipt + ': base=' + BASE_SHA + ' head=' + HEAD_SHA + ' captures=' + captures.length);
