import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const source=readFileSync('discadmin/ticketing-promos.js','utf8');
test('T23 Promos: 403, snapshots sin edición y módulo en shell a 390px',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  await page.route('**/discadmin/t23.html',r=>r.fulfill({contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'+
      '<main data-panel></main><script>window.csrf="test-csrf"</script></html>'}));
  await page.route('**/discadmin/ticketing-promos.js',r=>r.fulfill({
    contentType:'text/javascript',body:source}));
  let deny=true,created=0,deactivated=0;
  await page.route('**/api/ticketing/admin-promos.php*',r=>{
    if (deny) return r.fulfill({status:403,contentType:'application/json',
      body:'{"ok":false,"error":"FORBIDDEN"}'});
    const post=r.request().method()==='POST';
    if (post) {
      expect(r.request().headers()['x-csrf-token']).toBe('test-csrf');
      const payload=r.request().postDataJSON();
      expect(['create','deactivate']).toContain(payload.action);
      expect(payload).not.toHaveProperty('order_id');
      if (payload.action==='create') created++; else deactivated++;
    }
    return r.fulfill({contentType:'application/json',body:JSON.stringify(
      post?{ok:true}:{ok:true,rules:[{id:8,kind:'fixed',uses_count:1,
        max_uses:2,active:1,campaign_key:'noche'}]})});
  });
  await page.goto('https://www.brvtal.com.co/discadmin/t23.html');
  await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-promos.js');
    m.mount(document.querySelector('[data-panel]'));});
  await page.getByLabel('Evento ID').fill('6');
  await page.getByRole('button',{name:'Consultar reglas'}).click();
  await expect(page.getByRole('status')).toContainText('Sin permiso');
  deny=false;
  await page.getByRole('button',{name:'Consultar reglas'}).click();
  await expect(page.locator('[data-promo-rules]')).toContainText('1/2 usos');
  await expect(page.getByRole('button',{name:/editar/i})).toHaveCount(0);
  await page.getByRole('button',{name:'Desactivar'}).click();
  expect(deactivated).toBe(1);
  await expect(page.getByRole('status')).toContainText('ventas anteriores');
  expect(created).toBe(0);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth))
    .toBeLessThanOrEqual(391);
});
