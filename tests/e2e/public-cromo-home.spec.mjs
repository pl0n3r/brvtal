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
    await expect(img).toHaveCSS('object-fit', 'contain');
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
  await expect(eventButton).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('#conectado [aria-pressed="true"]')).toHaveCount(0);
  await expect(page.locator('#conectado .cr-cn2-groups')).toBeEmpty();
  await artistButton.click();
  await expect(artistButton).toHaveAttribute('aria-pressed', 'true');
  await expect(eventButton).toHaveClass(/cr-is-linked/);
  await expect(page.locator('#conectado .cr-cn2-sentence')).toContainText('Noches (1)');
  await expect(page.locator('#conectado .cr-cn2-related').first()).toContainText('GENESIS');
  await expect(page.locator('#conectado .cr-cn2-lines path')).toHaveCount(0);
  await expect(page.locator('#conectado .cr-cn2-actions a')).toHaveAttribute('href', '/artists/artist-one');
});

test('Conectado resume 25 vínculos por categoría, permite ampliar y limpiar con Escape', async ({ page }) => {
  const many = Array.from({ length: 25 }, (_, i) => ({
    id: 100 + i, slug: 'set-' + i, title: 'SET REAL ' + (i + 1), artist_name: 'ARTIST ONE',
  }));
  await mount(page, { width: 390, payload: {
    ...data, sets: many,
    relations: { artists: { 2: { sets: many.map((x) => x.id), events: [7] } } },
  } });
  const artistButton = page.locator('#conectado [data-key="a2"]');
  await artistButton.click();
  await expect(page.locator('#conectado .cr-cn2-sentence')).toContainText('Sets (25)');
  await expect(page.locator('#conectado .cr-cn2-sentence')).not.toContainText('SET REAL 20');
  const group = page.locator('#conectado .cr-cn2-related[aria-label="Sets"]');
  await expect(group.locator('a:visible')).toHaveCount(2);
  await group.getByRole('button', { name: 'Ver todos (25)' }).click();
  await expect(group.locator('a:visible')).toHaveCount(25);
  await page.keyboard.press('Escape');
  await expect(page.locator('#conectado .cr-cn2-groups')).toBeEmpty();
  await noOverflow(page);
});

test('Contacto recupera el captcha fallido, anuncia errores 422 y preserva el envío', async ({ page }) => {
  for (const width of [390, 768, 900, 1440]) {
    await mount(page, { width });
    const form = page.locator('#contacto form');
    const submit = form.getByRole('button', { name: 'Enviar mensaje' });
    await expect(submit).toBeDisabled();
    const retry = form.getByRole('button', { name: 'Reintentar verificación' });
    await expect(retry).toBeVisible();
    await page.route('**/api/contact.php', route => {
      if (route.request().method() === 'GET') {
        return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, data: { token: 'safe-fixture', question: '2 + 3 = ?' } }) });
      }
      return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ ok: false, fields: { captcha_answer: 'incorrecto' } }) });
    });
    await retry.click();
    await expect(submit).toBeEnabled();
    await form.locator('[name="name"]').fill('Persona');
    await form.locator('[name="email"]').fill('persona@example.org');
    await form.locator('[name="message"]').fill('Mensaje de prueba');
    await form.locator('[name="captcha_answer"]').fill('0');
    await submit.click();
    await expect(form.locator('#cromo-c')).toHaveAttribute('aria-invalid', 'true');
    await expect(form.locator('#cromo-err-captcha')).toContainText('Revisa este campo');
    await noOverflow(page);
  }
});

test('Contacto conserva el éxito si falla cargar el captcha siguiente', async ({ page }) => {
  await mount(page);
  let challenges = 0;
  await page.route('**/api/contact.php', route => {
    if (route.request().method() === 'POST') {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
    }
    challenges += 1;
    return route.fulfill({
      status: challenges === 1 ? 200 : 503,
      contentType: 'application/json',
      body: challenges === 1 ? JSON.stringify({ ok: true, data: { token: 'first-challenge', question: '2 + 3 = ?' } }) : '{}',
    });
  });
  const form = page.locator('#contacto form');
  await form.getByRole('button', { name: 'Reintentar verificación' }).click();
  await form.locator('[name="name"]').fill('Persona');
  await form.locator('[name="email"]').fill('persona@example.org');
  await form.locator('[name="message"]').fill('Mensaje de prueba');
  await form.locator('[name="captcha_answer"]').fill('5');
  await form.getByRole('button', { name: 'Enviar mensaje' }).click();
  await expect(form.locator('.cr-form-status')).toContainText('Mensaje enviado');
  await expect(form.locator('.cr-form-status')).toHaveAttribute('data-state', 'success');
  await expect(form.getByRole('button', { name: 'Reintentar verificación' })).toBeVisible();
  await expect(form.getByRole('button', { name: 'Enviar mensaje' })).toBeDisabled();
});

test('Sonido conserva altura por contenido sin margen gigante a 390/768/900/1440', async ({ page }) => {
  for (const width of [390, 768, 900, 1440]) {
    await mount(page, { width });
    const section = page.locator('#sonido');
    await expect(section).toBeVisible();
    const dimensions = await section.evaluate(node => {
      const heading = node.querySelector('h2');
      const margin = getComputedStyle(heading);
      return {
        height: node.getBoundingClientRect().height,
        marginTop: parseFloat(margin.marginTop), marginBottom: parseFloat(margin.marginBottom),
      };
    });
    expect(dimensions.marginTop).toBe(0);
    expect(dimensions.marginBottom).toBe(0);
    expect(dimensions.height).toBeLessThan(720);
    await noOverflow(page);
  }
});

test('Legacy #archive and #events navigate to visible, keyboard-focusable Cromo destinations at 390/1440', async ({ page, browserName }) => {
  const emptyArchive = { ...data, archive: { events: [] } };
  for (const width of [390, 1440]) {
    // AC-01: with no published archive, fall back to the always-rendered #noches.
    await mount(page, { width, hash: '#archive', payload: emptyArchive });
    await expect(page.locator('#archivo')).toHaveCount(0);
    await expect.poll(() => new URL(page.url()).hash).toBe('#noches');
    const empty = page.locator('#noches');
    await expect(empty).toBeVisible();
    await expect(empty).toBeFocused();
    await expect(empty).toHaveAttribute('tabindex', '-1');
    const emptyMetrics = await empty.evaluate(node => ({
      top: node.getBoundingClientRect().top,
      scroll: window.scrollY,
      margin: parseFloat(getComputedStyle(node).scrollMarginTop),
    }));
    expect(emptyMetrics.scroll).toBeGreaterThan(0);
    expect(emptyMetrics.top).toBeGreaterThanOrEqual(0);
    expect(emptyMetrics.top).toBeLessThan(180);
    expect(emptyMetrics.margin).toBeGreaterThanOrEqual(60);
    await page.keyboard.press(browserName === 'webkit' ? 'Alt+Tab' : 'Tab');
    await expect(page.locator('#noches a.cr-night').first()).toBeFocused();
    await noOverflow(page);

    // AC-02: with an archived event, keep #archivo and its first card.
    await mount(page, { width, hash: '#archive' });
    await expect(page.locator('#archivo .cr-pst')).toHaveCount(1);
    await expect(page.locator('#archivo .cr-pst')).toHaveAttribute('href', '/events/past-signal');
    await expect.poll(() => new URL(page.url()).hash).toBe('#archivo');
    const populated = page.locator('#archivo');
    await expect(populated).toBeVisible();
    await expect(populated).toBeFocused();
    await expect(populated).toHaveAttribute('tabindex', '-1');
    const populatedMetrics = await populated.evaluate(node => ({
      top: node.getBoundingClientRect().top,
      scroll: window.scrollY,
      margin: parseFloat(getComputedStyle(node).scrollMarginTop),
    }));
    expect(populatedMetrics.scroll).toBeGreaterThan(0);
    expect(populatedMetrics.top).toBeGreaterThanOrEqual(0);
    expect(populatedMetrics.top).toBeLessThan(180);
    expect(populatedMetrics.margin).toBeGreaterThanOrEqual(60);
    await page.keyboard.press(browserName === 'webkit' ? 'Alt+Tab' : 'Tab');
    await expect(page.locator('#archivo a.cr-pst').first()).toBeFocused();
    await noOverflow(page);

    // AC-03: #events continues to lead to #noches even with an empty archive.
    await mount(page, { width, hash: '#events', payload: emptyArchive });
    await expect.poll(() => new URL(page.url()).hash).toBe('#noches');
    await expect(page.locator('#noches')).toBeFocused();
    await expect(page.locator('#noches')).toBeVisible();
    await page.keyboard.press(browserName === 'webkit' ? 'Alt+Tab' : 'Tab');
    await expect(page.locator('#noches a.cr-night').first()).toBeFocused();
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

test('Cromo tablet 821–900px keeps every published link accessible in compact menu', async ({ page }) => {
  for (const width of [821, 850, 900]) {
    await mount(page, { width });
    const nav = page.locator('#cromo-home .cr-top nav');
    const trigger = page.locator('#cromo-home .cr-nav-toggle');
    await expect(trigger).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await trigger.click();
    await expect(nav).toBeVisible();
    await expect(nav.locator('a[href="#discos"]')).toBeVisible();
    await nav.locator('a[href="#artistas"]').click();
    await expect(page).toHaveURL(/#artistas$/);
    await expect(nav).toBeHidden();
    await trigger.click();
    // The fixture has published Sets but no Blog: Journal must not be linked.
    await expect(nav.locator('a[href="#journal"]')).toHaveCount(0);
    await nav.locator('a[href="#discos"]').click();
    await expect(page).toHaveURL(/#discos$/);
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
    await expect(page.locator('#cromo-home .cr-cta a[href="#discos"]')).toHaveText('Escuchar sets');
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

// #1631 — Cromo players use their own CMS SoundCloud URLs and official artwork.
const soundSets = [
  { id: 31, slug: 'first-set', title: 'FIRST SET', artist_name: 'DJ ONE',
    external_url: 'https://soundcloud.com/official/first-set', platform: 'soundcloud',
    cover_image: '/cms-first.jpg' },
  { id: 32, slug: 'second-set', title: 'SECOND SET', artist_name: 'DJ TWO',
    external_url: 'https://soundcloud.com/official/second-set', platform: 'soundcloud',
    cover_image: '/cms-second.jpg' },
  { id: 33, slug: 'broken-set', title: 'BROKEN SET', artist_name: 'DJ THREE',
    external_url: 'https://soundcloud.com/example/broken-set', platform: 'soundcloud',
    cover_image: '/cms-missing.jpg' },
  { id: 34, slug: 'profile', title: 'PROFILE IS NOT A SET', artist_name: 'DJ FOUR',
    external_url: 'https://soundcloud.com/official', platform: 'soundcloud' },
];
async function mockSoundCloud(page) {
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="500" height="500"><rect width="500" height="500" fill="#b30"/></svg>';
  await page.route('https://i1.sndcdn.com/**', route => route.fulfill({ contentType: 'image/svg+xml', body: svg }));
  await page.route('**/cms-first.jpg', route => route.fulfill({ contentType: 'image/svg+xml', body: svg }));
  await page.route('**/cms-second.jpg', route => route.fulfill({ contentType: 'image/svg+xml', body: svg }));
  await page.route('**/cms-missing.jpg', route => route.fulfill({ status: 404, body: '' }));
  await page.route('https://w.soundcloud.com/player/**', route => route.fulfill({
    contentType: 'text/html', body: '<!doctype html><html><body>PLAYER</body></html>',
  }));
  await page.route('**/api/soundcloud-artwork.php?**', route => {
    const track = new URL(route.request().url()).searchParams.get('url');
    if (track === soundSets[2].external_url) {
      return route.fulfill({ status: 404, contentType: 'application/json',
        body: JSON.stringify({ ok: false, error: 'TRACK_UNAVAILABLE' }) });
    }
    const marker = track === soundSets[0].external_url ? 'ONE' : 'TWO';
    return route.fulfill({ contentType: 'application/json',
      body: JSON.stringify({ ok: true, artwork_url: 'https://i1.sndcdn.com/artworks-' + marker + '-t500x500.jpg' }) });
  });
}

test('Cromo: no iframe before click, official artwork and player switches at 390, 1440', async ({ page }) => {
  test.setTimeout(90_000);
  await mockSoundCloud(page);
  for (const width of [390, 1440]) {
    await mount(page, { width, payload: { ...data, sets: soundSets } });
    const feat = page.locator('#cromo-home .cr-feat');
    const rows = page.locator('#cromo-home .cr-sl .cr-sound-row');
    await expect(rows).toHaveCount(3);
    await expect(page.locator('#cromo-home .cr-set-player iframe')).toHaveCount(0);
    await expect(feat.locator('.cr-set-art img')).toHaveAttribute('src', 'https://i1.sndcdn.com/artworks-ONE-t500x500.jpg');
    await expect(rows.nth(0).locator('.cr-set-art img')).toHaveAttribute('src', 'https://i1.sndcdn.com/artworks-TWO-t500x500.jpg');
    await expect(rows.nth(2).locator('.cr-set-art.cr-is-empty')).toBeVisible();
    await noOverflow(page);

    const mainButton = feat.locator('button.cr-sound-listen');
    await mainButton.focus();
    await expect(mainButton).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(mainButton).toHaveAttribute('aria-expanded', 'true');
    const firstFrame = page.locator('#cromo-home .cr-inline-player iframe');
    await expect(firstFrame).toHaveAttribute('src', /soundcloud\.com%2Fofficial%2Ffirst-set/);
    expect(new URL(page.url()).host).toBe('127.0.0.1:4173');

    // player switches: selecting second Set destroys the first provider iframe.
    await rows.nth(0).locator('button.cr-sound-listen').click();
    await expect(feat.locator('iframe')).toHaveCount(0);
    await expect(rows.nth(0).locator('.cr-sound-actions>.cr-inline-player iframe')).toHaveCount(1);
    await expect(page.locator('#cromo-home .cr-inline-player iframe')).toHaveAttribute('src', /soundcloud\.com%2Fofficial%2Fsecond-set/);
    await expect(page.locator('#cromo-home .cr-set-player iframe')).toHaveCount(1);
    await rows.nth(0).locator('button.cr-sound-listen').click();
    await expect(page.locator('#cromo-home .cr-set-player iframe')).toHaveCount(0);
    await expect(rows.nth(0).locator('a.cr-sound-external')).toHaveAttribute('href', soundSets[1].external_url);
    await noOverflow(page);
  }
});

test('Cromo: broken SoundCloud links use CMS/placeholder, no unsafe iframe or extra navigation', async ({ page }) => {
  await mockSoundCloud(page);
  await mount(page, { width: 390, payload: { ...data, sets: soundSets } });
  const rows = page.locator('#cromo-home .cr-sl .cr-sound-row');
  await expect(rows.nth(1).locator('button.cr-sound-listen')).toBeDisabled();
  await rows.nth(1).scrollIntoViewIfNeeded(); // The CMS cover is lazy-loaded.
  await expect(rows.nth(1).locator('.cr-set-art.cr-is-empty')).toBeVisible();
  await expect(rows.nth(1).locator('a.cr-sound-external')).toHaveAttribute('href', soundSets[2].external_url);
  await expect(rows.nth(2).locator('button.cr-sound-listen')).toBeDisabled();
  await expect(rows.nth(2).locator('.cr-set-art.cr-is-empty')).toBeVisible();
  await expect(page.locator('#cromo-home iframe[src^="https://w.soundcloud.com"]')).toHaveCount(0);
  await noOverflow(page);
});

test('Cromo: listening stops ambient audio and ambient activation closes iframe', async ({ page }) => {
  await mockSoundCloud(page);
  await mount(page, { width: 1440, payload: { ...data, sets: soundSets } });
  await page.evaluate(() => {
    window.__ambientStopped = 0;
    window.BRVTALCromoSound = { stop() { window.__ambientStopped += 1; } };
  });
  await page.locator('#cromo-home .cr-feat button.cr-sound-listen').click();
  expect(await page.evaluate(() => window.__ambientStopped)).toBe(1);
  await expect(page.locator('#cromo-home .cr-set-player iframe')).toHaveCount(1);
  const externalLink = page.locator('#cromo-home .cr-feat a.cr-sound-external');
  await externalLink.focus();
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('brvtal:cromo-ambient-start')));
  await expect(page.locator('#cromo-home .cr-set-player iframe')).toHaveCount(0);
  await expect(externalLink).toBeFocused();
  await expect(page.locator('#cromo-home .cr-feat button.cr-sound-listen')).toHaveAttribute('aria-expanded', 'false');
});


// 0/1/5/6/30 published Sets must be reachable, without discarding anything
// beyond the initially visible four rows. The provider is mocked for every row.
test('Cromo catalog 0/1/5/6/30 preserves editorial order, multi-DJs and keyboard at 390/1440', async ({ page }) => {
  test.setTimeout(120_000);
  await mockSoundCloud(page);
  for (const width of [390, 1440]) {
    for (const size of [0, 1, 5, 6, 30]) {
      const catalog = Array.from({ length: size }, (_, index) => ({
        ...soundSets[0], id: 900 + index, slug: 'catalog-' + index,
        title: 'EDITORIAL ' + (size - index), sort_order: index,
        artist_names: ['DJ PUBLICADO', 'DJ INVITADO'], artist_name: 'SOLO LEGACY',
        external_url: 'https://soundcloud.com/official/catalog-' + index,
      }));
      await mount(page, { width, reduced: size === 30, payload: { ...data, sets: catalog } });
      const section = page.locator('#cromo-home #discos');
      const feature = section.locator('.cr-feat');
      const rows = section.locator('.cr-sound-row');
      const more = section.locator('button.cr-set-more');
      if (size === 0) {
        await expect(feature).toHaveCount(0);
        await expect(more).toHaveCount(0);
        await expect(section).toHaveCount(0);
        await expect(page.locator('#cromo-section-nav a[href="#discos"]')).toHaveCount(0);
        await noOverflow(page);
        continue;
      }
      await expect(feature.locator('h3')).toHaveText(catalog[0].title);
      await expect(feature).toContainText('Último set publicado');
      await expect(feature).toContainText('DJ PUBLICADO y DJ INVITADO');
      await expect(feature).not.toContainText('SOLO LEGACY');
      await expect(rows).toHaveCount(Math.min(4, size - 1));
      let batches = 0;
      while (await more.isVisible()) {
        if (batches === 0) {
          await more.focus();
          await page.keyboard.press('Enter');
        } else {
          await more.click();
        }
        batches++;
        if (batches > 10) throw new Error('Catalog button failed to reach final state');
      }
      expect(batches).toBe(Math.max(0, Math.ceil((size - 5) / 4)));
      await expect(rows).toHaveCount(size - 1);
      await expect(more).toBeHidden();
      await expect(section.locator('.cr-set-count')).toContainText(size + ' de ' + size);
      await expect(section.locator('.cr-set-count')).toContainText('Catálogo completo');
      await noOverflow(page);

      if (size >= 6) {
        await feature.locator('button.cr-sound-listen').click();
        await expect(section.locator('.cr-set-player iframe')).toHaveCount(1);
        await rows.last().locator('button.cr-sound-listen').click();
        await expect(feature.locator('iframe')).toHaveCount(0);
        await expect(section.locator('.cr-set-player iframe')).toHaveCount(1);
        await rows.last().locator('button.cr-sound-listen').click();
        await expect(section.locator('.cr-set-player iframe')).toHaveCount(0);
        await noOverflow(page);
      }
    }
  }
});

test('Cromo revealed row retains SoundCloud fallback when URL is not embeddable', async ({ page }) => {
  await mockSoundCloud(page);
  const catalog = [...soundSets,
    { id: 35, slug: 'valid-revealed', title: 'VALID REVEALED',
      artist_names: ['DJ A', 'DJ B'], external_url: 'https://soundcloud.com/official/new-track', platform: 'soundcloud' },
    { id: 36, slug: 'profile-revealed', title: 'PROFILE REVEALED',
      artist_names: ['DJ C'], external_url: 'https://soundcloud.com/official', platform: 'soundcloud' },
  ];
  await mount(page, { width: 390, payload: { ...data, sets: catalog } });
  await page.locator('#cromo-home button.cr-set-more').click();
  const rows = page.locator('#cromo-home .cr-sound-row');
  await expect(rows).toHaveCount(5);
  const last = rows.last();
  await expect(last.locator('button.cr-sound-listen')).toBeDisabled();
  await expect(last.locator('.cr-set-art.cr-is-empty')).toBeVisible();
  await expect(last.locator('a.cr-sound-external')).toHaveAttribute('href', 'https://soundcloud.com/official');
  await expect(page.locator('#cromo-home .cr-set-player iframe')).toHaveCount(0);
  await noOverflow(page);
});


test('Cromo featured card has compact grouped content at 390/768/900/1440', async ({page}) => {
  test.setTimeout(120_000);
  await mockSoundCloud(page);
  for (const width of [390, 768, 900, 1440]) {
    for (const count of [1, 30]) {
      const catalog = Array.from({length:count}, (_,index) => ({
        ...soundSets[0], id:6000 + index, title:index===0 ? 'INDUSTRIAL SIGNAL' : 'SESSION ' + index,
        artist_names:['DJ PRINCIPAL','DJ INVITADO'],
      }));
      await mount(page, {width, reduced:width===390, payload:{...data, sets:catalog}});
      const feature=page.locator('#cromo-home #discos .cr-feat');
      await expect(feature).toContainText('DJ PRINCIPAL y DJ INVITADO');
      const measurements=await feature.evaluate(card => {
        const rect=node=>node.getBoundingClientRect();
        const outer=rect(card);
        const copy=card.querySelector('.cr-sound-feature-copy');
        const copyRect=rect(copy);
        const artistRect=rect(copy.querySelector('p'));
        const cover=rect(card.querySelector('.cr-set-art-featured'));
        const title=rect(card.querySelector('h3'));
        const actions=rect(card.querySelector('.cr-sound-actions'));
        return {
          height:outer.height,
          between:actions.top-artistRect.bottom,
          bottom:copyRect.bottom-actions.bottom,
          artRight:cover.right,
          titleLeft:title.left,
        };
      });
      expect(measurements.height).toBeLessThan(width===390 ? 740 : 530);
      expect(measurements.between).toBeLessThan(55);
      expect(measurements.bottom).toBeLessThan(55);
      if(width>640)expect(measurements.titleLeft).toBeGreaterThanOrEqual(measurements.artRight);
      await noOverflow(page);
      if (count===30) {
        await expect(page.locator('#cromo-home #discos .cr-sound-row')).toHaveCount(4);
        await expect(page.locator('#cromo-home #discos button.cr-set-more')).toBeVisible();
        await feature.locator('button.cr-sound-listen').click();
        await expect(page.locator('#cromo-home .cr-inline-player iframe')).toHaveCount(1);
        await noOverflow(page);
      }
    }
  }
});


test('Cromo mosaic removes left column gap after revealing 9 and 30 sets', async ({page}) => {
  test.setTimeout(120_000);
  await mockSoundCloud(page);
  for (const width of [390, 768, 900, 1440]) {
    for (const total of [5, 9, 30]) {
      const catalog=Array.from({length:total}, (_,index)=>({
        ...soundSets[0], id:7000+index, slug:'mosaic-'+index,
        title:index ? 'SESSION '+index : 'FEATURED SESSION',
        artist_names:['DJ PRINCIPAL', 'DJ INVITADO'],
        external_url:'https://soundcloud.com/official/session-'+index,
      }));
      await mount(page,{width,reduced:width===390,payload:{...data,sets:catalog}});
      const section=page.locator('#cromo-home #discos');
      const feature=section.locator('.cr-feat');
      const list=section.locator('.cr-sl');
      const rows=section.locator('.cr-sound-row');
      await expect(feature).toContainText('DJ PRINCIPAL y DJ INVITADO');
      await expect(rows).toHaveCount(4);
      let geometry=await page.evaluate(()=>{
        const section=document.querySelector('#cromo-home #discos');
        const f=section.querySelector('.cr-feat').getBoundingClientRect();
        const l=section.querySelector('.cr-sl').getBoundingClientRect();
        const r=[...section.querySelectorAll('.cr-sound-row')].map(el=>el.getBoundingClientRect());
        return {fLeft:f.left,fRight:f.right,fBottom:f.bottom,lLeft:l.left,lRight:l.right,lTop:l.top,
          firstY:r[0].top,secondY:r[1].top,firstX:r[0].left,secondX:r[1].left};
      });
      expect(Math.abs(geometry.fLeft-geometry.lLeft)).toBeLessThan(2);
      expect(Math.abs(geometry.fRight-geometry.lRight)).toBeLessThan(2);
      expect(geometry.lTop-geometry.fBottom).toBeGreaterThanOrEqual(0);
      expect(geometry.lTop-geometry.fBottom).toBeLessThan(40);
      if(width<=640)expect(geometry.secondY).toBeGreaterThan(geometry.firstY+30);
      else{
        expect(Math.abs(geometry.secondY-geometry.firstY)).toBeLessThan(3);
        expect(geometry.secondX).toBeGreaterThan(geometry.firstX+80);
      }
      await noOverflow(page);
      const more=section.locator('button.cr-set-more');
      while(await more.isVisible()) await more.click();
      await expect(rows).toHaveCount(total-1);
      await expect(more).toBeHidden();
      const count=section.locator('.cr-set-count');
      await expect(count).toContainText(total+' de '+total);
      await noOverflow(page);
    }
  }
});

test('Cromo mosaic optional GSAP reveal never blocks keyboard or reduced motion', async ({page}) => {
  await mockSoundCloud(page);
  const catalog=Array.from({length:10}, (_,index)=>({
    ...soundSets[0],id:8100+index,slug:'motion-'+index,
    title:'MOTION '+index,external_url:'https://soundcloud.com/official/motion-'+index,
  }));
  for(const reduced of [false,true]){
    await mount(page,{width:1440,reduced,payload:{...data,sets:catalog}});
    await page.evaluate(()=>{
      window.__revealCalls=[];
      window.gsap={
        fromTo(targets,from,to){
          window.__revealCalls.push({length:targets.length,from,to});
          return {kill(){}};
        },
      };
    });
    const more=page.locator('#cromo-home #discos button.cr-set-more');
    await more.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#cromo-home #discos .cr-sound-row')).toHaveCount(8);
    const calls=await page.evaluate(()=>window.__revealCalls);
    expect(calls.length).toBe(reduced?0:1);
    if(!reduced){
      expect(calls[0].length).toBe(4);
      expect(calls[0].to.stagger).toBe(.045);
    }
    await more.focus();
    await page.keyboard.press('Enter');
    await expect(more).toBeHidden();
    await expect(page.locator('#cromo-home #discos .cr-sound-row')).toHaveCount(9);
    const focusInLastBatch=await page.evaluate(()=>{
      const el=document.activeElement;
      return !!el?.closest('.cr-sound-row')?.textContent?.includes('MOTION 9');
    });
    expect(focusInLastBatch).toBe(true);
    await noOverflow(page);
  }
});

test('Cromo featured cover dominates and stays readable', async ({page}) => {
  test.setTimeout(120_000);
  await mockSoundCloud(page);
  for(const width of [390,768,1024,1440]){
    for(const total of [1,5,30]){
      const catalog=Array.from({length:total}, (_,index)=>({
        ...soundSets[0],id:9200+index,title:index?'SECONDARY '+index:'INDUSTRIAL RITUAL',
        artist_names:['DJ PRINCIPAL','DJ INVITADO'],
        external_url:'https://soundcloud.com/official/hierarchy-'+index,
      }));
      await mount(page,{width,reduced:width===390,payload:{...data,sets:catalog}});
      const feature=page.locator('#cromo-home #discos .cr-feat');
      await expect(feature.locator('.cr-sound-feature-kicker')).toHaveText('Último set publicado');
      const bounds=await feature.evaluate(node=>{
        const box=el=>el.getBoundingClientRect();
        const artwork=box(node.querySelector('.cr-set-art-featured'));
        const copy=box(node.querySelector('.cr-sound-feature-copy'));
        const btn=box(node.querySelector('.cr-sound-actions'));
        const outer=box(node);
        return {artWidth:artwork.width,artHeight:artwork.height,artRight:artwork.right,
          copyLeft:copy.left,copyTop:copy.top,copyBottom:copy.bottom,
          actionsLeft:btn.left,actionsRight:btn.right,actionsBottom:btn.bottom,
          outerRight:outer.right,outerHeight:outer.height};
      });
      expect(bounds.artWidth).toBeGreaterThanOrEqual(width<=640 ? 210 : width<1000 ? 180 : 216);
      expect(Math.abs(bounds.artWidth-bounds.artHeight)).toBeLessThan(3);
      expect(bounds.outerHeight).toBeLessThan(width<=640 ? 790 : 560);
      expect(bounds.actionsRight).toBeLessThanOrEqual(bounds.outerRight);
      if(width>640)expect(bounds.copyLeft).toBeGreaterThanOrEqual(bounds.artRight);
      const secondary=page.locator('#cromo-home #discos .cr-sound-row').first();
      if(total>1){
        const small=await secondary.locator('.cr-set-art').boundingBox();
        expect(bounds.artWidth).toBeGreaterThan(small.width*2.1);
      }
      await noOverflow(page);
      if(total===30){
        await feature.locator('button.cr-sound-listen').click();
        await expect(page.locator('#cromo-home .cr-inline-player iframe')).toHaveCount(1);
        await secondary.locator('button.cr-sound-listen').click();
        await expect(feature.locator('.cr-set-player iframe')).toHaveCount(0);
        await expect(page.locator('#cromo-home #discos iframe')).toHaveCount(1);
      }
    }
  }
});

test('Cromo secondary actions are right-aligned without overflow', async ({page})=>{
  test.setTimeout(120_000);
  await mockSoundCloud(page);
  const sets=Array.from({length:30},(_,i)=>({
    ...soundSets[0],id:10300+i,title:i===1?'EXTREMELY LONG INDUSTRIAL SESSION NAME WITH MULTIPLE DJS':'SET '+i,
    artist_names:['DJ PRINCIPAL','DJ INVITADO','DJ ADICIONAL'],
    external_url:'https://soundcloud.com/official/inline-'+i,
  }));
  for (const width of [390, 768, 900, 1280, 1440]){
    await mount(page,{width,payload:{...data,sets}});
    const row=page.locator('#cromo-home #discos .cr-sound-row').first();
    const measurements=await row.evaluate(el=>{
      const rect=x=>x.getBoundingClientRect();
      const meta=rect(el.querySelector('.cr-sound-row-meta'));
      const actions=rect(el.querySelector('.cr-sound-actions'));
      const item=rect(el);
      const a=rect(el.querySelector('button.cr-sound-listen'));
      const b=rect(el.querySelector('a.cr-sound-external'));
      return {metaRight:meta.right,metaBottom:meta.bottom,actionsLeft:actions.left,
        actionsTop:actions.top,actionsBottom:actions.bottom,
        itemRight:item.right,buttonsY:[a.top,b.top]};
    });
    if(width>=1260){
      expect(measurements.actionsLeft).toBeGreaterThanOrEqual(measurements.metaRight-3);
      expect(Math.abs(measurements.buttonsY[0]-measurements.buttonsY[1])).toBeLessThan(4);
    }else{
      expect(measurements.actionsTop).toBeGreaterThanOrEqual(measurements.metaBottom-3);
    }
    expect(measurements.actionsBottom).toBeGreaterThan(measurements.actionsTop);
    expect(measurements.itemRight).toBeGreaterThan(measurements.actionsLeft);
    await noOverflow(page);
    const more=page.locator('#cromo-home #discos button.cr-set-more');
    await more.click();
    const last=page.locator('#cromo-home #discos .cr-sound-row').last();
    await expect(last.locator('button.cr-sound-listen')).toBeEnabled();
    await last.locator('button.cr-sound-listen').click();
    await expect(page.locator('#cromo-home .cr-inline-player iframe')).toHaveCount(1);
    await last.locator('button.cr-sound-listen').click();
    await expect(page.locator('#cromo-home #discos iframe')).toHaveCount(0);
    await noOverflow(page);
  }
});


test('Cromo SoundCloud player stays inline beside ESCUCHAR, never floating', async ({page}) => {
  test.setTimeout(120_000);
  await mockSoundCloud(page);
  for (const width of [390, 768, 900, 1280, 1440]) {
    await mount(page, {width, payload: {...data, sets: soundSets}});
    const feature = page.locator('#cromo-home .cr-feat');
    const row = page.locator('#cromo-home .cr-sound-row').first();
    const trigger = feature.locator('button.cr-sound-listen');
    await expect(page.locator('#cromo-home .cr-inline-player')).toHaveCount(0);
    await trigger.click();
    const player = feature.locator('.cr-sound-actions>.cr-inline-player');
    await expect(player).toBeVisible();
    await expect(player.locator('iframe')).toHaveCount(1);
    await expect(player).toHaveCSS('position', 'relative');
    await expect(player.locator('iframe')).toHaveCSS('filter', /invert/);
    await expect(trigger).toHaveAttribute('aria-controls', /cromo-inline-player-/);
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(trigger).toHaveText('CERRAR');
    await expect(row.locator('iframe')).toHaveCount(0);
    const rects = await feature.evaluate(card => {
      const group = card.querySelector('.cr-sound-actions');
      const trigger = group.querySelector('button.cr-sound-listen');
      const panel = group.querySelector('.cr-inline-player');
      return {button:trigger.getBoundingClientRect().top, player:panel.getBoundingClientRect().top};
    });
    expect(rects.player).toBeGreaterThan(rects.button);
    await row.locator('button.cr-sound-listen').click();
    await expect(feature.locator('iframe')).toHaveCount(0);
    await expect(row.locator('.cr-sound-actions>.cr-inline-player iframe')).toHaveCount(1);
    await expect(row.locator('button.cr-sound-listen')).toHaveText('CERRAR');
    await row.locator('button.cr-sound-listen').click();
    await expect(page.locator('#cromo-home .cr-inline-player')).toHaveCount(0);
    await expect(row.locator('button.cr-sound-listen')).toBeFocused();
    await noOverflow(page);
  }
});

test('Cromo mobile menu is visible, keyboard-safe and opens real Sets destination', async ({ page }) => {
  test.setTimeout(120_000);
  for (const width of [360, 390, 430, 768, 900]) {
    await mount(page, { width, payload: data, reduced: width === 430 });
    const trigger = page.locator('#cromo-home .cr-nav-toggle');
    const menu = page.locator('#cromo-section-nav');
    await expect(trigger).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(menu).toBeHidden();
    await trigger.click();
    await expect(menu).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(menu.locator('a[href="#discos"]')).toBeVisible();
    await expect(menu.locator('a[href="#sonido"]')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(trigger).toBeFocused();
    await trigger.click();
    await menu.locator('a[href="#discos"]').click();
    await expect(menu).toBeHidden();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(page).toHaveURL(/#discos$/);
    await noOverflow(page);
  }
});

test('Cromo hides every empty editorial section and its nav link without hiding contact', async ({ page }) => {
  const empty = { events: [], artists: [], sets: [], memories: [], blog: [],
    archive: { events: [] }, relations: {events:{},artists:{}} };
  await mount(page, { width: 390, payload: empty });
  for (const id of ['noches', 'artistas', 'sonido', 'discos', 'memorias', 'journal', 'conectado']) {
    await expect(page.locator('#cromo-home #' + id)).toHaveCount(0);
    await expect(page.locator('#cromo-section-nav a[href="#' + id + '"]')).toHaveCount(0);
  }
  await expect(page.locator('#cromo-home #contacto')).toBeAttached();
  await expect(page.locator('#cromo-section-nav a[href="#contacto"]')).toHaveCount(1);
  await expect(page.locator('#cromo-home .cr-cta a')).toHaveCount(0);
  await page.locator('#cromo-home .cr-nav-toggle').click();
  await expect(page.locator('#cromo-section-nav a')).toHaveCount(1);
  await noOverflow(page);
});

test('Cromo adjusts nav to published data on each successful CMS reload', async ({ page }) => {
  const onlySets = { events: [], artists: [], sets: [set], memories: [], blog: [],
    archive: {events:[]}, relations:{events:{},artists:{}} };
  await mount(page, { width: 1440, payload: onlySets });
  await expect(page.locator('#cromo-section-nav a[href="#discos"]')).toBeVisible();
  await expect(page.locator('#cromo-home #sonido')).toBeAttached();
  await expect(page.locator('#cromo-home #discos')).toBeAttached();
  await expect(page.locator('#cromo-home #artistas')).toHaveCount(0);
  await expect(page.locator('#cromo-home #journal')).toHaveCount(0);
  await expect(page.locator('#cromo-home .cr-nav-toggle')).toBeHidden();
  await noOverflow(page);
});

test('Cromo failed public API does not treat data as an empty published catalog', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await page.route('**/api/public.php', route => route.fulfill({ status: 503,
    contentType: 'application/json', body: '{"ok":false}' }));
  await page.route('**/api/hero-slider.php', route => route.fulfill({
    contentType: 'application/json', body: '{"ok":true,"data":null}' }));
  await page.route(url + '*', route => route.fulfill({
    contentType: 'text/html', body: '<html><body><main id="legacy">FALLBACK</main></body></html>' }));
  await page.goto(url + '?failed-api-nav=' + (++fixtureSequence));
  await page.addScriptTag({ content: runtime });
  await expect(page.locator('#cromo-home')).toHaveCount(0);
  await expect(page.locator('#legacy')).toBeVisible();
});
