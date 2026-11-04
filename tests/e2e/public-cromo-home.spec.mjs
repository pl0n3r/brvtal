import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// All reads are disposable: the real Cromo runtime runs against fixed API fixtures.
const root = process.cwd();
const css = ['css/public-cromo.css', 'css/public-cromo-home.css']
  .map(path => readFileSync(join(root, path), 'utf8')).join('\n');
const runtime = readFileSync(join(root, 'js/public-cromo-home.js'), 'utf8');
const url = 'http://127.0.0.1:4173/cromo-home-e2e.html';
const event = { id: 7, slug: 'genesis', title: 'GENESIS', event_date: '2099-08-14 21:00:00', city: 'Pereira', venue: 'Warehouse' };
const artist = { id: 2, slug: 'artist-one', name: 'ARTIST ONE', is_collective_member: true };
const set = { id: 3, slug: 'genesis-set', title: 'GENESIS SET', artist_name: 'ARTIST ONE' };
const archive = { id: 8, slug: 'past-signal', title: 'PAST SIGNAL', event_date: '2025-08-01 21:00:00', city: 'Pereira' };
const data = {
  events: [event], artists: [artist], sets: [set], releases: [], memories: [], blog: [],
  archive: { events: [archive] },
  relations: { events: { 7: { artists: [2], sets: [3] } }, artists: { 2: { events: [7], sets: [3] } } },
};
const slider = {
  enabled: true, autoplay: true, interval: 2500,
  slides: [
    { desktopSrc: '/banner-a.svg', title: 'BANNER A', transition: 'fade' },
    { desktopSrc: '/banner-b.svg', title: 'BANNER B', transition: 'fade' },
  ],
};
let fixtureSequence = 0;
async function mount(page, { payload = data, banners = slider, hash = '', reduced = false, width = 1440 } = {}) {
  await page.setViewportSize({ width, height: 900 });
  await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
  await page.route('**/api/public.php', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: payload }) }));
  await page.route('**/api/hero-slider.php', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, data: banners }) }));
  await page.route('**/api/contact.php', route => route.fulfill({ status: 503, contentType: 'application/json', body: '{}' }));
  await page.route(url + '*', route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{margin:0;padding:0}</style><style>' + css + '</style></head><body><main id="legacy">FALLBACK</main></body></html>',
  }));
  // A fragment-only navigation does not reload the document or reinitialize Cromo.
  // Give every fixture a distinct URL so legacy-hash checks exercise a fresh mount.
  await page.goto(url + '?fixture=' + (++fixtureSequence) + hash);
  await page.addScriptTag({ content: runtime });
  await expect(page.locator('#cromo-home')).toBeAttached();
}
async function noOverflow(page) {
  const metrics = await page.evaluate(() => ({ width: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth }));
  expect(metrics.scroll).toBeLessThanOrEqual(metrics.width + 1);
}

test('Destacados advances manually, pauses and resumes the managed banners', async ({ page }) => {
  await mount(page);
  const slides = page.locator('#destacados .cr-bn-slide');
  await expect(slides).toHaveCount(2);
  await expect(slides.nth(0)).toHaveClass(/cr-is-active/);
  await page.getByRole('button', { name: 'Banner siguiente' }).click();
  await expect(slides.nth(1)).toHaveClass(/cr-is-active/);
  const pause = page.getByRole('button', { name: 'Pausar los banners' });
  await pause.click();
  await expect(page.getByRole('button', { name: 'Reanudar los banners' })).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('#destacados .cr-bn-stage')).toHaveClass(/cr-is-paused/);
  await page.getByRole('button', { name: 'Reanudar los banners' }).click();
  await expect(page.getByRole('button', { name: 'Pausar los banners' })).toHaveAttribute('aria-pressed', 'false');
});

test('reduced motion disables autoplay and permits deliberate navigation', async ({ page }) => {
  await page.clock.install();
  await mount(page, { reduced: true });
  const slides = page.locator('#destacados .cr-bn-slide');
  await page.clock.fastForward(6000);
  await expect(slides.nth(0)).toHaveClass(/cr-is-active/);
  await page.getByRole('button', { name: 'Banner siguiente' }).click();
  await expect(slides.nth(1)).toHaveClass(/cr-is-active/);
  const animation = await page.locator('#cromo-home .cr-glitch').first().evaluate(el => getComputedStyle(el, '::before').animationName);
  expect(animation).toBe('none');
});

test('Conectado selects published relations, highlights linked items and changes the sentence', async ({ page }) => {
  await mount(page);
  const eventButton = page.locator('#conectado [data-key="e7"]');
  const artistButton = page.locator('#conectado [data-key="a2"]');
  const setButton = page.locator('#conectado [data-key="s3"]');
  await expect(eventButton).toHaveAttribute('aria-pressed', 'true');
  await expect(artistButton).toHaveClass(/cr-is-linked/);
  await expect(setButton).toHaveClass(/cr-is-linked/);
  await artistButton.click();
  await expect(artistButton).toHaveAttribute('aria-pressed', 'true');
  await expect(eventButton).toHaveClass(/cr-is-linked/);
  await expect(page.locator('#conectado .cr-cn2-sentence')).toContainText('GENESIS');
  await expect(page.locator('#conectado .cr-cn2-actions a')).toHaveAttribute('href', '/artists/artist-one');
});

test('Últimas noches and legacy hashes land on the archive; empty data stays safe at both sizes', async ({ page }) => {
  for (const width of [390, 1440]) {
    await mount(page, { width, hash: '#archive' });
    await expect(page.locator('#archivo .cr-pst')).toHaveCount(1);
    await expect(page.locator('#archivo .cr-pst')).toHaveAttribute('href', '/events/past-signal');
    await expect.poll(() => new URL(page.url()).hash).toBe('#archivo');
    await noOverflow(page);
    await mount(page, { width, hash: '#events' });
    await expect.poll(() => new URL(page.url()).hash).toBe('#noches');
    await mount(page, { width, payload: { events: [], artists: [], sets: [], memories: [], blog: [], archive: { events: [] }, relations: {} }, banners: { enabled: false, slides: [] } });
    await expect(page.locator('#cromo-home')).toBeVisible();
    await expect(page.locator('#destacados')).toHaveCount(0);
    await expect(page.locator('#archivo')).toHaveCount(0);
    await expect(page.locator('#conectado')).toBeVisible();
    await noOverflow(page);
  }
});

test('Memorias muestra completa la imagen vertical del CMS en móvil y escritorio', async ({ page }) => {
  const portrait = {
    id: 9, media_id: 9, type: 'image',
    title: 'sample-artist-two', file_path: '/uploads/portrait-memory.svg',
  };
  await page.route('**/uploads/portrait-memory.svg', route => route.fulfill({
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="900"><rect width="600" height="900" fill="#202734"/><rect width="600" height="60" fill="#f22"/><rect y="840" width="600" height="60" fill="#29f"/></svg>',
  }));
  for (const width of [390, 1440]) {
    await mount(page, { width, payload: { ...data, memories: [portrait] } });
    const card = page.locator('#memorias .cr-mem .cr-t.cr-has-img');
    await expect(card).toHaveCount(1);
    await expect(card).toBeVisible();
    const frame = await card.evaluate(node => {
      const backdrop = getComputedStyle(node, '::before');
      const rect = node.getBoundingClientRect();
      return {
        size: backdrop.backgroundSize.split(',')[0].trim(),
        color: backdrop.backgroundColor,
        image: backdrop.backgroundImage,
        width: rect.width,
        height: rect.height,
      };
    });
    expect(frame.size).toBe('contain');
    expect(frame.color).toBe('rgb(7, 8, 11)');
    expect(frame.image).toContain('/uploads/portrait-memory.svg');
    expect(frame.width).toBeGreaterThan(0);
    if (width === 390) expect(frame.height).toBeGreaterThanOrEqual(380);
    await noOverflow(page);

    await page.locator('#memorias .cr-chip').filter({ hasText: 'Video' }).click();
    await expect(card).toBeHidden();
    await page.locator('#memorias .cr-chip').filter({ hasText: 'Imágenes' }).click();
    await expect(card).toBeVisible();
  }
});
