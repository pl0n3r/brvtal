import {test, expect} from '@playwright/test';
import {readFileSync, writeFileSync, mkdirSync} from 'node:fs';
// A deterministic, versioned baseline avoids shallow-checkout failures in CI.
// #1964 (A): Cupones uses pickers, Spanish discount types and Bogotá time.
const source=readFileSync('discadmin/ticketing-promos.js','utf8');
const css=readFileSync('discadmin/ticketing.css','utf8');
// Frozen baseline CSS from main@16280dbe2cdb538ca8e0292638f893b426190b9a (blob e3d959d6ece80d9951800a0be5847f24fa41b9c2).
const BEFORE_PROMOS_CSS = [
  ".ticketing{margin-top:22px;max-width:1100px}\n.ticketing-notice{display:block;bor",
  "der:1px solid #444;background:#101214;padding:14px;color:#d6d8da;font-size:13px}",
  "\n.ticketing-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:20px 0}\n.ticketing-t",
  "abs button{background:#101214;color:#d2d2d2;border:1px solid #34393e;padding:12p",
  "x 15px;font-size:12px}\n.ticketing-tabs button[aria-selected=\"true\"]{background:#",
  "b20f29;color:#fff;border-color:#b20f29}\n.ticketing :is(button,input):focus-visib",
  "le{outline:3px solid #fff;outline-offset:3px}\n.ticketing [role=\"tabpanel\"]{backg",
  "round:#0a0b0c;border:1px solid #292d31;min-height:180px;padding:24px}\n.ticketing",
  " h2{font-size:21px;margin:0 0 14px}\n.ticketing-empty{color:#aeb4b9}\n.ticketing-s",
  "ettings{display:grid;gap:14px;border:1px solid #34393e;margin-top:22px;padding:1",
  "8px}\n.ticketing-settings label{display:flex;gap:10px;align-items:center}\n.ticket",
  "ing button:disabled{opacity:.55;cursor:not-allowed}\n.ticketing-error,.ticketing-",
  "denied{color:#ffb0b8}\n@media(max-width:600px){.ticketing-tabs{display:grid;grid-",
  "template-columns:repeat(2,minmax(0,1fr))}.ticketing-tabs button{min-width:0}.tic",
  "keting [role=\"tabpanel\"]{padding:16px}}\n\n/* T18: one responsive records workspac",
  "e; tables scroll inside the module, never the page. */\n.ticketing-records-form{d",
  "isplay:grid;grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));g",
  "ap:12px;margin:16px 0}\n.ticketing-records-form label{display:grid;gap:5px;min-wi",
  "dth:0}\n.ticketing-records-form :is(input,select,button){min-width:0;max-width:10",
  "0%;box-sizing:border-box;padding:10px}\n[data-ticketing-records-results]{max-widt",
  "h:100%;overflow-x:auto}\n.ticketing-records-table{border-collapse:collapse;min-wi",
  "dth:780px;width:100%}\n.ticketing-records-table :is(th,td){padding:10px;border-bo",
  "ttom:1px solid #34393e;text-align:left}\n.ticketing-records-table tr[data-ticketi",
  "ng-anomaly]{outline:2px dashed #d29c37}\n[data-ticketing-records-detail]{margin-t",
  "op:18px}\n"
].join('');
// Frozen source of main@16280dbe2cdb538ca8e0292638f893b426190b9a:discadmin/ticketing-promos.js
// (Git blob 454927d4e987c578d29a141d64c64b2d73da6bf8). The screenshot is a synthetic browser render,
// not a historical screenshot of production.
const BEFORE_PROMOS_SOURCE = [
  "/* T23: synthetic-only Promos in the existing DISCADMIN workspace. */\nexport function",
  " mount(panel) {\n  const root = document.createElement('section');\n  const title = doc",
  "ument.createElement('h3'); title.textContent = 'Promos · pruebas';\n  const explanatio",
  "n = document.createElement('p');\n  explanation.textContent = 'Configuración sintética",
  ": no activa descuentos, pagos ni ventas reales.';\n  const form = document.createEleme",
  "nt('form');\n  const controls = [\n    ['event_id','Evento ID','number'], ['ticket_type",
  "_id','Tipo ID (opcional)','number'],\n    ['code','Cupón (solo se muestra al crearlo)'",
  ",'text'],\n    ['kind','Modalidad: fixed, percent, group3, group5','text'],\n    ['valu",
  "e_cop','Valor COP o porcentaje entero','number'],\n    ['starts_at_utc','Inicio UTC','",
  "datetime-local'],\n    ['ends_at_utc','Fin UTC','datetime-local'],\n    ['max_uses','Cu",
  "po total','number'], ['buyer_limit','Cupo por comprador','number'],\n    ['campaign_ke",
  "y','Campaña (atribución)','text']\n  ];\n  const inputs = {};\n  for (const [key, label,",
  " type] of controls) {\n    const wrap = document.createElement('label'); wrap.textCont",
  "ent = label + ' ';\n    const input = document.createElement('input');\n    input.name ",
  "= key; input.type = type; input.style.maxWidth = '100%';\n    input.required = key!=='",
  "ticket_type_id' && key!=='campaign_key';\n    if (type==='number' && key!=='ticket_typ",
  "e_id') input.min = '1';\n    wrap.append(input); form.append(wrap); inputs[key] = inpu",
  "t;\n  }\n  const actions = document.createElement('div');\n  for (const [name,label] of ",
  "[['create','Crear cupón'],['refresh','Consultar reglas']]) {\n    const button = docum",
  "ent.createElement('button');\n    button.type = 'button'; button.dataset.promoAction =",
  " name;\n    button.textContent = label; actions.append(button);\n  }\n  const status = d",
  "ocument.createElement('p'); status.setAttribute('role','status');\n  status.setAttribu",
  "te('aria-live','polite');\n  const list = document.createElement('ul'); list.dataset.p",
  "romoRules = '';\n  let eventRevision = 0;\n  inputs.event_id.addEventListener('input', ",
  "() => {\n    eventRevision++;\n    list.replaceChildren();\n  });\n  const utc = value =>",
  " value.replace('T',' ') + (value.length===16?':00':'');\n  async function request(body",
  ", eventId = inputs.event_id.value) {\n    const response = await fetch('/api/ticketing",
  "/admin-promos.php'+\n      (body?'':'?event_id='+encodeURIComponent(eventId)), {\n     ",
  " method:body?'POST':'GET', credentials:'same-origin',cache:'no-store',\n      headers:",
  "body?{'Content-Type':'application/json',\n        'X-CSRF-Token':String(window.csrf ||",
  " '')}:{},\n      ...(body?{body:JSON.stringify(body)}:{})\n    });\n    const payload = ",
  "await response.json();\n    if (!response.ok || !payload.ok) throw new Error(\n      re",
  "sponse.status===403?'Sin permiso':response.status===423?\n      'Solo disponible en pr",
  "uebas sintéticas':'Operación no disponible');\n    return payload;\n  }\n  async functio",
  "n refresh() {\n    const eventId = inputs.event_id.value;\n    const revision = eventRe",
  "vision;\n    const data = await request(null, eventId);\n    if (inputs.event_id.value ",
  "!== eventId || eventRevision !== revision) return;\n    list.replaceChildren();\n    fo",
  "r (const rule of data.rules || []) {\n      const item = document.createElement('li');",
  "\n      item.textContent = 'Regla #'+rule.id+' · '+rule.kind+' · '+\n        rule.uses_",
  "count+'/'+rule.max_uses+' usos · '+\n        (Number(rule.active)===1?'Activa':'Inacti",
  "va')+' · '+rule.campaign_key;\n      if (Number(rule.active)===1) {\n        const disa",
  "ble = document.createElement('button');\n        disable.type = 'button'; disable.text",
  "Content = 'Desactivar';\n        disable.addEventListener('click', async () => {\n     ",
  "     if (inputs.event_id.value !== eventId || eventRevision !== revision) return;\n   ",
  "       disable.disabled=true;\n          try { await request({action:'deactivate',id:N",
  "umber(rule.id)});\n            if (inputs.event_id.value !== eventId || eventRevision ",
  "!== revision) return;\n            status.textContent='Desactivada sin cambiar ventas ",
  "anteriores.';\n            await refresh();\n          } catch (error) { status.textCon",
  "tent=error.message; disable.disabled=false; }\n        });\n        item.append(disable",
  ");\n      }\n      list.append(item);\n    }\n  }\n  actions.addEventListener('click', asy",
  "nc event => {\n    const action = event.target.closest('[data-promo-action]')?.dataset",
  ".promoAction;\n    if (!action) return;\n    const button = event.target; button.disabl",
  "ed=true; status.textContent='Procesando…';\n    try {\n      if (action==='create') {\n ",
  "       const id = key => Number(inputs[key].value);\n        const code=inputs.code.va",
  "lue;\n        await request({action:'create',event_id:id('event_id'),\n          ticket",
  "_type_id:inputs.ticket_type_id.value?id('ticket_type_id'):null,\n          code,kind:i",
  "nputs.kind.value,value_cop:id('value_cop'),\n          starts_at_utc:utc(inputs.starts",
  "_at_utc.value),\n          ends_at_utc:utc(inputs.ends_at_utc.value),\n          max_us",
  "es:id('max_uses'),buyer_limit:id('buyer_limit'),\n          campaign_key:inputs.campai",
  "gn_key.value});\n        inputs.code.value=''; // never retain a plaintext coupon.\n   ",
  "     status.textContent='Cupón creado solo en ambiente sintético.';\n      } else { st",
  "atus.textContent='Reglas consultadas.'; }\n      await refresh();\n    } catch (error) ",
  "{ status.textContent=error.message; }\n    finally { button.disabled=false; }\n  });\n  ",
  "root.append(title,explanation,form,actions,status,list);\n  panel.replaceChildren(root",
  ");\n  return () => root.remove();\n}\n"
].join('');
const catalog={ok:true,events:[
  {id:6,title:'Genesis',slug:'genesis',date:'2026-12-04 21:00:00',ticket_types:[{id:11,name:'Preventa'},{id:12,name:'VIP'}]},
  {id:7,title:'Otra noche',date:null,ticket_types:[]}]};
async function open(page, routeApi, src=source, stylesheet=css){
  await page.route('**/discadmin/t23.html',r=>r.fulfill({contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'+
      '<body style="margin:0;background:#050505;color:#f4f5f6;font-family:Arial,sans-serif">'+
      '<section class="ticketing" style="padding:16px"><div data-panel role="tabpanel"></div></section>'+
      '<script>window.csrf="test-csrf"</script></body></html>'}));
  await page.route('**/discadmin/ticketing-promos.js',r=>r.fulfill({contentType:'text/javascript',body:src}));
  await page.route('**/api/ticketing/admin-promos.php*',routeApi);
  await page.goto('https://www.brvtal.com.co/discadmin/t23.html');
  await page.addStyleTag({content:stylesheet});
  await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-promos.js');
    m.mount(document.querySelector('[data-panel]'));});
}

test('Cupones: permisos, lista con estado en español y pausar sin editar (390px)',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  let deny=true,deactivated=0;
  await open(page,r=>{
    const url=new URL(r.request().url());
    if (url.searchParams.get('catalog')==='1') return r.fulfill({contentType:'application/json',body:JSON.stringify(catalog)});
    if (deny) return r.fulfill({status:403,contentType:'application/json',body:'{"ok":false,"error":"FORBIDDEN"}'});
    if (r.request().method()==='POST') {
      expect(r.request().headers()['x-csrf-token']).toBe('test-csrf');
      const payload=r.request().postDataJSON();
      expect(payload).toEqual({action:'deactivate',id:8});
      deactivated++;
      return r.fulfill({contentType:'application/json',body:'{"ok":true}'});
    }
    return r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,rules:[{id:8,kind:'fixed',value_cop:5000,
      starts_at_utc:'2026-11-24 23:00:00',ends_at_utc:'2026-11-28 04:59:00',uses_count:1,max_uses:2,active:deactivated?0:1,campaign_key:'noche'}]})});
  });
  const eventPicker=page.getByLabel('Evento',{exact:true});
  await expect(eventPicker.locator('option')).toHaveText(['Elige un evento','Genesis','Otra noche']);
  await eventPicker.selectOption('6');
  await expect(page.getByRole('status')).toContainText('No tienes permiso');
  await expect(page.getByLabel('Boleta',{exact:true}).locator('option')).toHaveText(['Todas las boletas','Preventa','VIP']);
  deny=false;
  await eventPicker.selectOption('7');
  await eventPicker.selectOption('6');
  const row=page.locator('.tk-table tbody tr').first();
  await expect(row).toContainText('$5.000');
  await expect(row).toContainText('1 / 2');
  await expect(row).toContainText('Activo');
  await expect(page.getByRole('button',{name:/editar/i})).toHaveCount(0);
  await page.getByRole('button',{name:'Pausar'}).click();
  await expect(page.getByRole('status')).toContainText('Las compras anteriores no cambian');
  await expect(row).toContainText('Pausado');
  expect(deactivated).toBe(1);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});

test('Cupones: crear con selectores, tipo en español y hora de Bogotá enviada en UTC',async({page})=>{
  await page.setViewportSize({width:1440,height:900});
  const posted=[];
  await open(page,r=>{
    const url=new URL(r.request().url());
    if (url.searchParams.get('catalog')==='1') return r.fulfill({contentType:'application/json',body:JSON.stringify(catalog)});
    if (r.request().method()==='POST') { posted.push(r.request().postDataJSON()); return r.fulfill({status:201,contentType:'application/json',body:'{"ok":true,"id":9}'}); }
    return r.fulfill({contentType:'application/json',body:'{"ok":true,"rules":[]}'});
  });
  await page.getByLabel('Evento',{exact:true}).selectOption('6');
  await expect(page.locator('.tk-empty-row')).toHaveText('Este evento todavía no tiene cupones.');
  await page.getByLabel('Boleta',{exact:true}).selectOption('11');
  await page.getByLabel('Código del cupón').fill('genesis10');
  await expect(page.getByLabel('Tipo de descuento').locator('option')).toHaveText(['Porcentaje','Valor fijo','3 boletas por un precio','5 boletas por un precio']);
  await page.getByLabel('Porcentaje de descuento').fill('10');
  await page.getByLabel('Desde (hora de Bogotá)').fill('2026-11-24T18:00');
  await page.getByLabel('Hasta (hora de Bogotá)').fill('2026-11-27T23:59');
  await page.getByLabel('Usos en total').fill('100');
  await page.getByLabel('Usos por comprador').fill('2');
  await expect(page.locator('[data-promo-summary]')).toContainText('10 % de descuento en Preventa');
  await expect(page.locator('[data-promo-summary]')).toContainText('100 usos, 2 por persona');
  await page.getByRole('button',{name:'Crear cupón'}).click();
  await expect(page.getByRole('status')).toHaveText('Cupón creado.');
  expect(posted).toEqual([{action:'create',event_id:6,ticket_type_id:11,code:'GENESIS10',kind:'percent',value_cop:10,
    starts_at_utc:'2026-11-24 23:00:00',ends_at_utc:'2026-11-28 04:59:00',max_uses:100,buyer_limit:2,campaign_key:''}]);
  await expect(page.getByLabel('Código del cupón')).toHaveValue('');
});

test('Cupones: un campo vacío se marca y no se envía nada',async({page})=>{
  let posts=0;
  await open(page,r=>{
    const url=new URL(r.request().url());
    if (url.searchParams.get('catalog')==='1') return r.fulfill({contentType:'application/json',body:JSON.stringify(catalog)});
    if (r.request().method()==='POST') posts++;
    return r.fulfill({contentType:'application/json',body:'{"ok":true,"rules":[]}'});
  });
  await page.getByRole('button',{name:'Crear cupón'}).click();
  await expect(page.getByRole('status')).toHaveText('Completa los campos marcados.');
  await expect(page.getByLabel('Evento',{exact:true})).toHaveAttribute('aria-invalid','true');
  expect(posts).toBe(0);
});

test('Cupones: capturas antes/después a 390 y 1440',async({page},testInfo)=>{
  const before=BEFORE_PROMOS_SOURCE;
  for (const [label,src] of [['antes',before],['despues',source]]) {
    for (const [width,height] of [[390,844],[1440,900]]) {
      await page.setViewportSize({width,height});
      await open(page,r=>{
        const url=new URL(r.request().url());
        if (url.searchParams.get('catalog')==='1') return r.fulfill({contentType:'application/json',body:JSON.stringify(catalog)});
        return r.fulfill({contentType:'application/json',body:'{"ok":true,"rules":[]}'});
      },src,label==='antes'?BEFORE_PROMOS_CSS:css);
      await expect(page.locator('[data-panel]')).toBeVisible();
      // A screenshot is not proof of a rendered interface: assert the mounted
      // controls before saving evidence for each viewport and version.
      await expect(page.locator('[data-panel]')).not.toBeEmpty();
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      const shot=await page.screenshot({fullPage:true});
      expect(shot.byteLength).toBeGreaterThan(2000);
      await testInfo.attach(`cupones-${label}-${width}`,{body:shot,contentType:'image/png'});
      if (process.env.BRVTAL_VISUAL_EVIDENCE) {
        mkdirSync(process.env.BRVTAL_VISUAL_EVIDENCE,{recursive:true});
        writeFileSync(`${process.env.BRVTAL_VISUAL_EVIDENCE}/cupones-${label}-${width}.png`,shot);
      }
      await page.unrouteAll({behavior:'ignoreErrors'});
    }
  }
});

test('T23: checkout sintético confirma desglose del servidor, no persiste cupón',async({page})=>{
  const checkoutSource=readFileSync('js/public-ticket-checkout.js','utf8');
  await page.setViewportSize({width:390,height:820});
  const url='https://www.brvtal.com.co/events/t23-fixture';
  await page.route('**/events/t23-fixture',route=>route.fulfill({
    contentType:'text/html',body:'<!doctype html><html lang="es">'+
      '<meta name="viewport" content="width=device-width,initial-scale=1">'+
      '<main class="event-ticket-checkout"><form id="brvtal-ticket-checkout" data-t23-synthetic="1">'+
      '<label>Tipo<select name="ticket_type_id"><option value="10" data-price="15000" '+
      'data-available="5">General</option></select></label>'+
      '<label>Cantidad<input type="number" name="quantity" value="2"></label>'+
      '<label>Método<select name="method"><option value="CARD">CARD</option></select></label>'+
      '<label>Invitación<input name="waitlist_token"></label>'+
      '<output id="ticket-checkout-total"></output>'+
      '<button type="submit">Continuar al pago</button></form>'+
      '<p id="ticket-checkout-status" role="status"></p></main></html>'
  }));
  await page.route('**/api/ticketing/waitlist.php*',route=>route.fulfill({
    contentType:'application/json',body:'{"ok":true,"types":[]}' }));
  await page.route('**/api/buyer/auth.php',route=>route.fulfill({
    contentType:'application/json',body:'{"ok":true,"authenticated":true,"csrf":"test"}'
  }));
  const posted=[];
  await page.route('**/events/t23-fixture**',route=>{
    if (route.request().method()!=='POST') return route.fallback();
    posted.push(route.request().postDataJSON());
    return route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,order_id:88,checkout:{environment:'mock',amount_in_cents:2500000},
      promo_breakdown:{subtotal_cop:30000,discount_cop:5000,total_cop:25000}
    })});
  });
  await page.goto(url);
  await page.addScriptTag({content:checkoutSource});
  const promo=page.getByLabel('Cupón promocional (solo pruebas)');
  await expect(promo).toBeVisible();
  await promo.fill('NOCHE2026A');
  await expect(page.locator('#ticket-checkout-total')).toContainText('pendiente de verificación');
  await page.getByRole('button',{name:'Continuar al pago'}).click();
  await expect(page.locator('#ticket-checkout-total')).toContainText('Descuento');
  await expect(page.locator('#ticket-checkout-total')).toContainText('25.000');
  await expect(page.locator('#ticket-checkout-status')).toContainText('pendiente');
  expect(posted).toHaveLength(1);
  expect(posted[0].promo_code).toBe('NOCHE2026A');
  expect(posted[0]).not.toHaveProperty('total_cop');
  expect(posted[0]).not.toHaveProperty('price');
  expect(await page.evaluate(()=>sessionStorage.getItem('brvtal-checkout:'+location.pathname)))
    .not.toContain('NOCHE2026A');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth))
    .toBeLessThanOrEqual(391);
});

test('Cupones: cambiar de evento ignora la respuesta tardía del anterior', async ({page}) => {
  let releaseSix;
  const pendingSix=new Promise(resolve => { releaseSix=resolve; });
  await open(page, async route => {
    const url=new URL(route.request().url());
    if (url.searchParams.get('catalog')==='1') return route.fulfill({contentType:'application/json',body:JSON.stringify(catalog)});
    const id=url.searchParams.get('event_id');
    if (id==='6') await pendingSix;
    await route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,rules:[{id:Number(id),kind:'fixed',value_cop:1000,uses_count:0,
        max_uses:1,active:0,campaign_key:'evento-'+id,starts_at_utc:'2026-11-24 23:00:00',ends_at_utc:'2026-11-25 23:00:00'}]})});
  });
  const requestSix=page.waitForRequest(req=>req.url().includes('event_id=6'));
  await page.getByLabel('Evento',{exact:true}).selectOption('6');
  await requestSix;
  await page.getByLabel('Evento',{exact:true}).selectOption('7');
  await expect(page.locator('.tk-table tbody')).toContainText('evento-7');
  releaseSix();
  await page.waitForTimeout(200);
  await expect(page.locator('.tk-table tbody')).not.toContainText('evento-6');
});

for (const [width,height] of [[390,844],[1440,900]]) {
  test('Promotores: alta, enlace, ventas y estado a '+width+'px',async({page},testInfo)=>{
    await page.setViewportSize({width,height});
    const posted=[];
    let created=false, active=true, enabled=true;
    const api=route=>{
      const req=route.request(), url=new URL(req.url());
      if (url.searchParams.get('catalog')==='1') return route.fulfill({json:catalog});
      if (req.method()==='POST') {
        expect(req.headers()['x-csrf-token']).toBe('test-csrf');
        const body=req.postDataJSON(); posted.push(body);
        if(body.action==='create_promoter') created=true;
        if(body.action==='set_promoter_active') active=body.active;
        if(body.action==='set_referral_active') enabled=body.active;
        return route.fulfill({json:{ok:true,id:1}});
      }
      if(url.searchParams.has('promoter_id')) return route.fulfill({json:{ok:true,
        promoter:{id:1,promoter_key:'PROMO001',active:Number(active)},
        codes:[{id:2,code:'GENESIS01',active:Number(enabled),paid_orders:2,gross_cop:100000,net_cop:75000}]}});
      if(url.searchParams.get('promoters')==='1') return route.fulfill({json:{ok:true,promoters:created?[{id:1,promoter_key:'PROMO001',active:Number(active)}]:[]}});
      return route.fulfill({json:{ok:true,rules:[]}});
    };
    // Exact prior module: the sole original-code change is mountPromoters(root).
    const baseline=source.split('/* #2204: a section of the existing promotions workspace')[0]
      .replace('  mountPromoters(root);\n','');
    await open(page,api,baseline,css);
    await expect(page.getByRole('heading',{name:'Nuevo cupón'})).toBeVisible();
    await expect(page.getByRole('heading',{name:'Promotores'})).toHaveCount(0);
    await testInfo.attach('promotores-antes-'+width,{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await page.unrouteAll({behavior:'ignoreErrors'});
    await open(page,api);
    await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{
      writeText:async value=>{window.__copied=value;}
    }}));
    await page.getByRole('button',{name:'Gestionar promotores'}).click();
    const section=page.getByRole('region',{name:'Promotores'});
    await expect(section.locator('[data-promoter-status]')).toHaveText('Todavía no hay promotores.');
    await section.getByLabel('Alias del promotor').fill('promo001');
    await section.getByLabel('Código de referencia').fill('genesis01');
    await section.getByRole('button',{name:'Crear promotor'}).click();
    await expect(section.getByText('Ventas netas: $75.000')).toBeVisible();
    await section.getByLabel('Evento para el enlace').selectOption('genesis');
    await section.getByRole('button',{name:'Copiar enlace de GENESIS01'}).click();
    expect(await page.evaluate(()=>window.__copied)).toBe('https://www.brvtal.com.co/events/genesis?ref=GENESIS01');
    await expect(section.locator('[data-promoter-status]')).toHaveText('Enlace copiado.');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await testInfo.attach('promotores-despues-'+width,{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await section.getByRole('button',{name:'Desactivar promotor'}).click();
    await expect(section.getByText('Promotor inactivo')).toBeVisible();
    await expect(section.getByRole('button',{name:'Copiar enlace de GENESIS01'})).toBeDisabled();
    await section.getByRole('button',{name:'Activar promotor'}).click();
    await expect(section.getByText('Promotor activo',{exact:true})).toBeVisible();
    await section.getByRole('button',{name:'Desactivar código GENESIS01'}).click();
    await expect(section.getByRole('button',{name:'Activar código GENESIS01'})).toBeVisible();
    await expect(section.getByText('Ventas netas: $75.000')).toBeVisible();
    expect(posted).toEqual([{action:'create_promoter',promoter_key:'PROMO001',code:'GENESIS01'},
      {action:'set_promoter_active',id:1,active:false},{action:'set_promoter_active',id:1,active:true},
      {action:'set_referral_active',id:1,code_id:2,active:false}]);
  });
}
