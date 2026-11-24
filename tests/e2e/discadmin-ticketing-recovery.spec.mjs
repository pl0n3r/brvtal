import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const js = readFileSync('discadmin/ticketing-recovery.js','utf8');
const php = readFileSync('discadmin/ticketing-recovery.php','utf8');
const html = php.slice(php.indexOf('<!doctype html>'))
  .replace(/<\?=[\s\S]*?\?>/g,'fixture-csrf');
const stub = (page, posts, mode = 'live') => {
  return Promise.all([
    page.route('**/recovery-fixture.html',r=>r.fulfill({contentType:'text/html',body:html})),
    page.route('**/discadmin/ticketing-recovery.js',r=>
      r.fulfill({contentType:'text/javascript',body:js})),
    page.route('**/api/ticketing/admin-recovery.php',r=>{
      if (r.request().method() === 'POST') posts.push({
        headers:r.request().headers(), data:r.request().postDataJSON()
      });
      r.fulfill({contentType:'application/json',body:JSON.stringify({
        ok:true, mode, mail_ready:mode!=='disabled',
        data:r.request().method()==='GET'
          ? [{event_id:6,queued:1,accepted:1,failed:0}]
          : {state:'queued',queued:true}
      })});
    })
  ]);
};
test('AC-02: 390px confirma, cancela y programa una sola petición con CSRF',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const posts=[]; await stub(page,posts);
  await page.goto('https://www.brvtal.com.co/recovery-fixture.html');
  await expect(page.locator('[data-recovery-rows] tr')).toHaveCount(1);
  await expect(page.getByRole('status')).toContainText('envío real');
  await page.locator('[name=order_id]').fill('91');
  await page.locator('[name=campaign]').fill('checkout');
  await page.getByRole('button',{name:'Preparar recordatorio'}).click();
  await page.getByRole('button',{name:'Cancelar'}).click();
  expect(posts).toHaveLength(0);
  await page.getByRole('button',{name:'Preparar recordatorio'}).click();
  await page.getByRole('button',{name:'Programar recordatorio'}).focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toContainText('programado una sola vez');
  expect(posts).toHaveLength(1);
  expect(posts[0].data).toMatchObject({order_id:91,campaign:'checkout'});
  expect(posts[0].headers['x-csrf-token']).toBe('fixture-csrf');
});
test('AC-02: modo deshabilitado impide preparar cualquier envío',async({page})=>{
  await page.setViewportSize({width:1440,height:900});
  const posts=[]; await stub(page,posts,'disabled');
  await page.goto('https://www.brvtal.com.co/recovery-fixture.html');
  await expect(page.getByRole('button',{name:'Preparar recordatorio'})).toBeDisabled();
  await expect(page.getByRole('status')).toContainText('correo deshabilitado');
  expect(posts).toHaveLength(0);
});
for (const width of [390,1440]) {
  test('AC-02 antes/después, resultados y responsive '+width+'px',async({page},info)=>{
    await page.setViewportSize({width,height:900});
    const before='<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
      +'<body><h2>Recuperación de compras</h2><p>Solo simulación</p><button>Vista previa</button></body></html>';
    await page.route('**/recovery-before.html',r=>
      r.fulfill({contentType:'text/html',body:before}));
    await page.goto('https://www.brvtal.com.co/recovery-before.html');
    await info.attach('recuperacion-antes-'+width+'.png',{
      body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    const posts=[]; await stub(page,posts);
    await page.goto('https://www.brvtal.com.co/recovery-fixture.html');
    await expect(page.locator('[data-recovery-rows] tr')).toHaveCount(1);
    await expect(page.locator('.tk-table')).toBeVisible();
    await expect(page.locator('[data-recovery-root]')).not.toContainText(/simulación|sintético|UTC/i);
    const ui=await page.evaluate(()=>({
      width:document.documentElement.scrollWidth,
      minButton:document.querySelector('[data-recovery-form] button').getBoundingClientRect().height,
      bg:getComputedStyle(document.documentElement).getPropertyValue('--bg').trim()
    }));
    expect(ui.width).toBeLessThanOrEqual(width);
    expect(ui.minButton).toBeGreaterThanOrEqual(44);
    expect(ui.bg).toBe('#050505');
    await info.attach('recuperacion-despues-'+width+'.png',{
      body:await page.screenshot({fullPage:true}),contentType:'image/png'});
  });
}
