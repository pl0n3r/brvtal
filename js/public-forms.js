(() => {
  'use strict';
  const node = (tag, text = '') => { const n = document.createElement(tag); n.textContent = text; return n; };
  const intent = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
  const button = (text, fn) => { const b = node('button', text); b.type = 'button'; b.addEventListener('click', fn); return b; };
  const checked = f => ['checkbox', 'consent'].includes(f.type);
  const restricted = /[<>]|(?:https?|javascript|data|vbscript|file):|\/\//iu;
  const keyOf = f => JSON.stringify([f.id, f.type, f.label, f.help ?? '', f.max_length ?? null, f.options ?? []]);
  function fieldControl(f, prefix) {
    const id = prefix + '-' + f.id, group = node(f.type === 'radio' ? 'fieldset' : 'div');
    const title = f.label + (f.required ? ' *' : '');
    const error = node('span'); error.id = id + '-error'; error.className = 'form-error';
    const help = node('small', f.help || ''); help.id = id + '-help';
    const controls = [];
    function addControl(input, label, suffix = '') {
      input.id = id + suffix; input.required = f.required; input.autocomplete = 'off';
      input.setAttribute('aria-describedby', help.id + ' ' + error.id);
      label.htmlFor = input.id; group.append(label, input); controls.push(input);
    }
    if (f.type === 'radio') {
      group.append(node('legend', title));
      for (const [i, option] of f.options.entries()) {
        const input = node('input'); input.type = 'radio'; input.name = id; input.value = option.value;
        addControl(input, node('label', option.label), '-' + i);
      }
    } else {
      const input = node(f.type === 'textarea' ? 'textarea' : f.type === 'select' ? 'select' : 'input');
      if (input.tagName === 'INPUT') input.type = checked(f) ? 'checkbox' : f.type;
      if (f.max_length) input.maxLength = f.max_length;
      if (f.type === 'select') {
        input.append(new Option('Selecciona una opción', ''));
        for (const o of f.options) input.append(new Option(o.label, o.value));
      }
      addControl(input, node('label', title));
    }
    group.className = 'form-field'; group.append(help, error);
    const value = () => f.type === 'radio' ? controls.find(c => c.checked)?.value : checked(f) ? controls[0].checked : controls[0].value;
    const restore = v => {
      if (f.type === 'radio') controls.forEach(c => { c.checked = c.value === v; });
      else if (checked(f)) controls[0].checked = v === true;
      else controls[0].value = typeof v === 'string' ? v : '';
    };
    function validate() {
      const v = value();
      const invalid = controls.some(c => !c.validity.valid) || (typeof v === 'string' && (restricted.test(v) || (f.required && !v.trim())));
      error.textContent = invalid ? 'Revisa este campo: completa el valor solicitado sin enlaces ni etiquetas.' : '';
      controls.forEach(c => c.setAttribute('aria-invalid', String(invalid)));
      return invalid ? controls[0] : null;
    }
    return {group, value, restore, validate, field:f};
  }
  async function api(spec, key, post = null) {
    const url = '/api/forms.php?' + new URLSearchParams({public_id:spec.id, version:String(spec.version)});
    const headers = {'Idempotency-Key':key};
    if (post) Object.assign(headers, {'Content-Type':'application/json','X-Form-Captcha-Token':post.token,
      'X-Form-Captcha-Answer':post.answer,'X-Form-Honeypot':post.honey});
    const response = await fetch(url, {method:post?'POST':'GET', headers, body:post?.body,
      credentials:'same-origin', cache:'no-store', redirect:'error', signal:AbortSignal.timeout(15000)});
    return {status:response.status, data:await response.json()};
  }
  async function newest(spec) {
    const response = await fetch(location.pathname + location.search, {cache:'no-store', credentials:'same-origin',
      redirect:'error', signal:AbortSignal.timeout(15000)});
    if (!response.ok) throw Error('page');
    const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
    return Array.from(doc.querySelectorAll('[data-public-form]'), n => JSON.parse(n.dataset.publicForm))
      .find(s => s.id === spec.id);
  }
  function mount(root, spec, prior = new Map(), message = '') {
    const prefix = 'public-form-' + intent(), form = node('form'), fields = node('fieldset');
    form.className = 'public-form'; form.autocomplete = 'off'; form.method = 'post'; form.noValidate = true;
    const status = node('p', message); status.setAttribute('role','status'); status.tabIndex = -1;
    const controls = spec.fields.map(f => fieldControl(f, prefix));
    controls.forEach(c => { if (c.field.type !== 'consent' && prior.has(keyOf(c.field))) c.restore(prior.get(keyOf(c.field))); fields.append(c.group); });
    const notice = node('p', spec.notice); notice.className = 'form-notice';
    const captcha = node('input'); captcha.id = prefix+'-challenge'; captcha.inputMode = 'numeric'; captcha.autocomplete = 'off'; captcha.required = true;
    const question = node('label','Respuesta del desafío'); question.htmlFor = captcha.id;
    const honey = node('input'); honey.hidden = true; honey.tabIndex = -1; honey.autocomplete = 'off';
    const submit = node('button','Enviar formulario'); submit.type = 'submit';
    let key = intent(), token = '', expires = 0, busy = false, frozen = null, unknown = false, stopped = false;
    const refresh = button('Cargar desafío', load);
    form.append(fields, notice, refresh, question, captcha, honey, submit, status);
    root.dataset.publicForm = JSON.stringify(spec);
    root.replaceChildren(node('h2',spec.title),form);
    function say(text) { status.textContent = text; status.focus(); }
    function sync() {
      form.setAttribute('aria-busy', String(busy));
      fields.disabled = busy || stopped || frozen !== null; refresh.disabled = busy || stopped;
      captcha.disabled = busy || stopped || !token; submit.disabled = busy || stopped || !token;
      submit.textContent = unknown ? 'Reintentar envío' : 'Enviar formulario';
    }
    async function recover() {
      token = '';
      const next = await newest(spec);
      if (!next || next.version === spec.version) { stopped = true; say('Formulario no disponible. No se confirmó el envío.'); return; }
      const values = new Map(controls.map(c => [keyOf(c.field), c.value()]));
      mount(root,next,values,(unknown?'No pudimos confirmar el intento anterior. ':'')+
        'El formulario cambió. Revisa los valores conservados y acepta el aviso de la nueva versión.');
      root.querySelector('[role="status"]').focus();
    }
    async function load() {
      if (busy || stopped) return;
      busy = true; token = ''; captcha.value = ''; say('Cargando desafío…'); sync();
      try {
        const {status:code,data} = await api(spec,key);
        if (code === 409 && data.error === 'FORM_VERSION_CONFLICT') await recover();
        else if (code === 200 && data.ok === true && typeof data.data?.captcha_token === 'string'
            && typeof data.data.question === 'string' && Number.isFinite(data.data.expires_in)) {
          token = data.data.captcha_token; expires = Date.now()+data.data.expires_in*1000;
          question.textContent = data.data.question; refresh.textContent = 'Renovar desafío'; say('Resuelve el desafío para continuar.');
        } else say(code === 429 ? 'Demasiados intentos. Intenta más tarde.' : 'No se pudo cargar el desafío. Intenta de nuevo.');
      } catch { say('No se pudo conectar. Intenta cargar el desafío de nuevo.'); }
      finally { busy = false; sync(); }
    }
    function payload() {
      const invalid = controls.map(c => c.validate()).filter(Boolean);
      if (invalid.length) { say('Revisa los campos marcados.'); invalid[0].focus(); return null; }
      return JSON.stringify(Object.fromEntries(controls.map(c=>[c.field.id,c.value()]).filter(([,v])=>v!==undefined&&v!=='')));
    }
    async function handleResult(code, data) {
      if (code === 200 && data.ok === true && data.message === 'FORM_RECEIVED') {
        stopped = true; token = ''; frozen = null; form.replaceChildren(status); say('Formulario recibido. Gracias.'); return;
      }
      if (code === 409 && data.error === 'FORM_VERSION_CONFLICT') { await recover(); return; }
      if (code === 409) { stopped = true; say('No se pudo confirmar este intento. No lo envíes de nuevo.'); return; }
      if (code === 422) {
        if (!unknown) frozen = null;
        if (data.error === 'FORM_CHALLENGE_INVALID') { token = ''; say('Desafío inválido o vencido. Renueva el desafío.'); }
        else say('El servidor rechazó los valores. Revisa los campos.');
        return;
      }
      if (code === 429) { say('Demasiados intentos. Intenta más tarde sin cambiar el envío.'); return; }
      unknown = true; say('No se confirmó el envío. Reintenta el mismo envío sin cambiar los datos.');
    }
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (busy || stopped) return;
      if (!token || Date.now() >= expires) { token = ''; say('Renueva el desafío antes de enviar.'); sync(); return; }
      if (!captcha.value.trim()) { say('Responde el desafío.'); captcha.focus(); return; }
      const body = frozen ?? payload(); if (body === null) return;
      frozen = body; busy = true; say('Enviando…'); sync();
      try { const result = await api(spec,key,{body,token,answer:captcha.value,honey:honey.value}); await handleResult(result.status,result.data); }
      catch { unknown = true; say('No se confirmó el envío. Reintenta el mismo envío sin cambiar los datos.'); }
      finally { busy = false; sync(); }
    });
    sync();
  }
  for (const root of document.querySelectorAll('[data-public-form]')) {
    try { mount(root, JSON.parse(root.dataset.publicForm)); } catch { /* Server fallback remains inert. */ }
  }
})();
