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
    'Se usará únicamente para enviar las boletas de esta venta; no da consentimiento publicitario.');
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
  area.append(heading,info,status,form,sale,closeForm);
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
      status.textContent='Elige un evento para abrir la caja.';
    } catch {
      if(active()) {
        clearOptions(event,'No se pudieron cargar los eventos',[]);
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
      if(!response.ok||payload.ok!==true||!payload.data)throw Error('DENIED');
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
