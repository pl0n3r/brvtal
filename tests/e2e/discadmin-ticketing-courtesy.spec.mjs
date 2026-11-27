import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';

const source=readFileSync('discadmin/ticketing-courtesy.js','utf8');
const css=readFileSync('discadmin/ticketing.css','utf8');
const legacyCatalog={ok:true,events:[{id:1,title:'Genesis',ticket_types:[{id:11,name:'General'}]}]};
const catalog=[{event_id:1,event_title:'Genesis',ticket_type_id:11,ticket_type_name:'General'}];
const invite=(id=5,status='issued',mail_state='queued')=>({id,event_id:1,event_title:'Genesis',ticket_type_id:11,ticket_type_name:'General',category:'artist',created_at_utc:'2026-11-27 00:00:00',status,invite_status:status,resend_requests:0,incident:false,mail_state});
const issued=(id=5,replayed=false)=>({ok:true,data:{invite_id:id,replayed,queued:true,sent:false,qr:null}});
const action=(id=5,status='queued')=>({ok:true,data:{invite_id:id,status,queued:status==='queued',sent:false}});

async function start(page,responder,{rows=[invite()],legacy=false,readResponder=null}={}) {
  await page.route('**/discadmin/t25-fixture.html',route=>route.fulfill({
    contentType:'text/html',body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="margin:16px;background:#050505;color:#f4f5f6;font:14px system-ui"><main class="ticketing"><div id="panel" data-ticketing-panel><section data-ticketing-records data-ticketing-csrf="synthetic-csrf"></section></div></main></body></html>'
  }));
  await page.route('**/discadmin/ticketing-courtesy.js',route=>route.fulfill({
    contentType:'text/javascript',body:legacy?execFileSync('git',['show','d4970015449c59661369f4a8b17f5bcf1b4a8ba0:discadmin/ticketing-courtesy.js'],{encoding:'utf8'}):source
  }));
  await page.route('**/api/ticketing/admin-courtesy.php',responder);
  await page.route('**/api/ticketing/admin-promos.php?catalog=1',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify(legacyCatalog)}));
  await page.route('**/api/ticketing/admin-courtesy-list.php*',route=>{
    if(readResponder)return readResponder(route);
    const ref=new URL(route.request().url()).searchParams.get('invite_id');
    const matches=ref?rows.filter(r=>String(r.id)===ref):rows;
    return reply(route,{ok:true,data:{rows:matches,catalog,totals:{issued:rows.length},has_more:false,next_cursor:null}});
  });
  page.removeAllListeners('dialog');page.on('dialog',dialog=>dialog.accept());
  await page.goto('https://www.brvtal.com.co/discadmin/t25-fixture.html');
  await page.addStyleTag({content:css});
  await page.evaluate(async()=>{
    (await import('/discadmin/ticketing-courtesy.js')).mount(document.querySelector('#panel'),()=>true,'synthetic-csrf');
  });
}
async function fill(page,email='guest01@example.test') {
  await page.getByLabel('Evento',{exact:true}).selectOption('1');
  await page.getByLabel('Boleta',{exact:true}).selectOption('11');
  await page.getByLabel('Correo del invitado').fill(email);
  await page.getByLabel('Motivo interno').fill('Cortesía sintética');
}
const reply=(route,data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});

test('Cortesías: emisión sin ingresos, QR únicamente por correo, CSRF',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const requests=[];
  await start(page,route=>{requests.push(route.request());return reply(route,issued());});
  await fill(page);
  await page.getByRole('button',{name:'Emitir cortesía'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('correo con el QR quedó en cola');
  await expect(page.getByLabel('Número de cortesía')).toHaveValue('5');
  await expect(page.locator('[data-courtesy-qr]')).toHaveCount(0);
  await expect(page.locator('[data-ticketing-courtesy]')).not.toContainText('BRVTAL:T1:');
  expect(requests).toHaveLength(1);
  const req=requests[0];
  expect(req.headers()['x-csrf-token']).toBe('synthetic-csrf');
  expect(req.postDataJSON().action).toBe('issue');
  expect(req.postDataJSON().recipient).toBe('guest01@example.test');
  expect(req.postDataJSON().idempotency_key).toMatch(/^[\w-]{20,64}$/);
});

test('Cortesías: revocar y reenviar realmente gestionan el QR',async({page})=>{
  const actions=[];
  await start(page,route=>{
    const op=route.request().postDataJSON().action;actions.push(op);
    return reply(route,op==='issue'?issued():op==='revoke'?action(5,'revoked'):action(5,'queued'));
  });
  await fill(page);
  await page.getByRole('button',{name:'Emitir cortesía'}).click();
  await page.getByRole('button',{name:'Reenviar correo con QR'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('El enlace anterior ya no es válido');
  await page.getByRole('button',{name:'Revocar'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('Cortesía revocada y cupo liberado');
  expect(actions).toEqual(['issue','resend','revoke']);
});

test('Cortesías: acceso denegado y referencia inválida fallan cerrado',async({page})=>{
  let count=0;
  await start(page,route=>{count++;return reply(route,{ok:false,error:'FORBIDDEN'},403);});
  await page.getByLabel('Número de cortesía').fill('8');
  await page.getByRole('button',{name:'Consultar invitaciones'}).click();
  await expect(page.getByText('No hay invitaciones para estos filtros.')).toBeVisible();
  expect(count).toBe(0);
  await page.getByLabel('Número de cortesía').fill('5');
  await page.getByRole('button',{name:'Consultar invitaciones'}).click();
  await page.getByRole('button',{name:'Revocar'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('No autorizada');
  expect(count).toBe(1);
});

test('Cortesías: replay de red reutiliza clave sin duplicar',async({page})=>{
  const requests=[];
  await start(page,route=>{
    requests.push(route.request().postDataJSON());
    if(requests.length===1)return route.abort('failed');
    return reply(route,issued(17,true));
  });
  await fill(page);
  const submit=page.getByRole('button',{name:'Emitir cortesía'});
  await submit.click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('Sin confirmación');
  await submit.click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('no se creó otra entrada');
  expect(requests).toHaveLength(2);
  expect(requests[1].idempotency_key).toBe(requests[0].idempotency_key);
  await page.getByLabel('Correo del invitado').fill('guest02@example.test');
  await submit.click();
  await expect.poll(()=>requests.length).toBe(3);
  expect(requests[2].idempotency_key).not.toBe(requests[1].idempotency_key);
});

test('Cortesías: montaje idempotente, controles accesibles',async({page})=>{
  await start(page,route=>reply(route,issued()));
  await page.evaluate(async()=>{
    (await import('/discadmin/ticketing-courtesy.js')).mount(document.querySelector('#panel'),()=>true,'synthetic-csrf');
  });
  await expect(page.locator('[data-ticketing-courtesy]')).toHaveCount(1);
  await expect(page.getByLabel('Correo del invitado')).toHaveAttribute('type','email');
  await expect(page.getByLabel('Evento',{exact:true})).toHaveJSProperty('tagName','SELECT');
  await expect(page.getByRole('button',{name:'Emitir cortesía'})).toBeEnabled();
  await page.getByRole('button',{name:'Emitir cortesía'}).focus();
  const focus=await page.getByRole('button',{name:'Emitir cortesía'}).evaluate(el=>document.activeElement===el);
  expect(focus).toBe(true);
});

test('Cortesías: contrato API sin proveedor directo ni secreto en UI',()=>{
  const endpoint=readFileSync('api/ticketing/admin-courtesy.php','utf8');
  const checkin=readFileSync('config/ticketing_checkin.php','utf8');
  const backend=readFileSync('config/ticketing_courtesy.php','utf8');
  expect(endpoint).toContain('brvtal_admin_require_csrf()');
  expect(checkin).toContain('brvtalCourtesyEnabled');
  expect(checkin).toContain("o.status='draft' AND o.total_cop=0");
  expect(backend).toContain('brvtalBuyerMailSeal');
  expect(backend).toContain('brvtal_ticket_mail_enqueue');
  expect(backend).not.toContain('COURTESY_DISABLED');
  expect(source).not.toContain('data.data.qr');
  expect(endpoint).not.toContain('curl_exec(');
});

test('Cortesías: detalle, evidencia de correo y estados incompatibles',async({page})=>{
  const requests=[];
  await start(page,route=>{requests.push(route.request());return reply(route,action());},
    {rows:[invite(5),invite(4,'used','accepted'),invite(3,'revoked','delivered')]});
  await expect(page.locator('[data-courtesy-invite="5"]')).toContainText('En cola');
  await expect(page.locator('[data-courtesy-invite="4"]')).toContainText('Aceptado por proveedor');
  await expect(page.locator('[data-courtesy-invite="3"]')).toContainText('Entregado');
  await expect(page.locator('[data-courtesy-invite="4"] button')).toHaveCount(1);
  await page.locator('[data-courtesy-invite="4"]').getByRole('button',{name:'Ver detalle'}).click();
  await expect(page.locator('[aria-live="polite"]')).toContainText('Cortesía #4');
  expect(requests).toHaveLength(0);
});
test('Cortesías: no escribe si la revalidación cambia el estado',async({page})=>{
  let writes=0;
  await start(page,route=>{writes++;return reply(route,action());},{readResponder:route=>{
    const reference=new URL(route.request().url()).searchParams.has('invite_id');
    return reply(route,{ok:true,data:{rows:[invite(5,reference?'used':'issued')],catalog,totals:{issued:1},has_more:false,next_cursor:null}});
  }});
  await page.getByRole('button',{name:'Revocar'}).click();
  await expect(page.locator('[data-courtesy-status]')).toContainText('La invitación cambió');
  expect(writes).toBe(0);
});
test('Cortesías: filtros y cursor estable sin duplicar el listado',async({page})=>{
  const queries=[];
  await start(page,route=>reply(route,issued()),{readResponder:route=>{
    const p=new URL(route.request().url()).searchParams;queries.push(Object.fromEntries(p));
    return reply(route,{ok:true,data:{rows:[invite(p.has('cursor')?4:5)],catalog,totals:{issued:2},
      has_more:!p.has('cursor'),next_cursor:p.has('cursor')?null:5}});
  }});
  await page.getByLabel('Filtrar evento').selectOption('1');
  await page.getByLabel('Filtrar estado').selectOption('issued');
  await page.getByRole('button',{name:'Consultar invitaciones'}).click();
  await expect(page.locator('[data-courtesy-invite="5"]')).toBeVisible();
  await page.getByRole('button',{name:'Cargar más'}).click();
  await expect(page.locator('[data-courtesy-invite]')).toHaveCount(2);
  expect(queries.at(-1)).toEqual({event_id:'1',status:'issued',cursor:'5'});
  await expect(page.getByRole('button',{name:'Cargar más'})).toBeHidden();
});
test('Cortesías: doble submit en vuelo solo envía una solicitud',async({page})=>{
  let writes=0,release;
  const gate=new Promise(resolve=>{release=resolve;});
  await start(page,async route=>{writes++;await gate;return reply(route,issued());});
  await fill(page);
  await page.getByRole('button',{name:'Emitir cortesía'}).click();
  await expect(page.getByRole('button',{name:'Emitir cortesía'})).toBeDisabled();
  await page.locator('[data-ticketing-courtesy] form').first().evaluate(form=>form.dispatchEvent(new Event('submit',{cancelable:true})));
  await expect.poll(()=>writes).toBe(1);release();
  await expect(page.locator('[data-courtesy-status]')).toContainText('quedó en cola');
  expect(writes).toBe(1);
});
for(const width of [390,1440]) {
  test('Cortesías capturas antes y después a '+width+'px',async({page},info)=>{
    await page.setViewportSize({width,height:900});
    await start(page,route=>reply(route,issued()),{legacy:true});
    await page.getByLabel('Evento',{exact:true}).selectOption('1');
    await expect(page.getByLabel('Boleta',{exact:true}).locator('option')).toHaveCount(2);
    await capture('antes');
    await start(page,route=>reply(route,issued()));
    await expect(page.locator('[data-courtesy-invite="5"]')).toBeVisible();
    await page.getByLabel('Evento',{exact:true}).selectOption('1');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await capture('despues');
    async function capture(stage){
      const png=await page.screenshot({fullPage:true});
      await info.attach('cortesias-'+stage+'-'+width+'.png',{body:png,contentType:'image/png'});
      // Exact synthetic render evidence for the assigned PR, without another workflow claim.
      if(process.env.GITHUB_HEAD_REF==='work/issue-2085'){
        const bytes=png.toString('base64').match(/.{1,1200}/g)||[];
        bytes.forEach((chunk,index)=>console.log('VISUAL-2085-'+stage+'-'+width+':'+index+':'+chunk));
      }
    }
  });
}
