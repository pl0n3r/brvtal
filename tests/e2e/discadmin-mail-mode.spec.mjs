import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

// #1962: Integraciones → Resend has the mail switch Apagado / Pruebas / Real with the
// owner's privacy and DNS confirmations. The server decides; the UI shows its state.
const settingsSource = readFileSync(new URL('../../discadmin/settings-v2.js', import.meta.url), 'utf8');
const settingsCss = readFileSync(new URL('../../discadmin/settings-v2.css', import.meta.url), 'utf8');
const url = 'http://127.0.0.1:4173/discadmin/mail-fixture.html?settings=integrations';
const KEYS = ['API_KEY', 'DOMAIN', 'FROM', 'WEBHOOK_SECRET', 'TEST_RECIPIENTS', 'MODE', 'PRIVACY_APPROVED', 'DNS_VERIFIED']
  .map(suffix => 'BRVTAL_TICKET_MAIL_' + suffix);

async function setup(page, { source = settingsSource, styles = settingsCss } = {}) {
  await page.route('**/discadmin/mail-fixture.html?*', route => route.fulfill({
    contentType: 'text/html',
    body: '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      + '<body style="margin:0;background:#050606;color:#f6f6f4;font-family:Arial,sans-serif">'
      + '<div class="main" style="padding:18px;max-width:1500px;margin:auto"><div id="fixture"></div></div></body></html>',
  }));
  await page.goto(url);
  await page.evaluate(keys => {
    window.state = { rows: [] };
    window.csrf = 'synthetic-csrf-token';
    window.req = async () => ({ data: [] });
    const fixture = window.mailFixture = { mode: 'disabled', privacy: '0', dns: '0', writes: [],
      status: { mode: 'disabled', ready: false, reason: 'MAIL_DISABLED', pinned: false } };
    const json = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
    const payload = () => ({ ok: true, write_enabled: true, master_key_configured: true,
      wompi: { mode: 'sandbox', ready: true, reason: null }, mail: fixture.status,
      items: keys.map(key => {
        const row = { key, provider: 'resend', field: key.toLowerCase(), stored: true, updated_at: '2026-11-24 08:00:00' };
        if (key.endsWith('_MODE')) row.value = fixture.mode;
        if (key.endsWith('_PRIVACY_APPROVED')) row.value = fixture.privacy;
        if (key.endsWith('_DNS_VERIFIED')) row.value = fixture.dns;
        if (key.endsWith('_DOMAIN')) row.value = 'brvtal.com.co';
        if (key.endsWith('_FROM')) row.value = 'boletas@brvtal.com.co';
        return row;
      }) });
    window.fetch = async (path, options = {}) => {
      const pathname = new URL(path, location.href).pathname;
      const method = options.method || 'GET';
      if (pathname === '/api/index.php/auth') return json({ ok: true, authenticated: true, csrf: 'synthetic-csrf-token' });
      if (pathname === '/api/admin-integration-secrets.php') {
        if (method === 'POST') {
          const body = JSON.parse(options.body || '{}');
          fixture.writes.push(body);
          if (body.key === 'BRVTAL_TICKET_MAIL_MODE') {
            if (body.value !== 'disabled' && body.privacy !== true) return json({ ok: false, error: 'MAIL_PRIVACY_REQUIRED' }, 409);
            if (body.value === 'live' && body.dns !== true) return json({ ok: false, error: 'MAIL_DNS_REQUIRED' }, 409);
            if (body.value === 'live' && body.confirm !== 'ENVIO_REAL') return json({ ok: false, error: 'MAIL_LIVE_CONFIRMATION_REQUIRED' }, 409);
            fixture.mode = body.value;
            fixture.privacy = body.privacy ? '1' : fixture.privacy;
            fixture.dns = body.dns ? '1' : '0';
            fixture.status = { mode: body.value, ready: body.value !== 'disabled',
              reason: body.value === 'disabled' ? 'MAIL_DISABLED' : null, pinned: false };
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
  }, KEYS);
  await page.addStyleTag({ content: styles });
  await page.addScriptTag({ content: source });
  await page.evaluate(() => { document.getElementById('fixture').innerHTML = window.settingsHome(); });
  const resend = page.locator('[data-integration-provider="resend"]');
  await expect(resend.locator('[data-mail-mode]')).toBeVisible();
  return resend;
}

test('Resend muestra el interruptor de correo con el modo efectivo del servidor', async ({ page }, testInfo) => {
  for (const [width, height] of [[1440, 900], [390, 844]]) {
    await page.setViewportSize({ width, height });
    const resend = await setup(page);
    const mode = resend.locator('[data-mail-mode]');
    await expect(mode.getByRole('radio')).toHaveCount(3);
    await expect(mode.getByRole('radio', { name: /Apagado/ })).toBeChecked();
    await expect(resend.locator('[data-mail-status]')).toHaveText('El envío está apagado: no sale ningún correo.');
    await expect(mode.getByRole('checkbox', { name: /política de privacidad/ })).not.toBeChecked();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await mode.scrollIntoViewIfNeeded();
    await testInfo.attach(`correo-interruptor-${width}`, { body: await mode.screenshot(), contentType: 'image/png' });
  }
});

test('Pruebas exige confirmar la privacidad y luego queda activo', async ({ page }) => {
  const resend = await setup(page);
  const mode = resend.locator('[data-mail-mode]');
  await mode.getByRole('radio', { name: /Pruebas/ }).check();
  await resend.locator('[data-mail-mode-apply]').click();
  await expect(resend.locator('[data-secret-feedback]')).toHaveText('Falta confirmar que la política de privacidad cubre compras y cuentas.');
  await mode.getByRole('checkbox', { name: /política de privacidad/ }).check();
  await resend.locator('[data-mail-mode-apply]').click();
  await expect(resend.locator('[data-mail-status]')).toContainText('Pruebas activas');
  const last = await page.evaluate(() => window.mailFixture.writes.at(-1));
  expect(last).toEqual({ key: 'BRVTAL_TICKET_MAIL_MODE', value: 'test', privacy: true, dns: false });
});

test('Real pide la confirmación explícita y envía ENVIO_REAL', async ({ page }) => {
  const resend = await setup(page);
  const mode = resend.locator('[data-mail-mode]');
  await mode.getByRole('radio', { name: /Real/ }).check();
  await mode.getByRole('checkbox', { name: /política de privacidad/ }).check();
  await mode.getByRole('checkbox', { name: /verificado en Resend/ }).check();
  page.once('dialog', dialog => dialog.dismiss());
  await resend.locator('[data-mail-mode-apply]').click();
  expect(await page.evaluate(() => window.mailFixture.writes.length)).toBe(0); // cancelled: nothing sent
  page.once('dialog', dialog => dialog.accept());
  await resend.locator('[data-mail-mode-apply]').click();
  await expect(resend.locator('[data-mail-status]')).toContainText('Envío real activo');
  const last = await page.evaluate(() => window.mailFixture.writes.at(-1));
  expect(last).toEqual({ key: 'BRVTAL_TICKET_MAIL_MODE', value: 'live', privacy: true, dns: true, confirm: 'ENVIO_REAL' });
});
