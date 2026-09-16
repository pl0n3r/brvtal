import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = [
  'css/style.css',
  'css/public-home-phase-a.css',
].map(path => readFileSync(join(process.cwd(), path), 'utf8')).join('\n');

const markup = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>
  <section class="hero scene home-phase-a-hero">
    <div class="hero-grid"></div><div class="hero-scan"></div><div class="hero-glitch-lines"></div>
    <div class="hero-copy"><div class="eyebrow mono">UNDERGROUND ELECTRONIC CULTURE / PEREIRA / COLOMBIA</div><h1 class="hero-title" data-text="BRVTAL">BRVTAL</h1><div class="hero-sub"><span>RAVE TILL GRAVE</span><span>EST. 2026</span></div><div class="hero-declaration"><span class="mono">BRVTAL / CULTURAL SIGNAL</span><strong>EVENTS / SOUND / ARTISTS / ARCHIVE</strong><p>BUILT IN PEREIRA. CONNECTED THROUGH UNDERGROUND ELECTRONIC CULTURE.</p></div></div>
    <div class="hero-logo-wrap"></div><div class="hero-bottom mono"><span>01 / 07</span><span>SCROLL TO ENTER</span><span>NOISE / SIGNAL / MUSIC</span></div>
  </section>
  <section class="genesis scene home-phase-a-experience">
    <div class="genesis-copy"><div class="eyebrow mono">NEXT EXPERIENCE / BRVTAL</div><h2 data-text="UMBRAL 03 // NUCLEO NEURAL + MAREA CIEGA">UMBRAL 03 // NUCLEO NEURAL + MAREA CIEGA</h2><p class="genesis-tag">LAST TICKETS.</p><div class="genesis-data mono"><span>21.11.2026</span><span>22:00</span><span>PEREIRA / WAREHOUSE 09</span></div><div class="experience-lineup"><span class="mono">LINEUP</span><p>PL0N3R <i>/</i> DNL5 <i>/</i> HAKKI</p></div><div class="experience-actions"><a class="enter" href="#event">ENTER EXPERIENCE <span>↗</span></a><a class="ticket-cta" href="#tickets">TICKETS <span>↗</span></a></div></div>
  </section>
</body></html>`;

test('Phase A keeps the cultural statement visible on desktop', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.setContent(markup);

  await expect(page.locator('.hero-declaration')).toBeVisible();
  await expect(page.locator('.hero-declaration strong')).toContainText('EVENTS / SOUND / ARTISTS / ARCHIVE');
  await expect(page.locator('.hero-copy .eyebrow')).toContainText('PEREIRA / COLOMBIA');
  await expect(page.locator('.ticket-cta')).toBeVisible();

  const accent = await page.locator('.hero-declaration').evaluate(el => getComputedStyle(el).borderLeftColor);
  expect(accent).not.toBe('rgba(0, 0, 0, 0)');
});

test('Phase A prioritizes event info and actions without mobile overflow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(markup);

  const ticketHeight = await page.locator('.ticket-cta').evaluate(el => el.getBoundingClientRect().height);
  const enterHeight = await page.locator('.experience-actions .enter').evaluate(el => el.getBoundingClientRect().height);
  expect(ticketHeight).toBeGreaterThanOrEqual(44);
  expect(enterHeight).toBeGreaterThanOrEqual(44);

  const metrics = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.viewport);

  await expect(page.locator('.genesis-data')).toContainText('PEREIRA / WAREHOUSE 09');
  await expect(page.locator('.experience-lineup')).toContainText('PL0N3R');
});
