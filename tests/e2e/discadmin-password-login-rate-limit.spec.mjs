import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const reliabilityJs = readFileSync(join(process.cwd(), 'discadmin/admin-reliability.js'), 'utf8');
const apiIndexPhp = readFileSync(join(process.cwd(), 'api/index.php'), 'utf8');
const rateLimitPhp = readFileSync(join(process.cwd(), 'config/password_rate_limit.php'), 'utf8');
const rateStorePhp = readFileSync(join(process.cwd(), 'config/rate_limit_store.php'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/password-login-rate-limit-e2e.html';

test('password auth records only failures and resets pressure after a valid password', async () => {
  expect(apiIndexPhp).toContain("require_once __DIR__ . '/../config/password_rate_limit.php';");

  const authStart = apiIndexPhp.indexOf("$email=strtolower(trim((string)($d['email']??'')))");
  const authEnd = apiIndexPhp.indexOf("method_not_allowed();", authStart);
  expect(authStart).toBeGreaterThan(-1);
  expect(authEnd).toBeGreaterThan(authStart);
  const authBlock = apiIndexPhp.slice(authStart, authEnd);

  const initialCheck = authBlock.indexOf('rate_limit_login($email);');
  const passwordVerify = authBlock.indexOf('password_verify(');
  const failureRecord = authBlock.indexOf('rate_limit_login($email,true);');
  const reset = authBlock.indexOf('reset_login_rate_limit($email);');
  const totpChallenge = authBlock.indexOf('brvtal_totp_pending_set(');

  expect(initialCheck).toBeGreaterThan(-1);
  expect(passwordVerify).toBeGreaterThan(initialCheck);
  expect(failureRecord).toBeGreaterThan(passwordVerify);
  expect(reset).toBeGreaterThan(failureRecord);
  expect(totpChallenge).toBeGreaterThan(reset);
  expect(apiIndexPhp).not.toContain("$data['attempts'][] = $now;");

  expect(rateLimitPhp).toContain("require_once __DIR__ . '/rate_limit_store.php';");
  expect(rateLimitPhp).toContain('BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES = 5');
  expect(rateLimitPhp).toContain('function brvtal_password_rate_limit_failure(');
  expect(rateLimitPhp).toContain("$data['attempts'][] = $now;");
  expect(rateLimitPhp).toContain('flock($lock, LOCK_EX)');
  expect(rateLimitPhp).toContain('brvtal_rate_limit_store_write($file, $data)');
  expect(rateLimitPhp).toContain('function brvtal_password_rate_limit_reset(');
  expect(rateLimitPhp).toContain("brvtal_rate_limit_store_write($file, ['attempts' => [], 'blocked_until' => 0])");

  expect(rateStorePhp).toContain("return $file . '.lock';");
  expect(rateStorePhp).toContain("fopen($temporary, 'x+b')");
  expect(rateStorePhp).toContain('while ($offset < $length)');
  expect(rateStorePhp).toContain('if ($ok && !fflush($handle)) $ok = false;');
  expect(rateStorePhp).toContain('@rename($temporary, $file)');
});

test('password login shows rate limit, invalid credentials and server failures distinctly', async ({ page }) => {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html><head><meta charset="utf-8"></head><body>
      <form id="login-form" onsubmit="login(event)">
        <input name="email" value="admin@brvtal.test">
        <input name="password" value="secret">
        <button type="submit">ENTER</button>
        <div class="error"></div>
      </form>
      <script>
        let csrf='';
        let state={authed:false};
        window.__loginCode='RATE_LIMITED';
        async function req(){ throw new Error(window.__loginCode); }
        async function go(section){ window.__went=section; }
        async function login(event){ event.preventDefault(); }
      </script>
      <script>${reliabilityJs}</script>
    </body></html>`,
  }));

  await page.goto(harnessUrl);
  const form = page.locator('#login-form');
  const error = page.locator('.error');
  const button = page.getByRole('button', { name: 'ENTER' });

  await form.evaluate(element => element.requestSubmit());
  await expect(error).toContainText('Demasiados intentos');
  await expect(error).toContainText('temporalmente limitado');
  await expect(button).toBeEnabled();

  await page.evaluate(() => { window.__loginCode = 'INVALID_CREDENTIALS'; });
  await form.evaluate(element => element.requestSubmit());
  await expect(error).toHaveText('Credenciales inválidas.');

  await page.evaluate(() => { window.__loginCode = 'INTERNAL_ERROR'; });
  await form.evaluate(element => element.requestSubmit());
  await expect(error).toHaveText('No se pudo iniciar sesión. Inténtalo de nuevo.');
  await expect(error).not.toContainText('Credenciales inválidas');
});
