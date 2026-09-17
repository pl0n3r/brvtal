import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const analyticsJs = readFileSync(join(process.cwd(), 'js/public-analytics.js'), 'utf8');
const measurementJs = readFileSync(join(process.cwd(), 'js/public-measurement.js'), 'utf8');
const url = 'http://127.0.0.1:4173/';
const consentUrl = 'http://127.0.0.1:4173/public-measurement-consent-e2e.html';

function harness() {
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
  <body style="margin:0">
    <header class="nav"><button id="menuToggle" type="button">MENU</button><a class="brand" href="#top">BRVTAL</a></header>
    <aside class="menu-panel" id="menuPanel" aria-hidden="true"><a id="menuEvents" href="#events">EVENTS</a></aside>
    <main id="top">
      <section class="hero scene" style="height:100vh">HERO</section>
      <section class="events scene" id="events" style="height:100vh">EVENTS</section>
      <section style="height:2400px">
        <button id="declared" type="button" data-measure-event="brvtal_cta_click" data-measure-section="events" data-measure-action="open" data-measure-destination="/events" data-measure-content-title="GENESIS" data-measure-email="never-send@example.com">OPEN</button>
        <a id="outbound" href="https://soundcloud.com/brvtal/sets">SOUNDCLOUD</a>
      </section>
    </main>
    <script>
      document.getElementById('menuToggle').addEventListener('click', () => {
        const panel = document.getElementById('menuPanel');
        panel.setAttribute('aria-hidden', panel.getAttribute('aria-hidden') === 'false' ? 'true' : 'false');
      });
      document.addEventListener('click', event => {
        if (event.target.closest('a')) event.preventDefault();
      });
    </script>
    <script>${measurementJs}</script>
  </body></html>`;
}

function consentHarness() {
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
  <body><main><section class="hero scene" style="height:100vh">HERO</section></main>
    <script data-gtm-id="GTM-W23PHGJG">${analyticsJs}</script>
    <script>${measurementJs}</script>
  </body></html>`;
}

async function open(page, choice) {
  await page.addInitScript(value => localStorage.setItem('brvtal.analytics.choice.v1', value), choice);
  await page.route(url, route => route.fulfill({ contentType:'text/html; charset=utf-8', body:harness() }));
  await page.goto(url);
}

function measurementEvents(page) {
  return page.evaluate(() => (window.dataLayer || []).filter(item => item && String(item.event || '').startsWith('brvtal_')));
}

test('public measurement stays silent without accepted analytics consent', async ({ page }) => {
  await open(page, 'rejected');
  await page.getByRole('button', { name:'MENU' }).click();
  await page.locator('#declared').click();
  await page.locator('#outbound').click();
  await page.evaluate(() => scrollTo(0, document.documentElement.scrollHeight));
  await page.waitForTimeout(50);

  expect(await measurementEvents(page)).toEqual([]);
  expect(await page.evaluate(() => window.BRVTALMeasure.enabled())).toBe(false);
  expect(await page.evaluate(() => window.BRVTALMeasure.push('brvtal_manual_test', { section:'events' }))).toBe(false);
});

test('accepted visitors emit normalized page, section, navigation, action and scroll signals', async ({ page }) => {
  await open(page, 'accepted');

  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_page_view')).toBe(true);
  const pageView = (await measurementEvents(page)).find(item => item.event === 'brvtal_page_view');
  expect(pageView.page_type).toBe('home');

  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_section_view' && item.section === 'hero')).toBe(true);

  await page.getByRole('button', { name:'MENU' }).click();
  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_menu_toggle' && item.action === 'open')).toBe(true);

  await page.locator('#menuEvents').click();
  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_navigation_click' && item.destination === '#events' && item.source === 'menu')).toBe(true);

  await page.locator('#declared').click();
  const declared = (await measurementEvents(page)).find(item => item.event === 'brvtal_cta_click');
  expect(declared).toMatchObject({ section:'events', action:'open', destination:'/events', content_title:'GENESIS' });
  expect(declared.email).toBeUndefined();

  await page.locator('#outbound').click();
  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_outbound_click' && item.destination === 'https://soundcloud.com/brvtal/sets')).toBe(true);

  await page.evaluate(() => scrollTo(0, document.documentElement.scrollHeight * 0.8));
  await expect.poll(async () => (await measurementEvents(page)).filter(item => item.event === 'brvtal_scroll_depth').map(item => item.depth)).toEqual(expect.arrayContaining([25, 50, 75]));

  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:analytics-settings-open')));
  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_analytics_settings_open')).toBe(true);
});

test('accepting analytics starts measurement and records the consent action only after opt-in', async ({ page }) => {
  let tagManagerRequests = 0;
  await page.route('https://www.googletagmanager.com/gtm.js**', route => {
    tagManagerRequests++;
    return route.fulfill({ contentType:'text/javascript', body:'' });
  });
  await page.route(consentUrl, route => route.fulfill({ contentType:'text/html; charset=utf-8', body:consentHarness() }));
  await page.goto(consentUrl);

  expect(await measurementEvents(page)).toEqual([]);
  expect(tagManagerRequests).toBe(0);

  await page.getByRole('button', { name:'ALLOW ANALYTICS' }).click();
  await expect.poll(() => tagManagerRequests).toBe(1);
  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_page_view')).toBe(true);
  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_analytics_consent' && item.action === 'accepted')).toBe(true);
});
