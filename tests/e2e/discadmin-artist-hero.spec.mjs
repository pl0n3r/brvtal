import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';

const heroRuntime = readFileSync(join(process.cwd(),'discadmin/artist-hero-preview.js'),'utf8');
const heroStyles = readFileSync(join(process.cwd(),'discadmin/artist-hero-preview.css'),'utf8');
const url = 'http://127.0.0.1:4173/discadmin/hero-framing-e2e.html';

async function mount(page) {
  const ranges = ['desktop','mobile'].map(viewport => {
    const settings = [
      ['zoom',0.8,2.2,0.05,viewport==='desktop'?1.2:1],
      ['x',0,100,1,viewport==='desktop'?65:50],
      ['y',0,100,1,viewport==='desktop'?27:50]
    ];
    return '<section class="artist-hero-controls" data-hero-controls="' + viewport + '" ' + (viewport==='mobile'?'hidden':'') + '>'
      + settings.map(([key,min,max,step,value]) =>
        `<label for="f_hero_${viewport}_${key}">${key}
          <input id="f_hero_${viewport}_${key}" type="range" min="${min}"
            max="${max}" step="${step}" value="${value}">
          <output data-hero-output="f_hero_${viewport}_${key}"></output>
        </label>`).join('') + '</section>';
  }).join('');
  const html = `<!doctype html><html><head><meta charset="utf-8"><style>${heroStyles}</style></head><body>
    <div id="mcontent">
      <input id="f_name" value="PL0N3R">
      <input id="f_photo" value="/uploads/media/pl0n3r.jpg">
      <div data-artist-hero data-viewport="desktop">
        <button type="button" data-hero-switch="desktop" aria-pressed="true">ESCRITORIO</button>
        <button type="button" data-hero-switch="mobile" aria-pressed="false">MÓVIL</button>
        <div class="artist-hero-stage">
          <img data-hero-preview-img src="/uploads/media/pl0n3r.jpg" alt="">
          <strong data-hero-preview-name>PL0N3R</strong>
        </div>
        ${ranges}
        <button type="button" data-hero-reset>RESTABLECER ESTA VISTA</button>
      </div>
    </div>
    <script>${heroRuntime}</script>
  </body></html>`;
  await page.route(url,route => route.fulfill({contentType:'text/html',body:html}));
  await page.route('**/uploads/media/**',route => route.fulfill({
    status:200,contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg"/>'
  }));
  await page.goto(url);
  await expect(page.locator('[data-artist-hero]')).toHaveAttribute('data-hero-bound','1');
}

async function setSlider(page,selector,value) {
  await page.locator(selector).evaluate((input,next) => {
    input.value=String(next);
    input.dispatchEvent(new Event('input',{bubbles:true}));
  }, value);
}

test('artist hero preview adjusts independent desktop zoom and focus without saving',async ({page}) => {
  await mount(page);
  const image = page.locator('[data-hero-preview-img]');
  await expect(image).toHaveCSS('transform','matrix(1.2, 0, 0, 1.2, 0, 0)');
  await expect(image).toHaveCSS('object-position','65% 27%');
  await setSlider(page,'#f_hero_desktop_zoom',1.8);
  await expect(image).toHaveCSS('transform','matrix(1.8, 0, 0, 1.8, 0, 0)');
  await setSlider(page,'#f_hero_desktop_x',78);
  await setSlider(page,'#f_hero_desktop_y',14);
  await expect(image).toHaveCSS('object-position','78% 14%');
  await expect(page.locator('[data-hero-output="f_hero_desktop_zoom"]')).toHaveText('180%');
  const values = await page.evaluate(() => BRVTALArtistHeroPreview.values());
  expect(values.desktop).toEqual({zoom:1.8,x:78,y:14});
  expect(values.mobile).toEqual({zoom:1,x:50,y:50});
});

test('mobile has a separate framing, returning to desktop restores its preview',async ({page}) => {
  await mount(page);
  await page.locator('[data-hero-switch=mobile]').click();
  await expect(page.locator('[data-artist-hero]')).toHaveAttribute('data-viewport','mobile');
  await expect(page.locator('[data-hero-controls="desktop"]')).toBeHidden();
  await expect(page.locator('[data-hero-controls="mobile"]')).toBeVisible();
  const image = page.locator('[data-hero-preview-img]');
  await setSlider(page,'#f_hero_mobile_zoom',2);
  await setSlider(page,'#f_hero_mobile_y',80);
  await expect(image).toHaveCSS('object-position','50% 80%');
  await expect(image).toHaveCSS('transform','matrix(2, 0, 0, 2, 0, 0)');
  await page.locator('[data-hero-switch=desktop]').click();
  await expect(image).toHaveCSS('object-position','65% 27%');
  await expect(image).toHaveCSS('transform','matrix(1.2, 0, 0, 1.2, 0, 0)');
  const values = await page.evaluate(() => BRVTALArtistHeroPreview.values());
  expect(values.mobile).toEqual({zoom:2,x:50,y:80});
});

test('reset only applies to active viewport and name/photo update live',async ({page}) => {
  await mount(page);
  await page.locator('[data-hero-switch=mobile]').click();
  await setSlider(page,'#f_hero_mobile_zoom',1.9);
  await page.locator('[data-hero-reset]').click();
  const values = await page.evaluate(() => BRVTALArtistHeroPreview.values());
  expect(values.mobile).toEqual({zoom:1,x:50,y:50});
  expect(values.desktop).toEqual({zoom:1.2,x:65,y:27});
  await page.locator('#f_name').fill('PL0N3R DJ');
  await page.locator('#f_photo').fill('/uploads/media/new-cover.jpg');
  await expect(page.locator('[data-hero-preview-name]')).toHaveText('PL0N3R DJ');
  await expect(page.locator('[data-hero-preview-img]'))
    .toHaveAttribute('src','http://127.0.0.1:4173/uploads/media/new-cover.jpg');
  for (const unsafe of [
    'javascript:alert(1)', 'data:image/svg+xml,<svg onload=alert(1)>',
    '//malicious.example/image.jpg', '/uploads/<img src=x onerror=alert(1)>.png',
    'http://evil.example/%3Cscript%3E', '/admin/private.png'
  ]) {
    await page.locator('#f_photo').fill(unsafe);
    if (unsafe.startsWith('http://evil.example/')) continue;
    await expect(page.locator('[data-hero-preview-img]')).not.toHaveAttribute('src');
  }
  await page.locator('#f_photo').fill('https://images.example.org/portrait.jpg');
  await expect(page.locator('[data-hero-preview-img]'))
    .toHaveAttribute('src','https://images.example.org/portrait.jpg');
});


test('image URL guard rejects encoded local traversal and credentials in both viewports',async ({page}) => {
  await mount(page);
  for (const viewport of ['desktop','mobile']) {
    await page.locator('[data-hero-switch="' + viewport + '"]').click();
    for (const unsafe of [
      '/uploads/../admin/private.png',
      '/uploads/%2e%2e/admin/private.png',
      'https://user:password@images.example.org/portrait.jpg',
      'file:///etc/passwd',
      'vbscript:msgbox(1)'
    ]) {
      await page.locator('#f_photo').fill(unsafe);
      await expect(page.locator('[data-hero-preview-img]')).not.toHaveAttribute('src');
    }
    await page.locator('#f_photo').fill('/uploads/media/portrait.jpg');
    await expect(page.locator('[data-hero-preview-img]'))
      .toHaveAttribute('src','http://127.0.0.1:4173/uploads/media/portrait.jpg');
    await page.locator('#f_photo').fill('https://images.example.org/portrait.jpg');
    await expect(page.locator('[data-hero-preview-img]'))
      .toHaveAttribute('src','https://images.example.org/portrait.jpg');
  }
});
