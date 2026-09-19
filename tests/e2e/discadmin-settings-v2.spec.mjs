import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const aliasesJs = readFileSync(join(process.cwd(), 'discadmin/admin-route-aliases.js'), 'utf8');
const securityJs = readFileSync(join(process.cwd(), 'discadmin/security.js'), 'utf8');
const js = readFileSync(join(process.cwd(), 'discadmin/settings-v2.js'), 'utf8');
const css = readFileSync(join(process.cwd(), 'discadmin/settings-v2.css'), 'utf8');
const url = 'http://127.0.0.1:4173/settings-v2-e2e.html';

function harness() {
  const rows = [
    {setting_key:'site',is_json:1,setting_value:JSON.stringify({name:'BRVTAL',tagline:'RAVE TILL GRAVE',default_locale:'es',available_locales:['es','en'],custom_keep:'preserve-me'})},
    {setting_key:'social',is_json:1,setting_value:JSON.stringify({instagram:'https://instagram.com/brvtal',soundcloud:'',youtube:'',spotify:'',website:'',custom_keep:'social-preserve'})},
    {setting_key:'seo',is_json:1,setting_value:JSON.stringify({site_title:'BRVTAL',description:'Default description',share_image:'',custom_keep:'seo-preserve'})},
    {setting_key:'analytics',is_json:1,setting_value:JSON.stringify({gtm_id:'',ga4_id:'G-OLD',google:'legacy',measurement_id:'legacy',google_tag_manager:'GTM-OLD1',tag_manager:'GTM-OLD2',gtm:'GTM-OLD3',custom_keep:'analytics-preserve'})},
    {setting_key:'appearance',is_json:1,setting_value:JSON.stringify({defaultAccent:'#ff1717'})},
    {setting_key:'theme.active',is_json:0,setting_value:'core'},
    {setting_key:'theme.core',is_json:1,setting_value:JSON.stringify({name:'BRVTAL CORE',branding:{siteName:'BRVTAL'}})},
  ];
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#050505;color:#fff;font-family:Arial}.btn,.iconbtn{border:1px solid #444;background:#111;color:#fff;padding:10px}.btn.red{background:#ff2038}.btn.ghost{background:transparent}</style><style>${css}</style></head><body><div id="app"></div><div id="modal"></div><script>
    window.state={section:'settings',rows:${JSON.stringify(rows)}};
    window.csrf='test-csrf';
    window.__posts=[];window.__legacy=[];window.__newRaw=0;window.__feedback=[];window.__settingsRoutes=[];window.__statusRequests=[];window.__totpStatusEmail='admin@example.test';
    window.fetch=async function(input,options={}){
      if(String(input).includes('/discadmin/totp-api.php?action=status')){
        window.__statusRequests.push({method:options.method,csrf:options.headers?.['X-CSRF-Token']});
        return new Response(JSON.stringify({ok:true,enabled:false,confirmed:false,email:window.__totpStatusEmail}),{status:200,headers:{'Content-Type':'application/json'}});
      }
      return new Response('{}',{status:200,headers:{'Content-Type':'application/json'}});
    };
    window.BRVTALFeedback={
      progress(message){window.__feedback.push(['progress',message])},
      success(message){window.__feedback.push(['success',message])},
      error(message){window.__feedback.push(['error',message])}
    };
    window.openSettingByKey=function(key){window.__legacy.push(key)};
    window.openModal=function(type){if(type==='settings')window.__newRaw+=1};
    window.go=async function(section){window.__settingsRoutes.push(['go',section]);};
    window.tech=async function(section){window.__settingsRoutes.push(['tech',section]);};
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
  </script><script>${securityJs}</script><script>${aliasesJs}</script><script>${js}</script><script>render()</script></body></html>`;
}

async function open(page, viewport={width:1280,height:900}) {
  await page.setViewportSize(viewport);
  await page.route('**/settings-v2-e2e.html*', route => route.fulfill({contentType:'text/html; charset=utf-8',body:harness()}));
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

test('Advanced contains working Security, Theme and System tools without raw-settings UI', async ({ page }) => {
  await open(page);

  await page.locator('[data-settings-tab="advanced"]').click();
  await expect(page.getByText('CONTROL PLANE',{exact:true})).toHaveCount(0);
  await expect(page.getByText('RAW SETTINGS',{exact:true})).toHaveCount(0);
  await expect(page.getByRole('button',{name:/NEW ADVANCED SETTING/i})).toHaveCount(0);
  await expect(page.getByRole('button',{name:/RAW EDIT/i})).toHaveCount(0);
  await expect(page.getByTestId('settings-security-host').locator('[data-admin-module="security"]')).toBeVisible();
  const security = page.getByTestId('settings-security-host').locator('[data-admin-module="security"]');
  await expect(security.getByText('2FA DISABLED')).toBeVisible();
  await expect(security).toHaveAttribute('data-security-mounted','1');
  await expect.poll(() => page.evaluate(() => window.__statusRequests)).toEqual([{method:'POST',csrf:'test-csrf'}]);

  const theme = page.getByRole('button',{name:'OPEN THEME STUDIO'});
  const system = page.getByRole('button',{name:'OPEN SYSTEM STATUS'});
  await expect(theme).toBeVisible();
  await expect(system).toBeVisible();
  await theme.click();
  await system.click();
  await expect.poll(() => page.evaluate(() => window.__settingsRoutes)).toEqual([['go','theme'],['tech','system']]);

  await page.evaluate(() => { window.__settingsRoutes = []; window.tech = undefined; });
  await system.click();
  await expect.poll(() => page.evaluate(() => window.__settingsRoutes)).toEqual([['go','system']]);
});

test('legacy Security navigation resolves to Settings Advanced', async ({ page }) => {
  await open(page);
  await page.evaluate(async () => { await window.go('security'); });
  await expect.poll(() => page.evaluate(() => window.__settingsRoutes)).toEqual([['go','settings']]);
  await expect(page.locator('[data-settings-pane="advanced"]')).toBeVisible();
  await expect(page.getByTestId('settings-security-host').locator('[data-admin-module="security"]')).toBeVisible();
});

test('embedded Security renders status data as text rather than markup', async ({ page }) => {
  await open(page);
  await page.evaluate(() => {
    window.__totpStatusEmail = '<strong>admin@example.test</strong>';
  });
  await page.locator('[data-settings-tab="advanced"]').click();

  const security = page.getByTestId('settings-security-host').locator('[data-admin-module="security"]');
  await expect(security.locator('strong', {hasText:'admin@example.test'})).toHaveCount(0);
  await expect(security.locator('[data-security-email]')).toHaveText('<strong>admin@example.test</strong>');
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

test('typed save handlers preserve sibling data across Social, SEO, and Analytics', async ({ page }) => {
  await open(page);

  await page.locator('[data-settings-tab="social"]').click();
  await page.locator('#sv2_website').fill('https://brvtal.com.co');
  await page.getByRole('button',{name:'SAVE SOCIAL'}).click();
  await expect.poll(() => page.evaluate(() => window.__posts.length)).toBe(1);

  await page.locator('[data-settings-tab="seo"]').click();
  await page.locator('#sv2_seo_title').fill('BRVTAL Archive');
  await page.locator('#sv2_seo_share').fill('/uploads/share.webp');
  await page.getByRole('button',{name:'SAVE SEO'}).click();
  await expect.poll(() => page.evaluate(() => window.__posts.length)).toBe(2);

  await page.locator('[data-settings-tab="analytics"]').click();
  await page.locator('#sv2_gtm_id').fill('gtm-abcd123');
  await page.getByRole('button',{name:'SAVE TAG MANAGER'}).click();
  await expect.poll(() => page.evaluate(() => window.__posts.length)).toBe(3);

  const posts = await page.evaluate(() => window.__posts.map(post => ({
    key:post.setting_key,
    value:JSON.parse(post.setting_value)
  })));

  expect(posts[0]).toEqual({
    key:'social',
    value:{
      instagram:'https://instagram.com/brvtal',
      soundcloud:'',
      youtube:'',
      spotify:'',
      website:'https://brvtal.com.co',
      custom_keep:'social-preserve'
    }
  });
  expect(posts[1].key).toBe('seo');
  expect(posts[1].value.site_title).toBe('BRVTAL Archive');
  expect(posts[1].value.share_image).toBe('/uploads/share.webp');
  expect(posts[1].value.custom_keep).toBe('seo-preserve');
  expect(posts[2].key).toBe('analytics');
  expect(posts[2].value.gtm_id).toBe('GTM-ABCD123');
  expect(posts[2].value.custom_keep).toBe('analytics-preserve');
  for (const legacy of ['ga4_id','google','measurement_id','google_tag_manager','tag_manager','gtm']) {
    expect(posts[2].value).not.toHaveProperty(legacy);
  }
});

test('typed save handlers keep validation failures from persisting invalid values', async ({ page }) => {
  await open(page);

  await page.locator('[data-settings-tab="social"]').click();
  await page.locator('#sv2_instagram').fill('javascript:alert(1)');
  await page.getByRole('button',{name:'SAVE SOCIAL'}).click();
  await expect.poll(() => page.evaluate(() => window.__feedback.at(-1))).toEqual(['error','INSTAGRAM must be an HTTP/HTTPS URL.']);
  expect(await page.evaluate(() => window.__posts.length)).toBe(0);

  await page.locator('[data-settings-tab="analytics"]').click();
  await page.locator('#sv2_gtm_id').fill('invalid-container');
  await page.getByRole('button',{name:'SAVE TAG MANAGER'}).click();
  await expect.poll(() => page.evaluate(() => window.__feedback.at(-1))).toEqual(['error','Google Tag Manager ID must use the GTM-XXXXXXX format.']);
  expect(await page.evaluate(() => window.__posts.length)).toBe(0);
});

test('SEO and Analytics remain typed while Advanced avoids arbitrary record editing', async ({ page }) => {
  await open(page);
  await page.locator('[data-settings-tab="seo"]').click();
  await expect(page.locator('#sv2_seo_title')).toBeVisible();
  await expect(page.getByRole('button',{name:'CHOOSE FROM MEDIA'})).toBeVisible();

  await page.locator('[data-settings-tab="analytics"]').click();
  await expect(page.locator('#sv2_gtm_id')).toBeVisible();
  await expect(page.locator('#sv2_ga4_id')).toHaveCount(0);
  await expect(page.getByText('IMMEDIATE / ALL PUBLIC PAGES')).toBeVisible();
  await expect(page.getByText('DIRECT GA4')).toBeVisible();
  await expect(page.getByText('RETIRED')).toBeVisible();

  await page.locator('[data-settings-tab="advanced"]').click();
  await expect(page.getByText('RAW SETTINGS',{exact:true})).toHaveCount(0);
  await expect(page.locator('[data-settings-raw]')).toHaveCount(0);
  await expect(page.locator('[data-settings-new-raw]')).toHaveCount(0);
  await expect(page.getByTestId('settings-security-host')).toBeVisible();
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
