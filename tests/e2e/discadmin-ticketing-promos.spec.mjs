import {test, expect} from '@playwright/test';
import {readFileSync, writeFileSync, mkdirSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
// #1964 (A): Cupones uses pickers, Spanish discount types and Bogotá time.
const source=readFileSync('discadmin/ticketing-promos.js','utf8');
const css=readFileSync('discadmin/ticketing.css','utf8');
const catalog={ok:true,events:[
  {id:6,title:'Genesis',date:'2026-12-04 21:00:00',ticket_types:[{id:11,name:'Preventa'},{id:12,name:'VIP'}]},
  {id:7,title:'Otra noche',date:null,ticket_types:[]}]};
async function open(page, routeApi, src=source){
  await page.route('**/discadmin/t23.html',r=>r.fulfill({contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'+
      '<body style="margin:0;background:#050505;color:#f4f5f6;font-family:Arial,sans-serif">'+
      '<section class="ticketing" style="padding:16px"><div data-panel role="tabpanel"></div></section>'+
      '<script>window.csrf="test-csrf"</script></body></html>'}));
  await page.route('**/discadmin/ticketing-promos.js',r=>r.fulfill({contentType:'text/javascript',body:src}));
  await page.route('**/api/ticketing/admin-promos.php*',routeApi);
  await page.goto('https://www.brvtal.com.co/discadmin/t23.html');
  await page.addStyleTag({content:css});
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
  const before=execFileSync('git',['show','16280dbe2cdb538ca8e0292638f893b426190b9a:discadmin/ticketing-promos.js'],{encoding:'utf8'});
  for (const [label,src] of [['antes',before],['despues',source]]) {
    for (const [width,height] of [[390,844],[1440,900]]) {
      await page.setViewportSize({width,height});
      await open(page,r=>{
        const url=new URL(r.request().url());
        if (url.searchParams.get('catalog')==='1') return r.fulfill({contentType:'application/json',body:JSON.stringify(catalog)});
        return r.fulfill({contentType:'application/json',body:'{"ok":true,"rules":[]}'});
      },src);
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
