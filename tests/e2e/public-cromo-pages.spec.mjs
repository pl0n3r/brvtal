import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const css = ['css/public-entity.css', 'css/public-cromo.css', 'css/public-cromo-pages.css']
  .map(path => readFileSync(join(root, path), 'utf8')).join('\n');
// Generate the real shared navigation from PHP rather than duplicating its six links.
const topbar = execFileSync('php', ['-r',
  'require "config/public_cromo_chrome.php"; echo brvtalPublicCromoTopbar();',
], { cwd: root, encoding: 'utf8' });
const routes = [
  ['evento', 'GENESIS', '/events/genesis'],
  ['artista', 'ARTIST ONE', '/artists/artist-one'],
  ['privacidad', 'PRIVACIDAD Y MIS DERECHOS', '/privacy'],
  ['404', 'PÁGINA NO ENCONTRADA', '/missing'],
];
const picture = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800"><rect width="600" height="800" fill="#777"/></svg>';
function markup(kind, heading) {
  return '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<style>html,body{margin:0;padding:0}img{max-width:100%}</style><style>' + css + '</style></head>'
    + '<body class="cromo-page entity-page">' + topbar
    + '<main id="main-content"><article class="entity-hero">'
    + '<div class="entity-image"><img src="data:image/svg+xml,' + encodeURIComponent(picture) + '" alt="Póster ' + kind + '"></div>'
    + '<div class="entity-copy"><div class="entity-kicker">BRVTAL / ' + kind + '</div>'
    + '<h1 data-text="' + heading + '">' + heading + '</h1>'
    + '<div class="entity-actions"><a href="/">VOLVER AL INICIO</a></div></div></article>'
    + '<section class="entity-statement"><div class="entity-section-label">BRVTAL / ' + kind + '</div><p>Contenido público de prueba.</p></section>'
    + '</main></body></html>';
}
async function inspect(page, width, reduced, kind, heading, path) {
  await page.setViewportSize({ width, height: 900 });
  await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
  await page.route('http://127.0.0.1:4173' + path, route => route.fulfill({
    contentType: 'text/html; charset=utf-8', body: markup(kind, heading),
  }));
  await page.goto('http://127.0.0.1:4173' + path);
  await expect(page.locator('.entity-nav .cp-nav a')).toHaveCount(6);
  const expected = ['noches', 'artistas', 'sonido', 'memorias', 'journal', 'contacto'];
  for (const section of expected) {
    await expect(page.locator('.cp-nav a[href="/#' + section + '"]')).toHaveCount(1);
  }
  await expect(page.locator('.entity-nav [data-cromo-sound]')).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('.entity-image img')).toBeVisible();
  await expect(page.locator('.entity-hero h1')).toHaveAttribute('data-text', heading);
  const poster = await page.locator('.entity-image img').evaluate(el => ({
    filter: getComputedStyle(el).filter, fit: getComputedStyle(el).objectFit,
  }));
  expect(poster.filter).toContain('grayscale(1)');
  expect(poster.fit).toBe('cover');
  const metrics = await page.evaluate(() => ({
    width: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.width + 1);
  if (reduced) {
    const motion = await page.locator('.entity-hero h1').evaluate(el => ({
      before: getComputedStyle(el, '::before').animationName,
      after: getComputedStyle(el, '::after').animationName,
    }));
    expect(motion.before).toBe('none');
    expect(motion.after).toBe('none');
  }
}

for (const [kind, heading, path] of routes) {
  test(kind + ' uses the shared Cromo top bar, duotone poster and readable title at 390/1440', async ({ page }) => {
    for (const width of [390, 1440]) {
      await inspect(page, width, false, kind, heading, path);
      await inspect(page, width, true, kind, heading, path);
    }
  });
}
