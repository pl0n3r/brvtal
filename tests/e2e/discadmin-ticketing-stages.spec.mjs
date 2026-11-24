import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const js = readFileSync('discadmin/ticketing-stages.js','utf8');
for (const width of [390,1440]) {
  test('T22 editor activo: permisos, foco, guardado y etapa vigente a ' + width + ' px',async({page},testInfo)=>{
    await page.setViewportSize({width,height:844});
    await page.route('**/discadmin/t22.html',r=>r.fulfill({contentType:'text/html',body:
      '<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'+
      '<main><div data-ticketing-panel></div></main><script>window.csrf="synthetic-token";</script></html>'}));
    await page.route('**/discadmin/ticketing-stages.js',r=>r.fulfill({contentType:'text/javascript',body:js}));
    let denied = true, posts = 0, active = 'early_bird';
    await page.route('**/api/ticketing/admin-stages.php',r=>{
      const isPost = r.request().method() === 'POST';
      if (isPost && denied) return r.fulfill({status:403,contentType:'application/json',body:'{"ok":false,"error":"FORBIDDEN"}'});
      if (isPost) {
        posts++; expect(r.request().headers()['x-csrf-token']).toBe('synthetic-token');
        active = 'preventa';
        return r.fulfill({contentType:'application/json',body:'{"ok":true,"draft_only":false,"checkout_activation":true}'});
      }
      return r.fulfill({contentType:'application/json',body:JSON.stringify({
        ok:true,draft_only:false,checkout_activation:true,
        current_stage:{code:active,price_cop:active==='preventa'?20000:15000},
        stages:[{code:active,price_cop:active==='preventa'?20000:15000,cumulative_capacity:20}]
      })});
    });
    await page.goto('https://www.brvtal.com.co/discadmin/t22.html');
    await page.evaluate(async()=>{
      const m = await import('/discadmin/ticketing-stages.js');
      m.mount(document.querySelector('[data-ticketing-panel]'),
        ()=>document.body.dataset.closed='true',{eventId:6,typeId:3});
    });
    await expect(page.getByText('Etapa vigente: Early Bird')).toBeVisible();
    await testInfo.attach('before-' + width,{body:await page.screenshot(),contentType:'image/png'});
    await expect(page.getByText('Guardar cambia el precio de las próximas compras.',{exact:false})).toBeVisible();
    await page.getByLabel('Precio COP').fill('20000');
    await page.getByLabel('Cupo acumulado').fill('20');
    await page.getByLabel('Abre (Bogotá)').fill('2026-12-01T09:00');
    await page.getByLabel('Cierra (Bogotá)').fill('2026-12-02T20:00');
    await page.getByRole('button',{name:'Guardar etapas activas',exact:true}).focus();
    await expect(page.getByRole('button',{name:'Guardar etapas activas',exact:true})).toBeFocused();
    const focus = await page.getByRole('button',{name:'Guardar etapas activas',exact:true})
      .evaluate(el=>getComputedStyle(el).outlineStyle);
    expect(focus).not.toBe('none');
    await page.getByRole('button',{name:'Guardar etapas activas',exact:true}).click();
    await expect(page.getByRole('status')).toContainText('Sin permiso');
    denied = false;
    await page.getByRole('button',{name:'Guardar etapas activas',exact:true}).click();
    await expect(page.getByRole('status')).toContainText('Etapa activa guardada');
    await expect(page.getByText('Etapa vigente: Preventa')).toBeVisible();
    expect(posts).toBe(1);
    await testInfo.attach('after-' + width,{body:await page.screenshot(),contentType:'image/png'});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width+1);
    await page.getByRole('button',{name:'Guardar etapas activas y cerrar'}).click();
    await expect.poll(()=>page.evaluate(()=>document.body.dataset.closed)).toBe('true');
  });
}
