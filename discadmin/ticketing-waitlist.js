// T28 mounts inside the existing DISCADMIN shell; never creates another session.
const el = (tag, text = '') => {
  const node = document.createElement(tag);
  node.textContent = text;
  return node;
};
export function mount(container, csrf = '') {
  if (!container) throw Error('WAITLIST_CONTAINER_REQUIRED');
  container.replaceChildren();
  const title = el('h2', 'Lista de espera');
  const form = el('form'), label = el('label', 'Evento '), id = el('input');
  id.name = 'event_id'; id.type = 'number'; id.min = '1'; id.required = true;
  label.append(id);
  const submit = el('button', 'Consultar'); submit.type = 'submit';
  form.append(label, submit);
  const status = el('p', 'Solo altas verificadas y consentidas.');
  status.setAttribute('role', 'status');
  const results = el('div');
  container.append(title, form, status, results);
  let generation = 0;
  async function load() {
    const token = ++generation;
    results.replaceChildren();
    if (!id.value || +id.value < 1) return;
    status.textContent = 'Consultando sin modificar registros…';
    try {
      const res = await fetch('/api/ticketing/admin-waitlist.php?event_id=' +
        encodeURIComponent(id.value), {credentials:'same-origin',cache:'no-store'});
      if (!res.ok) throw Error('UNAVAILABLE');
      const payload = await res.json();
      if (token !== generation || !payload.ok) return;
      status.textContent = 'Orden por consentimiento e ID. No confirma boletas ni pagos.';
      const table = el('table'), head = table.createTHead().insertRow();
      for (const name of ['ID', 'Tipo', 'Comprador', 'Estado', 'Alta', 'Vence']) {
        const th = el('th', name); th.scope = 'col'; head.append(th);
      }
      const body = table.createTBody();
      for (const entry of payload.rows) {
        const tr = body.insertRow();
        for (const value of [entry.id, entry.ticket_type_id, entry.buyer_id,
          entry.status, entry.created_at_utc, entry.offer_expires_at_utc || '—']) {
          tr.insertCell().textContent = String(value);
        }
      }
      results.append(table);
      const total = el('p', payload.counts.map(x=>x.status+': '+x.total).join(' · '));
      results.prepend(total);
    } catch {
      if (generation === token) status.textContent = 'Consulta no disponible; no se cambió ningún cupo.';
    }
  }
  form.addEventListener('submit', event => {event.preventDefault(); void load();});
  const offer = el('form'), type = el('input');
  type.name = 'ticket_type_id'; type.type = 'number'; type.min = '1'; type.required = true;
  const typeLabel = el('label', 'Tipo agotado '); typeLabel.append(type);
  const send = el('button', 'Ofrecer un cupo'); send.type = 'submit';
  offer.append(typeLabel,send); container.append(offer);
  offer.addEventListener('submit', async event => {
    event.preventDefault();
    if (!id.value || !type.value || !csrf || !confirm('¿Enviar una sola oferta expirable?')) return;
    send.disabled = true;
    try {
      const res = await fetch('/api/ticketing/admin-waitlist.php', {
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify({action:'offer',event_id:+id.value,ticket_type_id:+type.value})
      });
      if (!res.ok) throw Error('UNAVAILABLE');
      const data = await res.json();
      status.textContent = data.offered
        ? 'Invitación temporal creada, sin cobro ni boleta.'
        : 'No hay cupo libre o interesados elegibles.';
      void load();
    } catch {status.textContent = 'Oferta no disponible; no se promete un cupo.';}
    finally {send.disabled = false;}
  });
}
export function mountPublic(container, {eventId, typeId, csrf}) {
  container.replaceChildren();
  const form = el('form');
  const checkbox = el('input'); checkbox.type = 'checkbox'; checkbox.required = true;
  const label = el('label', 'Acepto recibir avisos únicamente de este evento. ');
  label.prepend(checkbox);
  const honeypot = el('input'); honeypot.name = 'website'; honeypot.tabIndex = -1;
  honeypot.setAttribute('aria-hidden', 'true'); honeypot.hidden = true;
  const send = el('button', 'Entrar en lista de espera'); send.type = 'submit';
  const status = el('p'); status.setAttribute('role','status');
  const privacy = el('a','Privacidad y mis derechos');
  privacy.href = '/privacy';
  form.append(label,privacy,honeypot,send);
  container.append(form,status);
  form.addEventListener('submit',async event => {
    event.preventDefault();
    if (!checkbox.checked || !csrf) return;
    send.disabled = true;
    try {
      const res = await fetch('/api/ticketing/waitlist.php', {
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify({action:'subscribe',event_id:eventId,ticket_type_id:typeId,
          consent:'waitlist-v1',website:honeypot.value})
      });
      if (!res.ok) throw Error('DENIED');
      status.textContent = 'Solicitud recibida. No es una reserva ni una compra.';
    } catch {status.textContent = 'No fue posible inscribirte; verifica tu correo y vuelve a intentarlo.';}
    finally {send.disabled = false;}
  });
}
