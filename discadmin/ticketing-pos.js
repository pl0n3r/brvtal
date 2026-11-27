/* #1970: staff Caja is a separate Operate tool. Server owns payment gates. */
export function mount(target) {
  if (!target) throw Error('POS_MOUNT_REQUIRED');
  const el=(tag,text,cls)=>{const n=document.createElement(tag);
    if(text!==undefined)n.textContent=String(text);if(cls)n.className=cls;return n;};
  const area=el('section',undefined,'tk-pos');area.dataset.posModule='1';
  const heading=el('h3','Caja de puerta');
  const info=el('p','Registro de demostración. No confirma cobros reales. Transferencias y QR requieren verificación independiente.','ticketing-empty');
  const status=el('p','Cargando eventos…','tk-status');status.dataset.posStatus='1';
  status.setAttribute('role','status');status.setAttribute('aria-live','polite');
  let counter=0;
  const field=(form,label,name,control,help='')=>{
    const id='tk-pos-'+(++counter),wrap=el('div',undefined,'tk-field');
    const text=el('label',label);text.htmlFor=id;control.id=id;control.name=name;
    wrap.append(text,control);if(help)wrap.append(el('small',help));form.append(wrap);
    return control;
  };
  const select=()=>el('select');
  const number=(value,min,max)=>{const n=el('input');n.type='number';n.value=value;
    n.min=min;n.max=max;n.required=true;return n;};
  const form=el('form',undefined,'tk-form');form.dataset.posForm='1';
  const event=field(form,'Evento','event_id',select());event.required=true;
  const opening=field(form,'Base en efectivo (COP)','opening_cash_cop',number('0','0','1000000000'));
  const open=el('button','Abrir caja','tk-button is-primary');open.type='submit';
  form.append(open);

  const setVisible=(node,visible)=>{node.hidden=!visible;node.style.display=visible?'':'none';};
  const sale=el('form',undefined,'tk-form');sale.dataset.posSale='1';setVisible(sale,false);
  const type=field(sale,'Boleta','ticket_type_id',select());type.required=true;
  const qty=field(sale,'Cantidad','quantity',number('1','1','10'));
  const method=field(sale,'Método','method',select());
  const buyerEmailInput=el('input');buyerEmailInput.type='email';
  buyerEmailInput.maxLength=190;buyerEmailInput.autocomplete='email';
  const buyerEmail=field(sale,'Correo del comprador (opcional)','buyer_email',buyerEmailInput,
    'Correo solo para entrega transaccional. En esta demostración no se envían correos ni se hacen cobros.');
  for (const [value,label,enabled] of [
    ['cash','Efectivo',true],['transfer','Transferencia: no disponible',false],
    ['wompi_qr','QR Wompi: no disponible',false]
  ]) {const option=el('option',label);option.value=value;option.disabled=!enabled;method.append(option);}
  const prepare=el('button','Preparar venta','tk-button is-primary');prepare.type='submit';
  sale.append(prepare);
  const confirm=el('button','Confirmar registro','tk-button is-primary');confirm.type='button';confirm.hidden=true;
  const cancel=el('button','Cancelar preparación','tk-button');cancel.type='button';cancel.hidden=true;
  sale.append(confirm,cancel);
  const receipts=el('output');receipts.dataset.posReceipt='1';sale.append(receipts);

  const closeForm=el('form',undefined,'tk-form');closeForm.dataset.posClose='1';setVisible(closeForm,false);
  const count=field(closeForm,'Efectivo contado (COP)','closing_cash_cop',number('0','0','1000000000'));
  const close=el('button','Cerrar caja y arquear','tk-button');close.type='submit';closeForm.append(close);
  // Separate owner-only sale. The legacy drawer above remains demo-only.
  const manual=el('section',undefined,'tk-manual');manual.dataset.posManual='1';
  manual.style.marginTop='2rem';manual.style.paddingTop='1rem';
  const manualHeading=el('h4','Crear pedido manual');
  const manualInfo=el('p',
    'Solo el propietario puede registrar un pago offline ya recibido. La referencia se verifica fuera de BRVTAL. No se procesa un cobro Wompi.',
    'ticketing-empty');
  const manualStatus=el('p','Elige un evento y completa los datos del comprador.','tk-status');
  manualStatus.setAttribute('role','status');manualStatus.setAttribute('aria-live','polite');
  const manualForm=el('form',undefined,'tk-form');manualForm.dataset.posManualForm='1';
  const manualEvent=field(manualForm,'Evento','manual_event_id',select());manualEvent.required=true;
  const manualType=field(manualForm,'Boleta','manual_ticket_type_id',select());manualType.required=true;
  const manualQty=field(manualForm,'Cantidad','manual_quantity',number('1','1','10'));
  const manualNameInput=el('input');manualNameInput.type='text';
  manualNameInput.required=true;manualNameInput.minLength=3;manualNameInput.maxLength=120;
  manualNameInput.autocomplete='name';
  const manualName=field(manualForm,'Nombre completo','manual_buyer_name',manualNameInput);
  const manualEmailInput=el('input');manualEmailInput.type='email';
  manualEmailInput.required=true;manualEmailInput.maxLength=190;manualEmailInput.autocomplete='email';
  const manualEmail=field(manualForm,'Correo para entregar las boletas','manual_buyer_email',
    manualEmailInput,'La entrega de QR queda en cola y depende del worker y de las puertas de correo.');
  const manualMethod=field(manualForm,'Pago recibido por','manual_method',select());
  for(const [value,label] of [
    ['cash','Efectivo'],['transfer','Transferencia bancaria'],
    ['nequi','Nequi'],['daviplata','Daviplata'],['terminal','Datáfono externo']
  ]){const option=el('option',label);option.value=value;manualMethod.append(option);}
  const manualRefInput=el('input');manualRefInput.type='text';manualRefInput.maxLength=80;
  manualRefInput.autocomplete='off';
  const manualRef=field(manualForm,'Referencia del pago','manual_payment_reference',manualRefInput,
    'Obligatoria en transferencias, billeteras y datáfono. No ingreses números de cuenta ni claves.');
  const updateManualRef=()=>{
    const required=manualMethod.value!=='cash';
    manualRef.required=required;manualRef.disabled=!required;
    if(!required)manualRef.value='';
  };manualMethod.addEventListener('change',updateManualRef);updateManualRef();
  const manualConfirmInput=el('input');manualConfirmInput.type='checkbox';
  manualConfirmInput.required=true;
  const manualConfirm=field(manualForm,'Confirmo que ya verifiqué el pago recibido',
    'manual_confirm',manualConfirmInput);
  const manualPreview=el('div',undefined,'ticketing-empty');
  manualPreview.dataset.posManualPreview='1';
  // Keep the quote above the action instead of stretching a neighboring grid cell.
  manualPreview.style.gridColumn='1 / -1';
  manualPreview.style.color='var(--text,#f4f5f6)';
  manualPreview.style.overflowWrap='anywhere';
  setVisible(manualPreview,false);
  manualPreview.setAttribute('aria-live','polite');
  manualForm.append(manualPreview);
  const manualSubmit=el('button','Revisar pedido manual','tk-button is-primary');
  manualSubmit.type='submit';manualForm.append(manualSubmit);
  const manualReceipt=el('output');manualReceipt.dataset.posManualReceipt='1';
  const manualLookup=el('button','Consultar recibo pendiente','tk-button');
  manualLookup.type='button';manualLookup.hidden=true;
  manual.append(manualHeading,manualInfo,manualForm,manualStatus,manualReceipt,manualLookup);
  area.append(heading,info,status,form,sale,closeForm,manual);
  target.append(area);
  const active=()=>area.isConnected&&target.isConnected;
  let shiftId=null,pending=null,csrf=null,busy=false,catalog=[];
  const clearOptions=(control,initial,items)=>{
    const first=el('option',initial);first.value='';
    control.replaceChildren(first,...items.map(item=>{
      const option=el('option',item.name||item.title);option.value=String(item.id);return option;
    }));
  };
  const fillTypes=()=>{
    const picked=catalog.find(row=>String(row.id)===event.value);
    clearOptions(type,'Elige una boleta',picked?.ticket_types||[]);
  };
  event.addEventListener('change',fillTypes);
  const fillManualTypes=()=>{
    const picked=catalog.find(row=>String(row.id)===manualEvent.value);
    clearOptions(manualType,'Elige una boleta',picked?.ticket_types||[]);
  };
  manualEvent.addEventListener('change',fillManualTypes);
  const storageKey='brvtal.pos.manual.intent.v1';
  let storedIntent=null,storageAvailable=true;
  try {storedIntent=sessionStorage.getItem(storageKey);}catch{storageAvailable=false;}
  if(storedIntent!==null&&!/^[a-f0-9]{32}$/.test(storedIntent))storageAvailable=false;
  let manualKey=storedIntent||crypto.randomUUID().replaceAll('-','');
  let manualSubmitted=false,manualReviewed=false,reviewSnapshot=null,manualPending=false;
  let manualQuote=null,reviewGeneration=0;
  const stageLabels={early_bird:'Early bird',preventa:'Preventa',general:'General',puerta:'Puerta'};
  const cop=new Intl.NumberFormat('es-CO');
  const quoteTime=new Intl.DateTimeFormat('es-CO',{timeZone:'America/Bogota',dateStyle:'short',timeStyle:'medium'});
  const quoteValid=(q,quantity)=>q?.channel==='manual'&&Number.isSafeInteger(q.unit_price_cop)
    &&q.unit_price_cop>0&&Number.isSafeInteger(q.amount_cop)&&q.amount_cop===q.unit_price_cop*Number(quantity)
    &&q.amount_cop<=10000000&&((q.source==='base'&&q.stage_code===null)
      ||(q.source==='stage'&&Object.hasOwn(stageLabels,q.stage_code)))
    &&typeof q.quote_token==='string'&&q.quote_token.length>0&&q.quote_token.length<=256
    &&typeof q.expires_at_utc==='string'&&/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(q.expires_at_utc)
    &&Date.parse(q.expires_at_utc)>Date.now();
  const clearManualReview=()=>{
    manualReviewed=false;reviewSnapshot=null;manualQuote=null;
    manualPreview.replaceChildren();setVisible(manualPreview,false);
    manualSubmit.textContent='Revisar pedido manual';
  };
  let recoveryRequired=!!storedIntent||!storageAvailable;
  const renderManualReceipt=(data,recovered)=>{
    manualSubmitted=true;manualSubmit.disabled=true;manualQuote=null;
    if(recovered){manualPreview.replaceChildren();setVisible(manualPreview,false);manualReviewed=false;reviewSnapshot=null;}
    let delivery='emisión de QR en cola, entrega por correo no verificada.';
    if(data.issuance==='issued')delivery='QR emitido, entrega por correo no verificada.';
    else if(recovered)delivery='emisión de QR pendiente, entrega por correo no verificada.';
    manualStatus.textContent='Pedido #'+data.order_id+(recovered?' recuperado.':' creado.')
      +' Pago offline confirmado; '+delivery+(recovered?' Se conserva el importe del recibo original.':'');
    manualReceipt.replaceChildren(el('p','Pedido #'+data.order_id+' · '
      +data.amount_cop+' COP · '+data.method+'. Consulta Pedidos para verificar el estado.'));
    const navigate=el('button','Ver en Pedidos','tk-button');navigate.type='button';
    navigate.addEventListener('click',()=>target.closest('[data-admin-module="ticketing"]')
      ?.querySelector('[data-ticketing-tab="orders"]')?.click());
    manualReceipt.append(navigate);
  };
  const recoverManual=async()=>{
    if(!active()||manualPending||busy||!storedIntent||!storageAvailable)return;
    manualPending=true;recoveryRequired=true;manualSubmit.disabled=true;manualLookup.disabled=true;
    manualStatus.textContent='Consultando el recibo de la intención original…';
    try{
      const data=await transaction({action:'manual_intent',idempotency_key:storedIntent});
      if(!active())return;
      if(data.state==='found'&&data.status==='paid'&&['issued','pending'].includes(data.issuance)){
        recoveryRequired=false;renderManualReceipt(data,true);
      }else if(data.state==='not_found'){
        recoveryRequired=false;clearManualReview();
        manualStatus.textContent='No hay recibo verificable todavía. Esto no prueba que el pago no se registró. Si reintentas, revisa y confirma los datos originales con la misma intención.';
      }else{
        manualStatus.textContent='Resultado incierto. No registres otra venta; consulta de nuevo el recibo o verifica Pedidos.';
      }
    }catch(error){
      if(active())manualStatus.textContent=error.message==='FORBIDDEN'
        ?'Solo el propietario puede consultar este recibo.':'No se pudo verificar el recibo. Conservamos la intención; consulta de nuevo antes de registrar otra venta.';
    }finally{
      manualPending=false;manualLookup.disabled=false;
      if(active())manualSubmit.disabled=recoveryRequired||manualSubmitted;
    }
  };
  manualLookup.addEventListener('click',()=>void recoverManual());
  const resetManualReview=()=>{
    reviewGeneration++;
    if(manualPending)return;
    if(manualReviewed)manualStatus.textContent='Los datos cambiaron. Revisa una cotización nueva.';
    clearManualReview();
    if(manualSubmitted){
      try {sessionStorage.removeItem(storageKey);}catch{
        recoveryRequired=true;manualSubmit.disabled=true;return;
      }
      storedIntent=null;manualLookup.hidden=true;
      manualKey=crypto.randomUUID().replaceAll('-','');
      manualSubmitted=false;
      manualSubmit.disabled=false;
      manualReceipt.replaceChildren();
    }
  };
  manualForm.addEventListener('input',resetManualReview);
  manualForm.addEventListener('change',resetManualReview);
  manualRef.addEventListener('input',()=>manualRef.setCustomValidity(''));
  const validManualReference=()=>{
    const reference=manualRef.value.trim();
    if(manualMethod.value!=='cash' && (reference.length<4
        || !/^[A-Za-z0-9][A-Za-z0-9 ._/-]*$/.test(reference))){
      manualStatus.textContent='La referencia debe tener entre 4 y 80 caracteres válidos.';
      manualRef.setCustomValidity('Ingresa la referencia completa del pago verificado.');
      manualRef.reportValidity();return false;
    }
    manualRef.setCustomValidity('');
    return true;
  };
  const readManualOrder=()=>({
    action:'manual_order',event_id:manualEvent.value,
    ticket_type_id:manualType.value,quantity:manualQty.value,
    buyer_name:manualName.value.trim(),buyer_email:manualEmail.value.trim(),
    method:manualMethod.value,payment_reference:manualRef.value.trim(),
    idempotency_key:manualKey,confirm:manualConfirm.checked
  });
  const renderManualQuote=(q,raw,snapshot)=>{
    const eventLabel=manualEvent.selectedOptions[0]?.textContent||'Evento';
    const ticketLabel=manualType.selectedOptions[0]?.textContent||'Boleta';
    const methodLabel=manualMethod.selectedOptions[0]?.textContent||raw.method;
    manualPreview.replaceChildren(
      el('strong','Total a registrar: '+cop.format(q.amount_cop)+' COP'),
      el('p',eventLabel+' · '+ticketLabel+' · '+raw.quantity+' boleta(s)'),
      el('p','Precio unitario: '+cop.format(q.unit_price_cop)+' COP'),
      el('p','Tarifa: '+(q.source==='stage'?'Etapa '+stageLabels[q.stage_code]:'Precio base')),
      el('p','Válida hasta '+quoteTime.format(new Date(q.expires_at_utc))+' (Bogotá)'),
      el('p','Comprador y destinatario: '+raw.buyer_name+' · '+raw.buyer_email),
      el('p','Medio: '+methodLabel+' · referencia: '+(raw.payment_reference||'no aplica')),
      el('p','Confirma únicamente si el importe coincide con el pago offline verificado.')
    );
    setVisible(manualPreview,true);
    manualQuote=q;reviewSnapshot=snapshot;manualReviewed=true;
    manualSubmit.textContent='Confirmar y crear pedido';
    manualStatus.textContent='Revisa el total, la tarifa y los datos antes de confirmar.';
  };
  const requestManualQuote=async(raw,snapshot)=>{
    clearManualReview();
    const generation=reviewGeneration;
    manualPending=true;manualSubmit.disabled=true;
    manualStatus.textContent='Consultando tarifa y cupo…';
    try{
      const q=await transaction({action:'manual_quote',event_id:raw.event_id,
        ticket_type_id:raw.ticket_type_id,quantity:raw.quantity,idempotency_key:raw.idempotency_key});
      if(!active())return;
      if(generation!==reviewGeneration||JSON.stringify(readManualOrder())!==snapshot){
        manualStatus.textContent='Los datos cambiaron. Revisa una cotización nueva.';return;
      }
      if(!quoteValid(q,raw.quantity))throw new Error('POS_QUOTE_INVALID');
      renderManualQuote(q,raw,snapshot);
    }catch(error){
      if(active())manualStatus.textContent=error.message==='FORBIDDEN'
        ?'Solo el propietario puede cotizar pedidos manuales.'
        :'No hay cotización válida. Revisa los datos y vuelve a cotizar; no se envió el pedido.';
    }finally{
      manualPending=false;
      if(active())manualSubmit.disabled=recoveryRequired||manualSubmitted;
    }
  };
  const submitManualOrder=async raw=>{
    raw.quote_token=manualQuote.quote_token;
    try{sessionStorage.setItem(storageKey,manualKey);storedIntent=manualKey;}
    catch{recoveryRequired=true;manualSubmit.disabled=true;
      manualStatus.textContent='No se puede conservar la intención. No se envió el pedido.';return;}
    manualLookup.hidden=false;manualLookup.disabled=true;
    manualPending=true;
    manualSubmit.disabled=true;
    // Prevent editing while a request is in flight; after a timeout the
    // original idempotency key is retained for safe retry.
    const fields=Array.from(manualForm.querySelectorAll('input,select'));
    const disabledBefore=fields.map(control=>control.disabled);
    fields.forEach(control=>{control.disabled=true;});
    manualStatus.textContent='Comprobando pago, aforo y pedido en el servidor…';
    try{
      const data=await transaction(raw);
      if(!active())return;
      renderManualReceipt({...data,method:data.method||raw.method},!!data.replayed);
    }catch(error){
      if(!active())return;
      const reasons={
        FORBIDDEN:'Solo el propietario puede crear pedidos manuales.',
        INVENTORY_SOLD_OUT:'No hay cupo suficiente. No se creó ningún pedido.',
        INVENTORY_NOT_SALEABLE:'El evento o la boleta ya no están disponibles.',
        POS_IDEMPOTENCY_CONFLICT:'La misma solicitud ya se usó con datos diferentes.',
        POS_TYPE_UNAVAILABLE:'No se encontró la boleta seleccionada.',
        POS_QUOTE_CHANGED:'La tarifa o el cupo cambiaron, o la cotización venció. Revisa una cotización nueva.',
        POS_QUOTE_REQUIRED:'Se necesita una cotización nueva antes de confirmar.',
        POS_INVALID_MANUAL_ORDER:'El servidor rechazó los datos. Revisa la referencia, el nombre y el medio de pago.'
      };
      if(error.status===409||error.status===403)clearManualReview();
      recoveryRequired=!Object.hasOwn(reasons,error.message)||error.message==='POS_IDEMPOTENCY_CONFLICT';
      manualStatus.textContent=reasons[error.message]
        || 'No se pudo verificar el resultado. Consultando el recibo original…';
    }finally{
      fields.forEach((control,index)=>{control.disabled=disabledBefore[index];});
      manualPending=false;manualLookup.disabled=false;
      if(active()&&!manualSubmitted){
        manualSubmit.disabled=recoveryRequired;
        if(recoveryRequired)void recoverManual();
      }
    }
  };
  manualForm.addEventListener('submit',async e=>{
    e.preventDefault();
    if(!active()||manualSubmitted||manualPending||busy||recoveryRequired||!manualForm.reportValidity())return;
    if(!validManualReference())return;
    const raw=readManualOrder();
    // Changes to a field, even if scripted without an input event, must
    // force a fresh operator review before any paid-order request.
    const snapshot=JSON.stringify(raw);
    if(!manualReviewed||reviewSnapshot!==snapshot){
      await requestManualQuote(raw,snapshot);return;
    }
    if(!quoteValid(manualQuote,raw.quantity)){
      clearManualReview();
      manualStatus.textContent='La cotización venció. Revisa una cotización nueva antes de confirmar.';
      return;
    }
    await submitManualOrder(raw);
  });
  async function loadCatalog() {
    try {
      let data=null;
      const embedded=target.closest('[data-finance-module]')?.querySelector('[data-finance-catalog]')?.textContent;
      if (embedded) {
        try {
          const snapshot=JSON.parse(embedded);
          if(snapshot.ok&&Array.isArray(snapshot.events))data=snapshot;
        } catch { /* fall back to the authorized API */ }
      }
      if (!data) {
        const response=await fetch('/api/ticketing/admin-promos.php?catalog=1',{
          credentials:'same-origin',cache:'no-store'});
        const payload=await response.json().catch(()=>({}));
        if(!response.ok||!payload.ok||!Array.isArray(payload.events))throw Error('NO_CATALOG');
        data=payload;
      }
      catalog=data.events;
      if(!active())return;
      clearOptions(event,'Elige un evento',catalog);
      if(catalog.length===1)event.value=String(catalog[0].id);
      fillTypes();
      clearOptions(manualEvent,'Elige un evento',catalog);
      if(catalog.length===1)manualEvent.value=String(catalog[0].id);
      fillManualTypes();
      status.textContent='Elige un evento para abrir la caja.';
    } catch {
      if(active()) {
        clearOptions(event,'No se pudieron cargar los eventos',[]);
        clearOptions(manualEvent,'No se pudieron cargar los eventos',[]);
        manualStatus.textContent='No se puede crear el pedido sin catálogo autorizado.';
        status.textContent='No se pudieron consultar los eventos. No se permite registrar una operación.';
      }
    }
  }
  const transaction=async raw=>{
    if(busy)throw Error('BUSY');busy=true;
    try {
      if(csrf===null){
        const response=await fetch('/discadmin/ticketing-orders.php',{
          credentials:'same-origin',cache:'no-store',
          headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}});
        if(!response.ok)throw Error('AUTH');
        const doc=new DOMParser().parseFromString(await response.text(),'text/html');
        csrf=doc.querySelector('[data-ticketing-records]')?.getAttribute('data-ticketing-csrf');
        if(!csrf||!/^[a-f0-9]{64}$/i.test(csrf))throw Error('AUTH');
      }
      const response=await fetch('/api/ticketing/admin-pos.php',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify(raw)
      });
      const payload=await response.json();
      if(!response.ok||payload.ok!==true||!payload.data)
        throw Object.assign(new Error(payload.error||'DENIED'),{status:response.status});
      return payload.data;
    }finally{busy=false;}
  };
  const execute=async(input,done)=>{
    if(!active())return;
    status.textContent='Validando en el servidor…';
    try {const data=await transaction(input);if(active())done(data);}
    catch {if(active())status.textContent='Operación no disponible. Comprueba el estado del servidor antes de reintentar.';}
  };
  form.addEventListener('submit',e=>{
    e.preventDefault();if(!form.reportValidity())return;
    void execute({action:'open',event_id:event.value,opening_cash_cop:opening.value},d=>{
      shiftId=d.shift_id;setVisible(form,false);setVisible(sale,true);setVisible(closeForm,true);
      status.textContent='Caja abierta. Turno '+shiftId+'. Sin confirmación de cobros reales.';
    });
  });
  sale.addEventListener('submit',e=>{
    e.preventDefault();if(!shiftId||pending||!sale.reportValidity())return;
    void execute({action:'prepare',shift_id:String(shiftId),ticket_type_id:type.value,
      quantity:qty.value,method:method.value,idempotency_key:crypto.randomUUID().replaceAll('-','')},d=>{
      if(d.replayed||!d.confirmation_token){status.textContent='Solicitud previa: consulta el recibo del servidor.';return;}
      pending={sale_id:String(d.sale_id),confirmation_token:d.confirmation_token};
      confirm.hidden=false;cancel.hidden=false;
      status.textContent='Preparación '+d.sale_id+' por '+d.amount_cop+' COP. Confirma solo después de verificar el efectivo.';
    });
  });
  confirm.addEventListener('click',()=>{
    if(!pending)return;
    void execute({action:'confirm',...pending,confirm:true,buyer_email:buyerEmail.value.trim()},d=>{
      pending=null;confirm.hidden=true;cancel.hidden=true;
      status.textContent='Registro de demostración: '+d.receipt+'. No constituye un cobro Wompi.';
      receipts.replaceChildren(el('p','Recibo y boletas disponibles una sola vez:'));
      for(const qr of d.tickets||[]){const code=el('code',qr);
        code.style.overflowWrap='anywhere';receipts.append(code,el('hr'));}
    });
  });
  cancel.addEventListener('click',()=>{
    if(!pending)return;
    void execute({action:'cancel',...pending},()=>{
      pending=null;confirm.hidden=true;cancel.hidden=true;
      status.textContent='Preparación anulada sin boletas ni cobros.';
    });
  });
  closeForm.addEventListener('submit',e=>{
    e.preventDefault();if(!shiftId||pending||!closeForm.reportValidity())return;
    void execute({action:'close',shift_id:String(shiftId),closing_cash_cop:count.value},d=>{
      status.textContent='Cierre registrado. Esperado: '+d.expected_cash_cop
        +' COP; diferencia: '+d.cash_difference_cop+' COP; Wompi excluido.';
      shiftId=null;setVisible(sale,false);setVisible(closeForm,false);setVisible(form,true);receipts.replaceChildren();
    });
  });
  void loadCatalog();
  if(!storageAvailable){manualSubmit.disabled=true;
    manualStatus.textContent='No se puede conservar la intención de forma segura. No registres otro pedido.';
  }else if(storedIntent){manualLookup.hidden=false;manualSubmit.disabled=true;void recoverManual();}
}
