import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

// Real PHP event renderer, synthetic artist settings, no production API or credentials.
const php = String.raw`require_once 'config/public_seo.php';
require_once 'config/public_page.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY,setting_value TEXT,is_json INTEGER)');
$framing = [
  'desktop'=>['zoom'=>2.1,'x'=>28.5,'y'=>15.0],
  'mobile'=>['zoom'=>1.6,'x'=>65.0,'y'=>7.0]
];
$insert = $pdo->prepare('INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,1)');
$insert->execute(['artist.hero.41', json_encode($framing)]);
$related = [
  'LINEUP'=>[
    ['artist_card_id'=>41,'title'=>'PLON3R','slug'=>'plon3r','image'=>'/uploads/portrait.svg','route_type'=>'artists'],
    ['artist_card_id'=>42,'title'=>"CRIXXES'T",'slug'=>'crixxes','image'=>'','route_type'=>'artists'],
    ['artist_card_id'=>43,'title'=>'SIN ENCUADRE','slug'=>'no-framing','image'=>'/uploads/portrait.svg','route_type'=>'artists']
  ],
  'SETS'=>[
    ['title'=>'SET','slug'=>'set','image'=>'/uploads/portrait.svg','route_type'=>'sets']
  ],
];
$page = [
  'entity'=>['id'=>1,'route_type'=>'events','title'=>'GENESIS','description'=>'Evento sintético'],
  'facts'=>[],'links'=>[],'record'=>['state'=>'active','year'=>'2026','status'=>'UPCOMING'],
  'related'=>brvtalPublicEntityArtistCardFraming($pdo,$related),'degraded'=>false
];
$seo = ['title'=>'GENESIS / BRVTAL','description'=>'Fixture','canonical'=>'https://www.brvtal.com.co/events/genesis','image'=>'/uploads/portrait.svg','schema'=>['@type'=>'MusicEvent']];
echo brvtal_public_entity_page($page,$seo);
`;
const portrait = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="600" viewBox="0 0 400 600"><rect width="400" height="600" fill="#232f41"/><circle cx="200" cy="140" r="83" fill="#e9bd93"/><path d="M68 430 Q200 260 332 430 V600 H68Z" fill="#adc8ed"/></svg>';

// The official WebKit container has no PHP binary. Chromium executes the real
// PHP renderer and the fast gate runs its PHP contract; WebKit independently
// verifies the exact same portrait CSS with a deterministic, synthetic DOM.
const webkitFixture = `<!doctype html><html lang="es"><head>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/css/public-entity.css">
  <link rel="stylesheet" href="/css/public-cromo.css">
  <link rel="stylesheet" href="/css/public-cromo-pages.css">
  </head><body class="entity-page entity-page--event entity-page--event-active cromo-page">
  <main><section class="entity-related"><div class="entity-section-label">CARTEL / 03</div><div class="entity-grid">
    <a class="entity-card entity-card--artist" href="/artists/plon3r" style="--artist-card-desktop-x:28.5%;--artist-card-desktop-y:15%;--artist-card-mobile-x:65%;--artist-card-mobile-y:7%;">
      <img src="/uploads/portrait.svg" alt=""><span><strong>PLON3R</strong></span></a>
    <a class="entity-card entity-card--artist" href="/artists/crixxes"><span class="entity-card-mark">BRVTAL</span><span><strong>CRIXXES'T</strong></span></a>
    <a class="entity-card entity-card--artist" href="/artists/no-framing"><img src="/uploads/portrait.svg" alt=""><span><strong>SIN ENCUADRE</strong></span></a>
  </div></section><section class="entity-related"><div class="entity-section-label">SETS</div><div class="entity-grid">
    <a class="entity-card" href="/sets/set"><img src="/uploads/portrait.svg" alt=""><span><strong>SET</strong></span></a>
  </div></section></main></body></html>`;

test('GENESIS artist lineup keeps portrait 4:5, saved desktop/mobile framing and equal placeholders', async ({ page }, testInfo) => {
  const html = testInfo.project.name === 'webkit-totp'
    ? webkitFixture
    : execFileSync('php', ['-r', php], {
        cwd: process.cwd(), encoding: 'utf8', timeout: 15000
      });
  await page.route('**/*', async route => {
    const pathname = new URL(route.request().url()).pathname;
    if (pathname === '/events/genesis') {
      return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
    }
    if (pathname === '/uploads/portrait.svg') {
      return route.fulfill({ status: 200, contentType: 'image/svg+xml', body: portrait });
    }
    if (pathname.startsWith('/css/') && /^\/css\/[a-z0-9_-]+\.css$/.test(pathname)) {
      const file = join(process.cwd(), pathname.slice(1));
      return route.fulfill({ status: 200, contentType: 'text/css', body: existsSync(file) ? readFileSync(file, 'utf8') : '' });
    }
    return route.fulfill({ status: 200, contentType: 'text/plain', body: '' });
  });

  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('http://127.0.0.1:4173/events/genesis', { waitUntil: 'domcontentloaded' });
    const cards = page.locator('.entity-card--artist');
    await expect(cards).toHaveCount(3);
    await expect(page.locator('.entity-card--artist .entity-card-mark')).toHaveCount(1);
    const visuals = await cards.evaluateAll(nodes => nodes.map(node => {
      const visual = node.querySelector('img, .entity-card-mark');
      const box = visual.getBoundingClientRect();
      const photo = node.querySelector('img');
      return {
        ratio: box.height / box.width,
        height: box.height,
        width: box.width,
        position: photo ? getComputedStyle(photo).objectPosition : '',
        style: node.getAttribute('style') || ''
      };
    }));
    for (const item of visuals) expect(item.ratio).toBeCloseTo(5 / 4, 1);
    expect(Math.max(...visuals.map(item => item.height)) - Math.min(...visuals.map(item => item.height))).toBeLessThan(1);
    expect(visuals[0].position).toBe(width < 768 ? '65% 7%' : '28.5% 15%');
    expect(visuals[0].style).not.toContain('zoom');
    expect(visuals[1].style).toBe('');
    expect(visuals[2].position).toBe('50% 20%');
    const setVisual = page.locator('.entity-related .entity-card:not(.entity-card--artist) img');
    await expect(setVisual).toHaveCount(1);
    const setRatio = await setVisual.evaluate(img => {
      const rect = img.getBoundingClientRect();
      return rect.height / rect.width;
    });
    expect(setRatio).toBeCloseTo(3 / 4, 1);
  }
});


/* WebKit has no PHP binary: pair this real-CSS Safari check with the PHP-rendered
 * contract in public-event-accent.spec.mjs (Chromium). All data are synthetic. */
test('Safari 390px respects event Accent and WCAG AA text contrast for light and dark choices', async ({ page }) => {
  const signalCss = ['css/public-entity.css', 'css/public-event-record.css', 'css/public-cromo.css', 'css/public-cromo-pages.css']
    .map(path => readFileSync(join(process.cwd(), path), 'utf8')).join('\n');
  const channels = value => (value.match(/\d+(?:\.\d+)?/g) || []).slice(0, 3).map(Number);
  const luminance = color => channels(color).reduce((sum, value, index) => {
    const channel = value / 255;
    const linear = channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4;
    return sum + [0.2126, 0.7152, 0.0722][index] * linear;
  }, 0);
  await page.setViewportSize({width: 390, height: 844});
  for (const [signal, ink, expectedRgb] of [
    ['#96d35f', '#030303', 'rgb(150, 211, 95)'],
    ['#101020', '#f4f1e8', 'rgb(16, 16, 32)'],
    ['#e12e34', '#030303', 'rgb(225, 46, 52)']
  ]) {
    await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
      + '<style>html,body{margin:0;padding:0}</style><style>' + signalCss + '</style></head>'
      + '<body class="entity-page entity-page--event entity-page--event-active cromo-page"'
      + ' style="--event-signal:' + signal + ';--event-ink:' + ink + '">'
      + '<main><article class="entity-hero"><div class="entity-image"></div>'
      + '<div class="entity-copy"><div class="entity-kicker">GENESIS</div><h1>GENESIS</h1>'
      + '<div class="entity-actions"><a href="https://tickets.example.org">ENTRADAS</a></div></div></article>'
      + '</main></body></html>');
    const values = await page.evaluate(() => {
      const body = document.body;
      const button = document.querySelector('.entity-actions a');
      return {
        signal: getComputedStyle(body).getPropertyValue('--event-signal').trim(),
        bg: getComputedStyle(button).backgroundColor,
        fg: getComputedStyle(button).color,
        tint: getComputedStyle(document.querySelector('.entity-image'), '::after').backgroundColor,
        ink: getComputedStyle(document.querySelector('.entity-kicker')).color,
        overflow: document.documentElement.scrollWidth > window.innerWidth + 1
      };
    });
    expect(values.signal).toBe(signal);
    expect(values.bg).toBe(expectedRgb);
    expect(values.tint).toBe(expectedRgb);
    const l1 = luminance(values.bg);
    const l2 = luminance(values.fg);
    const contrast = (Math.max(l1, l2) + .05) / (Math.min(l1, l2) + .05);
    expect(contrast).toBeGreaterThanOrEqual(4.5);
    expect(values.overflow).toBe(false);
  }
});
