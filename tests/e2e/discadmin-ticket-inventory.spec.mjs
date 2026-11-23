import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

// #1931: capacity for online sale in the event editor (Boletas step).
const moduleSource = readFileSync('discadmin/ticketing-inventory.js', 'utf8');
const css = readFileSync('discadmin/content-core.css', 'utf8');
const url = 'http://127.0.0.1:4173/discadmin/inventory-fixture.html';

const inventory = {
  ok: true, event_id: 7, capacity: null, used: 5,
  types: [
    { ticket_type_id: 10, name: 'Preventa', status: 'active', capacity: null, courtesy_capacity: 0, sold: 0, held: 2, courtesy_used: 0 },
    { ticket_type_id: 11, name: 'VIP', status: 'active', capacity: 20, courtesy_capacity: 4, sold: 3, held: 0, courtesy_used: 0 },
    { ticket_type_id: 12, name: 'Mesa', status: 'draft', capacity: null, courtesy_capacity: 0, sold: 0, held: 0, courtesy_used: 0 },
  ],
};

async function open(page, { failWith = null, eventId = 7 } = {}) {
  await page.route('**/discadmin/inventory-fixture.html', route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head>
      <body data-admin-module="content-core" style="margin:0"><div class="wrap"><div class="section">
      <div class="sectionhead"><strong>Boletas</strong></div><div id="tickets"></div><div id="host" class="ticket-inventory-host"></div></div></div></body></html>`,
  }));
  const posts = [];
  let current = structuredClone(inventory);
  await page.route('**/api/ticketing/admin-inventory.php**', async route => {
    const request = route.request();
    if (request.method() === 'POST') {
      posts.push({ body: request.postDataJSON(), csrf: request.headers()['x-csrf-token'] });
      if (failWith) return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, error: failWith }) });
      const body = request.postDataJSON();
      current = { ...current, capacity: body.capacity, types: current.types.map(type => ({
        ...type, capacity: body.types.find(row => row.ticket_type_id === type.ticket_type_id)?.capacity ?? type.capacity })) };
    }
    return route.fulfill({ contentType: 'application/json', body: JSON.stringify(current) });
  });
  await page.goto(url);
  await page.evaluate(() => { window.csrf = 'csrf-fixture'; });
  await page.addScriptTag({ type: 'module', content: moduleSource.replace('export async function mount', 'async function mount')
    + `\nwindow.__mount = mount;` });
  await page.waitForFunction(() => typeof window.__mount === 'function');
  await page.evaluate(id => window.__mount(document.getElementById('host'), { eventId: id }), eventId);
  return posts;
}

test('muestra aforo, cupo y uso por boleta y guarda con CSRF', async ({ page }, testInfo) => {
  for (const [width, height] of [[1440, 900], [390, 844]]) {
    await page.setViewportSize({ width, height });
    await page.goto('about:blank');
    await page.setContent(`<style>${css}</style><body data-admin-module="content-core"><div class="wrap"><div class="section"><div class="sectionhead"><strong>Boletas</strong></div><div id="tickets"></div></div></div></body>`);
    await testInfo.attach(`antes-${width}`, { body: await page.screenshot(), contentType: 'image/png' });
    await open(page);
    const block = page.locator('[data-ticket-inventory]');
    await expect(block.getByRole('heading', { name: 'Cupo para venta en línea' })).toBeVisible();
    await expect(block.getByLabel('Aforo total del evento')).toHaveValue('');
    await expect(block.getByText('En uso: 5')).toBeVisible();
    await expect(block.getByLabel('VIP')).toHaveValue('20');
    await expect(block.locator('[data-ticket-type="11"]')).toContainText('Vendidas 3 · Reservadas 0 · Cortesías 4');
    await expect(block.locator('[data-ticket-type="11"] .ticket-inventory-state')).toHaveText('Se vende en línea');
    await expect(block.locator('[data-ticket-type="10"] .ticket-inventory-state')).toHaveText('No se vende en línea');
    await expect(block.locator('[data-ticket-type="12"] .ticket-inventory-state')).toHaveText('Boleta no activa');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
    await testInfo.attach(`despues-${width}`, { body: await block.screenshot(), contentType: 'image/png' });
  }
  const posts = await open(page);
  const block = page.locator('[data-ticket-inventory]');
  await block.getByLabel('Aforo total del evento').fill('300');
  await block.getByLabel('Preventa').fill('200');
  await expect(block.locator('[data-ticket-type="10"] .ticket-inventory-state')).toHaveText('Se vende en línea');
  await block.getByRole('button', { name: 'Guardar cupo' }).click();
  await expect(page.locator('.ticket-inventory-status')).toHaveText('Cupo guardado.');
  expect(posts).toEqual([{ csrf: 'csrf-fixture', body: { capacity: 300, types: [
    { ticket_type_id: 10, capacity: 200 }, { ticket_type_id: 11, capacity: 20 }, { ticket_type_id: 12, capacity: 0 }] } }]);
  await expect(page.getByLabel('Preventa')).toHaveValue('200');
});

test('explica el rechazo del servidor y valida números antes de enviar', async ({ page }) => {
  const posts = await open(page, { failWith: 'INVENTORY_BELOW_USAGE' });
  const block = page.locator('[data-ticket-inventory]');
  await block.getByLabel('VIP').fill('1');
  await block.getByRole('button', { name: 'Guardar cupo' }).click();
  await expect(block.locator('.ticket-inventory-status')).toHaveText('El cupo no puede ser menor que lo ya vendido o reservado.');
  await expect(block.getByRole('button', { name: 'Guardar cupo' })).toBeEnabled();
  await block.getByLabel('VIP').fill('2.5');
  await block.getByRole('button', { name: 'Guardar cupo' }).click();
  await expect(block.locator('.ticket-inventory-status')).toHaveText('Usa números enteros entre 0 y 100.000.');
  expect(posts).toHaveLength(1);
});

test('un evento sin guardar no muestra cupo editable', async ({ page }) => {
  await open(page, { eventId: 0 });
  await expect(page.locator('[data-ticket-inventory]')).toContainText('Guarda el evento para configurar el cupo.');
  await expect(page.getByRole('button', { name: 'Guardar cupo' })).toHaveCount(0);
});
