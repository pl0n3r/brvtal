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


// This test checks real, server-side assembled Concept 05 markup. All media and
// public settings are synthetic; no production endpoints, accounts or cookies.
test('PHP-composed C05 displays five published CMS images at 390 and 1440', async ({page}, testInfo) => {
  const { spawnSync } = await import('node:child_process');
  const { existsSync } = await import('node:fs');
  const { resolve, extname, sep } = await import('node:path');
  const source = [
    "require 'config/public_home.php';",
    "$html = file_get_contents('index.html');",
    "foreach (['nav','hero','experience','nights','artists','sound','memories','journal','connected','footer'] as $part) {",
    "  $marker = '<!-- BRVTAL-HOME-PARTIAL:' . $part . ' -->';",
    "  if (substr_count($html,$marker) !== 1) { throw new RuntimeException('missing partial'); }",
    "  $html = str_replace($marker,file_get_contents('partials/home/' . $part . '.html'),$html);",
    "}",
    "echo brvtal_public_home_identity($html);"
  ].join('\n');
  const php = spawnSync('php', ['-r', source], {cwd:root, encoding:'utf8', timeout:30000});
  expect(php.status, php.stderr).toBe(0);
  const shell = php.stdout.replace('<body data-scene="CORE">', '<body data-scene="CORE" data-concept="05">');
  expect(shell).toContain('class="hero scene home-phase-a-hero"');
  const mediaSources = ['hero-face','crowd','artists','nights','memories'];
  const publicSite = {};
  for (const [index, name] of mediaSources.entries()) {
    publicSite['c05_media_' + name.replace('-','_')] = {
      id:100 + index,
      url:'/uploads/media/fixture/' + name + '.webp',
      status:'published'
    };
  }
  const composerUrl = 'http://127.0.0.1:4173/c05-media-php-composed.html';
  const fixtureBase = '/uploads/media/fixture/';
  const webp = Object.fromEntries(mediaSources.map(name =>
    [name + '.webp', readFileSync(join(root,'assets/placeholders/concept05',name+'.webp'))]));
  const mimes = {'.js':'application/javascript','.css':'text/css',
    '.png':'image/png','.webp':'image/webp','.jpeg':'image/jpeg','.jpg':'image/jpeg'};
  await page.addInitScript(site => {
    window.BRVTALPublicDataPromise = Promise.resolve({payload:{data:{settings:{site}}}});
    window.BRVTALRuntimeReady = Promise.resolve();
  }, publicSite);
  await page.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.href === composerUrl) return route.fulfill({
      contentType:'text/html; charset=utf-8',body:shell
    });
    if (url.origin !== 'http://127.0.0.1:4173') return route.abort();
    if (url.pathname.startsWith(fixtureBase)) {
      const name = url.pathname.slice(fixtureBase.length);
      return Object.hasOwn(webp,name)
        ? route.fulfill({contentType:'image/webp',body:webp[name]})
        : route.abort();
    }
    let file;
    try { file = resolve(root,decodeURIComponent(url.pathname).replace(/^\/+/,'')); }
    catch { return route.abort(); }
    if (!file.startsWith(root + sep) || !existsSync(file)) return route.abort();
    const ext = extname(file);
    if (!Object.hasOwn(mimes,ext)) return route.abort();
    // No real public API/network requests are made by this fixture.
    if (ext === '.js' && !url.pathname.endsWith('/js/public-concept05-hero.js')
      && !url.pathname.endsWith('/js/public-concept05-shell.js')) return route.abort();
    return route.fulfill({contentType:mimes[ext],body:readFileSync(file)});
  });
  for (const width of [390,1440]) {
    await page.setViewportSize({width,height:900});
    await page.goto(composerUrl,{waitUntil:'load'});
    const images = page.locator('img[data-c5-editorial-media]');
    await expect(images).toHaveCount(5);
    await expect.poll(() => images.evaluateAll(nodes =>
      nodes.filter(node => node.complete && node.naturalWidth > 0 && !node.hidden).length
    )).toBe(5);
    const broken = await images.evaluateAll(nodes =>
      nodes.filter(node => !node.src.startsWith('http://127.0.0.1:4173/uploads/media/fixture/')
        || node.naturalWidth === 0).length);
    expect(broken).toBe(0);
    const screenshot = await page.screenshot({fullPage:true});
    await testInfo.attach('php-c05-cms-synthetic-'+width,{body:screenshot,contentType:'image/png'});
  }
  await testInfo.attach('owner-reference-file', {
    body:readFileSync(join(root,'docs/reference/home-concept05-owner-reference-v2.png')),
    contentType:'image/png'
  });
});
