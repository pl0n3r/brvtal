/* T24: POS only in DISCADMIN Finance, synthetic/test database only. */
export async function mount(financePanel) {
  const area=document.createElement('section');
  area.dataset.posModule='1';
  area.innerHTML='';
  const title=document.createElement('h3'); title.textContent='Caja POS de puerta (solo pruebas)';
  const explain=document.createElement('p');
  explain.textContent='Deshabilitado en producción. Solo efectivo de prueba. Transferencias y QR Wompi requieren verificación independiente.';
  const status=document.createElement('p');status.dataset.posStatus='1';status.setAttribute('role','status');
  const create=(tag,label,attrs={})=>{
    const el=document.createElement(tag);el.textContent=label;
    for(const [k,v] of Object.entries(attrs)) el.setAttribute(k,v);
    return el;
  };
  const form=document.createElement('form');form.dataset.posForm='1';
  const field=(label,name,value='',max=1000000000)=>{
    const wrap=create('label',label);
    const input=document.createElement('input');
    input.name=name;input.type='number';input.min=name==='opening_cash_cop'?'0':'1';
    input.max=String(max);input.required=true;input.value=String(value);
    wrap.appendChild(input);form.appendChild(wrap);return input;
  };
  const event=field('Evento', 'event_id');
  const opening=field('Base en efectivo (COP)','opening_cash_cop','0');
  const open=create('button','Abrir caja de prueba',{type:'submit'});
  form.appendChild(open);
  const sale=document.createElement('form');sale.dataset.posSale='1';sale.hidden=true;
  const typeLabel=create('label','Tipo de entrada');
  const type=document.createElement('input');type.name='ticket_type_id';type.type='number';
  type.required=true;type.min='1';typeLabel.appendChild(type);sale.appendChild(typeLabel);
  const qtyLabel=create('label','Cantidad');
  const qty=document.createElement('input');qty.name='quantity';qty.type='number';
  qty.min='1';qty.max='10';qty.value='1';qty.required=true;qtyLabel.appendChild(qty);sale.appendChild(qtyLabel);
  const methodLabel=create('label','Método');
  const method=document.createElement('select');method.name='method';
  for(const [value,label,enabled] of [['cash','Efectivo de prueba',true],
    ['transfer','Transferencia: requiere autorización',false],
    ['wompi_qr','QR Wompi: solo comprobante del proveedor',false]]) {
    const o=document.createElement('option');o.value=value;o.textContent=label;o.disabled=!enabled;method.append(o);
  }
  methodLabel.append(method);sale.append(methodLabel);
  const prepare=create('button','Preparar venta',{type:'submit'});sale.append(prepare);
  const confirm=create('button','Confirmar cobro de prueba',{type:'button'});
  confirm.hidden=true;const cancel=create('button','Cancelar preparación',{type:'button'});cancel.hidden=true;
  sale.append(confirm,cancel);
  const receipts=create('output','');receipts.dataset.posReceipt='1';sale.append(receipts);
  const closeForm=document.createElement('form');closeForm.dataset.posClose='1';closeForm.hidden=true;
  const closeLabel=create('label','Efectivo contado (COP)');
  const count=document.createElement('input');count.name='closing_cash_cop';count.type='number';
  count.min='0';count.max='1000000000';count.required=true;closeLabel.append(count);
  closeForm.append(closeLabel,create('button','Cerrar caja y arquear',{type:'submit'}));
  area.append(title,explain,status,form,sale,closeForm);
  financePanel.append(area);
  const active=()=>area.isConnected&&financePanel.isConnected;
  let shiftId=null, pending=null, csrf=null, busy=false;
  const transaction=async raw=>{
    if(busy) throw Error('BUSY');
    busy=true;
    try {
      if(csrf===null) {
        const f=await fetch('/discadmin/ticketing-orders.php',
          {credentials:'same-origin',cache:'no-store',headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}});
        if(!f.ok) throw Error('AUTH');
        const doc=new DOMParser().parseFromString(await f.text(),'text/html');
        csrf=doc.querySelector('[data-ticketing-records]')?.getAttribute('data-ticketing-csrf');
        if(!csrf||!/^[a-f0-9]{64}$/i.test(csrf)) throw Error('AUTH');
      }
      const res=await fetch('/api/ticketing/admin-pos.php',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify(raw)
      });
      const payload=await res.json();
      if(!res.ok||payload.ok!==true||!payload.data) throw Error('DENIED');
      return payload.data;
    } finally {busy=false;}
  };
  const execute=async(input,done)=>{
    if(!active()) return;
    status.textContent='Validando en el servidor…';
    try { const data=await transaction(input);if(active()) done(data); }
    catch {if(active()) status.textContent='Operación no disponible. No se registró confirmación local; comprueba el estado del servidor antes de reintentar.';}
  };
  form.addEventListener('submit',e=>{
    e.preventDefault();
    void execute({action:'open',event_id:event.value,opening_cash_cop:opening.value},d=>{
      shiftId=d.shift_id;form.hidden=true;sale.hidden=false;closeForm.hidden=false;
      status.textContent='Caja sintética abierta. Turno #'+shiftId;
    });
  });
  sale.addEventListener('submit',e=>{
    e.preventDefault();if(!shiftId||pending) return;
    void execute({action:'prepare',shift_id:String(shiftId),ticket_type_id:type.value,
      quantity:qty.value,method:method.value,idempotency_key:crypto.randomUUID().replaceAll('-','')},d=>{
      if(d.replayed||!d.confirmation_token) {status.textContent='Solicitud previa: revise el recibo del servidor.';return;}
      pending={sale_id:String(d.sale_id),confirmation_token:d.confirmation_token};
      confirm.hidden=false;cancel.hidden=false;
      status.textContent='Preparación #'+d.sale_id+' por '+d.amount_cop+' COP. Confirma explícitamente solo tras verificar el efectivo.';
    });
  });
  confirm.addEventListener('click',()=>{
    if(!pending) return;
    const input={action:'confirm',...pending,confirm:true};
    void execute(input,d=>{
      pending=null;confirm.hidden=true;cancel.hidden=true;
      status.textContent='Cobro sintético registrado: '+d.receipt+' (no Wompi).';
      const label=create('p','Recibo y entradas de una sola visualización:');
      receipts.replaceChildren(label);
      for(const qr of d.tickets||[]) {
        const code=create('code',qr);code.style.overflowWrap='anywhere';receipts.append(code,document.createElement('hr'));
      }
    });
  });
  cancel.addEventListener('click',()=>{
    if(!pending) return;
    void execute({action:'cancel',...pending},()=>{
      pending=null;confirm.hidden=true;cancel.hidden=true;
      status.textContent='Preparación anulada sin boletas ni cobros.';
    });
  });
  closeForm.addEventListener('submit',e=>{
    e.preventDefault();if(!shiftId||pending) return;
    void execute({action:'close',shift_id:String(shiftId),closing_cash_cop:count.value},d=>{
      status.textContent='Cierre de prueba. Esperado: '+d.expected_cash_cop
        +' COP; diferencia: '+d.cash_difference_cop+' COP; Wompi excluido.';
      shiftId=null;sale.hidden=true;closeForm.hidden=true;form.hidden=false;
    });
  });
}
