import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
const code=readFileSync('discadmin/ticketing-entries.js','utf8');
const shellCode=readFileSync('discadmin/ticketing.js','utf8');
const pagePhp=readFileSync('discadmin/ticketing-entries.php','utf8');
const apiPhp=readFileSync('api/ticketing/admin-entries.php','utf8');
const fixture='<section data-ticketing-entries data-ticketing-csrf="fixture" data-ticketing-can-reissue="1" data-ticketing-can-void="1"><p data-entry-status role="status"></p><div data-entry-list></div><button data-entry-more hidden>Más</button><div data-entry-detail></div><div data-entry-qr hidden><div data-entry-qr-image></div><button data-entry-download>Descargar</button><button data-entry-close>Cerrar</button></div></section>';
const row={id:31,event_id:8,order_id:99,status:'issued',uses:0,mail_state:'queued'};
async function setup(page,reply) {
  await page.route('**/discadmin/t19-fixture.html',r=>r.fulfill({contentType:'text/html',body:'<html lang="es"><div id="panel"></div></html>'}));
  await page.route('**/discadmin/ticketing-entries.php',r=>r.fulfill({contentType:'text/html',body:fixture}));
  await page.route('**/discadmin/ticketing-entries.js',r=>r.fulfill({contentType:'text/javascript',body:code}));
  await page.route('**/api/ticketing/admin-entries.php*',reply);
  await page.goto('https://www.brvtal.com.co/discadmin/t19-fixture.html');
  await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-entries.js');await m.mount(document.querySelector('#panel'));});
}
test('T19 boletas responsive, RBAC visual y consulta privada',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  await setup(page,r=>r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[row]})}));
  await expect(page.locator('tbody tr')).toHaveCount(1);
  await expect(page.getByRole('button',{name:'Reemitir'})).toBeVisible();
  await expect(page.locator('[data-entry-status]')).toContainText('Consulta privada');
  await expect(page.locator('.tk-table tbody tr')).toContainText('Emitida');
  await expect(page.locator('.tk-table tbody tr')).toContainText('En cola');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
});
test('T19 doble clic no repite acción, antifraude y confirmación',async({page})=>{
  let posts=0,release;
  await setup(page,r=>{
    if (r.request().method()==='GET') return r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[row]})});
    posts++;
    return new Promise(resolve=>{release=()=>resolve(r.fulfill({contentType:'application/json',body:'{"ok":true,"data":{"qr":null,"replayed":true}}'}));});
  });
  page.on('dialog',()=>{throw Error('Browser-native prompts are forbidden');});
  await page.getByRole('button',{name:'Reemitir',exact:true}).dblclick();
  await expect(page.locator('dialog[data-entry-dialog="reissue"]')).toBeVisible();
  await page.getByRole('radio',{name:'Pérdida'}).check();
  await page.getByRole('button',{name:'Reemitir boleta',exact:true}).click();
  await expect.poll(()=>posts).toBe(1);
  release();
  await expect(page.locator('[data-entry-status]')).toContainText('Consulta privada');
});
test('T19 servidor exige CSRF, rol fuerte, firma de pago y no reconstituye secreto SHA',()=>{
  expect(pagePhp).toContain('brvtal_admin_require()');
  expect(apiPhp).toContain('brvtal_admin_require_csrf()');
  expect(apiPhp).toContain("brvtalTicketingCan($role,$capability)");
  expect(apiPhp).toContain("w.signed_status='APPROVED'");
  expect(apiPhp).toContain("hash_equals($hash,$parsed['hash'])");
  expect(apiPhp).toContain('IDEMPOTENCY_CONFLICT');
  expect(apiPhp).not.toContain('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED');
  expect(apiPhp).not.toContain("SET status='paid'");
});

test('T19 ficha muestra trazabilidad sin exponer QR ni correo privado',async({page})=>{
  await setup(page,r=>{
    const details=r.request().url().includes('entry_id=31');
    return r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,
      data:[details?{...row,buyer_id:7,mail_state:'sent',
        audit_history:[{outcome:'rejected',reason_code:'credential_reissued_lost',
          operator_admin_id:3,attempted_at_utc:'2026-11-21'}],
        mail_history:[{template_key:'reissue',delivery_state:'sent',
          created_at:'2026-11-21'}]}:row]})});
  });
  await page.getByRole('button',{name:'Ver ficha'}).click();
  const detail=page.getByRole('region',{name:'Ficha de boleta'});
  await expect(detail).toContainText('Comprador: 7');
  await expect(detail).toContainText('Reemitida por pérdida');
  await expect(detail).toContainText('Reemisión');
  await expect(detail).toContainText('Enviado');
  await expect(detail).not.toContainText('@example');
  await expect(detail).not.toContainText('BRVTAL:T1:');
});

test('T19 pestaña Boletas integrada en el shell DISCADMIN, sin segundo administrador',async({page})=>{
  await page.route('**/discadmin/t19-shell-fixture.html',r=>r.fulfill({
    contentType:'text/html',
    body:'<html lang="es"><div id="admin-module-host"></div></html>'
  }));
  await page.route('**/discadmin/ticketing.php',r=>r.fulfill({
    contentType:'text/html',
    body:'<div data-admin-module="ticketing" data-ticketing-role="superadmin"><div role="tablist"><button id="dashboard-tab" role="tab" data-ticketing-tab="dashboard" aria-selected="true">Dashboard</button><button id="boletas-tab" role="tab" data-ticketing-tab="entries" aria-selected="false">Boletas</button></div><section data-ticketing-panel></section></div>'
  }));
  await page.route('**/discadmin/ticketing-entries.php',r=>r.fulfill({contentType:'text/html',body:fixture}));
  await page.route('**/discadmin/ticketing-entries.js',r=>r.fulfill({contentType:'text/javascript',body:code}));
  await page.route('**/api/ticketing/admin-entries.php*',r=>r.fulfill({
    contentType:'application/json',body:JSON.stringify({ok:true,data:[row]})
  }));
  await page.goto('https://www.brvtal.com.co/discadmin/t19-shell-fixture.html');
  await page.addScriptTag({content:shellCode});
  await page.evaluate(async()=>{
    window.state={authed:true,section:'ticketing'};
    await window.BRVTALTicketing.mount();
  });
  await page.getByRole('tab',{name:'Boletas'}).click();
  await expect(page.getByRole('tab',{name:'Boletas'})).toHaveAttribute('aria-selected','true');
  await expect(page.getByRole('button',{name:'Ver ficha'})).toBeVisible();
  await expect(page.getByRole('button',{name:'Reemitir'})).toBeVisible();
  await expect(page.locator('[data-ticketing-panel] tbody tr')).toHaveCount(1);
});

test('T19 respuesta tardía no reinyecta la ficha al abandonar la pestaña',async({page})=>{
  let markStarted,releaseResponse;
  const started=new Promise(resolve=>{markStarted=resolve;});
  const gate=new Promise(resolve=>{releaseResponse=resolve;});
  await page.route('**/discadmin/t19-fixture.html',r=>r.fulfill({
    contentType:'text/html',body:'<html lang="es"><div id="panel" data-active="entries"></div></html>'
  }));
  await page.route('**/discadmin/ticketing-entries.js',r=>r.fulfill({contentType:'text/javascript',body:code}));
  await page.route('**/discadmin/ticketing-entries.php',async r=>{
    markStarted();
    await gate;
    await r.fulfill({contentType:'text/html',body:fixture});
  });
  await page.goto('https://www.brvtal.com.co/discadmin/t19-fixture.html');
  await page.evaluate(async()=>{
    const mod=await import('/discadmin/ticketing-entries.js');
    const panel=document.querySelector('#panel');
    window.pendingEntries=mod.mount(panel,()=>panel.dataset.active==='entries');
  });
  await started;
  await page.evaluate(()=>{
    const panel=document.querySelector('#panel');
    panel.dataset.active='dashboard';
    panel.textContent='Dashboard actual';
  });
  releaseResponse();
  await page.evaluate(()=>window.pendingEntries);
  await expect(page.locator('#panel')).toHaveText('Dashboard actual');
  await expect(page.locator('[data-ticketing-entries]')).toHaveCount(0);
});

test('#1969: void dialog is cancelable, requires a reason and sends unchanged API codes',async({page})=>{
  const sends=[];
  await setup(page,r=>{
    if(r.request().method()==='GET')return r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[row]})});
    sends.push(r.request().postDataJSON());
    return r.fulfill({contentType:'application/json',body:'{"ok":true,"data":{"replayed":false,"qr":null}}'});
  });
  page.on('dialog',()=>{throw Error('No native confirm/prompt allowed');});
  await page.getByRole('button',{name:'Anular',exact:true}).click();
  await expect(page.getByRole('dialog',{name:'Anular boleta'})).toBeVisible();
  await page.getByRole('button',{name:'Anular boleta',exact:true}).click();
  await expect(page.getByRole('alert')).toContainText('Selecciona un motivo');
  expect(sends).toHaveLength(0);
  await page.getByRole('button',{name:'Cancelar'}).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  expect(sends).toHaveLength(0);
  await page.getByRole('button',{name:'Anular',exact:true}).click();
  await page.getByRole('radio',{name:'Solicitud del comprador'}).check();
  await page.getByRole('button',{name:'Anular boleta',exact:true}).click();
  await expect.poll(()=>sends.length).toBe(1);
  expect(sends[0]).toMatchObject({entry_id:31,action:'void',reason:'buyer_request',confirm:'ANULAR'});
  expect(sends[0].idempotency_key).toMatch(/^[\w-]{20,64}$/);
});
test('#1969: QR preview is in-shell and does not send when cancelled',async({page})=>{
  const sends=[];
  await setup(page,r=>{
    if(r.request().method()==='GET')return r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[row]})});
    sends.push(r.request().postDataJSON());
    return r.fulfill({contentType:'application/json',body:'{"ok":true,"data":{"qr":null}}'});
  });
  await page.getByRole('button',{name:'Ver QR'}).click();
  await expect(page.getByRole('dialog',{name:'Verificar QR'})).toBeVisible();
  await page.getByRole('button',{name:'Cancelar'}).click();
  expect(sends).toHaveLength(0);
  await page.getByRole('button',{name:'Ver QR'}).click();
  await page.getByLabel('QR original').fill('BRVTAL:T1:fixture:synthetic');
  await page.getByRole('button',{name:'Verificar QR',exact:true}).click();
  await expect.poll(()=>sends.length).toBe(1);
  expect(sends[0]).toMatchObject({entry_id:31,action:'preview',qr:'BRVTAL:T1:fixture:synthetic'});
});
