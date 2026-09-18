/** Render published Blog records as BRVTAL TRANSMISSIONS using the shared public payload. */
(() => {
  'use strict';
  const section = document.querySelector('#transmissions');
  const grid = section?.querySelector('[data-transmissions-grid]');
  const count = section?.querySelector('[data-transmissions-count]');
  if (!section || !grid || !count) return;
  const MAX_ITEMS = 6;
  const routeUrl = (type, slug) => { const clean=String(slug??'').trim(); return clean ? `/${type}/${encodeURIComponent(clean)}` : ''; };
  const safeImageUrl = value => {
    const raw=String(value??'').trim(); if(!raw)return '';
    try { const url=new URL(raw,window.location.origin+'/'); if(!/^https?:$/i.test(url.protocol))return ''; return url.origin===window.location.origin ? url.pathname+url.search+url.hash : url.href; }
    catch (_) { return ''; }
  };
  const formatDate = value => {
    const raw=String(value??'').trim(); if(!raw)return '';
    const date=new Date(raw.replace(' ','T')); if(Number.isNaN(date.getTime()))return raw;
    return new Intl.DateTimeFormat(document.documentElement.lang||'en',{day:'2-digit',month:'2-digit',year:'numeric'}).format(date);
  };
  const create=(tag,className='',text='')=>{const node=document.createElement(tag);if(className)node.className=className;if(text!=='')node.textContent=String(text);return node;};
  const publicCatalog=root=>{
    const catalog={event:new Map(),artist:new Map(),set:new Map(),release:new Map()};
    const add=(type,items,titleField,routeType)=>(Array.isArray(items)?items:[]).forEach(item=>{const id=Number(item?.id||0),label=String(item?.[titleField]??'').trim();if(id<1||!label)return;catalog[type].set(id,{label,href:routeUrl(routeType,item?.slug)});});
    const archived=Array.isArray(root?.archive?.events)?root.archive.events:[];
    add('event',[...(Array.isArray(root?.events)?root.events:[]),...archived],'title','events');
    add('artist',root?.artists,'name','artists'); add('set',root?.sets,'title','sets'); add('release',root?.releases,'title','releases');
    return catalog;
  };
  const relationNodes=(post,catalog)=>{
    const seen=new Set(),nodes=[],relations=Array.isArray(post?.relations)?post.relations:[];
    for(const relation of relations){
      const type=String(relation?.related_type??'').trim().toLowerCase(),id=Number(relation?.related_id||0);
      if(!catalog[type]||id<1)continue;
      const key=`${type}:${id}`; if(seen.has(key))continue;
      const target=catalog[type].get(id); if(!target)continue; seen.add(key);
      const label=`${type.toUpperCase()} / ${target.label}`,node=target.href?create('a','transmission-relation mono',label):create('span','transmission-relation mono',label);
      if(target.href)node.href=target.href; nodes.push(node); if(nodes.length>=3)break;
    }
    return nodes;
  };
  const tagNodes=post=>(Array.isArray(post?.tags)?post.tags:[]).map(t=>String(t?.name??'').trim()).filter(Boolean).slice(0,3).map(name=>create('span','transmission-tag mono',name.toUpperCase()));
  const transmissionCard=(post,index,catalog)=>{
    const article=create('article','transmission'); if(Number(post?.featured||0)===1&&index===0)article.classList.add('transmission--featured'); article.dataset.transmissionId=String(Number(post?.id||0));
    const title=String(post?.title??'BRVTAL TRANSMISSION').trim()||'BRVTAL TRANSMISSION',excerpt=String(post?.excerpt??'').trim(),canonical=routeUrl('blog',post?.slug),image=safeImageUrl(post?.cover_image),date=formatDate(post?.published_at);
    const meta=create('div','transmission-meta mono'); meta.append(create('span','',date||'BRVTAL EDITORIAL'),create('span','',article.classList.contains('transmission--featured')?'FEATURED SIGNAL':'TRANSMISSION'));
    if(image){const visual=canonical?create('a','transmission-visual'):create('div','transmission-visual');if(canonical)visual.href=canonical;const img=create('img');img.src=image;img.alt=`${title} cover`;img.loading='lazy';img.decoding='async';visual.appendChild(img);article.appendChild(visual);}
    const body=create('div','transmission-body'); body.appendChild(meta);
    const titleLink=canonical?create('a','transmission-title-link'):create('div','transmission-title-link'); if(canonical)titleLink.href=canonical; titleLink.appendChild(create('h3','',title)); body.appendChild(titleLink);
    if(excerpt)body.appendChild(create('p','transmission-excerpt',excerpt));
    const tags=tagNodes(post); if(tags.length){const host=create('div','transmission-tags');tags.forEach(tag=>host.appendChild(tag));body.appendChild(host);}
    const relations=relationNodes(post,catalog),relationsRoot=create('div','transmission-relations');
    if(relations.length){relationsRoot.setAttribute('aria-label','Related cultural records');relations.forEach(node=>relationsRoot.appendChild(node));}
    else relationsRoot.appendChild(create('span','transmission-relation mono','INDEPENDENT TRANSMISSION'));
    body.appendChild(relationsRoot);
    if(canonical){const open=create('a','transmission-open mono','OPEN TRANSMISSION ↗');open.href=canonical;body.appendChild(open);}
    article.appendChild(body); return article;
  };
  const render=root=>{
    const posts=(Array.isArray(root?.blog)?root.blog:[]).filter(item=>item&&typeof item==='object'),visible=posts.slice(0,MAX_ITEMS),catalog=publicCatalog(root);
    if(visible.length)grid.replaceChildren(...visible.map((post,index)=>transmissionCard(post,index,catalog)));
    else {const empty=create('div','transmissions-empty');empty.append(create('strong','','NO PUBLISHED TRANSMISSIONS YET.'),create('span','','Editorial signals will appear here when a Blog post is published.'));grid.replaceChildren(empty);}
    count.textContent=`${String(visible.length).padStart(2,'0')} / ${String(posts.length).padStart(2,'0')} TRANSMISSIONS`;
    const kicker=section.querySelector('.transmissions-kicker'); if(kicker)kicker.textContent=`EDITORIAL SIGNAL / ${String(posts.length).padStart(2,'0')}`;
    document.documentElement.dataset.publicTransmissions='editorial';
    window.dispatchEvent(new CustomEvent('brvtal:transmissions-rendered',{detail:{count:posts.length,visible:visible.length}}));
  };
  const dataFromSharedRequest=async()=>{
    for(let attempt=0;attempt<12;attempt+=1){const request=window.BRVTALPublicDataPromise;if(request&&typeof request.then==='function'){const result=await request,payload=result?.payload??result,root=payload?.data&&typeof payload.data==='object'?payload.data:payload;return root&&typeof root==='object'?root:{};}await new Promise(resolve=>window.setTimeout(resolve,40));}
    return null;
  };
  window.addEventListener('load',()=>window.setTimeout(async()=>{try{const root=await dataFromSharedRequest();if(root!==null)render(root);}catch(_){/* Static fallback stays visible. */}},220),{once:true});
})();
