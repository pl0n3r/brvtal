import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
const script = readFileSync('js/public-ticket-checkout.js', 'utf8');
const css = readFileSync('css/public-ticket-checkout.css', 'utf8');
const path = 'https://www.brvtal.com.co/events/t13-fixture';
function render({ active = true, internal = true, external = true } = {}) {
  const fixture = JSON.stringify({ active, internal, external });
  const php = [
    "require_once 'config/public_seo.php'; require_once 'config/public_page.php';",
    '$f=json_decode($argv[1],true);',
    "$e=['id'=>42,'route_type'=>'events','title'=>'Noche sintética','description'=>'Boletas',",
    "'status'=>$f['active']?'tickets_available':'cancelled'];",
    "$offers=$f['external']?[['title'=>'General','price'=>30000,'currency'=>'COP',",
    "'status'=>'active','url'=>'https://tickets.example.invalid/general']]:[];",
    "$page=['entity'=>$e,'facts'=>[],'links'=>[],'related'=>['TICKETS'=>$offers],",
    "'record'=>['state'=>'active','status'=>$f['active']?'TICKETS AVAILABLE':'CANCELLED','year'=>'2026'],",
    "'degraded'=>false,",
    "'checkout'=>$f['internal']?['offers'=>[['id'=>5,'title'=>'General',",
    "'price_cop'=>30000,'available'=>3]],'methods'=>['CARD','NEQUI']]:[]];",
    "$seo=['title'=>'Noche sintética','description'=>'Prueba','image'=>'',",
    "'canonical'=>'https://www.brvtal.com.co/events/t13-fixture','schema'=>[]];",
    'echo brvtal_public_entity_page($page,$seo);',
  ].join(' ');
  return execFileSync('php', ['-r', php, fixture], { encoding: 'utf8' })
    .replace('</head>', '<style>' + css + '</style></head>');
}
async function open(page, fixture) {
  await page.route(path, route => route.fulfill({ contentType: 'text/html', body: render(fixture) }));
  await page.goto(path);
  await page.addScriptTag({ content: script });
}
test('T13 390/1440: servidor fija cupos, teclado y total sin recargo', async ({ page }) => {
  for (const width of [390, 1440]) {
    await page.setViewportSize({ width, height: 820 });
    await open(page);
    const form = page.locator('#brvtal-ticket-checkout');
    await expect(form).toBeVisible();
    await expect(page.locator('.event-buy-button').first()).toHaveAttribute('href','#checkout-boletas');
    await expect(page.locator('#ticket-checkout-total')).toContainText('30.000');
    await form.getByLabel('Cantidad').fill('2');
    await expect(page.locator('#ticket-checkout-total')).toContainText('60.000');
    await form.getByLabel('Cantidad').fill('4');
    await expect(form.getByRole('button',{name:'Continuar al pago'})).toBeDisabled();
    await form.getByLabel('Cantidad').fill('1');
    await form.getByLabel('Método').focus();
    await expect(form.getByLabel('Método')).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width+1);
  }
});
test('T13 preserva venta externa y no vende eventos cancelados', async ({ page }) => {
  await open(page, { internal: false, external: true });
  await expect(page.locator('#brvtal-ticket-checkout')).toHaveCount(0);
  await expect(page.locator('.event-buy-button').first()).toHaveAttribute('href',
    'https://tickets.example.invalid/general');
  await open(page, { active: false, internal: true });
  await expect(page.locator('#brvtal-ticket-checkout')).toHaveCount(0);
});
test('T13 huésped, timeout, retry y redirect nunca confirman pago', async ({ page }) => {
  await open(page);
  let allowed = false;
  let requests = [];
  await page.route('**/api/buyer/auth.php', route => route.fulfill({
    contentType:'application/json',
    body: JSON.stringify({ ok:true, csrf:'fixture', authenticated:allowed }),
  }));
  await page.route(path, async route => {
    if (route.request().method()!=='POST') {
      if (route.request().headers()['x-brvtal-ticket-checkout']==='status') return route.fulfill({
        status:200, contentType:'application/json', body:JSON.stringify({ok:true,state:'pending'}),
      });
      return route.fallback();
    }
    requests.push(route.request().postDataJSON());
    if (requests.length===1) return route.abort('failed');
    return route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,order_id:42,checkout:{environment:'mock',state:'awaiting_provider'},
    })});
  });
  const button=page.getByRole('button',{name:'Continuar al pago'});
  await button.click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('Inicia sesión');
  expect(requests).toHaveLength(0);
  allowed=true;
  await button.click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('No fue posible');
  await button.click();
  await expect(page.locator('#ticket-checkout-status')).toContainText('pendiente');
  expect(requests).toHaveLength(2);
  expect(requests[0].idempotency_key).toBe(requests[1].idempotency_key);
  expect(requests[1]).not.toHaveProperty('price');
  expect(requests[1]).not.toHaveProperty('status');
  await page.reload();
  await page.addScriptTag({content:script});
  await expect(page.locator('#ticket-checkout-status')).toContainText('pendiente');
  await expect(page.getByText('Pago confirmado por el servidor.')).toHaveCount(0);
});
