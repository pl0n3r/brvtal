import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

// #1929: the event page is a purchase page. The ticket panel sits beside the event
// (sticky on desktop) or right after the hero (phones), with a cart of several types.
const css = ['css/public-entity.css', 'css/public-event-record.css', 'css/public-ticket-checkout.css',
  'css/public-cromo.css', 'css/public-cromo-pages.css'].map(file => readFileSync(file, 'utf8')).join('\n');
const script = readFileSync('js/public-ticket-checkout.js', 'utf8');
const url = 'https://www.brvtal.com.co/events/cart-fixture';
const php = `require_once 'config/public_seo.php'; require_once 'config/public_page.php';
$f = json_decode($argv[1], true);
$until = date('Y-m-d H:i:s', strtotime('+4 days'));
$tickets = [
  ['ticket_type_id'=>5,'title'=>'Preventa','price'=>25000,'currency'=>'COP','status'=>'active','available_until'=>$until],
  ['ticket_type_id'=>6,'title'=>'VIP','price'=>60000,'currency'=>'COP','status'=>'active','description'=>'Zona VIP'],
];
$lineup = [];
foreach (range(1, 6) as $i) $lineup[] = ['title'=>'Artista '.$i,'slug'=>'artista-'.$i,'route_type'=>'artists','image'=>''];
$page = ['entity'=>['id'=>7,'route_type'=>'events','title'=>'Genesis','description'=>str_repeat('Una noche irrepetible. ', 40),
  'status'=>$f['status']],
  'facts'=>['DATE'=>'04.12.2026 / 9:00 PM','LOCATION'=>'Sala X, Pereira'],'links'=>[],
  'related'=>['LINEUP'=>$lineup,'TICKETS'=>$tickets],
  'record'=>['state'=>'active','status'=>$f['status']==='cancelled'?'CANCELLED':'TICKETS AVAILABLE','year'=>'2026'],'degraded'=>false,
  'checkout'=>$f['online'] ? ['offers'=>[['id'=>5,'title'=>'Preventa','price_cop'=>25000,'available'=>20],
    ['id'=>6,'title'=>'VIP','price_cop'=>60000,'available'=>2]],'methods'=>['CARD','NEQUI','PSE']] : []];
$seo = ['title'=>'Genesis','description'=>'Prueba','image'=>'','canonical'=>'https://www.brvtal.com.co/events/cart-fixture','schema'=>[]];
echo brvtal_public_entity_page($page, $seo);`;
function render({ online = true, status = 'tickets_available' } = {}) {
  return execFileSync('php', ['-r', php, JSON.stringify({ online, status })], { encoding: 'utf8' })
    .replace('</head>', '<style>' + css + '</style></head>')
    .replace('<script src="/js/public-ticket-checkout.js" defer></script>', '');
}
async function open(page, fixture) {
  await page.route(url, route => route.fulfill({ contentType: 'text/html', body: render(fixture) }));
  await page.route('**/api/ticketing/waitlist.php**', route => route.fulfill({ contentType: 'application/json', body: '{"ok":true,"types":[]}' }));
  await page.goto(url);
  await page.addScriptTag({ content: script });
}

test('escritorio: el panel de compra va al lado, queda fijo al bajar y arma el pedido', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await open(page);
  const panel = page.locator('#boletas');
  const hero = await page.locator('.entity-hero').boundingBox();
  const box = await panel.boundingBox();
  const main = await page.locator('.event-buy-main').boundingBox();
  expect(box.x).toBeGreaterThan(main.x + main.width - 1); // right column
  expect(box.y).toBeLessThan(hero.y + hero.height + 200); // starts right after the hero
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await page.locator('[data-cart-type="6"] [data-cart-step="1"]').click();
  await expect(page.locator('[data-cart-pay]')).toHaveText('Pagar $85.000');
  await page.locator('#lineup').scrollIntoViewIfNeeded();
  await expect(panel).toBeInViewport();
  expect((await panel.boundingBox()).y).toBeLessThan(200); // sticky near the top
  await expect(page.locator('.event-sticky-buy')).toBeHidden(); // desktop: no bottom bar
  await page.evaluate(() => scrollTo(0, document.querySelector('.event-buy-layout').offsetTop - 80));
  await testInfo.attach('cart-1440', { body: await page.screenshot(), contentType: 'image/png' });
});

test('móvil: el panel sigue al encabezado y la barra inferior muestra total y pago', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await open(page);
  const heroBottom = await page.locator('.entity-hero').evaluate(el => el.getBoundingClientRect().bottom + scrollY);
  const panelTop = await page.locator('#boletas').evaluate(el => el.getBoundingClientRect().top + scrollY);
  const statementTop = await page.locator('.entity-statement').evaluate(el => el.getBoundingClientRect().top + scrollY);
  expect(panelTop).toBeGreaterThan(heroBottom - 1);
  expect(panelTop).toBeLessThan(statementTop); // before the description
  await page.locator('#boletas').scrollIntoViewIfNeeded();
  await testInfo.attach('cart-390', { body: await page.locator('#boletas').screenshot(), contentType: 'image/png' });
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await page.locator('[data-cart-type="5"] [data-cart-step="1"]').click();
  await page.locator('#lineup').scrollIntoViewIfNeeded();
  const bar = page.locator('.event-sticky-buy');
  await expect(bar).toBeVisible();
  await expect(bar.locator('[data-cart-sticky-summary]')).toHaveText('2 boletas · $50.000');
  await expect(bar.getByRole('button', { name: 'Pagar' })).toBeVisible();
  await expect(bar.getByRole('link', { name: 'Elegir boletas' })).toBeHidden();
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
});

test('venta en línea apagada: se ven las boletas y un aviso, sin botón de pago', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await open(page, { online: false });
  await expect(page.locator('#brvtal-ticket-cart')).toHaveCount(0);
  await expect(page.locator('#boletas .event-ticket-card')).toHaveCount(2);
  await expect(page.locator('#boletas .event-ticket-card').first()).toContainText('$25.000');
  await expect(page.locator('#boletas .event-buy-note')).toContainText('todavía no está abierta');
  await expect(page.locator('.entity-actions .event-buy-button')).toHaveText('Ver boletas');
  await expect(page.locator('.entity-actions .event-buy-button')).toHaveAttribute('href', '#boletas');
  await expect(page.locator('[data-cart-pay]')).toHaveCount(0);
});

test('evento cancelado: no vende', async ({ page }) => {
  await open(page, { online: true, status: 'cancelled' });
  await expect(page.locator('#brvtal-ticket-cart')).toHaveCount(0);
  await expect(page.locator('[data-cart-pay]')).toHaveCount(0);
  await expect(page.locator('.event-buy-button[href="#boletas"]:has-text("Comprar")')).toHaveCount(0);
});
