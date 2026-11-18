import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';

const pickerJs = readFileSync(join(process.cwd(), 'discadmin/admin-datetime-picker.js'), 'utf8');
const pickerCss = readFileSync(join(process.cwd(), 'discadmin/admin-datetime-picker.css'), 'utf8');
const fragmentPhp = readFileSync(join(process.cwd(), 'discadmin/content-core.php'), 'utf8');
const loaderJs = readFileSync(join(process.cwd(), 'discadmin/admin-modules.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/datetime-picker-fixture.html';

function fixture() {
  return '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<style>' + pickerCss + '</style></head><body>'
    + '<section data-admin-module="content-core"><div id="eventModal">'
    + '<label for="e_event_date">Date & time</label>'
    + '<input id="e_event_date" type="datetime-local" value="2026-12-04T21:00">'
    + '<div id="tickets"></div><button type="button" id="save">Guardar</button></div></section>'
    + '<script>const stored=localStorage.getItem("adp-test-date");'
    + 'if(stored!==null)document.getElementById("e_event_date").value=stored;'
    + 'window.__events=0;document.getElementById("e_event_date").addEventListener("change",()=>window.__events++);'
    + 'document.getElementById("save").onclick=()=>localStorage.setItem("adp-test-date",document.getElementById("e_event_date").value);'
    + '</script><script>' + pickerJs + '</script>'
    + '<script>window.BRVTALAdminDateTimePicker.mount(document.querySelector("[data-admin-module]"));</script>'
    + '</body></html>';
}

async function load(page) {
  await page.route('**/discadmin/datetime-picker-fixture.html', route => route.fulfill({
    contentType: 'text/html; charset=utf-8', body: fixture()
  }));
  await page.goto(harnessUrl);
  await expect(page.locator('#e_event_date + .adp-trigger')).toBeVisible();
}

test('the fragment declares the real picker assets and its shell loads inert fragment scripts explicitly', () => {
  expect(fragmentPhp).toContain('data-datetime-picker-script="/discadmin/admin-datetime-picker.js"');
  expect(fragmentPhp).toContain('data-datetime-picker-style="/discadmin/admin-datetime-picker.css"');
  expect(loaderJs).toContain('BRVTALAdminDateTimePicker');
});

test('mouse chooses calendar date and event hour, saves and reloads without changing native format', async ({page}) => {
  await load(page);
  await page.locator('#e_event_date + .adp-trigger').click();
  const dialog = page.getByRole('dialog', {name:'Elegir fecha y hora'});
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('.adp-day').first()).toHaveJSProperty('tagName', 'BUTTON');
  await dialog.locator('[data-adp-day="5"]').click();
  await dialog.getByRole('button', {name:'10 PM', exact:true}).click();
  await dialog.getByRole('button', {name:'Listo'}).click();
  await expect(page.locator('#e_event_date')).toHaveValue('2026-12-05T22:00');
  await expect(page.locator('#e_event_date + .adp-trigger')).toContainText('10:00 PM');
  expect(await page.evaluate(() => window.__events)).toBe(1);
  await page.locator('#save').click();
  await page.reload();
  await expect(page.locator('#e_event_date')).toHaveValue('2026-12-05T22:00');
  await expect(page.locator('#e_event_date + .adp-trigger')).toContainText('dic');
});

test('keyboard navigates day grid and months, traps focus and Escape cancels without emitting change', async ({page}) => {
  await load(page);
  const trigger = page.locator('#e_event_date + .adp-trigger');
  await trigger.focus();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', {name:'Elegir fecha y hora'});
  await dialog.locator('[data-adp-day="4"]').focus();
  await page.keyboard.press('ArrowRight');
  await expect(dialog.locator('[data-adp-day="5"]')).toBeFocused();
  await page.keyboard.press('PageDown');
  await expect(dialog.locator('[data-adp-month]')).toContainText('enero');
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(trigger).toBeFocused();
  await expect(page.locator('#e_event_date')).toHaveValue('2026-12-04T21:00');
  expect(await page.evaluate(() => window.__events)).toBe(0);

  await trigger.press('Enter');
  await dialog.locator('[data-adp-day="6"]').focus();
  await dialog.locator('[data-adp-day="6"]').press('ArrowRight');
  await dialog.locator('[data-adp-select="hour"]').selectOption('11');
  await dialog.locator('[data-adp-select="minute"]').selectOption('15');
  await dialog.locator('[data-adp-select="period"]').selectOption('PM');
  await dialog.getByRole('button', {name:'Listo'}).click();
  await expect(page.locator('#e_event_date')).toHaveValue('2026-12-07T23:15');
});

test('dynamic ticket and timetable fields are enhanced without altering their native values', async ({page}) => {
  await load(page);
  await page.evaluate(() => {
    for (const name of ['available_from','available_until','starts_at','ends_at']) {
      const input = document.createElement('input');
      input.type = 'datetime-local';
      input.dataset.k = name;
      document.getElementById('tickets').appendChild(input);
    }
  });
  await expect(page.locator('#tickets .adp-trigger')).toHaveCount(4);
  await page.locator('#tickets .adp-trigger').first().click();
  await page.getByRole('dialog').getByRole('button', {name:'9 PM'}).click();
  await page.getByRole('dialog').getByRole('button', {name:'Listo'}).click();
  await expect(page.locator('#tickets [data-k="available_from"]')).toHaveValue(/^\d{4}-\d{2}-\d{2}T21:00$/);
  await page.locator('#tickets .adp-trigger').nth(1).click();
  await page.getByRole('dialog').getByRole('button', {name:'Borrar'}).click();
  await expect(page.locator('#tickets [data-k="available_until"]')).toHaveValue('');
});

test('mobile sheet is full-width with at least 44px touch calendar cells', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await load(page);
  await page.locator('.adp-trigger').click();
  const result = await page.evaluate(() => {
    const panel = document.querySelector('.adp-panel').getBoundingClientRect();
    const cell = document.querySelector('.adp-day').getBoundingClientRect();
    return {width:panel.width, viewport:innerWidth, height:cell.height};
  });
  expect(result.width).toBeGreaterThanOrEqual(result.viewport - 1);
  expect(result.height).toBeGreaterThanOrEqual(44);
});

const base = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test('real-stack saves the same YYYY-MM-DD HH:MM:SS API contract and reloads the visual editor', async ({page}) => {
  test.skip(!base || !adminPassword, 'Only the disposable PHP/MariaDB stack can run this test');
  const login = await page.request.post(base + '/api/index.php/auth', {
    data: {email: adminEmail, password: adminPassword}
  });
  expect(login.ok()).toBeTruthy();
  const auth = await login.json();
  expect(auth.csrf).toBeTruthy();
  const slug = 'ci-adp-' + Date.now().toString(36);
  let eventId = 0;
  try {
    await page.goto(base + '/discadmin/?module=events', {waitUntil:'domcontentloaded'});
    await expect(page.locator('[data-admin-module="content-core"]')).toBeAttached({timeout:10000});
    await page.getByRole('button', {name:'+ NEW EVENT', exact:true}).click();
    await page.locator('#e_title').fill('CI DATE PICKER ' + slug);
    await page.locator('#e_slug').fill(slug);
    await page.locator('#eventModal [data-step="2"]').click();
    const trigger = page.locator('#e_event_date + .adp-trigger');
    await expect(trigger).toBeVisible();
    await trigger.click();
    const dialog = page.getByRole('dialog', {name:'Elegir fecha y hora'});
    const month = dialog.locator('[data-adp-month]');
    for (let i = 0; i < 14; i++) {
      if ((await month.textContent()).toLowerCase().includes('diciembre 2026')) break;
      await dialog.getByRole('button', {name:'Mes siguiente'}).click();
    }
    await expect(month).toContainText('diciembre 2026');
    await dialog.locator('[data-adp-day="5"]').click();
    await dialog.getByRole('button', {name:'10 PM', exact:true}).click();
    await dialog.getByRole('button', {name:'Listo'}).click();
    await expect(page.locator('#e_event_date')).toHaveValue('2026-12-05T22:00');
    await page.locator('#e_city').fill('Pereira');

    const saved = page.waitForResponse(response =>
      response.request().method() === 'POST' && response.url().endsWith('/api/event-workflow.php')
    );
    await page.locator('#cc-top-saveBtn').click();
    const response = await saved;
    expect(response.ok()).toBeTruthy();
    const payload = await response.json();
    eventId = Number(payload.data?.event?.id || 0);
    expect(eventId).toBeGreaterThan(0);
    const rows = await (await page.request.get(base + '/api/index.php/events')).json();
    const persisted = rows.data.find(item => Number(item.id) === eventId);
    expect(String(persisted.event_date).slice(0,16)).toBe('2026-12-05 22:00');

    await page.reload();
    await page.waitForFunction(() => typeof window.BRVTALContentCore?.openEvent === 'function');
    await page.evaluate(id => window.BRVTALContentCore.openEvent(id), eventId);
    await expect(page.locator('#e_event_date')).toHaveValue('2026-12-05T22:00');
    await expect(page.locator('#e_event_date + .adp-trigger')).toContainText('10:00 PM');
  } finally {
    if (eventId) {
      const deleted = await page.request.delete(base + '/api/index.php/events/' + eventId, {
        headers: {'X-CSRF-Token': auth.csrf}
      });
      expect(deleted.ok(), 'Disposable real-stack event must be cleaned up').toBeTruthy();
    }
  }
});
