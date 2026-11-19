import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';

const preview = readFileSync(join(process.cwd(), 'discadmin/artist-hero-preview.js'), 'utf8');
const url = 'http://127.0.0.1:4173/discadmin/artist-import-fixture.html';
const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/hZ0AAAAASUVORK5CYII=', 'base64');

async function open(page) {
  await page.route('**/discadmin/artist-import-fixture.html', route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:'<!doctype html><html><body><div id="mcontent">'
      + '<input id="f_photo" value="/uploads/old.jpg"><input id="f_name" value="Artista">'
      + '<input id="f_instagram_url" value=""><input type="checkbox" id="f_is_collective_member">'
      + '<label for="artistPhotoImportUrl">Pegar enlace de imagen</label>'
      + '<input id="artistPhotoImportUrl"><button id="artistPhotoImportBtn">Importar foto</button>'
      + '<p id="artistPhotoImportStatus" role="status"></p>'
      + '<div class="thumbcell"><img class="thumb" src="/uploads/old.jpg"></div>'
      + '<div data-artist-hero data-viewport="desktop">'
      + '<img data-hero-preview-img src="/uploads/old.jpg"><img data-artist-card-preview-img src="/uploads/old.jpg">'
      + '<span data-artist-photo-source role="status"></span>'
      + '</div></div><script>let csrf="artist-csrf";</script><script>'
      + preview + '</script></body></html>'
  }));
  await page.goto(url);
}

test('importing HTTPS image uploads bytes to Media and associates its saved path with artist form', async ({page}) => {
  const calls = [];
  await open(page);
  await page.route('**/api/media-import-url.php', route => {
    calls.push('download');
    expect(route.request().method()).toBe('POST');
    expect(route.request().headers()['x-csrf-token']).toBe('artist-csrf');
    expect(JSON.parse(route.request().postData()).url).toBe('https://assets.example.test/photo.png');
    return route.fulfill({status:200,contentType:'image/png',body:image});
  });
  await page.route('**/api/media-library.php?action=upload', route => {
    calls.push('upload');
    expect(route.request().headers()['x-csrf-token']).toBe('artist-csrf');
    const data = route.request().postDataBuffer().toString('latin1');
    expect(data).toContain('name="file"');
    expect(data).toContain('filename="importada.png"');
    return route.fulfill({
      contentType:'application/json',
      body:JSON.stringify({ok:true,data:{id:17,file_path:'/uploads/media/2026/11/photo.png'}})
    });
  });
  await page.route('**/uploads/media/2026/11/photo.png', route => route.fulfill({contentType:'image/png',body:image}));
  await page.locator('#artistPhotoImportUrl').fill('https://assets.example.test/photo.png');
  await page.getByRole('button',{name:'Importar foto'}).click();
  await expect(page.locator('#f_photo')).toHaveValue('/uploads/media/2026/11/photo.png');
  await expect(page.locator('[data-hero-preview-img]')).toHaveAttribute('src',/\/uploads\/media\/2026\/11\/photo.png/);
  await expect(page.locator('#artistPhotoImportStatus')).toContainText('registrada en Media');
  expect(calls).toEqual(['download','upload']);
  await expect(page.locator('[data-artist-meta-try]')).toHaveCount(0);
});

test('invalid link and remote failure preserve the previous photo without closing editor', async ({page}) => {
  await open(page);
  const previous = '/uploads/old.jpg';
  await page.locator('#artistPhotoImportUrl').fill('http://private.test/image.png');
  await page.locator('#artistPhotoImportBtn').click();
  await expect(page.locator('#artistPhotoImportStatus')).toContainText('Enlace no válido');
  await expect(page.locator('#f_photo')).toHaveValue(previous);
  await page.route('**/api/media-import-url.php', route => route.fulfill({
    status:504,contentType:'application/json',
    body:JSON.stringify({ok:false,error:'REMOTE_TIMEOUT'})
  }));
  await page.locator('#artistPhotoImportUrl').fill('https://assets.example.test/unavailable.png');
  await page.locator('#artistPhotoImportBtn').click();
  await expect(page.locator('#artistPhotoImportStatus')).toContainText('sitio no respondió');
  await expect(page.locator('#f_photo')).toHaveValue(previous);
  await expect(page.locator('#artistPhotoImportBtn')).toBeEnabled();
  await expect(page.locator('#mcontent')).toBeVisible();
});
