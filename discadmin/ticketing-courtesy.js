/* T25: embedded in existing Boletería > Pedidos; backend owns every permission/limit. */
export function mount(panel) {
  if (panel.querySelector('[data-ticketing-courtesy]')) return;
  const section=document.createElement('section');
  section.dataset.ticketingCourtesy='';section.className='tk-courtesy';
  const heading=document.createElement('h3'); heading.textContent='Cortesías · solo personal autorizado';
  const note=document.createElement('p');
  note.textContent='Las cortesías no generan cobros. El QR se envía al correo del invitado cuando el servicio está habilitado.';
  const form=document.createElement('form');form.className='tk-form';
  const make=(name,label,type='text')=>{
    const wrap=document.createElement('div');wrap.className='tk-field';
    const title=document.createElement('label');title.htmlFor='tk-courtesy-'+name;title.textContent=label;
    const field=document.createElement(type==='select'?'select':'input');
    if(type!=='select')field.type=type;
    field.name=name;field.id=title.htmlFor;field.required=true;
    wrap.append(title,field);form.append(wrap);return field;
  };
  const event=make('event_id','Evento','select');
  const ticket=make('ticket_type_id','Boleta','select');
  make('recipient','Correo del invitado','email');make('reason','Motivo interno');
  const kind=document.createElement('select');kind.name='category';
  for(const [value,label] of [['artist','Artista'],['staff','Staff'],['press','Prensa'],['partner','Aliado']]) {
    const opt=document.createElement('option');opt.value=value;opt.textContent=label;kind.append(opt);
  }
  const label=document.createElement('label');label.textContent='Categoría';label.htmlFor='tk-courtesy-category';
  kind.id=label.htmlFor;
  const categoryField=document.createElement('div');categoryField.className='tk-field';
  categoryField.append(label,kind);form.append(categoryField);
  const submit=document.createElement('button');submit.type='submit';submit.textContent='Emitir cortesía';submit.className='tk-button is-primary';
  const status=document.createElement('p');status.setAttribute('role','status');status.dataset.courtesyStatus='';status.className='tk-status';
  status.textContent='Sin emisión.';
    const management=document.createElement('div');management.className='tk-form';
  const invite=document.createElement('input');invite.type='number';invite.min='1';invite.placeholder='Número de cortesía';
  invite.id='tk-courtesy-ref';invite.setAttribute('aria-label','Número de cortesía');
  const inviteField=document.createElement('div');inviteField.className='tk-field';
  const inviteLabel=document.createElement('label');inviteLabel.htmlFor=invite.id;inviteLabel.textContent='Número de cortesía';
  inviteField.append(inviteLabel,invite);
  const revoke=document.createElement('button');revoke.type='button';revoke.textContent='Revocar';
  const resend=document.createElement('button');resend.type='button';resend.textContent='Reenviar correo con QR';
  revoke.className='tk-button';resend.className='tk-button';management.append(inviteField,revoke,resend);
  let pendingIssue=null, busy=false;
  async function send(body) {
    if (busy) return;
    busy=true;
    status.textContent='Validando solicitud…';
    for(const button of [submit,revoke,resend])button.disabled=true;
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
      const id=Number(data.data.invite_id);
      if (!Number.isSafeInteger(id)||id<1) {
        status.textContent='No fue posible verificar la cortesía. Consulta el estado antes de reintentar.';
        return;
      }
      if (body.action==='issue') {
        pendingIssue=null;
        invite.value=String(id);
        status.textContent=data.data.replayed
          ? 'La cortesía ya estaba registrada; no se creó otra entrada ni otro correo.'
          : data.data.queued===true
            ? 'Cortesía creada sin cobro. El correo con el QR quedó en cola de envío.'
            : 'La cortesía no pudo confirmarse; consulta el estado antes de reintentar.';
      } else {
        status.textContent=body.action==='resend'
          ? 'Nuevo QR preparado para envío. El enlace anterior ya no es válido.'
          : 'Cortesía revocada y cupo liberado. El QR anterior ya no permite ingresar.';
      }
    } catch {
      if (section.isConnected) status.textContent=body.action==='issue'
        ? 'Sin confirmación del servidor. Reintenta con la misma clave.'
        : 'Sin confirmación del servidor. El QR visible se conserva.';
    } finally {busy=false;for(const button of [submit,revoke,resend])button.disabled=false;}
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
    if(!Number.isSafeInteger(inviteId)||inviteId<1){status.textContent='Indica un número de cortesía válido.';return;}
    void send({action,invite_id:inviteId});
  });
  form.append(submit);section.append(heading,note,form,management,status);panel.append(section);

  const option=(value,text)=>{const item=document.createElement('option');
    item.value=String(value);item.textContent=text;return item;};
  let catalog=[];
  const fillTickets=()=>{
    const selected=catalog.find(item=>String(item.id)===event.value);
    ticket.replaceChildren(option('','Elige una boleta'),
      ...(selected?.ticket_types||[]).map(item=>option(item.id,item.name)));
  };
  event.addEventListener('change',fillTickets);
  async function loadCatalog() {
    event.replaceChildren(option('','Cargando eventos…'));
    ticket.replaceChildren(option('','Elige un evento primero'));
    event.disabled=true;ticket.disabled=true;
    try {
      const response=await fetch('/api/ticketing/admin-promos.php?catalog=1',{
        credentials:'same-origin',cache:'no-store'});
      const data=await response.json().catch(()=>({}));
      if(!response.ok||!data.ok||!Array.isArray(data.events))throw Error('NO_CATALOG');
      if(!section.isConnected)return;
      catalog=data.events;
      event.replaceChildren(option('','Elige un evento'),...catalog.map(item=>option(item.id,item.title)));
      event.disabled=false;
      if(catalog.length===1)event.value=String(catalog[0].id);
      fillTickets();ticket.disabled=false;
    } catch {
      if(section.isConnected) {
        event.replaceChildren(option('','Eventos no disponibles'));
        status.textContent='No se pudieron cargar los eventos. No se puede emitir una cortesía.';
      }
    }
  }
  void loadCatalog();
}
