/* T23: synthetic-only Promos in the existing DISCADMIN workspace. */
export function mount(panel) {
  const root = document.createElement('section');
  const title = document.createElement('h3'); title.textContent = 'Promos · pruebas';
  const explanation = document.createElement('p');
  explanation.textContent = 'Configuración sintética: no activa descuentos, pagos ni ventas reales.';
  const form = document.createElement('form');
  const controls = [
    ['event_id','Evento ID','number'], ['ticket_type_id','Tipo ID (opcional)','number'],
    ['code','Cupón (solo se muestra al crearlo)','text'],
    ['kind','Modalidad: fixed, percent, group3, group5','text'],
    ['value_cop','Valor COP o porcentaje entero','number'],
    ['starts_at_utc','Inicio UTC','datetime-local'],
    ['ends_at_utc','Fin UTC','datetime-local'],
    ['max_uses','Cupo total','number'], ['buyer_limit','Cupo por comprador','number'],
    ['campaign_key','Campaña (atribución)','text']
  ];
  const inputs = {};
  for (const [key, label, type] of controls) {
    const wrap = document.createElement('label'); wrap.textContent = label + ' ';
    const input = document.createElement('input');
    input.name = key; input.type = type; input.style.maxWidth = '100%';
    input.required = key!=='ticket_type_id' && key!=='campaign_key';
    if (type==='number' && key!=='ticket_type_id') input.min = '1';
    wrap.append(input); form.append(wrap); inputs[key] = input;
  }
  const actions = document.createElement('div');
  for (const [name,label] of [['create','Crear cupón'],['refresh','Consultar reglas']]) {
    const button = document.createElement('button');
    button.type = 'button'; button.dataset.promoAction = name;
    button.textContent = label; actions.append(button);
  }
  const status = document.createElement('p'); status.setAttribute('role','status');
  status.setAttribute('aria-live','polite');
  const list = document.createElement('ul'); list.dataset.promoRules = '';
  const utc = value => value.replace('T',' ') + (value.length===16?':00':'');
  async function request(body) {
    const response = await fetch('/api/ticketing/admin-promos.php'+
      (body?'':'?event_id='+encodeURIComponent(inputs.event_id.value)), {
      method:body?'POST':'GET', credentials:'same-origin',cache:'no-store',
      headers:body?{'Content-Type':'application/json',
        'X-CSRF-Token':String(window.csrf || '')}:{},
      ...(body?{body:JSON.stringify(body)}:{})
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(
      response.status===403?'Sin permiso':response.status===423?
      'Solo disponible en pruebas sintéticas':'Operación no disponible');
    return payload;
  }
  async function refresh() {
    const data = await request();
    list.replaceChildren();
    for (const rule of data.rules || []) {
      const item = document.createElement('li');
      item.textContent = 'Regla #'+rule.id+' · '+rule.kind+' · '+
        rule.uses_count+'/'+rule.max_uses+' usos · '+
        (Number(rule.active)===1?'Activa':'Inactiva')+' · '+rule.campaign_key;
      if (Number(rule.active)===1) {
        const disable = document.createElement('button');
        disable.type = 'button'; disable.textContent = 'Desactivar';
        disable.addEventListener('click', async () => {
          disable.disabled=true;
          try { await request({action:'deactivate',id:Number(rule.id)});
            status.textContent='Desactivada sin cambiar ventas anteriores.';
            await refresh();
          } catch (error) { status.textContent=error.message; disable.disabled=false; }
        });
        item.append(disable);
      }
      list.append(item);
    }
  }
  actions.addEventListener('click', async event => {
    const action = event.target.closest('[data-promo-action]')?.dataset.promoAction;
    if (!action) return;
    const button = event.target; button.disabled=true; status.textContent='Procesando…';
    try {
      if (action==='create') {
        const id = key => Number(inputs[key].value);
        const code=inputs.code.value;
        await request({action:'create',event_id:id('event_id'),
          ticket_type_id:inputs.ticket_type_id.value?id('ticket_type_id'):null,
          code,kind:inputs.kind.value,value_cop:id('value_cop'),
          starts_at_utc:utc(inputs.starts_at_utc.value),
          ends_at_utc:utc(inputs.ends_at_utc.value),
          max_uses:id('max_uses'),buyer_limit:id('buyer_limit'),
          campaign_key:inputs.campaign_key.value});
        inputs.code.value=''; // never retain a plaintext coupon.
        status.textContent='Cupón creado solo en ambiente sintético.';
      } else { status.textContent='Reglas consultadas.'; }
      await refresh();
    } catch (error) { status.textContent=error.message; }
    finally { button.disabled=false; }
  });
  root.append(title,explanation,form,actions,status,list);
  panel.replaceChildren(root);
  return () => root.remove();
}
