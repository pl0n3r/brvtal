import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// Actual Settings renderer and CSS; only the site payload is synthetic.
const root = process.cwd();
const css = readFileSync(join(root, 'discadmin/settings-v2.css'), 'utf8');
const runtime = readFileSync(join(root, 'discadmin/settings-v2.js'), 'utf8');
const marker = '/* #1867: Settings (Operate) — vertical rhythm for labels, controls and help.';
const markerAt = css.indexOf(marker);
if (markerAt < 0) throw new Error('Issue #1867 visual patch is missing');
const baseline = css.slice(0, markerAt);
const url = 'http://127.0.0.1:4173/discadmin-settings-labels-1867.html';

const site = {
  name: 'BRVTAL', tagline: 'RAVE TILL GRAVE', description: 'La cultura nos conecta',
  default_locale: 'es', available_locales: ['es'],
  hero_manifesto_es: 'NUESTRA COMUNIDAD', hero_manifesto_en: 'PRESERVED EN MANIFESTO',
  hero_cta_es: 'EXPLORA BRVTAL', hero_cta_en: 'PRESERVED EN CTA',
  hero_eyebrow_es: 'NUESTRO MUNDO', hero_eyebrow_en: 'PRESERVED EN EYEBROW',
  legacy_unrelated: 'KEEP'
};

function fixture(fixed) {
  const rows = [{
    setting_key: 'site', is_json: 1, setting_value: JSON.stringify(site)
  }];
  const bootstrap = 'window.state=' + JSON.stringify({ rows }) + ';' +
    'window.__posts=[];window.__errors=[];' +
    'window.BRVTALFeedback={progress(){},success(){},error(message){window.__errors.push(message)}};' +
    'window.req=async function(path,options={}){' +
    'if(path==="/settings"&&options.method==="POST"){' +
    'const value=JSON.parse(options.body);window.__posts.push(value);' +
    'window.state.rows=[value];return {ok:true};}' +
    'if(path==="/settings")return {data:window.state.rows};return {data:[]};};' +
    'window.render=function(){document.getElementById("app").innerHTML=window.settingsHome(window.state.rows)};';
  return '<!doctype html><html lang="es"><head>' +
    '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<style>*,*::before,*::after{box-sizing:border-box}body{margin:0;background:#050505;color:#f4f5f6;font-family:Arial,Helvetica,sans-serif}' +
    '.btn{border:0;background:#fff;color:#000;padding:12px 17px;cursor:pointer}.btn.red{background:#ff2038;color:#fff}</style>' +
    '<style>' + (fixed ? css : baseline) + '</style></head>' +
    '<body><main id="app"></main><script>' + bootstrap + '</script>' +
    '<script>' + runtime + '</script><script>window.render()</script></body></html>';
}

async function mount(page, fixed) {
  await page.goto(url + '?fixed=' + (fixed ? '1' : '0'));
  await expect(page.locator('[data-settings-pane="general"]')).toBeVisible();
  await expect(page.locator('#sv2_site_name')).toHaveValue('BRVTAL');
}

async function capture(page, testInfo, name) {
  const path = testInfo.outputPath(name);
  await page.screenshot({ path, fullPage: true });
  await testInfo.attach(name, { path, contentType: 'image/png' });
}

async function measure(page) {
  return page.evaluate(() => {
    const pane = document.querySelector('[data-settings-pane="general"]');
    const fields = [...pane.querySelectorAll('.sv2-field')]
      .filter(field => getComputedStyle(field).display !== 'none' && field.getClientRects().length);
    const labels = fields.map(field => ({field, node:field.querySelector(':scope > span')}))
      .filter(item => item.node);
    const helps = fields.map(field => ({field, node:field.querySelector(':scope > small')}))
      .filter(item => item.node);
    const overlap = (a,b) => {
      const x = Math.min(a.right,b.right)-Math.max(a.left,b.left);
      const y = Math.min(a.bottom,b.bottom)-Math.max(a.top,b.top);
      return x > 1 && y > 1;
    };
    const collisions = [];
    let minimumSpacing = Infinity;
    for (const help of helps) {
      const rect = help.node.getBoundingClientRect();
      const input = help.field.querySelector(':scope > input');
      if (!input || input.getBoundingClientRect().bottom > rect.top + .5) {
        collisions.push('Help crosses its own input');
      }
      for (const label of labels) {
        if (label.field === help.field) continue;
        const next = label.node.getBoundingClientRect();
        const horizontal = Math.min(rect.right,next.right)-Math.max(rect.left,next.left);
        if (horizontal <= 1 || next.top < rect.top) continue;
        minimumSpacing = Math.min(minimumSpacing, next.top-rect.bottom);
        if (overlap(rect,next)) collisions.push('Help overlaps next field label');
      }
    }
    return {
      collisions,
      minimumSpacing,
      viewportWidth:document.documentElement.clientWidth,
      scrollWidth:document.documentElement.scrollWidth,
      visibleLabels:labels.length,
      visibleHelp:helps.length
    };
  });
}

for (const width of [390,1440]) {
  test('Settings: visible labels never collide with help at ' + width + 'px', async ({ page }, testInfo) => {
    await page.setViewportSize({width, height:900});
    await page.route('**/discadmin-settings-labels-1867.html*', route => {
      const fixed = new URL(route.request().url()).searchParams.get('fixed') === '1';
      return route.fulfill({contentType:'text/html; charset=utf-8',body:fixture(fixed)});
    });
    await mount(page, false);
    await capture(page, testInfo, 'before-' + width + '.png');
    await mount(page, true);
    for (const key of ['site_hero_manifesto_en','site_hero_cta_en','site_hero_eyebrow_en']) {
      await expect(page.locator('#sv2_' + key)).toBeHidden();
    }
    await expect(page.locator('#sv2_site_hero_manifesto_es')).toBeVisible();
    await expect(page.locator('#sv2_site_hero_cta_es')).toBeVisible();
    await expect(page.locator('#sv2_site_hero_eyebrow_es')).toBeVisible();
    const measured = await measure(page);
    expect(measured.collisions).toEqual([]);
    expect(measured.minimumSpacing).toBeGreaterThanOrEqual(12);
    expect(measured.visibleHelp).toBeGreaterThanOrEqual(5);
    expect(measured.scrollWidth).toBeLessThanOrEqual(measured.viewportWidth + 1);
    await capture(page, testInfo, 'after-' + width + '.png');
  });
}

test('Spanish-only visual hiding preserves stored English values on save', async ({page}) => {
  await page.setViewportSize({width:390,height:900});
  await page.route('**/discadmin-settings-labels-1867.html*', route =>
    route.fulfill({contentType:'text/html; charset=utf-8',body:fixture(true)}));
  await mount(page, true);
  await expect(page.locator('#sv2_site_hero_manifesto_en')).toBeHidden();
  await page.locator('#sv2_site_name').fill('BRVTAL ACTUALIZADO');
  await page.locator('[data-settings-save="general"]').click();
  await expect.poll(() => page.evaluate(() => window.__posts.length)).toBe(1);
  const persisted = await page.evaluate(() => JSON.parse(window.__posts[0].setting_value));
  expect(persisted).toMatchObject({
    name:'BRVTAL ACTUALIZADO', hero_manifesto_en:'PRESERVED EN MANIFESTO',
    hero_cta_en:'PRESERVED EN CTA', hero_eyebrow_en:'PRESERVED EN EYEBROW',
    legacy_unrelated:'KEEP'
  });
  expect(await page.evaluate(() => window.__errors)).toEqual([]);
});
