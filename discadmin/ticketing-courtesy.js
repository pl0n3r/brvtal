/* T25: embedded in existing Boletería > Pedidos; backend owns every permission/limit. */
export function mount(panel) {
  const section=document.createElement('section');
  section.dataset.ticketingCourtesy='';
  const heading=document.createElement('h3'); heading.textContent='Cortesías · solo personal autorizado';
  const note=document.createElement('p');
  note.textContent='La operación no cobra ni envía correos. Entrega el QR una sola vez y no lo publiques.';
  const form=document.createElement('form');
  const make=(name,label,type='text')=>{
    const wrap=document.createElement('label');wrap.textContent=label+' ';
    const field=document.createElement('input');field.name=name;field.type=type;field.required=true;
    if(type==='number')field.min='1';
    wrap.append(field);form.append(wrap);return field;
  };
  make('event_id','Evento','number');make('ticket_type_id','Tipo de boleta','number');
  make('recipient','Identificador del invitado');make('reason','Motivo interno');
  const kind=document.createElement('select');kind.name='category';
  for(const [value,label] of [['artist','Artista'],['staff','Staff'],['press','Prensa'],['partner','Aliado']]) {
    const opt=document.createElement('option');opt.value=value;opt.textContent=label;kind.append(opt);
  }
  const label=document.createElement('label');label.textContent='Categoría ';label.append(kind);form.append(label);
  const submit=document.createElement('button');submit.type='submit';submit.textContent='Emitir cortesía';
  const status=document.createElement('p');status.setAttribute('role','status');status.dataset.courtesyStatus='';
  status.textContent='Sin emisión.';
  const qr=document.createElement('output');qr.dataset.courtesyQr='';
  const management=document.createElement('div');
  const id=make.bind(null); // Ordinary admin actions use a separate, explicit identifier.
  const invite=document.createElement('input');invite.type='number';invite.min='1';invite.placeholder='ID de cortesía';
  invite.setAttribute('aria-label','ID de cortesía');
  const revoke=document.createElement('button');revoke.type='button';revoke.textContent='Revocar';
  const resend=document.createElement('button');resend.type='button';resend.textContent='Registrar reenvío (sin envío)';
  management.append(invite,revoke,resend);
  async function send(body) {
    status.textContent='Validando solicitud…';qr.textContent='';
    try {
      const response=await fetch('/api/ticketing/admin-courtesy.php',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':
          panel.querySelector('[data-ticketing-records]')?.dataset.ticketingCsrf||''},
        body:JSON.stringify(body)
      });
      const data=await response.json();
      if (!section.isConnected) return;
      if (!response.ok||data.ok!==true) {status.textContent='No autorizada o cupo no disponible. No se presume emisión.';return;}
      status.textContent=data.data.replayed?'Invitación existente, no se genera otro QR.':
        'Operación registrada. No hubo pagos ni correos enviados.';
      if(typeof data.data.qr==='string')qr.textContent=data.data.qr;
    } catch {if(section.isConnected) status.textContent='Sin confirmación del servidor. Reintenta con la misma clave.';}
  }
  form.addEventListener('submit',e=>{
    e.preventDefault();
    const values=Object.fromEntries(new FormData(form));
    void send({action:'issue',event_id:Number(values.event_id),
      ticket_type_id:Number(values.ticket_type_id),recipient:values.recipient,
      category:values.category,reason:values.reason,idempotency_key:crypto.randomUUID()});
  });
  for(const [button,action] of [[revoke,'revoke'],[resend,'resend']]) button.addEventListener('click',()=>{
    const inviteId=Number(invite.value);
    if(!Number.isSafeInteger(inviteId)||inviteId<1){status.textContent='Indica un ID válido.';return;}
    void send({action,invite_id:inviteId});
  });
  form.append(submit);section.append(heading,note,form,management,status,qr);panel.append(section);
}
