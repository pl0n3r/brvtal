/* T18b: private records plus synthetic-only audited actions; server enforces RBAC. */
export async function mount(panel, initialKind = 'orders') {
  const active = () => {
    const module = panel.closest('[data-admin-module="ticketing"]');
    const tab = module?.querySelector('[data-ticketing-tab="' + initialKind + '"]');
    return panel.isConnected && !!module && !!tab && tab.getAttribute('aria-selected') === 'true';
  };
  const error = message => { if (active()) panel.replaceChildren(document.createTextNode(message)); };
  try {
    const response = await fetch('/discadmin/ticketing-orders.php', {
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
    import('/discadmin/ticketing-courtesy.js')
      .then(({mount}) => {if (active()) mount(panel);})
      .catch(() => {if (active()) status.textContent='Cortesías no disponibles.';});
  }
  const more = panel.querySelector('[data-ticketing-records-more]');
  const kind = form.elements.namedItem('kind');
  const state = form.elements.namedItem('status');
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
  const cell = (tr, value) => {
    const td = tr.insertCell();
    td.textContent = value === null || value === undefined || value === '' ? 'Sin evidencia' : String(value);
    return td;
  };
  const money = n => Number(n || 0).toLocaleString('es-CO') + ' COP';
  function rowsTable(rows, append) {
    if (!append) results.replaceChildren();
    if (!rows.length) {
      if (!append) results.textContent = 'No hay resultados para estos filtros.';
      return;
    }
    let table = results.querySelector('table');
    if (!table) {
      table = document.createElement('table');
      table.className = 'ticketing-records-table';
      const head = table.createTHead().insertRow();
      const headings = kind.value === 'orders'
        ? ['Pedido','Evento','Comprador','Estado','Total','Unidades','Pagos aprobados','Pago reciente','Correo','Detalle']
        : ['Comprador','Correo','Estado','Pedidos','Pagado'];
      for (const title of headings) {
        const th = document.createElement('th'); th.scope = 'col'; th.textContent = title; head.appendChild(th);
      }
      results.append(table);
    }
    const body = table.tBodies[0] || table.createTBody();
    for (const row of rows) {
      const tr = body.insertRow();
      if (kind.value === 'orders') {
        [row.id,row.event_id,row.email,row.status,money(row.total_cop),row.units,
          row.approvals,row.last_payment_state,row.mail_state].forEach(value => cell(tr,value));
        const action = document.createElement('button');
        action.type = 'button'; action.textContent = 'Detalle #' + row.id;
        action.addEventListener('click', () => void loadDetail(row.id));
        cell(tr, '').append(action);
        if ((row.status === 'paid' && Number(row.approvals) !== 1) || Number(row.approvals) > 1) {
          tr.dataset.ticketingAnomaly = '1';
          tr.title = 'Revisar discrepancia entre pagos y pedido; no confirma liquidación.';
        }
      } else {
        [row.id,row.email,row.status,row.order_count,money(row.paid_total_cop)].forEach(value => cell(tr,value));
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
          li.textContent = String(row[primary]) + ' · ' + String(row[secondary]); list.append(li);
        }
        box.append(list);
      }
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
  kind.addEventListener('change', () => { options(); void refresh(); });
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
