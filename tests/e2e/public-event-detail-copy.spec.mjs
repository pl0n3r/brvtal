import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const css = [
  'css/public-entity.css', 'css/public-event-record.css',
  'css/public-cromo.css', 'css/public-cromo-pages.css',
].map(file => readFileSync(file, 'utf8')).join('\n');
const php = "require_once 'config/public_seo.php';\nrequire_once 'config/public_page.php';\n$fixture = json_decode($argv[1], true);\n$historical = $fixture['historical'];\n$offers = $fixture['withOffers'] ? [\n  ['title'=>'General','description'=>'Ingreso general','price'=>31000,'currency'=>'COP',\n   'status'=>'active','url'=>'https://tickets.example.org/general','available_from'=>null,'available_until'=>null],\n  ['title'=>'Zona agotada','price'=>49000,'currency'=>'COP',\n   'status'=>'sold_out','url'=>'https://tickets.example.org/soldout','available_from'=>null,'available_until'=>null],\n] : [];\n$entity = ['id'=>1,'route_type'=>'events','title'=>$fixture['title'],'description'=>$fixture['description'],\n 'status'=>$historical?'finished':'tickets_available','venue'=>'Sala X','city'=>'Pereira',\n 'venue_address'=>'Calle 8 #9-10, Pereira','accent'=>'#96d35f'];\n$page = ['entity'=>$entity,\n 'facts'=>['DATE'=>'SÁB 28 NOV · 8:00 PM','LOCATION'=>'Sala X, Pereira'],\n 'links'=>$historical?[]:($fixture['withOffers']?['TICKETS'=>'https://tickets.example.org/event']:[]),\n 'related'=>['LINEUP'=>[['title'=>'Artista','slug'=>'artista','route_type'=>'artists','image'=>'','meta'=>'DJ']],\n             'TICKETS'=>$offers],\n 'record'=>['state'=>$historical?'historical':'active','status'=>$historical?'FINISHED':'TICKETS AVAILABLE','year'=>'2026'],\n 'degraded'=>false];\n$seo=['title'=>$fixture['title'],'description'=>$fixture['description'],'image'=>'',\n      'canonical'=>'https://www.brvtal.com.co/events/genesis'];\necho brvtal_public_entity_page($page,$seo);";

const render = ({ title = 'Genesis', description = 'Una noche irrepetible', historical = false, withOffers = true } = {}) => {
  const fixture = JSON.stringify({ title, description, historical, withOffers });
  const html = execFileSync('php', ['-r', php, fixture], { encoding: 'utf8' });
  return html.replace('</head>', '<style>' + css + '</style></head>');
};

test('future event has real purchase options without internal jargon or sold-out links', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.setContent(render());
  await expect(page.locator('.entity-actions .event-buy-button')).toContainText('Comprar boletas');
  await expect(page.locator('.entity-actions .event-buy-button')).toContainText('desde $31.000');
  await expect(page.locator('.entity-actions .event-buy-button')).toBeInViewport();
  await expect(page.locator('.entity-actions .event-secondary')).toContainText('Ver line-up');
  await expect(page.locator('#boletas .event-ticket-card')).toHaveCount(2);
  await expect(page.locator('#boletas .event-ticket-card.is-sold-out a')).toHaveCount(0);
  await expect(page.locator('#boletas .event-ticket-card.is-sold-out')).toContainText('Agotado');
  await expect(page.locator('.entity-statement')).toContainText('Una noche irrepetible');
  await expect(page.locator('.event-record-band')).toContainText('PRÓXIMO EVENTO');
  await expect(page.getByText('EXPERIENCIA ACTIVA')).toHaveCount(0);
  await expect(page.getByText('SEÑAL DEL EVENTO')).toHaveCount(0);
  expect(await page.locator('.entity-image img').evaluate(el => getComputedStyle(el).objectFit)).toBe('contain');
});

test('mobile poster is above the copy, title has no glitch and sticky purchase respects footer', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.setContent(render({ title: 'Activación lujuria' }));
  const image = await page.locator('.entity-image').boundingBox();
  const copy = await page.locator('.entity-copy').boundingBox();
  expect(image && copy && image.y + image.height <= copy.y + 1).toBeTruthy();
  const title = page.locator('main h1');
  expect(await title.evaluate(el => getComputedStyle(el, '::before').content)).toBe('none');
  expect(await title.evaluate(el => getComputedStyle(el, '::after').content)).toBe('none');
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
  await expect(page.locator('.event-sticky-buy')).toBeHidden();
  await page.locator('#boletas').scrollIntoViewIfNeeded();
  await expect(page.locator('.event-sticky-buy')).toBeVisible();
  await page.locator('body > footer').scrollIntoViewIfNeeded();
  await expect(page.locator('.event-sticky-buy')).toBeHidden();
});

test('past events and no-offer events omit buying CTA and empty description', async ({ page }) => {
  for (const fixture of [
    { historical: true, withOffers: true, description: '' },
    { historical: false, withOffers: false, description: '' },
  ]) {
    await page.setContent(render(fixture));
    await expect(page.locator('.event-buy-button')).toHaveCount(0);
    await expect(page.locator('.event-sticky-buy')).toHaveCount(0);
    await expect(page.locator('.entity-statement')).toHaveCount(0);
  }
});
