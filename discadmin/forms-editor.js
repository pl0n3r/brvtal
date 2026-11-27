window.BRVTALFormsEditor = (() => {
  'use strict';
  const endpoint='/api/admin-form-definitions.php';
  const types={text:'Texto',email:'Correo',textarea:'Texto largo',select:'Lista',radio:'Opción única',checkbox:'Casilla',date:'Fecha',consent:'Consentimiento'};
  const el=(tag,text='',cls='')=>{const n=document.createElement(tag); n.textContent=text;if(cls){n.className=cls;} return n;};
  const button=(label,fn)=>{const n=el('button',label,'btn');n.type='button';n.onclick=()=>{void fn();};return n;};
  const uid=prefix=>prefix+'_'+crypto.randomUUID().replaceAll('-','');
  const blank=()=>({title:'',fields:[],privacy:null,delivery:{actions:[]}});
  const input=(type,value,update)=>{const n=el('input');n.type=type;if(['checkbox','radio'].includes(type)){n.checked=value===true;}else{n.value=value??'';}n.oninput=()=>update(type==='checkbox'?n.checked:n.value);return n;};
  const label=(name,control)=>{const n=el('label',name);n.append(control);return n;};
  function select(values,value,update){const n=el('select');for(const [id,name] of Object.entries(values)){const o=el('option',name);o.value=id;n.append(o);}n.value=value;n.onchange=()=>update(n.value);return n;}
  async function request(params,body,signal,token){
    const res=await fetch(endpoint+(params?'?'+new URLSearchParams(params):''),{credentials:'same-origin',cache:'no-store',signal,
      ...(body?{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':await token()},body:JSON.stringify(body)}:{})});
    if(!res.ok)throw new Error(String(res.status));
    const data=await res.json();if(data?.ok!==true||!data.data||typeof data.data!=='object'){throw new Error('invalid');}
    return data.data;
  }
  function fieldDefaults(type,notice){const f={id:uid('field'),order:0,type,label:types[type],required:type==='consent'};
    if(['text','email','textarea'].includes(type))f.max_length=type==='email'?254:500;
    if(['select','radio'].includes(type))f.options=[{value:uid('option'),label:'Opción 1'}];
    if(type==='consent'){Object.assign(f,{purpose:'privacy',notice_version:notice||''});}
    return f;}
  function previewOptions(sample,f,title){
    if(f.type==='select'){
      sample.append(label(title,select(Object.fromEntries(f.options.map(o=>[o.value,o.label])),f.options[0]?.value,()=>{})));
      return;
    }
    sample.append(el('p',title));
    for(const o of f.options){const n=input('radio',false,()=>{});n.name=f.id;sample.append(label(o.label,n));}
  }
  function previewField(f){
    const sample=el('div'),title=f.label+(f.required?' *':'');
    if(f.help){sample.append(el('p',f.help));}
    if(['radio','select'].includes(f.type)){previewOptions(sample,f,title);}
    else if(f.type==='textarea'){const n=el('textarea');n.value='Respuesta ficticia';sample.append(label(title,n));}
    else {
      const type=f.type==='consent'?'checkbox':f.type;
      const samples={email:'ejemplo@example.invalid',date:'2026-01-01',text:'Respuesta ficticia'};
      sample.append(label(title,input(type,samples[type]??false,()=>{})));
    }
    return sample;
  }
  function togglePreview(panel,draft){
    const existing=panel.querySelector('[data-preview]');
    if(existing){existing.remove();return;}
    const p=el('section');p.dataset.preview='1';
    p.append(el('h3',draft.title||'Vista previa'),el('p','Solo datos ficticios. No se enviarán respuestas.'));
    for(const f of draft.fields){p.append(previewField(f));}
    panel.append(p);p.scrollIntoView({block:'nearest'});
  }
  function offer(content,status,{current,signal,token,back}){
    return request(null,null,signal,token).then(data=>{
      if(!current()||!Array.isArray(data.items))return;
      content.prepend(button('Editor de formularios',()=>mount(data,content,status,{current,signal,token,back})));
    }).catch(()=>{});
  }
  function mount(initial,content,status,{current,signal,token,back}){
      if(!current())return;
      const panel=el('section','','forms-editor');content.replaceChildren(panel);
      const intro=content.parentElement?.querySelector(':scope > p');
      if(intro){intro.textContent='Edita definiciones y publica versiones de formularios autorizados.';}
      let row=null,draft=null,slug='',baseline='',busy=false,conflict=false,denied=false,drag=-1,guard=null;
      const valid=()=>current()&&panel.isConnected;
      const snapshot=()=>JSON.stringify({slug,draft});const dirty=()=>draft!==null&&snapshot()!==baseline;
      const guardApi=window.BRVTALUnsavedChanges;
      function endGuard(){if(guard){guardApi?.end(guard);guard=null;}}
      function touch(){guardApi?.touch(guard);panel.querySelector('[data-preview]')?.remove();updateActions();}
      const actions=el('div','','forms-actions'), body=el('div');
      function setMessage(message){if(valid())status.textContent=message;}
      async function run(fn){if(busy||!valid()){return;} busy=true;panel.inert=true;setMessage('Procesando…');
        try{await fn();}catch(e){if(!valid()){return;} const messages={'409':'Conflicto de revisión. El borrador local se conserva. Vuelve a abrir la versión guardada antes de publicar.',
          '403':'Permiso no válido. No se guardaron cambios.', '401':'Sesión vencida. No se guardaron cambios.', '419':'Sesión vencida. No se guardaron cambios.',
          '422':'Revisa los campos, el aviso de privacidad y las opciones.'};
          if(e.message==='409'&&row){conflict=true;}
          if(['401','403','419','AUTH_REQUIRED'].includes(e.message)){denied=true;}
          setMessage(messages[e.message]||'No fue posible completar la operación. Se conservan tus cambios.');}
        finally{busy=false;if(valid()){panel.inert=false;updateActions();}}}
      function leave(fn){if(busy){return;} if(dirty()&&!window.confirm('¿Descartar los cambios sin guardar?')){return;} return fn();}
      const exit=button('Volver a respuestas',()=>leave(()=>{endGuard();return back();}));
      function catalog(data){if(!valid()){return;} endGuard();row=null;draft=null;panel.replaceChildren(el('h2','Editor de formularios'),exit);
        const list=el('ul','','forms-list');for(const item of data.items){const li=el('li','','forms-row'),copy=el('div');
          copy.append(el('strong',item.slug),el('span',({active:'Activo',draft:'Borrador',disabled:'Desactivado'}[item.state]||'Estado desconocido')+' · versión '+(item.published_version_number??'sin publicar')));
          li.append(copy,button('Editar '+item.slug,()=>openRecord(item.form_key)));list.append(li);}
        panel.append(button('Crear formulario',()=>edit(null)),list);if(!data.items.length)panel.append(el('p','No hay definiciones todavía.'));
        if(data.next_cursor){panel.append(button('Más formularios',()=>loadCatalog({after:data.next_cursor})));}
        setMessage('');}
      function openRecord(key){return run(async()=>edit(await request({form_key:key},null,signal,token)));}
      function loadCatalog(params=null){return run(async()=>catalog(await request(params,null,signal,token)));}
      function updateActions(){if(!draft||!valid()){return;} for(const n of actions.querySelectorAll('[data-saved]'))n.disabled=busy||dirty()||conflict||denied||!row;
        const save=actions.querySelector('[data-save]');if(save)save.disabled=busy||conflict||denied||(row!==null&&!dirty());}
      async function write(action,extra={}){return run(async()=>{
        const payload=action==='create'?{action,slug,draft}:{action,form_key:row.form_key,revision:row.revision,...extra};
        const result=await request(null,payload,signal,token);if(valid()){edit(result);setMessage('Cambios guardados.');}});}
      function edit(record){if(!valid()){return;} endGuard();row=record;draft=record?structuredClone(record.draft):blank();slug=record?.slug||'';
        draft.fields.sort((a,b)=>a.order-b.order);draft.fields.forEach((f,i)=>{f.order=i;});baseline=snapshot();conflict=false;denied=false;
        panel.replaceChildren(el('h2',row?'Editar formulario':'Nuevo formulario'),el('p',row?'Revisión '+row.revision+' · Versión publicada '+(row.published_version_number??'ninguna'):'Borrador sin publicar'),
          button('Volver a definiciones',()=>leave(loadCatalog)));
        guard=el('div','','forms-editor-draft open');guard.append(body,actions);panel.append(guard);render();guardApi?.begin(guard);setMessage('');}
      function reorder(from,to){if(to<0||to>=draft.fields.length||from===to){return;} const [field]=draft.fields.splice(from,1);draft.fields.splice(to,0,field);renderFields();touch();}
      const fieldList=el('div','','forms-editor-fields');
      function renderFields(){fieldList.replaceChildren();for(const [index,f] of draft.fields.entries()){
        f.order=index;const group=el('fieldset','','forms-editor-field');group.dataset.id=f.id;group.append(el('legend',(index+1)+'. '+types[f.type]));
        const handle=button('Mover con arrastre',()=>{});handle.draggable=true;handle.ondragstart=e=>{drag=index;e.dataTransfer.setData('text/plain',f.id);};
        handle.ondragend=()=>{drag=-1;};group.ondragover=e=>e.preventDefault();group.ondrop=e=>{e.preventDefault();if(drag>=0){reorder(drag,index);}drag=-1;};
        const up=button('Subir',()=>{reorder(index,index-1);fieldList.children[index-1]?.querySelector('button[data-down]')?.focus();});up.dataset.up='1';up.disabled=index===0;
        const down=button('Bajar',()=>{reorder(index,index+1);fieldList.children[index+1]?.querySelector('button[data-up]')?.focus();});down.dataset.down='1';down.disabled=index===draft.fields.length-1;
        group.append(handle,up,down,button('Eliminar campo',()=>{draft.fields.splice(index,1);renderFields();touch();}),
          label('Etiqueta',input('text',f.label,v=>{f.label=v;touch();})),label('Ayuda',input('text',f.help,v=>{f.help=v;touch();})));
        if(f.type!=='consent')group.append(label('Obligatorio',input('checkbox',f.required,v=>{f.required=v;touch();})));
        if(Object.hasOwn(f,'max_length'))group.append(label('Longitud máxima',input('number',f.max_length,v=>{f.max_length=Number(v);touch();})));
        renderFieldDetails(group,f);
        fieldList.append(group);}}
      function renderFieldDetails(group,f){
        if(f.options){for(const [i,o] of f.options.entries()){group.append(label('Opción '+(i+1),input('text',o.label,v=>{o.label=v;touch();})),button('Eliminar opción '+(i+1),()=>{f.options.splice(i,1);renderFields();touch();}));}
          group.append(button('Agregar opción',()=>{if(f.options.length<32){f.options.push({value:uid('option'),label:'Nueva opción'});renderFields();touch();}}));}
        if(f.type==='consent')group.append(label('Finalidad del consentimiento',select({privacy:'Privacidad',marketing:'Marketing'},f.purpose,v=>{f.purpose=v;f.required=v==='privacy';touch();})),label('Versión del consentimiento',input('text',f.notice_version,v=>{f.notice_version=v;touch();})));
      }
      function render(){body.replaceChildren();actions.replaceChildren();
        body.append(label('Nombre del formulario',input('text',draft.title,v=>{draft.title=v;touch();})),label('Slug',input('text',slug,v=>{slug=v;touch();})));
        const type=select(types,'text',()=>{});body.append(label('Tipo de campo',type),button('Agregar campo',()=>{if(draft.fields.length<32){draft.fields.push(fieldDefaults(type.value,draft.privacy?.notice_version));renderFields();touch();}}),fieldList);renderFields();
        const privacy=el('fieldset');privacy.append(el('legend','Privacidad y entrega'));for(const [key,name] of Object.entries({notice_version:'Versión del aviso',notice_text:'Texto del aviso',purpose_key:'Clave de finalidad',retention_policy_key:'Clave de conservación'})){
          privacy.append(label(name,input('text',draft.privacy?.[key],v=>{draft.privacy??={notice_version:'',notice_text:'',purpose_key:'',retention_policy_key:''};draft.privacy[key]=v;touch();})));}
        privacy.append(label('Notificación interna',input('checkbox',draft.delivery.actions.length>0,v=>{draft.delivery.actions=v?['form_entry_received']:[];touch();})));body.append(privacy);
        const save=button('Guardar borrador',()=>write(row?'save':'create',{slug,draft}));save.dataset.save='1';actions.append(save);
        actions.append(button('Vista previa ficticia',()=>togglePreview(panel,draft)));
        if(row){for(const [action,name] of [['publish','Publicar versión'],['disable','Desactivar'],['duplicate','Duplicar definición']]){if(action==='disable'&&row.state==='disabled'){continue;} const b=button(name,()=>{
          if(action==='disable'&&!window.confirm('¿Desactivar este formulario sin eliminar respuestas?')){return;}
          if(action==='duplicate'){const next=window.prompt('Slug para la copia',slug+'-copia');if(!next){return;} return write(action,{slug:next});}return write(action);});b.dataset.saved='1';actions.append(b);}}
        updateActions();}
      catalog(initial);
  }
  return {offer};
})();
