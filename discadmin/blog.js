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
  function orderingAvailable(){if(!store.root)return false;return !(store.root.querySelector('#blog-search')?.value||'').trim()&&!(store.root.querySelector('#blog-status-filter')?.value||'')}
  function render(){if(!store.root)return;renderMetrics();const grid=store.root.querySelector('#blog-grid');if(!grid)return;const rows=visibleRows();grid.dataset.orderResource='blog';grid.dataset.orderEnabled=orderingAvailable()?'1':'0';if(!rows.length){grid.innerHTML='<div class="blog-empty">NO POSTS MATCH THIS VIEW</div>';window.BRVTALContentOrdering?.refresh?.(grid);return}grid.innerHTML=rows.map(post=>`<article class="blog-row" data-blog-id="${Number(post.id)}" data-order-id="${Number(post.id)}"><div>${cover(post)}</div><div><div class="blog-title">${esc(post.title)}</div><div class="blog-meta">/${esc(post.slug)}${Number(post.featured)===1?' · FEATURED':''}</div></div><div class="blog-excerpt">${esc(post.excerpt||'NO EXCERPT')}</div><div class="blog-date"><div class="blog-meta">PUBLISHED</div><b>${esc(post.published_at||'NOT YET')}</b></div><div class="blog-actions"><span class="blog-status-wrap"><span class="blog-status-pill ${esc(post.status)}">${esc(post.status)}</span></span><button class="iconbtn" type="button" data-edit>EDIT</button><button class="iconbtn" type="button" data-delete>DELETE</button></div></article>`).join('');grid.querySelectorAll('[data-blog-id]').forEach(row=>{const id=Number(row.dataset.blogId);row.querySelector('[data-edit]')?.addEventListener('click',()=>openEditor(id));row.querySelector('[data-delete]')?.addEventListener('click',()=>remove(id))});window.BRVTALContentOrdering?.refresh?.(grid)}

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

  function openEditor(id=null){
    const loadId=++editorLoadId;
    editorController?.abort();
    const controller=id?new AbortController():null;
    editorController=controller;
    const base=id?store.posts.find(x=>Number(x.id)===Number(id)):null;
    const load=async()=>{let r=base||{};if(id){try{const detail=await request('?id='+encodeURIComponent(id),{signal:controller.signal});if(loadId!==editorLoadId)return;r=detail.data||r}catch(e){if(e?.name==='AbortError'||loadId!==editorLoadId)return;setStatus('Unable to load post: '+e.message,'err');return}}
      if(loadId!==editorLoadId)return;
      const modal=document.getElementById('modal'),content=document.getElementById('mcontent'),title=document.getElementById('mtitle'),notice=document.getElementById('notice'),saveButton=document.getElementById('saveBtn');if(!modal||!content||!title||!saveButton)return;
      title.textContent=id?'EDIT BLOG POST':'NEW BLOG POST';if(notice)notice.className='notice';
      content.innerHTML=`<div class="form"><div class="section"><div class="sectionhead"><strong>EDITORIAL</strong><span class="helper">Drafts are first-class. Publishing is explicit.</span></div><div class="grid2"><div class="field"><label for="blog_title">Title *</label><input id="blog_title" value="${esc(r.title||'')}"></div><div class="field"><label for="blog_slug">Slug *</label><input id="blog_slug" value="${esc(r.slug||'')}"></div><div class="field full"><label for="blog_excerpt">Excerpt</label><textarea id="blog_excerpt">${esc(r.excerpt||'')}</textarea></div><div class="field full"><label for="blog_body">Body</label><textarea id="blog_body" style="min-height:300px">${esc(r.body||'')}</textarea></div><div class="field full"><label for="blog_cover_image">Cover image</label><div class="blog-editor-cover thumbcell">${r.cover_image?`<img class="thumb lg" src="${esc(normalizeMediaPath(r.cover_image))}" alt="${esc(r.title||'Cover')}">`:'<div class="thumb lg">NO IMAGE</div>'}<div><input id="blog_cover_image" value="${esc(normalizeMediaPath(r.cover_image||''))}"><button class="media-picker-btn" id="blog-cover-picker" type="button">SELECT MEDIA</button></div></div></div><div class="field"><label for="blog_status_field">Status</label><select id="blog_status_field"><option value="draft" ${r.status==='draft'||!r.status?'selected':''}>Draft</option><option value="published" ${r.status==='published'?'selected':''}>Published</option><option value="archived" ${r.status==='archived'?'selected':''}>Archived</option></select></div><div class="helper">Display order is managed visually from the Blog list.</div><label class="blog-featured full"><input id="blog_featured" type="checkbox" ${Number(r.featured)===1?'checked':''}><span><b>FEATURED POST</b><span class="meta">Prioritize on public editorial surfaces.</span></span></label></div></div><div class="section"><div class="sectionhead"><strong>RELATED CONTENT</strong><span class="helper">Connect the story to existing BRVTAL content.</span></div>${relationBoxes(r)}</div><div class="section"><div class="sectionhead"><strong>SEO</strong><span class="helper">Search and social metadata</span></div><div class="grid2"><div class="field"><label for="blog_seo_title">SEO title</label><input id="blog_seo_title" value="${esc(r.seo_title||'')}"></div><div class="field"><label for="blog_seo_description">SEO description</label><textarea id="blog_seo_description">${esc(r.seo_description||'')}</textarea></div></div></div></div>`;
      const titleInput=input('blog_title'),slugInput=input('blog_slug');let slugTouched=Boolean(r.slug);slugInput?.addEventListener('input',()=>{slugTouched=true});titleInput?.addEventListener('input',()=>{if(!slugTouched&&slugInput)slugInput.value=slugify(titleInput.value)});
      document.getElementById('blog-cover-picker')?.addEventListener('click',()=>{const coverInput=input('blog_cover_image');if(coverInput&&window.BRVTALMediaLibrary?.openPicker)window.BRVTALMediaLibrary.openPicker(coverInput,{imagesOnly:true})});
      saveButton.onclick=()=>save(id,r);modal.classList.add('open');
    };load();
  }
  function payload(existingRelations=[],sortOrder=0){const relations=mergeRelations(existingRelations);return{title:value('blog_title'),slug:value('blog_slug'),excerpt:value('blog_excerpt'),body:value('blog_body'),cover_image:normalizeMediaPath(value('blog_cover_image')),seo_title:value('blog_seo_title'),seo_description:value('blog_seo_description'),status:value('blog_status_field')||'draft',featured:input('blog_featured')?.checked?1:0,sort_order:Number(sortOrder),relations}}
  async function save(id=null,record={}){const button=document.getElementById('saveBtn');if(button){button.disabled=true;button.textContent='SAVING…'}try{const current=id?store.posts.find(post=>Number(post.id)===Number(id)):null;const nextOrder=current?Number(current.sort_order||0):store.posts.reduce((max,post)=>Math.max(max,Number(post.sort_order??-1)),-1)+1;const data=payload(record?.relations||[],nextOrder);if(!data.title)throw new Error('TITLE_REQUIRED');if(!data.slug)data.slug=slugify(data.title);await request(id?'?id='+encodeURIComponent(id):'',{method:id?'PUT':'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});if(typeof closeModal==='function')closeModal();await refresh();setStatus('Post saved.','ok')}catch(error){setStatus('Could not save post: '+(error?.message||'UNKNOWN_ERROR'),'err');window.BRVTALFeedback?.error?.((error?.message||'Blog save failed').replace(/_/g,' '),'blog-save')}finally{if(button?.isConnected){button.disabled=false;button.textContent='GUARDAR'}}}
  async function remove(id){if(!confirm('Delete this blog post?'))return;try{await request('?id='+encodeURIComponent(id),{method:'DELETE'});await refresh();setStatus('Post deleted.','ok')}catch(error){setStatus('Could not delete post: '+(error?.message||'UNKNOWN_ERROR'),'err')}}
  window.addEventListener('brvtal:content-order-changed',event=>{if(event.detail?.resource!=='blog'||!Array.isArray(event.detail.ids))return;store.posts=window.BRVTALContentOrdering?.applyOrder?.(store.posts,event.detail.ids)||store.posts});
  function mount(root){store.root=root;root.querySelector('#blog-new')?.addEventListener('click',()=>openEditor());root.querySelector('#blog-search')?.addEventListener('input',render);root.querySelector('#blog-status-filter')?.addEventListener('change',render);refresh()}
  return {mount,refresh,openEditor};
})();
