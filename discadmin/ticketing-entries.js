/* T19: one module inside DISCADMIN, never a second shell or QR secret store. */
export async function mount(panel) {
  const response = await fetch('/discadmin/ticketing-entries.php',
    {credentials:'same-origin',cache:'no-store',headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}});
  if (!response.ok) {panel.textContent='No se pudo abrir Boletas ('+response.status+').';return;}
  const doc = new DOMParser().parseFromString(await response.text(),'text/html');
  const root = doc.querySelector('[data-ticketing-entries]');
  if (!root) {panel.textContent='Respuesta inválida de Boletas.';return;}
  panel.replaceChildren(document.importNode(root,true));
  const host=panel.querySelector('[data-ticketing-entries]'), status=host.querySelector('[data-entry-status]');
  const list=host.querySelector('[data-entry-list]'),detail=host.querySelector('[data-entry-detail]');
  const qrBox=host.querySelector('[data-entry-qr]'),qrImage=host.querySelector('[data-entry-qr-image]');
  const more=host.querySelector('[data-entry-more]'),csrf=host.dataset.ticketingCsrf;
  let cursor=Number.MAX_SAFE_INTEGER, busy=false, generation=0;
  const active=()=>host.isConnected && panel.contains(host);
  const el=(tag,value)=>{const e=document.createElement(tag);e.textContent=String(value??'');return e;};
  function clearQr(){qrBox.hidden=true;qrImage.replaceChildren();}
  async function api(method,body,query='') {
    const res=await fetch('/api/ticketing/admin-entries.php'+query,{
      method,credentials:'same-origin',cache:'no-store',
      headers:method==='POST'?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{},
      ...(body?{body:JSON.stringify(body)}:{})
    });
    const value=await res.json();
    if (!res.ok || !value.ok) throw Error(value.error||'UNAVAILABLE');
    return value.data;
  }
  async function drawQr(qr) {
    clearQr();
    if (!window.QRCode) {
      await new Promise((resolve,reject)=>{
        const script=document.createElement('script');script.src='/discadmin/qrcode.min.js';
        script.onload=resolve;script.onerror=reject;document.head.append(script);
      });
    }
    if (!active()) return;
    new window.QRCode(qrImage,{text:qr,width:220,height:220,correctLevel:window.QRCode.CorrectLevel.M});
    qrBox.hidden=false;qrBox.querySelector('[data-entry-close]').focus();
  }
  async function action(row,name) {
    if (busy || !active()) return;
    const input={entry_id:Number(row.id),action:name};
    if (name==='preview') {
      const qr=window.prompt('Pega el QR original de esta boleta para verificarlo y mostrarlo.');
      if (!qr) return;
      input.qr=qr;
    } else {
      input.reason=window.prompt('Motivo: lost, compromised o buyer_request');
      if (!['lost','compromised','buyer_request'].includes(input.reason)) {
        status.textContent='Indica un motivo válido para auditar la acción.';return;
      }
      if (!window.confirm(name==='void'
        ? 'ANULAR invalida la boleta permanentemente. ¿Confirmas?'
        : 'REEMITIR invalida el QR anterior. El nuevo QR se muestra una vez. ¿Confirmas?')) return;
      input.idempotency_key=crypto.randomUUID();
      if (name==='void') input.confirm='ANULAR';
    }
    busy=true;clearQr();status.textContent='Verificando operación…';
    try {
      const result=await api('POST',input);
      if (!active()) return;
      status.textContent=result.replayed?'Operación ya registrada; no se vuelve a emitir QR.':
        name==='reissue'?'Credencial rotada y aviso en cola; guarda el nuevo QR ahora.':
        name==='void'?'Boleta anulada con auditoría.':'QR original verificado, sin rotación.';
      if (result.qr) await drawQr(result.qr);
      if (name!=='preview') await refresh();
    } catch(e) {if(active())status.textContent='Acción no completada: '+e.message+'. No se presume éxito.';}
    finally {busy=false;}
  }
  function rows(rows,append) {
    if (!append) list.replaceChildren();
    if (!rows.length && !append) {list.textContent='No hay boletas para mostrar.';return;}
    const table=list.querySelector('table')||document.createElement('table');
    if (!table.isConnected) {
      const head=table.createTHead().insertRow();
      ['Boleta','Evento','Pedido','Estado','Usos','Correo','Acciones'].forEach(t=>{
        const th=el('th',t);th.scope='col';head.append(th);
      });list.append(table);
    }
    const body=table.tBodies[0]||table.createTBody();
    for (const row of rows) {
      const tr=body.insertRow();
      [row.id,row.event_id,row.order_id,row.status,row.uses,row.mail_state||'Sin evidencia']
        .forEach(t=>tr.append(el('td',t)));
      const controls=el('td','');
      for (const [name,title,allowed] of [
        ['preview','Ver QR',true],['reissue','Reemitir',host.dataset.ticketingCanReissue==='1'],
        ['void','Anular',host.dataset.ticketingCanVoid==='1']]) {
        if (!allowed) continue;
        const btn=el('button',title);btn.type='button';btn.addEventListener('click',()=>action(row,name));
        controls.append(btn);
      }
      tr.append(controls);
    }
  }
  async function refresh(append=false) {
    const seq=++generation;status.textContent='Consultando boletas…';
    try {
      const data=await api('GET',null,'?before_id='+cursor);
      if (!active()||seq!==generation) return;
      rows(data,append);cursor=data.length?Number(data.at(-1).id):0;
      more.hidden=data.length<25||cursor===0;
      status.textContent='Consulta privada. Una boleta usada o anulada no se reactiva aquí.';
    } catch{if(active())status.textContent='No se pudieron consultar las boletas.';}
  }
  more.addEventListener('click',()=>refresh(true));
  qrBox.querySelector('[data-entry-close]').addEventListener('click',clearQr);
  qrBox.querySelector('[data-entry-download]').addEventListener('click',()=>{
    const canvas=qrImage.querySelector('canvas'),img=qrImage.querySelector('img');
    const url=canvas?.toDataURL('image/png')||img?.src;
    if (!url||!url.startsWith('data:image/png')) {status.textContent='No se generó imagen descargable.';return;}
    const a=document.createElement('a');a.href=url;a.download='boleta-qr.png';a.click();
  });
  await refresh();
}
