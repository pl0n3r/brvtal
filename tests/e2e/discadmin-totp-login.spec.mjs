import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const totpLoginJs = readFileSync(join(process.cwd(), 'discadmin/totp-login.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/e2e-totp-login.html';

function harnessHtml() {
  return `<!doctype html><html><head><meta charset="utf-8"><title>BRVTAL TOTP E2E</title></head><body>
    <div id="app"></div>
    <script>
      async function bootstrap() {
        const auth = await fetch('/api/index.php/auth', { method:'GET', credentials:'same-origin' });
        const session = await auth.json();
        const app = document.getElementById('app');
        if (session.authenticated) {
          window.__bootstrappedCsrf = session.csrf || '';
          app.innerHTML = '<main id="discadmin-shell" data-authenticated="true"><h1>BRVTAL CMS</h1><p>SECURITY / 2FA</p></main>';
          return;
        }

        app.innerHTML = '<form id="login-form"><input id="email" type="email"><input id="password" type="password"><button id="login-submit" type="submit">LOGIN</button><p id="login-error"></p></form>';
        document.getElementById('login-form').addEventListener('submit', async (event) => {
          event.preventDefault();
          const response = await fetch('/api/index.php/auth', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            credentials:'same-origin',
            body:JSON.stringify({
              email:document.getElementById('email').value,
              password:document.getElementById('password').value
            })
          });
          const data = await response.json();
          if (!response.ok || !data.ok) document.getElementById('login-error').textContent = data.error || 'LOGIN_FAILED';
        });
      }
    </script>
    <script>${totpLoginJs}</script>
    <script>bootstrap();</script>
  </body></html>`;
}

async function routeHarness(page) {
  await page.route('**/discadmin/e2e-totp-login.html**', route => route.fulfill({
    contentType: 'text/html',
    body: harnessHtml()
  }));
}

test('TOTP login uses canonical auth route and reloads from authenticated session', async ({ page }) => {
  let authenticated = false;
  const requests = [];

  await routeHarness(page);
  await page.route('**/api/index.php/auth**', async route => {
    const request = route.request();
    const method = request.method();
    const url = new URL(request.url());
    let body = null;
    if (method === 'POST') body = request.postDataJSON();
    requests.push({ method, pathname: url.pathname, body });

    if (method === 'GET') {
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({ ok:true, authenticated, csrf:authenticated ? 'csrf-after-totp' : null })
      });
    }

    if (body?.action === 'totp_verify') {
      expect(body.code).toBe('123456');
      authenticated = true;
      return route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          ok:true,
          admin:{ id:1, name:'BRVTAL Admin', email:'admin@example.test' },
          csrf:'csrf-after-totp',
          totp_verified:true
        })
      });
    }

    if (body?.action === 'totp_cancel') {
      return route.fulfill({ contentType:'application/json', body:JSON.stringify({ ok:true }) });
    }

    expect(body).toMatchObject({ email:'admin@example.test', password:'correct-password' });
    return route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        ok:true,
        requires_totp:true,
        admin:{ id:1, name:'BRVTAL Admin', email:'admin@example.test' }
      })
    });
  });

  await page.goto(harnessUrl);
  await page.locator('#email').fill('admin@example.test');
  await page.locator('#password').fill('correct-password');
  await page.locator('#login-submit').click();

  const dialog = page.getByRole('dialog', { name:'2FA VERIFICATION' });
  await expect(dialog).toBeVisible();
  await expect(dialog).toHaveAttribute('aria-modal', 'true');
  await expect(page.locator('#brvtal-totp-code')).toBeFocused();
  await expect(page.getByRole('button', { name:'Use recovery code' })).toBeVisible();
  await page.locator('#brvtal-totp-code').fill('123456');
  await page.locator('#brvtal-totp-submit').click();

  await expect(page.locator('#discadmin-shell')).toBeVisible();
  await expect(page.locator('#discadmin-shell')).toHaveAttribute('data-authenticated', 'true');
  await expect(page.getByText('SECURITY / 2FA')).toBeVisible();
  expect(await page.evaluate(() => window.__bootstrappedCsrf)).toBe('csrf-after-totp');

  const passwordLogin = requests.find(entry => entry.method === 'POST' && entry.body?.email);
  const totpVerify = requests.find(entry => entry.method === 'POST' && entry.body?.action === 'totp_verify');
  const authenticatedBootstrap = requests.find((entry, index) => entry.method === 'GET' && index > requests.findIndex(item => item === totpVerify));

  expect(passwordLogin?.pathname).toBe('/api/index.php/auth');
  expect(totpVerify?.pathname).toBe('/api/index.php/auth');
  expect(authenticatedBootstrap?.pathname).toBe('/api/index.php/auth');
  expect(requests.filter(entry => entry.body?.action === 'totp_verify')).toHaveLength(1);
});

test('TOTP challenge traps focus, toggles recovery mode by keyboard and cancels with Escape', async ({ page }) => {
  let cancelRequests = 0;

  await routeHarness(page);
  await page.route('**/api/index.php/auth**', async route => {
    const request = route.request();
    const method = request.method();
    const body = method === 'POST' ? request.postDataJSON() : null;

    if (method === 'GET') {
      return route.fulfill({ contentType:'application/json', body:JSON.stringify({ ok:true, authenticated:false, csrf:null }) });
    }
    if (body?.action === 'totp_cancel') {
      cancelRequests += 1;
      return route.fulfill({ contentType:'application/json', body:JSON.stringify({ ok:true }) });
    }
    if (body?.action === 'totp_verify') {
      throw new Error('This regression should cancel before verification.');
    }
    return route.fulfill({
      contentType:'application/json',
      body:JSON.stringify({ ok:true, requires_totp:true, admin:{ id:1, name:'BRVTAL Admin', email:'admin@example.test' } })
    });
  });

  await page.goto(harnessUrl);
  await page.locator('#email').fill('admin@example.test');
  await page.locator('#password').fill('correct-password');
  const login = page.getByRole('button', { name:'LOGIN' });
  await login.click();

  const input = page.locator('#brvtal-totp-code');
  const recovery = page.getByRole('button', { name:'Use recovery code' });
  await expect(page.getByRole('dialog', { name:'2FA VERIFICATION' })).toBeVisible();
  await expect(input).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await expect(recovery).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(input).toBeFocused();
  await expect(input).toHaveAttribute('aria-label', 'Recovery code');
  await expect(input).toHaveAttribute('maxlength', '10');
  await expect(page.getByRole('button', { name:'Use authenticator code' })).toHaveAttribute('aria-pressed', 'true');

  await page.keyboard.press('Escape');
  await expect(page.locator('#brvtal-totp-overlay')).toHaveCount(0);
  await expect(login).toBeFocused();
  expect(cancelRequests).toBe(1);
});
