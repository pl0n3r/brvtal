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


test('Social & Contact shows five CMS links after contact form at 390 and 1440', async ({ page }) => {
  const social = {
    instagram: 'https://www.instagram.com/brvtal/',
    soundcloud: 'https://soundcloud.com/brvtal',
    youtube: 'https://www.youtube.com/@brvtal',
    spotify: 'https://open.spotify.com/artist/1234',
    website: 'https://brvtal.example.org/contact?ref=home',
  };
  const labels = ['Instagram', 'SoundCloud', 'YouTube', 'Spotify', 'Sitio web'];
  for (const width of [390, 1440]) {
    await mount(page, { width, payload: { ...data, settings: { social } } });
    const links = page.locator('#contacto .cr-social-link');
    await expect(links).toHaveCount(5);
    await expect(page.locator('#contacto .cr-social h2')).toHaveText('Enlaces oficiales');
    await expect(page.locator('#contacto form')).toBeVisible();
    await expect(page.locator('#contacto form input[name="email"]')).toHaveCount(1);
    await expect(page.locator('#contacto form button[type="submit"]')).toHaveCount(1);
    for (let i = 0; i < 5; i++) {
      const link = links.nth(i);
      await expect(link).toContainText(labels[i]);
      await expect(link).toHaveAttribute('href', Object.values(social)[i]);
      await expect(link).toHaveAttribute('target', '_blank');
      await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
      const size = await link.evaluate(n => ({ width: n.getBoundingClientRect().width, height: n.getBoundingClientRect().height }));
      expect(size.width).toBeGreaterThanOrEqual(44);
      expect(size.height).toBeGreaterThanOrEqual(44);
    }
    await links.first().focus();
    await expect(links.first()).toBeFocused();
    expect(await links.first().evaluate(n => getComputedStyle(n).outlineStyle)).toBe('solid');
    await noOverflow(page);
  }
});

test('Social & Contact filters malformed and unsafe values, supports legacy keys and missing data', async ({ page }) => {
  const social = {
    instagram: 'javascript:alert(1)',
    instagram_url: 'https://instagram.com/old-destination',
    soundcloud_url: 'https://soundcloud.com/legacy-profile',
    youtube: '/relative/path',
    spotify: 'https://user:secret@open.spotify.com/artist/1',
    website: 'https://host.example.com\\@attacker.invalid',
  };
  await mount(page, { width: 390, payload: { ...data, settings: { social } } });
  let links = page.locator('#contacto .cr-social-link');
  await expect(links).toHaveCount(1);
  await expect(links).toHaveAttribute('href', 'https://soundcloud.com/legacy-profile');
  await noOverflow(page);
  await mount(page, { payload: { ...data, settings: { social: {
    instagram: 'data:text/html,<script>alert(1)</script>',
    soundcloud: 'https://', youtube: 'http://@example.com',
    spotify: 'https://bad.example.com/with space', website: 'mailto:hello@example.com',
  } } } });
  await expect(page.locator('#contacto .cr-social')).toHaveCount(0);
  await expect(page.locator('#contacto form')).toBeVisible();
  await mount(page, { payload: { ...data, settings: {} } });
  await expect(page.locator('#contacto .cr-social')).toHaveCount(0);
  await mount(page, { payload: { ...data, settings: { social: {
    website_url: 'https://example.org/?q=%3Cimg%20onerror%3Dalert(1)%3E',
  } } } });
  links = page.locator('#contacto .cr-social-link');
  await expect(links).toHaveCount(1);
  await expect(page.locator('#contacto .cr-social img')).toHaveCount(0);
  await expect(links).toHaveAttribute('target', '_blank');
  await noOverflow(page);
});


// #1520 — exercise the real Cromo CMS mount at the viewport edges, without
// replacing content hooks, desktop layout, or Concept 05 fallback.
test('Experiencia Cromo apila poster y ficha en 375/390/430/900, conserva escritorio y funciona sin portada', async ({ page }) => {
  await page.route('**/fixtures/cromo-experience-cover.svg', route => route.fulfill({
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="#23131b"/></svg>',
  }));
  const longTitle = 'CEREMONIA INDUSTRIAL PSICOTICA DE VELOCIDAD Y DISTORSION CON NOMBRE EXTREMADAMENTE LARGO';
  const fullEvent = {
    ...event, title: longTitle, cover_image: '/fixtures/cromo-experience-cover.svg',
    ticket_url: 'https://tickets.example.org/genesis', venue: 'UN ALMACEN INDUSTRIAL CON UBICACION EXTREMADAMENTE LARGA',
    description: 'Experiencia editorial para probar márgenes y legibilidad sin mutar información de CMS.',
  };
  for (const width of [375, 390, 430, 900, 1440]) {
    for (const cover of [true, false]) {
      await mount(page, {
        width, reduced: width === 390,
        payload: { ...data, events: [{ ...fullEvent, cover_image: cover ? fullEvent.cover_image : '' }] },
        banners: { enabled: false, slides: [] },
      });
      const card = page.locator('#experiencia .cr-xp');
      await expect(card).toBeVisible();
      await expect(card.locator('.cr-xp-body h3')).toHaveText(longTitle);
      await expect(page.locator('#cromo-home .cr-top .cr-snd')).toHaveAccessibleName(/Activar sonido|Apagar el pulso/);
      await expect(card.locator('.cr-xp-fly')).toHaveCount(cover ? 1 : 0);
      const metrics = await card.evaluate(node => {
        const rect = element => element ? element.getBoundingClientRect().toJSON() : null;
        const panel = node.querySelector('.cr-xp-body');
        const flyer = node.querySelector('.cr-xp-fly');
        const header = document.querySelector('#cromo-home .cr-top');
        const brand = header.querySelector('a.cr-u');
        const sound = header.querySelector('button.cr-snd');
        const tickets = header.querySelector('a.cr-tickets');
        const facts = node.querySelector('.cr-xp-facts');
        const title = node.querySelector('h3');
        return {
          columns: getComputedStyle(node).gridTemplateColumns.trim().split(/\s+/).length,
          card: rect(node), panel: rect(panel), flyer: rect(flyer),
          panelPadding: parseFloat(getComputedStyle(panel).paddingLeft),
          factsColumns: getComputedStyle(facts).gridTemplateColumns.trim().split(/\s+/).length,
          title: rect(title), brand: rect(brand), sound: rect(sound), tickets: rect(tickets),
          ctas: [...node.querySelectorAll('.cr-xp-cta a')].map(rect),
          header: rect(header),
          documentWidth: document.documentElement.clientWidth,
          scrollWidth: document.documentElement.scrollWidth,
        };
      });
      expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.documentWidth + 1);
      expect(metrics.title.left).toBeGreaterThanOrEqual(metrics.panel.left + 20);
      expect(metrics.title.right).toBeLessThanOrEqual(metrics.panel.right - 20);
      for (const cta of metrics.ctas) {
        expect(cta.height).toBeGreaterThanOrEqual(44);
        expect(cta.width).toBeGreaterThanOrEqual(44);
        expect(cta.right).toBeLessThanOrEqual(metrics.panel.right + 1);
      }
      if (width <= 900) {
        expect(metrics.columns).toBe(1);
        expect(metrics.panelPadding).toBeGreaterThanOrEqual(24);
        expect(metrics.factsColumns).toBe(2);
        if (cover) {
          expect(metrics.flyer.width).toBeGreaterThanOrEqual(metrics.panel.width - 2);
          expect(metrics.flyer.bottom).toBeLessThanOrEqual(metrics.panel.top + 2);
          expect(metrics.flyer.height).toBeGreaterThan(150);
        }
      } else if (cover) {
        expect(metrics.columns).toBe(2);
        expect(metrics.flyer.top).toBeLessThanOrEqual(metrics.panel.top + 2);
      } else {
        expect(metrics.columns).toBe(1);
      }
      if (width <= 430) {
        expect(metrics.brand.right).toBeLessThanOrEqual(metrics.sound.left + 1);
        expect(metrics.sound.right).toBeLessThanOrEqual(metrics.tickets.left + 1);
        expect(metrics.sound.width).toBeGreaterThanOrEqual(44);
        expect(metrics.tickets.height).toBeGreaterThanOrEqual(44);
        for (const cta of metrics.ctas) expect(cta.width).toBeGreaterThanOrEqual(metrics.panel.width - metrics.panelPadding * 2 - 2);
      }
      if (width === 390 && cover) {
        const img = card.locator('.cr-xp-fly img');
        await expect(img).toBeVisible();
        expect(await img.evaluate(el => getComputedStyle(el).transitionDuration)).toBe('0s');
      }
      await noOverflow(page);
    }
  }
});
