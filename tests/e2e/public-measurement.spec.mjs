import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const analyticsJs = readFileSync(join(process.cwd(), 'js/public-analytics.js'), 'utf8');
const measurementJs = readFileSync(join(process.cwd(), 'js/public-measurement.js'), 'utf8');
const url = 'http://127.0.0.1:4173/';
const privacyUrl = 'http://127.0.0.1:4173/?email=query-secret%40example.com&campaign=query-secret';
const fullUrl = 'http://127.0.0.1:4173/public-measurement-gtm-e2e.html';

function harness() {
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
  <body style="margin:0">
    <header class="nav"><button id="menuToggle" type="button">MENU</button><a class="brand" href="#top">BRVTAL</a></header>
    <aside class="menu-panel" id="menuPanel" aria-hidden="true"><a id="menuEvents" href="#events">EVENTS</a></aside>
    <main id="top">
      <section class="hero scene" style="height:100vh">HERO</section>
      <section class="events scene" id="events" style="height:100vh">EVENTS</section>
      <section style="height:2400px">
        <button id="declared" type="button" data-measure-event="brvtal_cta_click" data-measure-section="events" data-measure-action="open" data-measure-destination="/events" data-measure-content-title="GENESIS" data-measure-email="declared-secret@example.com">OPEN</button>
        <form id="privateForm">
          <input name="email" value="form-secret@example.com">
          <textarea name="notes">private-form-value</textarea>
        </form>
        <a id="outbound" href="https://soundcloud.com/brvtal/sets?token=outbound-query-secret#private">SOUNDCLOUD</a>
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

function fullHarness() {
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
  <body><main><section class="hero scene" style="height:100vh">HERO</section></main>
    <script data-gtm-id="GTM-W23PHGJG">${analyticsJs}</script>
    <script>${measurementJs}</script>
  </body></html>`;
}

async function open(page, targetUrl = url) {
  await page.route(targetUrl, route => route.fulfill({ contentType:'text/html; charset=utf-8', body:harness() }));
  await page.goto(targetUrl);
}

function measurementEvents(page) {
  return page.evaluate(() => (window.dataLayer || []).filter(item => item && String(item.event || '').startsWith('brvtal_')));
}

test('public measurement runs immediately even if a legacy rejected choice exists', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('brvtal.analytics.choice.v1', 'rejected'));
  await open(page);

  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_page_view')).toBe(true);
  expect(await page.evaluate(() => window.BRVTALMeasure.enabled())).toBe(true);
  expect(await page.evaluate(() => window.BRVTALMeasure.push('brvtal_manual_test', { section:'events' }))).toBe(true);
  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_manual_test')).toBe(true);
});

test('public measurement emits normalized signals without query strings or form values', async ({ page }) => {
  await open(page, privacyUrl);

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

  await page.locator('#outbound').click();
  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_outbound_click')).toBe(true);
  const outbound = (await measurementEvents(page)).find(item => item.event === 'brvtal_outbound_click');
  expect(outbound.destination).toBe('https://soundcloud.com/brvtal/sets');

  const emitted = JSON.stringify(await measurementEvents(page));
  for (const secret of ['query-secret', 'form-secret@example.com', 'private-form-value', 'outbound-query-secret', 'declared-secret@example.com']) {
    expect(emitted).not.toContain(secret);
  }

  await page.evaluate(() => scrollTo(0, document.documentElement.scrollHeight * 0.8));
  await expect.poll(async () => (await measurementEvents(page)).filter(item => item.event === 'brvtal_scroll_depth').map(item => item.depth)).toEqual(expect.arrayContaining([25, 50, 75]));
});

test('public measurement normalizes repeated trailing slashes before deriving entity context', async ({ page }) => {
  const entityUrl = 'http://127.0.0.1:4173/artists/pl0n3r////';
  await page.route(entityUrl, route => route.fulfill({ contentType:'text/html; charset=utf-8', body:harness() }));
  await page.goto(entityUrl);

  const context = await page.evaluate(() => window.BRVTALMeasure.context());
  expect(context).toMatchObject({ page_type:'artist', content_type:'artist', content_slug:'pl0n3r' });
});

test('GTM and measurement both start on the first page load', async ({ page }) => {
  let tagManagerRequests = 0;
  await page.route('https://www.googletagmanager.com/gtm.js**', route => {
    tagManagerRequests++;
    return route.fulfill({ contentType:'text/javascript', body:'' });
  });
  await page.route(fullUrl, route => route.fulfill({ contentType:'text/html; charset=utf-8', body:fullHarness() }));
  await page.goto(fullUrl);

  await expect.poll(() => tagManagerRequests).toBe(1);
  await expect.poll(async () => (await measurementEvents(page)).some(item => item.event === 'brvtal_page_view')).toBe(true);
  await expect(page.locator('.analytics-choice')).toHaveCount(0);
});
