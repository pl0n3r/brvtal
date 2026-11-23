import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// #1925: the V3 menu roots stay compact; .ia-navgroup must not inherit the
// old group-heading margin from admin-console.css.
const css = ['admin-appearance', 'admin-shell', 'admin-information-architecture', 'admin-design-system', 'admin-console']
  .map(name => readFileSync(join(process.cwd(), `discadmin/${name}.css`), 'utf8'))
  .join('\n');
const harnessUrl = 'http://127.0.0.1:4173/discadmin-sidebar-compact-e2e.html';

const roots = ['BOLETERÍA', 'CORREOS', 'SITIO WEB', 'CONFIGURACIÓN', 'MÚSICA'];
const children = ['GENERAL', 'REDES SOCIALES Y CONTACTO', 'INTEGRACIONES', 'SEGURIDAD', 'ESTADO DEL SISTEMA', 'COPIAS DE SEGURIDAD'];

function group(label, open) {
  const key = label.toLowerCase().replace(/[^a-z]+/g, '-');
  const leaves = children.map(child => `<button type="button" data-ia-subnav="1">${child}</button>`).join('');
  return `<div class="ia-navgroup" data-ia-group="${key}"><div class="ia-navhead">`
    + `<button type="button" class="ia-nav-parent" data-ia-key="${key}" aria-expanded="${open}">${label}</button></div>`
    + `<div class="ia-navchildren" role="group"${open ? '' : ' hidden'}>${leaves}</div></div>`;
}

async function mount(page) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType: 'text/html; charset=utf-8',
    body: `<!doctype html><html data-discadmin-appearance="dark"><head><style>${css}</style></head><body>
      <div id="app"><div class="shell"><aside class="side" id="admin-primary-nav">
        <div class="logo">BRVTAL</div><div class="sub">DISCADMIN</div>
        <div class="nav" data-ia-tree="v3">${roots.map(label => group(label, label === 'CONFIGURACIÓN')).join('')}</div>
        <div class="sidefoot"><div class="brvtal-admin-release"><span class="version">BRVTAL v0.0.0</span></div></div>
      </aside><main class="main"><div class="top"><h1>Configuración</h1></div></main></div></div>
    </body></html>`,
  }));
  await page.goto(harnessUrl);
}

for (const [width, height] of [[1440, 900], [390, 844]]) {
  test(`V3 menu roots stay compact at ${width}px`, async ({ page }, testInfo) => {
    await page.setViewportSize({ width, height });
    await mount(page);
    // On phones the sidebar is an off-canvas drawer: open it like the menu button does.
    await page.evaluate(() => {
      document.querySelector('.shell').classList.add('admin-nav-open');
      document.body.classList.add('admin-nav-open');
    });
    await expect(page.locator('#admin-primary-nav')).toBeVisible();
    const tops = await page.locator('.nav[data-ia-tree="v3"] .ia-navhead').evaluateAll(heads =>
      heads.map(head => head.getBoundingClientRect().top));
    expect(tops).toHaveLength(roots.length);
    // Collapsed neighbours: BOLETERÍA → CORREOS → SITIO WEB → CONFIGURACIÓN.
    for (let i = 1; i <= 3; i += 1) expect(tops[i] - tops[i - 1]).toBeLessThanOrEqual(48);
    const margin = await page.locator('.ia-navgroup').first().evaluate(el => getComputedStyle(el).marginTop);
    expect(margin).toBe('0px');
    const rootHeight = await page.locator('.ia-nav-parent').first().evaluate(el => el.getBoundingClientRect().height);
    expect(rootHeight).toBeGreaterThanOrEqual(40);
    await testInfo.attach(`sidebar-${width}`, { body: await page.locator('#admin-primary-nav').screenshot(), contentType: 'image/png' });
  });
}
