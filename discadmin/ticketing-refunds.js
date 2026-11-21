/* T21 sandbox staff component; mount inside the canonical DISCADMIN ticketing panel. */
export async function mount(panel, csrf) {
  if (!panel || typeof csrf !== 'string' || !csrf) throw Error('REFUND_ADMIN_CONTEXT');
  const api='/api/ticketing/admin-refunds.php';
  const el=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);return n;};
  const root=el('section');
  root.setAttribute('aria-label','Gestión de devoluciones');
  const title=el('h2','Devoluciones · entorno de pruebas');
  const status=el('p','Cargando solicitudes…');
  status.setAttribute('role','status');
  const listing=el('div');
  root.append(title,status,listing);
  panel.replaceChildren(root);
  const live=()=>root.isConnected;
  async function refresh() {
    if(!live())return;
    try {
      const response=await fetch(api,{credentials:'same-origin',cache:'no-store'});
      if(!response.ok)throw Error('UNAVAILABLE');
      const result=await response.json();
      if(!result.ok||!Array.isArray(result.data))throw Error('UNAVAILABLE');
      listing.replaceChildren();
      if(!result.data.length)listing.append(el('p','Sin solicitudes en el entorno sintético.'));
      for(const request of result.data) {
        const item=el('article');
        const state=String(request.status||'desconocido');
        item.append(el('h3','Solicitud #'+request.id),el('p','Evento '+request.event_id+
          ' · Pedido '+request.order_id+' · '+request.amount_cop+' COP · '+state));
        if(state==='requested') {
          const note=el('input');note.type='text';note.maxLength=220;
          note.setAttribute('aria-label','Motivo de gestión #'+request.id);
          const approve=el('button','Aprobar');approve.type='button';
          const reject=el('button','Rechazar');reject.type='button';
          const message=el('p');message.setAttribute('role','status');
          for(const button of [approve,reject])button.addEventListener('click',async()=>{
            const action=button===approve?'approve':'reject';
            if(action==='reject'&&note.value.trim().length<4){
              message.textContent='Indica un motivo de rechazo.';return;
            }
            approve.disabled=true;reject.disabled=true;message.textContent='Procesando…';
            try {
              const res=await fetch(api,{method:'POST',credentials:'same-origin',cache:'no-store',
                headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
                body:JSON.stringify({id:Number(request.id),action,note:note.value,receipt:''})});
              const body=await res.json();
              if(!res.ok||!body.ok)throw Error('UNAVAILABLE');
              if(live()) { status.textContent='Cambio registrado; no constituye un reembolso.';await refresh(); }
            }catch(_) {if(live())message.textContent='No se confirmó el cambio. Comprueba el estado antes de reintentar.';}
            finally{approve.disabled=false;reject.disabled=false;}
          });
          item.append(note,approve,reject,message);
        }
        listing.append(item);
      }
      status.textContent='Solicitudes sintéticas cargadas. Aprobar no confirma ni ejecuta pagos.';
    }catch(_){if(live())status.textContent='Devoluciones no disponibles en este entorno.';}
  }
  await refresh();
}
