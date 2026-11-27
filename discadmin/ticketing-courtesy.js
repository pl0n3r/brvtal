/* Cortesías: canonical owner-only workspace; the server owns permissions and state. */
async function read(params) {
  const response=await fetch('/api/ticketing/admin-courtesy-list.php?'+params,{credentials:'same-origin',cache:'no-store'});
  const body=await response.json();
  if(!response.ok||body.ok!==true||!Array.isArray(body.data?.rows)||!Array.isArray(body.data?.catalog)){
    throw new Error('NO_LIST');
  }
  return body.data;
}

function issueMessage(result) {
  if(result.replayed){
    return result.needs_resend===true
      ? 'La cortesía existe, pero no tiene un envío confirmado. Usa Reenviar correo con QR.'
      : 'La cortesía ya estaba registrada; no se creó otra entrada ni otro correo.';
  }
  return result.queued===true
    ? 'Cortesía creada sin cobro. El correo con el QR quedó en cola de envío.'
    : 'La cortesía no pudo confirmarse; consulta el estado antes de reintentar.';
}

function errorMessage(code) {
  if(code==='MAIL_NOT_READY')return 'El correo aún no está configurado para este destinatario. No se emitió la cortesía.';
  if(code==='COURTESY_SCHEMA_MISSING')return 'La base de datos de cortesías no está preparada. No se emitió la cortesía.';
  return 'No autorizada o cupo no disponible. No se presume emisión.';
}

function actionMessage(body,result,id) {
  const resend=body.action==='resend';
  if(id!==body.invite_id||result.status!==(resend?'queued':'revoked')||(resend&&result.queued!==true)){
    return 'Respuesta no confirmada. Consulta la invitación antes de reintentar.';
  }
  return resend
    ? 'Nuevo QR preparado para envío. El enlace anterior ya no es válido.'
    : 'Cortesía revocada y cupo liberado. El QR anterior ya no permite ingresar.';
}

export function mount(panel, active = () => panel.isConnected, csrf = '') {
  if (!csrf || !active() || panel.querySelector('[data-ticketing-courtesy]')) return;
  panel.replaceChildren();
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
  const list=document.createElement('section');list.setAttribute('aria-label','Invitaciones');
  const filters=document.createElement('form');filters.className='tk-form';
  const option=(value,text)=>{const item=document.createElement('option');
    item.value=String(value);item.textContent=text;return item;};
  const field=(name,label,choices)=>{
    const wrap=document.createElement('label');wrap.className='tk-field';wrap.textContent=label;
    const input=document.createElement(choices?'select':'input');input.name=name;
    if(choices)input.append(option('','Todos'),...choices.map(([v,t])=>option(v,t)));
    else {input.type='number';input.min='1';input.step='1';}
    wrap.append(input);filters.append(wrap);return input;
  };
  const states=[['issued','Emitida'],['used','Usada'],['revoked','Revocada'],['refunded','Reembolsada'],['unknown','Desconocida']];
  const categories=[['artist','Artista'],['staff','Staff'],['press','Prensa'],['partner','Aliado']];
  const mail={queued:'En cola',accepted:'Aceptado por proveedor',delivered:'Entregado',unknown:'Sin evidencia'};
  const filterEvent=field('event_id','Filtrar evento',[]), filterType=field('ticket_type_id','Filtrar boleta',[]);
  field('category','Filtrar categoría',categories);field('status','Filtrar estado',states);field('invite_id','Número de cortesía');
  const button=(label,handler)=>{const b=document.createElement('button');b.type='button';b.className='tk-button';
    b.textContent=label;
    if(handler){b.addEventListener('click',handler);}
    return b;
  };
  const search=button('Consultar invitaciones');search.type='submit';filters.append(search);
  const listStatus=document.createElement('p');listStatus.className='tk-status';listStatus.setAttribute('role','status');
  const results=document.createElement('div');results.className='tk-table-wrap';
  const detail=document.createElement('div');detail.setAttribute('aria-live','polite');detail.tabIndex=-1;
  const more=button('Cargar más',()=>void load(true));more.hidden=true;
  let catalog=[],cursor=null,request=0,query='',pendingIssue=null,busy=false,ready=false;
  const alive=()=>active()&&section.isConnected;
  const labelFor=(pairs,value)=>pairs.find(([v])=>v===value)?.[1]||'Desconocido';
  const summary=row=>['Cortesía #'+row.id,row.event_title,row.ticket_type_name,labelFor(categories,row.category),
    row.created_at_utc+' UTC',labelFor(states,row.status),mail[row.mail_state]||mail.unknown,
    'Reenvíos: '+row.resend_requests,row.incident?'Incidencia de estado':''].filter(Boolean).join(' · ');
  const sync=()=>{
    for(const el of section.querySelectorAll('button'))el.disabled=busy;
    submit.disabled=busy||!ready||!catalog.length;event.disabled=busy||!ready;ticket.disabled=busy||!ready;
  };
  function setOptions(select,items,placeholder) {
    const value=select.value;
    select.replaceChildren(option('',placeholder),...items.map(([v,t])=>option(v,t)));
    if([...select.options].some(o=>o.value===value))select.value=value;
  }
  const eventChoices=()=>[...new Map(catalog.map(row=>[row.event_id,row.event_title]))];
  const typeChoices=id=>catalog.filter(row=>!id||String(row.event_id)===id)
    .map(row=>[row.ticket_type_id,row.ticket_type_name]);
  function fillTickets(){setOptions(ticket,event.value?typeChoices(event.value):[],'Elige una boleta');}
  function fillFilters(){setOptions(filterType,typeChoices(filterEvent.value),'Todas');}
  function setCatalog(rows){
    catalog=rows;setOptions(event,eventChoices(),'Elige un evento');
    if(!event.value&&eventChoices().length===1)event.value=String(eventChoices()[0][0]);
    fillTickets();setOptions(filterEvent,eventChoices(),'Todos');fillFilters();ready=true;sync();
  }
  event.addEventListener('change',fillTickets);filterEvent.addEventListener('change',fillFilters);
  const eligible=row=>row?.status==='issued'&&row.invite_status==='issued'&&row.incident===false;
  async function rowAction(row,action){
    if(busy||!alive())return;
    if(action!=='detail'&&!window.confirm(action==='revoke'?'Revocar esta cortesía e invalidar su QR anterior?':
      'Generar un nuevo QR e invalidar el enlace anterior?'))return;
    busy=true;++request;sync();more.hidden=true;
    try {
      const data=await read(new URLSearchParams({invite_id:String(row.id)}));
      if(!alive())return;
      const current=data.rows.find(item=>item.id===row.id);
      if(!current)throw new Error('NO_INVITE');
      if(action==='detail'){detail.textContent=summary(current);return;}
      if(!eligible(current)||current.resend_requests!==row.resend_requests){
        status.textContent='La invitación cambió. Revisa su estado antes de actuar.';return;
      }
      await send({action,invite_id:row.id},true);
    }catch{if(alive())status.textContent='No se pudo verificar la invitación. No reintentes sin consultar su estado.';}
    finally{busy=false;if(alive()){sync();if(action!=='detail'){detail.replaceChildren();await load(false);search.focus();}else{more.hidden=cursor===null;detail.focus();}}}
  }
  function renderRows(rows,append){
    if(!append)results.replaceChildren();
    if(!rows.length&&!append){results.textContent='No hay invitaciones para estos filtros.';return;}
    let table=results.querySelector('table');
    const headings=['Referencia','Evento y boleta','Categoría','Estado','Correo','Acciones'];
    if(!table){
      table=document.createElement('table');table.className='tk-table';
      const header=table.createTHead().insertRow();
      for(const name of headings){const th=document.createElement('th');th.scope='col';th.textContent=name;header.append(th);}
      table.createTBody();results.append(table);
    }
    for(const row of rows){
      const tr=table.tBodies[0].insertRow();tr.dataset.courtesyInvite=String(row.id);
      ['#'+row.id,row.event_title+' · '+row.ticket_type_name,labelFor(categories,row.category),
        labelFor(states,row.status)+(row.incident?' · Incidencia de estado':''),mail[row.mail_state]||mail.unknown]
        .forEach((value,index)=>{const td=tr.insertCell();td.dataset.label=headings[index];td.textContent=value;});
      const actions=tr.insertCell();actions.dataset.label='Acciones';
      const group=document.createElement('div');group.className='tk-entry-actions';
      group.append(button('Ver detalle',()=>void rowAction(row,'detail')));
      if(eligible(row)){
        group.append(button('Reenviar correo con QR',()=>void rowAction(row,'resend')),
          button('Revocar',()=>void rowAction(row,'revoke')));
      }
      actions.append(group);
    }
  }
  async function load(append=false){
    if(busy||!alive())return;
    const token=++request;more.hidden=true;
    if(!append){cursor=null;results.replaceChildren();
      query=new URLSearchParams([...new FormData(filters)].filter(([,v])=>v!=='')).toString();}
    const params=new URLSearchParams(query);
    if(append&&cursor!==null)params.set('cursor',String(cursor));
    listStatus.textContent='Consultando invitaciones…';
    try{
      const data=await read(params);
      if(!alive()||token!==request)return;
      setCatalog(data.catalog);
      renderRows(data.rows,append);
      cursor=data.has_more&&Number.isSafeInteger(data.next_cursor)&&data.next_cursor>0?data.next_cursor:null;
      more.hidden=cursor===null;
      listStatus.textContent=states.map(([v,t])=>t+': '+(data.totals?.[v]??0)).join(' · ');
    }catch{
      if(!alive()||token!==request)return;
      ready=false;results.replaceChildren();sync();
      listStatus.textContent='No se pudieron consultar las invitaciones. Vuelve a consultar.';
    }
  }
  filters.addEventListener('submit',e=>{e.preventDefault();detail.replaceChildren();void load();});
  const listHeading=document.createElement('h3');listHeading.textContent='Invitaciones registradas';
  list.append(listHeading,filters,listStatus,results,more,detail);
  function applyWriteResult(body,ok,data) {
    if(!ok||data.ok!==true||!data.data){
      status.textContent=errorMessage(data.error);return;
    }
    const id=Number(data.data.invite_id);
    if(!Number.isSafeInteger(id)||id<1){
      status.textContent='No fue posible verificar la cortesía. Consulta el estado antes de reintentar.';return;
    }
    if(body.action!=='issue'){
      status.textContent=actionMessage(body,data.data,id);return;
    }
    pendingIssue=null;
    filters.reset();fillFilters();filters.elements.namedItem('invite_id').value=String(id);
    status.textContent=issueMessage(data.data);
  }
  async function send(body, locked=false) {
    if (!alive() || (busy&&!locked) || (!locked&&!ready)) return;
    if(!locked){busy=true;++request;more.hidden=true;}
    sync();
    status.textContent='Validando solicitud…';

    try {
      const response=await fetch('/api/ticketing/admin-courtesy.php',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':
          csrf},
        body:JSON.stringify(body)
      });
      const data=await response.json();
      if (!alive()) return;
      applyWriteResult(body,response.ok,data);
    } catch {
      if (alive()) status.textContent=body.action==='issue'
        ? 'Sin confirmación del servidor. Reintenta con la misma clave.'
        : 'No se pudo confirmar la operación. Consulta el estado antes de reintentar.';
    } finally {
      if(!locked){busy=false;if(alive()){sync();await load(false);}}
    }
  }
  // The fingerprint is compared on submit: editing back to the same values preserves retries.
  form.addEventListener('submit',e=>{
    e.preventDefault();
    if (busy || !ready) return;
    const values=Object.fromEntries(new FormData(form));
    const eventId=Number(values.event_id),typeId=Number(values.ticket_type_id);
    if(!Number.isSafeInteger(eventId)||eventId<1||!Number.isSafeInteger(typeId)||typeId<1)return;
    const recipient=String(values.recipient).trim().toLowerCase();
    const category=String(values.category),reason=String(values.reason).trim();
    const fingerprint=JSON.stringify([eventId,typeId,recipient,category,reason]);
    if (!pendingIssue||pendingIssue.fingerprint!==fingerprint) {
      pendingIssue={fingerprint,key:crypto.randomUUID()};
    }
    void send({action:'issue',event_id:eventId,ticket_type_id:typeId,
      recipient,category,reason,idempotency_key:pendingIssue.key});
  });
  form.append(submit);section.append(heading,note,form,status,list);panel.append(section);
  sync();void load();
}
