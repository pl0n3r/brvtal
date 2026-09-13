import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const appJs = readFileSync(join(process.cwd(), 'js/app.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-runtime-fallback-e2e.html';

for (const missing of ['GSAP', 'ScrollTrigger']) {
  test(`public navigation and CMS work without ${missing}`, async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/api/public.php', route => route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ok:true,data:{events:[{id:1,title:'CMS EVENT',status:'published'}],artists:[],sets:[],media:[],settings:{}}}),
    }));
    await page.route(harnessUrl, route => route.fulfill({
      contentType: 'text/html; charset=utf-8',
      body: `<!doctype html><html lang="en"><body>
        <div id="loader"></div><button id="menuToggle">MENU <strong>+</strong></button>
        <aside id="menuPanel" aria-hidden="true"><a href="#events">EVENTS</a></aside>
        <button id="soundToggle">SOUND <b>OFF</b></button>
        <span id="dynamicStatus"></span><div id="apiFallback"></div>
        <main><div class="events-track"></div></main>
        ${missing === 'ScrollTrigger' ? '<script>window.gsap={registerPlugin(){},ticker:{add(){},lagSmoothing(){}},from(){},to(){},fromTo(){}};</script>' : ''}
        <script>${appJs}</script>
      </body></html>`,
    }));

    await page.goto(harnessUrl);
    await expect(page.locator('#loader')).toHaveCount(0);
    await page.getByRole('button', {name:/MENU/}).click();
    await expect(page.locator('#menuPanel')).toHaveAttribute('aria-hidden', 'false');
    await expect(page.locator('.events-track')).toContainText('CMS EVENT');
    await expect(page.locator('#dynamicStatus')).toHaveText('LIVE / CMS CONNECTED');
    expect(errors).toEqual([]);
  });
}
