(() => {
  'use strict';

  const ENDPOINT = '/api/media-relations.php';
  const TYPES = ['event','artist','set','release'];
  let loadId = 0;
  let timer = 0;
  let currentMediaId = 0;
  let current = [];
  let options = {};
  let csrf = '';

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const label = type => ({event:'EVENT',artist:'ARTIST',set:'SET',release:'RELEASE'})[type] || String(type).toUpperCase();

  async function getCsrf() {
    if (csrf) return csrf;
    const response = await fetch('/api/auth', {credentials:'same-origin',cache:'no-store'});
    const payload = await response.json();
    if (!response.ok || !payload.authenticated || !payload.csrf) throw new Error('AUTH_REQUIRED');
    csrf = payload.csrf;
    return csrf;
  }

  async function request(mediaId, init = {}) {
    const headers = {'Accept':'application/json', ...(init.headers || {})};
    if (init.method && init.method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = await getCsrf();
    }
    const response = await fetch(`${ENDPOINT}?media_id=${encodeURIComponent(mediaId)}`, {credentials:'same-origin',cache:'no-store',...init,headers});
    const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || payload.ok === false) throw new Error(payload.error || `HTTP_${response.status}`);
    return payload;
  }

  function activeMediaId() {
    return Number(document.querySelector('.media-card.active[data-media-id]')?.dataset.mediaId || 0);
  }

  function target(type, id) {
    return (Array.isArray(options[type]) ? options[type] : []).find(item => Number(item.id) === Number(id));
  }

  function relationRows() {
    if (!current.length) return '<div class="media-relations-empty">NO STRUCTURED CONNECTIONS YET</div>';
    return current.map((relation, index) => {
      const item = target(relation.related_type, relation.related_id);
      const title = item?.title || `#${relation.related_id}`;
      return `<div class="media-relation-chip"><span><b>${esc(label(relation.related_type))}</b>${esc(title)}</span><button type="button" data-memory-remove="${index}" aria-label="Remove ${esc(title)}">×</button></div>`;
    }).join('');
  }

  function selector(type) {
    const rows = Array.isArray(options[type]) ? options[type] : [];
    const selected = new Set(current.filter(row => row.related_type === type).map(row => Number(row.related_id)));
    const available = rows.filter(row => !selected.has(Number(row.id)));
    const opts = available.map(row => `<option value="${Number(row.id)}">${esc(row.title)}${row.status && row.status !== 'published' ? ` · ${esc(String(row.status).toUpperCase())}` : ''}</option>`).join('');
    return `<div class="media-relation-add"><label>${esc(label(type))}<select data-memory-select="${type}" ${available.length ? '' : 'disabled'}><option value="">SELECT ${esc(label(type))}</option>${opts}</select></label><button type="button" data-memory-add="${type}" ${available.length ? '' : 'disabled'}>ADD</button></div>`;
  }

  function bind(panel) {
    panel.querySelectorAll('[data-memory-remove]').forEach(button => button.addEventListener('click', () => {
      current.splice(Number(button.dataset.memoryRemove), 1);
      renderReady(panel);
    }));
    panel.querySelectorAll('[data-memory-add]').forEach(button => button.addEventListener('click', () => {
      const type = button.dataset.memoryAdd;
      const select = panel.querySelector(`[data-memory-select="${CSS.escape(type)}"]`);
      const id = Number(select?.value || 0);
      if (!TYPES.includes(type) || id < 1) return;
      current.push({related_type:type,related_id:id,sort_order:current.length});
      renderReady(panel);
    }));
    panel.querySelector('[data-memory-save]')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      button.disabled = true;
      button.textContent = 'SAVING…';
      try {
        const payload = await request(currentMediaId, {method:'PUT',body:JSON.stringify({relations:current})});
        current = Array.isArray(payload.data?.relations) ? payload.data.relations : current;
        window.BRVTALNotify?.('success', 'Memory connections saved.');
        renderReady(panel);
      } catch (error) {
        window.BRVTALNotify?.('error', `Could not save Memory connections · ${error.message}`, {timeout:0});
        button.disabled = false;
        button.textContent = 'SAVE CONNECTIONS';
      }
    });
  }

  function renderReady(panel) {
    panel.innerHTML = `<div class="media-relations-head"><span>CULTURAL CONNECTIONS</span><b>STRUCTURED / PUBLIC-SAFE</b></div><p>Connect this Memory to real BRVTAL records. Public pages only expose targets that are themselves public.</p><div class="media-relation-chips">${relationRows()}</div><div class="media-relation-add-grid">${TYPES.map(selector).join('')}</div><button class="media-relations-save" type="button" data-memory-save>SAVE CONNECTIONS</button>`;
    bind(panel);
  }

  function panelFor(mediaId) {
    const body = document.querySelector('#media-inspector .media-inspector-body');
    if (!body) return null;
    let panel = body.querySelector('#media-relations-panel');
    if (!panel) {
      panel = document.createElement('section');
      panel.id = 'media-relations-panel';
      panel.className = 'media-relations-panel';
      body.appendChild(panel);
    }
    panel.dataset.mediaId = String(mediaId);
    return panel;
  }

  async function mount() {
    const mediaId = activeMediaId();
    if (mediaId < 1) {
      currentMediaId = 0;
      return;
    }
    const existing = document.querySelector('#media-relations-panel');
    if (mediaId === currentMediaId && existing?.dataset.loaded === '1') return;
    currentMediaId = mediaId;
    const panel = panelFor(mediaId);
    if (!panel) return;
    const id = ++loadId;
    panel.dataset.loaded = '0';
    panel.innerHTML = '<div class="media-relations-loading">LOADING CULTURAL CONNECTIONS…</div>';
    try {
      const payload = await request(mediaId);
      if (id !== loadId || mediaId !== activeMediaId() || !panel.isConnected) return;
      if (payload.data?.available !== true) {
        panel.innerHTML = '<div class="media-relations-migration"><strong>CONNECTION MODEL PENDING</strong><span>Apply migration_media_relations_01.sql through the controlled migration flow before editing Memory connections.</span></div>';
        panel.dataset.loaded = '1';
        return;
      }
      current = Array.isArray(payload.data.relations) ? payload.data.relations : [];
      options = payload.data.options && typeof payload.data.options === 'object' ? payload.data.options : {};
      panel.dataset.loaded = '1';
      renderReady(panel);
    } catch (error) {
      if (id !== loadId) return;
      panel.innerHTML = `<div class="media-relations-migration"><strong>CONNECTIONS UNAVAILABLE</strong><span>${esc(error.message)}</span></div>`;
      panel.dataset.loaded = '1';
    }
  }

  const observer = new MutationObserver(() => {
    clearTimeout(timer);
    timer = setTimeout(mount, 50);
  });
  observer.observe(document.documentElement, {childList:true,subtree:true,attributes:true,attributeFilter:['class']});
  setTimeout(mount, 100);
})();
