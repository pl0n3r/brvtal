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
  const manualSubmit=el('button','Crear pedido manual','tk-button is-primary');
  manualSubmit.type='submit';manualForm.append(manualSubmit);
  const manualReceipt=el('output');manualReceipt.dataset.posManualReceipt='1';
  manual.append(manualHeading,manualInfo,manualForm,manualStatus,manualReceipt);
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
  let manualKey=crypto.randomUUID().replaceAll('-','');
  let manualSubmitted=false;
  manualForm.addEventListener('input',()=>{
    if(!manualSubmitted)return;
    manualKey=crypto.randomUUID().replaceAll('-','');
    manualSubmitted=false;manualSubmit.disabled=false;
    manualReceipt.replaceChildren();
    manualStatus.textContent='Los datos cambiaron. Confirma de nuevo antes de crear otro pedido.';
  });
  manualForm.addEventListener('change',()=>{
    if(manualSubmitted){
      manualKey=crypto.randomUUID().replaceAll('-','');
      manualSubmitted=false;manualSubmit.disabled=false;
      manualReceipt.replaceChildren();
    }
  });
  manualForm.addEventListener('submit',async e=>{
    e.preventDefault();
    if(!active()||manualSubmitted||busy||!manualForm.reportValidity())return;
    const raw={
      action:'manual_order',event_id:manualEvent.value,
      ticket_type_id:manualType.value,quantity:manualQty.value,
      buyer_name:manualName.value.trim(),buyer_email:manualEmail.value.trim(),
      method:manualMethod.value,payment_reference:manualRef.value.trim(),
      idempotency_key:manualKey,confirm:manualConfirm.checked
    };
    manualSubmit.disabled=true;
    manualStatus.textContent='Comprobando pago, aforo y pedido en el servidor…';
    try{
      const data=await transaction(raw);
      if(!active())return;
      manualSubmitted=true;
      manualStatus.textContent='Pedido #'+data.order_id+(data.replayed?' recuperado.':' creado.')
        +' Pago offline confirmado; emisión de QR en cola, correo aún no enviado.';
      manualReceipt.replaceChildren(el('p','Pedido #'+data.order_id+' · '
        +data.amount_cop+' COP · '+data.method+'. Consulta Pedidos para verificar el estado.'));
      const navigate=el('button','Ver en Pedidos','tk-button');navigate.type='button';
      navigate.addEventListener('click',()=>{
        target.closest('[data-admin-module="ticketing"]')
          ?.querySelector('[data-ticketing-tab="orders"]')?.click();
      });
      manualReceipt.append(navigate);
    }catch(error){
      if(!active())return;
      const reasons={
        FORBIDDEN:'Solo el propietario puede crear pedidos manuales.',
        INVENTORY_SOLD_OUT:'No hay cupo suficiente. No se creó ningún pedido.',
        INVENTORY_NOT_SALEABLE:'El evento o la boleta ya no están disponibles.',
        POS_IDEMPOTENCY_CONFLICT:'La solicitud cambió. Revisa los datos antes de iniciar otra.',
        POS_TYPE_UNAVAILABLE:'No se encontró la boleta seleccionada.',
        POS_INVALID_MANUAL_ORDER:'Revisa los datos y la referencia del pago.'
      };
      manualStatus.textContent=reasons[error.message]
        || 'No se pudo registrar. Reintenta con los mismos datos para evitar pedidos duplicados.';
    }finally{
      if(active()&&!manualSubmitted)manualSubmit.disabled=false;
    }
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
      if(!response.ok||payload.ok!==true||!payload.data)throw Error(payload.error||'DENIED');
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
}
