import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const root = process.cwd();
const baseline = 'bfde3ca92a8d0094f01d80b8918ca05ce60d55eb';
const beforeSource = execFileSync('git', ['show', baseline + ':discadmin/settings-v2.js'], {
  cwd: root, encoding: 'utf8', timeout: 20000
});
const afterSource = readFileSync(join(root, 'discadmin/settings-v2.js'), 'utf8');
const styles = readFileSync(join(root, 'discadmin/settings-v2.css'), 'utf8');
const path = join(root, 'artifacts', 'issue-1875-visual');
const url = 'http://127.0.0.1:4173/discadmin/qa-1875.html?settings=integrations';

test('AC-04: cuatro capturas Chromium de Settings Integraciones antes/después 390 y 1440', async ({ page }) => {
  mkdirSync(path, { recursive: true });
  const generated = [];
  const requests = [];
  await page.route('**/*', async route => {
    const current = new URL(route.request().url());
    if (current.pathname !== '/discadmin/qa-1875.html') {
      requests.push(current.pathname);
      return route.abort();
    }
    return route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>' +
        '<body style="margin:0;background:#080a0b;color:#f2ede6;font:14px Arial,sans-serif">' +
        '<div style="padding:12px 18px;background:#2a1d1a;border-bottom:1px solid #5f3730;color:#ffe2c6;font-size:12px">' +
        'FIXTURE SINTÉTICA · NO PRODUCCIÓN · BRVTAL #1875</div>' +
        '<main class="main" style="padding:12px 16px"><div id="fixture"></div></main></body></html>'
    });
  });
  for (const width of [390, 1440]) {
    for (const phase of ['before', 'after']) {
      await page.setViewportSize({width, height:900});
      await page.goto(url);
      await page.evaluate(() => {
        window.state = {rows:[]};
        window.csrf = 'synthetic-csrf-1875';
        window.req = async () => ({data:[]});
        window.fetch = async (resource, options = {}) => {
          const pathname = new URL(String(resource), location.href).pathname;
          if (pathname === '/api/admin-integration-secrets.php') {
            return new Response(JSON.stringify({ok:true,write_enabled:true,
              master_key_configured:true,items:[]}),
              {status:200,headers:{'Content-Type':'application/json'}});
          }
          if (pathname === '/api/index.php/auth') {
            return new Response(JSON.stringify({authenticated:true,csrf:'synthetic-csrf-1875'}),
              {status:200,headers:{'Content-Type':'application/json'}});
          }
          throw new Error('Blocked synthetic fixture request: ' + pathname);
        };
      });
      await page.addStyleTag({content:styles});
      await page.addScriptTag({content:phase === 'before' ? beforeSource : afterSource});
      await page.evaluate(() => {document.getElementById('fixture').innerHTML=window.settingsHome();});
      const card = page.locator('[data-integration-provider="resend"]');
      await expect(card).toBeVisible();
      const count = phase === 'after' ? 1 : 0;
      await expect(card.locator('[data-mail-test-send]')).toHaveCount(count);
      await expect(card.locator('[data-mail-allowlist]')).toHaveCount(count);
      expect(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth)).toBe(false);
      expect(await page.locator('body').innerText()).not.toContain('re_SYNTHETIC_TEST_ONLY');
      const file = phase + '-' + width + '.png';
      await page.screenshot({path:join(path,file),fullPage:true});
      generated.push({file,phase,viewport_width:width,viewport_height:900});
    }
  }
  expect(requests).toEqual([]);
  const head=execFileSync('git',['rev-parse','HEAD'],{cwd:root,encoding:'utf8'}).trim();
  writeFileSync(join(path,'manifest.json'),JSON.stringify({
    issue:1875,pr:1896,head,baseline,generated,
    before_source:'exact Git baseline discadmin/settings-v2.js at ' + baseline,
    after_source:'current PR HEAD discadmin/settings-v2.js',
    browser:'Playwright Chromium',synthetic_fixture:true,
    production_observed:false,real_credentials_used:false,external_requests:0,
    design_review:'1 shell, existing Resend card, 390px no overflow, 1440px hierarchy; test button and encrypted allowlist added, no live activation'
  },null,2)+'\n');
});
