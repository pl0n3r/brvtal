/* T22: live stage pricing in the existing DISCADMIN ticketing shell.
 * All prices are server-authoritative; an edit only affects new checkouts. */
export function mount(panel, close = () => {}, defaults = {}) {
  const section = document.createElement('section');
  section.dataset.ticketingStages = '';
  section.style.cssText = 'max-width:100%;min-width:0;overflow-wrap:anywhere';
  const title = document.createElement('h3');
  title.textContent = 'Etapas de precio activas';
  const help = document.createElement('p');
  help.textContent = 'Guardar cambia el precio de las próximas compras. Las boletas ya compradas mantienen su precio.';
  const current = document.createElement('p');
  current.setAttribute('aria-live', 'polite');
  current.textContent = 'Consulta el evento para ver su etapa vigente.';
  const form = document.createElement('form');
  form.style.cssText = 'display:grid;max-width:100%;min-width:0;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:0.75rem';
  function field(label, name, type = 'text') {
    const wrap = document.createElement('label');
    wrap.textContent = label + ' ';
    wrap.style.minWidth = '0';
    const input = document.createElement('input');
    input.name = name; input.type = type; input.required = true;
    input.style.cssText = 'width:100%;max-width:100%;min-width:0;min-height:44px;box-sizing:border-box';
    if (type === 'number') input.min = '1';
    wrap.append(input); form.append(wrap);
    return input;
  }
  const event = field('Evento ID','event_id','number');
  const ticket = field('Tipo de boleta ID','ticket_type_id','number');
  if (Number.isSafeInteger(defaults.eventId) && defaults.eventId > 0) event.value = String(defaults.eventId);
  if (Number.isSafeInteger(defaults.typeId) && defaults.typeId > 0) ticket.value = String(defaults.typeId);
  const codeLabel = document.createElement('label');
  codeLabel.textContent = 'Etapa ';
  const code = document.createElement('select');
  code.name = 'code';
  const names = new Map([['early_bird','Early Bird'],['preventa','Preventa'],['general','General'],['puerta','Puerta']]);
  for (const [value, text] of names) {
    const option = document.createElement('option');
    option.value = value; option.textContent = text; code.append(option);
  }
  codeLabel.style.minWidth = '0';
  code.style.cssText = 'width:100%;max-width:100%;min-width:0;min-height:44px;box-sizing:border-box';
  codeLabel.append(code); form.append(codeLabel);
  const price = field('Precio COP','price_cop','number');
  const cap = field('Cupo acumulado','cumulative_capacity','number');
  const open = field('Abre (Bogotá)','opens_at_local','datetime-local');
  const shut = field('Cierra (Bogotá)','closes_at_local','datetime-local');
  const status = document.createElement('p');
  status.setAttribute('role','status');
  status.setAttribute('aria-live','polite');
  const actions = document.createElement('div');
  actions.style.cssText = 'display:flex;flex-wrap:wrap;gap:0.5rem;max-width:100%';
  for (const [key,label] of [['read','Consultar'],['save','Guardar etapas activas'],['close','Guardar etapas activas y cerrar']]) {
    const button = document.createElement('button');
    button.type = 'button'; button.style.minHeight = '44px';
    button.dataset.stageAction = key; button.textContent = label; actions.append(button);
  }
  const style = document.createElement('style');
  style.textContent = '[data-ticketing-stages] :is(input,select,button):focus-visible{outline:3px solid currentColor;outline-offset:2px}';
  section.append(style,title,help,current,form,actions,status); panel.append(section);
  const local = value => value ? value.replace('T',' ') + (value.length === 16 ? ':00' : '') : '';
  const buttons = [...actions.querySelectorAll('button')];
  let busy = false;
  function showCurrent(data) {
    const stage = data.current_stage;
    current.textContent = stage && names.has(stage.code)
      ? 'Etapa vigente: ' + names.get(stage.code) + ' · ' + Number(stage.price_cop).toLocaleString('es-CO') + ' COP'
      : 'Sin etapa vigente para nuevas compras.';
  }
  async function send(action) {
    if (busy) return;
    const eventId = Number(event.value), typeId = Number(ticket.value);
    if (!Number.isSafeInteger(eventId) || eventId < 1 || !Number.isSafeInteger(typeId) || typeId < 1) {
      status.textContent = 'Indica un evento y tipo válidos.'; return;
    }
    const url = '/api/ticketing/admin-stages.php';
    const readUrl = url + '?event_id=' + eventId + '&ticket_type_id=' + typeId;
    const isRead = action === 'read';
    const opts = isRead ? {credentials:'same-origin',cache:'no-store'} : {
      method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':String(window.csrf || '')},
      body:JSON.stringify({event_id:eventId,ticket_type_id:typeId,stages:[{
        code:code.value,price_cop:Number(price.value),
        cumulative_capacity:Number(cap.value),
        opens_at_local:local(open.value),closes_at_local:local(shut.value)
      }]})
    };
    busy = true; for (const button of buttons) button.disabled = true;
    section.setAttribute('aria-busy','true');
    status.textContent = isRead ? 'Consultando etapas…' : 'Guardando precios activos…';
    try {
      const response = await fetch(isRead ? readUrl : url,opts);
      if (!response.ok) throw new Error(response.status === 403 ? 'Sin permiso' : 'Request failed');
      const data = await response.json();
      if (!data.ok || !section.isConnected) throw new Error('Request failed');
      if (isRead) {
        showCurrent(data);
        const rows = Array.isArray(data.stages) ? data.stages : [];
        status.textContent = rows.length
          ? rows.map(s => (names.get(s.code) || s.code) + ': ' + s.price_cop + ' COP · cupo ' + s.cumulative_capacity).join(' | ')
          : 'Sin etapas configuradas.';
      } else {
        status.textContent = 'Etapa activa guardada. El cambio aplica a nuevas compras.';
        if (action === 'close') close();
        else {
          try {
            const read = await fetch(readUrl,{credentials:'same-origin',cache:'no-store'});
            if (read.ok) {
              const data = await read.json();
              if (data.ok && section.isConnected) showCurrent(data);
            }
          } catch (_) { /* Saved successfully; a summary refresh is best-effort. */ }
        }
      }
    } catch (e) {
      if (section.isConnected) status.textContent = e.message === 'Sin permiso'
        ? 'Sin permiso para cambiar etapas.' : 'No fue posible completar la operación. Vuelve a intentarlo.';
    } finally {
      busy = false; for (const button of buttons) button.disabled = false;
      section.removeAttribute('aria-busy');
    }
  }
  actions.addEventListener('click',e => {
    const key = e.target.closest('button[data-stage-action]')?.dataset.stageAction;
    if (key) void send(key);
  });
  if (event.value && ticket.value) void send('read');
}
