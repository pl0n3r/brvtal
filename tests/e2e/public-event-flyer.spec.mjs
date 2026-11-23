import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

// #1935: «Flyer» purchase page (approved alternative A) and in-page links that stay
// on the event although the document declares <base href="/">.
const css = ['css/public-entity.css', 'css/public-event-record.css', 'css/public-ticket-checkout.css',
  'css/public-cromo.css', 'css/public-cromo-pages.css'].map(file => readFileSync(file, 'utf8')).join('\n');
const url = 'https://www.brvtal.com.co/events/flyer-fixture';
// A 16:9 poster served locally, so the test measures the real aspect ratio.
const poster = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="900"><rect width="1600" height="900" fill="#96d35f"/></svg>`);
const php = `require_once 'config/public_seo.php'; require_once 'config/public_page.php';
$f = json_decode($argv[1], true);
$tickets = [['ticket_type_id'=>5,'title'=>'Preventa','price'=>25000,'currency'=>'COP','status'=>'active','available_until'=>'2030-11-29 23:59:00']];
$lineup = [];
foreach (range(1, 6) as $i) $lineup[] = ['title'=>'Artista '.$i,'slug'=>'artista-'.$i,'route_type'=>'artists','image'=>''];
$page = ['entity'=>['id'=>7,'route_type'=>'events','title'=>'Genesis','description'=>str_repeat('Una noche irrepetible. ', 30),'status'=>'tickets_available'],
  'facts'=>['DATE'=>'04.12.2030 / 9:00 PM','LOCATION'=>'Sala X, Pereira'],'links'=>[],
  'related'=>['LINEUP'=>$lineup,'TICKETS'=>$tickets],
  'record'=>['state'=>'active','status'=>'TICKETS AVAILABLE','year'=>'2030'],'degraded'=>false,
  'checkout'=>$f['online'] ? ['offers'=>[['id'=>5,'title'=>'Preventa','price_cop'=>25000,'available'=>20]],'methods'=>['CARD','NEQUI']] : []];
$seo = ['title'=>'Genesis','description'=>'Prueba','image'=>'https://www.brvtal.com.co/uploads/flyer-fixture.svg',
  'canonical'=>'https://www.brvtal.com.co/events/flyer-fixture','schema'=>[]];
echo brvtal_public_entity_page($page, $seo);`;
const render = online => execFileSync('php', ['-r', php, JSON.stringify({ online })], { encoding: 'utf8' })
  .replace('</head>', '<style>' + css + '</style></head>');

async function open(page, online = true) {
  await page.route('**/uploads/flyer-fixture*', route => route.fulfill({ contentType: 'image/svg+xml', body: poster }));
  await page.route('https://www.brvtal.com.co/', route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Home</title><p>home</p>' }));
  await page.route(url, route => route.fulfill({ contentType: 'text/html', body: render(online) }));
  await page.route('**/*.js*', route => route.fulfill({ contentType: 'text/javascript', body: '' }));
  await page.goto(url);
  await page.waitForFunction(() => document.querySelector('.entity-image img')?.complete);
}

test('AC-01: los enlaces internos no salen del evento pese a <base href="/">', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await open(page);
  expect(await page.evaluate(() => document.baseURI)).toBe('https://www.brvtal.com.co/');
  // Keyboard skip link must stay on the event, rather than resolving to <base href="/">.
  const skip = page.locator('.skip-link');
  await expect(skip).toHaveAttribute('href', '/events/flyer-fixture#main-content');
  await skip.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(url + '#main-content');
  expect(new URL(page.url()).pathname).toBe('/events/flyer-fixture');
  await expect(page.locator('#main-content')).toBeInViewport();
  for (const [name, target] of [['Comprar boletas', '#boletas'], ['Ver line-up', '#lineup']]) {
    await page.evaluate(() => scrollTo(0, 0));
    await page.locator('.entity-actions a', { hasText: name }).click();
    await expect(page).toHaveURL(url + target);
    expect(new URL(page.url()).pathname).toBe('/events/flyer-fixture');
    await expect(page.locator(target)).toBeInViewport();
  }
});

for (const [width, height] of [[390, 844], [1440, 900]]) {
  test(`AC-02 ${width}px: póster sin franjas y compra en la primera pantalla`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height });
    await open(page);
    const img = await page.locator('.entity-image img').boundingBox();
    const box = await page.locator('.entity-image').boundingBox();
    expect(Math.abs(box.height - img.height)).toBeLessThanOrEqual(1); // no letterbox inside the box
    if (width < 900) expect(Math.abs(img.height - img.width * 9 / 16)).toBeLessThanOrEqual(2); // real ratio
    const offer = page.locator('.event-flyer-offer');
    await expect(offer).toContainText('$25.000');
    await expect(offer).toContainText(/hasta \S+ 29 nov/);
    const cta = await page.locator('.entity-actions .event-buy-button').boundingBox();
    expect(cta.y + cta.height).toBeLessThanOrEqual(height);
    if (width >= 900) {
      const panel = await page.locator('#boletas').boundingBox();
      expect(panel.x).toBeGreaterThan(box.x + box.width - 1); // beside the poster
      expect(panel.y).toBeLessThan(img.y + 80); // starts at the poster's height
    } else {
      const panelTop = await page.locator('#boletas').evaluate(el => el.getBoundingClientRect().top + scrollY);
      const heroBottom = await page.locator('.entity-hero').evaluate(el => el.getBoundingClientRect().bottom + scrollY);
      expect(panelTop).toBeGreaterThanOrEqual(heroBottom - 1);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
    await testInfo.attach(`flyer-${width}`, { body: await page.screenshot(), contentType: 'image/png' });
  });
}

test('AC-03: venta apagada honesta y panel visible bajo el menú a 1280px', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await open(page, false);
  await expect(page.locator('#boletas .event-ticket-status')).toHaveText('Venta en línea pronto');
  const notify = await page.locator('#boletas .event-buy-notify').boundingBox();
  expect(notify.height).toBeGreaterThanOrEqual(44);
  await expect(page.locator('.entity-actions .event-buy-button')).toHaveText('Ver boletas');

  await page.setViewportSize({ width: 1280, height: 800 });
  await open(page);
  // A nav wrapped to two rows must not cover the sticky panel's heading.
  await page.evaluate(() => { document.querySelector('.entity-nav').style.minHeight = '138px'; });
  await page.evaluate(() => scrollTo(0, 900));
  await expect.poll(() => page.evaluate(() => {
    const title = document.getElementById('event-buy-title').getBoundingClientRect();
    return Boolean(document.elementFromPoint(title.left + 10, title.top + title.height / 2)?.closest('#event-buy-title'));
  })).toBe(true);
});
