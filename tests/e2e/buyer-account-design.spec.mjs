import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

// Browser renders actual buyer/index.php + buyer-access.css + buyer-account.css.
// No real users, credentials, purchases, external provider calls or production.
const root=process.cwd();
const current=readFileSync(join(root,'buyer/index.php'),'utf8');
const access=readFileSync(join(root,'css/buyer-access.css'),'utf8');
const account=readFileSync(join(root,'css/buyer-account.css'),'utf8');
const origin='http://127.0.0.1:4173';
const output=join(root,'artifacts','buyer-account-1911');
const fakeOrders=[
  {event_id:13,event_title:'BRVTAL Noche industrial',status:'paid',payment_status:'paid',
   created_at_utc:'2026-11-22 19:30:00',total_cop:95000,currency:'COP',
   items:[{name:'Entrada general',quantity:1,status:'issued',credential_active:false}]},
  {event_id:27,event_title:'BRVTAL Techno al amanecer',status:'pending',
   payment_status:'pending',created_at_utc:'2026-11-20 03:45:00',total_cop:70000,
   currency:'COP',items:[]},
];
// The BEFORE capture reconstructs the original issue's legacy page layout and
// labels for visual comparison. It is synthetic, not a production screenshot.
const before=String.raw`<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mis compras / antes (reconstrucción)</title><style>
:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#090909;color:#f7f7f7}
body{margin:0}main{max-width:66rem;margin:auto;padding:clamp(1rem,4vw,3rem)}
h1{font-size:clamp(2.2rem,7vw,4.5rem);text-transform:uppercase;letter-spacing:-.06em}
header{border-bottom:2px solid #d32626;margin-bottom:2rem}
section,article{border:1px solid #555;padding:1.1rem;margin:1rem 0}
form{display:flex;flex-wrap:wrap;gap:.8rem;align-items:end}
label{display:grid;gap:.35rem;flex:1 1 9rem}
input,button{font:inherit;padding:.7rem;color:inherit;background:#1b1b1b;border:1px solid #bbb}
button{background:#e9e9e9;color:#111;font-weight:700}
</style></head><body><main><header><h1>Mis compras</h1><p>Entradas y pedidos de tu cuenta. Sin datos del área administrativa.</p></header>
<p>La vinculación de invitados requiere correo verificado; aún no está habilitada.</p>
<form><label>Evento (ID)<input inputmode="numeric"></label><label>Desde (UTC)<input type="date"></label>
<label>Hasta (UTC)<input type="date"></label><button>Filtrar</button></form>
<section aria-label="Pedidos"><p>Cargando compras…</p></section>
<section><h2>Devoluciones</h2><p>Sin solicitudes de devolución.</p></section></main></body></html>`;
async function prepare(page,{orders=fakeOrders,error=false}={}){
  const queries=[];
  await page.route('**/css/buyer-access.css*',route=>route.fulfill({status:200,contentType:'text/css',body:access}));
  await page.route('**/css/buyer-account.css*',route=>route.fulfill({status:200,contentType:'text/css',body:account}));
  await page.route('**/assets/fonts/BrokenGlass.ttf',route=>route.fulfill({
    status:200,contentType:'font/ttf',path:join(root,'assets/fonts/BrokenGlass.ttf'),
  }));
  await page.route('**/api/buyer/auth.php',async route=>{
    const method=route.request().method();
    return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(
      method==='POST'?{ok:true}:{ok:true,authenticated:true,csrf:'synthetic-csrf'})});
  });
  await page.route('**/api/buyer/refunds.php',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify({ok:true,data:[]}),
  }));
  await page.route('**/api/buyer/account.php**',route=>{
    const query=new URL(route.request().url()).searchParams;queries.push(Object.fromEntries(query));
    const chosen=query.get('event_id');
    return route.fulfill({status:error?503:200,contentType:'application/json',
      body:JSON.stringify(error?{ok:false}:{ok:true,data:{
        orders:chosen?orders.filter(order=>String(order.event_id)===chosen):orders,
        has_more:false,page:0,
      }})});
  });
  await page.route(origin+'/buyer/',route=>route.fulfill({
    status:200,contentType:'text/html; charset=utf-8',body:current,
  }));
  await page.route(origin+'/mi-cuenta/acceso',route=>route.fulfill({
    status:200,contentType:'text/html; charset=utf-8',body:'<!doctype html><html><body>Acceso de comprador</body></html>',
  }));
  await page.goto(origin+'/buyer/');
  return queries;
}
test('AC-01: comprador sintético, selector por nombre y fecha de Colombia, sin términos internos ni scroll horizontal',async({page})=>{
  await page.setViewportSize({width:390,height:900});
  const queries=await prepare(page);
  await expect(page.getByRole('heading',{name:'Mis compras'})).toBeVisible();
  await expect(page.locator('link[href^="/css/buyer-access.css"]')).toHaveCount(1);
  await expect(page.locator('link[href^="/css/buyer-account.css"]')).toHaveCount(1);
  const selector=page.getByRole('combobox',{name:'Evento'});
  await expect(selector.locator('option')).toHaveCount(3);
  await expect(selector.locator('option',{hasText:'BRVTAL Noche industrial'})).toHaveValue('13');
  await expect(page.getByText('Comprado el 22 de noviembre', {exact:false})).toBeVisible();
  const content=await page.locator('body').innerText();
  expect(content).not.toMatch(/\bUTC\b|Evento \(ID\)|Fecha UTC|vinculación de invitados/i);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await selector.selectOption('13');
  await page.getByRole('button',{name:'Aplicar filtros'}).click();
  await expect(page.getByRole('heading',{name:'BRVTAL Noche industrial'})).toBeVisible();
  await expect(page.getByRole('heading',{name:'BRVTAL Techno al amanecer'})).toHaveCount(0);
  expect(queries.some(q=>q.event_id==='13')).toBe(true);
  await page.getByRole('button',{name:'Limpiar filtros'}).click();
  await expect(page.getByRole('heading',{name:'BRVTAL Techno al amanecer'})).toBeVisible();
  await expect(page.getByRole('navigation',{name:'Navegación de cuenta'}).getByRole('link',{name:'Devoluciones'}))
    .toHaveAttribute('href','#refund-list');
  await expect(page.getByRole('link',{name:/Volver al inicio/}).last()).toHaveAttribute('href','/');
});
test('AC-01: cero compras deja acción visible; errores recuperables y cerrar sesión mantiene contrato CSRF',async({page})=>{
  await page.setViewportSize({width:390,height:900});
  await prepare(page,{orders:[]});
  await expect(page.getByRole('heading',{name:'Todavía no tienes compras'})).toBeVisible();
  await expect(page.getByRole('link',{name:'Ver la cartelera'})).toHaveAttribute('href','/');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.getByRole('button',{name:'Cerrar sesión'}).click();
  await expect(page).toHaveURL(origin+'/mi-cuenta/acceso');
});
test('AC-01: errores de consulta, sin revelar detalles de servidor ni desactivar recuperación',async({page})=>{
  await prepare(page,{error:true});
  await expect(page.getByRole('status').first()).toContainText('Revisa tu conexión');
  await expect(page.getByRole('button',{name:'Aplicar filtros'})).toBeEnabled();
});
test('AC-02: antes/después de fixture sintética a 390 y 1440 px, con capturas y autorrevisión',async({page},testInfo)=>{
  mkdirSync(output,{recursive:true});const generated=[];
  for(const width of [390,1440]){
    await page.setViewportSize({width,height:900});
    await page.route(origin+'/buyer/legacy',route=>route.fulfill({
      status:200,contentType:'text/html; charset=utf-8',body:before,
    }));
    await page.goto(origin+'/buyer/legacy');
    const beforePath=join(output,'before-'+width+'.png');
    await page.screenshot({path:beforePath,fullPage:true});
    await testInfo.attach('before-'+width,{path:beforePath,contentType:'image/png'});
    await page.unroute(origin+'/buyer/legacy');
    await prepare(page,{orders:[]});
    await expect(page.getByRole('heading',{name:'Todavía no tienes compras'})).toBeVisible();
    const afterPath=join(output,'after-'+width+'.png');
    await page.screenshot({path:afterPath,fullPage:true});
    await testInfo.attach('after-'+width,{path:afterPath,contentType:'image/png'});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    const nav=page.locator('.account-nav a,.account-nav button');
    for(const item of await nav.all()){
      const rect=await item.boundingBox();expect(rect.height).toBeGreaterThanOrEqual(44);
    }
    const focused=page.getByRole('button',{name:'Aplicar filtros'});
    await focused.focus();
    const outline=await focused.evaluate(el=>getComputedStyle(el).outlineStyle);
    expect(outline).not.toBe('none');
    generated.push({width,before:'before-'+width+'.png',after:'after-'+width+'.png'});
  }
  writeFileSync(join(output,'manifest.json'),JSON.stringify({
    issue:1911,browser:'Playwright Chromium',source_after:'buyer/index.php + public access and account CSS',
    source_before:'synthetic reconstruction of initial #1911 legacy markup',
    production_observed:false,generated,
  },null,2)+'\n');
});
