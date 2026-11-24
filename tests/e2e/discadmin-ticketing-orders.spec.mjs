import {test,expect} from '@playwright/test';
import {readFileSync, mkdirSync, writeFileSync} from 'node:fs';
const js = readFileSync('discadmin/ticketing-orders.js','utf8');
const css = readFileSync('discadmin/ticketing.css','utf8');
const php = readFileSync('discadmin/ticketing-orders.php','utf8');
const api = readFileSync('api/ticketing/admin-orders.php','utf8');
const fixture = '<section data-ticketing-records><h2>Pedidos y compradores</h2>'
  + '<form data-ticketing-records-form>'
  + '<input type="hidden" name="kind" value="orders">'
  + '<label>Buscar<input type="search" name="q"></label><label>Correo exacto<input type="email" name="email"></label>'
  + '<label>Evento<input type="number" name="event_id"></label><label>Estado<select name="status"></select></label>'
  + '<label>Desde<input type="date" name="from"></label><label>Hasta<input type="date" name="to"></label>'
  + '<button type="submit">Consultar</button><button type="button" data-ticketing-export>Exportar CSV</button></form>'
  + '<p role="status" data-ticketing-records-status></p><div data-ticketing-records-results></div>'
  + '<button type="button" data-ticketing-records-more hidden>Cargar más</button>'
  + '<div data-ticketing-records-detail></div></section>';
// Exact immutable baseline from main@589e712a788d9835054c692eae7af13d01b469aa: JS blob 23a28a11d0071ac255f410c27787351dfefb3c95, CSS blob d3f693cf3216ef12b45c58297def23b856d9dc4b.
const BEFORE_ORDERS_JS = [
  "/* T18b: private records plus synthetic-only audited actions; server enforces RBAC. */\nexport async function mount(",
  "panel, initialKind = 'orders') {\n  const active = () => {\n    const module = panel.closest('[data-admin-module=\"tic",
  "keting\"]');\n    const tab = module?.querySelector('[data-ticketing-tab=\"' + initialKind + '\"]');\n    return panel.i",
  "sConnected && !!module && !!tab && tab.getAttribute('aria-selected') === 'true';\n  };\n  const error = message => { ",
  "if (active()) panel.replaceChildren(document.createTextNode(message)); };\n  try {\n    const response = await fetch(",
  "'/discadmin/ticketing-orders.php', {\n      credentials: 'same-origin', cache: 'no-store', headers: {'X-BRVTAL-ADMIN",
  "-FRAGMENT':'1'}\n    });\n    if (response.status === 403) return error('Sin permiso para consultar pedidos o comprad",
  "ores.');\n    if (!response.ok) throw Error('FRAGMENT');\n    const fragment = new DOMParser().parseFromString(await ",
  "response.text(), 'text/html')\n      .querySelector('[data-ticketing-records]');\n    if (!fragment || !active()) ret",
  "urn;\n    panel.replaceChildren(document.importNode(fragment, true));\n  } catch { return error('No se pudo cargar Pe",
  "didos y Compradores.'); }\n  const form = panel.querySelector('[data-ticketing-records-form]');\n  const results = pa",
  "nel.querySelector('[data-ticketing-records-results]');\n  const status = panel.querySelector('[data-ticketing-record",
  "s-status]');\n  const detail = panel.querySelector('[data-ticketing-records-detail]');\n  // T25 mounts only in the e",
  "xisting Orders workspace; no second admin shell.\n  if (initialKind === 'orders' && ['superadmin','root','owner','pl",
  "atform_owner']\n    .includes(panel.closest('[data-admin-module=\"ticketing\"]')?.dataset.ticketingRole)) {\n    import",
  "('/discadmin/ticketing-courtesy.js')\n      .then(({mount}) => {if (active()) mount(panel);})\n      .catch(() => {if",
  " (active()) status.textContent='Cortesías no disponibles.';});\n  }\n  const more = panel.querySelector('[data-ticket",
  "ing-records-more]');\n  const kind = form.elements.namedItem('kind');\n  const state = form.elements.namedItem('statu",
  "s');\n  const statusOptions = {\n    orders: [['','Todos'],['draft','Borrador'],['pending','Pendiente'],['paid','Paga",
  "do'],\n      ['failed','Fallido'],['cancelled','Cancelado'],['expired','Expirado'],['refunded','Reembolsado']],\n    ",
  "buyers: [['','Todos'],['guest','Invitado'],['active','Activo'],['blocked','Bloqueado']]\n  };\n  function options() {",
  "\n    state.replaceChildren(...statusOptions[kind.value].map(([value, label]) => {\n      const option = document.cre",
  "ateElement('option');\n      option.value = value; option.textContent = label; return option;\n    }));\n  }\n  kind.va",
  "lue = initialKind === 'buyers' ? 'buyers' : 'orders';\n  options();\n  let cursor = null, generation = 0;\n  const par",
  "ams = () => new URLSearchParams(new FormData(form));\n  const cell = (tr, value) => {\n    const td = tr.insertCell()",
  ";\n    td.textContent = value === null || value === undefined || value === '' ? 'Sin evidencia' : String(value);\n   ",
  " return td;\n  };\n  const money = n => Number(n || 0).toLocaleString('es-CO') + ' COP';\n  function rowsTable(rows, a",
  "ppend) {\n    if (!append) results.replaceChildren();\n    if (!rows.length) {\n      if (!append) results.textContent",
  " = 'No hay resultados para estos filtros.';\n      return;\n    }\n    let table = results.querySelector('table');\n   ",
  " if (!table) {\n      table = document.createElement('table');\n      table.className = 'ticketing-records-table';\n  ",
  "    const head = table.createTHead().insertRow();\n      const headings = kind.value === 'orders'\n        ? ['Pedido",
  "','Evento','Comprador','Estado','Total','Unidades','Pagos aprobados','Pago reciente','Correo','Detalle']\n        : ",
  "['Comprador','Correo','Estado','Pedidos','Pagado'];\n      for (const title of headings) {\n        const th = docume",
  "nt.createElement('th'); th.scope = 'col'; th.textContent = title; head.appendChild(th);\n      }\n      results.appen",
  "d(table);\n    }\n    const body = table.tBodies[0] || table.createTBody();\n    for (const row of rows) {\n      const",
  " tr = body.insertRow();\n      if (kind.value === 'orders') {\n        [row.id,row.event_id,row.email,row.status,mone",
  "y(row.total_cop),row.units,\n          row.approvals,row.last_payment_state,row.mail_state].forEach(value => cell(tr",
  ",value));\n        const action = document.createElement('button');\n        action.type = 'button'; action.textConte",
  "nt = 'Detalle #' + row.id;\n        action.addEventListener('click', () => void loadDetail(row.id));\n        cell(tr",
  ", '').append(action);\n        if ((row.status === 'paid' && Number(row.approvals) !== 1) || Number(row.approvals) >",
  " 1) {\n          tr.dataset.ticketingAnomaly = '1';\n          tr.title = 'Revisar discrepancia entre pagos y pedido;",
  " no confirma liquidación.';\n        }\n      } else {\n        [row.id,row.email,row.status,row.order_count,money(row",
  ".paid_total_cop)].forEach(value => cell(tr,value));\n      }\n    }\n  }\n\n  // T18b: visible affordances are mockable,",
  " but server enforces synthetic-only,\n  // active consent and per-action RBAC. Nothing ever calls Resend or Wompi.\n ",
  " function addSyntheticActions(box, orderId) {\n    const section = document.createElement('section');\n    section.da",
  "taset.ticketingActions = '';\n    const heading = document.createElement('h4');\n    heading.textContent = 'Acciones ",
  "administrativas · simulación únicamente';\n    const warning = document.createElement('p');\n    warning.textContent ",
  "= 'Sin correos, cobros, cambios de pago o emisión de QR. Consentimiento y permisos se comprueban en el servidor.';\n",
  "    const selectLabel = document.createElement('label');\n    selectLabel.textContent = 'Acción ';\n    const select ",
  "= document.createElement('select');\n    select.dataset.ticketingAction = '';\n    for (const [value, caption] of [\n ",
  "     ['note','Nota interna sintética'],['contact_preview','Preparar contacto (sin envío)'],\n      ['recovery_previe",
  "w','Preparar recuperación (sin envío)'],\n      ['resend_preview','Preparar reenvío (sin envío)']\n    ]) {\n      con",
  "st option = document.createElement('option');\n      option.value = value; option.textContent = caption; select.appe",
  "nd(option);\n    }\n    selectLabel.append(select);\n    const noteLabel = document.createElement('label');\n    noteLa",
  "bel.textContent = 'Nota breve, sin datos personales ';\n    const note = document.createElement('textarea');\n    not",
  "e.dataset.ticketingNote = '';\n    note.maxLength = 280;\n    note.rows = 2;\n    note.autocomplete = 'off';\n    noteL",
  "abel.append(note);\n    const review = document.createElement('button');\n    review.type = 'button'; review.textCont",
  "ent = 'Preparar acción';\n    const confirm = document.createElement('button');\n    confirm.type = 'button'; confirm",
  ".textContent = 'Confirmar simulación';\n    confirm.dataset.ticketingConfirm = ''; confirm.hidden = true;\n    const ",
  "cancel = document.createElement('button');\n    cancel.type = 'button'; cancel.textContent = 'Cancelar'; cancel.hidd",
  "en = true;\n    const feedback = document.createElement('p');\n    feedback.dataset.ticketingActionStatus = ''; feedb",
  "ack.setAttribute('role','status');\n    feedback.setAttribute('aria-live','polite');\n    feedback.textContent = 'Nin",
  "guna acción enviada.';\n    let prepared = null;\n    function reset() {\n      prepared = null; review.hidden = false",
  "; confirm.hidden = true; cancel.hidden = true;\n      confirm.disabled = false;\n    }\n    select.addEventListener('c",
  "hange', () => {\n      noteLabel.hidden = select.value !== 'note';\n      reset();\n    });\n    review.addEventListene",
  "r('click', () => {\n      const value = note.value.trim();\n      if (select.value === 'note' && (value.length < 3 ||",
  " value.length > 280)) {\n        feedback.textContent = 'La nota necesita entre 3 y 280 caracteres.';\n        return",
  ";\n      }\n      prepared = {\n        order_id: String(orderId), action: select.value,\n        idempotency_key: glob",
  "alThis.crypto.randomUUID(),\n        note: select.value === 'note' ? value : ''\n      };\n      feedback.textContent ",
  "= 'Sin envío real. Confirma para registrar la simulación.';\n      review.hidden = true; confirm.hidden = false; can",
  "cel.hidden = false;\n      confirm.focus();\n    });\n    cancel.addEventListener('click', () => {\n      reset(); feed",
  "back.textContent = 'Acción cancelada: no se enviaron datos.';\n      review.focus();\n    });\n    confirm.addEventLis",
  "tener('click', async () => {\n      if (!prepared || !active()) return;\n      confirm.disabled = true;\n      feedbac",
  "k.textContent = 'Registrando simulación…';\n      try {\n        const response = await fetch('/api/ticketing/admin-a",
  "ctions.php', {\n          method:'POST',credentials:'same-origin',cache:'no-store',\n          headers:{'Content-Type",
  "':'application/json','X-CSRF-Token':\n            panel.querySelector('[data-ticketing-records]')?.dataset.ticketing",
  "Csrf || ''},\n          body:JSON.stringify(prepared)\n        });\n        const payload = await response.json();\n   ",
  "     if (!active()) return;\n        if (response.ok && payload?.ok && payload.data?.email_sent === false\n          ",
  "  && payload.data?.payment_changed === false) {\n          feedback.textContent = 'Acción registrada como ' + payloa",
  "d.data.status +\n            '. Sin envíos reales ni cambios de pago.';\n          reset();\n        } else if (respon",
  "se.status === 409 && payload?.error === 'CONSENT_REQUIRED') {\n          feedback.textContent = 'Sin consentimiento ",
  "acreditado: solicitud bloqueada; no hubo envío.';\n          reset();\n        } else if (response.status === 403) {\n",
  "          feedback.textContent = 'Sin permiso para esta acción.';\n          reset();\n        } else if (response.st",
  "atus === 423) {\n          feedback.textContent = 'Operación deshabilitada fuera de pruebas sintéticas.';\n          ",
  "reset();\n        } else {\n          feedback.textContent = 'No se pudo registrar. Reintenta la misma solicitud o ca",
  "ncela.';\n        }\n      } catch {\n        if (active()) feedback.textContent = 'Error de conexión. No se presume é",
  "xito ni envío.';\n      } finally {\n        confirm.disabled = false;\n      }\n    });\n    section.append(heading,war",
  "ning,selectLabel,noteLabel,review,confirm,cancel,feedback);\n    box.append(section);\n  }\n\n  async function loadDeta",
  "il(id) {\n    detail.textContent = 'Consultando el detalle…';\n    try {\n      const response = await fetch('/api/tic",
  "keting/admin-orders.php?kind=orders&order_id=' + encodeURIComponent(id),\n        {credentials:'same-origin',cache:'",
  "no-store'});\n      if (!response.ok) throw Error('DETAIL');\n      const data = await response.json();\n      if (!da",
  "ta.ok || !active()) throw Error('DETAIL');\n      const box = document.createElement('section');\n      const title =",
  " document.createElement('h3'); title.textContent = 'Pedido #' + id; box.append(title);\n      const sections = [['Bo",
  "letas',data.data.detail.items,'ticket_name_snapshot','quantity'],\n        ['Pagos',data.data.detail.payments,'statu",
  "s','gross_amount_cop'],\n        ['Correos',data.data.detail.mail,'delivery_state','created_at']];\n      for (const ",
  "[label, rows, primary, secondary] of sections) {\n        const h = document.createElement('h4'); h.textContent = la",
  "bel; box.append(h);\n        const list = document.createElement('ul');\n        if (!rows.length) { const li = docum",
  "ent.createElement('li'); li.textContent = 'Sin evidencia'; list.append(li); }\n        for (const row of rows) {\n   ",
  "       const li = document.createElement('li');\n          li.textContent = String(row[primary]) + ' · ' + String(ro",
  "w[secondary]);\n          // #1950: cross-check a payment with Wompi by its reference and transaction ID.\n          ",
  "if (label === 'Pagos') {\n            li.textContent += ' · Ref. ' + (row.provider_reference || 'sin referencia')\n  ",
  "            + ' · Transacción ' + (row.provider_transaction_id || 'sin transacción');\n          }\n          list.ap",
  "pend(li);\n        }\n        box.append(list);\n      }\n      addSyntheticActions(box, id);\n      detail.replaceChild",
  "ren(box);\n    } catch { if (active()) detail.textContent = 'No se pudo cargar el detalle. No se modificó el pedido.",
  "'; }\n  }\n  async function refresh(append = false) {\n    const token = ++generation;\n    if (!append) { cursor = nul",
  "l; results.replaceChildren(); detail.replaceChildren(); }\n    status.textContent = 'Consultando registros…';\n    co",
  "nst p = params();\n    if (cursor !== null) p.set('cursor', String(cursor));\n    try {\n      const response = await ",
  "fetch('/api/ticketing/admin-orders.php?' + p,\n        {credentials:'same-origin',cache:'no-store'});\n      if (resp",
  "onse.status === 403) throw Error('FORBIDDEN');\n      if (!response.ok) throw Error('UNAVAILABLE');\n      const data",
  " = await response.json();\n      if (!data.ok || !active() || token !== generation) return;\n      rowsTable(data.dat",
  "a.rows, append);\n      cursor = data.data.next_cursor;\n      more.hidden = !data.data.has_more;\n      status.textCo",
  "ntent = 'Resultados de consulta privada. Pagos, boletas y entregas se verifican por separado.';\n    } catch (e) {\n ",
  "     if (!active() || token !== generation) return;\n      more.hidden = true;\n      status.textContent = e.message ",
  "=== 'FORBIDDEN' ? 'Acceso no autorizado.' :\n        'No se pudo consultar. Reintenta sin cambiar datos.';\n    }\n  }",
  "\n  kind.addEventListener('change', () => { options(); void refresh(); });\n  form.addEventListener('submit', event =",
  "> { event.preventDefault(); void refresh(); });\n  more.addEventListener('click', () => void refresh(true));\n  const",
  " exportButton = panel.querySelector('[data-ticketing-export]');\n  if (exportButton) exportButton.addEventListener('",
  "click', async () => {\n    try {\n      const p = params(); p.set('format','csv'); if (cursor) p.set('cursor',String(",
  "cursor));\n      const res = await fetch('/api/ticketing/admin-orders.php?' + p, {credentials:'same-origin',cache:'n",
  "o-store'});\n      if (!res.ok) throw Error('EXPORT');\n      const url = URL.createObjectURL(await res.blob()), a = ",
  "document.createElement('a');\n      a.href = url; a.download = 'brvtal-ticketing-page.csv'; a.click();\n      URL.rev",
  "okeObjectURL(url);\n      status.textContent = 'CSV privado generado de una página, sin datos de tarjetas ni secreto",
  "s.';\n    } catch { status.textContent = 'No se pudo exportar CSV.'; }\n  });\n  void refresh();\n}\n"
].join('');
const BEFORE_ORDERS_CSS = [
  "/* #1964 (A): Boletería inside the DISCADMIN shell. Groups → sections → one workspace.\n   Colors come from the shel",
  "l tokens so light appearance keeps working. */\n.ticketing{margin-top:18px;max-width:1180px;color:var(--text,#f4f5f6",
  ")}\n.ticketing-status{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;margin:0;color:var(--muted,#7d848b",
  ");font-size:13px}\n.ticketing-status>span{display:inline-flex;align-items:center;gap:8px}\n.ticketing-status>span::be",
  "fore{content:\"\";width:8px;height:8px;border-radius:50%;background:currentColor;opacity:.7}\n.ticketing-link{min-heig",
  "ht:44px;padding:0;background:none;border:0;color:var(--text,#f4f5f6);font:inherit;text-decoration:underline;text-un",
  "derline-offset:3px;cursor:pointer}\n.ticketing-groups{display:flex;gap:28px;margin-top:16px;border-bottom:1px solid ",
  "var(--line,#292d31);overflow-x:auto;scrollbar-width:none}\n.ticketing-groups button{flex:none;min-height:44px;paddin",
  "g:0;background:none;border:0;border-bottom:2px solid transparent;color:var(--muted,#7d848b);font:inherit;font-size:",
  "14px;font-weight:600;cursor:pointer;transition:color .15s ease-out,border-color .15s ease-out}\n.ticketing-groups bu",
  "tton:hover{color:var(--text,#f4f5f6)}\n.ticketing-groups button[aria-pressed=\"true\"]{color:var(--text,#f4f5f6);borde",
  "r-bottom-color:var(--red,#ff2038)}\n.ticketing-tabs{display:flex;gap:8px;margin:14px 0 18px;overflow-x:auto;scrollba",
  "r-width:none}\n.ticketing-tabs button{flex:none;min-height:38px;padding:0 14px;background:transparent;color:var(--te",
  "xt,#f4f5f6);border:1px solid var(--line,#292d31);font:inherit;font-size:13px;cursor:pointer;transition:background-c",
  "olor .15s ease-out,border-color .15s ease-out}\n.ticketing-tabs button:hover{border-color:var(--muted,#7d848b)}\n.tic",
  "keting-tabs button[aria-selected=\"true\"]{background:var(--text,#f4f5f6);color:var(--bg,#050505);border-color:var(--",
  "text,#f4f5f6);font-weight:600}\n.ticketing-tabs button[hidden]{display:none}\n.ticketing :is(button,input,select,text",
  "area,a):focus-visible{outline:3px solid var(--text,#fff);outline-offset:3px}\n.ticketing [role=\"tabpanel\"]{min-heigh",
  "t:180px;padding:4px 0 24px}\n.ticketing h2{font-size:22px;margin:0 0 6px}\n.ticketing h3{font-size:17px;margin:0}\n.ti",
  "cketing-empty{margin:0 0 18px;color:var(--muted,#7d848b);max-width:68ch}\n.ticketing button:disabled{opacity:.55;cur",
  "sor:not-allowed}\n.ticketing-error,.ticketing-denied{color:#ffb0b8}\n.ticketing-frame{display:block;width:100%;min-he",
  "ight:420px;border:1px solid var(--line,#292d31);background:var(--p,#0a0b0c)}\n\n/* Shared form system: grouped steps,",
  " labels above fields, one primary action. */\n.tk-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(min",
  "(220px,100%),1fr));gap:16px;margin:0 0 20px}\n.tk-step{margin:0;padding:16px;min-width:0;border:1px solid var(--line",
  ",#292d31);background:var(--p,#0a0b0c);display:flex;flex-direction:column;gap:12px}\n.tk-step legend{padding:0 6px;co",
  "lor:var(--muted,#7d848b);font-size:12px;font-weight:600}\n.tk-field{display:flex;flex-direction:column;gap:6px;min-w",
  "idth:0;font-size:13px}\n.tk-field :is(input,select,textarea){box-sizing:border-box;width:100%;min-height:44px;paddin",
  "g:0 12px;background:var(--bg,#050505);color:var(--text,#f4f5f6);border:1px solid var(--line,#292d31);font:inherit;f",
  "ont-size:14px}\n.tk-field :is(input,select,textarea):hover{border-color:var(--muted,#7d848b)}\n.tk-field small{color:",
  "var(--muted,#7d848b);font-size:12px}\n.tk-field [aria-invalid=\"true\"]{border-color:#ff6b6b}\n.tk-footer{grid-column:1",
  "/-1;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px 16px}\n.tk-summary{margin:",
  "0;color:var(--text,#f4f5f6);font-size:14px;max-width:70ch;text-wrap:pretty}\n.tk-actions{display:flex;flex-wrap:wrap",
  ";gap:8px}\n.tk-button{min-height:44px;padding:0 18px;background:transparent;color:var(--text,#f4f5f6);border:1px sol",
  "id var(--line,#292d31);font:inherit;font-weight:600;cursor:pointer;transition:border-color .15s ease-out,background",
  "-color .15s ease-out}\n.tk-button:hover{border-color:var(--muted,#7d848b)}\n.tk-button.is-primary{background:var(--re",
  "d,#ff2038);border-color:var(--red,#ff2038);color:#090b0d}\n.tk-button.is-primary:hover{filter:brightness(1.08)}\n.tk-",
  "status{margin:0;min-height:20px;color:var(--muted,#7d848b);font-size:13px}\n.tk-status[data-tone=\"error\"]{color:#ffb",
  "0b8}\n.tk-status[data-tone=\"ok\"]{color:var(--green,#49d98a)}\n.tk-table-wrap{max-width:100%;overflow-x:auto}\n.tk-tabl",
  "e{width:100%;border-collapse:collapse;font-size:14px;font-variant-numeric:tabular-nums}\n.tk-table th{padding:10px 1",
  "2px;border-bottom:1px solid var(--line,#292d31);color:var(--muted,#7d848b);font-size:12px;font-weight:600;text-alig",
  "n:left;white-space:nowrap}\n.tk-table td{padding:12px;border-bottom:1px solid var(--line,#292d31);text-align:left;ve",
  "rtical-align:middle}\n.tk-table td:first-child{font-weight:600}\n.tk-badge{display:inline-flex;align-items:center;gap",
  ":6px;white-space:nowrap}\n.tk-badge::before{content:\"\";width:8px;height:8px;border-radius:50%;background:var(--muted",
  ",#7d848b)}\n.tk-badge.is-on::before{background:var(--green,#49d98a)}\n.tk-empty-row td{color:var(--muted,#7d848b);fon",
  "t-weight:400}\n@media(prefers-reduced-motion:reduce){.ticketing-groups button,.ticketing-tabs button,.tk-button{tran",
  "sition:none}}\n@media(max-width:600px){\n  .ticketing-groups{gap:20px}\n  .tk-footer{flex-direction:column;align-items",
  ":stretch}\n  .tk-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}\n  .tk-table thead{display:none}",
  "\n  .tk-table,.tk-table tbody,.tk-table tr,.tk-table td{display:block;width:100%;box-sizing:border-box}\n  .tk-table ",
  "tr{padding:10px 0;border-bottom:1px solid var(--line,#292d31)}\n  .tk-table td{padding:4px 0;border:0}\n  .tk-table t",
  "d[data-label]::before{content:attr(data-label) \" · \";color:var(--muted,#7d848b);font-weight:400}\n}\n\n/* T18: one res",
  "ponsive records workspace; tables scroll inside the module, never the page. */\n.ticketing-records-form{display:grid",
  ";grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));gap:12px;margin:16px 0}\n.ticketing-records-form",
  " label{display:grid;gap:5px;min-width:0}\n.ticketing-records-form :is(input,select,button){min-width:0;max-width:100",
  "%;box-sizing:border-box;padding:10px}\n[data-ticketing-records-results]{max-width:100%;overflow-x:auto}\n.ticketing-r",
  "ecords-table{border-collapse:collapse;min-width:780px;width:100%}\n.ticketing-records-table :is(th,td){padding:10px;",
  "border-bottom:1px solid #34393e;text-align:left}\n.ticketing-records-table tr[data-ticketing-anomaly]{outline:2px da",
  "shed #d29c37}\n[data-ticketing-records-detail]{margin-top:18px}\n.tk-promos-list-title{margin-top:28px}\n/* Wide scree",
  "ns: the four steps share the row exactly. Native pickers follow the theme. */\n@media(min-width:1000px){.tk-form{gri",
  "d-template-columns:repeat(4,minmax(0,1fr))}}\n.ticketing{color-scheme:dark}\nhtml[data-discadmin-appearance=\"light\"] ",
  ".ticketing{color-scheme:light}\n"
].join('');
const beforeFixture = fixture.replace('<input type="hidden" name="kind" value="orders">',
  '<label>Vista<select name="kind"><option value="orders">Pedidos</option><option value="buyers">Compradores</option></select></label>');
async function start(page, reply, kind = 'orders', source = js, stylesheet = css, html = fixture) {
  await page.route('**/discadmin/t18-fixture.html', route => route.fulfill({
    contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
      + '<style>' + stylesheet + '</style><section data-admin-module="ticketing">'
      + '<button type="button" data-ticketing-tab="orders" aria-selected="' + String(kind==='orders') + '">Pedidos</button>'
      + '<button type="button" data-ticketing-tab="buyers" aria-selected="' + String(kind==='buyers') + '">Compradores</button>'
      + '<div id="ticketing-panel"></div></section></html>'
  }));
  await page.route('**/discadmin/ticketing-orders.js', route => route.fulfill({contentType:'text/javascript',body:source}));
  await page.route('**/discadmin/ticketing-orders.php*', route => route.fulfill({
    contentType:'text/html',body:html.replace('name="kind" value="orders"','name="kind" value="'+kind+'"')
  }));
  await page.route('**/api/ticketing/admin-orders.php?*', reply);
  await page.goto('https://www.brvtal.com.co/discadmin/t18-fixture.html');
  await page.evaluate(async kind => {
    const module = await import('/discadmin/ticketing-orders.js');
    await module.mount(document.getElementById('ticketing-panel'),kind);
  },kind);
}
const order = {id:91,buyer_id:3,event_id:6,status:'refunded',currency:'COP',
  total_cop:75000,email:'synthetic@example.test',units:2,approvals:1,
  last_payment_state:'refunded',mail_state:'failed'};
test('T18 pedidos: mobile, búsqueda, pago/correo desacoplados y detalle', async ({page}) => {
  await page.setViewportSize({width:390,height:840});
  let requests = [];
  await start(page,route => {
    const url = new URL(route.request().url());
    requests.push(url);
    const detail = url.searchParams.has('order_id');
    route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true,data:detail
        ? {rows:[order],detail:{items:[{ticket_name_snapshot:'General',quantity:2}],
          payments:[{status:'refunded',gross_amount_cop:75000}],mail:[{delivery_state:'failed',created_at:'2026-11-19'}]}}
        : {rows:[order],kind:'orders',has_more:false,next_cursor:null}
    })});
  });
  await expect(page.locator('table tbody tr')).toHaveCount(1);
  await expect(page.locator('table')).toContainText('Reembolsado');
  await expect(page.locator('table')).toContainText('Fallido');
  await expect(page.locator('table tbody tr')).not.toContainText('Sin evidencia');
  await page.locator('[name=q]').fill('91');
  await page.getByRole('button',{name:'Consultar'}).click();
  await expect.poll(()=>requests.some(url=>url.searchParams.get('q')==='91')).toBeTruthy();
  await page.getByRole('button',{name:'Detalle #91'}).click();
  await expect(page.locator('[data-ticketing-records-detail]')).toContainText('General');
  await expect(page.locator('[data-ticketing-records-detail]')).toContainText('Fallido');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
});
test('T18 compradores: empty search, explicit permission denial and safe failure', async ({page}) => {
  let denied = false;
  await start(page,route => route.fulfill(denied
    ? {status:403,contentType:'application/json',body:'{"ok":false,"error":"FORBIDDEN"}'}
    : {contentType:'application/json',body:JSON.stringify({ok:true,data:{rows:[],kind:'buyers',has_more:false,next_cursor:null}})}
  ),'buyers');
  await expect(page.locator('[data-ticketing-records-results]')).toContainText('No hay resultados');
  await expect(page.locator('select[name=status] option')).toHaveCount(4);
  denied = true;
  await page.getByRole('button',{name:'Consultar'}).click();
  await expect(page.locator('[data-ticketing-records-status]')).toContainText('Acceso no autorizado');
  await expect(page.locator('table')).toHaveCount(0);
});
test('T18 servidor obliga sesión, roles y filtros tipados; export sin secretos', () => {
  expect(php).toContain('brvtal_admin_require();');
  expect(php).toContain("brvtalTicketingCan($role, $kind)");
  expect(php).toContain('type="hidden" name="kind"');
  expect(api).toContain('brvtalTicketAdminFilters($_GET)');
  expect(api).toContain('BUYER_PERMISSION_REQUIRED');
  expect(api).toContain("brvtalTicketingCan($role, 'export')");
  expect(api).not.toContain('provider_transaction_id');
});

test('#1969: anomaly is visible without hover, and timestamps show Bogotá',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  await start(page,route=>{
    const detail=new URL(route.request().url()).searchParams.has('order_id');
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:detail
      ? {rows:[],detail:{items:[],payments:[{status:'APPROVED',gross_amount_cop:12000}],
          mail:[{delivery_state:'sent',created_at:'2026-11-24 23:00:00'}]}}
      : {rows:[{...order,status:'paid',approvals:0,last_payment_state:'APPROVED',mail_state:'sent'}],
          has_more:false,next_cursor:null}})});
  });
  const row=page.locator('.ticketing-records-table tbody tr');
  await expect(row).toContainText('Pagado');
  await expect(row).toContainText('Aprobado');
  await expect(row.locator('.tk-records-anomaly')).toContainText('no coinciden');
  await expect(row.locator('td[data-label="Detalle"]')).not.toContainText('Sin evidencia');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});

test('#1969: browser-generated before/after orders at 390 and 1440',async({page},info)=>{
  const reply=route=>route.fulfill({contentType:'application/json',body:JSON.stringify({
    ok:true,data:{rows:[{...order,status:'paid',approvals:0,last_payment_state:'APPROVED',mail_state:'sent'}],
      kind:'orders',has_more:false,next_cursor:null}})});
  for (const [version,source,styles,markup] of [
    ['antes',BEFORE_ORDERS_JS,BEFORE_ORDERS_CSS,beforeFixture],
    ['despues',js,css,fixture]]) {
    for (const width of [390,1440]) {
      await page.setViewportSize({width,height:width===390?844:900});
      await start(page,reply,'orders',source,styles,markup);
      await expect(page.locator('table tbody tr')).toHaveCount(1);
      const image=await page.screenshot({fullPage:true});
      expect(image.byteLength).toBeGreaterThan(1500);
      await info.attach('pedidos-'+version+'-'+width,{body:image,contentType:'image/png'});
      if(process.env.BRVTAL_VISUAL_EVIDENCE) {
        mkdirSync(process.env.BRVTAL_VISUAL_EVIDENCE,{recursive:true});
        writeFileSync(process.env.BRVTAL_VISUAL_EVIDENCE+'/pedidos-'+version+'-'+width+'.png',image);
      }
      if(version==='despues')
        expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    }
  }
});

// #1976: a stuck issuance is visible in the order detail and can be retried now.
const retryFixture = fixture.replace('<section data-ticketing-records>',
  '<section data-ticketing-records data-ticketing-csrf="csrf-sintetico">');
const issueReply = issue => route => {
  const detail = new URL(route.request().url()).searchParams.has('order_id');
  return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:detail
    ? {rows:[],detail:{items:[{ticket_name_snapshot:'Preventa',quantity:2}],
        payments:[{status:'approved',gross_amount_cop:50000}],mail:[],issue}}
    : {rows:[{...order,status:'paid',last_payment_state:'approved',mail_state:null}],
        kind:'orders',has_more:false,next_cursor:null}})});
};
const failedIssue = {state:'failed',attempts:9,next_attempt_at_utc:'2026-11-24 16:23:02'};

test('#1976: failed issuance shows its state and retries with CSRF',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const posts = [];
  await page.route('**/api/ticketing/admin-issue-retry.php', route => {
    posts.push({headers:route.request().headers(),body:route.request().postDataJSON()});
    return route.fulfill({contentType:'application/json',body:'{"ok":true,"data":{"scheduled":true}}'});
  });
  await start(page,issueReply(failedIssue),'orders',js,css,retryFixture);
  await page.getByRole('button',{name:'Detalle #91'}).click();
  const detail = page.locator('[data-ticketing-records-detail]');
  await expect(detail).toContainText('Emisión de boletas');
  await expect(detail).toContainText('La emisión falló 9 veces');
  await expect(detail).toContainText('24 de nov de 2026, 11:23');
  await expect(detail).not.toContainText('MAIL_');
  const retry = page.getByRole('button',{name:'Reintentar emisión ahora'});
  expect((await retry.boundingBox()).height).toBeGreaterThanOrEqual(44);
  await retry.click();
  await expect(detail.locator('[data-issue-retry-feedback]')).toContainText('Reintento programado');
  expect(posts).toHaveLength(1);
  expect(posts[0].headers['x-csrf-token']).toBe('csrf-sintetico');
  expect(posts[0].body).toEqual({order_id:91});
  await expect(retry).toBeDisabled();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});

test('#1976: issued order has no retry; nothing pending is explained',async({page})=>{
  let state = 'issued';
  await page.route('**/api/ticketing/admin-issue-retry.php', route => route.fulfill(
    {status:409,contentType:'application/json',body:'{"ok":false,"error":"NOTHING_TO_RETRY"}'}));
  await start(page,route=>issueReply(state==='issued'
    ? {state:'issued',attempts:2,next_attempt_at_utc:null} : failedIssue)(route),'orders',js,css,retryFixture);
  await page.getByRole('button',{name:'Detalle #91'}).click();
  const detail = page.locator('[data-ticketing-records-detail]');
  await expect(detail).toContainText('Boletas emitidas.');
  await expect(page.getByRole('button',{name:'Reintentar emisión ahora'})).toHaveCount(0);
  state = 'failed';
  await page.getByRole('button',{name:'Detalle #91'}).click();
  await page.getByRole('button',{name:'Reintentar emisión ahora'}).click();
  await expect(detail.locator('[data-issue-retry-feedback]')).toContainText('no tiene una emisión pendiente');
});

test('#1976: retry endpoint requires session, CSRF, mail permission and only moves the due time',()=>{
  const endpoint = readFileSync('api/ticketing/admin-issue-retry.php','utf8');
  const delivery = readFileSync('config/ticketing_issue_delivery.php','utf8');
  expect(endpoint).toContain('brvtal_admin_require();');
  expect(endpoint).toContain('brvtal_admin_require_csrf();');
  expect(endpoint).toContain("brvtalTicketingCan((string)($q->fetchColumn() ?: ''), 'mail')");
  const retry = delivery.slice(delivery.indexOf('function brvtal_ticket_issue_retry_now'));
  expect(retry).toContain("state IN ('pending','failed')");
  expect(retry).not.toContain('brvtal_ticket_issue_consume');
});

test('#1976: browser-generated before/after order detail at 390 and 1440',async({page},info)=>{
  for (const [version,issue] of [['antes',null],['despues',failedIssue]]) {
    for (const width of [390,1440]) {
      await page.setViewportSize({width,height:width===390?844:900});
      await start(page,issueReply(issue),'orders',js,css,retryFixture);
      await page.getByRole('button',{name:'Detalle #91'}).click();
      await expect(page.locator('[data-ticketing-records-detail]')).toContainText('Preventa');
      const image=await page.screenshot({fullPage:true});
      expect(image.byteLength).toBeGreaterThan(1500);
      await info.attach('emision-'+version+'-'+width,{body:image,contentType:'image/png'});
      if(process.env.BRVTAL_VISUAL_EVIDENCE) {
        mkdirSync(process.env.BRVTAL_VISUAL_EVIDENCE,{recursive:true});
        writeFileSync(process.env.BRVTAL_VISUAL_EVIDENCE+'/emision-'+version+'-'+width+'.png',image);
      }
      expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    }
  }
});
