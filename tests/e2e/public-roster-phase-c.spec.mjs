import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = [
  'css/style.css',
  'css/public-roster.css',
].map(path => readFileSync(join(process.cwd(), path), 'utf8')).join('\n');

const markup = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>
<section class="artists scene" id="artists">
  <div class="section-head"><span class="mono">BRVTAL ROSTER / 03</span><h2>ROSTER</h2><span class="mono">CORE / ALUMNI / ARTISTS</span></div>
  <div class="artist-list">
    <section class="roster-group roster-group--active" data-roster-group="active">
      <div class="roster-group-head mono"><span>CORE / ACTIVE</span><b>01</b></div>
      <a class="artist artist--active" href="/artists/pl0n3r"><span>01</span><strong>PL0N3R</strong><i>BRVTAL / ACTIVE · SINCE 2026</i></a>
    </section>
    <section class="roster-group roster-group--alumni" data-roster-group="alumni">
      <div class="roster-group-head mono"><span>ALUMNI / ARCHIVE</span><b>01</b></div>
      <a class="artist artist--alumni" href="/artists/archive-signal"><span>02</span><strong>ARCHIVE SIGNAL</strong><i>BRVTAL / ALUMNI · 2025—2026</i></a>
    </section>
    <section class="roster-group roster-group--network" data-roster-group="network">
      <div class="roster-group-head mono"><span>ARTISTS / COLLABORATORS</span><b>01</b></div>
      <a class="artist artist--network" href="/artists/long-collaborator-name"><span>03</span><strong>VERY LONG COLLABORATOR NAME FOR MOBILE</strong><i>ARTIST / COLLABORATOR</i></a>
    </section>
  </div>
  <div class="artist-preview"></div><div class="artist-crosshair"></div>
</section>
</body></html>`;

test('Roster groups membership states and keeps canonical profile links', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.setContent(markup);
  await expect(page.locator('[data-roster-group="active"]')).toContainText('CORE / ACTIVE');
  await expect(page.locator('[data-roster-group="alumni"]')).toContainText('ALUMNI / ARCHIVE');
  await expect(page.locator('[data-roster-group="network"]')).toContainText('ARTISTS / COLLABORATORS');
  await expect(page.locator('.artist--active')).toHaveAttribute('href', '/artists/pl0n3r');
});

test('Roster stays readable and touch-safe on mobile without horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(markup);

  const dimensions = await page.locator('.artist').evaluateAll(nodes => nodes.map(node => ({
    height: node.getBoundingClientRect().height,
    right: node.getBoundingClientRect().right,
    left: node.getBoundingClientRect().left,
  })));
  for (const item of dimensions) {
    expect(item.height).toBeGreaterThanOrEqual(44);
    expect(item.left).toBeGreaterThanOrEqual(0);
    expect(item.right).toBeLessThanOrEqual(390.5);
  }

  const pageMetrics = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(pageMetrics.scrollWidth).toBeLessThanOrEqual(pageMetrics.viewport);
  await expect(page.locator('.artist--network strong')).toBeVisible();
  await expect(page.locator('.artist-preview')).toBeHidden();
});
