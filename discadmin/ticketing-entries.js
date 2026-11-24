/* T19: one module inside DISCADMIN, never a second shell or QR secret store. */
export async function mount(panel,isCurrent=()=>true) {
  const response = await fetch('/discadmin/ticketing-entries.php',
    {credentials:'same-origin',cache:'no-store',headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}});
  if (!isCurrent()) return;
  if (!response.ok) {panel.textContent='No se pudo abrir Boletas ('+response.status+').';return;}
  const markup=await response.text();
  if (!isCurrent()) return;
  const doc = new DOMParser().parseFromString(markup,'text/html');
  const root = doc.querySelector('[data-ticketing-entries]');
  if (!root) {panel.textContent='Respuesta inválida de Boletas.';return;}
  panel.replaceChildren(document.importNode(root,true));
  const host=panel.querySelector('[data-ticketing-entries]'), status=host.querySelector('[data-entry-status]');
  const list=host.querySelector('[data-entry-list]'),detail=host.querySelector('[data-entry-detail]');
  const qrBox=host.querySelector('[data-entry-qr]'),qrImage=host.querySelector('[data-entry-qr-image]');
  const more=host.querySelector('[data-entry-more]'),csrf=host.dataset.ticketingCsrf;
  let cursor=Number.MAX_SAFE_INTEGER, busy=false, dialogOpen=false, generation=0;
  const stateLabels = {
    issued:'Emitida',revoked:'Anulada',used:'Utilizada',pending:'Pendiente',
    sent:'Enviado',queued:'En cola',failed:'Fallido',delivered:'Entregado',
    no_delivery:'Sin envío',approved:'Aprobado',rejected:'Rechazado',
    accepted:'Aceptado',processed:'Procesado'
  };
  const displayState = value => value === null || value === undefined || value === ''
    ? 'Sin registro' : (stateLabels[String(value).toLowerCase()] || 'Estado no reconocido');
  const toBogota = value => {
    if (!value) return 'Sin fecha';
    const src=String(value).trim();
    const date = /^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/.test(src)
      ? new Date(src.replace(' ','T')+'Z') : new Date(src);
    if (!Number.isFinite(date.getTime())) return 'Fecha no disponible';
    return new Intl.DateTimeFormat('es-CO', {timeZone:'America/Bogota',
      year:'numeric',month:'short',day:'2-digit',
      ...(src.includes(':')?{hour:'2-digit',minute:'2-digit',hour12:false}:{})}).format(date);
  };
  const active=()=>isCurrent() && host.isConnected && panel.contains(host);
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
  // The browser's native <dialog> traps focus, supports Escape and never exposes
  // sensitive action values through window.prompt / window.confirm.
  function requestAction(row,name,trigger) {
    dialogOpen=true;
    return new Promise(resolve => {
      const modal=document.createElement('dialog');
      modal.className='tk-entry-dialog';
      modal.dataset.entryDialog=name;
      modal.setAttribute('aria-label',name==='preview'?'Verificar QR':name==='void'?'Anular boleta':'Reemitir boleta');
      const heading=el('h3',name==='preview'?'Verificar QR original':
        name==='void'?'Confirmar anulación':'Confirmar reemisión');
      const note=el('p',name==='preview'
        ? 'Pega el QR original. Solo se usa para esta verificación; no se guarda.'
        : name==='void'
          ? 'La anulación es irreversible y no cambia el pago. Elige el motivo antes de continuar.'
          : 'El QR anterior quedará inválido y el nuevo se mostrará una sola vez. Elige el motivo.');
      modal.append(heading,note);
      let field;
      if (name==='preview') {
        const label=el('label','QR original');
        label.className='tk-field';
        const area=document.createElement('textarea');
        area.dataset.entryQrInput='';
        area.setAttribute('aria-label','QR original');
        area.rows=3;area.autocomplete='off';
        label.append(area);modal.append(label);field=area;
      } else {
        const group=document.createElement('fieldset');
        group.append(el('legend','Motivo de la operación'));
        const reasons=[
          ['lost','Pérdida'],
          ['compromised','Posible uso indebido'],
          ['buyer_request','Solicitud del comprador']
        ];
        for (const [code,text] of reasons) {
          const label=document.createElement('label');
          const input=document.createElement('input');
          input.type='radio';input.name='entry-reason';input.value=code;
          label.append(input,document.createTextNode(text));group.append(label);
        }
        modal.append(group);field=group.querySelector('input');
      }
      const error=el('p','');error.setAttribute('role','alert');
      const actions=document.createElement('div');
      actions.className='tk-actions';
      const cancel=el('button','Cancelar');cancel.type='button';cancel.className='tk-button';
      const confirm=el('button',name==='preview'?'Verificar QR':name==='void'?'Anular boleta':'Reemitir boleta');
      confirm.type='button';confirm.className='tk-button is-primary';
      actions.append(cancel,confirm);
      modal.append(error,actions);
      let resolved=false;
      function close(result) {
        if(resolved)return;
        resolved=true;dialogOpen=false;modal.close();modal.remove();
        if(active())trigger?.focus();
        resolve(result);
      }
      modal.addEventListener('cancel',ev=>{ev.preventDefault();close(null);});
      cancel.addEventListener('click',()=>close(null));
      confirm.addEventListener('click',()=>{
        if (name==='preview') {
          const qr=field.value.trim();
          if (!qr) {error.textContent='Introduce el QR original.';field.focus();return;}
          close({qr});
        } else {
          const chosen=modal.querySelector('input[name="entry-reason"]:checked');
          if (!chosen) {error.textContent='Selecciona un motivo.';field.focus();return;}
          close({reason:chosen.value});
        }
      });
      host.append(modal);
      modal.showModal();
      field.focus();
    });
  }
  async function action(row,name,trigger) {
    if (busy || dialogOpen || !active()) return;
    const choice=await requestAction(row,name,trigger);
    if (!choice || !active()) return;
    const input={entry_id:Number(row.id),action:name,...choice};
    if (name!=='preview') {
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
  let detailSeq=0;
  async function showDetail(row) {
    const id=Number(row.id),seq=++detailSeq;
    if (!Number.isSafeInteger(id) || id < 1 || !active()) return;
    detail.replaceChildren(el('p','Consultando ficha privada…'));
    try {
      const results=await api('GET',null,'?entry_id='+id);
      if (!active() || seq!==detailSeq) return;
      const entry=Array.isArray(results)?results[0]:null;
      if (!entry || Number(entry.id)!==id) throw Error('ENTRY_NOT_FOUND');
      const section=document.createElement('section');
      section.setAttribute('role','region');
      section.setAttribute('aria-label','Ficha de boleta');
      section.append(el('h3','Ficha de boleta #'+id));
      for (const [label,value] of [
        ['Evento',entry.event_id],['Pedido',entry.order_id],
        ['Comprador',entry.buyer_id],['Estado',displayState(entry.status)],
        ['Check-ins',entry.uses],['Estado de correo',displayState(entry.mail_state)]]) {
        section.append(el('p',label+': '+String(value??'Sin evidencia')));
      }
      const audit=Array.isArray(entry.audit_history)?entry.audit_history:[];
      section.append(el('h4','Historial de auditoría'));
      if (!audit.length) section.append(el('p','Sin operaciones registradas.'));
      for (const item of audit.slice(0,20)) {
        const reason={
          credential_reissued_lost:'Reemitida por pérdida',
          credential_reissued_compromised:'Reemitida por seguridad',
          credential_reissued_buyer_request:'Reemitida por solicitud',
          entry_revoked_lost:'Anulada por pérdida',
          entry_revoked_compromised:'Anulada por seguridad',
          entry_revoked_buyer_request:'Anulada por solicitud'
        }[item.reason_code] || 'Operación registrada';
        section.append(el('p',[displayState(item.outcome),reason,
          'Operador '+String(item.operator_admin_id??'Sin registro'),
          toBogota(item.attempted_at_utc)].join(' · ')));
      }
      const mail=Array.isArray(entry.mail_history)?entry.mail_history:[];
      section.append(el('h4','Historial de avisos'));
      if (!mail.length) section.append(el('p','Sin avisos registrados.'));
      for (const item of mail.slice(0,20)) {
        section.append(el('p',[(item.template_key==='reissue'?'Reemisión':'Aviso'),
          displayState(item.delivery_state),toBogota(item.created_at)].join(' · ')));
      }
      detail.replaceChildren(section);
    } catch {
      if (active() && seq===detailSeq) detail.replaceChildren(
        el('p','No se pudo consultar la ficha. No se presume estado.'));
    }
  }
  function rows(rows,append) {
    if (!append) list.replaceChildren();
    if (!rows.length && !append) {list.textContent='No hay boletas para mostrar.';return;}
    const table=list.querySelector('table')||document.createElement('table');
    if (!table.isConnected) {
      table.className='tk-table';
      const head=table.createTHead().insertRow();
      ['Boleta','Evento','Pedido','Estado','Usos','Correo','Acciones'].forEach(t=>{
        const th=el('th',t);th.scope='col';head.append(th);
      });list.append(table);
    }
    const body=table.tBodies[0]||table.createTBody();
    for (const row of rows) {
      const tr=body.insertRow();
      const labels=['Boleta','Evento','Pedido','Estado','Usos','Correo'];
      [row.id,row.event_id,row.order_id,displayState(row.status),
        row.uses,displayState(row.mail_state)]
        .forEach((value,i)=>{
          const td=el('td',value);td.dataset.label=labels[i];tr.append(td);
        });
      const controls=el('td','');controls.dataset.label='Acciones';
      controls.className='tk-entry-actions';
      const inspect=el('button','Ver ficha');
      inspect.type='button';inspect.className='tk-button';
      inspect.addEventListener('click',()=>showDetail(row));
      controls.append(inspect);
      for (const [name,title,allowed] of [
        ['preview','Ver QR',true],['reissue','Reemitir',host.dataset.ticketingCanReissue==='1'],
        ['void','Anular',host.dataset.ticketingCanVoid==='1']]) {
        if (!allowed) continue;
        const btn=el('button',title);btn.type='button';btn.className='tk-button';
        btn.addEventListener('click',()=>action(row,name,btn));
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
