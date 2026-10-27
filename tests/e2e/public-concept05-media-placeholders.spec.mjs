import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const heroRuntime = readFileSync(join(root, 'js/public-concept05-hero.js'), 'utf8');
const placeholder = readFileSync(join(root, 'assets/placeholders/concept05/hero-face.webp'));

for (const width of [390, 1440]) {
  test('published CMS media has no broken image at ' + width + ' and preserves reference evidence', async ({page}) => {
    await page.setViewportSize({width, height:844});
    await page.route('**/uploads/media/fixture/hero.webp', route =>
      route.fulfill({contentType:'image/webp',body:placeholder}));
    await page.setContent('<!doctype html><html lang="es"><head><base href="http://127.0.0.1:4173/"></head><body>'
      + '<section class="home-phase-a-hero">'
      + '<img hidden data-c5-editorial-media="hero_face" alt="BRVTAL synthetic hero">'
      + '<img hidden data-c5-editorial-media="crowd" alt="BRVTAL synthetic crowd">'
      + '</section></body></html>');
    await page.evaluate(() => {
      window.BRVTALPublicDataPromise = Promise.resolve({data:{settings:{site:{
        c05_media_hero_face: {id:33,url:'/uploads/media/fixture/hero.webp',status:'published'},
        c05_media_crowd: {id:34,url:'assets/placeholders/concept05/crowd.webp',status:'published'}
      }}}});
    });
    await page.addScriptTag({content:heroRuntime});
    const hero = page.locator('[data-c5-editorial-media=hero_face]');
    await expect(hero).toHaveAttribute('src','/uploads/media/fixture/hero.webp');
    await expect(hero).toBeVisible();
    await expect(page.locator('[data-c5-editorial-media=crowd]')).toBeHidden();
    const broken = await page.locator('img').evaluateAll(images =>
      images.filter(img => img.hasAttribute('src') && (!img.complete || img.naturalWidth === 0)).length);
    expect(broken).toBe(0);
    mkdirSync(join(root,'test-results','public-concept05-media'),{recursive:true});
    await page.screenshot({path:join(root,'test-results','public-concept05-media','cms-'+width+'.png'),fullPage:true});
    // Reference comparison is a separate OWNER review, not a pixel-equality assertion.
    expect(readFileSync(join(root,'docs/reference/home-concept05-owner-reference-v2.png')).length).toBeGreaterThan(100);
  });
}

test('unsafe CMS image never becomes a broken or navigable URL', async ({page}) => {
  await page.setContent('<!doctype html><section class="home-phase-a-hero"><img hidden data-c5-editorial-media="hero_face"></section>');
  await page.evaluate(() => { window.BRVTALPublicDataPromise = Promise.resolve({
    data:{settings:{site:{c05_media_hero_face:{id:42,url:'javascript:alert(1)',status:'published'}}}}
  }); });
  await page.addScriptTag({content:heroRuntime});
  const image = page.locator('[data-c5-editorial-media=hero_face]');
  await expect(image).not.toHaveAttribute('src',/./);
  await expect(image).toBeHidden();
});
