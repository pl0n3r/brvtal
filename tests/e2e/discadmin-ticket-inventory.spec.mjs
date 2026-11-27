import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

// #1931: capacity for online sale in the event editor (Boletas step).
const moduleSource = readFileSync('discadmin/ticketing-inventory.js', 'utf8');
const css = readFileSync('discadmin/content-core.css', 'utf8');
const url = 'http://127.0.0.1:4173/discadmin/inventory-fixture.html';

const inventory = {
  ok: true, event_id: 7, capacity: null, used: 5,
  types: [
    { ticket_type_id: 10, name: 'Preventa', status: 'active', capacity: null, courtesy_capacity: 0, door_price_cop: null, sold: 0, held: 2, courtesy_used: 0 },
    { ticket_type_id: 11, name: 'VIP', status: 'active', capacity: 20, courtesy_capacity: 4, door_price_cop: 75000, sold: 3, held: 0, courtesy_used: 0 },
    { ticket_type_id: 12, name: 'Mesa', status: 'draft', capacity: null, courtesy_capacity: 0, door_price_cop: null, sold: 0, held: 0, courtesy_used: 0 },
  ],
  // #1939: the server's verdict, with the same rules as the checkout.
  sale: { ready: false, reasons: ['WOMPI_DISABLED', 'EVENT_CAPACITY_MISSING'], types: [
    { ticket_type_id: 10, offered: false, reasons: ['TYPE_CAPACITY_MISSING'] },
    { ticket_type_id: 11, offered: true, reasons: [] },
    { ticket_type_id: 12, offered: false, reasons: ['TYPE_STATUS', 'TYPE_CAPACITY_MISSING'] },
  ] },
};

async function open(page, { failWith = null, eventId = 7, data = inventory, forbidden = false, source = moduleSource } = {}) {
  await page.route('**/discadmin/inventory-fixture.html', route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head>
      <body data-admin-module="content-core" style="margin:0"><div class="wrap"><div class="section">
      <div class="sectionhead"><strong>Boletas</strong></div><div id="tickets"></div><div id="host" class="ticket-inventory-host"></div></div></div></body></html>`,
  }));
  const posts = [];
  let current = structuredClone(data);
  await page.route('**/api/ticketing/admin-inventory.php**', async route => {
    const request = route.request();
    if (forbidden) return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'FORBIDDEN' }) });
    if (request.method() === 'POST') {
      posts.push({ body: request.postDataJSON(), csrf: request.headers()['x-csrf-token'] });
      if (failWith) return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, error: failWith }) });
      const body = request.postDataJSON();
      current = { ...current, capacity: body.capacity, max_per_buyer: body.max_per_buyer, types: current.types.map(type => ({
        ...type, ...body.types.find(row => row.ticket_type_id === type.ticket_type_id) })) };
    }
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify(current) });
  });
  await page.goto(url);
  await page.evaluate(() => { window.csrf = 'csrf-fixture'; });
  await page.addScriptTag({ type: 'module', content: source.replace('export async function mount', 'async function mount')
    + `\nwindow.__mount = mount;` });
  await page.waitForFunction(() => typeof window.__mount === 'function');
  await page.evaluate(id => window.__mount(document.getElementById('host'), { eventId: id }), eventId);
  return posts;
}

test('muestra aforo, cupo y uso por boleta y guarda con CSRF', async ({ page }, testInfo) => {
  for (const [width, height] of [[1440, 900], [390, 844]]) {
    await page.setViewportSize({ width, height });
    // Before: PR #2264 artifact 15168709896; baseline JS and CSS blobs identical to main.
    // Capture the changed block at the same viewport sizes, not a shell without the editor.
    await open(page);
    const block = page.locator('[data-ticket-inventory]');
    await expect(block.getByRole('heading', { name: 'Cupo para venta en línea' })).toBeVisible();
    await expect(block.getByLabel('Aforo total del evento')).toHaveValue('');
    await expect(block.getByText('En uso: 5')).toBeVisible();
    await expect(block.getByLabel('VIP', { exact: true })).toHaveValue('20');
    await expect(block.getByLabel('Cupo de cortesías de VIP')).toHaveValue('4');
    await expect(block.getByLabel('Precio en puerta (opcional) de VIP en COP')).toHaveValue('75000');
    await expect(block.locator('[data-ticket-type="11"]')).toContainText('75.000 COP');
    await expect(block.getByLabel('Precio en puerta (opcional) de Preventa en COP')).toHaveValue('');
    await expect(block.getByText('Si lo dejas vacío, la puerta cobra la etapa vigente').first()).toBeVisible();
    await expect(block.locator('[data-ticket-type="11"]')).toContainText('Cortesías: presupuesto 4 · consumidas 0 · saldo 4');
    await expect(block.locator('[data-ticket-type="11"] .ticket-inventory-state')).toHaveText('Se vende en línea');
    await expect(block.locator('[data-ticket-type="10"] .ticket-inventory-state')).toHaveText('Sin cupo');
    await expect(block.locator('[data-ticket-type="12"] .ticket-inventory-state')).toHaveText('Boleta no activa · Sin cupo');
    const verdict = block.locator('.ticket-inventory-sale');
    await expect(verdict).toHaveAttribute('data-sale-ready', 'false');
    await expect(verdict).toContainText('Venta en línea: no disponible');
    await expect(verdict.locator('li')).toHaveText([/Wompi está apagado/, /Falta el aforo total/]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
    await testInfo.attach(`despues-${width}`, { body: await block.screenshot(), contentType: 'image/png' });
  }
  const posts = await open(page);
  const block = page.locator('[data-ticket-inventory]');
  await block.getByLabel('Aforo total del evento').fill('300');
  const capacity = block.getByLabel('Preventa', { exact: true });
  await capacity.fill('200');
  await capacity.focus();
  await page.keyboard.press('Tab');
  const courtesy = block.getByLabel('Cupo de cortesías de Preventa');
  await expect(courtesy).toBeFocused();
  await courtesy.fill('12');
  const door = block.getByLabel('Precio en puerta (opcional) de Preventa en COP');
  await page.keyboard.press('Tab');
  await expect(door).toBeFocused();
  await door.fill('60000');
  await expect(block.locator('[data-ticket-type="10"] .ticket-inventory-state')).toHaveText('Cupo sin guardar');
  await block.getByRole('button', { name: 'Guardar cupo' }).click();
  await expect(page.locator('.ticket-inventory-status')).toHaveText('Cupo, precio y límites guardados.');
  expect(posts).toEqual([{ csrf: 'csrf-fixture', body: { capacity: 300, max_per_buyer: null, types: [
    { ticket_type_id: 10, capacity: 200, courtesy_capacity: 12, door_price_cop: 60000, min_per_order: null, max_per_order: null },
    { ticket_type_id: 11, capacity: 20, courtesy_capacity: 4, door_price_cop: 75000, min_per_order: null, max_per_order: null },
    { ticket_type_id: 12, capacity: 0, courtesy_capacity: 0, door_price_cop: null, min_per_order: null, max_per_order: null }] } }]);
  await expect(page.getByLabel('Preventa', { exact: true })).toHaveValue('200');
  await expect(page.getByLabel('Cupo de cortesías de Preventa')).toHaveValue('12');
  await expect(page.getByLabel('Precio en puerta (opcional) de Preventa en COP')).toHaveValue('60000');
  await expect(page.locator('[data-ticket-type="10"]')).toContainText('Cortesías: presupuesto 12 · consumidas 0 · saldo 12');
});

test('explica el rechazo del servidor y valida números antes de enviar', async ({ page }) => {
  const posts = await open(page, { failWith: 'INVENTORY_BELOW_USAGE' });
  const block = page.locator('[data-ticket-inventory]');
  await block.getByLabel('VIP', { exact: true }).fill('1');
  await block.getByRole('button', { name: 'Guardar cupo' }).click();
  await expect(block.locator('.ticket-inventory-status')).toHaveText('El cupo no puede ser menor que lo ya vendido o reservado.');
  await expect(block.getByRole('button', { name: 'Guardar cupo' })).toBeEnabled();
  await block.getByLabel('Cupo de cortesías de VIP').fill('2.5');
  await block.getByRole('button', { name: 'Guardar cupo' }).click();
  await expect(block.locator('.ticket-inventory-status')).toHaveText('Usa números enteros entre 0 y 100.000.');
  expect(posts).toHaveLength(1);
});

test('#1939: con todo listo dice «Venta en línea: lista»', async ({ page }) => {
  await open(page, { data: { ...inventory, capacity: 300,
    sale: { ready: true, reasons: [], types: [{ ticket_type_id: 11, offered: true, reasons: [] }] } } });
  await expect(page.locator('.ticket-inventory-sale')).toHaveText('Venta en línea: lista');
  await expect(page.locator('.ticket-inventory-sale li')).toHaveCount(0);
});

test('un evento sin guardar no muestra cupo editable', async ({ page }) => {
  await open(page, { eventId: 0 });
  await expect(page.locator('[data-ticket-inventory]')).toContainText('Guarda el evento para configurar el cupo.');
  await expect(page.getByRole('button', { name: 'Guardar cupo' })).toHaveCount(0);
});

test('cortesías: consumo confirmado, rechazo y permiso', async ({ page }) => {
  const data = structuredClone(inventory);
  data.types[1].courtesy_used = 3;
  const posts = await open(page, { data, failWith: 'INVENTORY_COURTESY_BELOW_USAGE' });
  const row = page.locator('[data-ticket-type="11"]');
  await expect(row).toContainText('Cortesías: presupuesto 4 · consumidas 3 · saldo 1');
  await row.getByLabel('Cupo de cortesías de VIP').fill('2');
  await page.getByRole('button', { name: 'Guardar cupo' }).click();
  await expect(page.locator('.ticket-inventory-status')).toHaveText('El cupo de cortesías no puede ser menor que las cortesías consumidas.');
  await expect(row).toContainText('Cortesías: presupuesto 4 · consumidas 3 · saldo 1');
  expect(posts).toHaveLength(1);
  await open(page, { forbidden: true });
  await expect(page.locator('.ticket-inventory-status')).toHaveText('Solo un superadmin puede editar el cupo.');
  await expect(page.getByRole('button', { name: 'Guardar cupo' })).toHaveCount(0);
});

// Both viewport sizes exercise save and explicit clear with server-confirmed state.
for (const [width, height] of [[390, 844], [1440, 900]]) {
  test(`precio opcional de puerta: persistir, validar y vaciar a ${width}px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height });
    const posts = await open(page);
    const block = page.locator('[data-ticket-inventory]');
    const door = block.getByLabel('Precio en puerta (opcional) de VIP en COP');
    const save = block.getByRole('button', { name: 'Guardar cupo, precio y límites' });
    for (const bad of ['0', '-1', '1.5', '10000001']) {
      await door.fill(bad);
      await save.click();
      await expect(block.locator('.ticket-inventory-status')).toHaveText('Usa un precio entero entre 1 y 10.000.000 COP, o déjalo vacío.');
    }
    expect(posts).toHaveLength(0);
    await door.fill('90000');
    await expect(block.locator('[data-ticket-type="11"] .ticket-inventory-state')).toHaveText('Precio sin guardar');
    await save.click();
    await expect(block.locator('.ticket-inventory-status')).toHaveText('Cupo, precio y límites guardados.');
    await expect(door).toHaveValue('90000');
    expect(posts[0].body.types[1].door_price_cop).toBe(90000);
    await expect(block.locator('[data-ticket-type="11"]')).toContainText('90.000 COP');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
    await testInfo.attach(`precio-despues-${width}`, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
    await door.fill('');
    await save.click();
    await expect(block.locator('.ticket-inventory-status')).toHaveText('Cupo, precio y límites guardados.');
    await expect(door).toHaveValue('');
    expect(posts[1].body.types[1].door_price_cop).toBeNull();
    expect(posts.every(post => post.csrf === 'csrf-fixture')).toBe(true);
  });
}

// The dedicated visual workflow uses a shallow checkout. Fetch only the pinned
// public baseline when needed, and verify its exact blob before rendering.
function inventoryBaseline() {
  const sha = 'aedf900a624fff1a33e6b18d3917b4c451bc6dcf';
  const ref = sha + ':discadmin/ticketing-inventory.js';
  try {
    execFileSync('git', ['cat-file', '-e', ref], { stdio: 'ignore' });
  } catch {
    execFileSync('git', ['fetch', '--no-tags', '--depth=1', 'https://github.com/pl0n3r/brvtal.git', sha], { stdio: 'pipe', timeout: 15000 });
  }
  expect(execFileSync('git', ['rev-parse', ref], { encoding: 'utf8' }).trim()).toBe('36854393febcdd2e566b5380322fc238db3c2596');
  return execFileSync('git', ['show', ref], { encoding: 'utf8' });
}

// #2182: same committed pre-change module and fixture at both viewports.
for (const [width, height] of [[390, 844], [1440, 900]]) {
  test(`límites de compra: guardar, rechazar y vaciar a ${width}px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height });
    const data = { ...inventory, capacity: 50 };
    const baseline = inventoryBaseline();
    await open(page, { data, source: baseline });
    const block = page.locator('[data-ticket-inventory]');
    await expect(block.getByLabel('VIP', { exact: true })).toHaveValue('20');
    await expect(block.getByLabel('Máximo por comprador del evento')).toHaveCount(0);
    await testInfo.attach(`2182-antes-${width}`, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
    const posts = await open(page, { data });
    const buyer = block.getByLabel('Máximo por comprador del evento');
    const min = block.getByLabel('Mínimo por pedido de VIP');
    const max = block.getByLabel('Máximo por pedido de VIP');
    const save = block.getByRole('button', { name: 'Guardar cupo, precio y límites' });
    await expect(buyer).toHaveValue('');
    await expect(min).toHaveValue('');
    await expect(max).toHaveValue('');
    await buyer.fill('51');
    await save.click();
    await expect(block.locator('.ticket-inventory-status')).toContainText('Usa límites enteros positivos');
    await buyer.fill('10');
    await min.fill('9');
    await max.fill('8');
    await save.click();
    await expect(block.locator('.ticket-inventory-status')).toContainText('Usa límites enteros positivos');
    expect(posts).toHaveLength(0);
    await min.fill('2');
    await min.focus();
    await page.keyboard.press('Tab');
    await expect(max).toBeFocused();
    await expect(block.locator('[data-ticket-type="11"] .ticket-inventory-state')).toHaveText('Límites sin guardar');
    await save.click();
    await expect(block.locator('.ticket-inventory-status')).toHaveText('Cupo, precio y límites guardados.');
    await expect(buyer).toHaveValue('10');
    await expect(min).toHaveValue('2');
    await expect(max).toHaveValue('8');
    expect(posts[0].body.max_per_buyer).toBe(10);
    expect(posts[0].body.types[1]).toMatchObject({ min_per_order: 2, max_per_order: 8 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
    await testInfo.attach(`2182-despues-${width}`, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
    await buyer.fill('');
    await min.fill('');
    await max.fill('');
    await save.click();
    await expect(block.locator('.ticket-inventory-status')).toHaveText('Cupo, precio y límites guardados.');
    expect(posts[1].body.max_per_buyer).toBeNull();
    expect(posts[1].body.types[1]).toMatchObject({ min_per_order: null, max_per_order: null });
    await expect(buyer).toHaveValue('');
    await expect(min).toHaveValue('');
    await expect(max).toHaveValue('');
  });
}
