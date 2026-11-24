/* T21 sandbox staff component; mount inside the canonical DISCADMIN ticketing panel. */
export async function mount(panel, csrf = '') {
  if (!panel || typeof csrf !== 'string') throw Error('REFUND_ADMIN_CONTEXT');
  let csrfToken = csrf;
  const api='/api/ticketing/admin-refunds.php';
  const el=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);return n;};
  const root=el('section');
  root.setAttribute('aria-label','Gestión de devoluciones');root.className='tk-refunds';
  const title=el('h3','Devoluciones');
  const status=el('p','Cargando solicitudes…');status.className='tk-status';
  status.setAttribute('role','status');
  const listing=el('div');
  const note=el('p','Consulta y gestión interna. La aprobación no envía dinero; el proveedor de cobros requiere verificación aparte.');
  note.className='ticketing-empty';
  root.append(title,note,status,listing);
  panel.replaceChildren(root);
  const live=()=>root.isConnected;
  async function refresh() {
    if(!live())return;
    try {
      const response=await fetch(api,{credentials:'same-origin',cache:'no-store'});
      if(!response.ok)throw Error('UNAVAILABLE');
      const result=await response.json();
      if(!result.ok||!Array.isArray(result.data))throw Error('UNAVAILABLE');
      if (!csrfToken && typeof result.csrf === 'string' && /^[a-f0-9]{64}$/.test(result.csrf))
        csrfToken = result.csrf;
      listing.replaceChildren();
      if(!result.data.length)listing.append(el('p','No hay solicitudes para gestionar.'));
      for(const request of result.data) {
        const item=el('article');item.className='tk-step';
        const state=String(request.status||'desconocido');
        const statusLabel={requested:'Solicitada',approved:'Aprobada',rejected:'Rechazada',
          refunded:'Registrada',cancelled:'Cancelada'}[state]||'En revisión';
        item.append(el('h3','Solicitud '+request.id),el('p','Evento '+request.event_id+
          ' · Pedido '+request.order_id+' · '+request.amount_cop+' COP · '+statusLabel));
        if(state==='approved') {
          const dispatch=el('button','Registrar devolución de demostración');dispatch.className='tk-button';
          dispatch.type='button';
          const message=el('p');message.setAttribute('role','status');
          dispatch.addEventListener('click',async()=>{
            if (!csrfToken) {message.textContent='Token CSRF no disponible.';return;}
            dispatch.disabled=true;message.textContent='Consultando proveedor de demostración…';
            try {
              const response=await fetch(api,{method:'POST',credentials:'same-origin',cache:'no-store',
                headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
                body:JSON.stringify({id:Number(request.id),action:'sandbox_dispatch',note:'',receipt:''})});
              const body=await response.json();
              if (!response.ok||!body.ok) throw Error('UNAVAILABLE');
              if (live()) {
                await refresh();
                status.textContent=body.data.status==='refunded'
                  ? 'Comprobante de demostración registrado y boletas invalidadas.'
                  : 'Estado de proveedor pendiente de verificación; no reintentar el envío.';
              }
            } catch (_) {
              if(live())message.textContent='Estado incierto. Comprueba la solicitud antes de reintentar.';
            } finally{dispatch.disabled=false;}
          });
          item.append(dispatch,message);
        }
        if(state==='requested') {
          const note=el('input');note.type='text';note.maxLength=220;
          note.setAttribute('aria-label','Motivo de gestión #'+request.id);
          const approve=el('button','Aprobar');approve.type='button';approve.className='tk-button';
          const reject=el('button','Rechazar');reject.type='button';reject.className='tk-button';
          const message=el('p');message.setAttribute('role','status');
          for(const button of [approve,reject])button.addEventListener('click',async()=>{
            const action=button===approve?'approve':'reject';
            if(action==='reject'&&note.value.trim().length<4){
              message.textContent='Indica un motivo de rechazo.';return;
            }
            if (!csrfToken) { message.textContent='Token CSRF no disponible.'; return; }
            approve.disabled=true;reject.disabled=true;message.textContent='Procesando…';
            try {
              const res=await fetch(api,{method:'POST',credentials:'same-origin',cache:'no-store',
                headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
                body:JSON.stringify({id:Number(request.id),action,note:note.value,receipt:''})});
              const body=await res.json();
              if(!res.ok||!body.ok)throw Error('UNAVAILABLE');
              if(live()) { await refresh();status.textContent='Cambio registrado; no constituye un reembolso.'; }
            }catch(_) {if(live())message.textContent='No se confirmó el cambio. Comprueba el estado antes de reintentar.';}
            finally{approve.disabled=false;reject.disabled=false;}
          });
          const noteField=el('div');noteField.className='tk-field';noteField.append(note);
          item.append(noteField,approve,reject,message);
        }
        listing.append(item);
      }
      status.textContent='Solicitudes cargadas. Aprobar no confirma ni ejecuta pagos.';
    }catch(_){if(live())status.textContent='Devoluciones no disponibles en este entorno.';}
  }
  await refresh();
}
