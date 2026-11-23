import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

// #1927: Integraciones keeps Wompi test and production key sets apart and lets the
// owner pick the sale mode; Production asks for explicit confirmation.
const settingsSource = readFileSync(new URL('../../discadmin/settings-v2.js', import.meta.url), 'utf8');
const settingsCss = readFileSync(new URL('../../discadmin/settings-v2.css', import.meta.url), 'utf8');
const url = 'http://127.0.0.1:4173/discadmin/wompi-fixture.html?settings=integrations';

// Immutable reference: the real settings code and CSS before this PR, not a mock drawing.
const visualBaseSha = '05c80a8d5851f120d49fb00e2229a82a9e9fd38a';
let baseFetched = false;
function beforeSource(path) {
  const show = () => execFileSync('git', ['show', visualBaseSha + ':' + path],
    { encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 });
  try { return show(); }
  catch {
    if (!baseFetched) {
      execFileSync('git', ['fetch', '--no-tags', '--depth=1', 'origin', visualBaseSha],
        { stdio: 'ignore', timeout: 60000 });
      baseFetched = true;
    }
    return show();
  }
}

// Export only synthetic fixtures, not production data or credentials, to CI logs.
// The payload is chunked so evidence can be materialized as stable PR PNG attachments.
function emitVisual(name, bytes) {
  const encoded = bytes.toString('base64');
  for (let at = 0; at < encoded.length; at += 4000) {
    console.log('BRVTAL_VISUAL|' + name + '|' + at + '|' + encoded.slice(at, at + 4000));
  }
}

const WOMPI_KEYS = [
  'PUBLIC_KEY', 'PRIVATE_KEY', 'INTEGRITY_SECRET', 'EVENTS_SECRET',
  'PROD_PUBLIC_KEY', 'PROD_PRIVATE_KEY', 'PROD_INTEGRITY_SECRET', 'PROD_EVENTS_SECRET',
  'ENABLED_METHODS', 'RETURN_URL', 'MODE',
].map(suffix => 'BRVTAL_TICKET_WOMPI_' + suffix);

async function setup(page, { prodComplete = true, source = settingsSource, styles = settingsCss } = {}) {
  await page.route('**/discadmin/wompi-fixture.html?*', route => route.fulfill({
    contentType: 'text/html',
    body: '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      + '<body style="margin:0;background:#050606;color:#f6f6f4;font-family:Arial,sans-serif">'
      + '<div class="main" style="padding:18px;max-width:1500px;margin:auto"><div class="top"><h1 style="font-size:16px">SETTINGS</h1></div>'
      + '<div id="fixture"></div></div></body></html>',
  }));
  await page.goto(url);
  await page.evaluate(({ keys, prodComplete }) => {
    window.state = { rows: [] };
    window.csrf = 'synthetic-csrf-token';
    window.req = async () => ({ data: [] });
    const stored = new Set(keys.filter(key => key !== 'BRVTAL_TICKET_WOMPI_MODE'));
    if (!prodComplete) stored.delete('BRVTAL_TICKET_WOMPI_PROD_EVENTS_SECRET');
    const fixture = window.wompiFixture = {
      mode: 'sandbox', writes: [], responses: [],
      status: { mode: 'sandbox', ready: true, reason: null },
    };
    const json = (data, status = 200) => {
      const body = JSON.stringify(data);
      fixture.responses.push(body);
      return new Response(body, { status, headers: { 'Content-Type': 'application/json' } });
    };
    const payload = () => ({
      ok: true, write_enabled: true, master_key_configured: true, wompi: fixture.status,
      items: [...keys.map(key => {
        const row = { key, provider: 'wompi', field: key.toLowerCase(), stored: key === 'BRVTAL_TICKET_WOMPI_MODE' || stored.has(key),
          updated_at: '2026-11-23 08:00:00' };
        if (key === 'BRVTAL_TICKET_WOMPI_MODE') row.value = fixture.mode;
        if (key === 'BRVTAL_TICKET_WOMPI_ENABLED_METHODS') row.value = 'CARD,NEQUI,PSE';
        if (key === 'BRVTAL_TICKET_WOMPI_RETURN_URL') row.value = 'https://www.brvtal.com.co/mi-cuenta';
        return row;
      })],
    });
    window.fetch = async (path, options = {}) => {
      const pathname = new URL(path, location.href).pathname;
      const method = options.method || 'GET';
      if (pathname === '/api/index.php/auth') return json({ ok: true, authenticated: true, csrf: 'synthetic-csrf-token' });
      if (pathname === '/api/admin-integration-secrets.php') {
        if (method === 'POST') {
          const body = JSON.parse(options.body || '{}');
          fixture.writes.push(body);
          if (body.key === 'BRVTAL_TICKET_WOMPI_MODE' && body.value === 'production') {
            if (body.confirm !== 'COBROS_REALES') return json({ ok: false, error: 'WOMPI_PRODUCTION_CONFIRMATION_REQUIRED' }, 409);
            if (!prodComplete) return json({ ok: false, error: 'WOMPI_PRODUCTION_KEYS_INCOMPLETE' }, 409);
          }
          if (body.key === 'BRVTAL_TICKET_WOMPI_MODE') {
            fixture.mode = body.value;
            fixture.status = { mode: body.value, ready: body.value !== 'off', reason: body.value === 'off' ? 'WOMPI_DISABLED' : null };
          }
        }
        return json(payload());
      }
      if (pathname === '/api/admin-integration-test-mail.php') return json({ ok: false, error: 'MAIL_TEST_UNAVAILABLE' }, 503);
      if (pathname === '/discadmin/technical.php')
        return json({ ok: true, health: { status: 'unknown' }, database: { counts: {} }, repository: { github: { backlog_state: 'unavailable' } }, checks: [], issues: [] });
      if (pathname === '/api/content-health.php') return json({ ok: true, data: { score: 100 } });
      if (pathname === '/api/admin-activity.php') return json({ ok: true, data: { total: 0, items: [] } });
      if (pathname === '/discadmin/free-quotas.php') return json({ ok: true, data: { rows: [] } });
      if (pathname === '/api/admin-dashboard-preferences.php') return json({ ok: true, data: { modules: [] } });
      if (pathname === '/api/admin-system-status.php') return json({ ok: true, integrations: [], workers: [] });
      throw Error('Unexpected fixture request ' + method + ' ' + pathname);
    };
    window.go = section => {
      if (section === 'settings') document.getElementById('fixture').innerHTML = window.settingsHome();
    };
  }, { keys: WOMPI_KEYS, prodComplete });
  await page.addStyleTag({ content: styles });
  await page.addScriptTag({ content: source });
  await page.evaluate(() => { document.getElementById('fixture').innerHTML = window.settingsHome(); });
  const wompi = page.locator('[data-integration-provider="wompi"]');
  await expect(wompi).toBeVisible();
  return wompi;
}

test('Wompi separa llaves de pruebas y producción y muestra el modo efectivo', async ({ page }) => {
  const wompi = await setup(page);
  await expect(wompi.locator('.sv2-secret-group')).toHaveText(['Llaves de pruebas', 'Llaves de producción', 'Cobro']);
  await expect(wompi.locator('[data-secret-key="BRVTAL_TICKET_WOMPI_PROD_PUBLIC_KEY"]')).toHaveAttribute('placeholder', 'pub_prod_…');
  await expect(wompi.locator('[data-secret-key="BRVTAL_TICKET_WOMPI_PUBLIC_KEY"]')).toHaveAttribute('placeholder', 'pub_test_…');
  await expect(wompi.locator('[data-secret-key="BRVTAL_TICKET_WOMPI_ENABLED_METHODS"]')).toHaveValue('CARD,NEQUI,PSE');
  await expect(wompi.getByRole('radio')).toHaveCount(3);
  await expect(wompi.getByRole('radio', { name: /Pruebas/ })).toBeChecked();
  await expect(wompi.locator('[data-provider-state]')).toHaveText('Pruebas activas');
  await expect(wompi.locator('[data-wompi-status]')).toContainText('no son reales');
  // Stored keys are write-only: every key field renders empty, never prefilled.
  const keyValues = await wompi.locator('[data-secret-input][type="password"]').evaluateAll(inputs => inputs.map(input => input.value));
  expect(keyValues).toHaveLength(8);
  expect(keyValues.every(value => value === '')).toBe(true);
});

test('Producción pide confirmación y solo entonces activa cobros reales', async ({ page }, testInfo) => {
  for (const [width, height] of [[1440, 900], [390, 844]]) {
    await page.setViewportSize({ width, height });
    const wompi = await setup(page);
    await wompi.scrollIntoViewIfNeeded();
    await testInfo.attach(`wompi-mode-${width}`, { body: await wompi.screenshot(), contentType: 'image/png' });
  }
  const wompi = page.locator('[data-integration-provider="wompi"]');
  await wompi.getByRole('radio', { name: /Producción/ }).check();
  page.once('dialog', dialog => dialog.dismiss());
  await wompi.getByRole('button', { name: 'Aplicar modo' }).click();
  expect(await page.evaluate(() => window.wompiFixture.writes.length)).toBe(0);

  page.once('dialog', dialog => {
    expect(dialog.message()).toContain('cobros reales');
    dialog.accept();
  });
  await wompi.getByRole('button', { name: 'Aplicar modo' }).click();
  await expect(wompi.locator('[data-provider-state]')).toHaveText('Producción · cobros reales');
  await expect(wompi.locator('[data-secret-feedback]')).toContainText('cobros reales');
  const writes = await page.evaluate(() => window.wompiFixture.writes);
  expect(writes).toEqual([{ key: 'BRVTAL_TICKET_WOMPI_MODE', value: 'production', confirm: 'COBROS_REALES' }]);
});

test('Producción incompleta se rechaza con un motivo claro', async ({ page }) => {
  const wompi = await setup(page, { prodComplete: false });
  await wompi.getByRole('radio', { name: /Producción/ }).check();
  page.once('dialog', dialog => dialog.accept());
  await wompi.getByRole('button', { name: 'Aplicar modo' }).click();
  await expect(wompi.locator('[data-secret-feedback]')).toContainText('llaves de producción');
  await expect(wompi.locator('[data-provider-state]')).toHaveText('Pruebas activas');
});

test('AC-03: capturas reales antes/después de Wompi desde main y HEAD a 390/1440', async ({ page }, testInfo) => {
  const baseJs = beforeSource('discadmin/settings-v2.js');
  const baseCss = beforeSource('discadmin/settings-v2.css');
  for (const [width, height] of [[390, 844], [1440, 900]]) {
    await page.setViewportSize({ width, height });
    const before = await setup(page, { source: baseJs, styles: baseCss });
    const beforeBytes = await before.screenshot();
    await testInfo.attach('antes-wompi-' + width + 'px.png',
      { body: beforeBytes, contentType: 'image/png' });
    emitVisual('antes-' + width, beforeBytes);

    const after = await setup(page);
    await expect(after.locator('.sv2-secret-group'))
      .toHaveText(['Llaves de pruebas', 'Llaves de producción', 'Cobro']);
    const afterBytes = await after.screenshot();
    await testInfo.attach('despues-wompi-' + width + 'px.png',
      { body: afterBytes, contentType: 'image/png' });
    emitVisual('despues-' + width, afterBytes);
  }
});
