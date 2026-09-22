window.BRVTALBlog = (() => {
  'use strict';

  const endpoint = '/api/blog.php';
  const relatedSources = {
    event: '/api/index.php/events',
    artist: '/api/index.php/artists',
    set: '/api/index.php/sets',
    release: '/api/releases.php'
  };
  const store = {
    root:null,
    posts:[],
    related:{event:[],artist:[],set:[],release:[]},
    relatedState:{event:'loading',artist:'loading',set:'loading',release:'loading'},
    loading:false
  };
  let editorLoadId = 0;
  let editorController = null;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const normalizeMediaPath = value => {
    const raw = String(value || '').trim();
    if (!raw || /^(?:data:|blob:|https?:\/\/)/i.test(raw) || raw.startsWith('/')) return raw;
    return '/' + raw.replace(/^\.?\//,'').replace(/^\/+/,'');
  };
  const slugify = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
  const relationKey = relation => `${String(relation?.related_type || '')}:${Number(relation?.related_id)}`;

  function setStatus(message='',kind='') {
    const el=store.root?.querySelector('#blog-status'); if(!el)return;
    el.textContent=message;el.className='blog-status'+(kind?' '+kind:'');
  }
  async function csrfToken(){
    try{if(typeof csrf!=='undefined'&&csrf)return csrf}catch(_){}
    const r=await fetch('/api/index.php/auth',{credentials:'same-origin',cache:'no-store'});const j=await r.json().catch(()=>({}));
    if(!r.ok||!j.authenticated||!j.csrf)throw new Error('AUTH_REQUIRED');return j.csrf;
  }
  async function request(query='',options={}){
    const opts={...options,credentials:'same-origin',cache:'no-store'};const method=String(opts.method||'GET').toUpperCase();opts.headers={...(opts.headers||{})};
    if(['POST','PUT','PATCH','DELETE'].includes(method))opts.headers['X-CSRF-Token']=await csrfToken();
    const r=await fetch(endpoint+query,opts);const j=await r.json().catch(()=>({ok:false,error:'INVALID_RESPONSE'}));
    if(!r.ok||j.ok===false){const e=new Error(j.error||('HTTP_'+r.status));e.status=r.status;e.payload=j;throw e}return j;
  }
  function cover(record){const src=normalizeMediaPath(record?.cover_image||'');return src?`<img class="blog-cover" src="${esc(src)}" alt="${esc(record?.title||'Blog cover')}" loading="lazy">`:'<div class="blog-cover-ph">NO IMG</div>'}
  function visibleRows(){if(!store.root)return[];const q=(store.root.querySelector('#blog-search')?.value||'').trim().toLowerCase();const status=store.root.querySelector('#blog-status-filter')?.value||'';return store.posts.filter(post=>{if(status&&post.status!==status)return false;if(!q)return true;return [post.title,post.slug,post.excerpt].join(' ').toLowerCase().includes(q)})}
  function renderMetrics(){if(!store.root)return;const values={'blog-total':store.posts.length,'blog-published':store.posts.filter(x=>x.status==='published').length,'blog-drafts':store.posts.filter(x=>x.status==='draft').length,'blog-featured':store.posts.filter(x=>Number(x.featured)===1).length};Object.entries(values).forEach(([id,v])=>{const el=store.root.querySelector('#'+id);if(el)el.textContent=String(v)})}
  function orderingAvailable() {
    if (!store.root) return false;
    const query = (store.root.querySelector('#blog-search')?.value || '').trim();
    const status = store.root.querySelector('#blog-status-filter')?.value || '';
    return query === '' && status === '';
  }

  function blogRow(post) {
    const featured = Number(post.featured) === 1 ? ' · FEATURED' : '';
    return `
      <article
        class="blog-row"
        data-blog-id="${Number(post.id)}"
        data-order-id="${Number(post.id)}"
      >
        <div>${cover(post)}</div>
        <div>
          <div class="blog-title">${esc(post.title)}</div>
          <div class="blog-meta">/${esc(post.slug)}${featured}</div>
        </div>
        <div class="blog-excerpt">${esc(post.excerpt || 'NO EXCERPT')}</div>
        <div class="blog-date">
          <div class="blog-meta">PUBLISHED</div>
          <b>${esc(post.published_at || 'NOT YET')}</b>
        </div>
        <div class="blog-actions">
          <span class="blog-status-wrap">
            <span class="blog-status-pill ${esc(post.status)}">${esc(post.status)}</span>
          </span>
          <button class="iconbtn" type="button" data-edit>EDIT</button>
          <button class="iconbtn" type="button" data-delete>DELETE</button>
        </div>
      </article>
    `;
  }

  function render() {
    if (!store.root) return;
    renderMetrics();
    const grid = store.root.querySelector('#blog-grid');
    if (!grid) return;
    const rows = visibleRows();
    if (window.BRVTALDataGrid?.render?.('blog',grid,rows,{
      allRows:store.posts,
      orderingEnabled:orderingAvailable()
    })) return;
    if (!rows.length) {
      grid.innerHTML = '<div class="blog-empty">NO POSTS MATCH THIS VIEW</div>';
      return;
    }
    grid.innerHTML = rows.map(blogRow).join('');
    grid.querySelectorAll('[data-blog-id]').forEach(row => {
      const id = Number(row.dataset.blogId);
      row.querySelector('[data-edit]')?.addEventListener('click', () => openEditor(id));
      row.querySelector('[data-delete]')?.addEventListener('click', () => remove(id));
    });
  }

  async function loadRelated(){
    Object.keys(relatedSources).forEach(type=>{store.relatedState[type]='loading'});
    const results=await Promise.all(Object.entries(relatedSources).map(async([type,url])=>{
      try{
        const r=await fetch(url,{credentials:'same-origin',cache:'no-store'});
        const j=await r.json().catch(()=>null);
        if(!r.ok||!j||j.ok===false||!Array.isArray(j.data))throw new Error(j?.error||('HTTP_'+r.status));
        return [type,{state:'ready',data:j.data}];
      }catch(error){
        return [type,{state:'error',data:[],error:error?.message||'RELATED_SOURCE_UNAVAILABLE'}];
      }
    }));
    results.forEach(([type,result])=>{store.related[type]=result.data;store.relatedState[type]=result.state});
  }
  async function refresh(){if(store.loading)return;store.loading=true;try{setStatus('Loading blog…');const [result]=await Promise.all([request(''),loadRelated()]);store.posts=Array.isArray(result.data)?result.data:[];render();const relatedErrors=Object.values(store.relatedState).some(state=>state==='error');setStatus(relatedErrors?'EDITORIAL READY · RELATED SOURCE WARNING':'EDITORIAL READY',relatedErrors?'err':'ok')}catch(error){if(error?.message==='BLOG_SCHEMA_MISSING'){const grid=store.root?.querySelector('#blog-grid');if(grid)grid.innerHTML='<div class="blog-schema-note"><b>BLOG DATABASE MIGRATION REQUIRED</b><br>Run database/migration_blog_01.sql before using this module.</div>';setStatus('BLOG_SCHEMA_MISSING','err')}else setStatus('Unable to load blog: '+(error?.message||'UNKNOWN_ERROR'),'err')}finally{store.loading=false}}
  function input(id){return document.getElementById(id)}function value(id){return input(id)?.value?.trim?.()??''}
  function relationLabel(type,item){if(type==='artist')return item.name||item.slug||('Artist '+item.id);return item.title||item.name||item.slug||(type+' '+item.id)}
  function relationBoxes(record){const selected=new Set((record?.relations||[]).map(relationKey));return `<div class="blog-related-grid">${Object.entries(store.related).map(([type,items])=>{const state=store.relatedState[type];const body=state==='error'?`<span class="helper blog-related-error">Unable to load ${esc(type.toUpperCase())}S. Existing relations will be preserved.</span>`:items.length?items.map(item=>`<label class="blog-related-item"><input type="checkbox" data-blog-related-type="${type}" data-blog-related-id="${Number(item.id)}" ${selected.has(type+':'+Number(item.id))?'checked':''}><span>${esc(relationLabel(type,item))}</span></label>`).join(''):'<span class="helper">No records available.</span>';return `<div class="blog-related-box" data-blog-related-source="${type}" data-state="${state}"><strong>${type.toUpperCase()}S</strong>${body}</div>`}).join('')}</div>`}

  function mergeRelations(existingRelations=[]){
    const failedTypes=new Set(Object.entries(store.relatedState).filter(([,state])=>state==='error').map(([type])=>type));
    const checked=[...document.querySelectorAll('[data-blog-related-type]:checked')].map(el=>({related_type:String(el.dataset.blogRelatedType||''),related_id:Number(el.dataset.blogRelatedId)})).filter(relation=>relation.related_type&&Number.isFinite(relation.related_id));
    const checkedKeys=new Set(checked.map(relationKey));
    const existing=(Array.isArray(existingRelations)?existingRelations:[]).map((relation,index)=>({related_type:String(relation?.related_type||''),related_id:Number(relation?.related_id),sort_order:Number.isFinite(Number(relation?.sort_order))?Number(relation.sort_order):index})).filter(relation=>relation.related_type&&Number.isFinite(relation.related_id)).sort((a,b)=>a.sort_order-b.sort_order);
    const merged=[];const seen=new Set();
    existing.forEach(relation=>{const key=relationKey(relation);if(failedTypes.has(relation.related_type)||checkedKeys.has(key)){merged.push({related_type:relation.related_type,related_id:relation.related_id});seen.add(key)}});
    checked.forEach(relation=>{const key=relationKey(relation);if(!seen.has(key)){merged.push(relation);seen.add(key)}});
    return merged.map((relation,index)=>({...relation,sort_order:index}));
  }

  function replaceBlogSortOrderControl() {
    const control = document.getElementById('blog_sort_order');
    const field = control?.closest('.field');
    if (!field) return;

    const helper = document.createElement('div');
    helper.className = 'helper';
    helper.textContent = 'Display order is managed visually from the Blog list.';
    field.replaceWith(helper);
  }


  const BLOG_BODY_ALLOWED = new Set(['P','BR','H2','H3','H4','STRONG','B','EM','I','UL','OL','LI','A','BLOCKQUOTE','IMG']);
  const BLOG_BODY_DANGEROUS = new Set(['SCRIPT','STYLE','IFRAME','OBJECT','EMBED','SVG','MATH']);
  const BLOG_BODY_STYLE_TAGS = new Set(['P','H2','H3','H4']);

  function blogBodyAllowedAttributes(tagName){
    if(tagName==='A')return ['href','title','target','rel'];
    if(tagName==='IMG')return ['src','alt','title','width','height'];
    if(BLOG_BODY_STYLE_TAGS.has(tagName))return ['style'];
    return [];
  }

  function cleanBlogBodyStyle(value){
    const match=/(?:^|;)\s*text-align\s*:\s*(left|center|right)\s*(?:;|$)/i.exec(String(value||''));
    return match?'text-align:'+match[1].toLowerCase():'';
  }

  function isSafeBlogBodyUrl(value,image=false){
    const url=String(value||'').trim();
    if(/^https?:\/\//i.test(url))return true;
    if(url.startsWith('/')&&!url.startsWith('//'))return true;
    if(!image&&(url.startsWith('#')||/^mailto:/i.test(url)))return true;
    return false;
  }

  function sanitizeBlogBodyAttribute(node,attr){
    const name=attr.name.toLowerCase();
    const allowed=blogBodyAllowedAttributes(node.tagName);
    if(name.startsWith('on')||!allowed.includes(name)){
      node.removeAttribute(attr.name);
      return;
    }
    if(name==='style'){
      const clean=cleanBlogBodyStyle(attr.value);
      if(clean)node.setAttribute('style',clean);
      else node.removeAttribute('style');
      return;
    }
    const isLinkUrl=node.tagName==='A'&&name==='href';
    const isImageUrl=node.tagName==='IMG'&&name==='src';
    if((isLinkUrl||isImageUrl)&&!isSafeBlogBodyUrl(attr.value,isImageUrl)){
      node.removeAttribute(name);
    }
  }

  function sanitizeBlogBodyNode(node){
    if(BLOG_BODY_DANGEROUS.has(node.tagName)){
      node.remove();
      return;
    }
    if(!BLOG_BODY_ALLOWED.has(node.tagName)){
      node.replaceWith(...node.childNodes);
      return;
    }
    [...node.attributes].forEach(attr=>sanitizeBlogBodyAttribute(node,attr));
    if(node.tagName==='A'&&String(node.getAttribute('target')).toLowerCase()==='_blank'){
      node.setAttribute('rel','noopener noreferrer');
    }
  }

  function clientSafeBlogHtml(html='') {
    const template=document.createElement('template');
    template.innerHTML=String(html||'');
    [...template.content.querySelectorAll('*')].forEach(sanitizeBlogBodyNode);
    return template.innerHTML;
  }

  function bodyEditorMarkup(body='') {
    return `<div class="field full blog-body-field">
      <div class="blog-body-label"><label id="blog-body-label" for="blog_body">Body</label><span class="helper">Visual editor + safe HTML source. Unsupported markup is reported on save.</span></div>
      <div class="blog-body-editor" data-blog-body-editor data-mode="visual">
        <div class="blog-body-toolbar" role="toolbar" aria-label="Blog body formatting">
          <div class="blog-body-mode" aria-label="Editor mode">
            <button type="button" class="active" data-blog-body-mode="visual" aria-pressed="true">VISUAL</button>
            <button type="button" data-blog-body-mode="source" aria-pressed="false">HTML</button>
            <button type="button" data-blog-body-preview aria-pressed="false">PREVIEW</button>
          </div>
          <div class="blog-body-formatting">
            <select data-blog-body-block aria-label="Text block">
              <option value="p">PARAGRAPH</option><option value="h2">H2</option><option value="h3">H3</option><option value="h4">H4</option><option value="blockquote">QUOTE</option>
            </select>
            <button type="button" data-blog-body-command="bold" aria-label="Bold"><b>B</b></button>
            <button type="button" data-blog-body-command="italic" aria-label="Italic"><i>I</i></button>
            <button type="button" data-blog-body-command="insertUnorderedList" aria-label="Bulleted list">• LIST</button>
            <button type="button" data-blog-body-command="insertOrderedList" aria-label="Numbered list">1. LIST</button>
            <button type="button" data-blog-body-link aria-label="Insert link">LINK</button>
            <button type="button" data-blog-body-command="justifyLeft" aria-label="Align left">L</button>
            <button type="button" data-blog-body-command="justifyCenter" aria-label="Align center">C</button>
            <button type="button" data-blog-body-command="justifyRight" aria-label="Align right">R</button>
            <button type="button" data-blog-body-command="undo" aria-label="Undo">UNDO</button>
            <button type="button" data-blog-body-command="redo" aria-label="Redo">REDO</button>
            <button type="button" data-blog-body-media aria-label="Insert image from Media Library">MEDIA</button>
          </div>
        </div>
        <div class="blog-body-visual" data-blog-body-visual contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="blog-body-label"></div>
        <textarea id="blog_body" class="blog-body-source" aria-label="Blog body HTML source" spellcheck="false" hidden>${esc(body)}</textarea>
        <div class="blog-body-media-picker-source" hidden><input id="blog_body_media_path" aria-hidden="true" tabindex="-1"></div>
        <iframe class="blog-body-preview" data-blog-body-preview-frame title="Rendered blog body preview" sandbox hidden></iframe>
        <div class="blog-body-warning" data-blog-body-warning role="status" aria-live="polite" hidden></div>
      </div>
    </div>`;
  }

  function currentBodyEditor(){return document.querySelector('[data-blog-body-editor]')}

  function setBodyClientCleanupWarning(editor,changed){
    const warning=editor?.querySelector('[data-blog-body-warning]');
    if(!warning)return;
    warning.hidden=!changed;
    warning.textContent=changed
      ? 'UNSUPPORTED MARKUP DETECTED · Visual/Preview mode uses the safe allowlist. Review the cleaned result before saving.'
      : '';
  }

  function syncBodyVisualToSource(editor=currentBodyEditor()){
    if(!editor)return'';
    const visual=editor.querySelector('[data-blog-body-visual]');
    const source=editor.querySelector('#blog_body');
    if(visual&&source)source.value=visual.innerHTML.trim();
    setBodyClientCleanupWarning(editor,false);
    return source?.value||'';
  }

  function syncBodySourceToVisual(editor=currentBodyEditor()){
    if(!editor)return'';
    const source=editor.querySelector('#blog_body');
    const visual=editor.querySelector('[data-blog-body-visual]');
    const raw=String(source?.value||'').trim();
    const clean=clientSafeBlogHtml(raw).trim();
    const changed=clean!==raw;
    if(visual)visual.innerHTML=clean;
    if(source)source.value=clean;
    setBodyClientCleanupWarning(editor,changed);
    return clean;
  }

  function blogBodyValue(){
    const editor=currentBodyEditor();
    if(!editor)return value('blog_body');
    if(editor.dataset.mode==='visual')syncBodyVisualToSource(editor);
    return String(editor.querySelector('#blog_body')?.value||'').trim();
  }

  function setBodyMode(editor,mode){
    if(!editor)return;
    const visual=editor.querySelector('[data-blog-body-visual]'),source=editor.querySelector('#blog_body'),preview=editor.querySelector('[data-blog-body-preview-frame]');
    if(mode==='source')syncBodyVisualToSource(editor);
    if(mode==='visual')syncBodySourceToVisual(editor);
    editor.dataset.mode=mode;
    if(visual)visual.hidden=mode!=='visual';
    if(source)source.hidden=mode!=='source';
    if(preview)preview.hidden=true;
    editor.querySelectorAll('[data-blog-body-mode]').forEach(button=>{const active=button.dataset.blogBodyMode===mode;button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active))});
    editor.querySelector('[data-blog-body-preview]')?.setAttribute('aria-pressed','false');
  }

  function renderBodyPreview(editor=currentBodyEditor()){
    if(!editor)return;
    if(editor.dataset.mode==='visual')syncBodyVisualToSource(editor);
    const source=editor.querySelector('#blog_body'),preview=editor.querySelector('[data-blog-body-preview-frame]');
    if(!preview)return;
    const raw=String(source?.value||'').trim();
    const clean=clientSafeBlogHtml(raw).trim();
    setBodyClientCleanupWarning(editor,clean!==raw);
    preview.srcdoc=`<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;padding:24px;background:#090a0b;color:#f4f4f0;font:17px/1.65 Arial,sans-serif}h2,h3,h4{line-height:1.05}a{color:#9dff45}img{max-width:100%;height:auto}blockquote{margin-left:0;padding-left:18px;border-left:2px solid #9dff45;color:#c8c8c2}</style></head><body>${clean||'<p>Nothing to preview yet.</p>'}</body></html>`;
    preview.hidden=false;
    editor.querySelector('[data-blog-body-preview]')?.setAttribute('aria-pressed','true');
  }

  function executeBlogBodyCommand(command,value=null){
    document.execCommand(command,false,value);
  }

  function pasteBlogBodyPlainText(event,editor){
    event.preventDefault();
    const text=event.clipboardData?.getData('text/plain')||'';
    executeBlogBodyCommand('insertText',text);
    syncBodyVisualToSource(editor);
  }

  function insertBlogBodyMedia(editor,visual,mediaInput){
    const src=normalizeMediaPath(mediaInput?.value||'');
    if(!src)return;

    setBodyMode(editor,'visual');
    visual?.focus();
    executeBlogBodyCommand('insertImage',src);

    const images=[...(visual?.querySelectorAll('img')||[])];
    const inserted=images.at(-1);
    if(inserted&&!inserted.getAttribute('alt')){
      const filename=src.split('/').pop()?.replace(/\.[^.]+$/,'').replace(/[-_]+/g,' ').trim()
        ||'BRVTAL editorial image';
      inserted.setAttribute('alt',filename);
    }
    syncBodyVisualToSource(editor);
    if(mediaInput)mediaInput.value='';
  }

  function initBodyEditor(body=''){
    const editor=currentBodyEditor();
    if(!editor)return;

    const source=editor.querySelector('#blog_body');
    const visual=editor.querySelector('[data-blog-body-visual]');
    const mediaInput=editor.querySelector('#blog_body_media_path');

    if(source)source.value=String(body||'');
    syncBodySourceToVisual(editor);

    visual?.addEventListener('input',()=>syncBodyVisualToSource(editor));
    visual?.addEventListener('paste',event=>pasteBlogBodyPlainText(event,editor));

    editor.querySelectorAll('[data-blog-body-mode]').forEach(button=>{
      button.addEventListener('click',()=>setBodyMode(editor,button.dataset.blogBodyMode));
    });
    editor.querySelector('[data-blog-body-preview]')?.addEventListener(
      'click',
      ()=>renderBodyPreview(editor)
    );
    editor.querySelectorAll('[data-blog-body-command]').forEach(button=>{
      button.addEventListener('click',()=>{
        setBodyMode(editor,'visual');
        visual?.focus();
        executeBlogBodyCommand(button.dataset.blogBodyCommand);
        syncBodyVisualToSource(editor);
      });
    });
    editor.querySelector('[data-blog-body-block]')?.addEventListener('change',event=>{
      setBodyMode(editor,'visual');
      visual?.focus();
      executeBlogBodyCommand('formatBlock',event.target.value);
      syncBodyVisualToSource(editor);
    });
    editor.querySelector('[data-blog-body-link]')?.addEventListener('click',()=>{
      setBodyMode(editor,'visual');
      visual?.focus();
      const href=window.prompt?.('Link URL','https://')||'';
      if(!href)return;
      executeBlogBodyCommand('createLink',href);
      syncBodyVisualToSource(editor);
    });
    editor.querySelector('[data-blog-body-media]')?.addEventListener('click',()=>{
      if(mediaInput&&window.BRVTALMediaLibrary?.openPicker){
        window.BRVTALMediaLibrary.openPicker(mediaInput,{imagesOnly:true});
      }
    });
    mediaInput?.addEventListener(
      'change',
      ()=>insertBlogBodyMedia(editor,visual,mediaInput)
    );
  }

  function showBodyWarnings(warnings,cleanBody=''){
    const editor=currentBodyEditor();
    if(!editor)return;

    const list=Array.isArray(warnings)?warnings.filter(Boolean):[];
    const warning=editor.querySelector('[data-blog-body-warning]');
    if(cleanBody!==''){
      const source=editor.querySelector('#blog_body');
      if(source)source.value=String(cleanBody);
      syncBodySourceToVisual(editor);
    }
    if(warning){
      warning.hidden=!list.length;
      warning.textContent=list.length?'SAVED WITH CLEANUP · '+list.join(' · '):'';
    }
  }

  function blogEditorCoverMarkup(record){
    const path=normalizeMediaPath(record.cover_image||'');
    if(!path)return '<div class="thumb lg">NO IMAGE</div>';
    return '<img class="thumb lg" src="'+esc(path)+'" alt="'+esc(record.title||'Cover')+'">';
  }

  function blogPostEditorMarkup(record){
    const cover=blogEditorCoverMarkup(record);
    return `<div class="form"><div class="section"><div class="sectionhead"><strong>EDITORIAL</strong><span class="helper">Drafts are first-class. Publishing is explicit.</span></div><div class="grid2"><div class="field"><label for="blog_title">Title *</label><input id="blog_title" value="${esc(record.title||'')}"></div><div class="field"><label for="blog_slug">Slug *</label><input id="blog_slug" value="${esc(record.slug||'')}"></div><div class="field full"><label for="blog_excerpt">Excerpt</label><textarea id="blog_excerpt">${esc(record.excerpt||'')}</textarea></div>${bodyEditorMarkup(record.body||'')}<div class="field full"><label for="blog_cover_image">Cover image</label><div class="blog-editor-cover thumbcell">${cover}<div><input id="blog_cover_image" value="${esc(normalizeMediaPath(record.cover_image||''))}"><button class="media-picker-btn" id="blog-cover-picker" type="button">SELECT MEDIA</button></div></div></div><div class="field"><label for="blog_status_field">Status</label><select id="blog_status_field"><option value="draft" ${record.status==='draft'||!record.status?'selected':''}>Draft</option><option value="published" ${record.status==='published'?'selected':''}>Published</option><option value="archived" ${record.status==='archived'?'selected':''}>Archived</option></select></div><div class="field"><label for="blog_sort_order">Sort order</label><input id="blog_sort_order" type="number" value="${Number(record.sort_order||0)}"></div><label class="blog-featured full"><input id="blog_featured" type="checkbox" ${Number(record.featured)===1?'checked':''}><span><b>FEATURED POST</b><span class="meta">Prioritize on public editorial surfaces.</span></span></label></div></div><div class="section"><div class="sectionhead"><strong>RELATED CONTENT</strong><span class="helper">Connect the story to existing BRVTAL content.</span></div>${relationBoxes(record)}</div><div class="section"><div class="sectionhead"><strong>SEO</strong><span class="helper">Search and social metadata</span></div><div class="grid2"><div class="field"><label for="blog_seo_title">SEO title</label><input id="blog_seo_title" value="${esc(record.seo_title||'')}"></div><div class="field"><label for="blog_seo_description">SEO description</label><textarea id="blog_seo_description">${esc(record.seo_description||'')}</textarea></div></div></div></div>`;
  }

  async function loadBlogEditorRecord(id,base,controller,loadId){
    if(!id)return base||{};
    try{
      const detail=await request('?id='+encodeURIComponent(id),{signal:controller?.signal});
      if(loadId!==editorLoadId)return null;
      return detail.data||base||{};
    }catch(error){
      if(error?.name==='AbortError'||loadId!==editorLoadId)return null;
      setStatus('Unable to load post: '+error.message,'err');
      return null;
    }
  }

  function bindBlogEditorFields(record,id,saveButton){
    const titleInput=input('blog_title');
    const slugInput=input('blog_slug');
    let slugTouched=Boolean(record.slug);

    slugInput?.addEventListener('input',()=>{slugTouched=true});
    titleInput?.addEventListener('input',()=>{
      if(!slugTouched&&slugInput)slugInput.value=slugify(titleInput.value);
    });

    document.getElementById('blog-cover-picker')?.addEventListener('click',()=>{
      const coverInput=input('blog_cover_image');
      if(coverInput&&window.BRVTALMediaLibrary?.openPicker){
        window.BRVTALMediaLibrary.openPicker(coverInput,{imagesOnly:true});
      }
    });
    saveButton.onclick=()=>save(id,record);
  }

  async function openEditor(id=null){
    const loadId=++editorLoadId;
    editorController?.abort();
    const controller=id?new AbortController():null;
    editorController=controller;
    const base=id?store.posts.find(item=>Number(item.id)===Number(id)):null;
    const record=await loadBlogEditorRecord(id,base,controller,loadId);
    if(!record||loadId!==editorLoadId)return;

    const modal=document.getElementById('modal');
    const content=document.getElementById('mcontent');
    const title=document.getElementById('mtitle');
    const notice=document.getElementById('notice');
    const saveButton=document.getElementById('saveBtn');
    if(!modal||!content||!title||!saveButton)return;

    title.textContent=id?'EDIT BLOG POST':'NEW BLOG POST';
    if(notice)notice.className='notice';
    content.innerHTML=blogPostEditorMarkup(record);
    replaceBlogSortOrderControl();
    initBodyEditor(record.body||'');
    bindBlogEditorFields(record,id,saveButton);
    modal.classList.add('open');
  }

  function payload(existingRelations = [], sortOrder = 0) {
    const relations = mergeRelations(existingRelations);
    return {
      title:value('blog_title'),
      slug:value('blog_slug'),
      excerpt:value('blog_excerpt'),
      body:blogBodyValue(),
      cover_image:normalizeMediaPath(value('blog_cover_image')),
      seo_title:value('blog_seo_title'),
      seo_description:value('blog_seo_description'),
      status:value('blog_status_field') || 'draft',
      featured:input('blog_featured')?.checked ? 1 : 0,
      sort_order:Number(sortOrder),
      relations
    };
  }

  function blogNextSortOrder(id){
    const current=id
      ? store.posts.find(post=>Number(post.id)===Number(id))
      : null;
    if(current)return Number(current.sort_order||0);

    return store.posts.reduce(
      (max,post)=>Math.max(max,Number(post.sort_order??-1)),
      -1
    )+1;
  }

  function blogSavePayload(id,record){
    const data=payload(record?.relations||[],blogNextSortOrder(id));
    if(!data.title)throw new Error('TITLE_REQUIRED');
    if(!data.slug)data.slug=slugify(data.title);
    return data;
  }

  function blogSaveRequest(id,data){
    return request(id?'?id='+encodeURIComponent(id):'',{
      method:id?'PUT':'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(data)
    });
  }

  function rebindCreatedBlogWarningSave(button,id,record,result){
    if(id||!result?.data?.id)return {id,record};

    const reboundId=Number(result.data.id);
    const reboundRecord=result.data;
    if(button)button.onclick=()=>save(reboundId,reboundRecord);
    return {id:reboundId,record:reboundRecord};
  }

  async function handleBlogSaveWarnings(button,id,record,result,data,warnings){
    const rebound=rebindCreatedBlogWarningSave(button,id,record,result);
    showBodyWarnings(warnings,result?.data?.body||data.body);
    await refresh();
    setStatus('Post saved with body cleanup warnings. Review the editor before leaving.','err');
    window.BRVTALMediaLibrary?.notify?.(
      'warning',
      'Post saved after unsupported body markup was removed.',
      {timeout:6200}
    );
    return rebound;
  }

  function setBlogSaveButtonBusy(button,busy){
    if(!button?.isConnected)return;
    button.disabled=busy;
    button.textContent=busy?'SAVING…':'GUARDAR';
  }

  function reportBlogSaveError(error){
    const message=error?.message||'UNKNOWN_ERROR';
    setStatus('Could not save post: '+message,'err');
    window.BRVTALFeedback?.error?.(
      message.replaceAll('_',' '),
      'blog-save'
    );
  }

  async function save(id = null, record = {}) {
    const button=document.getElementById('saveBtn');
    setBlogSaveButtonBusy(button,true);

    try{
      const data=blogSavePayload(id,record);
      const result=await blogSaveRequest(id,data);
      const warnings=Array.isArray(result?.warnings)
        ? result.warnings.filter(Boolean)
        : [];

      if(warnings.length){
        const rebound=await handleBlogSaveWarnings(
          button,
          id,
          record,
          result,
          data,
          warnings
        );
        id=rebound.id;
        record=rebound.record;
        return;
      }

      if(typeof closeModal==='function')closeModal(true);
      await refresh();
      setStatus('Post saved.','ok');
    }catch(error){
      reportBlogSaveError(error);
    }finally{
      setBlogSaveButtonBusy(button,false);
    }
  }

  async function remove(id) {
    if (!confirm('Delete this blog post?')) return;
    try {
      await request('?id=' + encodeURIComponent(id), {method:'DELETE'});
      await refresh();
      setStatus('Post deleted.','ok');
    } catch (error) {
      setStatus(
        'Could not delete post: ' + (error?.message || 'UNKNOWN_ERROR'),
        'err'
      );
    }
  }

  window.addEventListener('brvtal:content-order-changed', event => {
    if (
      event.detail?.resource !== 'blog'
      || !Array.isArray(event.detail.ids)
    ) {
      return;
    }
    store.posts = window.BRVTALContentOrdering?.applyOrder?.(
      store.posts,
      event.detail.ids
    ) || store.posts;
  });

  function mount(root){store.root=root;root.querySelector('#blog-new')?.addEventListener('click',()=>openEditor());root.querySelector('#blog-search')?.addEventListener('input',render);root.querySelector('#blog-status-filter')?.addEventListener('change',render);refresh()}
  return {mount,refresh,openEditor,remove};
})();
