import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const js=readFileSync('discadmin/ticketing-stages.js','utf8');
test('T22 mobile: stage editor refuses 403 and Save and Close remains in shell',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  await page.route('**/discadmin/t22.html',r=>r.fulfill({contentType:'text/html',body:
    '<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'+
    '<main><div data-ticketing-panel></div></main><script>window.csrf="synthetic-token";</script></html>'}));
  await page.route('**/discadmin/ticketing-stages.js',r=>r.fulfill({contentType:'text/javascript',body:js}));
  let denied=true,posts=0;
  await page.route('**/api/ticketing/admin-stages.php',r=>{
    if (denied) return r.fulfill({status:403,contentType:'application/json',body:'{"ok":false,"error":"FORBIDDEN"}'});
    if (r.request().method()==='POST') { posts++; expect(r.request().headers()['x-csrf-token']).toBe('synthetic-token'); }
    return r.fulfill({contentType:'application/json',body:'{"ok":true,"draft_only":true}'});
  });
  await page.goto('https://www.brvtal.com.co/discadmin/t22.html');
  await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-stages.js');
    m.mount(document.querySelector('[data-ticketing-panel]'),()=>document.body.dataset.closed='true');
  });
  await page.getByLabel('Evento ID').fill('6');
  await page.getByLabel('Tipo de boleta ID').fill('3');
  await page.getByLabel('Precio COP').fill('15000');
  await page.getByLabel('Cupo acumulado').fill('20');
  await page.getByLabel('Abre (Bogotá)').fill('2026-12-01T09:00');
  await page.getByLabel('Cierra (Bogotá)').fill('2026-12-02T20:00');
  await page.getByRole('button',{name:'Guardar',exact:true}).click();
  await expect(page.getByRole('status')).toContainText('No fue posible');
  denied=false;
  await page.getByRole('button',{name:'Guardar y cerrar'}).click();
  await expect.poll(()=>page.evaluate(()=>document.body.dataset.closed)).toBe('true');
  expect(posts).toBe(1);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
});
