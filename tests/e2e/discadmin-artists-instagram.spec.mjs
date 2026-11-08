import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const runtime = readFileSync(join(root, 'discadmin/artist-hero-preview.js'), 'utf8');
const css = readFileSync(join(root, 'discadmin/artist-hero-preview.css'), 'utf8');
const url = 'http://127.0.0.1:4173/discadmin/artist-instagram-e2e.html';
const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1400" height="1600"><rect width="1400" height="1600" fill="#a41e32"/></svg>';

async function mount(page, { width = 390, member = false, instagram = 'https://instagram.com/professional.dj/' } = {}) {
  await page.setViewportSize({ width, height: 900 });
  const sliders = ['desktop','mobile'].map(view => '<div class="artist-hero-controls" data-hero-controls="' + view + '" ' + (view === 'mobile' ? 'hidden' : '') + '>' +
    ['zoom','x','y'].map(key => '<label><input type="range" id="f_hero_' + view + '_' + key + '" min="' + (key === 'zoom' ? '0.8' : '0') + '" max="' + (key === 'zoom' ? '2.2' : '100') + '" step="' + (key === 'zoom' ? '0.05' : '1') + '" value="' + (key === 'zoom' ? '1' : '50') + '"><output data-hero-output="f_hero_' + view + '_' + key + '"></output></label>').join('') + '</div>').join('');
  const body = '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + css + '</style></head><body><div id="mcontent"><input id="f_name" value="DJ TEST"><input id="f_photo" value="/uploads/media/authorized.svg"><input id="f_instagram_url" value="' + instagram + '"><input type="checkbox" id="f_is_collective_member" ' + (member ? 'checked' : '') + '><div data-artist-hero data-viewport="desktop"><button data-hero-switch="desktop" aria-pressed="true">Escritorio</button><button data-hero-switch="mobile" aria-pressed="false">Móvil</button><div class="artist-hero-stage"><img data-hero-preview-img alt=""><strong data-hero-preview-name></strong><small data-hero-preview-member></small></div><figure class="artist-card-preview"><div class="artist-card-preview-image"><img data-artist-card-preview-img alt=""></div><figcaption><strong data-artist-card-preview-name></strong><span data-artist-card-preview-member></span></figcaption></figure><p data-artist-photo-source role="status"></p><button data-artist-meta-try>Probar Meta</button>' + sliders + '<button data-hero-reset>Restablecer</button></div></div></body></html>';
  await page.route(url, route => route.fulfill({ contentType: 'text/html; charset=utf-8', body }));
  await page.route('**/uploads/media/authorized.svg', route => route.fulfill({ contentType: 'image/svg+xml', body: svg }));
  await page.goto(url);
  await page.addScriptTag({ content: runtime });
  await expect(page.locator('[data-artist-hero]')).toHaveAttribute('data-hero-bound','1');
}

test('artist card and hero preview preserve uncropped image at 390 and 1440', async ({ page }) => {
  for (const width of [390, 1440]) {
    await mount(page, { width });
    await expect(page.locator('[data-artist-card-preview-img]')).toHaveCSS('object-fit', 'contain');
    await expect(page.locator('[data-hero-preview-img]')).toHaveCSS('object-fit', 'contain');
    await expect(page.locator('[data-artist-card-preview-member]')).toHaveText('ARTISTA INVITADO');
    await page.locator('#f_is_collective_member').check();
    await expect(page.locator('[data-artist-card-preview-member]')).toHaveText('MIEMBRO DEL COLECTIVO BRVTAL');
    await page.locator('[data-hero-switch="mobile"]').click();
    await expect(page.locator('[data-artist-hero]')).toHaveAttribute('data-viewport','mobile');
    const slider = page.locator('#f_hero_mobile_zoom');
    await slider.evaluate(el => { el.value = '1.5'; el.dispatchEvent(new Event('input', { bubbles:true })); });
    await expect(page.locator('[data-hero-preview-img]')).toHaveCSS('transform','matrix(1.5, 0, 0, 1.5, 0, 0)');
  }
});

test('official photo is used only if sufficiently large and authorized; failure keeps CMS', async ({ page }) => {
  await page.route('**/api/instagram-profile-photo.php?username=professional.dj', route =>
    route.fulfill({ contentType:'image/svg+xml', body:svg }));
  await mount(page, { width:1440 });
  await page.locator('[data-artist-meta-try]').click();
  await expect(page.locator('[data-artist-photo-source]')).toContainText('Foto oficial autorizada:');
  await expect(page.locator('[data-artist-card-preview-img]')).toHaveAttribute('src', /instagram-profile-photo.php/);

  await page.locator('#f_instagram_url').fill('https://instagram.com/unapproved.user/');
  await page.route('**/api/instagram-profile-photo.php?username=unapproved.user', route =>
    route.fulfill({ status:404, body:'' }));
  await page.locator('[data-artist-meta-try]').click();
  await expect(page.locator('[data-artist-photo-source]')).toContainText('Meta no permite');
  await expect(page.locator('[data-artist-card-preview-img]')).toHaveAttribute('src', /uploads\/media\/authorized.svg/);
});
