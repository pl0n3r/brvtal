/* T18b: private records plus synthetic-only audited actions; server enforces RBAC. */
export async function mount(panel, initialKind = 'orders') {
  const active = () => {
    const module = panel.closest('[data-admin-module="ticketing"]');
    const tab = module?.querySelector('[data-ticketing-tab="' + initialKind + '"]');
    return panel.isConnected && !!module && !!tab && tab.getAttribute('aria-selected') === 'true';
  };
  const error = message => { if (active()) panel.replaceChildren(document.createTextNode(message)); };
  try {
    const response = await fetch('/discadmin/ticketing-orders.php?kind=' + encodeURIComponent(initialKind), {
      credentials: 'same-origin', cache: 'no-store', headers: {'X-BRVTAL-ADMIN-FRAGMENT':'1'}
    });
    if (response.status === 403) return error('Sin permiso para consultar pedidos o compradores.');
    if (!response.ok) throw Error('FRAGMENT');
    const fragment = new DOMParser().parseFromString(await response.text(), 'text/html')
      .querySelector('[data-ticketing-records]');
    if (!fragment || !active()) return;
    panel.replaceChildren(document.importNode(fragment, true));
  } catch { return error('No se pudo cargar Pedidos y Compradores.'); }
  const form = panel.querySelector('[data-ticketing-records-form]');
  const results = panel.querySelector('[data-ticketing-records-results]');
  const status = panel.querySelector('[data-ticketing-records-status]');
  const detail = panel.querySelector('[data-ticketing-records-detail]');
  // T25 mounts only in the existing Orders workspace; no second admin shell.
  if (initialKind === 'orders' && ['superadmin','root','owner','platform_owner']
    .includes(panel.closest('[data-admin-module="ticketing"]')?.dataset.ticketingRole)) {
    import('/discadmin/ticketing-courtesy.js' + new URL(import.meta.url).search)
      .then(({mount}) => {if (active()) mount(panel);})
      .catch(() => {if (active()) status.textContent='Cortesías no disponibles.';});
  }
  const more = panel.querySelector('[data-ticketing-records-more]');
  const kind = form.elements.namedItem('kind');
  const state = form.elements.namedItem('status');
  const labelState = {
    draft:'Borrador', pending:'Pendiente', paid:'Pagado', failed:'Fallido',
    cancelled:'Cancelado', expired:'Expirado', refunded:'Reembolsado',
    approved:'Aprobado', declined:'Rechazado', rejected:'Rechazado',
    sent:'Enviado', delivered:'Entregado', queued:'En cola',
    no_delivery:'Sin envío', issued:'Emitida', revoked:'Anulada',
    active:'Activo', blocked:'Bloqueado', guest:'Invitado',
    processing:'En proceso', error:'Error', undelivered:'Sin entregar'
  };
  const displayState = code => {
    if (code === null || code === undefined || code === '') return 'Sin registro';
    const key = String(code).trim().toLowerCase();
    return labelState[key] || 'Estado no reconocido';
  };
  const bogotaDate = value => {
    if (!value) return 'Sin fecha';
    const source=String(value).trim();
    const parsed = /^\d{4}-\d{2}-\d{2}$/.test(source)
      ? new Date(source+'T12:00:00Z')
      : /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/.test(source)
        ? new Date(source.replace(' ','T')+'Z') : new Date(source);
    if (!Number.isFinite(parsed.getTime())) return 'Fecha no disponible';
    return new Intl.DateTimeFormat('es-CO', {
      timeZone:'America/Bogota',day:'2-digit',month:'short',year:'numeric',
      ...(source.includes(':')?{hour:'2-digit',minute:'2-digit',hour12:false}:{})
    }).format(parsed);
  };
  const statusOptions = {
    orders: [['','Todos'],['draft','Borrador'],['pending','Pendiente'],['paid','Pagado'],
      ['failed','Fallido'],['cancelled','Cancelado'],['expired','Expirado'],['refunded','Reembolsado']],
    buyers: [['','Todos'],['guest','Invitado'],['active','Activo'],['blocked','Bloqueado']]
  };
  function options() {
    state.replaceChildren(...statusOptions[kind.value].map(([value, label]) => {
      const option = document.createElement('option');
      option.value = value; option.textContent = label; return option;
    }));
  }
  kind.value = initialKind === 'buyers' ? 'buyers' : 'orders';
  options();
  let cursor = null, generation = 0;
  const params = () => new URLSearchParams(new FormData(form));
  const cell = (tr, value, label = '') => {
    const td = tr.insertCell();
    if (label) td.dataset.label = label;
    td.textContent = value === null || value === undefined || value === '' ? 'Sin registro' : String(value);
    return td;
  };
  const money = n => Number(n || 0).toLocaleString('es-CO') + ' COP';
  function rowsTable(rows, append) {
    if (!append) results.replaceChildren();
    if (!rows.length) {
      if (!append) results.textContent = 'No hay resultados para estos filtros.';
      return;
    }
    const headings = kind.value === 'orders'
      ? ['Pedido','Evento','Comprador','Estado','Total','Unidades','Pagos aprobados','Pago reciente','Correo','Detalle']
      : ['Comprador','Correo','Estado','Pedidos','Pagado'];
    let table = results.querySelector('table');
    if (!table) {
      table = document.createElement('table');
      table.className = 'ticketing-records-table tk-table';
      const head = table.createTHead().insertRow();
      for (const title of headings) {
        const th = document.createElement('th'); th.scope = 'col'; th.textContent = title; head.appendChild(th);
      }
      results.append(table);
    }
    const body = table.tBodies[0] || table.createTBody();
    for (const row of rows) {
      const tr = body.insertRow();
      if (kind.value === 'orders') {
        const values = [row.id,row.event_id,row.email,displayState(row.status),
          money(row.total_cop),row.units,row.approvals,
          displayState(row.last_payment_state),displayState(row.mail_state)];
        values.forEach((value,index) => cell(tr,value,headings[index]));
        const actionCell = tr.insertCell();
        actionCell.dataset.label = 'Detalle';
        const action = document.createElement('button');
        action.type = 'button'; action.className = 'tk-button';
        action.textContent = 'Detalle #' + row.id;
        action.addEventListener('click', () => void loadDetail(row.id));
        actionCell.append(action);
        if ((row.status === 'paid' && Number(row.approvals) !== 1) || Number(row.approvals) > 1) {
          tr.dataset.ticketingAnomaly = '1';
          const warning = document.createElement('span');
          warning.className = 'tk-records-anomaly';
          warning.textContent = 'Atención: los pagos aprobados no coinciden con el pedido. Revisar.';
          tr.cells[3].append(warning);
        }
      } else {
        [row.id,row.email,displayState(row.status),row.order_count,money(row.paid_total_cop)]
          .forEach((value,index) => cell(tr,value,headings[index]));
      }
    }
  }

  // #1979: all three commands use the protected admin endpoint. Never use
  // HTML interpolation for order data or record a successful send before worker receipt.
  function addOrderActions(header, orderId, orderStatus) {
    const wrapper=document.createElement('div');
    wrapper.className='tk-order-actions';
    wrapper.dataset.ticketingActions='';
    const menu=document.createElement('details');
    menu.className='tk-order-menu';
    const summary=document.createElement('summary');
    summary.textContent='Más acciones';
    menu.append(summary);
    const dialog=document.createElement('dialog');
    dialog.className='tk-entry-dialog tk-order-dialog';
    const dialogTitle=document.createElement('h3');
    const dialogText=document.createElement('p');
    const noteLabel=document.createElement('label');
    noteLabel.textContent='Nota interna, sin datos personales';
    const note=document.createElement('textarea');
    note.dataset.ticketingNote='';
    note.rows=3; note.maxLength=280; note.autocomplete='off';
    noteLabel.append(note);
    const feedback=document.createElement('p');
    feedback.dataset.ticketingActionStatus='';
    feedback.setAttribute('role','status');
    feedback.setAttribute('aria-live','polite');
    const controls=document.createElement('div');
    controls.className='tk-actions';
    const back=document.createElement('button');
    back.type='button'; back.className='tk-button'; back.textContent='Volver';
    const confirm=document.createElement('button');
    confirm.type='button'; confirm.className='tk-button is-primary';
    confirm.dataset.ticketingConfirm='';
    controls.append(back,confirm);
    dialog.append(dialogTitle,dialogText,noteLabel,feedback,controls);
    let request=null;
    const descriptions={
      note:['Agregar nota','Se guardará con tu identidad y fecha en el historial del pedido.'],
      resend:['Reenviar boletas','Se pondrá en cola otro correo con los mismos QR, sin emitir boletas nuevas.'],
      cancel_pending:['Cancelar pedido pendiente','Se liberará su inventario. No se puede cancelar una compra pagada.']
    };
    function open(action) {
      if(!descriptions[action] || !active()) return;
      if(action==='resend' && orderStatus!=='paid') return;
      if(action==='cancel_pending' && !['draft','pending'].includes(orderStatus)) return;
      request=null;
      dialogTitle.textContent=descriptions[action][0];
      dialogText.textContent=descriptions[action][1];
      noteLabel.hidden=action!=='note';
      note.value='';
      feedback.textContent='';
      confirm.disabled=false;
      confirm.textContent='Confirmar '+(action==='note'?'nota':action==='resend'?'reenvío':'cancelación');
      dialog.dataset.action=action;
      menu.open=false;
      dialog.showModal();
      if(action==='note') note.focus(); else confirm.focus();
    }
    const commands=[['note','Nota interna'],['resend','Reenviar boletas'],
      ['cancel_pending','Cancelar pedido pendiente']];
    for(const [action,label] of commands) {
      if(action==='resend' && orderStatus!=='paid') continue;
      if(action==='cancel_pending' && !['draft','pending'].includes(orderStatus)) continue;
      const button=document.createElement('button');
      button.type='button';button.className='tk-button';
      button.textContent=label;button.dataset.orderAction=action;
      button.addEventListener('click',()=>open(action));
      menu.append(button);
    }
    back.addEventListener('click',()=>dialog.close());
    dialog.addEventListener('close',()=>{request=null;});
    confirm.addEventListener('click',async()=>{
      const action=dialog.dataset.action;
      const value=note.value.trim();
      if(action==='note' && (value.length<3 || value.length>280)) {
        feedback.textContent='La nota debe tener entre 3 y 280 caracteres.';
        note.focus();return;
      }
      if(!request) request={order_id:String(orderId),action,
        idempotency_key:globalThis.crypto.randomUUID(),
        note:action==='note'?value:''};
      confirm.disabled=true;
      feedback.textContent='Guardando la acción…';
      try {
        const response=await fetch('/api/ticketing/admin-actions.php',{
          method:'POST',credentials:'same-origin',cache:'no-store',
          headers:{'Content-Type':'application/json','X-CSRF-Token':
            panel.querySelector('[data-ticketing-records]')?.dataset.ticketingCsrf || ''},
          body:JSON.stringify(request)
        });
        const payload=await response.json().catch(()=>null);
        if(!active()) return;
        if(response.ok && payload?.ok) {
          dialog.close();
          status.textContent=action==='note'?'Nota guardada en el historial.'
            :action==='resend'?'Reenvío en cola; la entrega depende del servicio de correo.'
            :'Pedido cancelado e inventario liberado.';
          await loadDetail(orderId);
          return;
        }
        const explanations={
          FORBIDDEN:'Sin permisos para esta operación.',
          ORDER_NOT_PENDING:'El pedido ya no está pendiente. No se canceló.',
          PAYMENT_RECONCILIATION_REQUIRED:'Hay un pago en curso o aprobado. Requiere conciliación.',
          ORDER_NOT_PAID:'Solo se pueden reenviar boletas de compras pagadas.',
          TICKETS_NOT_ISSUED:'Las boletas aún no se han emitido.',
          MAIL_PAYLOAD_NOT_RECOVERABLE:'No se puede reenviar el correo cifrado original.',
          MAIL_NOT_ENABLED:'El modo de correo actual no permite reenviar.',
          MAIL_RATE_LIMITED:'Se alcanzó el límite de correos. Reintenta más adelante.',
          IDEMPOTENCY_CONFLICT:'La solicitud cambió. Cierra el diálogo y vuelve a intentarlo.'
        };
        feedback.textContent=explanations[payload?.error]||
          'No se pudo registrar. Reintenta la misma solicitud.';
      }catch {
        if(active()) feedback.textContent='Sin conexión. No se presume éxito; reintenta con la misma clave.';
      }finally{confirm.disabled=false;}
    });
    wrapper.append(menu,dialog);
    header.append(wrapper);
    return open;
  }

  function renderOrderTimeline(box,info,openAction,id) {
    const issue=info.issue||{};
    const order=info.order||{};
    const paid=order.status==='paid';
    const rows=info.mail||[];
    const mail=rows[0]||{};
    const entries=info.entries||{};
    const payment=(info.payments||[]).find(p=>p.status==='approved');
    const issued=issue.state==='done'||issue.state==='issued';
    const sent=['accepted','delivered'].includes(mail.delivery_state);
    const used=Number(entries.used||0)>0;
    const steps=[
      ['Creado',order.created_at_utc,!!order.created_at_utc],
      ['Pagado',paid?(payment?.created_at_utc||order.created_at_utc):null,paid],
      ['Boletas emitidas',issued?(entries.first_issued_at||order.created_at_utc):null,issued],
      ['Correo con QR',sent?(mail.state_at||mail.created_at):null,sent],
      ['Entrada',used?entries.last_used_at:null,used]
    ];
    const stalled=issue.state==='failed'?2:['failed','bounced'].includes(mail.delivery_state)?3:
      order.status==='failed'?1:-1;
    const panel=document.createElement('section');panel.className='tk-order-journey';
    panel.dataset.ticketingOrderJourney='';
    const h=document.createElement('h4');h.textContent='Recorrido del pedido';
    const list=document.createElement('ol');list.className='tk-order-timeline';
    for(const [index,step] of steps.entries()){
      const li=document.createElement('li');
      li.className='tk-order-step '+(step[2]?'is-done':stalled===index?'is-stalled':'is-pending');
      const name=document.createElement('strong');name.textContent=step[0];
      const state=document.createElement('span');
      state.textContent=step[2]?bogotaDate(step[1]):stalled===index?'Requiere atención':'Pendiente';
      li.append(name,state);list.append(li);
    }
    panel.append(h,list);
    if(stalled!==-1){
      const alert=document.createElement('div');
      alert.className='tk-order-stalled';alert.setAttribute('role','alert');
      const p=document.createElement('p');
      p.textContent=stalled===2?'La emisión de boletas falló. Programa un nuevo intento.'
        :stalled===3?'El último correo no se entregó. Puedes reenviar las mismas boletas.'
        :'El pago no se completó. Comprueba su estado antes de actuar.';
      alert.append(p);
      if(stalled===2) addIssueRetry(alert,id,issue);
      if(stalled===3 && paid){
        const button=document.createElement('button');
        button.type='button';button.className='tk-button is-primary';
        button.textContent='Reenviar boletas';
        button.addEventListener('click',()=>openAction('resend'));
        alert.append(button);
      }
      panel.append(alert);
    }
    // Issuance and mail delivery are independent. Keep a visible issuance
    // receipt even when the email has failed, without enabling a retry on 'done'.
    if(stalled!==2 && ['pending','processing','done','issued'].includes(issue.state)) {
      addIssueRetry(panel,id,issue);
    }
    box.append(panel);
  }

  // #1976: issuance state of the order's tickets, with a retry that only brings
  // the worker's next attempt forward. The cron still issues within a minute.
  function addIssueRetry(box, id, issue) {
    if (!issue) return;
    const h = document.createElement('h4'); h.textContent = 'Emisión de boletas';
    const text = document.createElement('p');
    const waiting = issue.state === 'pending' || issue.state === 'failed';
    // The outbox marks a finished issuance as 'done' (migration_ticketing_webhook_01).
    text.textContent = issue.state === 'done' || issue.state === 'issued' ? 'Boletas emitidas.'
      : issue.state === 'processing' ? 'Emitiendo boletas…'
      : issue.state === 'failed' ? 'La emisión falló ' + issue.attempts + (issue.attempts === 1 ? ' vez' : ' veces')
        + '. Próximo intento: ' + bogotaDate(issue.next_attempt_at_utc) + '.'
      : issue.state === 'pending' ? 'Emisión pendiente. Próximo intento: '
        + (issue.next_attempt_at_utc ? bogotaDate(issue.next_attempt_at_utc) : 'en el siguiente minuto') + '.'
      : 'Estado de emisión no reconocido.';
    box.append(h, text);
    if (!waiting) return;
    const button = document.createElement('button');
    button.type = 'button'; button.className = 'tk-button is-primary';
    button.textContent = 'Reintentar emisión ahora';
    const feedback = document.createElement('p');
    feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite');
    feedback.dataset.issueRetryFeedback = '';
    button.addEventListener('click', async () => {
      button.disabled = true;
      feedback.textContent = 'Programando el reintento…';
      try {
        const response = await fetch('/api/ticketing/admin-issue-retry.php', {
          method:'POST',credentials:'same-origin',cache:'no-store',
          headers:{'Content-Type':'application/json','X-CSRF-Token':
            panel.querySelector('[data-ticketing-records]')?.dataset.ticketingCsrf || ''},
          body:JSON.stringify({order_id:Number(id)})
        });
        const payload = await response.json().catch(() => null);
        if (!active()) return;
        if (response.ok && payload?.ok) {
          feedback.textContent = 'Reintento programado. Las boletas se emiten en el próximo minuto; vuelve a abrir el detalle para confirmarlo.';
          return;
        }
        feedback.textContent = response.status === 403 ? 'Sin permiso para reintentar la emisión.'
          : response.status === 409 ? 'Este pedido no tiene una emisión pendiente.'
          : 'No se pudo programar el reintento. Intenta de nuevo.';
      } catch {
        if (active()) feedback.textContent = 'Error de conexión. No se programó el reintento.';
      }
      button.disabled = false;
    });
    box.append(button, feedback);
  }

  async function loadDetail(id) {
    detail.textContent='Consultando el detalle…';
    try {
      const response=await fetch('/api/ticketing/admin-orders.php?kind=orders&order_id='+encodeURIComponent(id),
        {credentials:'same-origin',cache:'no-store'});
      if(!response.ok) throw Error('DETAIL');
      const result=await response.json();
      if(!result.ok || !active()) throw Error('DETAIL');
      const info=result.data.detail||{};
      const record=result.data.rows?.[0]||{};
      const order=info.order||{};
      const page=document.createElement('article');
      page.className='tk-order-page';page.dataset.ticketingOrderPage='';
      const header=document.createElement('header');header.className='tk-order-head';
      const top=document.createElement('div');
      const title=document.createElement('h3');title.textContent='Pedido #'+id;
      const subtitle=document.createElement('p');
      subtitle.textContent=(order.event_title||'Evento sin título')+' · '+displayState(order.status||record.status);
      top.append(title,subtitle);
      const facts=document.createElement('dl');facts.className='tk-order-facts';
      const keys=[['Comprador',record.email||'Datos reservados'],
        ['Correo',record.email||'No visible para este rol'],
        ['Evento',order.event_title||'Sin registro'],
        ['Total',money(order.total_cop??record.total_cop)],
        ['Medio de pago',info.payments?.[0]?.provider||'Sin pago']];
      for(const [label,value] of keys){
        const div=document.createElement('div');
        const dt=document.createElement('dt');dt.textContent=label;
        const dd=document.createElement('dd');dd.textContent=String(value);
        div.append(dt,dd);facts.append(div);
      }
      const openAction=addOrderActions(header,id,order.status||record.status);
      header.prepend(top,facts);page.append(header);
      renderOrderTimeline(page,info,openAction,id);
      const sections=document.createElement('div');sections.className='tk-order-sections';
      const section=(heading,rows,format,empty)=>{
        const el=document.createElement('section');const h=document.createElement('h4');
        h.textContent=heading;el.append(h);
        const ul=document.createElement('ul');
        if(!rows?.length) {
          const li=document.createElement('li');li.textContent=empty;ul.append(li);
        } else for(const row of rows) {
          const li=document.createElement('li');li.textContent=format(row);ul.append(li);
        }
        el.append(ul);return el;
      };
      sections.append(section('Boletas',info.items,
        r=>String(r.ticket_name_snapshot||'Boleta')+' · '+r.quantity+' unidad(es)',
        'Todavía no hay boletas para mostrar.'));
      sections.append(section('Pago',info.payments,
        r=>displayState(r.status)+' · '+money(r.gross_amount_cop)+' · Ref. '+
          (r.provider_reference||'sin referencia')+' · Transacción '+
          (r.provider_transaction_id||'sin transacción'),
        'No hay transacciones registradas.'));
      // Mail status has its own source of truth. Payment or issuance cannot
      // imply that a QR email was sent, accepted or delivered.
      sections.append(section('Correo',info.mail,
        r=>displayState(r.delivery_state)+' · '+bogotaDate(r.state_at||r.created_at),
        'Sin evidencia de entrega de correo.'));
      const notes=section('Notas',info.notes,
        r=>String(r.note_text)+' · '+String(r.actor_name||'Administración')+
          ' · '+bogotaDate(r.created_at_utc),
        'Aún no hay notas internas.');
      notes.dataset.ticketingNoteList='';
      const addNote=document.createElement('button');addNote.type='button';
      addNote.className='tk-button';addNote.textContent='Agregar nota';
      addNote.addEventListener('click',()=>openAction('note'));
      notes.append(addNote);sections.append(notes);
      page.append(sections);
      detail.replaceChildren(page);
    }catch {
      if(active()) detail.textContent='No se pudo cargar el detalle. No se modificó el pedido.';
    }
  }
  async function refresh(append = false) {
    const token = ++generation;
    if (!append) { cursor = null; results.replaceChildren(); detail.replaceChildren(); }
    status.textContent = 'Consultando registros…';
    const p = params();
    if (cursor !== null) p.set('cursor', String(cursor));
    try {
      const response = await fetch('/api/ticketing/admin-orders.php?' + p,
        {credentials:'same-origin',cache:'no-store'});
      if (response.status === 403) throw Error('FORBIDDEN');
      if (!response.ok) throw Error('UNAVAILABLE');
      const data = await response.json();
      if (!data.ok || !active() || token !== generation) return;
      rowsTable(data.data.rows, append);
      cursor = data.data.next_cursor;
      more.hidden = !data.data.has_more;
      status.textContent = 'Resultados de consulta privada. Pagos, boletas y entregas se verifican por separado.';
    } catch (e) {
      if (!active() || token !== generation) return;
      more.hidden = true;
      status.textContent = e.message === 'FORBIDDEN' ? 'Acceso no autorizado.' :
        'No se pudo consultar. Reintenta sin cambiar datos.';
    }
  }
  // The workspace (Pedidos or Compradores) comes from the shell tab, not a duplicate selector.
  if (kind.tagName === 'SELECT') {
    kind.addEventListener('change', () => { options(); void refresh(); });
  }
  form.addEventListener('submit', event => { event.preventDefault(); void refresh(); });
  more.addEventListener('click', () => void refresh(true));
  const exportButton = panel.querySelector('[data-ticketing-export]');
  if (exportButton) exportButton.addEventListener('click', async () => {
    try {
      const p = params(); p.set('format','csv'); if (cursor) p.set('cursor',String(cursor));
      const res = await fetch('/api/ticketing/admin-orders.php?' + p, {credentials:'same-origin',cache:'no-store'});
      if (!res.ok) throw Error('EXPORT');
      const url = URL.createObjectURL(await res.blob()), a = document.createElement('a');
      a.href = url; a.download = 'brvtal-ticketing-page.csv'; a.click();
      URL.revokeObjectURL(url);
      status.textContent = 'CSV privado generado de una página, sin datos de tarjetas ni secretos.';
    } catch { status.textContent = 'No se pudo exportar CSV.'; }
  });
  void refresh();
}
