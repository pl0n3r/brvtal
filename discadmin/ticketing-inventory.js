/* #1931 · Capacity for online sale inside the DISCADMIN event editor (Boletas step).
 * Online checkout only offers ticket types with capacity; this block is that switch.
 * The server enforces permissions, CSRF and the usage floors; this UI only explains them. */
const ERRORS = {
  INVENTORY_BELOW_USAGE: 'El cupo no puede ser menor que lo ya vendido o reservado.',
  INVENTORY_BELOW_COURTESY: 'El cupo de una boleta no puede ser menor que sus cortesías asignadas.',
  INVENTORY_TYPE_NOT_IN_EVENT: 'Una boleta ya no pertenece a este evento. Vuelve a abrir el editor.',
  INVENTORY_INVALID_INPUT: 'Usa números enteros entre 0 y 100.000.',
  INVENTORY_EVENT_NOT_FOUND: 'El evento ya no existe.',
  FORBIDDEN: 'Solo un superadmin puede editar el cupo.',
  CSRF: 'La sesión cambió. Recarga DISCADMIN e inténtalo de nuevo.',
  INVENTORY_UNAVAILABLE: 'El inventario no está disponible. Puede faltar aplicar la migración de boletería en este servidor.',
};
const number = new Intl.NumberFormat('es-CO');
// #1939: reasons from the server-side sale readiness, same rules as the checkout.
const SALE_REASONS = {
  WOMPI_DISABLED: 'Wompi está apagado: elige Pruebas o Producción en Configuración › Integraciones.',
  WOMPI_CREDENTIALS_MISSING: 'Faltan llaves de Wompi del modo elegido, o no tienen el prefijo correcto.',
  WOMPI_METHODS_UNAVAILABLE: 'Faltan los métodos de pago de Wompi.',
  WOMPI_RETURN_URL_INVALID: 'Falta la página de regreso (HTTPS) de Wompi.',
  WOMPI_CONFIG_UNAVAILABLE: 'No se pudo leer la configuración de Wompi.',
  EVENT_NOT_FOUND: 'El evento no existe.',
  EVENT_CAPACITY_MISSING: 'Falta el aforo total del evento.',
  EVENT_FULL: 'El aforo del evento está completo entre lo vendido y lo reservado.',
  EVENT_SOLD_OUT: 'El evento está marcado como agotado.',
  EVENT_STATUS: 'El estado del evento no permite vender.',
  EVENT_NOT_PUBLISHED: 'El evento no está publicado todavía (o su publicación está programada).',
  EVENT_DATE_MISSING: 'El evento no tiene fecha.',
  EVENT_DATE_PAST: 'La fecha del evento ya pasó.',
  EVENT_DATE_INVALID: 'La fecha del evento no es válida.',
  UNKNOWN: 'El checkout no ofrece ninguna boleta y no se identificó el motivo.',
};
const TYPE_REASONS = {
  TYPE_CAPACITY_MISSING: 'Sin cupo',
  TYPE_STATUS: 'Boleta no activa',
  TYPE_CURRENCY: 'La moneda debe ser COP',
  TYPE_NOT_STARTED: 'Su venta aún no empieza',
  TYPE_ENDED: 'Su venta ya terminó',
  TYPE_WINDOW_INVALID: 'Fechas de venta no válidas',
  TYPE_PRICE: 'Precio no válido (entero en pesos)',
  TYPE_SOLD_OUT: 'Cupo agotado',
  TYPE_NOT_OFFERED: 'No se ofrece en línea',
};
const saleReason = code => SALE_REASONS[code]
  || (code.startsWith('ERROR_') ? `Error interno al revisar la venta (${code.slice(6)}). Revisa los registros de DISCADMIN.` : code);

function el(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value === false || value === null || value === undefined) continue;
    if (key === 'text') node.textContent = value;
    else node.setAttribute(key, value === true ? '' : String(value));
  }
  node.append(...children);
  return node;
}

async function request(eventId, body = null) {
  const response = await fetch('/api/ticketing/admin-inventory.php?event_id=' + encodeURIComponent(eventId), body ? {
    method: 'POST', credentials: 'same-origin', cache: 'no-store',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': String(window.csrf || '') },
    body: JSON.stringify(body),
  } : { credentials: 'same-origin', cache: 'no-store' });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok || payload.ok !== true) {
    throw new Error(String(payload.error || (response.status === 403 ? 'FORBIDDEN' : 'INVENTORY_UNAVAILABLE')));
  }
  return payload;
}

/** Mounts (or remounts) the capacity block for one saved event. */
export async function mount(host, { eventId } = {}) {
  host.replaceChildren();
  const id = Number(eventId);
  const heading = el('h3', { id: 'ticket-inventory-title', text: 'Cupo para venta en línea' });
  const help = el('p', { class: 'helper', text: 'Una boleta sin cupo se muestra en la página pero no se vende en línea. El cupo nunca puede bajar de lo ya vendido o reservado.' });
  const section = el('section', { class: 'ticket-inventory', 'aria-labelledby': 'ticket-inventory-title', 'data-ticket-inventory': true }, heading, help);
  host.append(section);
  if (!Number.isSafeInteger(id) || id < 1) {
    section.append(el('p', { class: 'ticket-inventory-empty', text: 'Guarda el evento para configurar el cupo.' }));
    return;
  }
  const status = el('p', { class: 'ticket-inventory-status', role: 'status', 'aria-live': 'polite', text: 'Cargando cupo…' });
  section.append(status);
  let data;
  try {
    data = await request(id);
  } catch (error) {
    if (!section.isConnected) return;
    status.textContent = ERRORS[error.message] || ERRORS.INVENTORY_UNAVAILABLE;
    if (error.message !== 'FORBIDDEN') {
      const retry = el('button', { type: 'button', class: 'btn', text: 'Reintentar' });
      retry.addEventListener('click', () => void mount(host, { eventId: id }));
      section.append(retry);
    }
    return;
  }
  if (!section.isConnected) return;
  status.textContent = '';

  const sale = data.sale && typeof data.sale === 'object' ? data.sale : null;
  if (sale) {
    const reasons = Array.isArray(sale.reasons) ? sale.reasons : [];
    const box = el('div', { class: 'ticket-inventory-sale' + (sale.ready ? ' is-ready' : ''), 'data-sale-ready': sale.ready ? 'true' : 'false' },
      el('strong', { text: sale.ready ? 'Venta en línea: lista' : 'Venta en línea: no disponible' }));
    if (!sale.ready && reasons.length) {
      box.append(el('ul', {}, ...reasons.map(code => el('li', { text: saleReason(String(code)) }))));
    }
    status.before(box);
  }
  const saleByType = new Map((Array.isArray(sale?.types) ? sale.types : []).map(row => [row.ticket_type_id, row]));

  const totalInput = el('input', { id: 'ticket-inventory-total', type: 'number', min: 0, max: 100000, step: 1,
    inputmode: 'numeric', value: data.capacity ?? '', placeholder: 'Sin aforo' });
  const total = el('div', { class: 'ticket-inventory-total' },
    el('label', { for: 'ticket-inventory-total', text: 'Aforo total del evento' }), totalInput,
    el('small', { text: `En uso: ${number.format(data.used)}` }));
  status.before(total);

  const types = Array.isArray(data.types) ? data.types : [];
  const inputs = new Map();
  if (!types.length) {
    status.before(el('p', { class: 'ticket-inventory-empty', text: 'Añade y guarda boletas para asignarles cupo.' }));
  } else {
    const list = el('ul', { class: 'ticket-inventory-list' });
    for (const type of types) {
      const inputId = 'ticket-inventory-type-' + type.ticket_type_id;
      const input = el('input', { id: inputId, type: 'number', min: 0, max: 100000, step: 1, inputmode: 'numeric',
        value: type.capacity ?? '', placeholder: 'Sin cupo' });
      inputs.set(type.ticket_type_id, input);
      const usage = [`Vendidas ${number.format(type.sold)}`, `Reservadas ${number.format(type.held)}`];
      if (type.courtesy_capacity > 0) usage.push(`Cortesías ${number.format(type.courtesy_capacity)}`);
      const sale = el('span', { class: 'ticket-inventory-state' });
      const verdict = saleByType.get(type.ticket_type_id);
      const original = input.value;
      const refresh = () => {
        // Until the capacity is edited, show the server's verdict (same rules as the checkout).
        if (verdict && input.value === original) {
          const codes = Array.isArray(verdict.reasons) ? verdict.reasons : [];
          sale.textContent = verdict.offered ? 'Se vende en línea'
            : (codes.map(code => TYPE_REASONS[code] || code).join(' · ') || 'No se vende en línea');
          sale.classList.toggle('is-open', verdict.offered === true);
          return;
        }
        const value = Number(input.value);
        const open = input.value !== '' && value > 0 && type.status === 'active';
        if (open) {
          sale.textContent = 'Se vende en línea';
        } else if (type.status !== 'active') {
          sale.textContent = 'Boleta no activa';
        } else {
          sale.textContent = 'No se vende en línea';
        }
        sale.classList.toggle('is-open', open);
      };
      input.addEventListener('input', refresh);
      refresh();
      list.append(el('li', { class: 'ticket-inventory-row', 'data-ticket-type': type.ticket_type_id },
        el('div', { class: 'ticket-inventory-name' },
          el('label', { for: inputId, text: type.name || 'Boleta' }),
          el('small', { text: usage.join(' · ') })),
        input, sale));
    }
    status.before(list);
  }

  const save = el('button', { type: 'button', class: 'btn red', text: 'Guardar cupo' });
  section.append(el('div', { class: 'ticket-inventory-actions' }, save));
  const whole = value => value === '' ? 0 : Number(value);
  save.addEventListener('click', async () => {
    const body = { capacity: whole(totalInput.value), types: [...inputs].map(([typeId, input]) => ({
      ticket_type_id: typeId, capacity: whole(input.value) })) };
    const values = [body.capacity, ...body.types.map(row => row.capacity)];
    if (!values.every(value => Number.isSafeInteger(value) && value >= 0 && value <= 100000)) {
      status.textContent = ERRORS.INVENTORY_INVALID_INPUT;
      return;
    }
    save.disabled = true;
    status.textContent = 'Guardando cupo…';
    try {
      await request(id, body);
      if (!section.isConnected) return;
      await mount(host, { eventId: id });
      const fresh = host.querySelector('.ticket-inventory-status');
      if (fresh) fresh.textContent = 'Cupo guardado.';
    } catch (error) {
      if (!section.isConnected) return;
      status.textContent = ERRORS[error.message] || ERRORS.INVENTORY_UNAVAILABLE;
      save.disabled = false;
    }
  });
}
