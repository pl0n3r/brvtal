import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const appJs = readFileSync(join(process.cwd(), 'js/app.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/public-language-selector-e2e.html';

const i18n = {
  version: 1,
  canonical_locale: 'es',
  default_locale: 'es',
  available_locales: ['es', 'en'],
  catalog: {
    es: {'nav.events':'EVENTOS','nav.contact':'CONTACTO'},
    en: {'nav.events':'EVENTS','nav.contact':'CONTACT'},
  },
  protected_terms: {},
};

async function installHarness(page) {
  await page.route('**/api/public.php', route => route.fulfill({
    contentType: 'application/json',
    body: JSON.stringify({
      ok: true,
      data: {events:[], artists:[], sets:[], media:[], settings:{i18n}},
    }),
  }));
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html lang="es"><body>
      <div id="loader"></div>
      <button id="menuToggle">MENU <strong>+</strong></button>
      <aside id="menuPanel" aria-hidden="true"></aside>
      <fieldset id="languageSelector"><legend>Idioma / Language</legend>
        <button type="button" data-locale="es" aria-pressed="true">ES</button>
        <button type="button" data-locale="en" aria-pressed="false">EN</button>
      </fieldset>
      <h2 data-i18n-key="nav.events">EVENTOS</h2>
      <a data-i18n-key="nav.contact">CONTACTO</a>
      <span data-current-locale>ES</span>
      <span id="dynamicStatus"></span><div id="apiFallback"></div>
      <div class="events-track"></div>
      <script>${appJs}</script>
    </body></html>`,
  }));
}

test('ES/EN switches without navigation and persists the explicit choice', async ({ page }) => {
  await installHarness(page);
  await page.goto(harnessUrl);
  await expect(page.locator('#dynamicStatus')).toHaveText('LIVE / CMS CONNECTED');
  await expect(page.locator('html')).toHaveAttribute('lang', 'es');
  await expect(page.locator('[data-i18n-key="nav.events"]')).toHaveText('EVENTOS');

  let navigations = 0;
  page.on('framenavigated', frame => {
    if (frame === page.mainFrame()) navigations += 1;
  });

  await page.getByRole('button', {name:'EN', exact:true}).click();
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await expect(page.locator('[data-i18n-key="nav.events"]')).toHaveText('EVENTS');
  await expect(page.locator('[data-current-locale]')).toHaveText('EN');
  await expect(page.getByRole('button', {name:'EN', exact:true})).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate(() => localStorage.getItem('brvtal.public.locale'))).toBe('en');
  expect(navigations).toBe(0);

  await page.reload();
  await expect(page.locator('#dynamicStatus')).toHaveText('LIVE / CMS CONNECTED');
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await expect(page.locator('[data-i18n-key="nav.events"]')).toHaveText('EVENTS');
  await expect(page.locator('[data-current-locale]')).toHaveText('EN');
});
