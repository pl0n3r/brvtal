import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// Disposable fixture exercises the real home runtime without production data.
const root = process.cwd();
const css = ['css/public-cromo.css', 'css/public-cromo-home.css']
  .map(path => readFileSync(join(root, path), 'utf8')).join('\n');
const runtime = readFileSync(join(root, 'js/public-cromo-home.js'), 'utf8');
const url = 'http://127.0.0.1:4173/home-artist-framing-e2e.html';
let fixtureId = 0;
const crop = (zoom, x, y) => ({ zoom, x, y });
const fixture = {
  events: [], sets: [], releases: [], memories: [], blog: [],
  archive: { events: [] }, relations: {},
  artists: [
    {
      id: 101, slug: 'framed', name: 'FRAMED', photo: '/uploads/framed.svg',
      bio: '**FRAMED** hace *música*',
      hero_framing: { desktop: crop(1.5, 30, 20), mobile: crop(1.5, 30, 20) },
    },
    { id: 102, slug: 'default', name: 'DEFAULT', photo: '/uploads/framed.svg', bio: 'Bio' },
    { id: 103, slug: 'no-photo', name: 'NO PHOTO', bio: 'Sin fotografía' },
  ],
};
async function mount(page, data = fixture, width = 1440) {
  await page.setViewportSize({ width, height: 900 });
  await page.route('**/api/public.php', r => r.fulfill({
    contentType: 'application/json', body: JSON.stringify({ ok: true, data }),
  }));
  await page.route('**/api/hero-slider.php', r => r.fulfill({
    contentType: 'application/json', body: JSON.stringify({ ok: true, data: { enabled: false, slides: [] } }),
  }));
  await page.route('**/uploads/framed.svg', r => r.fulfill({
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="400"><rect width="200" height="400" fill="navy"/></svg>',
  }));
  await page.route(url + '*', r => r.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
      + '<style>html,body{margin:0;padding:0}</style><style>' + css + '</style></head>'
      + '<body><main id="legacy">FALLBACK</main></body></html>',
  }));
  await page.goto(url + '?case=' + (++fixtureId));
  await page.addScriptTag({ content: runtime });
  await expect(page.locator('#cromo-home')).toBeAttached();
}
test('photo fills card, honors stored 150% / 30% / 20% framing, defaults and orb at both widths', async ({ page }) => {
  for (const width of [1440, 390]) {
    await mount(page, fixture, width);
    const framed = page.locator('#artistas a[href="/artists/framed"] img.cr-photo');
    await expect(framed).toHaveCSS('object-fit', 'cover');
    await expect(framed).toHaveCSS('object-position', '30% 20%');
    await expect(framed).toHaveCSS('transform', 'matrix(1.5, 0, 0, 1.5, 0, 0)');
    const fallback = page.locator('#artistas a[href="/artists/default"] img.cr-photo');
    await expect(fallback).toHaveCSS('object-fit', 'cover');
    await expect(fallback).toHaveCSS('object-position', '50% 50%');
    await expect(fallback).toHaveCSS('transform', 'matrix(1, 0, 0, 1, 0, 0)');
    const noPhoto = page.locator('#artistas a[href="/artists/no-photo"]');
    await expect(noPhoto.locator('img')).toHaveCount(0);
    await expect(noPhoto.locator('.cr-orb')).toBeVisible();
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    expect(scrollWidth).toBeLessThanOrEqual(width + 1);
  }
});
test('artist biography displays plain text rather than Markdown markers or links', async ({ page }) => {
  await mount(page, {
    ...fixture,
    artists: [{ ...fixture.artists[0], bio: '**FRAMED** no cree en [etiquetas](https://example.org) ni _límites_.' }],
  });
  const bio = page.locator('#artistas a[href="/artists/framed"] .cr-rl');
  await expect(bio).toHaveText('FRAMED no cree en etiquetas ni límites.');
  await expect(bio.locator('a')).toHaveCount(0);
});
test('HTML-like biography remains inert text, not executable markup', async ({ page }) => {
  await mount(page, {
    ...fixture,
    artists: [{ ...fixture.artists[0], bio: '<img src=x onerror=alert(1)> **FRAMED**' }],
  });
  const bio = page.locator('#artistas a[href="/artists/framed"] .cr-rl');
  await expect(bio).toContainText('<img src=x onerror=alert(1)> FRAMED');
  await expect(bio.locator('img')).toHaveCount(0);
});

test('invalid framing values are rejected and mobile viewport can use its own crop', async ({ page }) => {
  await mount(page, {
    ...fixture, artists: [{
      ...fixture.artists[0], hero_framing: {
        desktop: { zoom: 'invalid', x: 200, y: 20 },
        mobile: crop(2, 70, 60),
      },
    }],
  });
  const image = page.locator('#artistas a[href="/artists/framed"] img.cr-photo');
  await expect(image).toHaveCSS('object-position', '50% 50%');
  await expect(image).toHaveCSS('transform', 'matrix(1, 0, 0, 1, 0, 0)');
  await page.setViewportSize({ width: 390, height: 900 });
  await expect(image).toHaveCSS('object-position', '70% 60%');
  await expect(image).toHaveCSS('transform', 'matrix(2, 0, 0, 2, 0, 0)');
});
