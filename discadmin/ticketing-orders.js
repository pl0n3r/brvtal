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

  // T18b: visible affordances are mockable, but server enforces synthetic-only,
  // active consent and per-action RBAC. Nothing ever calls Resend or Wompi.
  function addSyntheticActions(box, orderId) {
    const section = document.createElement('section');
    section.dataset.ticketingActions = '';
    const heading = document.createElement('h4');
    heading.textContent = 'Acciones administrativas · simulación únicamente';
    const warning = document.createElement('p');
    warning.textContent = 'Sin correos, cobros, cambios de pago o emisión de QR. Consentimiento y permisos se comprueban en el servidor.';
    const selectLabel = document.createElement('label');
    selectLabel.textContent = 'Acción ';
    const select = document.createElement('select');
    select.dataset.ticketingAction = '';
    for (const [value, caption] of [
      ['note','Nota interna sintética'],['contact_preview','Preparar contacto (sin envío)'],
      ['recovery_preview','Preparar recuperación (sin envío)'],
      ['resend_preview','Preparar reenvío (sin envío)']
    ]) {
      const option = document.createElement('option');
      option.value = value; option.textContent = caption; select.append(option);
    }
    selectLabel.append(select);
    const noteLabel = document.createElement('label');
    noteLabel.textContent = 'Nota breve, sin datos personales ';
    const note = document.createElement('textarea');
    note.dataset.ticketingNote = '';
    note.maxLength = 280;
    note.rows = 2;
    note.autocomplete = 'off';
    noteLabel.append(note);
    const review = document.createElement('button');
    review.type = 'button'; review.textContent = 'Preparar acción';
    const confirm = document.createElement('button');
    confirm.type = 'button'; confirm.textContent = 'Confirmar simulación';
    confirm.dataset.ticketingConfirm = ''; confirm.hidden = true;
    const cancel = document.createElement('button');
    cancel.type = 'button'; cancel.textContent = 'Cancelar'; cancel.hidden = true;
    const feedback = document.createElement('p');
    feedback.dataset.ticketingActionStatus = ''; feedback.setAttribute('role','status');
    feedback.setAttribute('aria-live','polite');
    feedback.textContent = 'Ninguna acción enviada.';
    let prepared = null;
    function reset() {
      prepared = null; review.hidden = false; confirm.hidden = true; cancel.hidden = true;
      confirm.disabled = false;
    }
    select.addEventListener('change', () => {
      noteLabel.hidden = select.value !== 'note';
      reset();
    });
    review.addEventListener('click', () => {
      const value = note.value.trim();
      if (select.value === 'note' && (value.length < 3 || value.length > 280)) {
        feedback.textContent = 'La nota necesita entre 3 y 280 caracteres.';
        return;
      }
      prepared = {
        order_id: String(orderId), action: select.value,
        idempotency_key: globalThis.crypto.randomUUID(),
        note: select.value === 'note' ? value : ''
      };
      feedback.textContent = 'Sin envío real. Confirma para registrar la simulación.';
      review.hidden = true; confirm.hidden = false; cancel.hidden = false;
      confirm.focus();
    });
    cancel.addEventListener('click', () => {
      reset(); feedback.textContent = 'Acción cancelada: no se enviaron datos.';
      review.focus();
    });
    confirm.addEventListener('click', async () => {
      if (!prepared || !active()) return;
      confirm.disabled = true;
      feedback.textContent = 'Registrando simulación…';
      try {
        const response = await fetch('/api/ticketing/admin-actions.php', {
          method:'POST',credentials:'same-origin',cache:'no-store',
          headers:{'Content-Type':'application/json','X-CSRF-Token':
            panel.querySelector('[data-ticketing-records]')?.dataset.ticketingCsrf || ''},
          body:JSON.stringify(prepared)
        });
        const payload = await response.json();
        if (!active()) return;
        if (response.ok && payload?.ok && payload.data?.email_sent === false
            && payload.data?.payment_changed === false) {
          feedback.textContent = 'Acción registrada como ' + payload.data.status +
            '. Sin envíos reales ni cambios de pago.';
          reset();
        } else if (response.status === 409 && payload?.error === 'CONSENT_REQUIRED') {
          feedback.textContent = 'Sin consentimiento acreditado: solicitud bloqueada; no hubo envío.';
          reset();
        } else if (response.status === 403) {
          feedback.textContent = 'Sin permiso para esta acción.';
          reset();
        } else if (response.status === 423) {
          feedback.textContent = 'Operación deshabilitada fuera de pruebas sintéticas.';
          reset();
        } else {
          feedback.textContent = 'No se pudo registrar. Reintenta la misma solicitud o cancela.';
        }
      } catch {
        if (active()) feedback.textContent = 'Error de conexión. No se presume éxito ni envío.';
      } finally {
        confirm.disabled = false;
      }
    });
    section.append(heading,warning,selectLabel,noteLabel,review,confirm,cancel,feedback);
    box.append(section);
  }

  // #1976: issuance state of the order's tickets, with a retry that only brings
  // the worker's next attempt forward. The cron still issues within a minute.
  function addIssueRetry(box, id, issue) {
    if (!issue) return;
    const h = document.createElement('h4'); h.textContent = 'Emisión de boletas';
    const text = document.createElement('p');
    const waiting = issue.state === 'pending' || issue.state === 'failed';
    text.textContent = issue.state === 'issued' ? 'Boletas emitidas.'
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
    detail.textContent = 'Consultando el detalle…';
    try {
      const response = await fetch('/api/ticketing/admin-orders.php?kind=orders&order_id=' + encodeURIComponent(id),
        {credentials:'same-origin',cache:'no-store'});
      if (!response.ok) throw Error('DETAIL');
      const data = await response.json();
      if (!data.ok || !active()) throw Error('DETAIL');
      const box = document.createElement('section');
      const title = document.createElement('h3'); title.textContent = 'Pedido #' + id; box.append(title);
      const sections = [['Boletas',data.data.detail.items,'ticket_name_snapshot','quantity'],
        ['Pagos',data.data.detail.payments,'status','gross_amount_cop'],
        ['Correos',data.data.detail.mail,'delivery_state','created_at']];
      for (const [label, rows, primary, secondary] of sections) {
        const h = document.createElement('h4'); h.textContent = label; box.append(h);
        const list = document.createElement('ul');
        if (!rows.length) { const li = document.createElement('li'); li.textContent = 'Sin evidencia'; list.append(li); }
        for (const row of rows) {
          const li = document.createElement('li');
          const first = primary === 'status' || primary === 'delivery_state'
            ? displayState(row[primary]) : String(row[primary] ?? 'Sin registro');
          const second = secondary === 'created_at' ? bogotaDate(row[secondary])
            : String(row[secondary] ?? 'Sin registro');
          li.textContent = first + ' · ' + second;
          // #1950: cross-check a payment with Wompi by its reference and transaction ID.
          if (label === 'Pagos') {
            li.textContent += ' · Ref. ' + (row.provider_reference || 'sin referencia')
              + ' · Transacción ' + (row.provider_transaction_id || 'sin transacción');
          }
          list.append(li);
        }
        box.append(list);
      }
      addIssueRetry(box, id, data.data.detail.issue);
      addSyntheticActions(box, id);
      detail.replaceChildren(box);
    } catch { if (active()) detail.textContent = 'No se pudo cargar el detalle. No se modificó el pedido.'; }
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
