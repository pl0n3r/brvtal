import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const js=readFileSync('discadmin/ticketing-recovery.js','utf8');
const pageHtml='<html lang="es"><main data-recovery-root data-csrf="synthetic-csrf">'
 +'<form data-recovery-form><label>Pedido <input name="order_id" type="number"></label>'
 +'<label>Campaña <input name="campaign" value="noviembre"></label>'
 +'<button type="submit">Preparar simulación</button></form>'
 +'<div data-recovery-confirm hidden><button data-recovery-yes>Confirmar simulación</button>'
 +'<button data-recovery-no>Cancelar</button></div>'
 +'<p data-recovery-status role="status"></p><table><tbody data-recovery-rows></tbody></table></main>'
 +'<script type="module" src="/discadmin/ticketing-recovery.js"></script></html>';
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
