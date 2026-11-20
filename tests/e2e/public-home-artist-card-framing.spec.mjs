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
      bio: '**FRAMED** hace *música*', is_collective_member: 1,
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
// Contract (#1795): the home card is the DISCADMIN «Vista de tarjeta / foto completa».
// The whole photo is shown (4:5 frame, object-fit:contain, no zoom); hero_framing is
// the artist page header framing and must not crop the card.
test('card shows the whole photo and ignores header framing at both widths', async ({ page }) => {
  for (const width of [1440, 390]) {
    await mount(page, fixture, width);
    for (const slug of ['framed', 'default']) {
      const card = page.locator('#artistas a[href="/artists/' + slug + '"]');
      const image = card.locator('.cr-ac-frame img.cr-photo');
      await expect(image).toHaveCSS('object-fit', 'contain');
      await expect(image).toHaveCSS('object-position', '50% 50%');
      await expect(image).toHaveCSS('transform', 'none');
      const ratio = await card.locator('.cr-ac-frame').evaluate(node => {
        const box = node.getBoundingClientRect();
        return box.width / box.height;
      });
      expect(ratio).toBeCloseTo(0.8, 2);
      const gradient = await card.evaluate(node => getComputedStyle(node, '::before').content);
      expect(gradient).toBe('none');
    }
    const noPhoto = page.locator('#artistas a[href="/artists/no-photo"]');
    await expect(noPhoto.locator('img')).toHaveCount(0);
    await expect(noPhoto.locator('.cr-orb')).toBeVisible();
    const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    expect(scrollWidth).toBeLessThanOrEqual(width + 1);
  }
});

test('name and membership sit under the photo and the card shows no biography', async ({ page }) => {
  for (const width of [1440, 390]) {
    await mount(page, fixture, width);
    const expected = { framed: 'MIEMBRO DEL COLECTIVO BRVTAL', default: 'ARTISTA INVITADO', 'no-photo': 'ARTISTA INVITADO' };
    for (const [slug, label] of Object.entries(expected)) {
      const card = page.locator('#artistas a[href="/artists/' + slug + '"]');
      await card.scrollIntoViewIfNeeded();
      const membership = card.locator('.cr-ac-cap .cr-artist-membership');
      await expect(membership).toHaveText(label);
      await expect(membership).toBeVisible();
      await expect(card.locator('.cr-rl')).toHaveCount(0);
      if (slug === 'no-photo') continue;
      const frame = await card.locator('.cr-ac-frame').boundingBox();
      const name = await card.locator('.cr-ac-cap .cr-nm').boundingBox();
      const tag = await membership.boundingBox();
      expect(name.y).toBeGreaterThanOrEqual(frame.y + frame.height - 1);
      expect(tag.y).toBeGreaterThanOrEqual(name.y + name.height - 1);
    }
    await expect(page.locator('#artistas')).not.toContainText('hace música');
  }
});

test('HTML-like names and biographies stay inert text', async ({ page }) => {
  await mount(page, {
    ...fixture,
    artists: [{ ...fixture.artists[0], name: '<img src=x onerror=alert(1)>', bio: '<img src=x onerror=alert(1)> **FRAMED**' }],
  });
  const card = page.locator('#artistas a[href="/artists/framed"]');
  await expect(card.locator('.cr-nm')).toContainText('<img src=x onerror=alert(1)>');
  await expect(card.locator('img')).toHaveCount(1);
  await expect(card.locator('.cr-rl')).toHaveCount(0);
});
