window.BRVTALForms = (() => {
  'use strict';
  const states = {received:'Recibida',reviewed:'Revisada',redacted:'Anonimizada'};
  const management = {new:'Nueva',in_progress:'En gestión',resolved:'Resuelta',archived:'Archivada',spam:'Spam'};
  const transitions = {new:['in_progress','spam'],in_progress:['resolved','spam'],resolved:['archived','in_progress'],
    archived:['in_progress'],spam:['in_progress','archived']};
  const notices = {pending:'Pendiente',enqueued:'En cola (no acredita envío)',suppressed:'Suprimida'};
  const node = (tag, text = '', className = '') => {
    const el = document.createElement(tag);
    el.textContent = text;
    if (className) el.className = className;
    return el;
  };
  const button = (label, action) => {
    const el = node('button',label,'btn');
    el.type = 'button'; el.addEventListener('click',() => { void action(); }); return el;
  };
  function value(input) {
    if (Array.isArray(input)) return input.map(value).join(', ');
    if (input == null) return '—';
    if (typeof input === 'boolean') return input ? 'Sí' : 'No';
    return String(input);
  }
  const date = input => {
    if (typeof input !== 'string') return '—';
    const instant = new Date(input.replace(' ','T') + 'Z');
    return Number.isFinite(instant.getTime()) ? new Intl.DateTimeFormat('es-CO',{
      dateStyle:'medium',timeStyle:'short',timeZone:'America/Bogota'
    }).format(instant) : '—';
  };
  function pair(dl,label,text) { dl.append(node('dt',label),node('dd',value(text))); }
  function row(title,description,action,label) {
    const li = node('li','','forms-row'), copy = node('div');
    copy.append(node('strong',title),node('span',description));
    li.append(copy,button(label,action)); return li;
  }
  function mount(root) {
    const status = root.querySelector('[data-forms-status]');
    const content = root.querySelector('[data-forms-content]');
    let active, formKey = '', filters = {}, cursors = [''], enabled = false, busy = false;
    const intro = root.querySelector(':scope > p');
    function capability(value) {
      enabled = value === true;
      if (intro) intro.textContent = enabled ? 'Consulta, gestiona y exporta respuestas autorizadas.' : 'Consulta de respuestas registradas. Solo lectura.';
    }
    const current = controller => active === controller && root.isConnected &&
      window.state?.authed === true && window.state?.section === 'forms';
    function heading(label,focus) {
      const el = node('h2',label); el.tabIndex = -1; content.append(el);
      if (focus) el.focus();
    }
    async function read(params,render,retry) {
      active?.abort(); busy = false; capability(false);
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
        capability(payload.data.management_enabled);
        status.textContent = ''; render(payload.data);
      } catch (_) {
        // Do not log request errors: they may contain response identifiers or data.
        if (!current(controller)) return;
        content.replaceChildren();
        status.textContent = 'No fue posible consultar formularios. Intenta de nuevo.';
        content.append(button('Reintentar',retry));
      }
    }
    async function post(body,done,refresh) {
      const controller = active;
      if (!enabled || busy || !current(controller)) return;
      busy = true;
      const controls = [...content.querySelectorAll('button,input,select')].filter(el=>!el.disabled);
      controls.forEach(el=>{el.disabled=true;});
      status.textContent = body.action === 'export' ? 'Preparando CSV…' : 'Guardando…';
      try {
        let token = typeof csrf !== 'undefined' ? csrf : '';
        if (!token) {
          const auth = await fetch('/api/index.php/auth',{credentials:'same-origin',cache:'no-store',signal:controller.signal});
          const session = await auth.json();
          if (!auth.ok || session.authenticated!==true || !session.csrf) throw new Error('AUTH_REQUIRED');
          token=session.csrf;
        }
        if (!current(controller)) return;
        const res=await fetch('/api/admin-forms.php',{method:'POST',credentials:'same-origin',cache:'no-store',
          signal:controller.signal,headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify(body)});
        if (!current(controller)) return;
        if ([401,403,419].includes(res.status)) throw new Error('AUTH_REQUIRED');
        if (res.status===503) {
          await refresh('La gestión no está disponible. Consulta el estado actualizado.'); return;
        }
        if (res.ok && body.action==='export') {
          if (!/^text\/csv(?:;|$)/i.test(res.headers.get('Content-Type')||'')) throw new Error('INVALID');
          const blob=await res.blob();
          if (!current(controller)) return;
          const url=URL.createObjectURL(blob), link=node('a');
          link.href=url; link.download='forms-responses.csv';
          document.body.append(link); link.click(); link.remove();
          setTimeout(()=>URL.revokeObjectURL(url),1000);
          status.textContent='CSV descargado.'; return;
        }
        const payload=await res.json();
        if (!current(controller)) return;
        if (res.status===409) {
          await refresh('La respuesta cambió o ya no está disponible. Revisa el estado actualizado antes de actuar.'); return;
        }
        if (payload.error==='EXPORT_LIMIT_EXCEEDED') {
          status.textContent='Más de 1.000 respuestas vigentes. Acota los filtros para exportar.'; return;
        }
        if (!res.ok || payload.ok!==true) throw new Error('INVALID');
        await done();
      } catch (error) {
        if (!current(controller)) return;
        if (error.message==='AUTH_REQUIRED') {
          capability(false); content.replaceChildren();
          status.textContent='Sesión o permiso no válido. Vuelve a iniciar sesión.'; return;
        }
        status.textContent='No fue posible completar la acción. Revisa los datos antes de volver a intentarlo.';
      } finally {
        if (current(controller)) {
          busy=false; controls.forEach(el=>{el.disabled=false;});
        }
      }
    }
    function rows(items,render) {
      if (!Array.isArray(items)) throw new Error('INVALID_LIST');
      const list = node('ul','','forms-list');
      items.forEach(item => list.append(render(item)));
      content.append(list);
      if (!items.length) content.append(node('p','No hay resultados.'));
    }
    function catalogRow(item) {
      return row(value(item.form_key),
        'Respuestas: '+value(item.total)+' · Nuevas: '+value(item.new_count)+' · Versión '+value(item.latest_version),
        () => { formKey = item.form_key; return list(true); },'Ver respuestas de '+value(item.form_key));
    }
    function catalog(focus = false) {
      formKey = ''; filters = {}; cursors = [''];
      return read({view:'catalog'},data => {
        heading('Formularios disponibles',focus);
        rows(data.items,catalogRow);
      },()=>catalog(true));
    }
    function controls() {
      const form = node('form'), select = node('select');
      for (const [key,label] of [['','Todos'],...Object.entries(states)]) {
        const option = node('option',label); option.value = key; select.append(option);
      }
      select.value = filters.state || '';
      const label = node('label','Estado'); label.append(select); form.append(label);
      const workflow = node('select'), workflowLabel = node('label','Gestión');
      for (const [key,title] of [['','Todos'],...Object.entries(management)]) {
        const option=node('option',title); option.value=key; workflow.append(option);
      }
      workflow.value=filters.management_state||''; workflowLabel.append(workflow);
      if (enabled) form.append(workflowLabel);
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
        filters = Object.fromEntries(Object.entries({state:select.value,management_state:enabled?workflow.value:'',from:inputs.from.value,to:inputs.to.value}).filter(([,v])=>v));
        cursors = ['']; void list(true);
      });
      content.append(form);
    }
    function entryRow(item) {
      return row(date(item.created_at_utc),
        (states[item.state] || 'Estado desconocido')+
          (enabled && management[item.management_state] ? ' · '+management[item.management_state] : '')+' · Versión '+value(item.version_number),
        ()=>detail(item.entry_id),'Ver respuesta '+value(item.entry_id));
    }
    function list(focus = false,message = '') {
      const cursor = cursors.at(-1);
      return read({view:'list',form_key:formKey,limit:'20',...filters,...(cursor?{cursor}:{})},data => {
        heading('Respuestas de '+formKey,focus);
        content.append(button('Volver a formularios',()=>catalog(true)));
        controls();
        content.append(node('p',value(data.total)+' respuestas · Página '+cursors.length+' · Hora de Bogotá'));
        rows(data.items,entryRow);
        const actions = node('div','','forms-actions');
        if (cursors.length>1) actions.append(button('Anterior',()=>{cursors.pop();return list(true);}));
        if (data.has_more===true && /^[a-f0-9]{32}$/.test(data.next_cursor || '')) {
          actions.append(button('Siguiente',()=>{cursors.push(data.next_cursor);return list(true);}));
        }
        if (enabled) actions.append(button('Exportar CSV de los filtros',()=>post(
          {action:'export',form_key:formKey,...filters},()=>{},msg=>list(true,msg))));
        content.append(actions);
        if (enabled) content.append(node('p','Exporta hasta 1.000 respuestas vigentes de todos los resultados filtrados, no solo de esta página.'));
        if (message) status.textContent=message;
      },()=>list(true));
    }
    function manage(data,id) {
      if (!enabled || data.state==='redacted' || !Number.isInteger(data.revision) || data.revision<0
          || !Object.hasOwn(management,data.management_state)) return;
      content.append(node('h3','Gestión de respuesta'));
      const summary=node('dl'); pair(summary,'Estado de gestión',management[data.management_state]);
      pair(summary,'Revisión',data.revision); content.append(summary);
      const body={form_key:formKey,entry_id:id,expected_revision:data.revision};
      const refresh=message=>detail(id,message);
      const actions=node('div','','forms-actions');
      for (const target of transitions[data.management_state]) {
        actions.append(button('Cambiar a '+management[target],()=>post({...body,action:'transition',state:target},
          ()=>refresh('Estado actualizado.'),refresh)));
      }
      content.append(actions,node('h3','Notas internas'));
      const history=node('dl');
      if (!Array.isArray(data.notes)) throw new Error('INVALID_NOTES');
      for (const note of data.notes) pair(history,date(note.created_at_utc)+' · Admin '+value(note.actor_admin_id),note.note_text);
      if (!data.notes.length) pair(history,'Historial','Sin notas internas.');
      content.append(history);
      const form=node('form'), label=node('label','Añadir nota interna'), input=node('input');
      input.type='text'; input.required=true; input.maxLength=1000;
      label.append(input); form.append(label);
      const submit=node('button','Guardar nota','btn'); submit.type='submit'; form.append(submit);
      form.addEventListener('submit',event=>{
        event.preventDefault();
        const note=input.value.trim();
        if (!note) {status.textContent='Escribe una nota antes de guardar.'; return;}
        void post({...body,action:'note',note},()=>refresh('Nota guardada.'),refresh);
      });
      content.append(form);
    }
    function detail(id,message = '') {
      return read({view:'detail',form_key:formKey,entry_id:id},data => {
        heading('Detalle de respuesta',true);
        content.append(button('Volver a respuestas',()=>list(true)));
        const dl = node('dl'); pair(dl,'Formulario',data.form_key); pair(dl,'Versión histórica',data.version_number);
        pair(dl,'Estado',states[data.state] || 'Estado desconocido'); pair(dl,'Recibida (Bogotá)',date(data.created_at_utc));
        pair(dl,'Aviso de privacidad',data.notice_version); pair(dl,'Finalidad',data.purpose_key);
        pair(dl,'Conservación hasta (Bogotá)',date(data.retention_until_utc)); content.append(dl);
        content.append(node('h3','Campos de la respuesta'));
        const fields = node('dl');
        if (data.state === 'redacted') pair(fields,'Contenido','Contenido anonimizado.');
        else if (data.revision === null) pair(fields,'Contenido','Contenido fuera del periodo de conservación.');
        else {
          if (!Array.isArray(data.fields)) throw new Error('INVALID_FIELDS');
          for (const field of data.fields) pair(fields,value(field.label),field.value);
          if (!data.fields.length) pair(fields,'Contenido','Sin campos disponibles.');
        }
        content.append(fields,node('h3','Notificaciones'));
        if (!Array.isArray(data.notifications)) throw new Error('INVALID_NOTIFICATIONS');
        const notificationList = node('dl');
        for (const notice of data.notifications) pair(notificationList,value(notice.action_key),
          (notices[notice.state] || 'Estado desconocido')+' · '+date(notice.created_at_utc));
        if (!data.notifications.length) pair(notificationList,'Estado','Sin notificaciones registradas.');
        content.append(notificationList);
        manage(data,id);
        if (message) status.textContent=message;
      },()=>detail(id));
    }
    return catalog();
  }
  return {mount};
})();
