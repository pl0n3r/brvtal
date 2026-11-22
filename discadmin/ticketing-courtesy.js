/* T25: embedded in existing Boletería > Pedidos; backend owns every permission/limit. */
export function mount(panel) {
  if (panel.querySelector('[data-ticketing-courtesy]')) return;
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
  const qr=document.createElement('output');qr.dataset.courtesyQr='';qr.hidden=true;
  const qrCaption=document.createElement('p');qrCaption.dataset.courtesyQrOwner='';
  const clearQr=document.createElement('button');clearQr.type='button';
  clearQr.textContent='Ocultar QR';clearQr.hidden=true;
  const management=document.createElement('div');
  const invite=document.createElement('input');invite.type='number';invite.min='1';invite.placeholder='ID de cortesía';
  invite.setAttribute('aria-label','ID de cortesía');
  const revoke=document.createElement('button');revoke.type='button';revoke.textContent='Revocar';
  const resend=document.createElement('button');resend.type='button';resend.textContent='Registrar reenvío (sin envío)';
  management.append(invite,revoke,resend);
  let displayedInvite=null, pendingIssue=null, busy=false;
  function concealQr() {
    qr.textContent='';qr.hidden=true;qrCaption.textContent='';
    clearQr.hidden=true;displayedInvite=null;
  }
  clearQr.addEventListener('click',concealQr);
  async function send(body) {
    if (busy) return;
    busy=true;
    status.textContent='Validando solicitud…';
    try {
      const response=await fetch('/api/ticketing/admin-courtesy.php',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':
          panel.querySelector('[data-ticketing-records]')?.dataset.ticketingCsrf||''},
        body:JSON.stringify(body)
      });
      const data=await response.json();
      if (!section.isConnected) return;
      if (!response.ok||data.ok!==true||!data.data) {
        status.textContent='No autorizada o cupo no disponible. No se presume emisión.';
        return;
      }
      if (body.action==='issue') {
        pendingIssue=null;
        const id=Number(data.data.invite_id);
        const validId=Number.isSafeInteger(id)&&id>0;
        const issued=data.data.replayed===false&&validId
          &&typeof data.data.qr==='string'&&data.data.qr.startsWith('BRVTAL:T1:');
        if (issued) {
          concealQr();
          displayedInvite=id;
          qr.textContent=data.data.qr;qr.hidden=false;clearQr.hidden=false;
          qrCaption.textContent='QR de la cortesía #'+id+'. Entrega única; ocúltalo al terminar.';
        } else if (validId&&displayedInvite!==id) {
          // A replay cannot recover a secret from a previously issued invitation.
          concealQr();
        }
        status.textContent=data.data.replayed
          ? 'Invitación existente; el reintento no recupera ni genera otro QR.'
          : issued ? 'Operación registrada. No hubo pagos ni correos enviados.'
            : 'Operación registrada sin QR nuevo; no se presume un secreto recuperable.';
      } else {
        if (body.action==='revoke'&&displayedInvite===Number(body.invite_id)) concealQr();
        status.textContent=body.action==='resend'
          ? 'Reenvío registrado sin enviar correos.':'Revocación registrada.';
      }
    } catch {
      if (section.isConnected) status.textContent=body.action==='issue'
        ? 'Sin confirmación del servidor. Reintenta con la misma clave.'
        : 'Sin confirmación del servidor. El QR visible se conserva.';
    } finally {busy=false;}
  }
  form.addEventListener('input',()=>{pendingIssue=null;});
  form.addEventListener('change',()=>{pendingIssue=null;});
  form.addEventListener('submit',e=>{
    e.preventDefault();
    if (busy) return;
    const values=Object.fromEntries(new FormData(form));
    const eventId=Number(values.event_id),typeId=Number(values.ticket_type_id);
    const recipient=String(values.recipient).trim().toLowerCase();
    const category=String(values.category),reason=String(values.reason).trim();
    const fingerprint=JSON.stringify([eventId,typeId,recipient,category,reason]);
    if (!pendingIssue||pendingIssue.fingerprint!==fingerprint) {
      pendingIssue={fingerprint,key:crypto.randomUUID()};
    }
    void send({action:'issue',event_id:eventId,ticket_type_id:typeId,
      recipient,category,reason,idempotency_key:pendingIssue.key});
  });
  for(const [button,action] of [[revoke,'revoke'],[resend,'resend']]) button.addEventListener('click',()=>{
    const inviteId=Number(invite.value);
    if(!Number.isSafeInteger(inviteId)||inviteId<1){status.textContent='Indica un ID válido.';return;}
    void send({action,invite_id:inviteId});
  });
  form.append(submit);section.append(heading,note,form,management,status,qrCaption,qr,clearQr);panel.append(section);
}
