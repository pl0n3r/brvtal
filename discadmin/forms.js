window.BRVTALForms = (() => {
  'use strict';
  const states = {received:'Recibida',reviewed:'Revisada',redacted:'Anonimizada'};
  const notices = {pending:'Pendiente',enqueued:'En cola (no acredita envío)',suppressed:'Suprimida'};
  const node = (tag, text = '', className = '') => {
    const el = document.createElement(tag);
    el.textContent = text;
    if (className) el.className = className;
    return el;
  };
  const button = (label, action) => {
    const el = node('button',label,'btn');
    el.type = 'button'; el.addEventListener('click',action); return el;
  };
  const value = input => Array.isArray(input) ? input.map(value).join(', ') :
    input == null ? '—' : typeof input === 'boolean' ? (input ? 'Sí' : 'No') : String(input);
  const date = input => {
    if (typeof input !== 'string') return '—';
    const instant = new Date(input.replace(' ','T') + 'Z');
    return Number.isFinite(instant.getTime()) ? new Intl.DateTimeFormat('es-CO',{
      dateStyle:'medium',timeStyle:'short',timeZone:'America/Bogota'
    }).format(instant) : '—';
  };
  function pair(dl,label,text) { dl.append(node('dt',label),node('dd',value(text))); }
  function mount(root) {
    const status = root.querySelector('[data-forms-status]');
    const content = root.querySelector('[data-forms-content]');
    let active, formKey = '', filters = {}, cursors = [''];
    const current = controller => active === controller && root.isConnected &&
      window.state?.authed === true && window.state?.section === 'forms';
    function heading(label,focus) {
      const el = node('h2',label); el.tabIndex = -1; content.append(el);
      if (focus) el.focus();
    }
    async function read(params,render,retry) {
      active?.abort();
      const controller = new AbortController(); active = controller;
      content.replaceChildren(); status.textContent = 'Cargando…';
      try {
        const res = await fetch('/api/admin-forms.php?' + new URLSearchParams(params),{
          method:'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal
        });
        if (!current(controller)) return;
        if (res.status === 403 || res.status === 401) {
          status.textContent = 'No tienes permiso para consultar formularios.'; return;
        }
        if (!res.ok) throw new Error('UNAVAILABLE');
        const payload = await res.json();
        if (!current(controller)) return;
        if (payload?.ok !== true || !payload.data || typeof payload.data !== 'object') throw new Error('INVALID');
        status.textContent = ''; render(payload.data);
      } catch (_) {
        if (!current(controller)) return;
        content.replaceChildren();
        status.textContent = 'No fue posible consultar formularios. Intenta de nuevo.';
        content.append(button('Reintentar',retry));
      }
    }
    function rows(items,render) {
      if (!Array.isArray(items)) throw new Error('INVALID_LIST');
      const list = node('ul','','forms-list');
      items.forEach(item => list.append(render(item)));
      content.append(list);
      if (!items.length) content.append(node('p','No hay resultados.'));
    }
    function row(title,description,action,label) {
      const li = node('li','','forms-row'), copy = node('div');
      copy.append(node('strong',title),node('span',description));
      li.append(copy,button(label,action)); return li;
    }
    function catalog(focus = false) {
      formKey = ''; filters = {}; cursors = [''];
      return read({view:'catalog'},data => {
        heading('Formularios disponibles',focus);
        rows(data.items,item => row(value(item.form_key),
          'Respuestas: '+value(item.total)+' · Nuevas: '+value(item.new_count)+' · Versión '+value(item.latest_version),
          () => { formKey = item.form_key; list(true); },'Ver respuestas de '+value(item.form_key)));
      },()=>catalog(true));
    }
    function controls() {
      const form = node('form'), select = node('select');
      for (const [key,label] of [['','Todos'],...Object.entries(states)]) {
        const option = node('option',label); option.value = key; select.append(option);
      }
      select.value = filters.state || '';
      const label = node('label','Estado'); label.append(select); form.append(label);
      const inputs = {};
      for (const [key,title] of [['from','Desde'],['to','Hasta']]) {
        const dateLabel = node('label',title), input = node('input');
        input.type = 'date'; input.value = filters[key] || ''; inputs[key] = input;
        dateLabel.append(input); form.append(dateLabel);
      }
      const submit = node('button','Consultar','btn'); submit.type = 'submit'; form.append(submit);
      form.addEventListener('submit',event => {
        event.preventDefault();
        if (inputs.from.value && inputs.to.value && inputs.from.value > inputs.to.value) {
          status.textContent = 'La fecha inicial no puede ser posterior a la final.'; return;
        }
        filters = Object.fromEntries(Object.entries({state:select.value,from:inputs.from.value,to:inputs.to.value}).filter(([,v])=>v));
        cursors = ['']; list(true);
      });
      content.append(form);
    }
    function list(focus = false) {
      const cursor = cursors.at(-1);
      return read({view:'list',form_key:formKey,limit:'20',...filters,...(cursor?{cursor}:{})},data => {
        heading('Respuestas de '+formKey,focus);
        content.append(button('Volver a formularios',()=>catalog(true)));
        controls();
        content.append(node('p',value(data.total)+' respuestas · Página '+cursors.length+' · Hora de Bogotá'));
        rows(data.items,item => row(date(item.created_at_utc),
          (states[item.state] || 'Estado desconocido')+' · Versión '+value(item.version_number),
          ()=>detail(item.entry_id),'Ver respuesta '+value(item.entry_id)));
        const actions = node('div','','forms-actions');
        if (cursors.length>1) actions.append(button('Anterior',()=>{cursors.pop();list(true);}));
        if (data.has_more===true && /^[a-f0-9]{32}$/.test(data.next_cursor || '')) {
          actions.append(button('Siguiente',()=>{cursors.push(data.next_cursor);list(true);}));
        }
        content.append(actions);
      },()=>list(true));
    }
    function detail(id) {
      return read({view:'detail',form_key:formKey,entry_id:id},data => {
        heading('Detalle de respuesta',true);
        content.append(button('Volver a respuestas',()=>list(true)));
        const dl = node('dl'); pair(dl,'Formulario',data.form_key); pair(dl,'Versión histórica',data.version_number);
        pair(dl,'Estado',states[data.state] || 'Estado desconocido'); pair(dl,'Recibida (Bogotá)',date(data.created_at_utc));
        pair(dl,'Aviso de privacidad',data.notice_version); pair(dl,'Finalidad',data.purpose_key);
        pair(dl,'Conservación hasta (Bogotá)',date(data.retention_until_utc)); content.append(dl);
        content.append(node('h3','Campos de la respuesta'));
        const fields = node('dl');
        if (data.state === 'redacted') fields.append(node('dd','Contenido anonimizado.'));
        else {
          if (!Array.isArray(data.fields)) throw new Error('INVALID_FIELDS');
          for (const field of data.fields) pair(fields,value(field.label),field.value);
          if (!data.fields.length) fields.append(node('dd','Sin campos disponibles.'));
        }
        content.append(fields,node('h3','Notificaciones'));
        if (!Array.isArray(data.notifications)) throw new Error('INVALID_NOTIFICATIONS');
        const notificationList = node('dl');
        for (const notice of data.notifications) pair(notificationList,value(notice.action_key),
          (notices[notice.state] || 'Estado desconocido')+' · '+date(notice.created_at_utc));
        if (!data.notifications.length) notificationList.append(node('dd','Sin notificaciones registradas.'));
        content.append(notificationList);
      },()=>detail(id));
    }
    return catalog();
  }
  return {mount};
})();
