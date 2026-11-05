import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// All reads are disposable: the real Cromo runtime runs against fixed API fixtures.
const root = process.cwd();
const css = ['css/public-cromo.css', 'css/public-cromo-home.css']
  .map(path => readFileSync(join(root, path), 'utf8')).join('\n');
const runtime = readFileSync(join(root, 'js/public-cromo-home.js'), 'utf8');
const soundRuntime = readFileSync(join(root, 'js/public-cromo-sound.js'), 'utf8');
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

test('Artistas muestra fotos CMS y fallback seguro en 390/1440', async ({ page }) => {
  await page.route('**/uploads/artist-photo.svg', route => route.fulfill({
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" width="700" height="900"><rect width="700" height="900" fill="#4a315a"/></svg>',
  }));
  await page.route('**/uploads/artist-broken.svg', route => route.fulfill({ status: 404, body: '' }));
  const artists = [
    { ...artist, photo: '/uploads/artist-photo.svg', bio: 'Sesión especial' },
    { id: 23, slug: 'sin-foto', name: 'SIN FOTO', photo: null },
    { id: 24, slug: 'url-insegura', name: 'URL INSEGURA', photo: 'javascript:alert(1)' },
    { id: 25, slug: 'origen-externo', name: 'ORIGEN EXTERNO', photo: 'https://untrusted.invalid/image.svg' },
    { id: 26, slug: 'foto-rota', name: 'FOTO ROTA', photo: '/uploads/artist-broken.svg' },
  ];
  for (const width of [390, 1440]) {
    await mount(page, { width, payload: { ...data, artists } });
    const cards = page.locator('#artistas .cr-ac');
    await expect(cards).toHaveCount(6);
    const valid = page.locator('#artistas .cr-ac[href="/artists/artist-one"]');
    const img = valid.locator('img.cr-photo');
    await valid.scrollIntoViewIfNeeded();
    await expect(img).toHaveAttribute('src', '/uploads/artist-photo.svg');
    await expect(img).toHaveAttribute('alt', '');
    await expect(img).toHaveAttribute('loading', 'lazy');
    await expect(img).toHaveAttribute('decoding', 'async');
    await expect.poll(() => img.evaluate(node => node.complete && node.naturalWidth > 0)).toBe(true);
    await expect(img).toHaveCSS('object-fit', 'cover');
    await expect(valid).toHaveClass(/cr-has-photo/);
    const gradient = await valid.evaluate(node => getComputedStyle(node, '::before').backgroundImage);
    expect(gradient).toContain('linear-gradient');
    await expect(valid.locator('.cr-orb')).toBeHidden();
    await expect(valid.locator('.cr-nm')).toHaveText('ARTIST ONE');
    for (const slug of ['sin-foto', 'url-insegura', 'origen-externo', 'foto-rota']) {
      const fallback = page.locator('#artistas .cr-ac[href="/artists/' + slug + '"]');
      await fallback.scrollIntoViewIfNeeded();
      await expect(fallback.locator('img')).toHaveCount(0);
      await expect(fallback.locator('.cr-orb')).toBeVisible();
    }
    const join = page.locator('#artistas .cr-ac[href="#contacto"]');
    await expect(join.locator('img')).toHaveCount(0);
    await expect(join.locator('.cr-orb')).toBeVisible();
    await expect(page.locator('#contacto')).toBeVisible();
    await noOverflow(page);
  }
});

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
    const socialRing = await links.first().evaluate(n => {
      const style = getComputedStyle(n);
      return { kind: style.outlineStyle, width: parseFloat(style.outlineWidth), color: style.outlineColor };
    });
    expect(socialRing.kind).toBe('solid');
    expect(socialRing.width).toBeGreaterThanOrEqual(3);
    expect(socialRing.color).not.toBe('rgba(0, 0, 0, 0)');
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
  test.setTimeout(120_000); // Ten real CMS mounts across five viewports.
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
          panelContentWidth: panel.clientWidth - parseFloat(getComputedStyle(panel).paddingLeft) - parseFloat(getComputedStyle(panel).paddingRight),
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
        expect(metrics.brand.right).toBeLessThanOrEqual(metrics.sound.left + 1);
        expect(metrics.sound.right).toBeLessThanOrEqual(metrics.tickets.left + 1);
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
        const [flyerTrack, panelTrack] = await card.evaluate(node =>
          getComputedStyle(node).gridTemplateColumns.trim().split(/\s+/).map(parseFloat));
        expect(flyerTrack / panelTrack).toBeCloseTo(5 / 7, 1);
      } else {
        expect(metrics.columns).toBe(1);
      }
      if (width <= 430) {
        expect(metrics.brand.right).toBeLessThanOrEqual(metrics.sound.left + 1);
        expect(metrics.sound.right).toBeLessThanOrEqual(metrics.tickets.left + 1);
        expect(metrics.sound.width).toBeGreaterThanOrEqual(44);
        expect(metrics.tickets.height).toBeGreaterThanOrEqual(44);
        for (const cta of metrics.ctas) expect(cta.width).toBeGreaterThanOrEqual(metrics.panelContentWidth - 1);
      }
      if (width === 390 && cover) {
        const ticketControl = page.locator('#cromo-home .cr-top .cr-tickets');
        await ticketControl.focus();
        const ticketRing = await ticketControl.evaluate(el => {
          const style = getComputedStyle(el);
          return { kind: style.outlineStyle, width: parseFloat(style.outlineWidth), color: style.outlineColor };
        });
        expect(ticketRing.kind).toBe('solid');
        expect(ticketRing.width).toBeGreaterThanOrEqual(4);
        expect(ticketRing.color).toBe('rgb(255, 59, 31)');
        const img = card.locator('.cr-xp-fly img');
        await expect(img).toBeVisible();
        expect(await img.evaluate(el => getComputedStyle(el).transitionDuration)).toBe('0s');
      }
      await noOverflow(page);
    }
  }
});

test('Cromo keeps all section links accessible in the 821–900px header', async ({ page }) => {
  for (const width of [821, 850, 900]) {
    await mount(page, { width });
    const nav = page.locator('#cromo-home .cr-top nav');
    await expect(nav).toBeVisible();
    const positions = await nav.evaluate(node => {
      const rect = el => el.getBoundingClientRect().toJSON();
      const header = node.parentElement;
      return {
        brand: rect(header.querySelector('a.cr-u')),
        nav: rect(node),
        actions: rect(header.querySelector('div')),
        links: [...node.querySelectorAll('a')].map(rect),
      };
    });
    expect(positions.brand.right).toBeLessThanOrEqual(positions.nav.left + 1);
    expect(positions.nav.right).toBeLessThanOrEqual(positions.actions.left + 1);
    expect(positions.actions.right).toBeLessThanOrEqual(width + 1);
    for (const link of positions.links) {
      expect(link.left).toBeGreaterThanOrEqual(positions.nav.left - 1);
      expect(link.right).toBeLessThanOrEqual(positions.nav.right + 1);
      expect(link.height).toBeGreaterThanOrEqual(44);
      expect(link.width).toBeGreaterThanOrEqual(44);
    }
    await nav.locator('a[href="#artistas"]').click();
    await expect(page).toHaveURL(/#artistas$/);
    await nav.locator('a[href="#journal"]').click();
    await expect(page).toHaveURL(/#journal$/);
    await noOverflow(page);
  }
});


test('Próxima noche usa una tarjeta completa clicable y CTA real sin desbordes', async ({ page }) => {
  for (const width of [390, 1440]) {
    await mount(page, { width });
    const next = page.locator('#cromo-home .cr-hero .cr-next-link');
    await expect(next).toHaveCount(1);
    await expect(next).toHaveAttribute('href', '/events/genesis');
    await expect(next).toHaveAttribute('aria-label', /Ver evento GENESIS/);
    await expect(next.locator('.cr-next-count')).toContainText(/días/i);
    await expect(next.locator('.cr-next-event')).toHaveText('GENESIS');
    await expect(next.locator('.cr-next-details')).toContainText('Pereira');
    await expect(next.locator('.cr-next-action')).toContainText('VER EVENTO');
    const metrics = await next.evaluate(node => {
      const rect = node.getBoundingClientRect();
      return { left: rect.left, right: rect.right, width: rect.width, height: rect.height };
    });
    expect(metrics.left).toBeGreaterThanOrEqual(-1);
    expect(metrics.right).toBeLessThanOrEqual(width + 1);
    // #1560: tamaño original de la tarjeta (280px en escritorio, 340px en móvil).
    // offsetWidth ignora la rotación de -3° que infla el rectángulo visual.
    const layoutWidth = await next.evaluate(node => (node.closest('.cr-next') || node).offsetWidth);
    expect(layoutWidth).toBeLessThanOrEqual(width >= 1000 ? 280 : 340);
    const cta = await next.locator('.cr-next-action').boundingBox();
    expect(cta.height).toBeGreaterThanOrEqual(44);
    expect(metrics.width).toBeGreaterThanOrEqual(44);
    expect(metrics.height).toBeGreaterThanOrEqual(44);
    await next.focus();
    await expect(next).toBeFocused();
    expect(await next.evaluate(node => getComputedStyle(node).outlineStyle)).toBe('solid');
    await noOverflow(page);
  }
});

test('Próxima noche sin slug no inventa enlace; sin evento no crea tarjeta', async ({ page }) => {
  await mount(page, { payload: { ...data, events: [{ ...event, slug: '' }] } });
  await expect(page.locator('#cromo-home .cr-next')).toHaveCount(1);
  await expect(page.locator('#cromo-home .cr-next-link')).toHaveCount(0);
  await expect(page.locator('#cromo-home .cr-next-action')).toHaveCount(0);
  await mount(page, { payload: { ...data, events: [] } });
  await expect(page.locator('#cromo-home .cr-next')).toHaveCount(0);
});

test('GSAP anima la entrada y la flecha; movimiento reducido lo omite', async ({ page }) => {
  await page.addInitScript(() => {
    window.__cromoGsapCalls = [];
    window.BRVTALRuntimeReady = Promise.resolve();
    window.gsap = {
      matchMedia: () => ({
        add(_query, callback) { window.__cromoGsapCalls.push('media'); callback(); },
        revert() {},
      }),
      context: callback => { callback(); return { revert() {} }; },
      timeline: () => ({
        from() { window.__cromoGsapCalls.push('from'); return this; },
      }),
      to: (_target, vars) => { window.__cromoGsapCalls.push('to:' + vars.x); },
    };
  });
  await mount(page);
  await expect.poll(() => page.evaluate(() => window.__cromoGsapCalls.includes('from'))).toBe(true);
  await page.locator('#cromo-home .cr-next-link').hover();
  await expect.poll(() => page.evaluate(() => window.__cromoGsapCalls.includes('to:7'))).toBe(true);
  await mount(page, { reduced: true });
  expect(await page.evaluate(() => window.__cromoGsapCalls.length)).toBe(0);
  await expect(page.locator('#cromo-home .cr-next-link')).toHaveAttribute('href', '/events/genesis');
});

 
test('La próxima noche sigue visible y navegable sin GSAP a 390/1440', async ({ page }) => {
  for (const width of [390, 1440]) {
    await mount(page, { width });
    const card = page.locator('#cromo-home .cr-next-link');
    await expect(card).toHaveAttribute('href', '/events/genesis');
    await expect(card).toHaveCSS('opacity', '1');
    await noOverflow(page);
  }
});

test.describe('Contador de próxima noche en un cambio DST reproducible', () => {
  test.use({ timezoneId: 'America/New_York' });
  test('cuenta dos días calendario cuando termina el horario de verano', async ({ page }) => {
    // Local 2027-11-06, before the extra hour on November 7.
    await page.clock.install({ time: new Date('2027-11-06T16:00:00Z') });
    await mount(page, {
      width: 390,
      payload: { ...data, events: [{ ...event, event_date: '2027-11-08 21:00:00' }] },
    });
    const card = page.locator('#cromo-home .cr-next-link');
    await expect(card.locator('.cr-next-count')).toHaveText('Faltan 2 días');
    await expect(card).toHaveAttribute('href', '/events/genesis');
    await noOverflow(page);
  });
});

test('GSAP limpia flecha al cambiar a movimiento reducido y conserva bfcache', async ({ page }) => {
  await page.addInitScript(() => {
    window.__cromoMotionLog = [];
    window.BRVTALRuntimeReady = Promise.resolve();
    const log = window.__cromoMotionLog;
    const realMatchMedia = window.matchMedia.bind(window);
    window.gsap = {
      matchMedia: () => {
        let release = () => {};
        return {
          add(_query, callback) {
            const query = realMatchMedia('(prefers-reduced-motion: no-preference)');
            let cleanup = null;
            const sync = () => {
              if (query.matches && !cleanup) cleanup = callback() || (() => {});
              if (!query.matches && cleanup) { cleanup(); cleanup = null; }
            };
            query.addEventListener('change', sync);
            release = () => {
              query.removeEventListener('change', sync);
              if (cleanup) { cleanup(); cleanup = null; }
            };
            sync();
          },
          revert() { log.push('media-revert'); release(); },
        };
      },
      context(callback) {
        callback();
        return { revert() { log.push('context-revert'); } };
      },
      timeline: () => ({
        from() { log.push('intro'); return this; },
      }),
      to(arrow, vars) {
        log.push('to:' + vars.x);
        arrow.style.transform = 'translateX(' + vars.x + 'px)';
      },
      killTweensOf() { log.push('kill-tweens'); },
      set(arrow, vars) {
        log.push('set:' + vars.x);
        arrow.style.transform = 'translateX(' + vars.x + 'px)';
      },
    };
  });

  for (const width of [390, 1440]) {
    await mount(page, { width });
    const card = page.locator('#cromo-home .cr-next-link');
    const arrow = card.locator('.cr-next-arrow');
    await expect(card).toHaveAttribute('href', '/events/genesis');
    await expect.poll(() => page.evaluate(() =>
      window.__cromoMotionLog.filter(call => call === 'intro').length)).toBe(2);
    await card.hover();
    await expect.poll(() => page.evaluate(() => window.__cromoMotionLog.includes('to:7'))).toBe(true);
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await expect.poll(() => page.evaluate(() => window.__cromoMotionLog.includes('context-revert'))).toBe(true);
    expect(await page.evaluate(() => window.__cromoMotionLog)).toEqual(expect.arrayContaining(['kill-tweens', 'set:0']));
    await expect(arrow).toHaveCSS('transform', 'matrix(1, 0, 0, 1, 0, 0)');
    await expect(card).toHaveAttribute('href', '/events/genesis');

    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await expect.poll(() => page.evaluate(() =>
      window.__cromoMotionLog.filter(call => call === 'intro').length)).toBe(4);
    await page.evaluate(() => {
      const keep = new Event('pagehide');
      Object.defineProperty(keep, 'persisted', { value: true });
      window.dispatchEvent(keep);
    });
    expect(await page.evaluate(() => window.__cromoMotionLog.includes('media-revert'))).toBe(false);
    await page.evaluate(() => {
      const exit = new Event('pagehide');
      Object.defineProperty(exit, 'persisted', { value: false });
      window.dispatchEvent(exit);
    });
    await expect.poll(() => page.evaluate(() => window.__cromoMotionLog.includes('media-revert'))).toBe(true);
    await expect(card).toHaveAttribute('href', '/events/genesis');
    await noOverflow(page);
  }
});


test('Hero Description de Discadmin usa texto CMS seguro sin cambiar cinta ni navegación', async ({ page }) => {
  const description = 'Cultura nocturna sin barreras <img src=x onerror=alert(1)> y sin HTML';
  const payload = { ...data, settings: { site: { description, tagline: 'RAVE TILL GRAVE - CMS' } } };
  for (const width of [390, 1440]) {
    await mount(page, { width, payload });
    const heroText = page.locator('#cromo-home .cr-hero .cr-core p');
    await expect(heroText).toHaveText(description);
    expect(await heroText.evaluate(node => node.childElementCount)).toBe(0);
    await expect(page.locator('#cromo-home .cr-band span').first()).toHaveText('Rave till grave');
    await expect(page.locator('#cromo-home .cr-cta a[href="#noches"]')).toHaveText('Ver la próxima noche');
    await expect(page.locator('#cromo-home .cr-cta a[href="#sonido"]')).toHaveText('Escuchar');
    await expect(page.locator('#cromo-home .cr-next-link')).toHaveAttribute('href', '/events/genesis');
    await noOverflow(page);
  }
});

test('Hero Description conserva texto original si site.description falta o está vacío', async ({ page }) => {
  const fallback = 'Más que fiestas. Una cultura en movimiento.';
  for (const width of [390, 1440]) {
    for (const payload of [
      { ...data, settings: { site: { description: '   ', tagline: 'TAGLINE NO ES DESCRIPTION' } } },
      { ...data, settings: { site: {} } },
      { ...data },
    ]) {
      await mount(page, { width, payload });
      await expect(page.locator('#cromo-home .cr-hero .cr-core p')).toHaveText(fallback);
      await expect(page.locator('#cromo-home .cr-band span').first()).toHaveText('Rave till grave');
      await expect(page.locator('#cromo-home .cr-next-link')).toHaveAttribute('href', '/events/genesis');
      await noOverflow(page);
    }
  }
});

test('Hero Description limita entrada CMS a 240 caracteres en móvil y escritorio', async ({ page }) => {
  const description = 'la cultura nocturna es nuestra '.repeat(14);
  const normalized = description.replace(/\s+/g, ' ').trim();
  const expected = normalized.slice(0, 239).trimEnd() + '…';
  expect(expected.length).toBeLessThanOrEqual(240);
  for (const width of [390, 1440]) {
    await mount(page, { width, payload: { ...data, settings: { site: { description } } } });
    const heroText = page.locator('#cromo-home .cr-hero .cr-core p');
    await expect(heroText).toHaveText(expected);
    expect((await heroText.textContent()).length).toBeLessThanOrEqual(240);
    await noOverflow(page);
  }
});

// #1570: el botón de sonido solo dice «Apagar el pulso» cuando el audio corre de verdad.
for (const [mode, expected] of [['running', 'true'], ['suspended', 'false'], ['rejected', 'false']]) {
  test('El sonido ' + (expected === 'true' ? 'queda activo' : 'vuelve a apagado y permite reintentar') + ' si resume() termina ' + mode, async ({ page }) => {
    await page.addInitScript(resumeMode => {
      const node = () => new Proxy(function () {}, { get: (t, k) => (k === 'connect' ? (x) => x || node() : node()), apply: () => node() });
      window.AudioContext = class {
        constructor() { this.state = 'suspended'; this.currentTime = 0; this.sampleRate = 44100; this.destination = node(); window.__audioStarts = 0; }
        createGain() { return node(); } createOscillator() { return node(); } createBiquadFilter() { return node(); }
        createBufferSource() { window.__audioStarts += 1; return node(); } createBuffer() { return { getChannelData: () => [] }; }
        addEventListener() {}
        resume() {
          if (resumeMode === 'rejected') return Promise.reject(new Error('blocked'));
          if (resumeMode === 'running') this.state = 'running';
          return Promise.resolve();
        }
      };
      window.__resumeMode = resumeMode;
    }, mode);
    await page.route('**/sound-e2e.html', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body><button class="cromo-sound" type="button"><span class="cromo-sound-label">Activar sonido</span></button></body></html>' }));
    await page.goto('http://127.0.0.1:4173/sound-e2e.html');
    await page.addScriptTag({ content: soundRuntime });
    const button = page.locator('button.cromo-sound');
    await page.evaluate(() => window.BRVTALCromoSound.bind(document.querySelector('button.cromo-sound')));
    await button.click();
    await expect(button).toHaveAttribute('aria-pressed', expected);
    await expect(button.locator('.cromo-sound-label')).toHaveText(expected === 'true' ? 'Apagar el pulso' : 'Activar sonido');
    // El desbloqueo ocurre dentro del gesto del clic.
    expect(await page.evaluate(() => window.__audioStarts)).toBeGreaterThan(0);
  });
}

// #1575/#1577: en WebKit el sonido usa un <audio> con el bucle renderizado y, si falla, Web Audio.
for (const [mode, expectedSource] of [['plays', 'blob'], ['loop-rejected', 'fallback']]) {
  test('El sonido en WebKit ' + (mode === 'plays' ? 'reproduce el bucle renderizado con audio' : 'cae a Web Audio si el audio renderizado es rechazado'), async ({ page }) => {
    await page.addInitScript(playMode => {
      window.__cromoForceMediaSound = true;
      window.__audioContextsCreated = 0;
      window.__mediaSources = [];
      const RealAudioContext = window.AudioContext;
      window.AudioContext = class extends RealAudioContext { constructor(...args) { super(...args); window.__audioContextsCreated += 1; } };
      HTMLMediaElement.prototype.pause = function () {};
      HTMLMediaElement.prototype.play = function () {
        window.__mediaSources.push(this.src);
        return playMode === 'loop-rejected' && this.src.startsWith('blob:') ? Promise.reject(new Error('blocked')) : Promise.resolve();
      };
    }, mode);
    await page.route('**/sound-e2e.html', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body><button class="cromo-sound" type="button"><span class="cromo-sound-label">Activar sonido</span></button></body></html>' }));
    await page.goto('http://127.0.0.1:4173/sound-e2e.html');
    await page.addScriptTag({ content: soundRuntime });
    const button = page.locator('button.cromo-sound');
    await page.evaluate(() => window.BRVTALCromoSound.bind(document.querySelector('button.cromo-sound')));
    await button.click();
    await expect(button).toHaveAttribute('aria-pressed', 'true');
    await expect(button.locator('.cromo-sound-label')).toHaveText('Apagar el pulso');
    // Primero el clip silencioso (blob:, la CSP no permite data:) dentro del gesto; después el bucle.
    expect(await page.evaluate(() => window.__mediaSources[0])).toMatch(/^blob:/);
    await expect.poll(() => page.evaluate(() => window.__mediaSources.at(-1))).toMatch(/^blob:/);
    // El contexto de Web Audio se prepara dentro del gesto para poder servir de respaldo.
    expect(await page.evaluate(() => window.__audioContextsCreated)).toBe(1);
  });
}
