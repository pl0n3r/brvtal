import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = readFileSync(join(process.cwd(), 'discadmin/admin-record-lists.css'), 'utf8');
const js = readFileSync(join(process.cwd(), 'discadmin/admin-record-lists.js'), 'utf8');
const legacyCss = `.thead,.tr{display:grid;grid-template-columns:2fr 1.1fr 1fr .8fr 165px;gap:12px}.actions{display:flex;justify-content:flex-end}@media(max-width:850px){.thead{display:none}.tr{grid-template-columns:1fr 1fr}.tr>div:nth-child(2),.tr>div:nth-child(3),.tr>div:nth-child(4){display:none}}`;

const fixtures = {
  EVENTS: ['GENESIS','2026-09-14 21:00','Pereira / La Perla','published','LINEUP EDIT DEL'],
  ARTISTS: ['PL0N3R','SOUNDCLOUD','published','10','EDIT DEL'],
  SETS: ['BRVTAL SESSION','PL0N3R','GENESIS','published','EDIT DEL'],
  MEDIA: ['GENESIS COVER','image','image/webp','published','EDIT DEL'],
  PAGES: ['ABOUT','en','published','','EDIT DEL'],
};

async function mount(page, moduleName) {
  const cells = fixtures[moduleName];
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${legacyCss}</style><style>${css}</style></head><body>
    <main class="main"><div class="top"><div class="admin-page-title"><h1>${moduleName}</h1></div></div>
      <div class="table"><div class="thead"><div>ONE</div><div>TWO</div><div>THREE</div><div>FOUR</div><div></div></div>
        <div id="rows"><div class="tr">
          <div><div class="title">${cells[0]}</div><div class="meta">sample-slug</div></div>
          <div>${cells[1]}</div><div>${cells[2]}</div><div>${cells[3]}</div><div class="actions">${cells[4]}</div>
        </div></div>
      </div>
    </main><script>${js}</script></body></html>`);
  await page.evaluate(() => window.BRVTALAdminRecordLists.decorate());
}

test('mobile legacy lists preserve every meaningful field and actions', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });

  for (const moduleName of Object.keys(fixtures)) {
    await mount(page, moduleName);
    const row = page.locator('#rows .tr');
    await expect(row).toBeVisible();
    await expect(page.locator('.table')).toHaveAttribute('data-record-list', moduleName.toLowerCase());

    const labels = moduleName === 'EVENTS'
      ? ['DATE','LOCATION','STATUS']
      : moduleName === 'ARTISTS'
        ? ['LINKS','STATUS','ORDER']
        : moduleName === 'SETS'
          ? ['ARTIST','EVENT','STATUS']
          : moduleName === 'MEDIA'
            ? ['TYPE','MIME','STATUS']
            : ['LOCALE','STATUS'];

    for (const label of labels) {
      await expect(row.locator(`[data-label="${label}"]`)).toBeVisible();
    }
    await expect(row.locator('.record-actions')).toBeVisible();
  }
});

test('event card shows date, location and status that legacy CSS used to hide', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mount(page, 'EVENTS');
  const row = page.locator('#rows .tr');
  await expect(row.getByText('2026-09-14 21:00')).toBeVisible();
  await expect(row.getByText('Pereira / La Perla')).toBeVisible();
  await expect(row.getByText('published')).toBeVisible();
  await expect(row.getByText('LINEUP EDIT DEL')).toBeVisible();
});

test('desktop keeps the existing five-column list layout', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await mount(page, 'EVENTS');
  const row = page.locator('#rows .tr');
  await expect(row.getByText('2026-09-14 21:00')).toBeVisible();
  await expect(page.locator('.thead')).toBeVisible();
  const columns = await row.evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length);
  expect(columns).toBe(5);
});
