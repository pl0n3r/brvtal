import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const js = readFileSync(join(process.cwd(), 'discadmin/settings-v2.js'), 'utf8');
const css = readFileSync(join(process.cwd(), 'discadmin/settings-v2.css'), 'utf8');
const url = 'http://127.0.0.1:4173/settings-v2-e2e.html';

function harness() {
  const rows = [
    {setting_key:'site',is_json:1,setting_value:JSON.stringify({name:'BRVTAL',tagline:'RAVE TILL GRAVE',default_locale:'es',available_locales:['es','en'],custom_keep:'preserve-me'})},
    {setting_key:'social',is_json:1,setting_value:JSON.stringify({instagram:'https://instagram.com/brvtal',soundcloud:'',youtube:'',spotify:''})},
    {setting_key:'appearance',is_json:1,setting_value:JSON.stringify({defaultAccent:'#ff1717'})},
    {setting_key:'theme.active',is_json:0,setting_value:'core'},
    {setting_key:'theme.core',is_json:1,setting_value:JSON.stringify({name:'BRVTAL CORE',branding:{siteName:'BRVTAL'}})},
  ];
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#050505;color:#fff;font-family:Arial}.btn,.iconbtn{border:1px solid #444;background:#111;color:#fff;padding:10px}.btn.red{background:#ff2038}.btn.ghost{background:transparent}</style><style>${css}</style></head><body><div id="app"></div><div id="modal"></div><script>
    window.state={section:'settings',rows:${JSON.stringify(rows)}};
    window.__posts=[];window.__legacy=[];window.__newRaw=0;
    window.openSettingByKey=function(key){window.__legacy.push(key)};
    window.openModal=function(type){if(type==='settings')window.__newRaw+=1};
    window.req=async function(path,options={}){
      if(path==='/settings'&&options.method==='POST'){
        const payload=JSON.parse(options.body);window.__posts.push(payload);
        const idx=state.rows.findIndex(row=>row.setting_key===payload.setting_key);
        const next={setting_key:payload.setting_key,setting_value:payload.setting_value,is_json:payload.is_json};
        if(idx>=0)state.rows[idx]=next;else state.rows.push(next);
        return {ok:true};
      }
      if(path==='/settings')return {data:state.rows};
      if(path==='/media')return {data:[{id:1,type:'image',title:'Share',file_path:'/share.webp'}]};
      return {data:[]};
    };
    window.render=function(){document.getElementById('app').innerHTML=window.settingsHome(state.rows)};
  </script><script>${js}</script><script>render()</script></body></html>`;
}

async function open(page, viewport={width:1280,height:900}) {
  await page.setViewportSize(viewport);
  await page.route(url, route => route.fulfill({contentType:'text/html; charset=utf-8',body:harness()}));
  await page.goto(url);
}

test('Settings uses typed logical sections instead of raw JSON as the primary UI', async ({ page }) => {
  await open(page);
  await expect(page.locator('[data-settings-tab]')).toHaveText(['01GENERAL','02SOCIAL & CONTACT','03SEO','04ANALYTICS & PRIVACY','05ADVANCED']);
  await expect(page.locator('[data-settings-pane="general"]')).toBeVisible();
  await expect(page.locator('#sv2_site_name')).toHaveValue('BRVTAL');
  await expect(page.getByText(/reserved for #212/i)).toBeVisible();
  await expect(page.locator('[data-settings-pane="general"] textarea')).toHaveCount(0);
});

test('typed save preserves unknown sibling JSON keys', async ({ page }) => {
  await open(page);
  await page.locator('#sv2_site_name').fill('BRVTAL SIGNAL');
  await page.getByRole('button',{name:'SAVE GENERAL'}).click();
  await expect.poll(() => page.evaluate(() => window.__posts.length)).toBe(1);
  const payload = await page.evaluate(() => window.__posts[0]);
  const value = JSON.parse(payload.setting_value);
  expect(payload.setting_key).toBe('site');
  expect(value.name).toBe('BRVTAL SIGNAL');
  expect(value.default_locale).toBe('es');
  expect(value.available_locales).toEqual(['es','en']);
  expect(value.custom_keep).toBe('preserve-me');
});

test('SEO and Analytics are first-class typed Settings while raw editing stays Advanced', async ({ page }) => {
  await open(page);
  await page.getByRole('button',{name:/03SEO/}).click();
  await expect(page.locator('#sv2_seo_title')).toBeVisible();
  await expect(page.getByRole('button',{name:'CHOOSE FROM MEDIA'})).toBeVisible();

  await page.getByRole('button',{name:/04ANALYTICS & PRIVACY/}).click();
  await expect(page.locator('#sv2_ga4_id')).toBeVisible();
  await expect(page.getByText('REQUIRED / USER CHOICE')).toBeVisible();

  await page.getByRole('button',{name:/05ADVANCED/}).click();
  await expect(page.getByText('RAW SETTINGS')).toBeVisible();
  await page.locator('[data-settings-raw="appearance"]').click();
  await expect.poll(() => page.evaluate(() => window.__legacy)).toEqual(['appearance']);
});

test('Settings remains usable on mobile without horizontal overflow', async ({ page }) => {
  await open(page,{width:390,height:844});
  const metrics = await page.evaluate(() => ({
    scrollWidth:document.documentElement.scrollWidth,
    width:window.innerWidth,
    tabs:[...document.querySelectorAll('[data-settings-tab]')].map(node=>node.getBoundingClientRect().height),
    save:document.querySelector('[data-settings-save="general"]').getBoundingClientRect().height,
  }));
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.width + 1);
  expect(Math.min(...metrics.tabs)).toBeGreaterThanOrEqual(44);
  expect(metrics.save).toBeGreaterThanOrEqual(44);
});
