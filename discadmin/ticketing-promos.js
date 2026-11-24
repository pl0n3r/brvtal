/* #1964 (A): Cupones — guided steps (Qué / Cuánto / Cuándo / Límites), pickers
   instead of typed IDs, Bogotá local time sent as UTC. Same API contract as T23. */
const KINDS = [
  ['percent', 'Porcentaje', 'Porcentaje de descuento'],
  ['fixed', 'Valor fijo', 'Descuento en pesos'],
  ['group3', '3 boletas por un precio', 'Precio total de las 3 boletas'],
  ['group5', '5 boletas por un precio', 'Precio total de las 5 boletas'],
];
const KIND_LABEL = Object.fromEntries(KINDS.map(([value, label]) => [value, label]));
const money = value => new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP',
  maximumFractionDigits: 0 }).format(value).replace(/\s/gu, '');
const bogota = new Intl.DateTimeFormat('es-CO', { timeZone: 'America/Bogota', day: 'numeric',
  month: 'short', hour: 'numeric', minute: '2-digit' });

// Bogotá has no daylight saving: local wall time is UTC-5 all year.
export function bogotaToUtc(local) {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local)) return '';
  return new Date(local + ':00-05:00').toISOString().slice(0, 19).replace('T', ' ');
}
function utcLabel(utc) {
  const date = new Date(String(utc).replace(' ', 'T') + 'Z');
  return Number.isNaN(date.getTime()) ? '' : bogota.format(date);
}

function make(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'text') node.textContent = value;
    else if (key === 'class') node.className = value;
    else node.setAttribute(key, value);
  }
  node.append(...children);
  return node;
}
let fieldCount = 0;
// The label names only its field; help text is linked, never part of the name.
function field(label, control, help) {
  const id = control.id || `tk-promo-${++fieldCount}`;
  control.id = id;
  const children = [make('label', { for: id }, [label]), control];
  if (help) {
    control.setAttribute('aria-describedby', id + '-help');
    children.push(make('small', { id: id + '-help', text: help }));
  }
  return make('div', { class: 'tk-field' }, children);
}
function step(legend, fields) {
  return make('fieldset', { class: 'tk-step' }, [make('legend', { text: legend }), ...fields]);
}
function emptyRow(text) {
  return make('tr', { class: 'tk-empty-row' }, [make('td', { colspan: '6', text })]);
}

export function mount(panel) {
  const root = make('section', { class: 'tk-promos', 'aria-labelledby': 'tk-promos-new' });
  const event = make('select', { name: 'event_id', required: '' });
  const type = make('select', { name: 'ticket_type_id' });
  const code = make('input', { name: 'code', required: '', minlength: '8', maxlength: '32', autocomplete: 'off',
    autocapitalize: 'characters', spellcheck: 'false', placeholder: 'GENESIS10' });
  const kind = make('select', { name: 'kind', required: '' },
    KINDS.map(([value, label]) => make('option', { value, text: label })));
  const value = make('input', { name: 'value_cop', type: 'number', min: '1', max: '100', required: '', inputmode: 'numeric' });
  const valueLabel = document.createTextNode(KINDS[0][2]);
  const starts = make('input', { name: 'starts_at', type: 'datetime-local', required: '' });
  const ends = make('input', { name: 'ends_at', type: 'datetime-local', required: '' });
  const maxUses = make('input', { name: 'max_uses', type: 'number', min: '1', required: '', inputmode: 'numeric', value: '100' });
  const buyerLimit = make('input', { name: 'buyer_limit', type: 'number', min: '1', required: '', inputmode: 'numeric', value: '2' });
  const campaign = make('input', { name: 'campaign_key', maxlength: '48', autocomplete: 'off', spellcheck: 'false',
    placeholder: 'instagram-noviembre' });

  const summary = make('p', { class: 'tk-summary', 'data-promo-summary': '' });
  const status = make('p', { class: 'tk-status', role: 'status', 'aria-live': 'polite' });
  const create = make('button', { type: 'submit', class: 'tk-button is-primary', 'data-promo-action': 'create', text: 'Crear cupón' });
  const reset = make('button', { type: 'reset', class: 'tk-button', text: 'Limpiar' });
  const form = make('form', { class: 'tk-form', novalidate: '' }, [
    step('Qué', [field('Evento', event), field('Boleta', type, 'Déjalo en «Todas las boletas» para cualquier boleta del evento.'),
      field('Código del cupón', code, 'Entre 8 y 32 letras o números. Solo se muestra al crearlo.')]),
    step('Cuánto', [field('Tipo de descuento', kind), field(valueLabel, value)]),
    step('Cuándo', [field('Desde (hora de Bogotá)', starts), field('Hasta (hora de Bogotá)', ends)]),
    step('Límites', [field('Usos en total', maxUses), field('Usos por comprador', buyerLimit),
      field('Campaña (opcional)', campaign, 'Para saber de dónde llegan las ventas.')]),
    make('div', { class: 'tk-footer' }, [summary, make('div', { class: 'tk-actions' }, [reset, create])]),
  ]);
  const tableBody = make('tbody');
  const table = make('div', { class: 'tk-table-wrap' }, [make('table', { class: 'tk-table' }, [
    make('thead', {}, [make('tr', {}, ['Regla', 'Descuento', 'Vigencia', 'Usos', 'Estado', 'Acción']
      .map(text => make('th', { scope: 'col', text })))]),
    tableBody,
  ])]);
  const listTitle = make('h3', { text: 'Cupones del evento', class: 'tk-promos-list-title' });
  root.append(make('h3', { id: 'tk-promos-new', text: 'Nuevo cupón' }), form, status, listTitle, table);
  panel.append(root);

  let catalog = [];
  let eventRevision = 0;
  const say = (text, tone = '') => { status.textContent = text; status.dataset.tone = tone; };

  function describe() {
    const amount = Number(value.value);
    const what = !amount ? 'Define el descuento'
      : kind.value === 'percent' ? `${amount} % de descuento`
      : kind.value === 'fixed' ? `${money(amount)} de descuento`
      : `${kind.value === 'group3' ? 3 : 5} boletas por ${money(amount)}`;
    const ticket = type.value ? type.selectedOptions[0]?.textContent : 'todas las boletas';
    const when = starts.value && ends.value
      ? ` · del ${utcLabel(bogotaToUtc(starts.value))} al ${utcLabel(bogotaToUtc(ends.value))}` : '';
    summary.textContent = `${what} en ${ticket}${when} · ${maxUses.value || '?'} usos, ${buyerLimit.value || '?'} por persona.`;
  }
  function fillTypes() {
    const chosen = catalog.find(item => String(item.id) === event.value);
    type.replaceChildren(make('option', { value: '', text: 'Todas las boletas' }),
      ...(chosen?.ticket_types || []).map(item => make('option', { value: String(item.id), text: item.name })));
  }
  async function request(body, eventId = event.value) {
    const response = await fetch('/api/ticketing/admin-promos.php' + (body ? '' : '?event_id=' + encodeURIComponent(eventId)), {
      method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
      headers: body ? { 'Content-Type': 'application/json', 'X-CSRF-Token': String(window.csrf || '') } : {},
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.ok) {
      const messages = {
        403: 'No tienes permiso para gestionar cupones.',
        409: 'Ese código ya existe. Elige otro.',
        422: 'Revisa los datos: código de 8 a 32 caracteres, fechas en orden y usos por persona menores o iguales al total.',
        423: 'Los cupones todavía no están activos en esta tienda.',
      };
      throw new Error(messages[response.status] || 'No se pudo completar. Inténtalo de nuevo.');
    }
    return payload;
  }
  function ruleRow(rule, eventId, revision) {
    const active = Number(rule.active) === 1;
    const amount = Number(rule.value_cop);
    const discount = rule.kind === 'percent' ? `${amount} %` : rule.kind === 'fixed' ? money(amount)
      : `${KIND_LABEL[rule.kind] || rule.kind} · ${money(amount)}`;
    const action = make('td', { 'data-label': 'Acción' });
    if (active) {
      const off = make('button', { type: 'button', class: 'tk-button', text: 'Pausar' });
      off.addEventListener('click', async () => {
        if (event.value !== eventId || eventRevision !== revision) return;
        off.disabled = true;
        try {
          await request({ action: 'deactivate', id: Number(rule.id) });
          say('Cupón pausado. Las compras anteriores no cambian.', 'ok');
          await refresh();
        } catch (error) {
          say(error.message, 'error');
          off.disabled = false;
        }
      });
      action.append(off);
    }
    return make('tr', {}, [
      make('td', { 'data-label': 'Regla', text: `#${rule.id}${rule.campaign_key ? ' · ' + rule.campaign_key : ''}` }),
      make('td', { 'data-label': 'Descuento', text: discount }),
      make('td', { 'data-label': 'Vigencia', text: `${utcLabel(rule.starts_at_utc)} – ${utcLabel(rule.ends_at_utc)}` }),
      make('td', { 'data-label': 'Usos', text: `${rule.uses_count} / ${rule.max_uses}` }),
      make('td', { 'data-label': 'Estado' }, [make('span', { class: 'tk-badge' + (active ? ' is-on' : ''), text: active ? 'Activo' : 'Pausado' })]),
      action,
    ]);
  }
  async function refresh() {
    const eventId = event.value;
    const revision = eventRevision;
    if (!eventId) {
      tableBody.replaceChildren(emptyRow('Elige un evento para ver sus cupones.'));
      return;
    }
    const data = await request(null, eventId);
    if (event.value !== eventId || eventRevision !== revision) return;
    const rules = data.rules || [];
    tableBody.replaceChildren(...(rules.length ? rules.map(rule => ruleRow(rule, eventId, revision))
      : [emptyRow('Este evento todavía no tiene cupones.')]));
  }

  kind.addEventListener('change', () => {
    const chosen = KINDS.find(([option]) => option === kind.value) || KINDS[0];
    valueLabel.textContent = chosen[2];
    value.max = kind.value === 'percent' ? '100' : '10000000';
  });
  event.addEventListener('change', () => {
    eventRevision++;
    fillTypes();
    refresh().catch(error => say(error.message, 'error'));
  });
  form.addEventListener('input', describe);
  form.addEventListener('change', describe);
  form.addEventListener('reset', () => setTimeout(() => { fillTypes(); describe(); say(''); }));
  form.addEventListener('submit', async submit => {
    submit.preventDefault();
    for (const input of form.querySelectorAll('[aria-invalid]')) input.removeAttribute('aria-invalid');
    const invalid = [...form.elements].find(input => input.willValidate && !input.checkValidity());
    if (invalid) {
      invalid.setAttribute('aria-invalid', 'true');
      invalid.focus();
      say('Completa los campos marcados.', 'error');
      return;
    }
    create.disabled = true;
    say('Creando cupón…');
    try {
      await request({ action: 'create', event_id: Number(event.value),
        ticket_type_id: type.value ? Number(type.value) : null,
        code: code.value.trim().toUpperCase(), kind: kind.value, value_cop: Number(value.value),
        starts_at_utc: bogotaToUtc(starts.value), ends_at_utc: bogotaToUtc(ends.value),
        max_uses: Number(maxUses.value), buyer_limit: Number(buyerLimit.value),
        campaign_key: campaign.value.trim() });
      code.value = ''; // never retain a plaintext coupon.
      say('Cupón creado.', 'ok');
      await refresh();
    } catch (error) {
      say(error.message, 'error');
    } finally {
      create.disabled = false;
    }
  });

  async function loadCatalog() {
    try {
      const response = await fetch('/api/ticketing/admin-promos.php?catalog=1', { credentials: 'same-origin', cache: 'no-store' });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) throw new Error('CATALOG');
      catalog = payload.events || [];
    } catch {
      say('No se pudieron cargar los eventos.', 'error');
    }
    event.replaceChildren(make('option', { value: '', text: catalog.length ? 'Elige un evento' : 'No hay eventos publicados' }),
      ...catalog.map(item => make('option', { value: String(item.id), text: item.title })));
    if (catalog.length === 1) event.value = String(catalog[0].id);
    fillTypes();
    describe();
    await refresh().catch(error => say(error.message, 'error'));
  }
  void loadCatalog();
  return () => root.remove();
}
