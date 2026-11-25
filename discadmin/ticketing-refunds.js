/* BRVTAL #1980 · Operate: manual Wompi-panel refunds; no payment transport. */
export async function mount(panel, csrf = '') {
  if (!panel || typeof csrf !== 'string') throw Error('REFUND_ADMIN_CONTEXT');
  const endpoint='/api/ticketing/admin-refunds.php';
  let csrfToken=csrf;
  const el=(tag,value)=>{const node=document.createElement(tag);
    if(value!==undefined)node.textContent=String(value);return node;};
  const root=el('section');root.className='tk-refunds';
  root.setAttribute('aria-label','Gestión de devoluciones');
  const heading=el('h3','Devoluciones');
  const policy=el('p','Las boletas solo admiten devolución por cancelación o aplazamiento. '
    +'El dueño procesa el reembolso en el panel de Wompi, por el mismo medio de pago.');
  policy.className='ticketing-empty';
  const status=el('p','Cargando solicitudes…');status.className='tk-status';
  status.setAttribute('role','status');
  const actions=el('div'),listing=el('div');
  root.append(heading,policy,status,actions,listing);panel.replaceChildren(root);
  const live=()=>root.isConnected;
  const money=value=>new Intl.NumberFormat('es-CO',{
    style:'currency',currency:'COP',maximumFractionDigits:0}).format(Number(value)||0);
  function control(tag,label){
    const node=el(tag,label);node.className='tk-button';node.style.minHeight='44px';
    if(tag==='button')node.type='button';
    return node;
  }
  function field(label,input){
    const wrap=el('label');wrap.className='tk-field';
    wrap.append(el('span',label),input);return wrap;
  }
  async function send(body){
    if(!csrfToken)throw Error('CSRF_INVALID');
    const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',
      cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
      body:JSON.stringify(body)});
    const result=await response.json();
    if(!response.ok||result.ok!==true)throw Error('UNAVAILABLE');
    return result.data;
  }
  function incidentForm(events){
    const form=el('form');form.className='tk-step';
    form.append(el('h4','Registrar cancelación o aplazamiento'));
    const event=control('select');event.setAttribute('aria-label','Evento afectado');
    const placeholder=el('option','Selecciona un evento');placeholder.value='';
    event.append(placeholder);
    for(const entry of events){
      if(!Number.isSafeInteger(Number(entry.id))||Number(entry.id)<1)continue;
      const option=el('option',entry.title||'Evento');option.value=String(entry.id);
      event.append(option);
    }
    const cause=control('select');cause.setAttribute('aria-label','Situación del evento');
    for(const [id,text] of [['cancelled','Evento cancelado'],['postponed','Evento aplazado']]){
      const option=el('option',text);option.value=id;cause.append(option);
    }
    const button=control('button','Registrar situación');button.type='submit';
    const note=el('p','Solo el dueño puede declarar la excepción de devolución.');
    const message=el('p');message.setAttribute('role','status');
    form.append(field('Evento',event),field('Situación',cause),button,note,message);
    form.addEventListener('submit',async ev=>{
      ev.preventDefault();const id=Number(event.value);
      if(!Number.isSafeInteger(id)||id<1){message.textContent='Selecciona el evento afectado.';return;}
      button.disabled=true;message.textContent='Registrando la situación…';
      try {
        await send({action:'declare_incident',event_id:id,cause:cause.value});
        if(live()){await refresh();status.textContent='Situación registrada por el dueño.';}
      }catch(_){if(live())message.textContent='No se pudo confirmar. Consulta la situación antes de reintentar.';}
      finally{button.disabled=false;}
    });
    return form;
  }
  function row(refund,canDecide){
    const item=el('article');item.className='tk-step';
    const name=String(refund.event_title||'Evento');
    const state=String(refund.status||'requested');
    const stateNames={requested:'Pendiente de revisión',approved:'Aprobada; pendiente de Wompi',
      manual_pending:'Pendiente de Wompi',refunded:'Registrada y anulada',
      rejected:'Rechazada',failed:'Revisión necesaria',processing:'En proceso'};
    item.append(el('h4',name),el('p',money(refund.amount_cop)+' · '
      +(stateNames[state]||'En revisión')));
    // Human-readable original Wompi reference lets the owner locate the
    // exact original charge before making any external dashboard refund.
    const payment=typeof refund.wompi_payment_ref==='string'
      &&/^[A-Za-z0-9._:-]{1,190}$/.test(refund.wompi_payment_ref)
      ?refund.wompi_payment_ref:null;
    item.append(el('p',payment?'Pago en Wompi: '+payment:
      'Sin pago de Wompi verificable: no confirmar devolución.'));
    if(!canDecide)return item;
    if(state!=='requested'&&state!=='approved'&&state!=='manual_pending')return item;
    const message=el('p');message.setAttribute('role','status');
    if(state==='requested'){
      const note=control('input');note.type='text';note.maxLength=220;
      note.setAttribute('aria-label','Motivo de decisión');
      const approve=control('button','Aprobar devolución');
      const reject=control('button','Rechazar devolución');
      const act=async action=>{
        if(action==='reject'&&note.value.trim().length<4){
          message.textContent='Indica un motivo de rechazo.';return;
        }
        approve.disabled=reject.disabled=true;message.textContent='Registrando decisión…';
        try{
          await send({id:Number(refund.id),action,note:note.value,receipt:''});
          if(live()){await refresh();status.textContent='Decisión registrada por el dueño.';}
        }catch(_){if(live())message.textContent='Decisión no confirmada. Comprueba el estado antes de reintentar.';}
        finally{approve.disabled=reject.disabled=false;}
      };
      approve.addEventListener('click',()=>act('approve'));
      reject.addEventListener('click',()=>act('reject'));
      item.append(field('Nota del dueño',note),approve,reject,message);
    }else{
      const receipt=control('input');receipt.type='text';receipt.maxLength=120;
      receipt.setAttribute('aria-label','Referencia del reembolso de Wompi');
      const warning=el('p','Primero completa el reembolso en el panel de Wompi. '
        +'Solo después registra su referencia aquí: se anularán las boletas.');
      const confirm=control('button','Confirmar reembolso hecho en Wompi');
      confirm.addEventListener('click',async()=>{
        const value=receipt.value.trim();
        if(!/^[A-Za-z0-9._:-]{6,120}$/.test(value)){
          message.textContent='Escribe la referencia válida del reembolso de Wompi.';return;
        }
        confirm.disabled=true;message.textContent='Registrando comprobante…';
        try{
          await send({id:Number(refund.id),action:'manual_confirm',note:'',receipt:value});
          if(live()){await refresh();status.textContent='Comprobante registrado y boletas anuladas.';}
        }catch(_){if(live())message.textContent='Estado no confirmado. Comprueba Wompi y la solicitud antes de reintentar.';}
        finally{confirm.disabled=false;}
      });
      item.append(warning,field('Referencia de Wompi',receipt),confirm,message);
    }
    return item;
  }
  async function refresh(){
    if(!live())return;
    try{
      const response=await fetch(endpoint,{credentials:'same-origin',cache:'no-store'});
      if(!response.ok)throw Error('UNAVAILABLE');
      const result=await response.json();
      if(result.ok!==true||!Array.isArray(result.data))throw Error('UNAVAILABLE');
      if(!csrfToken&&typeof result.csrf==='string'&&/^[a-f0-9]{64}$/.test(result.csrf))
        csrfToken=result.csrf;
      const canDecide=result.can_decide===true;
      actions.replaceChildren();
      if(canDecide)actions.append(incidentForm(Array.isArray(result.events)?result.events:[]));
      else actions.append(el('p','Consulta de solicitudes. Solo el dueño puede tomar decisiones.'));
      listing.replaceChildren();
      if(!result.data.length)listing.append(el('p','Aún no hay solicitudes.'));
      for(const refund of result.data)listing.append(row(refund,canDecide));
      status.textContent='Solicitudes cargadas. Ninguna acción aquí envía dinero.';
    }catch(_){if(live())status.textContent='No se pudieron consultar las devoluciones.';}
  }
  await refresh();
}
