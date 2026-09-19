(() => {
  'use strict';

  const nativeGo = window.go;
  let openToken = 0;
  let picker = null;
  let currentItems = [];
  let availableItems = [];
  let relationCatalog = {};
  let relationsReady = false;
  const relationTypes = new Set(['event','artist','set','release']);

  const normalizePath = value => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, location.origin);
      if (url.origin !== location.origin || !url.pathname.startsWith('/uploads/')) return '';
      return `${url.pathname}${url.search}`;
    } catch (error) {
      // Invalid or malformed asset URLs are intentionally rejected.
      return '';
    }
  };

  const create = (tag, {className='', text='', attrs={}, dataset={}} = {}) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== '') element.textContent = String(text);
    Object.entries(attrs).forEach(([name, value]) => {
      if (value === false || value === null || value === undefined) return;
      const stringValue = String(value);
      switch (name) {
        case 'alt': element.setAttribute('alt', stringValue); break;
        case 'loading': element.setAttribute('loading', stringValue); break;
        case 'preload': element.setAttribute('preload', stringValue); break;
        case 'maxlength': element.setAttribute('maxlength', stringValue); break;
        case 'placeholder': element.setAttribute('placeholder', stringValue); break;
        case 'type': element.setAttribute('type', stringValue); break;
        case 'min': element.setAttribute('min', stringValue); break;
        case 'max': element.setAttribute('max', stringValue); break;
        case 'value': element.setAttribute('value', stringValue); break;
        case 'role': element.setAttribute('role', stringValue); break;
        case 'aria-modal': element.setAttribute('aria-modal', stringValue); break;
        case 'aria-label': element.setAttribute('aria-label', stringValue); break;
        case 'aria-live': element.setAttribute('aria-live', stringValue); break;
        case 'autocomplete': element.setAttribute('autocomplete', stringValue); break;
        case 'muted': if (value === true) element.setAttribute('muted', ''); break;
        case 'playsinline': if (value === true) element.setAttribute('playsinline', ''); break;
        case 'disabled': if (value === true) element.setAttribute('disabled', ''); break;
        case 'hidden': if (value === true) element.setAttribute('hidden', ''); break;
        default: break;
      }
    });
    Object.entries(dataset).forEach(([name, value]) => { element.dataset[name] = String(value); });
    return element;
  };

  function csrfToken() {
    return typeof window.csrf === 'string' ? window.csrf : '';
  }

  async function api(action, {method='GET', id=0, body=null} = {}) {
    const url = new URL('/api/memories.php', location.origin);
    url.searchParams.set('action', action);
    if (id) url.searchParams.set('id', String(id));
    const headers = {'Accept':'application/json'};
    if (method !== 'GET') {
      const token = csrfToken();
      if (token) headers['X-CSRF-Token'] = token;
      headers['Content-Type'] = 'application/json';
    }
    const response = await fetch(url, {
      method,
      credentials:'same-origin',
      cache:'no-store',
      headers,
      body: body === null ? null : JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || payload.ok === false) throw new Error(payload.error || `HTTP_${response.status}`);
    return payload;
  }

  function status(message, kind='') {
    const node = document.querySelector('[data-memories-status]');
    if (!node) return;
    node.textContent = message || '';
    node.dataset.kind = kind;
  }

  function previewNode(item, className='') {
    const type = String(item.media_type || item.type || '').toLowerCase();
    const src = normalizePath(item.file_path);
    if (type === 'image' && src) {
      const image = create('img', {
        className,
        attrs:{alt:item.alt_text || item.title || item.media_title || 'Memory',loading:'lazy'},
      });
      image.src = src;
      return image;
    }
    if (type === 'video' && src) {
      const video = create('video', {className, attrs:{muted:true,playsinline:true,preload:'metadata'}});
      video.src = src;
      video.muted = true;
      video.playsInline = true;
      return video;
    }
    const fallback = create('div', {className:'memory-audio-preview'});
    fallback.append(create('span', {text:type === 'audio' ? 'AUDIO SIGNAL' : 'MEDIA UNAVAILABLE'}));
    return fallback;
  }

  function labeledControl(labelText, control) {
    const label = create('label', {text:labelText});
    label.append(control);
    return label;
  }

  function relationKey(relation) {
    return `${String(relation?.related_type || '')}:${Number(relation?.related_id) || 0}`;
  }

  function relationLabel(relation) {
    const type = String(relation?.related_type || '');
    const id = Number(relation?.related_id) || 0;
    const source = relationCatalog[type];
    const target = Array.isArray(source?.items)
      ? source.items.find(candidate => Number(candidate.id) === id)
      : null;
    return target?.label ? `${type.toUpperCase()} / ${target.label}` : `${type.toUpperCase()} #${id}`;
  }

  function currentRelationKeys(wrapper, item) {
    const existing = Array.isArray(item?.relations) ? item.relations : [];
    if (wrapper.dataset.memoryRelationsBuilt !== '1') {
      return new Set(existing.map(relationKey));
    }

    const keys = new Set();
    relationTypes.forEach(type => {
      const source = relationCatalog[type] || {state:'error'};
      if (source.state !== 'ready') {
        existing
          .filter(relation => relation?.related_type === type)
          .forEach(relation => keys.add(relationKey(relation)));
        return;
      }

      wrapper.querySelectorAll(`[data-memory-relation-type="${type}"]:checked`).forEach(input => {
        const id = Number(input.dataset.memoryRelationId) || 0;
        if (id > 0) keys.add(`${type}:${id}`);
      });
    });
    return keys;
  }

  function updateRelationSummary(wrapper, item) {
    const summary = wrapper.querySelector('[data-memory-relations-summary]');
    if (!summary) return;
    const keys = currentRelationKeys(wrapper, item);
    const relations = [...keys].map(key => {
      const [type, rawId] = key.split(':');
      return {related_type:type,related_id:Number(rawId)||0};
    }).filter(relation => relation.related_id > 0);
    const labels = relations.map(relationLabel).slice(0, 2);
    const suffix = relations.length > labels.length ? ` +${relations.length - labels.length}` : '';
    summary.textContent = relations.length
      ? `${relations.length} SELECTED · ${labels.join(' · ')}${suffix}`
      : '0 SELECTED';
  }

  function relationTargetNode(type, target, selected, wrapper, item) {
    const label = create('label', {className:'memory-relation-item'});
    const checkbox = create('input', {
      attrs:{type:'checkbox'},
      dataset:{memoryRelationType:type,memoryRelationId:Number(target.id)||0},
    });
    checkbox.checked = selected.has(`${type}:${Number(target.id)||0}`);
    checkbox.addEventListener('change', () => updateRelationSummary(wrapper, item));
    label.append(
      checkbox,
      create('span', {text:String(target.label || `${type} #${target.id}`)}),
      create('small', {text:String(target.status || '').toUpperCase()})
    );
    return label;
  }

  function relationGroupNode(type, selected, wrapper, item) {
    const source = relationCatalog[type] || {state:'error',items:[]};
    const group = create('fieldset', {className:'memory-relations-group'});
    group.append(create('legend', {text:`${type.toUpperCase()}S`}));

    if (source.state !== 'ready') {
      group.dataset.sourceState = 'error';
      group.append(create('span', {
        className:'memory-relations-note',
        text:'Source unavailable · existing links will be preserved.',
      }));
      return group;
    }

    const items = Array.isArray(source.items) ? source.items : [];
    if (!items.length) {
      group.append(create('span', {className:'memory-relations-note', text:'No records available.'}));
      return group;
    }

    items.forEach(target => {
      group.append(relationTargetNode(type, target, selected, wrapper, item));
    });
    return group;
  }

  function buildRelationOptions(details, wrapper, item, selected) {
    if (wrapper.dataset.memoryRelationsBuilt === '1') return;
    wrapper.dataset.memoryRelationsBuilt = '1';
    relationTypes.forEach(type => {
      details.append(relationGroupNode(type, selected, wrapper, item));
    });
    updateRelationSummary(wrapper, item);
  }

  function relationEditor(item) {
    const wrapper = create('div', {
      className:'memory-relations',
      dataset:{memoryRelationsBuilt:'0'},
    });
    wrapper.append(create('div', {className:'memory-relations-head', text:'CULTURAL RELATIONSHIPS'}));
    if (!relationsReady) {
      wrapper.append(create('p', {
        className:'memory-relations-note',
        text:'Relationships are unavailable until the additive memory_relations migration is applied. Memory editing remains available.',
      }));
      return wrapper;
    }

    const summaryText = create('p', {
      className:'memory-relations-selection',
      dataset:{memoryRelationsSummary:'1'},
    });
    wrapper.append(summaryText);

    const details = create('details', {className:'memory-relations-details'});
    details.append(create('summary', {text:'EDIT RELATIONSHIPS'}));
    wrapper.append(details);

    const selected = new Set((Array.isArray(item.relations) ? item.relations : []).map(relationKey));
    details.addEventListener('toggle', () => {
      if (details.open) buildRelationOptions(details, wrapper, item, selected);
    });
    updateRelationSummary(wrapper, item);
    return wrapper;
  }

  function collectRelations(card, item) {
    if (!relationsReady) return null;
    const existing = Array.isArray(item?.relations) ? item.relations : [];
    const editor = card.querySelector('.memory-relations');
    if (editor && editor.dataset.memoryRelationsBuilt !== '1') {
      return existing
        .map(relation => ({
          related_type:String(relation?.related_type || ''),
          related_id:Number(relation?.related_id) || 0,
        }))
        .filter(relation => relationTypes.has(relation.related_type) && relation.related_id > 0);
    }

    const relations = [];
    relationTypes.forEach(type => {
      const source = relationCatalog[type] || {state:'error'};
      if (source.state !== 'ready') {
        existing.filter(relation => relation?.related_type === type).forEach(relation => {
          relations.push({related_type:type,related_id:Number(relation.related_id)||0});
        });
        return;
      }
      card.querySelectorAll(`[data-memory-relation-type="${type}"]:checked`).forEach(input => {
        const id = Number(input.dataset.memoryRelationId) || 0;
        if (id > 0) relations.push({related_type:type,related_id:id});
      });
    });
    return relations.filter(relation => relation.related_id > 0);
  }

  function cardNode(item) {
    const id = Number(item.id) || 0;
    const article = create('article', {className:'memory-admin-card', dataset:{memoryId:id}});
    const preview = create('div', {className:'memory-admin-preview'});
    preview.append(previewNode(item));
    preview.append(create('span', {className:'memory-admin-badge', text:String(item.media_type || '').toUpperCase()}));
    const form = create('div', {className:'memory-admin-form'});
    const meta = create('div', {className:'memory-admin-meta'});
    meta.append(create('span', {text:`MEDIA #${Number(item.media_id)||0}`}),create('span', {text:item.media_status || ''}),create('span', {text:item.mime_type || ''}));
    const title = create('input', {attrs:{maxlength:'180'}}); title.dataset.memoryTitle = ''; title.value = String(item.title || '');
    const context = create('textarea', {attrs:{maxlength:'320',placeholder:'Optional public context'}}); context.dataset.memoryContext = ''; context.value = String(item.context || '');
    const order = create('input', {attrs:{type:'number',min:'-100000',max:'100000'}}); order.dataset.memoryOrder = ''; order.value = String(Number(item.sort_order) || 0);
    const select = create('select'); select.dataset.memoryStatus = '';
    ['draft','published'].forEach(value => { const option = create('option', {text:value.toUpperCase(), attrs:{value}}); option.selected = item.status === value; select.append(option); });
    const grid2 = create('div', {className:'grid2'}); grid2.append(labeledControl('ORDER', order), labeledControl('STATUS', select));
    const actions = create('div', {className:'memory-admin-actions'});
    const saveGroup = create('div', {className:'group'}), removeGroup = create('div', {className:'group'});
    const save = create('button', {className:'btn red', text:'SAVE', attrs:{type:'button'}}); save.dataset.memorySave = '';
    const remove = create('button', {className:'iconbtn', text:'REMOVE', attrs:{type:'button'}}); remove.dataset.memoryRemove = '';
    save.addEventListener('click', () => saveMemory(id, article)); remove.addEventListener('click', () => removeMemory(id));
    saveGroup.append(save); removeGroup.append(remove); actions.append(saveGroup, removeGroup);
    form.append(meta, labeledControl('PUBLIC TITLE', title), labeledControl('CONTEXT', context), grid2, relationEditor(item), actions);
    article.append(preview, form); return article;
  }

  function render() {
    const grid = document.querySelector('[data-memories-grid]'), empty = document.querySelector('[data-memories-empty]');
    if (!grid || !empty) return;
    grid.replaceChildren(...currentItems.map(cardNode)); empty.hidden = currentItems.length !== 0;
  }

  async function refresh() {
    status('LOADING MEMORIES…');
    const [list, available, catalog] = await Promise.all([
      api('list'),
      api('available'),
      api('catalog').catch(() => ({ok:false,data:{},relations_ready:false})),
    ]);
    currentItems = Array.isArray(list.data) ? list.data : [];
    availableItems = Array.isArray(available.data) ? available.data : [];
    relationCatalog = catalog?.data && typeof catalog.data === 'object' ? catalog.data : {};
    relationsReady = catalog?.relations_ready === true && list?.relations_ready === true;
    render();
    const suffix = relationsReady ? ' · RELATIONSHIPS READY' : ' · RELATIONSHIPS WAITING FOR MIGRATION';
    status(`${currentItems.length} ${currentItems.length === 1 ? 'MEMORY' : 'MEMORIES'} CURATED${suffix}`);
  }

  async function saveMemory(id, card) {
    const item = currentItems.find(entry => Number(entry.id) === Number(id)); if (!item || !card) return;
    const body = {media_id:Number(item.media_id),title:card.querySelector('[data-memory-title]')?.value || '',context:card.querySelector('[data-memory-context]')?.value || '',sort_order:Number(card.querySelector('[data-memory-order]')?.value || 0),status:card.querySelector('[data-memory-status]')?.value || 'draft'};
    const relations = collectRelations(card, item);
    if (relations !== null) body.relations = relations;
    status('SAVING MEMORY…');
    try { await api('update', {method:'PUT', id, body}); await refresh(); window.BRVTALFeedback?.success?.('Memory saved.','memories'); }
    catch (error) { status(String(error.message || error), 'error'); window.BRVTALFeedback?.error?.(String(error.message || error).replaceAll('_',' '),'memories'); }
  }

  async function removeMemory(id) {
    const item = currentItems.find(entry => Number(entry.id) === Number(id)); if (!item) return;
    if (!confirm(`Remove “${item.title || item.media_title || 'Memory'}” from public Memories? The Media Library asset will be kept.`)) return;
    status('REMOVING MEMORY…');
    try { await api('delete', {method:'DELETE', id}); await refresh(); window.BRVTALFeedback?.success?.('Memory removed. Source media kept.','memories'); }
    catch (error) { status(String(error.message || error), 'error'); window.BRVTALFeedback?.error?.(String(error.message || error).replaceAll('_',' '),'memories'); }
  }

  function closePicker() { picker?.remove(); picker = null; window.BRVTALAdminModalAccessibility?.sync?.(); }
  function pickerItems(query='') { const q = String(query || '').trim().toLowerCase(); return availableItems.filter(item => !q || `${item.title || ''} ${item.type || ''} ${item.status || ''}`.toLowerCase().includes(q)); }
  function pickerItemNode(item) {
    const curated = Number(item.memory_id || 0) > 0;
    const button = create('button', {className:'memory-picker-item',attrs:{type:'button',disabled:curated},dataset:{memoryMediaId:Number(item.id)||0}});
    button.append(previewNode(item));
    const copy = create('span', {className:'memory-picker-copy'});
    copy.append(create('strong', {text:item.title || `Media #${item.id}`}),create('span', {text:`${String(item.type || '').toUpperCase()} / ${item.status || ''}${curated ? ' / ALREADY CURATED' : ''}`}));
    button.append(copy);
    if (!curated) {
      button.addEventListener('click', () => createMemory(Number(item.id) || 0));
    }
    return button;
  }
  function renderPicker(query='') {
    if (!picker) return;
    const grid = picker.querySelector('[data-memories-picker-grid]');
    if (!grid) return;
    const items = pickerItems(query);
    if (!items.length) {
      grid.replaceChildren(create('div', {
        className:'memories-admin-empty',
        text:'No matching image, video or audio assets.',
      }));
      return;
    }
    grid.replaceChildren(...items.map(pickerItemNode));
  }
  function buildPicker() {
    const overlay = create('div', {className:'memories-picker'}), card = create('div', {className:'memories-picker-card', attrs:{role:'dialog','aria-modal':'true','aria-label':'Select media for a Memory'}}), head = create('div', {className:'memories-picker-head'}), heading = create('div');
    heading.append(create('div', {className:'eyebrow', text:'MEDIA LIBRARY'}), create('h3', {text:'ADD MEMORY'}));
    const close = create('button', {className:'iconbtn', text:'CLOSE ×', attrs:{type:'button'}}); close.dataset.memoriesPickerClose = ''; head.append(heading, close);
    const tools = create('div', {className:'memories-picker-tools'}), search = create('input', {attrs:{type:'search',placeholder:'Search Media Library…',autocomplete:'off'}}); search.dataset.memoriesPickerSearch = ''; tools.append(search);
    const grid = create('div', {className:'memories-picker-grid'}); grid.dataset.memoriesPickerGrid = ''; card.append(head, tools, grid); overlay.append(card); return overlay;
  }
  function openPicker() {
    closePicker(); picker = buildPicker(); document.body.appendChild(picker); renderPicker();
    picker.querySelector('[data-memories-picker-close]')?.addEventListener('click', closePicker);
    picker.querySelector('[data-memories-picker-search]')?.addEventListener('input', event => renderPicker(event.target.value));
    picker.addEventListener('click', event => { if (event.target === picker) closePicker(); });
    window.BRVTALAdminModalAccessibility?.sync?.(); requestAnimationFrame(() => picker?.querySelector('[data-memories-picker-search]')?.focus());
  }
  async function createMemory(mediaId) {
    const asset = availableItems.find(item => Number(item.id) === Number(mediaId)); if (!asset) return;
    const maxOrder = currentItems.reduce((max,item) => Math.max(max, Number(item.sort_order || 0)), -1);
    try { await api('create', {method:'POST',body:{media_id:mediaId,title:asset.title || 'Memory',context:'',status:'draft',sort_order:maxOrder + 1}}); closePicker(); await refresh(); window.BRVTALFeedback?.success?.('Memory added as draft.','memories'); }
    catch (error) { window.BRVTALFeedback?.error?.(String(error.message || error).replaceAll('_',' '),'memories'); }
  }

  function setNavActive(active) {
    const memoryButton = document.querySelector('[data-memories-nav]');
    document.querySelectorAll('.side .nav > button').forEach(button => {
      if (button === memoryButton) { button.classList.toggle('active', active); button.classList.toggle('memories-nav-active', active); }
      else if (active && String(button.textContent || '').trim().toUpperCase().includes('MEDIA')) button.classList.remove('active');
    });
  }
  function syncUrl(active, mode='push') {
    const url = new URL(location.href); if (active) { url.searchParams.set('module','media'); url.searchParams.set('view','memories'); } else url.searchParams.delete('view');
    const next = `${url.pathname}${url.search}${url.hash}`, current = `${location.pathname}${location.search}${location.hash}`;
    if (next !== current) history[mode === 'replace' ? 'replaceState' : 'pushState']({brvtalMemories:active},'',next);
  }
  function ensureNav() {
    const nav = document.querySelector('.side .nav'); if (!nav || nav.querySelector('[data-memories-nav]')) return;
    const button = create('button', {text:'MEMORIES', attrs:{type:'button'}, dataset:{adminNav:'media',memoriesNav:'1'}}); button.addEventListener('click', () => open({mode:'push'}));
    const media = [...nav.querySelectorAll(':scope > button')].find(item => {
      const label = String(item.textContent || '').trim().toUpperCase();
      return item.dataset.adminNav === 'media' || label === 'MEDIA' || label === 'MEDIA LIBRARY';
    });
    if (media) media.after(button);
    else nav.appendChild(button);
  }
  function buildWorkspace() {
    const section = create('section', {className:'memories-admin'}); section.dataset.adminModule = 'memories';
    const head = create('div', {className:'memories-admin-head'}), copy = create('div');
    copy.append(create('div', {className:'eyebrow', text:'MEDIA / CURATION'}),create('h2', {text:'MEMORIES'}),create('p', {text:'Curate the public archive from existing Media Library assets. Removing a Memory never deletes the source asset.'}));
    const add = create('button', {className:'btn red', text:'+ ADD MEMORY', attrs:{type:'button'}}); add.dataset.memoriesAdd = ''; head.append(copy, add);
    const statusNode = create('div', {className:'memories-admin-status', attrs:{role:'status','aria-live':'polite'}}); statusNode.dataset.memoriesStatus = '';
    const grid = create('div', {className:'memories-admin-grid', attrs:{'aria-live':'polite'}}); grid.dataset.memoriesGrid = '';
    const empty = create('div', {className:'memories-admin-empty', text:'No curated Memories yet. Add one from Media Library.', attrs:{hidden:true}}); empty.dataset.memoriesEmpty = '';
    section.append(head, statusNode, grid, empty); return section;
  }
  async function open({mode='push'} = {}) {
    const token = ++openToken;
    if (!window.state?.authed) return;
    if (typeof nativeGo === 'function') {
      await nativeGo.call(window, 'media');
    }
    if (token !== openToken) return;

    const host = document.getElementById('admin-module-host');
    if (!host) return;
    host.replaceChildren(buildWorkspace());

    const title = document.querySelector('.main > .top h1');
    if (title) title.textContent = 'MEMORIES';

    host.querySelector('[data-memories-add]')?.addEventListener('click', openPicker);
    setNavActive(true);
    syncUrl(true, mode);
    try {
      await refresh();
    } catch (error) {
      const message = String(error.message || error).replaceAll('_', ' ');
      status(message, 'error');
      window.BRVTALFeedback?.error?.(message, 'memories');
    }
  }

  window.go = async function(section, ...args) {
    if (section === 'memories') {
      return open({mode:'push'});
    }
    ++openToken;
    closePicker();
    const result = typeof nativeGo === 'function'
      ? await nativeGo.call(this, section, ...args)
      : undefined;
    if (section === 'media') {
      setNavActive(false);
      syncUrl(false, 'replace');
    }
    ensureNav();
    return result;
  };
  const navObserver = new MutationObserver(() => ensureNav()); navObserver.observe(document.documentElement,{childList:true,subtree:true}); ensureNav();
  function restoreRoute() {
    const params = new URLSearchParams(location.search);
    if (params.get('module') !== 'media' || params.get('view') !== 'memories') return;
    let attempts = 0;
    const timer = setInterval(() => {
      attempts += 1;
      if (window.state?.authed) {
        clearInterval(timer);
        open({mode:'replace'}).catch(() => {
          // Route restoration failure is surfaced by the workspace on the next explicit open.
        });
      } else if (attempts >= 100) {
        clearInterval(timer);
      }
    }, 100);
  }
  window.addEventListener('popstate', () => {
    const params = new URLSearchParams(location.search);
    if (params.get('module') === 'media' && params.get('view') === 'memories') {
      open({mode:'replace'}).catch(() => {
        // Browser-history restoration can safely defer to the next explicit navigation.
      });
    } else if (params.get('module') === 'media') {
      ++openToken;
      closePicker();
      setNavActive(false);
      if (typeof nativeGo === 'function') {
        nativeGo.call(window, 'media');
      }
    }
  });
  restoreRoute();
})();
