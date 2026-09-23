(() => {
  'use strict';

  const state = {items:[],type:'all',query:'',lastFocus:null,viewerItems:[],viewerIndex:0,delivery:{}};
  const qs = (selector, root=document) => root.querySelector(selector);
  const searchText = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const fileUrl = value => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, location.origin);
      if (url.origin !== location.origin || !url.pathname.startsWith('/uploads/')) return '';
      return `${url.pathname}${url.search}`;
    } catch (error) {
      // Invalid or malformed public asset URLs are intentionally rejected.
      return '';
    }
  };
  const mediaType = item => {
    const declared = String(item?.type || '').toLowerCase();
    if (['image','video','audio'].includes(declared)) return declared;
    const mime = String(item?.mime_type || '').toLowerCase().split('/')[0];
    return ['image','video','audio'].includes(mime) ? mime : 'other';
  };
  const create = (tag, {className='', text} = {}) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined && text !== '') element.textContent = String(text);
    return element;
  };
  const localUploadPath = value => {
    const raw = fileUrl(value);
    if (!raw) return '';
    try {
      const url = new URL(raw, location.origin);
      return url.pathname.startsWith('/uploads/') ? url.pathname : '';
    } catch (error) {
      // fileUrl already filters malformed paths; this remains a defensive rejection.
      return '';
    }
  };

  function deliveryCandidate(value, context='card') {
    const path = localUploadPath(value), delivery = path ? state.delivery[path] : null, variants = delivery?.variants || {};
    let candidate = null;
    if (context === 'square') candidate = variants.square || variants.card || variants.w1280 || variants.display || null;
    else if (context === 'hero') candidate = variants.hero || variants.w1920 || variants.w1280 || variants.display || null;
    else if (context === 'viewer') candidate = variants.w1920 || variants.w1280 || variants.display || null;
    else if (context === 'preserve') candidate = variants.w1280 || variants.w1920 || variants.display || null;
    else candidate = variants.card || variants.w1280 || variants.square || variants.display || null;
    if (!candidate?.src) return null;
    const src = fileUrl(candidate.src); return src ? {...candidate,src} : null;
  }
  function imageDeliveryContext(image) {
    if (!(image instanceof Element)) return 'preserve';
    const explicit = String(image.dataset?.brvtalImageContext || '').toLowerCase();
    if (['square','card','hero','viewer','preserve'].includes(explicit)) return explicit;
    if (image.closest('.related-item-image')) return 'square';
    if (image.closest('.public-media-item')) return 'card';
    if (image.matches('.brvtal-hero-media')) return 'hero';
    if (image.closest('.brvtal-hero-layer')) return 'preserve';
    return 'preserve';
  }
  function bindImageFallback(image) {
    if (image.dataset.brvtalWebpFallbackBound) return;
    image.dataset.brvtalWebpFallbackBound = '1';
    image.addEventListener('error', () => {
      const original = image.dataset.brvtalOriginalSrc || '';
      if (!original || image.dataset.brvtalFallbackApplied === '1') return;
      image.dataset.brvtalFallbackApplied = '1';
      delete image.dataset.brvtalWebpSrc;
      if (image.hasAttribute('src')) image.src = original; else image.dataset.src = original;
    });
  }
  function applyResponsiveImage(image, context=imageDeliveryContext(image)) {
    if (!(image instanceof HTMLImageElement)) return;
    const currentSrc=image.getAttribute('src')||'', deferredSrc=image.getAttribute('data-src')||'', original=image.dataset.brvtalOriginalSrc||currentSrc||deferredSrc;
    if (!localUploadPath(original)) return;
    if (!image.dataset.brvtalOriginalSrc) image.dataset.brvtalOriginalSrc = original;
    const candidate=deliveryCandidate(original,context); if (!candidate?.src) return;
    image.dataset.brvtalWebpSrc=candidate.src; bindImageFallback(image);
    if (currentSrc) { if (currentSrc !== candidate.src) image.src=candidate.src; }
    else if (deferredSrc && deferredSrc !== candidate.src) image.dataset.src=candidate.src;
    if (!image.hasAttribute('width') && Number(candidate.width)>0) image.width=Number(candidate.width);
    if (!image.hasAttribute('height') && Number(candidate.height)>0) image.height=Number(candidate.height);
  }
  function applyDocumentImageDelivery(root=document) {
    const scope = root instanceof Element || root instanceof Document ? root : document;
    if (scope instanceof HTMLImageElement) applyResponsiveImage(scope);
    scope.querySelectorAll('img').forEach(image => applyResponsiveImage(image));
  }
  let imageDeliveryPromise=null;
  function loadImageDelivery() {
    if (imageDeliveryPromise) return imageDeliveryPromise;
    imageDeliveryPromise=fetch('/api/public-image-delivery.php',{credentials:'same-origin',headers:{Accept:'application/json'}})
      .then(response => response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`)))
      .then(payload => { state.delivery=payload?.ok && payload.data && typeof payload.data==='object' ? payload.data : {}; applyDocumentImageDelivery(); return state.delivery; })
      .catch(() => { state.delivery={}; return state.delivery; });
    return imageDeliveryPromise;
  }

  function closeViewer() {
    const viewer=document.getElementById('public-media-viewer');
    if (!viewer) { window.BRVTALScrollLock?.unlock('media-viewer'); return; }
    viewer.querySelectorAll('video,audio').forEach(media => { try { media.pause(); } catch (_) {} });
    viewer.remove(); window.BRVTALScrollLock?.unlock('media-viewer'); state.lastFocus?.focus?.(); state.lastFocus=null; state.viewerItems=[];
  }
  function viewerMediaNode(item) {
    const type=mediaType(item), url=fileUrl(item.file_path), title=item.title||item.alt_text||'BRVTAL memory';
    if (!url) return create('div',{className:'public-media-viewer-audio',text:'MEDIA UNAVAILABLE'});
    if (type==='video') {
      const video=create('video');
      video.src=url; video.controls=true; video.playsInline=true; video.preload='metadata'; video.setAttribute('aria-label',String(title));
      return video;
    }
    if (type==='audio') {
      const wrapper=create('div',{className:'public-media-viewer-audio'});
      wrapper.append(create('span',{className:'mono',text:'AUDIO MEMORY'}));
      const audio=create('audio');
      audio.src=url; audio.controls=true; audio.preload='metadata'; audio.setAttribute('aria-label',String(title));
      wrapper.append(audio);
      return wrapper;
    }
    const candidate=deliveryCandidate(url,'viewer'), image=create('img');
    image.src=candidate?.src||url; image.alt=String(item.alt_text||title); image.dataset.brvtalOriginalSrc=url; image.dataset.brvtalImageContext='viewer';
    return image;
  }
  function showViewerItem() {
    const item=state.viewerItems[state.viewerIndex], viewer=document.getElementById('public-media-viewer');
    if (!item || !viewer) return;
    const dialog=qs('[role="dialog"]',viewer), stage=qs('[data-public-media-stage]',viewer), titleNode=qs('[data-public-media-title]',viewer), context=qs('[data-public-media-context]',viewer), position=qs('[data-public-media-position]',viewer);
    if (!dialog || !stage || !titleNode || !context || !position) return;
    const title=item.title||item.alt_text||'BRVTAL memory', type=mediaType(item);
    dialog.setAttribute('aria-label',String(title));
    viewer.querySelectorAll('video,audio').forEach(media => { try { media.pause(); } catch (_) {} });
    const media=viewerMediaNode(item); stage.replaceChildren(media); if (media instanceof HTMLImageElement) applyResponsiveImage(media,'viewer');
    const audio = media instanceof HTMLAudioElement ? media : media.querySelector?.('audio');
    if (audio) {
      audio.addEventListener('error', () => markMemoryUnavailable(item, audio, stage), {once:true});
    }
    titleNode.textContent=String(title); context.textContent=String(item.context||'').trim(); context.hidden=context.textContent==='';
    position.textContent=`${type.toUpperCase()} / ${state.viewerIndex+1} OF ${state.viewerItems.length}`;
  }
  function markMemoryUnavailable(item, mediaNode, stage) {
    const id = Number(item?.id) || 0;
    const opener = [...document.querySelectorAll('[data-public-media-open]')]
      .find(button => Number(button.dataset.publicMediaOpen) === id);
    if (opener) {
      opener.disabled = true;
      opener.setAttribute('aria-disabled', 'true');
      const card = opener.closest('[data-public-media-item]');
      card?.classList.add('is-media-missing');
      const cardMedia = opener.querySelector('img,video');
      if (cardMedia) cardMedia.hidden = true;
    }
    if (mediaNode) mediaNode.hidden = true;
    if (stage) {
      stage.replaceChildren(create('div', {
        className:'public-media-viewer-audio',
        text:'MEDIA UNAVAILABLE',
      }));
    }
  }
  function moveViewer(direction) { if (state.viewerItems.length<2) return; state.viewerIndex=(state.viewerIndex+direction+state.viewerItems.length)%state.viewerItems.length; showViewerItem(); }
  function buildViewer() {
    const viewer=create('div',{className:'public-media-viewer'}); viewer.id='public-media-viewer';
    const card=create('div',{className:'public-media-viewer-card'}); card.setAttribute('role','dialog'); card.setAttribute('aria-modal','true'); card.setAttribute('aria-label','BRVTAL memory');
    const close=create('button',{className:'public-media-close mono',text:'CLOSE ×'}); close.type='button'; close.dataset.publicMediaClose='';
    const stage=create('div',{className:'public-media-viewer-stage'}); stage.dataset.publicMediaStage='';
    const meta=create('div',{className:'public-media-viewer-meta'}), copy=create('div'), title=create('strong'); title.dataset.publicMediaTitle='';
    const context=create('p'); context.hidden=true; context.dataset.publicMediaContext=''; copy.append(title,context);
    const position=create('span',{className:'mono'}); position.dataset.publicMediaPosition=''; meta.append(copy,position);
    const controls=create('div',{className:'public-media-viewer-controls'}), prev=create('button',{className:'mono',text:'← PREVIOUS'}), next=create('button',{className:'mono',text:'NEXT →'});
    prev.type='button'; prev.setAttribute('aria-label','Previous memory'); prev.dataset.publicMediaPrev='';
    next.type='button'; next.setAttribute('aria-label','Next memory'); next.dataset.publicMediaNext='';
    controls.append(prev,next); card.append(close,stage,meta,controls); viewer.append(card); return viewer;
  }
  function openViewer(item,trigger) {
    if (!item || typeof item !== 'object' || !fileUrl(item.file_path) || mediaType(item)==='other') return;
    if (document.getElementById('public-media-viewer')) closeViewer();
    state.lastFocus=trigger||null;
    const visibleIds=new Set([...document.querySelectorAll('[data-public-media-item]:not([hidden]) [data-public-media-open]')].map(button=>Number(button.dataset.publicMediaOpen)));
    state.viewerItems=state.items.filter(entry=>fileUrl(entry.file_path)&&mediaType(entry)!=='other'&&visibleIds.has(Number(entry.id)));
    if (!state.viewerItems.some(entry=>Number(entry.id)===Number(item.id))) state.viewerItems=[item];
    state.viewerIndex=Math.max(0,state.viewerItems.findIndex(entry=>Number(entry.id)===Number(item.id)));
    const viewer=buildViewer(); document.body.appendChild(viewer); window.BRVTALScrollLock?.lock('media-viewer'); showViewerItem();
    const close=viewer.querySelector('[data-public-media-close]'), prev=viewer.querySelector('[data-public-media-prev]'), next=viewer.querySelector('[data-public-media-next]');
    if (!close || !prev || !next) { closeViewer(); return; }
    prev.hidden=state.viewerItems.length<2; next.hidden=state.viewerItems.length<2;
    close.addEventListener('click',closeViewer); prev.addEventListener('click',()=>moveViewer(-1)); next.addEventListener('click',()=>moveViewer(1));
    viewer.addEventListener('click',event=>{if(event.target===viewer)closeViewer();}); close.focus();
  }

  function relationHref(relation) {
    const route = String(relation?.route_type || '');
    const slug = String(relation?.slug || '');
    if (!['events','artists','sets','releases'].includes(route) || !/^[a-z0-9-]{1,190}$/.test(slug)) return '';
    return `/${route}/${encodeURIComponent(slug)}`;
  }

  function relationContextNode(item) {
    const relations = Array.isArray(item?.relations) ? item.relations : [];
    const links = relations.map(relation => {
      const href = relationHref(relation);
      const label = String(relation?.label || '').trim();
      const type = String(relation?.related_type || '').toUpperCase();
      if (!href || !label) return null;
      const link = create('a', {text:`${type} / ${label}`});
      link.href = href;
      link.className = 'public-memory-context-link mono';
      return link;
    }).filter(Boolean);
    if (!links.length) return null;
    const context = create('div', {className:'public-memory-context'});
    context.append(...links);
    return context;
  }

  function itemNode(item) {
    if (!item || typeof item !== 'object') return null;
    const type = mediaType(item);
    const url = fileUrl(item.file_path);
    const title = item.title || item.alt_text || 'BRVTAL memory';
    if (!url || type === 'other') return null;

    const figure = create('figure', {className:'public-media-item'});
    figure.dataset.publicMediaItem = '';
    figure.dataset.publicMediaTypeValue = type;
    const relationSearch = (Array.isArray(item.relations) ? item.relations : [])
      .map(relation => `${relation?.related_type || ''} ${relation?.label || ''}`)
      .join(' ');
    figure.dataset.publicMediaSearchValue = searchText(
      `${title} ${item.context || ''} ${item.alt_text || ''} ${relationSearch}`
    );

    const open = create('button', {className:'public-media-open'});
    open.type = 'button';
    open.setAttribute('aria-label', `Open ${String(title)}`);
    open.dataset.publicMediaOpen = String(Number(item.id) || 0);

    if (type === 'image') {
      const candidate = deliveryCandidate(url, 'card');
      const image = create('img');
      image.src = candidate?.src || url;
      image.alt = String(item.alt_text || title);
      image.loading = 'lazy';
      image.decoding = 'async';
      image.dataset.brvtalOriginalSrc = url;
      image.dataset.brvtalImageContext = 'card';
      if (candidate && Number(candidate.width) > 0 && Number(candidate.height) > 0) {
        image.width = Number(candidate.width);
        image.height = Number(candidate.height);
      }
      open.append(image);
    } else if (type === 'video') {
      const video = create('video');
      video.src = url;
      video.muted = true;
      video.playsInline = true;
      video.preload = 'metadata';
      video.setAttribute('aria-label', String(title));
      open.append(video);
    } else {
      const audio = create('div', {className:'public-media-audio'});
      audio.append(
        create('span', {className:'mono',text:'AUDIO SIGNAL'}),
        create('span', {className:'public-media-audio-mark',text:'///'})
      );
      const mark = audio.querySelector('.public-media-audio-mark');
      if (mark) {
        mark.setAttribute('aria-hidden', 'true');
      }
      open.append(audio);
    }

    open.addEventListener('click', () => openViewer(item, open));
    const caption = create('figcaption');
    const copy = create('div');
    copy.append(create('strong', {text:title}));
    if (item.context) {
      copy.append(create('p', {text:item.context}));
    }
    const relationContext = relationContextNode(item);
    if (relationContext) copy.append(relationContext);
    caption.append(copy, create('span', {className:'mono',text:type.toUpperCase()}));
    figure.append(open, caption);
    return figure;
  }
  function controlButtons(){return [...document.querySelectorAll('[data-public-media-filter],[data-public-media-type]')];}
  function applyFilters() {
    let visible = 0;
    document.querySelectorAll('[data-public-media-item]').forEach(item => {
      const typeMatch = state.type === 'all' || item.dataset.publicMediaTypeValue === state.type;
      const queryMatch = !state.query
        || String(item.dataset.publicMediaSearchValue || '').includes(state.query);
      item.hidden = !(typeMatch && queryMatch);
      if (item.hidden === false) {
        visible += 1;
      }
    });
    controlButtons().forEach(button => {
      const type = button.dataset.publicMediaFilter || button.dataset.publicMediaType || 'all';
      button.classList.toggle('active', type === state.type);
    });
    const count = qs('[data-public-media-count]');
    if (count) {
      count.textContent = `${visible} ${visible === 1 ? 'MEMORY' : 'MEMORIES'} FOUND`;
    }
    const empty = qs('[data-public-media-empty]');
    if (empty) {
      empty.hidden = visible !== 0;
    }
  }

  function renderMemories(items) {
    const grid = qs('[data-public-media-grid]') || qs('.media-grid');
    if (!grid) return false;
    state.items = Array.isArray(items)
      ? items.filter(item => (
        Number(item?.media_id) > 0
        && Boolean(fileUrl(item?.file_path))
        && mediaType(item) !== 'other'
      ))
      : [];
    grid.classList.add('public-media-grid');
    grid.replaceChildren(...state.items.map(itemNode).filter(Boolean));
    applyFilters();
    document.documentElement.dataset.publicMemories = 'curated';
    window.dispatchEvent(new CustomEvent('brvtal:memories-rendered', {
      detail: { count: state.items.length },
    }));
    return true;
  }

  function resetFilters() {
    state.type = 'all';
    state.query = '';
    const search = qs('[data-public-media-search]');
    if (search) {
      search.value = '';
    }
    applyFilters();
    search?.focus();
  }

  function bindControls() {
    controlButtons().forEach(button => {
      if (button.dataset.bound) return;
      button.dataset.bound = '1';
      button.addEventListener('click', () => {
        state.type = button.dataset.publicMediaFilter || button.dataset.publicMediaType || 'all';
        applyFilters();
      });
    });
    const search = qs('[data-public-media-search]');
    if (search && !search.dataset.bound) {
      search.dataset.bound = '1';
      search.addEventListener('input', () => {
        state.query = searchText(search.value);
        applyFilters();
      });
    }
    const reset = qs('[data-public-media-reset]');
    if (reset && !reset.dataset.bound) {
      reset.dataset.bound = '1';
      reset.addEventListener('click', resetFilters);
    }
  }
  document.addEventListener('keydown', event => {
    const viewer = document.getElementById('public-media-viewer');
    if (!viewer) return;

    if (event.key === 'Escape') {
      event.preventDefault();
      closeViewer();
      return;
    }

    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      const mediaTarget = event.target instanceof Element
        ? event.target.closest('video,audio')
        : null;
      if (mediaTarget) return;
      event.preventDefault();
      moveViewer(event.key === 'ArrowLeft' ? -1 : 1);
      return;
    }

    if (event.key !== 'Tab') return;
    const selector = [
      'button:not([disabled]):not([hidden])',
      'a[href]',
      'input:not([disabled])',
      'select:not([disabled])',
      'textarea:not([disabled])',
      '[tabindex]:not([tabindex="-1"])',
      'audio[controls]',
      'video[controls]',
    ].join(',');
    const focusable = [...viewer.querySelectorAll(selector)]
      .filter(node => !node.closest('[hidden]'));
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (!first || !last) return;

    if (!viewer.contains(document.activeElement)) {
      event.preventDefault();
      first.focus();
    } else if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  window.addEventListener('brvtal:public-data', event => {
    renderMemories(event.detail?.memories || []);
  });
  window.BRVTALPublicMedia={render:renderMemories,applyFilters,openViewer,closeViewer,loadImageDelivery,applyDocumentImageDelivery,applyResponsiveImage};
  bindControls();loadImageDelivery();
})();
