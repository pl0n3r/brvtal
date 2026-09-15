import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = [
  'css/style.css',
  'css/archive.css',
  'css/public-media.css',
  'css/public-entity.css',
].map(path => readFileSync(join(process.cwd(), path), 'utf8')).join('\n');

async function fontSize(locator) {
  return locator.evaluate(element => Number.parseFloat(getComputedStyle(element).fontSize));
}

async function height(locator) {
  return locator.evaluate(element => element.getBoundingClientRect().height);
}

test('public mobile discovery controls remain readable and touch friendly', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>
    <section class="event-archive">
      <div class="archive-discovery">
        <label class="archive-search"><span>SEARCH HISTORY</span><input data-archive-search type="search"></label>
        <div class="archive-relations"><button type="button">ALL RECORDS</button></div>
      </div>
      <div class="archive-years"><button type="button">ALL YEARS</button></div>
      <article class="archive-event"><div class="archive-event-copy"><div class="archive-event-meta">2026 <span>PEREIRA</span></div><div class="archive-event-relations">5 ARTISTS / 1 SET</div><a class="archive-event-link" href="#archive">VIEW NIGHT</a></div></article>
    </section>

    <section class="public-media-tools">
      <label><span>SEARCH MEDIA</span><input type="search" data-media-search></label>
      <div class="public-media-types"><button type="button">ALL MEDIA</button></div>
    </section>
    <div class="public-media-count">3 RECORDS</div>
    <button class="public-media-close" type="button">CLOSE</button>
    <div class="public-media-viewer-controls"><button type="button">PREVIOUS</button><button type="button">NEXT</button></div>

    <nav class="entity-nav"><a class="entity-brand" href="#">BRVTAL<small>RAVE TILL GRAVE</small></a><a href="#back">BACK TO BRVTAL</a></nav>
    <main><section class="entity-hero"><div class="entity-copy"><span class="entity-kicker">EVENT</span><div class="entity-facts"><div><span>STATUS</span><b>SOLD OUT</b></div></div><div class="entity-actions"><a href="#tickets">TICKETS</a></div></div></section><section class="entity-related"><div class="entity-grid"><a class="entity-card" href="#related"><span class="entity-card-mark">BRV</span><span><small>RELATED</small><strong>SESSION</strong></span></a></div></section></main>
    <footer>BRVTAL</footer>
  </body></html>`);

  await expect.poll(() => fontSize(page.locator('[data-archive-search]'))).toBeGreaterThanOrEqual(16);
  await expect.poll(() => height(page.locator('[data-archive-search]'))).toBeGreaterThanOrEqual(44);
  await expect.poll(() => height(page.locator('.archive-relations button'))).toBeGreaterThanOrEqual(44);
  await expect.poll(() => height(page.locator('.archive-years button'))).toBeGreaterThanOrEqual(44);
  await expect.poll(() => fontSize(page.locator('.archive-event-meta'))).toBeGreaterThanOrEqual(11);
  await expect.poll(() => height(page.locator('.archive-event-link'))).toBeGreaterThanOrEqual(44);

  await expect.poll(() => fontSize(page.locator('[data-media-search]'))).toBeGreaterThanOrEqual(16);
  await expect.poll(() => height(page.locator('[data-media-search]'))).toBeGreaterThanOrEqual(44);
  await expect.poll(() => height(page.locator('.public-media-types button'))).toBeGreaterThanOrEqual(44);
  await expect.poll(() => height(page.locator('.public-media-close'))).toBeGreaterThanOrEqual(44);
  await expect.poll(() => height(page.locator('.public-media-viewer-controls button').first())).toBeGreaterThanOrEqual(44);
  await expect.poll(() => fontSize(page.locator('.public-media-count'))).toBeGreaterThanOrEqual(11);

  await expect.poll(() => height(page.locator('.entity-nav > a:last-child'))).toBeGreaterThanOrEqual(44);
  await expect.poll(() => fontSize(page.locator('.entity-nav > a:last-child'))).toBeGreaterThanOrEqual(11);
  await expect.poll(() => height(page.locator('.entity-actions a'))).toBeGreaterThanOrEqual(44);
  await expect.poll(() => fontSize(page.locator('.entity-facts span'))).toBeGreaterThanOrEqual(11);
  await expect.poll(() => fontSize(page.locator('.entity-card small'))).toBeGreaterThanOrEqual(11);
});

test('long Next Experience titles keep readable mobile line spacing', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>
    <section class="genesis scene">
      <div class="genesis-copy">
        <div class="eyebrow mono">NEXT EXPERIENCE / BRVTAL</div>
        <h2 data-text="UMBRAL 03 // NUCLEO NEURAL + MAREA CIEGA">UMBRAL 03 // NUCLEO NEURAL + MAREA CIEGA</h2>
        <p class="genesis-tag">BRVTAL EXPERIENCE.</p>
        <div class="genesis-data mono"><span>21.11.2026</span><span>22:00</span><span>BOGOTA / BODEGA 17</span></div>
        <a class="enter" href="#events">ENTER EXPERIENCE <span>↗</span></a>
      </div>
    </section>
  </body></html>`);

  const metrics = await page.locator('.genesis-copy h2').evaluate(element => {
    const style = getComputedStyle(element);
    const rect = element.getBoundingClientRect();
    const tagRect = element.nextElementSibling.getBoundingClientRect();
    const fontSize = Number.parseFloat(style.fontSize);
    const lineHeight = Number.parseFloat(style.lineHeight);
    return {
      fontSize,
      lineHeight,
      right: rect.right,
      bottom: rect.bottom,
      tagTop: tagRect.top,
      viewportWidth: window.innerWidth,
    };
  });

  expect(metrics.fontSize).toBeLessThan(90);
  expect(metrics.lineHeight / metrics.fontSize).toBeGreaterThanOrEqual(0.88);
  expect(metrics.right).toBeLessThanOrEqual(metrics.viewportWidth);
  expect(metrics.tagTop - metrics.bottom).toBeGreaterThanOrEqual(10);
});
