(() => {
  'use strict';

  const ENDPOINT = '/api/media-context.php';
  const TYPES = [
    ['event','EVENTS'],
    ['artist','ARTISTS'],
    ['set','SETS'],
    ['release','RELEASES'],
  ];
  let loadId = 0;
  let activeMediaId = 0;
  let contextData = null;
  let csrfToken = '';

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

  function selectedMediaId() {
    return Number(document.querySelector('#media-grid .media-card.active')?.dataset.mediaId || 0);
  }

  async function csrf() {
    if (csrfToken) return csrfToken;
    const response = await fetch('/api/auth', {credentials:'same-origin',cache:'no-store'});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.authenticated || !payload.csrf) throw new Error('AUTH_REQUIRED');
    csrfToken = payload.csrf;
    return csrfToken;
  }

  async function request(id, options = {}) {
    const headers = {...(options.headers || {})};
    if (options.method && options.method !== 'GET') headers['X-CSRF-Token'] = await csrf();
    const response = await fetch(`${ENDPOINT}?id=${encodeURIComponent(id)}`, {
      credentials:'same-origin',
      cache:'no-store',
      ...options,
      headers,
    });
    const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.error || `HTTP_${response.status}`);
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  function notify(kind, message) {
    window.BRVTALMediaLibrary?.notify?.(kind, message, kind === 'error' ? {timeout:0} : {});
  }

  function relationKey(type, id) {
    return `${type}:${Number(id) || 0}`;
  }

  function groupHtml(type, label, catalog, selected) {
    const rows = Array.isArray(catalog?.[type]) ? catalog[type] : [];
    return `<div class="media-cultural-group" data-media-cultural-group="${esc(type)}">
      <strong>${esc(label)} / ${rows.length}</strong>
      <div class="media-cultural-options">${rows.length ? rows.map(row => {
        const id = Number(row?.id || 0);
        const checked = selected.has(relationKey(type,id));
        return `<label class="media-cultural-option"><input type="checkbox" data-media-relation data-relation-type="${esc(type)}" value="${id}" ${checked?'checked':''}><span><b>${esc(row?.label || `#${id}`)}</b><small>${esc(row?.status || '')}</small></span></label>`;
      }).join('') : '<div class="media-cultural-empty">NO RECORDS</div>'}</div>
    </div>`;
  }

  function render(data) {
    const inspector = document.getElementById('media-inspector');
    const body = inspector?.querySelector('.media-inspector-body');
    if (!body || selectedMediaId() !== Number(data?.media_id || 0)) return;
    body.querySelector('.media-cultural-context')?.remove();

    const section = document.createElement('section');
    section.className = 'media-cultural-context';
    section.dataset.mediaRelationsReady = data.relations_ready ? '1' : '0';
    if (!data.relations_ready) {
      section.classList.add('is-unavailable');
      section.innerHTML = '<div class="media-cultural-context-head"><div><h4>CULTURAL CONTEXT</h4><strong>RELATIONS MIGRATION REQUIRED</strong></div></div>';
    } else {
      const selected = new Set((Array.isArray(data.relations) ? data.relations : []).map(row => relationKey(row.related_type,row.related_id)));
      section.innerHTML = `<div class="media-cultural-context-head"><div><h4>CULTURAL CONTEXT / ${selected.size}</h4><span>EXPLICIT RELATIONS ONLY</span></div></div>
        <p class="media-cultural-context-note">Connect this Memory to real Events, Artists, Sets or Releases. These links drive public archive context; file usage tracking remains separate.</p>
        <div class="media-cultural-context-groups">${TYPES.map(([type,label]) => groupHtml(type,label,data.relation_catalog,selected)).join('')}</div>`;
    }

    const usage = body.querySelector('.media-usage');
    if (usage) usage.insertAdjacentElement('beforebegin', section);
    else body.appendChild(section);
  }

  async function loadForSelection() {
    const id = selectedMediaId();
    const inspector = document.getElementById('media-inspector');
    if (!id || !inspector?.querySelector('.media-inspector-body')) {
      activeMediaId = 0;
      contextData = null;
      return;
    }
    if (id === activeMediaId && inspector.querySelector('.media-cultural-context')) return;

    const currentLoad = ++loadId;
    activeMediaId = id;
    contextData = null;
    try {
      const payload = await request(id);
      if (currentLoad !== loadId || selectedMediaId() !== id) return;
      contextData = payload.data || null;
      render(contextData);
    } catch (error) {
      if (currentLoad !== loadId) return;
      const body = inspector.querySelector('.media-inspector-body');
      if (!body) return;
      body.querySelector('.media-cultural-context')?.remove();
      const section = document.createElement('section');
      section.className = 'media-cultural-context is-unavailable';
      section.innerHTML = `<div class="media-cultural-context-head"><div><h4>CULTURAL CONTEXT</h4><strong>CONTEXT UNAVAILABLE</strong><span>${esc(error.message)}</span></div></div>`;
      body.querySelector('.media-usage')?.insertAdjacentElement('beforebegin', section);
    }
  }

  function collectRelations() {
    return [...document.querySelectorAll('#media-inspector [data-media-relation]:checked')].map((node,index) => ({
      related_type: node.dataset.relationType || '',
      related_id: Number(node.value || 0),
      sort_order: index,
    }));
  }

  async function saveCombined(button) {
    const id = selectedMediaId();
    if (!id || !contextData?.relations_ready) return;
    button.disabled = true;
    const payload = {
      title: document.getElementById('media-edit-title')?.value || '',
      alt_text: document.getElementById('media-edit-alt')?.value || '',
      status: document.getElementById('media-edit-status')?.value || 'published',
      relations: collectRelations(),
    };
    try {
      notify('processing','Saving media + cultural context…');
      await request(id, {method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
      notify('success','Media + cultural context saved.');
      activeMediaId = 0;
      contextData = null;
      await window.BRVTALMediaLibrary?.refresh?.(id);
    } catch (error) {
      notify('error',`Could not save media context · ${error.message}`);
    } finally {
      if (button.isConnected) button.disabled = false;
    }
  }

  document.addEventListener('click', event => {
    const button = event.target.closest?.('#media-save');
    if (!button) return;
    const context = document.querySelector('#media-inspector .media-cultural-context');
    if (!context || context.dataset.mediaRelationsReady !== '1') return;
    event.preventDefault();
    event.stopImmediatePropagation();
    saveCombined(button);
  }, true);

  const observer = new MutationObserver(() => {
    window.clearTimeout(observer.timer);
    observer.timer = window.setTimeout(loadForSelection, 30);
  });
  observer.observe(document.documentElement, {childList:true,subtree:true});
  window.setTimeout(loadForSelection, 120);
})();
