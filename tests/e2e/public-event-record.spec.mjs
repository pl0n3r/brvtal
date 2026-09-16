import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const entityCss = readFileSync(join(process.cwd(), 'css/public-entity.css'), 'utf8');
const recordCss = readFileSync(join(process.cwd(), 'css/public-event-record.css'), 'utf8');

function fixture(state = 'historical') {
  const historical = state === 'historical';
  const ticket = historical ? '' : '<a href="https://tickets.example.com">TICKETS ↗</a>';
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${entityCss}</style><style>${recordCss}</style></head>
  <body class="entity-page entity-page--event entity-page--event-${state}" data-event-record-state="${state}" style="--event-signal:#b6ff00">
    <header class="entity-nav"><a class="entity-brand">BRVTAL<small>RAVE TILL GRAVE</small></a><a href="#events">${historical ? '← BACK TO ARCHIVE' : '← BACK TO EVENTS'}</a></header>
    <main id="main-content">
      <article class="entity-hero">
        <div class="entity-image"><div style="width:100%;height:100%;background:#111"></div><span>${historical ? 'EVENT RECORD' : 'EVENT'} / BRVTAL</span></div>
        <div class="entity-copy"><div class="entity-kicker">BRVTAL / ${historical ? 'EVENT RECORD' : 'EVENT'} / 014</div><h1 data-text="GENESIS: INDUSTRIAL SIGNAL">GENESIS: INDUSTRIAL SIGNAL</h1><div class="entity-facts"><div><span>DATE</span><b>14.08.2026 / 21:00</b></div><div><span>LOCATION</span><b>PEREIRA / COLOMBIA</b></div><div><span>STATUS</span><b>${historical ? 'FINISHED' : 'TICKETS AVAILABLE'}</b></div>${historical ? '<div><span>RECORD</span><b>ARCHIVE / 2026</b></div>' : ''}</div><div class="entity-actions">${ticket}<a href="#network">EXPLORE CONNECTIONS ↗</a></div></div>
      </article>
      <section class="event-record-band"><div><span>${historical ? 'EVENT RECORD / FINISHED' : 'EVENT SIGNAL / TICKETS AVAILABLE'}</span><strong>${historical ? 'ARCHIVE / 2026' : 'ACTIVE EXPERIENCE / 2026'}</strong></div><p>CANONICAL EVENT FILE / BRVTAL</p></section>
      <section class="entity-statement"><div class="entity-section-label">${historical ? 'RECORD / INFORMATION' : 'ABOUT / EXPERIENCE'}</div><p>BRVTAL x RANDOM KORE / underground electronic culture from Pereira.</p></section>
      <section class="entity-related"><div class="entity-section-label">${historical ? 'RECORDED SETS' : 'LINEUP'} / 02</div><div class="entity-grid"><a class="entity-card" href="#a"><span class="entity-card-mark">BRVTAL</span><span><small>SOUNDCLOUD</small><strong>PL0N3R / GENESIS LIVE</strong></span></a><a class="entity-card" href="#b"><span class="entity-card-mark">BRVTAL</span><span><small>16.08.2026</small><strong>GENESIS TRANSMISSION</strong></span></a></div></section>
    </main>
  </body></html>`;
}

test('historical Event Record is visibly archival while staying BRVTAL', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.setContent(fixture('historical'));
  await expect(page.locator('body')).toHaveAttribute('data-event-record-state', 'historical');
  await expect(page.getByText('EVENT RECORD / FINISHED')).toBeVisible();
  await expect(page.getByText('ARCHIVE / 2026').last()).toBeVisible();
  await expect(page.locator('.event-record-band')).toHaveCSS('display', 'grid');
  await expect(page.locator('.entity-actions')).not.toContainText('TICKETS');
  const signal = await page.locator('body').evaluate(el => getComputedStyle(el).getPropertyValue('--event-signal').trim());
  expect(signal.toLowerCase()).toBe('#b6ff00');
});

test('active Event keeps a clear ticket action and active lifecycle framing', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.setContent(fixture('active'));
  await expect(page.getByRole('link', { name: 'TICKETS ↗' })).toBeVisible();
  await expect(page.getByText('EVENT SIGNAL / TICKETS AVAILABLE')).toBeVisible();
  await expect(page.getByText('← BACK TO EVENTS')).toBeVisible();
});

test('Event Record stays touch-safe and has no horizontal overflow on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(fixture('active'));
  const metrics = await page.evaluate(() => ({
    width: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    actions: [...document.querySelectorAll('.entity-actions a')].map(el => el.getBoundingClientRect().height),
    back: document.querySelector('.entity-nav > a:last-child')?.getBoundingClientRect().height || 0,
    bandWidth: document.querySelector('.event-record-band')?.getBoundingClientRect().width || 0,
  }));
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.width + 1);
  expect(Math.min(...metrics.actions)).toBeGreaterThanOrEqual(44);
  expect(metrics.back).toBeGreaterThanOrEqual(44);
  expect(metrics.bandWidth).toBeLessThanOrEqual(metrics.width + 1);
});
