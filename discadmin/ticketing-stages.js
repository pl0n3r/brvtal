/* T22: draft-only stage configuration in the existing DISCADMIN ticketing shell.
 * No public checkout pricing is activated by this editor. */
export function mount(panel, close = () => {}) {
  const section = document.createElement('section');
  const title = document.createElement('h3');
  title.textContent = 'Etapas de preventa (configuración no publicada)';
  section.style.cssText = 'max-width:100%;min-width:0;overflow-wrap:anywhere';
  section.append(title);
  const help = document.createElement('p');
  help.textContent = 'Cupo acumulado por tipo; sin cobros ni activación pública hasta integración de checkout.';
  section.append(help);
  const form = document.createElement('form');
  form.style.cssText = 'display:grid;max-width:100%;min-width:0;grid-template-columns:repeat(auto-fit,minmax(min(100%,180px),1fr));gap:0.75rem';
  function field(label, name, type = 'text') {
    const wrap = document.createElement('label');
    wrap.textContent = label + ' ';
    wrap.style.minWidth = '0';
    const input = document.createElement('input');
    input.name = name; input.type = type; input.required = true;
    input.style.cssText = 'width:100%;max-width:100%;min-width:0;box-sizing:border-box';
    if (type === 'number') input.min = '1';
    wrap.append(input); form.append(wrap);
    return input;
  }
  const event = field('Evento ID','event_id','number');
  const ticket = field('Tipo de boleta ID','ticket_type_id','number');
  const codeLabel = document.createElement('label');
  codeLabel.textContent = 'Etapa ';
  const code = document.createElement('select');
  code.name='code';
  for (const [value, text] of [['early_bird','Early Bird'],['preventa','Preventa'],
    ['general','General'],['puerta','Puerta']]) {
    const option=document.createElement('option'); option.value=value;
    option.textContent=text; code.append(option);
  }
  codeLabel.style.minWidth = '0';
  code.style.cssText = 'width:100%;max-width:100%;min-width:0;box-sizing:border-box';
  codeLabel.append(code); form.append(codeLabel);
  const price = field('Precio COP','price_cop','number');
  const cap = field('Cupo acumulado','cumulative_capacity','number');
  const open = field('Abre (Bogotá)','opens_at_local','datetime-local');
  const shut = field('Cierra (Bogotá)','closes_at_local','datetime-local');
  const status = document.createElement('p'); status.setAttribute('role','status');
  status.setAttribute('aria-live','polite');
  const actions=document.createElement('div');
  actions.style.cssText = 'display:flex;flex-wrap:wrap;gap:0.5rem;max-width:100%';
  for (const [key,label] of [['read','Consultar'],['save','Guardar'],['close','Guardar y cerrar']]) {
    const button=document.createElement('button'); button.type='button';
    button.dataset.stageAction=key; button.textContent=label; actions.append(button);
  }
  section.append(form,actions,status); panel.append(section);
  const local = value => value ? value.replace('T',' ') + (value.length===16?':00':'') : '';
  async function send(action) {
    status.textContent='Procesando…';
    const eventId=Number(event.value), typeId=Number(ticket.value);
    if (!Number.isSafeInteger(eventId) || eventId<1 || !Number.isSafeInteger(typeId) || typeId<1) {
      status.textContent='Indica un evento y tipo válidos.'; return;
    }
    const url='/api/ticketing/admin-stages.php';
    const opts = action==='read'
      ? {credentials:'same-origin',cache:'no-store'}
      : {method:'POST',credentials:'same-origin',headers:{
        'Content-Type':'application/json','X-CSRF-Token':String(window.csrf || '')},
        body:JSON.stringify({event_id:eventId,ticket_type_id:typeId,stages:[{
          code:code.value,price_cop:Number(price.value),
          cumulative_capacity:Number(cap.value),
          opens_at_local:local(open.value),closes_at_local:local(shut.value)
        }]})};
    try {
      const response=await fetch(action==='read'?url+'?event_id='+eventId+'&ticket_type_id='+typeId:url,opts);
      if (!response.ok) throw new Error(response.status===403?'Sin permiso':'No se pudo guardar o consultar');
      const data=await response.json();
      if (!section.isConnected || !data.ok) throw new Error('No disponible');
      if (action==='read') {
        const stages=Array.isArray(data.stages)?data.stages:[];
        status.textContent=stages.length ? stages.map(s=>s.code+': '+s.price_cop+' COP · cupo '+s.cumulative_capacity).join(' | ') : 'Sin etapas configuradas.';
      } else {
        status.textContent='Etapa guardada como borrador. El checkout sigue sin activar este precio.';
        if (action==='close') close();
      }
    } catch (e) {
      if (section.isConnected) status.textContent=e.message==='Sin permiso'?e.message:'No fue posible completar la operación.';
    }
  }
  actions.addEventListener('click',e => {
    const key=e.target.closest('button[data-stage-action]')?.dataset.stageAction;
    if (key) void send(key);
  });
}
