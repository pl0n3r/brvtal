import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const base = process.env.BRVTAL_FORMS_VISUAL_BASE || '';
const rootUrl = 'http://127.0.0.1:4173/discadmin/';
const id = '00000000000000000000000000000001';
const id2 = '00000000000000000000000000000002';
const attack = '<img src=x onerror="window.formsXss=true">';
const formRow = {form_key:'contact',latest_version:2,total:2,new_count:1};
const item = {entry_id:id,form_key:'contact',version_number:1,state:'received',created_at_utc:'2026-11-20 14:00:00'};
const read = (path,old=false) => old
  ? execFileSync('git',['show',base+':'+path],{encoding:'utf8',maxBuffer:4*1024*1024})
  : readFileSync(path,'utf8');

async function setup(page,{old=false,mode='ok',direct=false}={}) {
  const core = read('discadmin/index-core.php',old);
  const skin = core.match(/<style>([\s\S]*?)<\/style>/)[1];
  const runtime = core.match(/<script>\s*const API=([\s\S]*?)<\/script>/)[0]
    .replace(/<\?=[\s\S]*?\?>/g,'fixture').replace("let csrf='';let state=","window.csrf='';window.state=");
  const css = ['admin-modules','admin-appearance','admin-shell','admin-information-architecture','admin-design-system']
    .map(name=>read('discadmin/'+name+'.css',old)).join('\n');
  const calls = []; const control = {mode,calls};
  await page.route('**/*',async route=>{
    const url = new URL(route.request().url());
    if (url.origin !== new URL(rootUrl).origin) return route.abort();
    if (url.pathname === '/discadmin/') return route.fulfill({contentType:'text/html',body:
      '<!doctype html><html lang="es" data-discadmin-appearance="dark"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+skin+css+'</style><div id="app"></div><div id="modal"></div>'+runtime+
      '<script src="/discadmin/admin-modules.js"></script><script src="/discadmin/admin-shell.js"></script><script src="/discadmin/admin-information-architecture.js"></script><script>state.authed=true;state.section="pages";render();</script></html>'});
    if (url.pathname === '/discadmin/forms.php' && !old) return route.fulfill({contentType:'text/html',
      body:read('discadmin/forms.php').split('?>').at(-1)});
    if (url.pathname === '/api/admin-forms.php') {
      const params = Object.fromEntries(url.searchParams); calls.push({method:route.request().method(),params});
      if (control.mode==='denied') return route.fulfill({status:403,json:{ok:false}});
      if (control.mode==='network') return route.abort();
      if (control.mode==='error') return route.fulfill({status:503,json:{ok:false}});
      const data = params.view==='catalog' ? {items:control.mode==='empty'?[]:[formRow]} :
        params.view==='list' ? {items:[params.cursor?{...item,entry_id:id2,state:'redacted'}:item],total:2,
          has_more:!params.cursor,next_cursor:params.cursor?null:id} :
          {...item,entry_id:params.entry_id,state:params.entry_id===id2?'redacted':'received',
            notice_version:'privacy-fixture-v1',purpose_key:'contact_capture',retention_until_utc:'2027-11-20 14:00:00',
            fields:[{label:'Mensaje histórico',value:attack}],notifications:[{action_key:'contact_notify',state:'enqueued',created_at_utc:item.created_at_utc}]};
      return route.fulfill({json:{ok:true,data}});
    }
    if (url.pathname==='/discadmin/ticketing.php') return route.fulfill({contentType:'text/html',body:'<section data-admin-module="ticketing"></section>'});
    if (url.pathname.startsWith('/api/')) return route.fulfill({json:{ok:true,data:[]}});
    if (/^\/discadmin\/[a-z0-9-]+\.js$/.test(url.pathname)) {
      try {return route.fulfill({contentType:'application/javascript',body:read(url.pathname.slice(1),old)});}
      catch {return route.fulfill({status:404,body:''});}
    }
    return route.fulfill({status:404,body:''});
  });
  await page.goto(rootUrl+(direct?'?module=forms':''));
  await expect(page.locator('.nav[data-ia-tree="v3"]')).toBeAttached();
  return control;
}

async function siteMenu(page) {
  if (await page.locator('.admin-menu-toggle').isVisible()) await page.locator('.admin-menu-toggle').click();
  const toggle=page.locator('[data-ia-toggle="website"]');
  if (await toggle.getAttribute('aria-expanded')!=='true') await toggle.click();
}

for (const width of [390,1440]) {
  test('2068 Formularios: montaje, navegación, filtros y seguridad '+width,async({page},testInfo)=>{
    await page.setViewportSize({width,height:900});
    if (base) {
      const before=await page.context().newPage(); await before.setViewportSize({width,height:900});
      await setup(before,{old:true}); await siteMenu(before);
      await expect(before.locator('[data-ia-key="forms"]')).toHaveCount(0);
      mkdirSync('artifacts/forms-2068',{recursive:true});
      await before.screenshot({path:'artifacts/forms-2068/before-'+width+'.png',animations:'disabled'});
      await before.close();
    }
    const control=await setup(page); await siteMenu(page);
    await expect(page.locator('.nav > :not([hidden])')).toHaveCount(8);
    const nav=page.getByRole('button',{name:'FORMULARIOS',exact:true});
    await nav.focus(); await page.keyboard.press('Enter');
    const workspace=page.locator('[data-admin-module="forms"]');
    await expect(workspace.getByText('Respuestas: 2 · Nuevas: 1 · Versión 2')).toBeVisible();
    await expect(page).toHaveURL(/module=forms/);
    await workspace.getByRole('button',{name:'Ver respuestas de contact'}).click();
    await workspace.getByLabel('Estado').selectOption('received');
    await workspace.getByLabel('Desde').fill('2026-11-20');
    await workspace.getByRole('button',{name:'Consultar'}).click();
    await expect.poll(()=>control.calls.at(-1).params).toMatchObject({state:'received',from:'2026-11-20'});
    await workspace.getByRole('button',{name:'Ver respuesta '+id}).click();
    await expect(workspace.getByText(attack,{exact:true})).toBeVisible();
    await expect(workspace.getByText('privacy-fixture-v1',{exact:true})).toBeVisible();
    await expect(workspace.getByText('contact_capture',{exact:true})).toBeVisible();
    await expect(workspace.getByText('En cola (no acredita envío)',{exact:false})).toBeVisible();
    expect(await page.evaluate(()=>window.formsXss)).toBeUndefined();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    expect(page.url()).not.toContain(id);
    if(base) await page.screenshot({path:'artifacts/forms-2068/after-'+width+'.png',animations:'disabled'});
    await workspace.getByRole('button',{name:'Volver a respuestas'}).click();
    await workspace.getByRole('button',{name:'Siguiente'}).click();
    await workspace.getByRole('button',{name:'Ver respuesta '+id2}).click();
    await expect(workspace.getByText('Contenido anonimizado.')).toBeVisible();
    await expect(workspace.getByText(attack,{exact:true})).toHaveCount(0);
    await page.goBack(); await expect(page.locator('[data-admin-module="forms"]')).toHaveCount(0);
    await page.goForward(); await expect(workspace.getByText('Formularios disponibles')).toBeVisible();
    control.mode='network'; await workspace.getByRole('button',{name:'Ver respuestas de contact'}).click();
    await expect(workspace.getByRole('status')).toContainText('No fue posible');
    control.mode='error'; await workspace.getByRole('button',{name:'Reintentar'}).click();
    await expect(workspace.getByRole('status')).toContainText('No fue posible');
    control.mode='denied'; await workspace.getByRole('button',{name:'Reintentar'}).click();
    await expect(workspace.getByRole('status')).toContainText('No tienes permiso');
    expect(control.calls.every(call=>call.method==='GET')).toBe(true);
  });
}
test('2068 enlace directo: denegado y vacío sin datos',async({page})=>{
  await setup(page,{mode:'denied',direct:true});
  await expect(page.locator('[data-forms-status]')).toContainText('No tienes permiso');
  const other=await page.context().newPage(); await setup(other,{mode:'empty',direct:true});
  await expect(other.getByText('No hay resultados.')).toBeVisible(); await other.close();
});
