/* #1888: editor inside the existing DISCADMIN > Boletería > Correos surface.
   This module never sends mail, accepts arbitrary HTML or exposes protected links. */
const ENDPOINT = '/api/admin-mail-templates.php';
const MEDIA = '/api/media-library.php?action=list';
const WRITERS = new Set(['superadmin', 'root', 'owner', 'platform_owner']);
const NAMES = {
  verify_account:'Confirmación de cuenta', reset_password:'Recuperación de contraseña',
  order_pending:'Pedido pendiente', order_rejected:'Pago rechazado',
  payment_approved:'Compra aprobada', reminder:'Recordatorio de evento',
  refund:'Actualización de devolución', staff_password_recovery:'Acceso del personal',
  staff_password_changed:'Contraseña del personal', brand_theme:'Identidad BRVTAL'
};
const TRIGGERS = {
  verify_account:'Registro', reset_password:'Restablecimiento de acceso',
  order_pending:'Creación de pedido', order_rejected:'Pago no aprobado',
  payment_approved:'Confirmación de pago', reminder:'Aviso previo al evento',
  refund:'Cambio de devolución', staff_password_recovery:'Solicitud del personal',
  staff_password_changed:'Cambio de contraseña', brand_theme:'Marca compartida'
};
const FIELDS = [
  ['subject','Asunto','text',180],['preheader','Texto previo','text',180],
  ['title','Título','text',160],['message','Mensaje','textarea',4000],
  ['cta','Texto del botón','text',80],['footer','Pie de correo','textarea',500]
];
const COLORS = [
  ['background','Fondo'],['foreground','Texto'],['accent','Botón y acentos']
];
const ERROR_MESSAGES = {
  401:'Tu sesión terminó. Vuelve a ingresar a DISCADMIN.',
  403:'No tienes permiso para realizar esta acción. Tus cambios siguen aquí.',
  409:'Otra edición cambió la versión. Copia tus cambios antes de recargar; no se sobrescribieron.',
  503:'El editor no está disponible ahora. Tus cambios permanecen en pantalla.',
  422:'Revisa las variables, textos, colores y archivos de Media antes de continuar.'
};
const safe = value => String(value == null ? '' : value).replace(/[&<>"']/g,
  char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const make = (tag, text, className) => {
  const element = document.createElement(tag);
  if (text !== undefined) element.textContent = text;
  if (className) element.className = className;
  return element;
};
const validColor = value => /^#[0-9a-fA-F]{6}$/.test(String(value));
const allowedPath = path => {
  const value = String(path || '');
  return /^\/?uploads\/[a-zA-Z0-9._/-]+\.(?:png|jpe?g|gif|webp)$/i.test(value)
    && !value.includes('..') ? (value.startsWith('/') ? value : '/' + value) : '';
};
const textContrast = hex => {
  if (!validColor(hex)) return '#111111';
  const colors = [1,3,5].map(i => parseInt(hex.slice(i,i+2),16)/255);
  const lum = colors.map(x => x<=0.04045 ? x/12.92 : Math.pow((x+0.055)/1.055,2.4));
  return 0.2126*lum[0]+0.7152*lum[1]+0.0722*lum[2] > 0.179 ? '#111111' : '#ffffff';
};
async function getCsrf() {
  const globalToken = String(window.csrf || '').trim();
  if (globalToken) return globalToken;
  const response = await fetch('/api/auth',{credentials:'same-origin',cache:'no-store'});
  const json = await response.json();
  if (!response.ok || !json.authenticated || !json.csrf) throw new Error('AUTH_REQUIRED');
  return String(json.csrf);
}
async function request(view, data, options = {}) {
  const post = !!data;
  const url = post ? ENDPOINT : ENDPOINT + '?view=' + encodeURIComponent(view)
    + (options.key ? '&key=' + encodeURIComponent(options.key) + '&scope=' + encodeURIComponent(options.scope) : '')
    + (options.versionId ? '&version_id=' + options.versionId : '');
  const headers = post ? {'Content-Type':'application/json','X-CSRF-Token':await getCsrf()} : {};
  const response = await fetch(url,{
    method:post?'POST':'GET',credentials:'same-origin',cache:'no-store',headers,
    ...(post ? {body:JSON.stringify(data)} : {}),signal:options.signal
  });
  const json = await response.json().catch(() => ({ok:false}));
  if (!response.ok || json.ok!==true || !json.data || typeof json.data!=='object') {
    const error = new Error('MAIL_API_UNAVAILABLE'); error.status = response.status;
    throw error;
  }
  if (post && json.mail_sent!==false) throw new Error('MAIL_API_UNAVAILABLE');
  return json.data;
}
function emailHtml(doc, assets) {
  const theme = doc.theme || {};
  const bg = validColor(theme.background) ? theme.background : '#111111';
  const fg = validColor(theme.foreground) ? theme.foreground : '#f7f7f7';
  const accent = validColor(theme.accent) ? theme.accent : '#ffffff';
  const foreground = textContrast(bg);
  const buttonText = textContrast(accent);
  const logo = assets.get(Number(theme.logo_media_id));
  const hero = assets.get(Number(theme.hero_media_id));
  const image = (item,alt,width) => item && item.path
    ? '<img src="' + safe(item.path) + '" alt="' + safe(alt) +
      '" width="' + width + '" style="display:block;max-width:100%;height:auto;border:0;margin:0 auto 20px;">'
    : '';
  const isTheme = !Object.prototype.hasOwnProperty.call(doc,'subject');
  return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
    + '<meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; img-src https: http:; style-src &#39;unsafe-inline&#39;">'
    + '</head><body style="margin:0;padding:24px 12px;background:' + bg + ';color:' + foreground + ';font-family:Arial,Helvetica,sans-serif;">'
    + '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;max-width:600px;width:100%;border-collapse:collapse;">'
    + '<tr><td style="padding:32px;background:' + bg + ';color:' + fg + ';border:1px solid ' + accent + ';">'
    + image(logo,'Logo de marca (vista de prueba)',180)
    + '<p style="font-size:12px;letter-spacing:2px;margin:0 0 22px;color:' + fg + ';">BRVTAL</p>'
    + image(hero,'Imagen del correo (vista de prueba)',530)
    + '<h1 style="font-size:26px;line-height:1.3;margin:0 0 20px;color:' + fg + ';">'
    + safe(isTheme?'Identidad visual de correo':doc.title) + '</h1>'
    + '<p style="font-size:16px;line-height:1.65;white-space:pre-line;margin:0 0 28px;color:' + fg + ';">'
    + safe(isTheme?'Diseño compartido con los correos transaccionales.':doc.message) + '</p>'
    + (isTheme?'':'<span style="display:inline-block;padding:13px 22px;background:' + accent
      + ';color:' + buttonText + ';font-weight:bold;font-size:15px;border-radius:4px;">'
      + safe(doc.cta) + '</span>')
    + '<p style="font-size:12px;line-height:1.6;margin:26px 0 0;color:' + fg + ';">'
    + safe(isTheme?'BRVTAL · Vista sintética':doc.footer)
    + '</p><p style="font-size:11px;line-height:1.5;margin:14px 0 0;color:' + fg
    + ';">Los enlaces de seguridad no se pueden editar: se generan en el servidor al enviar.</p>'
    + '</td></tr></table></body></html>';
}
export function mount(panel, active = () => true, role = '') {
  if (!panel) return () => {};
  let alive=true, epoch=0, busy=false, dirty=false, previewTimer=null;
  let current=null, catalog=[], history=[], revision=0, draftId=null, activeId=null;
  let documentCopy=null, assets=new Map(), previewMode='desktop', previewPayload=null;
  const writable=WRITERS.has(String(role || '').toLowerCase());
  const workspace=make('section',undefined,'mail-templates');
  workspace.dataset.mailWorkspace='';
  workspace.innerHTML =
    '<header class="mail-templates__heading"><div><h3>Correos transaccionales</h3>'
    + '<p>Diseña mensajes sin cambiar destinatarios, enlaces de seguridad ni envíos reales.</p></div>'
    + '<span class="mail-templates__privacy">Vista previa con datos ficticios</span></header>'
    + '<div class="mail-templates__navigation" role="tablist" aria-label="Vistas de correos">'
    + '<button type="button" role="tab" data-mail-tab="templates" aria-selected="true" tabindex="0">Plantillas</button>'
    + '<button type="button" role="tab" data-mail-tab="history" aria-selected="false" tabindex="-1">Historial</button></div>'
    + '<output class="mail-templates__status" data-mail-status role="status" aria-live="polite"></output>'
    + '<div data-mail-content></div>';
  panel.replaceChildren(workspace);
  const content=workspace.querySelector('[data-mail-content]');
  const status=workspace.querySelector('[data-mail-status]');
  const showStatus=(message,isError=false)=>{
    if (!alive || !active()) return;
    status.textContent=message;
    status.dataset.level=isError?'error':'info';
  };
  const setBusy=value=>{busy=value;workspace.setAttribute('aria-busy',String(value));};
  const errorMessage=error=>ERROR_MESSAGES[error.status] ||
    (error.message==='AUTH_REQUIRED'?ERROR_MESSAGES[401]:'No se pudo completar la operación. Revisa la conexión y reintenta.');
  const selection=()=>current && {key:current.key,scope:current.scope};
  function renderList(list) {
    list.replaceChildren();
    for (const item of catalog) {
      const button=make('button',undefined,'mail-templates__item');
      button.type='button';button.dataset.mailKey=item.key;
      button.setAttribute('aria-current',String(!!current && current.key===item.key));
      const name=make('strong',NAMES[item.key]||item.key);
      const trigger=make('span',(TRIGGERS[item.key]||'Correo')+' · '+item.scope);
      const version=make('span','Versión '+(item.lastVersion || 'base'));
      button.append(name,trigger,version);
      button.addEventListener('click',()=>selectItem(item));
      list.append(button);
    }
  }
  function editableField(name,label,type,max) {
    const wrap=make('label',undefined,'mail-templates__field');
    const nameNode=make('span',label);
    const input=make(type==='textarea'?'textarea':'input');
    input.dataset.mailField=name;input.name=name;
    if(type!=='textarea')input.type='text';else input.rows=name==='message'?6:3;
    input.maxLength=max;input.value=String(documentCopy[name]||'');
    input.disabled=!writable;
    input.addEventListener('input',onEdit);
    wrap.append(nameNode,input);
    return wrap;
  }
  function colorField(name,label) {
    const wrap=make('label',undefined,'mail-templates__field mail-templates__color');
    wrap.append(make('span',label));
    const row=make('span',undefined,'mail-templates__color-controls');
    const picker=make('input');picker.type='color';picker.dataset.mailColorPicker=name;
    picker.value=validColor(documentCopy.theme[name])?documentCopy.theme[name]:'#ffffff';
    const hex=make('input');hex.type='text';hex.maxLength=7;hex.pattern='#[0-9A-Fa-f]{6}';
    hex.dataset.mailColor=name;hex.value=picker.value;hex.spellcheck=false;
    picker.disabled=!writable;hex.disabled=!writable;
    picker.addEventListener('input',()=>{hex.value=picker.value;onEdit();});
    hex.addEventListener('input',()=>{if(validColor(hex.value))picker.value=hex.value;onEdit();});
    row.append(picker,hex);wrap.append(row);return wrap;
  }
  function mediaControl(name,label) {
    const wrap=make('div',undefined,'mail-templates__field');
    wrap.append(make('span',label));
    const row=make('div',undefined,'mail-templates__media-row');
    const summary=make('output',documentCopy.theme[name]?'Media #'+documentCopy.theme[name]:'Sin imagen');
    summary.dataset.mailMediaValue=name;
    const choose=make('button','Elegir de Media','btn ghost');choose.type='button';
    choose.disabled=!writable;choose.addEventListener('click',()=>chooseMedia(name));
    const clear=make('button','Quitar','btn ghost');clear.type='button';clear.disabled=!writable;
    clear.addEventListener('click',()=>{
      documentCopy.theme[name]=null;summary.textContent='Sin imagen';onEdit();
    });
    row.append(summary,choose,clear);wrap.append(row);return wrap;
  }
  function collect() {
    const copy=structuredClone(documentCopy);
    for(const node of workspace.querySelectorAll('[data-mail-field]'))copy[node.dataset.mailField]=node.value;
    for(const node of workspace.querySelectorAll('[data-mail-color]'))copy.theme[node.dataset.mailColor]=node.value;
    return copy;
  }
  function validDocument(copy) {
    for(const [name] of COLORS)if(!validColor(copy.theme[name]))return 'Los colores deben tener formato hexadecimal #RRGGBB.';
    const allowed=current.allowed_variables || [];
    for(const key of Object.keys(copy)) {
      if(typeof copy[key]!=='string')continue;
      const text=copy[key];
      const tokens=[...text.matchAll(/\{\{([a-z_]{1,40})\}\}/g)].map(x=>x[1]);
      const stripped=text.replace(/\{\{[a-z_]{1,40}\}\}/g,'');
      if(tokens.some(variable=>!allowed.includes(variable)) || /[{}]/.test(stripped))
        return 'Hay una variable desconocida; usa solo las variables de la lista.';
      if(/[<>]/.test(text)||/https?:\/\/|www\.|(?:data|javascript):/i.test(text))
        return 'No ingreses HTML ni URL: los enlaces seguros vienen del servidor.';
    }
    return '';
  }
  function onEdit(){
    if(!current||!documentCopy)return;
    dirty=true;previewPayload=null;
    updateActions();queuePreview();
    showStatus('Cambios sin guardar. El correo activo no se modificó.');
  }
  function updateActions(){
    for(const el of workspace.querySelectorAll('[data-mail-action]')){
      el.disabled=busy || !writable || (el.dataset.mailAction==='publish'&&(!draftId||dirty));
    }
    const state=workspace.querySelector('[data-mail-draft-state]');
    if(state)state.textContent=dirty?'Sin guardar':draftId?'Borrador #'+draftId:'Plantilla activa o predeterminada';
  }
  function plainText(copy) {
    if(!copy)return '';
    return ['Asunto: '+(copy.subject||'Vista de marca'),copy.preheader||'',copy.title||'',copy.message||'',copy.cta||'',copy.footer||'',
      'Los enlaces privados se generan en el servidor; no aparecen en esta prueba.'].join('\n\n');
  }
  function paintPreview(){
    const iframe=workspace.querySelector('[data-mail-preview-frame]');
    const plain=workspace.querySelector('[data-mail-preview-plain]');
    if(!iframe||!plain)return;
    const copy=previewPayload;
    const isPlain=previewMode==='plain';
    iframe.hidden=isPlain;plain.hidden=!isPlain;
    iframe.dataset.previewMode=previewMode;
    if(copy){
      iframe.srcdoc=emailHtml(copy,assets);
      plain.textContent=plainText(copy);
    }else{
      iframe.srcdoc='<html lang="es"><body style="font-family:Arial;padding:25px;">Vista previa pendiente de validación.</body></html>';
      plain.textContent='Vista previa pendiente de validación.';
    }
  }
  function queuePreview() {
    if(previewTimer!==null)clearTimeout(previewTimer);
    const selectionId=epoch;
    previewTimer=setTimeout(async()=>{
      previewTimer=null;
      if(!alive||!active()||!current||selectionId!==epoch)return;
      const copy=collect();
      const validation=validDocument(copy);
      if(validation){previewPayload=null;paintPreview();showStatus(validation,true);return;}
      try{
        const data=await request('preview',{action:'preview',...selection(),template_copy:copy});
        if(!alive||!active()||selectionId!==epoch)return;
        if(data.synthetic!==true||data.mail_sent!==false)throw new Error('MAIL_API_UNAVAILABLE');
        previewPayload=data.template_copy;paintPreview();
      }catch(error){
        if(selectionId===epoch&&alive&&active()){previewPayload=null;paintPreview();showStatus(errorMessage(error),true);}
      }
    },300);
  }
  async function chooseMedia(name) {
    const dialog=make('dialog',undefined,'mail-templates__dialog');
    const heading=make('h4','Selecciona una imagen publicada de Media');
    const description=make('p','Solo imágenes publicadas. Los archivos no se suben desde el editor.');
    const options=make('div',undefined,'mail-templates__media-options');
    const close=make('button','Cerrar','btn ghost');close.type='button';
    close.addEventListener('click',()=>dialog.close());
    dialog.append(heading,description,options,close);
    workspace.append(dialog);dialog.addEventListener('close',()=>dialog.remove(),{once:true});
    dialog.showModal();
    options.append(make('p','Cargando biblioteca…'));
    try{
      const response=await fetch(MEDIA,{credentials:'same-origin',cache:'no-store'});
      const json=await response.json();
      if(!response.ok||json.ok!==true||!Array.isArray(json.data))throw new Error('MEDIA_UNAVAILABLE');
      options.replaceChildren();
      const available=json.data.filter(item=>item.type==='image'&&item.status==='published'&&
        Number.isSafeInteger(Number(item.id))&&Number(item.id)>0&&allowedPath(item.file_path));
      if(!available.length)options.append(make('p','No hay imágenes publicadas disponibles.'));
      for(const item of available.slice(0,120)){
        assets.set(Number(item.id),{path:allowedPath(item.file_path),label:String(item.title||'Imagen')});
        const button=make('button',undefined,'mail-templates__media-option');button.type='button';
        const img=make('img');img.src=allowedPath(item.file_path);img.alt='';img.loading='lazy';
        button.append(img,make('span',String(item.title||'Imagen')+' · #'+item.id));
        button.addEventListener('click',()=>{
          if(!alive||!active())return;
          documentCopy.theme[name]=Number(item.id);
          const output=workspace.querySelector('[data-mail-media-value="'+name+'"]');
          if(output)output.textContent='Media #'+item.id;
          onEdit();dialog.close();
        });
        options.append(button);
      }
    }catch{
      options.replaceChildren(make('p','Media no está disponible. Puedes continuar editando texto y colores.'));
    }
  }
  function renderEditor(){
    content.replaceChildren();
    const layout=make('div',undefined,'mail-templates__layout');
    const sidebar=make('aside',undefined,'mail-templates__sidebar');
    sidebar.append(make('h4','Catálogo de plantillas'));
    const list=make('div',undefined,'mail-templates__catalog');list.dataset.mailCatalog='';
    sidebar.append(list);
    const main=make('div',undefined,'mail-templates__editor');
    const header=make('div',undefined,'mail-templates__editor-title');
    header.append(make('h4',NAMES[current.key]||current.key),
      make('p',TRIGGERS[current.key]||'Correo transaccional'),
      make('span','Estado: ', 'mail-templates__state-label'));
    const state=make('strong');state.dataset.mailDraftState='';header.lastChild.append(state);
    const form=make('form',undefined,'mail-templates__form');form.noValidate=true;
    form.addEventListener('submit',event=>event.preventDefault());
    if(current.key!=='brand_theme'){
      for(const [name,label,type,max] of FIELDS)form.append(editableField(name,label,type,max));
      const variables=make('div',undefined,'mail-templates__variables');
      variables.append(make('strong','Variables permitidas'),
        make('p','Los enlaces de verificación y recuperación son obligatorios, seguros y no editables. Los inserta el servidor.'));
      const chips=make('div',undefined,'mail-templates__chips');
      for(const name of current.allowed_variables||[]){
        const button=make('button','{{'+name+'}}','mail-templates__chip');button.type='button';button.disabled=!writable;
        button.addEventListener('click',()=>{
          const target=form.querySelector('[data-mail-field="message"]');
          if(!target)return;
          const at=target.selectionStart;
          target.setRangeText('{{'+name+'}}',at,target.selectionEnd,'end');
          target.focus();onEdit();
        });
        chips.append(button);
      }
      if(!chips.children.length)chips.append(make('span','Sin variables editables en esta plantilla.'));
      variables.append(chips);form.append(variables);
    }
    const brand=make('fieldset',undefined,'mail-templates__brand');
    brand.append(make('legend','Identidad de marca'),
      make('p','Color de la tarjeta y del texto derivados del tema. Tipografía email segura: Arial, 16 px; contenedor de 600 px.'));
    for(const [name,label] of COLORS)brand.append(colorField(name,label));
    brand.append(mediaControl('logo_media_id','Logo de Media (opcional)'),
      mediaControl('hero_media_id','Imagen de Media (opcional)'));form.append(brand);
    const actions=make('div',undefined,'mail-templates__actions');
    for(const [action,label] of [['save','Guardar borrador'],['publish','Publicar']]){
      const button=make('button',label,action==='publish'?'btn':'btn ghost');
      button.type='button';button.dataset.mailAction=action;
      button.addEventListener('click',()=>action==='save'?saveDraft():publish());
      actions.append(button);
    }
    const reload=make('button','Recargar','btn ghost');reload.type='button';
    reload.addEventListener('click',()=>{
      if(dirty&&!window.confirm('¿Descartar cambios sin guardar?'))return;
      selectItem(current);
    });
    const test=make('button','Enviar prueba','btn ghost');test.type='button';test.disabled=true;
    test.title='Solo disponible cuando la integración segura de pruebas esté habilitada y autorizada.';
    const note=make('small','Envío de prueba deshabilitado: se requiere integración segura #1875.');
    actions.append(reload,test);
    form.append(actions,note);
    const preview=make('section',undefined,'mail-templates__preview');
    preview.append(make('h4','Vista previa validada'));
    const modes=make('div',undefined,'mail-templates__modes');
    for(const [mode,label] of [['desktop','Escritorio'],['mobile','Móvil'],['plain','Texto plano']]){
      const button=make('button',label,'btn ghost');button.type='button';
      button.dataset.mailPreviewMode=mode;button.setAttribute('aria-pressed',String(previewMode===mode));
      button.addEventListener('click',()=>{
        previewMode=mode;
        for(const node of modes.children)node.setAttribute('aria-pressed',String(node===button));
        paintPreview();
      });
      modes.append(button);
    }
    const frame=make('iframe');frame.dataset.mailPreviewFrame='';
    frame.title='Correo de prueba, sin enlaces activos ni datos personales';
    frame.setAttribute('sandbox','');frame.referrerPolicy='no-referrer';
    const plain=make('pre');plain.dataset.mailPreviewPlain='';
    preview.append(modes,frame,plain);
    main.append(header,form,preview);layout.append(sidebar,main);content.append(layout);
    renderList(list);updateActions();paintPreview();queuePreview();
  }
  function renderHistory(){
    content.replaceChildren();
    const header=make('div',undefined,'mail-templates__history-head');
    header.append(make('h4','Historial de versiones'),make('p','Restaurar crea una versión nueva; no borra el historial anterior.'));
    const choose=make('select');choose.setAttribute('aria-label','Plantilla del historial');
    for(const entry of catalog){
      const option=make('option',NAMES[entry.key]||entry.key);option.value=entry.key;
      option.selected=current?.key===entry.key;choose.append(option);
    }
    choose.addEventListener('change',()=>{
      const selected=catalog.find(entry=>entry.key===choose.value);
      if(selected)selectItem(selected,'history');
    });
    header.append(choose);
    const list=make('div',undefined,'mail-templates__history-list');
    if(!history.length)list.append(make('p','Aún no hay versiones; se utilizarán los valores predeterminados.'));
    for(const item of history){
      const row=make('div',undefined,'mail-templates__history-item');
      const meta=make('div');
      const label=item.kind==='draft'?'Borrador':item.kind==='rollback'?'Restauración':'Publicada';
      meta.append(make('strong',label+' · versión '+item.revision),
        make('small','ID '+item.version_id+' · '+(item.created_at||'Fecha no disponible')));
      row.append(meta);
      if(item.first_published_at && !item.discarded_at){
        const button=make('button','Restablecer versión','btn ghost');button.type='button';
        button.disabled=!writable||busy;
        button.addEventListener('click',()=>restore(item.version_id));
        row.append(button);
      }
      list.append(row);
    }
    content.append(header,list);
  }
  function activateTab(tab) {
    for(const button of workspace.querySelectorAll('[data-mail-tab]')){
      const selected=button.dataset.mailTab===tab;
      button.setAttribute('aria-selected',String(selected));button.tabIndex=selected?0:-1;
    }
    if(!current)return;
    if(tab==='history'){renderHistory();return;}
    renderEditor();
  }
  async function readHistory(item) {
    try{
      const data=await request('history',null,selection()||{key:item.key,scope:item.scope});
      return Array.isArray(data.items)?data.items:[];
    }catch{return [];}
  }
  async function selectItem(item,tab='templates'){
    if(busy)return;
    if(dirty && !window.confirm('Hay cambios sin guardar. ¿Descartarlos para abrir otra plantilla?'))return;
    const id=++epoch;current=item;dirty=false;previewPayload=null;draftId=null;history=[];
    showStatus('Cargando '+(NAMES[item.key]||item.key)+'…');setBusy(true);
    try{
      const [activeData,historyData]=await Promise.all([
        request('active',null,{key:item.key,scope:item.scope}),
        request('history',null,{key:item.key,scope:item.scope}).catch(()=>({items:[]}))
      ]);
      if(!alive||!active()||id!==epoch)return;
      revision=Number(activeData.revision)||0;activeId=activeData.version_id||null;
      history=Array.isArray(historyData.items)?historyData.items:[];
      item.lastVersion=history.length?history[0].revision:(activeId||'base');
      documentCopy=structuredClone(activeData.document||item.defaults);
      const latest=history.find(x=>x.kind==='draft'&&!x.first_published_at&&!x.discarded_at);
      if(latest){
        try{
          const stored=await request('draft',null,{key:item.key,scope:item.scope,versionId:latest.version_id});
          if(!alive||!active()||id!==epoch)return;
          if(stored.document){documentCopy=structuredClone(stored.document);draftId=latest.version_id;}
        }catch{showStatus('No se pudo cargar el borrador; se usa la versión activa.',true);}
      }
      if(!alive||!active()||id!==epoch)return;
      activateTab(tab);
      if(!status.textContent.includes('No se pudo'))showStatus('Versión cargada. Guardar borrador no publica.');
    }catch(error){
      if(id!==epoch||!alive||!active())return;
      content.replaceChildren(make('p',errorMessage(error),'mail-templates__error'));
      const retry=make('button','Reintentar','btn ghost');retry.type='button';
      retry.addEventListener('click',()=>selectItem(item,tab));
      content.append(retry);showStatus(errorMessage(error),true);
    }finally{if(id===epoch){setBusy(false);updateActions();}}
  }
  async function saveDraft(){
    if(busy||!writable)return;
    const id=epoch;const copy=collect();const validation=validDocument(copy);
    if(validation){showStatus(validation,true);return;}
    setBusy(true);updateActions();
    try{
      const result=await request('save_draft',{action:'save_draft',...selection(),
        template_copy:copy,revision});
      if(id!==epoch||!alive||!active())return;
      revision=result.revision;draftId=result.version_id;documentCopy=copy;dirty=false;
      history=await readHistory(current);
      showStatus('Borrador guardado. La versión publicada no cambió.');
    }catch(error){if(id===epoch)showStatus(errorMessage(error),true);}
    finally{if(id===epoch){setBusy(false);updateActions();}}
  }
  async function publish(){
    if(busy||!writable)return;
    if(dirty||!draftId){showStatus('Guarda un borrador antes de publicar.',true);return;}
    if(!window.confirm('¿Publicar este borrador? Cambiará el correo activo para próximos envíos.'))return;
    const id=epoch;setBusy(true);updateActions();
    try{
      const result=await request('publish',{action:'publish',...selection(),
        version_id:draftId,revision});
      if(id!==epoch||!alive||!active())return;
      revision=result.revision;activeId=result.version_id;draftId=null;dirty=false;
      history=await readHistory(current);
      showStatus('Plantilla publicada. El historial anterior se conserva.');
    }catch(error){if(id===epoch)showStatus(errorMessage(error),true);}
    finally{if(id===epoch){setBusy(false);updateActions();}}
  }
  async function restore(versionId){
    if(busy||!writable)return;
    if(dirty){showStatus('Guarda o descarta los cambios antes de restaurar.',true);return;}
    if(!window.confirm('¿Restablecer esta versión publicada? Se creará una versión nueva.'))return;
    const id=epoch;setBusy(true);updateActions();
    try{
      await request('restore',{action:'restore',...selection(),
        previous_version_id:versionId,revision});
      if(id!==epoch||!alive||!active())return;
      showStatus('Versión restaurada sin borrar el historial.');
      setBusy(false);await selectItem(current,'history');
      showStatus('Versión restaurada. El historial permanece disponible.');
    }catch(error){if(id===epoch)showStatus(errorMessage(error),true);}
    finally{if(id===epoch){setBusy(false);updateActions();}}
  }
  workspace.querySelector('[data-mail-tab="templates"]').addEventListener('click',()=>{
    if(current)activateTab('templates');
  });
  workspace.querySelector('[data-mail-tab="history"]').addEventListener('click',()=>{
    if(current)activateTab('history');
  });
  workspace.querySelector('.mail-templates__navigation').addEventListener('keydown',event=>{
    if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
    const tabs=[...workspace.querySelectorAll('[data-mail-tab]')];
    const index=tabs.indexOf(document.activeElement);if(index<0)return;
    event.preventDefault();
    const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:
      (index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
    tabs[next].click();tabs[next].focus();
  });
  const start=async()=>{
    setBusy(true);showStatus('Cargando catálogo…');
    try{
      const response=await request('catalog');
      if(!alive||!active())return;
      catalog=Array.isArray(response.items)?response.items.filter(x=>x&&x.key&&x.scope&&x.defaults):[];
      if(!catalog.length)throw new Error('MAIL_API_UNAVAILABLE');
      setBusy(false);await selectItem(catalog[0]);
    }catch(error){
      if(!alive||!active())return;
      setBusy(false);showStatus(errorMessage(error),true);
      const retry=make('button','Reintentar catálogo','btn ghost');retry.type='button';
      retry.addEventListener('click',start);content.replaceChildren(retry);
    }
  };
  start();
  return ()=>{
    alive=false;++epoch;
    if(previewTimer!==null)clearTimeout(previewTimer);
    for(const dialog of workspace.querySelectorAll('dialog[open]'))dialog.close();
  };
}
