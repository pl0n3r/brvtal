import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const html = readFileSync(join(root, 'buyer/login.php'), 'utf8');
const security = readFileSync(join(root, 'config/buyer_auth.php'), 'utf8');
const api = readFileSync(join(root, 'api/buyer/auth.php'), 'utf8');
const pageUrl = 'http://127.0.0.1:4173/buyer/login.php';

test('buyer security primitives never share admin cookie or bypass pending schema', () => {
  expect(security).toContain("BRVTAL_BUYER_SESSION_NAME = 'BRVTAL_BUYER'");
  expect(security).toContain("session.use_strict_mode");
  expect(security).toContain('session_regenerate_id(true)');
  expect(security).toContain("samesite' => 'Strict'");
  expect(security).toContain('hash_equals(');
  expect(security).toContain('password_hash(');
  expect(security).toContain('random_bytes(32)');
  expect(api).toContain('brvtalBuyerSchemaReady($pdo)');
  expect(api).toContain("'BUYER_SCHEMA_PENDING'");
  expect(api).toContain("'CSRF_INVALID'");
  expect(api).not.toContain('admin_id');
});

test('mobile buyer login has accessible wait and error states with fake mail/network', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 750 });
  let sends = 0;
  let csrfHeader = '';
  await page.route(pageUrl, route => route.fulfill({contentType:'text/html',body:html}));
  await page.route('**/api/buyer/auth.php', async route => {
    if (route.request().method() === 'GET') {
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,csrf:'test-csrf'})});
    }
    sends++;
    csrfHeader = route.request().headers()['x-csrf-token'];
    await route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,error:'BUYER_SCHEMA_PENDING'})});
  });
  await page.goto(pageUrl);
  await expect(page.getByRole('heading', {name:'Cuenta de comprador'})).toBeVisible();
  await page.getByLabel('Correo electrónico').fill('buyer@example.test');
  await page.getByLabel('Contraseña').fill('a-synthetic-password');
  await page.getByRole('button',{name:'Entrar'}).click();
  await expect(page.getByRole('status')).toContainText('No se pudo iniciar sesión');
  await expect(page.getByLabel('Correo electrónico')).toBeFocused();
  expect(sends).toBe(1);
  expect(csrfHeader).toBe('test-csrf');
  await expect(page.getByRole('button',{name:'Entrar'})).toBeEnabled();
});
