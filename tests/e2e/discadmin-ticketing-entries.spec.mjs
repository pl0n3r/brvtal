import {test,expect} from '@playwright/test';
import {readFileSync, mkdirSync, writeFileSync} from 'node:fs';
const code=readFileSync('discadmin/ticketing-entries.js','utf8');
const shellCode=readFileSync('discadmin/ticketing.js','utf8');
const css=readFileSync('discadmin/ticketing.css','utf8');
// Immutable original: main@589e712a788d9835054c692eae7af13d01b469aa, JS blob 4a523d208d4f6031e56f41322909b3f8fd2cfaed, CSS blob d3f693cf3216ef12b45c58297def23b856d9dc4b.
const BEFORE_ENTRIES_JS=[
  "/* T19: one module inside DISCADMIN, never a second shell or QR secret store. */\nexport async function mount(panel,i",
  "sCurrent=()=>true) {\n  const response = await fetch('/discadmin/ticketing-entries.php',\n    {credentials:'same-origi",
  "n',cache:'no-store',headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}});\n  if (!isCurrent()) return;\n  if (!response.ok) {pane",
  "l.textContent='No se pudo abrir Boletas ('+response.status+').';return;}\n  const markup=await response.text();\n  if ",
  "(!isCurrent()) return;\n  const doc = new DOMParser().parseFromString(markup,'text/html');\n  const root = doc.querySe",
  "lector('[data-ticketing-entries]');\n  if (!root) {panel.textContent='Respuesta inválida de Boletas.';return;}\n  pane",
  "l.replaceChildren(document.importNode(root,true));\n  const host=panel.querySelector('[data-ticketing-entries]'), sta",
  "tus=host.querySelector('[data-entry-status]');\n  const list=host.querySelector('[data-entry-list]'),detail=host.quer",
  "ySelector('[data-entry-detail]');\n  const qrBox=host.querySelector('[data-entry-qr]'),qrImage=host.querySelector('[d",
  "ata-entry-qr-image]');\n  const more=host.querySelector('[data-entry-more]'),csrf=host.dataset.ticketingCsrf;\n  let c",
  "ursor=Number.MAX_SAFE_INTEGER, busy=false, generation=0;\n  const active=()=>isCurrent() && host.isConnected && panel",
  ".contains(host);\n  const el=(tag,value)=>{const e=document.createElement(tag);e.textContent=String(value??'');return",
  " e;};\n  function clearQr(){qrBox.hidden=true;qrImage.replaceChildren();}\n  async function api(method,body,query='') ",
  "{\n    const res=await fetch('/api/ticketing/admin-entries.php'+query,{\n      method,credentials:'same-origin',cache:",
  "'no-store',\n      headers:method==='POST'?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},\n      ...(body",
  "?{body:JSON.stringify(body)}:{})\n    });\n    const value=await res.json();\n    if (!res.ok || !value.ok) throw Error",
  "(value.error||'UNAVAILABLE');\n    return value.data;\n  }\n  async function drawQr(qr) {\n    clearQr();\n    if (!windo",
  "w.QRCode) {\n      await new Promise((resolve,reject)=>{\n        const script=document.createElement('script');script",
  ".src='/discadmin/qrcode.min.js';\n        script.onload=resolve;script.onerror=reject;document.head.append(script);\n ",
  "     });\n    }\n    if (!active()) return;\n    new window.QRCode(qrImage,{text:qr,width:220,height:220,correctLevel:w",
  "indow.QRCode.CorrectLevel.M});\n    qrBox.hidden=false;qrBox.querySelector('[data-entry-close]').focus();\n  }\n  async",
  " function action(row,name) {\n    if (busy || !active()) return;\n    const input={entry_id:Number(row.id),action:name",
  "};\n    if (name==='preview') {\n      const qr=window.prompt('Pega el QR original de esta boleta para verificarlo y m",
  "ostrarlo.');\n      if (!qr) return;\n      input.qr=qr;\n    } else {\n      input.reason=window.prompt('Motivo: lost, ",
  "compromised o buyer_request');\n      if (!['lost','compromised','buyer_request'].includes(input.reason)) {\n        s",
  "tatus.textContent='Indica un motivo válido para auditar la acción.';return;\n      }\n      if (!window.confirm(name==",
  "='void'\n        ? 'ANULAR invalida la boleta permanentemente. ¿Confirmas?'\n        : 'REEMITIR invalida el QR anteri",
  "or. El nuevo QR se muestra una vez. ¿Confirmas?')) return;\n      input.idempotency_key=crypto.randomUUID();\n      if",
  " (name==='void') input.confirm='ANULAR';\n    }\n    busy=true;clearQr();status.textContent='Verificando operación…';\n",
  "    try {\n      const result=await api('POST',input);\n      if (!active()) return;\n      status.textContent=result.r",
  "eplayed?'Operación ya registrada; no se vuelve a emitir QR.':\n        name==='reissue'?'Credencial rotada y aviso en",
  " cola; guarda el nuevo QR ahora.':\n        name==='void'?'Boleta anulada con auditoría.':'QR original verificado, si",
  "n rotación.';\n      if (result.qr) await drawQr(result.qr);\n      if (name!=='preview') await refresh();\n    } catch",
  "(e) {if(active())status.textContent='Acción no completada: '+e.message+'. No se presume éxito.';}\n    finally {busy=",
  "false;}\n  }\n  let detailSeq=0;\n  async function showDetail(row) {\n    const id=Number(row.id),seq=++detailSeq;\n    i",
  "f (!Number.isSafeInteger(id) || id < 1 || !active()) return;\n    detail.replaceChildren(el('p','Consultando ficha pr",
  "ivada…'));\n    try {\n      const results=await api('GET',null,'?entry_id='+id);\n      if (!active() || seq!==detailS",
  "eq) return;\n      const entry=Array.isArray(results)?results[0]:null;\n      if (!entry || Number(entry.id)!==id) thr",
  "ow Error('ENTRY_NOT_FOUND');\n      const section=document.createElement('section');\n      section.setAttribute('role",
  "','region');\n      section.setAttribute('aria-label','Ficha de boleta');\n      section.append(el('h3','Ficha de bole",
  "ta #'+id));\n      for (const [label,value] of [\n        ['Evento',entry.event_id],['Pedido',entry.order_id],\n       ",
  " ['Comprador',entry.buyer_id],['Estado',entry.status],\n        ['Check-ins',entry.uses],['Estado de correo',entry.ma",
  "il_state||'Sin evidencia']]) {\n        section.append(el('p',label+': '+String(value??'Sin evidencia')));\n      }\n  ",
  "    const audit=Array.isArray(entry.audit_history)?entry.audit_history:[];\n      section.append(el('h4','Historial d",
  "e auditoría'));\n      if (!audit.length) section.append(el('p','Sin operaciones registradas.'));\n      for (const it",
  "em of audit.slice(0,20)) {\n        section.append(el('p',[item.outcome,item.reason_code||'check-in',\n          item.",
  "operator_admin_id,item.attempted_at_utc].filter(x=>x!==null&&x!==undefined).join(' · ')));\n      }\n      const mail=",
  "Array.isArray(entry.mail_history)?entry.mail_history:[];\n      section.append(el('h4','Historial de avisos'));\n     ",
  " if (!mail.length) section.append(el('p','Sin avisos registrados.'));\n      for (const item of mail.slice(0,20)) {\n ",
  "       section.append(el('p',[item.template_key,item.delivery_state,item.created_at]\n          .filter(x=>x!==null&&",
  "x!==undefined).join(' · ')));\n      }\n      detail.replaceChildren(section);\n    } catch {\n      if (active() && seq",
  "===detailSeq) detail.replaceChildren(\n        el('p','No se pudo consultar la ficha. No se presume estado.'));\n    }",
  "\n  }\n  function rows(rows,append) {\n    if (!append) list.replaceChildren();\n    if (!rows.length && !append) {list.",
  "textContent='No hay boletas para mostrar.';return;}\n    const table=list.querySelector('table')||document.createElem",
  "ent('table');\n    if (!table.isConnected) {\n      const head=table.createTHead().insertRow();\n      ['Boleta','Event",
  "o','Pedido','Estado','Usos','Correo','Acciones'].forEach(t=>{\n        const th=el('th',t);th.scope='col';head.append",
  "(th);\n      });list.append(table);\n    }\n    const body=table.tBodies[0]||table.createTBody();\n    for (const row of",
  " rows) {\n      const tr=body.insertRow();\n      [row.id,row.event_id,row.order_id,row.status,row.uses,row.mail_state",
  "||'Sin evidencia']\n        .forEach(t=>tr.append(el('td',t)));\n      const controls=el('td','');\n      const inspect",
  "=el('button','Ver ficha');\n      inspect.type='button';\n      inspect.addEventListener('click',()=>showDetail(row));",
  "\n      controls.append(inspect);\n      for (const [name,title,allowed] of [\n        ['preview','Ver QR',true],['reis",
  "sue','Reemitir',host.dataset.ticketingCanReissue==='1'],\n        ['void','Anular',host.dataset.ticketingCanVoid==='1",
  "']]) {\n        if (!allowed) continue;\n        const btn=el('button',title);btn.type='button';btn.addEventListener('",
  "click',()=>action(row,name));\n        controls.append(btn);\n      }\n      tr.append(controls);\n    }\n  }\n  async fun",
  "ction refresh(append=false) {\n    const seq=++generation;status.textContent='Consultando boletas…';\n    try {\n      ",
  "const data=await api('GET',null,'?before_id='+cursor);\n      if (!active()||seq!==generation) return;\n      rows(dat",
  "a,append);cursor=data.length?Number(data.at(-1).id):0;\n      more.hidden=data.length<25||cursor===0;\n      status.te",
  "xtContent='Consulta privada. Una boleta usada o anulada no se reactiva aquí.';\n    } catch{if(active())status.textCo",
  "ntent='No se pudieron consultar las boletas.';}\n  }\n  more.addEventListener('click',()=>refresh(true));\n  qrBox.quer",
  "ySelector('[data-entry-close]').addEventListener('click',clearQr);\n  qrBox.querySelector('[data-entry-download]').ad",
  "dEventListener('click',()=>{\n    const canvas=qrImage.querySelector('canvas'),img=qrImage.querySelector('img');\n    ",
  "const url=canvas?.toDataURL('image/png')||img?.src;\n    if (!url||!url.startsWith('data:image/png')) {status.textCon",
  "tent='No se generó imagen descargable.';return;}\n    const a=document.createElement('a');a.href=url;a.download='bole",
  "ta-qr.png';a.click();\n  });\n  await refresh();\n}\n"
].join('');
const BEFORE_ENTRIES_CSS=[
  "/* #1964 (A): Boletería inside the DISCADMIN shell. Groups → sections → one workspace.\n   Colors come from the shell",
  " tokens so light appearance keeps working. */\n.ticketing{margin-top:18px;max-width:1180px;color:var(--text,#f4f5f6)}",
  "\n.ticketing-status{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;margin:0;color:var(--muted,#7d848b);f",
  "ont-size:13px}\n.ticketing-status>span{display:inline-flex;align-items:center;gap:8px}\n.ticketing-status>span::before",
  "{content:\"\";width:8px;height:8px;border-radius:50%;background:currentColor;opacity:.7}\n.ticketing-link{min-height:44",
  "px;padding:0;background:none;border:0;color:var(--text,#f4f5f6);font:inherit;text-decoration:underline;text-underlin",
  "e-offset:3px;cursor:pointer}\n.ticketing-groups{display:flex;gap:28px;margin-top:16px;border-bottom:1px solid var(--l",
  "ine,#292d31);overflow-x:auto;scrollbar-width:none}\n.ticketing-groups button{flex:none;min-height:44px;padding:0;back",
  "ground:none;border:0;border-bottom:2px solid transparent;color:var(--muted,#7d848b);font:inherit;font-size:14px;font",
  "-weight:600;cursor:pointer;transition:color .15s ease-out,border-color .15s ease-out}\n.ticketing-groups button:hover",
  "{color:var(--text,#f4f5f6)}\n.ticketing-groups button[aria-pressed=\"true\"]{color:var(--text,#f4f5f6);border-bottom-co",
  "lor:var(--red,#ff2038)}\n.ticketing-tabs{display:flex;gap:8px;margin:14px 0 18px;overflow-x:auto;scrollbar-width:none",
  "}\n.ticketing-tabs button{flex:none;min-height:38px;padding:0 14px;background:transparent;color:var(--text,#f4f5f6);b",
  "order:1px solid var(--line,#292d31);font:inherit;font-size:13px;cursor:pointer;transition:background-color .15s ease",
  "-out,border-color .15s ease-out}\n.ticketing-tabs button:hover{border-color:var(--muted,#7d848b)}\n.ticketing-tabs but",
  "ton[aria-selected=\"true\"]{background:var(--text,#f4f5f6);color:var(--bg,#050505);border-color:var(--text,#f4f5f6);fo",
  "nt-weight:600}\n.ticketing-tabs button[hidden]{display:none}\n.ticketing :is(button,input,select,textarea,a):focus-vis",
  "ible{outline:3px solid var(--text,#fff);outline-offset:3px}\n.ticketing [role=\"tabpanel\"]{min-height:180px;padding:4p",
  "x 0 24px}\n.ticketing h2{font-size:22px;margin:0 0 6px}\n.ticketing h3{font-size:17px;margin:0}\n.ticketing-empty{margi",
  "n:0 0 18px;color:var(--muted,#7d848b);max-width:68ch}\n.ticketing button:disabled{opacity:.55;cursor:not-allowed}\n.ti",
  "cketing-error,.ticketing-denied{color:#ffb0b8}\n.ticketing-frame{display:block;width:100%;min-height:420px;border:1px",
  " solid var(--line,#292d31);background:var(--p,#0a0b0c)}\n\n/* Shared form system: grouped steps, labels above fields, ",
  "one primary action. */\n.tk-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(220px,100%),1fr));gap:",
  "16px;margin:0 0 20px}\n.tk-step{margin:0;padding:16px;min-width:0;border:1px solid var(--line,#292d31);background:var",
  "(--p,#0a0b0c);display:flex;flex-direction:column;gap:12px}\n.tk-step legend{padding:0 6px;color:var(--muted,#7d848b);",
  "font-size:12px;font-weight:600}\n.tk-field{display:flex;flex-direction:column;gap:6px;min-width:0;font-size:13px}\n.tk",
  "-field :is(input,select,textarea){box-sizing:border-box;width:100%;min-height:44px;padding:0 12px;background:var(--b",
  "g,#050505);color:var(--text,#f4f5f6);border:1px solid var(--line,#292d31);font:inherit;font-size:14px}\n.tk-field :is",
  "(input,select,textarea):hover{border-color:var(--muted,#7d848b)}\n.tk-field small{color:var(--muted,#7d848b);font-siz",
  "e:12px}\n.tk-field [aria-invalid=\"true\"]{border-color:#ff6b6b}\n.tk-footer{grid-column:1/-1;display:flex;flex-wrap:wra",
  "p;align-items:center;justify-content:space-between;gap:12px 16px}\n.tk-summary{margin:0;color:var(--text,#f4f5f6);fon",
  "t-size:14px;max-width:70ch;text-wrap:pretty}\n.tk-actions{display:flex;flex-wrap:wrap;gap:8px}\n.tk-button{min-height:",
  "44px;padding:0 18px;background:transparent;color:var(--text,#f4f5f6);border:1px solid var(--line,#292d31);font:inher",
  "it;font-weight:600;cursor:pointer;transition:border-color .15s ease-out,background-color .15s ease-out}\n.tk-button:h",
  "over{border-color:var(--muted,#7d848b)}\n.tk-button.is-primary{background:var(--red,#ff2038);border-color:var(--red,#",
  "ff2038);color:#090b0d}\n.tk-button.is-primary:hover{filter:brightness(1.08)}\n.tk-status{margin:0;min-height:20px;colo",
  "r:var(--muted,#7d848b);font-size:13px}\n.tk-status[data-tone=\"error\"]{color:#ffb0b8}\n.tk-status[data-tone=\"ok\"]{color",
  ":var(--green,#49d98a)}\n.tk-table-wrap{max-width:100%;overflow-x:auto}\n.tk-table{width:100%;border-collapse:collapse;",
  "font-size:14px;font-variant-numeric:tabular-nums}\n.tk-table th{padding:10px 12px;border-bottom:1px solid var(--line,",
  "#292d31);color:var(--muted,#7d848b);font-size:12px;font-weight:600;text-align:left;white-space:nowrap}\n.tk-table td{",
  "padding:12px;border-bottom:1px solid var(--line,#292d31);text-align:left;vertical-align:middle}\n.tk-table td:first-c",
  "hild{font-weight:600}\n.tk-badge{display:inline-flex;align-items:center;gap:6px;white-space:nowrap}\n.tk-badge::before",
  "{content:\"\";width:8px;height:8px;border-radius:50%;background:var(--muted,#7d848b)}\n.tk-badge.is-on::before{backgrou",
  "nd:var(--green,#49d98a)}\n.tk-empty-row td{color:var(--muted,#7d848b);font-weight:400}\n@media(prefers-reduced-motion:",
  "reduce){.ticketing-groups button,.ticketing-tabs button,.tk-button{transition:none}}\n@media(max-width:600px){\n  .tic",
  "keting-groups{gap:20px}\n  .tk-footer{flex-direction:column;align-items:stretch}\n  .tk-actions{display:grid;grid-temp",
  "late-columns:repeat(2,minmax(0,1fr))}\n  .tk-table thead{display:none}\n  .tk-table,.tk-table tbody,.tk-table tr,.tk-t",
  "able td{display:block;width:100%;box-sizing:border-box}\n  .tk-table tr{padding:10px 0;border-bottom:1px solid var(--",
  "line,#292d31)}\n  .tk-table td{padding:4px 0;border:0}\n  .tk-table td[data-label]::before{content:attr(data-label) \" ",
  "· \";color:var(--muted,#7d848b);font-weight:400}\n}\n\n/* T18: one responsive records workspace; tables scroll inside th",
  "e module, never the page. */\n.ticketing-records-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(1",
  "80px,100%),1fr));gap:12px;margin:16px 0}\n.ticketing-records-form label{display:grid;gap:5px;min-width:0}\n.ticketing-",
  "records-form :is(input,select,button){min-width:0;max-width:100%;box-sizing:border-box;padding:10px}\n[data-ticketing",
  "-records-results]{max-width:100%;overflow-x:auto}\n.ticketing-records-table{border-collapse:collapse;min-width:780px;",
  "width:100%}\n.ticketing-records-table :is(th,td){padding:10px;border-bottom:1px solid #34393e;text-align:left}\n.ticke",
  "ting-records-table tr[data-ticketing-anomaly]{outline:2px dashed #d29c37}\n[data-ticketing-records-detail]{margin-top",
  ":18px}\n.tk-promos-list-title{margin-top:28px}\n/* Wide screens: the four steps share the row exactly. Native pickers ",
  "follow the theme. */\n@media(min-width:1000px){.tk-form{grid-template-columns:repeat(4,minmax(0,1fr))}}\n.ticketing{co",
  "lor-scheme:dark}\nhtml[data-discadmin-appearance=\"light\"] .ticketing{color-scheme:light}\n"
].join('');
const pagePhp=readFileSync('discadmin/ticketing-entries.php','utf8');
const apiPhp=readFileSync('api/ticketing/admin-entries.php','utf8');
const fixture='<section data-ticketing-entries data-ticketing-csrf="fixture" data-ticketing-can-reissue="1" data-ticketing-can-void="1"><p data-entry-status role="status"></p><div data-entry-list></div><button data-entry-more hidden>Más</button><div data-entry-detail></div><div data-entry-qr hidden><div data-entry-qr-image></div><button data-entry-download>Descargar</button><button data-entry-close>Cerrar</button></div></section>';
const row={id:31,event_id:8,order_id:99,status:'issued',uses:0,mail_state:'queued'};
async function setup(page,reply,source=code,stylesheet=css) {
  await page.route('**/discadmin/t19-fixture.html',r=>r.fulfill({contentType:'text/html',body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1"><div id="panel"></div></html>'}));
  await page.route('**/discadmin/ticketing-entries.php',r=>r.fulfill({contentType:'text/html',body:fixture}));
  await page.route('**/discadmin/ticketing-entries.js',r=>r.fulfill({contentType:'text/javascript',body:source}));
  await page.route('**/api/ticketing/admin-entries.php*',reply);
  await page.goto('https://www.brvtal.com.co/discadmin/t19-fixture.html');
  await page.addStyleTag({content:stylesheet});
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

test('#1969: browser-generated before/after boletas at 390 and 1440',async({page},info)=>{
  const reply=r=>r.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:[row]})});
  for(const [version,script,styles] of [
    ['antes',BEFORE_ENTRIES_JS,BEFORE_ENTRIES_CSS],
    ['despues',code,css]]) {
    for(const width of [390,1440]) {
      await page.setViewportSize({width,height:width===390?844:900});
      await setup(page,reply,script,styles);
      await expect(page.locator('tbody tr')).toHaveCount(1);
      const image=await page.screenshot({fullPage:true});
      expect(image.byteLength).toBeGreaterThan(1500);
      await info.attach('boletas-'+version+'-'+width,{body:image,contentType:'image/png'});
      if(process.env.BRVTAL_VISUAL_EVIDENCE){
        mkdirSync(process.env.BRVTAL_VISUAL_EVIDENCE,{recursive:true});
        writeFileSync(process.env.BRVTAL_VISUAL_EVIDENCE+'/boletas-'+version+'-'+width+'.png',image);
      }
      if(version==='despues')
        expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    }
  }
});
