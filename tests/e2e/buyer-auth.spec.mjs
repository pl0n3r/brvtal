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
  // Unknown identities still run the password verifier, avoiding a cheap timing oracle.
  expect(security).not.toContain('BRVTAL_BUYER_DUMMY_PASSWORD_HASH');
  expect(api).toContain('password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)');
  expect(api).toContain('password_verify($password, $storedHash)');
});

test('mobile buyer login announces unavailability without inventing access', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 750 });
  let sends = 0;
  let csrfHeader = '';
  await page.route(pageUrl, route => route.fulfill({ contentType: 'text/html', body: html }));
  await page.route('**/api/buyer/auth.php', async route => {
    if (route.request().method() === 'GET') {
      return route.fulfill({ contentType: 'application/json',
        body: JSON.stringify({ ok: true, csrf: 'test-csrf' }) });
    }
    sends++;
    csrfHeader = route.request().headers()['x-csrf-token'];
    await route.fulfill({ status: 503, contentType: 'application/json',
      body: JSON.stringify({ ok: false, error: 'BUYER_SCHEMA_PENDING' }) });
  });
  await page.goto(pageUrl);
  await expect(page.getByRole('heading', { name: /Entra a tu cuenta/i })).toBeVisible();
  await page.locator('#entrar-email').fill('buyer@example.test');
  await page.locator('#entrar-password').fill('a-synthetic-password');
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.getByRole('status')).toContainText('El servicio no está disponible');
  await expect(page.getByRole('status')).toHaveAttribute('aria-live', 'polite');
  expect(sends).toBe(1);
  expect(csrfHeader).toBe('test-csrf');
  await expect(page.getByRole('button', { name: 'Entrar' })).toBeEnabled();
});

test('mobile buyer login stays pending until reply, then navigates to Mis compras', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 750 });
  let release;
  const gate = new Promise(resolve => { release = resolve; });
  await page.route(pageUrl, route => route.fulfill({ contentType: 'text/html', body: html }));
  await page.route('**/mi-cuenta', route => route.fulfill({
    contentType: 'text/html', body: '<!doctype html><html lang="es"><body><h1>Mis compras</h1></body></html>'
  }));
  await page.route('**/api/buyer/auth.php', async route => {
    if (route.request().method() === 'GET') {
      return route.fulfill({ contentType: 'application/json',
        body: JSON.stringify({ ok: true, csrf: 'fixture' }) });
    }
    await gate;
    return route.fulfill({ contentType: 'application/json',
      body: JSON.stringify({ ok: true, csrf: 'rotated' }) });
  });
  await page.goto(pageUrl);
  await page.locator('#entrar-email').fill('fixture@example.test');
  await page.locator('#entrar-password').fill('synthetic-password-for-test');
  await page.getByRole('button', { name: 'Entrar' }).click({ noWaitAfter: true });
  await expect(page.getByRole('button', { name: 'Entrar' })).toBeDisabled();
  await expect(page.getByRole('status')).toContainText('Comprobando los datos');
  release();
  await expect(page).toHaveURL('http://127.0.0.1:4173/mi-cuenta');
  await expect(page.getByRole('heading', { name: 'Mis compras' })).toBeVisible();
});


test('AC-02 #1985: enlace de compra configura contraseña y acceso a pedidos a 390/1440', async ({page}, info) => {
  const purchase = readFileSync(join(root,'buyer/reset.php'),'utf8');
  const login = readFileSync(join(root,'buyer/login.php'),'utf8');
  const token = 'A'.repeat(43);
  for (const width of [390,1440]) {
    await page.setViewportSize({width,height:900});
    const previous='http://127.0.0.1:4173/buyer/reset.php?accion=confirm-reset&token='+token;
    const purchaseLink=previous+'&origen=compra';
    let confirmed=0;
    await page.route('**/buyer/reset.php*', route=>route.fulfill({contentType:'text/html',body:purchase}));
    await page.route('**/mi-cuenta/acceso*', route=>route.fulfill({contentType:'text/html',body:login}));
    await page.route('**/mi-cuenta', route=>route.fulfill({
      contentType:'text/html',body:'<!doctype html><html lang="es"><body><h1>Mis compras</h1><p>Pedido confirmado</p></body></html>'
    }));
    await page.route('**/api/buyer/auth.php',async route=>{
      if(route.request().method()==='GET')return route.fulfill({
        contentType:'application/json',body:JSON.stringify({ok:true,csrf:'synthetic-csrf'})
      });
      const submitted=route.request().postDataJSON();
      if(submitted.action==='confirm-reset'){
        expect(submitted.token).toBe(token);
        expect(submitted.password).toBe('Synthetic-Guest-1985-Long');
        expect(route.request().headers()['x-csrf-token']).toBe('synthetic-csrf');
        confirmed++;
      }
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true})});
    });
    await page.goto(previous);
    await expect(page.getByRole('heading',{name:'Nueva contraseña.'})).toBeVisible();
    await info.attach('1985-before-'+width,{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await page.goto(purchaseLink);
    await expect(page.getByRole('heading',{name:'Crea tu contraseña.'})).toBeVisible();
    await expect(page.getByRole('button',{name:/Crear contraseña/})).toBeVisible();
    await info.attach('1985-after-'+width,{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    const dimensions=await page.evaluate(()=>({
      client:document.documentElement.clientWidth,
      scroll:document.documentElement.scrollWidth
    }));
    expect(dimensions.scroll).toBeLessThanOrEqual(dimensions.client);
    await page.locator('#reset-new-password').fill('Synthetic-Guest-1985-Long');
    await page.locator('#reset-confirm-password').fill('Synthetic-Guest-1985-Long');
    await page.getByRole('button',{name:/Crear contraseña/}).click();
    await expect(page.getByRole('status')).toContainText('Contraseña creada.');
    expect(confirmed).toBe(1);
    await page.getByRole('link',{name:'Entrar a mi cuenta'}).click();
    await page.locator('#entrar-email').fill('guest-1985@example.test');
    await page.locator('#entrar-password').fill('Synthetic-Guest-1985-Long');
    await page.getByRole('button',{name:'Entrar',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Mis compras'})).toBeVisible();
    await page.unrouteAll({behavior:'ignoreErrors'});
  }
});
