import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const css = ['css/public-entity.css', 'css/public-cromo.css', 'css/public-cromo-pages.css']
  .map(path => readFileSync(join(root, path), 'utf8')).join('\n');
// Generate the real shared navigation from PHP rather than duplicating its six links.
const topbar = execFileSync('php', ['-r',
  'require "config/public_cromo_chrome.php"; echo brvtalPublicCromoTopbar();',
], { cwd: root, encoding: 'utf8' });
const routes = [
  ['evento', 'GENESIS', '/events/genesis'],
  ['artista', 'ARTIST ONE', '/artists/artist-one'],
  ['privacidad', 'PRIVACIDAD Y MIS DERECHOS', '/privacy'],
  ['404', 'PÁGINA NO ENCONTRADA', '/missing'],
];
const picture = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="800"><rect width="600" height="800" fill="#777"/></svg>';
function markup(kind, heading) {
  return '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<style>html,body{margin:0;padding:0}img{max-width:100%}</style><style>' + css + '</style></head>'
    + '<body class="cromo-page entity-page">' + topbar
    + '<main id="main-content"><article class="entity-hero">'
    + '<div class="entity-image"><img src="data:image/svg+xml,' + encodeURIComponent(picture) + '" alt="Póster ' + kind + '"></div>'
    + '<div class="entity-copy"><div class="entity-kicker">BRVTAL / ' + kind + '</div>'
    + '<h1 data-text="' + heading + '">' + heading + '</h1>'
    + '<div class="entity-actions"><a href="/">VOLVER AL INICIO</a></div></div></article>'
    + '<section class="entity-statement"><div class="entity-section-label">BRVTAL / ' + kind + '</div><p>Contenido público de prueba.</p></section>'
    + '</main></body></html>';
}
async function inspect(page, width, reduced, kind, heading, path) {
  await page.setViewportSize({ width, height: 900 });
  await page.emulateMedia({ reducedMotion: reduced ? 'reduce' : 'no-preference' });
  await page.route('http://127.0.0.1:4173' + path, route => route.fulfill({
    contentType: 'text/html; charset=utf-8', body: markup(kind, heading),
  }));
  await page.goto('http://127.0.0.1:4173' + path);
  await expect(page.locator('.entity-nav .cp-nav a')).toHaveCount(6);
  const expected = ['noches', 'artistas', 'sonido', 'memorias', 'journal', 'contacto'];
  for (const section of expected) {
    await expect(page.locator('.cp-nav a[href="/#' + section + '"]')).toHaveCount(1);
  }
  await expect(page.locator('.entity-nav [data-cromo-sound]')).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('.entity-image img')).toBeVisible();
  await expect(page.locator('.entity-hero h1')).toHaveAttribute('data-text', heading);
  const poster = await page.locator('.entity-image img').evaluate(el => ({
    filter: getComputedStyle(el).filter, fit: getComputedStyle(el).objectFit,
  }));
  expect(poster.filter).toContain('grayscale(1)');
  expect(poster.fit).toBe('cover');
  const metrics = await page.evaluate(() => ({
    width: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.width + 1);
  if (reduced) {
    const motion = await page.locator('.entity-hero h1').evaluate(el => ({
      before: getComputedStyle(el, '::before').animationName,
      after: getComputedStyle(el, '::after').animationName,
    }));
    expect(motion.before).toBe('none');
    expect(motion.after).toBe('none');
  }
}

for (const [kind, heading, path] of routes) {
  test(kind + ' uses the shared Cromo top bar, duotone poster and readable title at 390/1440', async ({ page }) => {
    for (const width of [390, 1440]) {
      await inspect(page, width, false, kind, heading, path);
      await inspect(page, width, true, kind, heading, path);
    }
  });
}


// Render the actual PHP document so a handwritten fixture cannot hide missing assets.
const contactMarkup = execFileSync('php', ['-r',
  'require "config/public_contact_page.php";'
    + ' if (!function_exists("brvtal_public_seo_tags")) {function brvtal_public_seo_tags(array $seo): string {return "";}}'
    + ' echo brvtal_public_contact_page(["title"=>"Contact — BRVTAL","description"=>"Contacto de prueba"]);',
], { cwd: root, encoding: 'utf8' });
const contactCss = readFileSync(join(root, 'css/contact-social.css'), 'utf8');
const soundJs = readFileSync(join(root, 'js/public-cromo-sound.js'), 'utf8');
const bindingJs = readFileSync(join(root, 'js/public-cromo-pages.js'), 'utf8');
async function openContactSound(page, width) {
  await page.setViewportSize({width, height:844});
  await page.addInitScript(() => {
    window.__createdCromoContexts = 0;
    class FakeAudioContext {
      constructor() { window.__createdCromoContexts++; this.state='suspended'; this.sampleRate=44100; this.currentTime=0; this.destination={}; }
      createGain() { return {gain:{setTargetAtTime(){},setValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){return this}}; }
      createBuffer() { return {getChannelData(){return new Float32Array(128)}}; }
      createBufferSource() { return {connect(){return this},start(){},stop(){}}; }
      createOscillator() { return {frequency:{setValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){return this},start(){},stop(){}}; }
      createBiquadFilter() { return {frequency:{setValueAtTime(){},exponentialRampToValueAtTime(){}},Q:{},connect(){return this}}; }
      addEventListener() {}
      resume() { this.state='running'; return Promise.resolve(); }
    }
    window.AudioContext = FakeAudioContext;
  });
  await page.route('**/__cromo-contact', route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:contactMarkup.replace('</head>','<style>'+contactCss+'</style></head>'),
  }));
  await page.route('**/js/public-cromo.js', route => route.fulfill({contentType:'text/javascript',body:''}));
  await page.route('**/js/public-contact.js', route => route.fulfill({contentType:'text/javascript',body:''}));
  await page.route('**/js/public-cromo-sound.js', route => route.fulfill({contentType:'text/javascript',body:soundJs}));
  await page.route('**/js/public-cromo-pages.js', route => route.fulfill({contentType:'text/javascript',body:bindingJs}));
  await page.goto('http://127.0.0.1:4173/__cromo-contact');
  const button=page.locator('.contact-page-nav [data-cromo-sound]');
  await expect(button).toHaveCount(1);
  await expect(button).toBeVisible();
  await expect(button).toHaveAttribute('aria-pressed','false');
  await expect(button).toHaveAccessibleName('Activar sonido');
  expect(await page.evaluate(() => window.__createdCromoContexts)).toBe(0);
  expect(await button.evaluate(el => parseFloat(getComputedStyle(el).minHeight))).toBeGreaterThanOrEqual(44);
  await expect(page.locator('#brvtalContactForm')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  return button;
}
for (const width of [390,1440]) {
  test('real contact sound toggles on gesture and keyboard at ' + width, async ({page}) => {
    const button=await openContactSound(page,width);
    await button.click();
    await expect(button).toHaveAttribute('aria-pressed','true');
    await expect(button).toHaveAccessibleName('Apagar el pulso');
    await button.focus();
    await page.keyboard.press('Space');
    await expect(button).toHaveAttribute('aria-pressed','false');
    await page.keyboard.press('Enter');
    await expect(button).toHaveAttribute('aria-pressed','true');
    await page.keyboard.press('Enter');
    await expect(button).toHaveAttribute('aria-pressed','false');
  });
}
test('contact sound toggles by touch at 390 without submitting form', async ({browser}) => {
  const context=await browser.newContext({viewport:{width:390,height:844},hasTouch:true});
  try {
    const page=await context.newPage();
    const button=await openContactSound(page,390);
    await button.tap();
    await expect(button).toHaveAttribute('aria-pressed','true');
    await button.tap();
    await expect(button).toHaveAttribute('aria-pressed','false');
    await expect(page.locator('#brvtalContactForm')).toBeVisible();
  } finally { await context.close(); }
});

// Render /contact from its actual PHP generator and compare against the shared links.
test('contact Cromo navigation is keyboard accessible and occupies two rows at 390px', async ({page}) => {
  const sharedHrefs = Array.from(topbar.matchAll(/href="(\/#[^"]+)"/g), match => match[1]);
  expect(sharedHrefs).toHaveLength(6);
  for (const width of [390, 1440]) {
    await openContactSound(page, width);
    const links = page.locator('.contact-page-nav nav.contact-page-cromo-nav a');
    await expect(links).toHaveCount(6);
    expect(await links.evaluateAll(nodes => nodes.map(node => node.getAttribute('href')))).toEqual(sharedHrefs);
    const boxes = await links.evaluateAll(nodes => nodes.map(node => {
      const rect = node.getBoundingClientRect();
      return {left:rect.left,right:rect.right,top:Math.round(rect.top),bottom:rect.bottom};
    }));
    const rows = [...new Set(boxes.map(box => box.top))];
    expect(rows).toHaveLength(width === 390 ? 2 : 1);
    if (width === 390) {
      expect(rows.map(row => boxes.filter(box => box.top === row).length)).toEqual([3,3]);
    }
    for (const row of rows) {
      const sorted = boxes.filter(box => box.top === row).sort((a,b) => a.left - b.left);
      for (let index=1; index<sorted.length; index++) {
        expect(sorted[index].left).toBeGreaterThanOrEqual(sorted[index-1].right - 1);
      }
    }
    const bounds = await page.evaluate(() => {
      const header = document.querySelector('.contact-page-nav').getBoundingClientRect();
      const menu = document.querySelector('.contact-page-cromo-nav').getBoundingClientRect();
      const title = document.querySelector('#contactTitle').getBoundingClientRect();
      return {headerBottom:header.bottom,menuTop:menu.top,titleTop:title.top,
        scrollWidth:document.documentElement.scrollWidth,viewport:document.documentElement.clientWidth};
    });
    expect(bounds.menuTop).toBeGreaterThan(35);
    expect(bounds.titleTop).toBeGreaterThanOrEqual(bounds.headerBottom-1);
    expect(bounds.scrollWidth).toBeLessThanOrEqual(bounds.viewport+1);
    await links.first().focus();
    for (let index=0; index<6; index++) {
      await expect(links.nth(index)).toBeFocused();
      if (index<5) await page.keyboard.press('Tab');
    }
    await expect(page.locator('.contact-page-nav [data-cromo-sound]')).toBeVisible();
    await expect(page.locator('#brvtalContactForm')).toBeVisible();
    await page.route('http://127.0.0.1:4173/', route => route.fulfill({
      contentType:'text/html; charset=utf-8',
      body:'<!doctype html><html><body><main id="noches">Noches</main></body></html>',
    }));
    await links.first().click();
    await expect(page).toHaveURL(/\/#noches$/);
  }
});
