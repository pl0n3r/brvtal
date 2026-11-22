import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const sourceFiles = [
  'css/public-entity.css',
  'css/public-event-record.css',
  'css/public-cromo.css',
  'css/public-cromo-pages.css',
];
const newStyleMarker = '/* #1859 · Primera vista de evento:';
const cssAfter = sourceFiles.map(file => readFileSync(file, 'utf8')).join('\n');
const cssBefore = sourceFiles.map(file => {
  const css = readFileSync(file, 'utf8');
  return file.endsWith('public-cromo-pages.css') ? css.split(newStyleMarker, 1)[0] : css;
}).join('\n');
const script = [
  "require_once 'config/public_seo.php';",
  "require_once 'config/public_page.php';",
  "$ticketUrl = ($argv[1] ?? '') === 'ticket' ? 'https://tickets.example.org/genesis' : '';",
  "$past = ($argv[2] ?? '') === 'past';",
  "$entity = ['id'=>42,'route_type'=>'events','title'=>'Génesis BRVTAL',",
  " 'description'=>'Noche de prueba', 'status'=>'published','venue'=>'Club X',",
  " 'city'=>'Pereira', 'accent'=>'#e12e34'];",
  "$page = ['entity'=>$entity,",
  " 'facts'=>['DATE'=>'28 NOV · 8:00 PM','LOCATION'=>'Club X, Pereira'],",
  " 'links'=>$ticketUrl ? ['TICKETS'=>$ticketUrl] : [],",
  " 'related'=>['LINEUP'=>[['title'=>'Artista','slug'=>'artista',",
  "   'route_type'=>'artists','image'=>'','meta'=>'DJ']]],",
  " 'record'=>['state'=>$past ? 'historical' : 'active',",
  "   'status'=>$past ? 'FINISHED' : 'UPCOMING', 'year'=>'2026'],",
  " 'degraded'=>false];",
  "$seo = ['title'=>'Génesis BRVTAL','description'=>'Noche de prueba',",
  " 'image'=>'','canonical'=>'https://www.brvtal.com.co/events/genesis',",
  " 'schema'=>['@context'=>'https://schema.org','@type'=>'MusicEvent']];",
  "echo brvtal_public_entity_page($page,$seo);",
].join('\n');

function render({ ticket = false, past = false, before = false } = {}) {
  let html = execFileSync('php', ['-r', script, ticket ? 'ticket' : 'none', past ? 'past' : 'future'], {
    encoding: 'utf8',
  });
  if (before) {
    // Baseline sintética: mismo PHP y CSS pre-#1859, sin menú ni CTA nuevos.
    html = html.replace(/<details class="event-mobile-menu">[\s\S]*?<\/details>/, '');
    html = html.replace(/<a class="event-notify"[^>]*>[\s\S]*?<\/a>/, '');
  }
  return html.replace('</head>', '<style>' + (before ? cssBefore : cssAfter) + '</style></head>');
}

async function delayEntrance(page) {
  await page.addStyleTag({content: 'body.cromo-page.entity-page--event .entity-hero .entity-copy > * {animation-duration:60s!important;animation-delay:60s!important}'});
}

async function firstViewport(page, selector, name) {
  const node = page.locator(selector);
  await expect(node, name).toBeVisible();
  await expect(node, name + ' must be in the first viewport').toBeInViewport({ratio:0.8});
  const visible = await node.evaluate(el => {
    const s = getComputedStyle(el);
    return {opacity: Number(s.opacity), visibility:s.visibility};
  });
  expect(visible.opacity, name + ' must not animate from transparency').toBeGreaterThan(0.99);
  expect(visible.visibility).toBe('visible');
}

const rgba = text => (text.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
const lum = color => rgba(color).reduce((total, v, i) => {
  const c = v / 255;
  const linear = c <= 0.04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4;
  return total + [.2126,.7152,.0722][i] * linear;
}, 0);
const contrast = (a, b) => {
  const values = [lum(a), lum(b)].sort((x,y) => y - x);
  return (values[0] + .05) / (values[1] + .05);
};

for (const width of [390, 1440]) {
  test('server first viewport, slow animation, before/after evidence at ' + width, async ({page}, testInfo) => {
    await page.setViewportSize({width, height: width === 390 ? 844 : 900});
    await page.emulateMedia({reducedMotion:'no-preference'});
    const captureDir = join('artifacts', 'issue-1859-visual');
    mkdirSync(captureDir, {recursive:true});
    await page.setContent(render({before:true}));
    await delayEntrance(page);
    const beforeShot = await page.screenshot({animations:'allow'});
    writeFileSync(join(captureDir, 'before-' + width + 'px.png'), beforeShot);
    await testInfo.attach('before-' + width + 'px.png', {
      body: beforeShot, contentType:'image/png',
    });
    await page.setContent(render());
    await delayEntrance(page);
    await firstViewport(page, '.entity-hero h1', 'Event title');
    await firstViewport(page, '.entity-facts > div:nth-child(1) b', 'Event date');
    await firstViewport(page, '.entity-facts > div:nth-child(2) b', 'Venue');
    await firstViewport(page, '.entity-actions .event-notify', 'Contact CTA without tickets');
    await expect(page.locator('.event-notify')).toHaveAttribute('href', '/contact');
    const metrics = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      width: document.documentElement.clientWidth,
      redText: getComputedStyle(document.querySelector('.event-record-band span')).color,
      surface: getComputedStyle(document.querySelector('.event-record-band')).backgroundColor,
    }));
    expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.width + 1);
    expect(contrast(metrics.redText, metrics.surface)).toBeGreaterThanOrEqual(4.5);
    const afterShot = await page.screenshot({animations:'allow'});
    writeFileSync(join(captureDir, 'after-' + width + 'px.png'), afterShot);
    await testInfo.attach('after-' + width + 'px.png', {
      body: afterShot, contentType:'image/png',
    });
  });
}

test('event with ticket URL exposes purchase CTA on server and sticky CTA after scrolling', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await page.setContent(render({ticket:true}));
  await firstViewport(page, '.entity-actions .event-buy-button', 'Purchase CTA');
  await expect(page.locator('.event-buy-button').first()).toHaveAttribute('href', 'https://tickets.example.org/genesis');
  await expect(page.locator('.event-notify')).toHaveCount(0);
  await page.locator('#lineup').scrollIntoViewIfNeeded();
  await expect(page.locator('.event-sticky-buy')).toBeVisible();
  await page.locator('body > footer').scrollIntoViewIfNeeded();
  await expect(page.locator('.event-sticky-buy')).toBeHidden();
});

test('mobile header has one row and a native keyboard-accessible menu', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await page.setContent(render());
  await expect(page.locator('.entity-nav > .cp-nav')).toBeHidden();
  const menu = page.locator('.event-mobile-menu');
  await expect(menu.locator('summary')).toBeVisible();
  const positions = await page.evaluate(() => {
    const selectors = ['.entity-nav .entity-brand','.event-mobile-menu summary','.entity-nav .cp-snd'];
    return selectors.map(s => document.querySelector(s).getBoundingClientRect().top);
  });
  expect(Math.max(...positions)-Math.min(...positions)).toBeLessThanOrEqual(12);
  await menu.locator('summary').focus();
  await page.keyboard.press('Enter');
  await expect(menu).toHaveAttribute('open', '');
  await expect(menu.getByRole('link', {name:'Mi cuenta'})).toBeVisible();
  await expect(menu.getByRole('link', {name:/VOLVER A EVENTOS/})).toBeVisible();
  await expect(menu.locator('nav a')).toHaveCount(8);
  const rows = await menu.locator('nav a').evaluateAll(nodes => nodes.map(n => n.getBoundingClientRect().height));
  expect(Math.min(...rows)).toBeGreaterThanOrEqual(44);
});

test('poster follows facts and CTA on mobile, without affecting historical records', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await page.setContent(render({ticket:true}));
  const image = await page.locator('.entity-image').boundingBox();
  const copy = await page.locator('.entity-copy').boundingBox();
  expect(image && copy && copy.y + copy.height <= image.y + 1).toBeTruthy();
  await page.setContent(render({past:true}));
  await expect(page.locator('.event-notify')).toHaveCount(0);
  await expect(page.locator('.event-buy-button')).toHaveCount(0);
});
