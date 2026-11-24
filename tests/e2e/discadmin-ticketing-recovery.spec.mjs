import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const js=readFileSync('discadmin/ticketing-recovery.js','utf8');
const recoveryPhp=readFileSync('discadmin/ticketing-recovery.php','utf8');
const pageHtml=recoveryPhp.slice(recoveryPhp.indexOf('<!doctype html>'))
  .replace(/<\?=[\s\S]*?\?>/g,'synthetic-csrf');
test('T29 390: preview requires explicit confirmation; no providers, no tracking',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const calls=[];
  await page.route('**/t29-synthetic.html',r=>r.fulfill({contentType:'text/html',body:pageHtml}));
  await page.route('**/discadmin/ticketing-recovery.js',r=>r.fulfill({contentType:'text/javascript',body:js}));
  await page.route('**/api/ticketing/admin-recovery.php',r=>{
    if(r.request().method()==='POST') calls.push(r.request().postDataJSON());
    r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:r.request().method()==='GET'
      ? [{event_id:6,campaign_key:'noviembre',stage:'cart',previews:1}]
      : {state:'suppressed',reason:'consent_unavailable',sent:false}})});
  });
  await page.goto('https://www.brvtal.com.co/t29-synthetic.html');
  await expect(page.locator('[data-recovery-rows] tr')).toHaveCount(1);
  await page.locator('[name=order_id]').fill('91');
  await page.locator('[name=campaign]').fill('noviembre');
  await page.locator('[data-recovery-form] button[type="submit"]').click();
  await page.locator('[data-recovery-no]').click();
  expect(calls).toHaveLength(0);
  await page.locator('[data-recovery-form] button[type="submit"]').click();
  await page.locator('[data-recovery-yes]').focus();
  await page.keyboard.press('Enter');
  await expect(page.getByRole('status')).toContainText('omitida');
  expect(calls).toHaveLength(1);
  expect(calls[0].campaign).toBe('noviembre');
});
test('T29 1440: disabled production and single shell integration',async({page})=>{
  await page.setViewportSize({width:1440,height:900});
  await page.route('**/t29-synthetic.html',r=>r.fulfill({contentType:'text/html',body:pageHtml}));
  await page.route('**/discadmin/ticketing-recovery.js',r=>r.fulfill({contentType:'text/javascript',body:js}));
  await page.route('**/api/ticketing/admin-recovery.php',r=>r.fulfill({status:423,
    contentType:'application/json',body:'{"ok":false,"error":"SYNTHETIC_ONLY"}'}));
  await page.goto('https://www.brvtal.com.co/t29-synthetic.html');
  await expect(page.getByRole('status')).toContainText('Sin habilitación');
  // #1964: Recuperación is a Marketing section of the same shell, not a block above it.
  const shell=readFileSync('discadmin/ticketing.php','utf8');
  const shellJs=readFileSync('discadmin/ticketing.js','utf8');
  expect(shell).toContain("'recovery'=>['Recuperación'");
  expect(shellJs).toContain('/discadmin/ticketing-recovery.php');
  const php=readFileSync('config/ticketing_recovery.php','utf8');
  expect(php).toContain('brvtalRecoverySynthetic');
  expect(php).not.toContain('brvtal_ticket_mail_enqueue(');
  expect(php).not.toContain('UPDATE ticket_orders');
  expect(php).not.toContain('ticket_inventory_hold(');
});

for (const width of [390,1440]) {
  test('AC-01 Recuperación antes/después, tokens y tabla a '+width+' px',async({page},testInfo)=>{
    await page.setViewportSize({width,height:900});
    const before='<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
      +'<body><h2>Recuperación de selección</h2><p>Panel de simulación de marketing.</p>'
      +'<form><label>Pedido sintético <input type="number"></label>'
      +'<label>Campaña <input value="checkout"></label>'
      +'<button>Preparar simulación</button></form><table>'
      +'<tr><th>Evento</th><th>Campaña</th><th>Etapa</th><th>Vistas previas elegibles</th></tr>'
      +'<tr><td>6</td><td>noviembre</td><td>cart</td><td>1</td></tr></table></body></html>';
    await page.route('**/discadmin/recovery-before.html',r=>r.fulfill({
      contentType:'text/html',body:before}));
    await page.goto('https://www.brvtal.com.co/discadmin/recovery-before.html');
    await testInfo.attach('recuperacion-antes-'+width+'.png',{
      body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await page.route('**/t29-synthetic.html',r=>r.fulfill({
      contentType:'text/html',body:pageHtml}));
    await page.route('**/discadmin/ticketing-recovery.js',r=>r.fulfill({
      contentType:'text/javascript',body:js}));
    await page.route('**/api/ticketing/admin-recovery.php',r=>r.fulfill({
      contentType:'application/json',body:JSON.stringify({ok:true,data:[
        {event_id:6,campaign_key:'noviembre',stage:'cart',previews:1}]})}));
    await page.goto('https://www.brvtal.com.co/t29-synthetic.html');
    await expect(page.locator('[data-recovery-rows] tr')).toHaveCount(1);
    await expect(page.locator('table.tk-table')).toBeVisible();
    await expect(page.locator('[data-recovery-root]')).not.toContainText(/sintético|simulación|pruebas|UTC/i);
    const theme=await page.evaluate(()=>({
      bg:getComputedStyle(document.documentElement).getPropertyValue('--bg').trim(),
      text:getComputedStyle(document.documentElement).getPropertyValue('--text').trim(),
      line:getComputedStyle(document.documentElement).getPropertyValue('--line').trim(),
      scroll:document.documentElement.scrollWidth
    }));
    expect(theme).toMatchObject({bg:'#050505',text:'#f4f5f6',line:'#292d31'});
    expect(theme.scroll).toBeLessThanOrEqual(width);
    await testInfo.attach('recuperacion-despues-'+width+'.png',{
      body:await page.screenshot({fullPage:true}),contentType:'image/png'});
  });
}
