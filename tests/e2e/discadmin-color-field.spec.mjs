import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = readFileSync(join(process.cwd(), 'discadmin/admin-color-field.css'), 'utf8');
const js = readFileSync(join(process.cwd(), 'discadmin/admin-color-field.js'), 'utf8');

async function mount(page) {
  await page.setContent(`<!doctype html><html><head><style>${css}</style></head><body>
    <div class="field" data-admin-color-field data-color-default="#ff2038">
      <label for="event-accent">Accent</label>
      <div class="admin-color-field__controls">
        <input id="event-accent" data-color-hex maxlength="7" pattern="#[0-9A-Fa-f]{6}" aria-describedby="event-accent-help event-accent-error">
        <input id="event-accent-picker" data-color-picker type="color" value="#ff2038" aria-label="Choose accent visually">
      </div>
      <div class="admin-color-field__meta">
        <span data-color-swatch class="admin-color-field__swatch" aria-hidden="true"></span>
        <output data-color-value class="admin-color-field__value">No accent</output>
      </div>
      <div id="event-accent-help">Exact HEX</div>
      <div id="event-accent-error" data-color-error class="admin-color-field__error" hidden>Invalid HEX</div>
    </div>
    <script>${js}</script>
  </body></html>`);
}

test('HEX entry normalizes and stays synchronized with picker and preview', async ({ page }) => {
  await mount(page);
  const text = page.locator('#event-accent');
  const picker = page.locator('#event-accent-picker');

  await text.fill('A1B2C3');
  await text.blur();

  await expect(text).toHaveValue('#a1b2c3');
  await expect(text).toHaveAttribute('aria-invalid', 'false');
  await expect(picker).toHaveValue('#a1b2c3');
  await expect(page.locator('[data-color-value]')).toHaveText('#A1B2C3');
  await expect(page.locator('[data-color-error]')).toBeHidden();
});

test('visual picker writes exact HEX back to the text field', async ({ page }) => {
  await mount(page);
  await page.locator('#event-accent-picker').evaluate(picker => {
    picker.value = '#12ab34';
    picker.dispatchEvent(new Event('input', { bubbles:true }));
  });

  await expect(page.locator('#event-accent')).toHaveValue('#12ab34');
  await expect(page.locator('[data-color-value]')).toHaveText('#12AB34');
});

test('invalid HEX is visible and reusable normalization fails closed', async ({ page }) => {
  await mount(page);
  const text = page.locator('#event-accent');
  await text.fill('#12');

  await expect(text).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('[data-color-error]')).toBeVisible();
  expect(await page.evaluate(() => window.BRVTALAdminColorField.normalize('#12'))).toBeNull();
  expect(await page.evaluate(() => window.BRVTALAdminColorField.normalize('ABCDEF'))).toBe('#abcdef');
});

test('sync refreshes a field when an editor assigns its value programmatically', async ({ page }) => {
  await mount(page);
  await page.evaluate(() => {
    const input = document.getElementById('event-accent');
    input.value = '#445566';
    window.BRVTALAdminColorField.sync(input);
  });

  await expect(page.locator('#event-accent-picker')).toHaveValue('#445566');
  await expect(page.locator('[data-color-value]')).toHaveText('#445566'.toUpperCase());
});
