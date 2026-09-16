(() => {
  'use strict';

  const ENDPOINT = '/api/media-relations.php';
  let renderTimer = null;
  let requestToken = 0;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[ch]));

  function currentMediaId() {
    const active = document.querySelector('#media-grid .media-card.active[data-media-id], .media-card.active[data-media-id]');
    const id = Number(active?.dataset.mediaId || 0);
    return Number.isSafeInteger(id) && id > 0 ? id : 0;
  }

  function inspectorBody() {
    return document.querySelector('#media-inspector .media-inspector-body');
  }

  async function adminCsrf() {
    try {
      if (typeof csrf !== 'undefined' && csrf) return csrf;
    } catch (_) {}
    const response = await fetch('/api/index.php/auth', {credentials:'same-origin', cache:'no-store'});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.authenticated || !payload.csrf) throw new Error('AUTH_REQUIRED');
    return payload.csrf;
  }

  async function request(mediaId, method = 'GET', body = null) {
    const headers = {'Accept':'application/json'};
    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = await adminCsrf();
    }
    const response = await fetch(`${ENDPOINT}?media_id=${encodeURIComponent(mediaId)}`, {
      method,
      credentials:'same-origin',
      cache:'no-store',
      headers,
      body: body === null ? null : JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.error || `HTTP_${response.status}`);
      error.status = response.status;
      throw error;
    }
    return payload.data || {};
  }

  function groupLabel(type) {
    return ({event:'EVENTS',artist:'ARTISTS',set:'SETS',release:'RELEASES'})[type] || String(type || '').toUpperCase();
  }

  function relationKey(type, id) {
    return `${String(type)}:${Number(id)}`;
  }

  function renderPending(host, mediaId) {
    host.innerHTML = `<div class="media-relations" data-media-relations data-media-id="${mediaId}">
      <div class="media-relations-head"><div><b>CULTURAL RELATIONS</b><span>MEMORY → ARCHIVE CONTEXT</span></div><span class="media-relations-count">—</span></div>
      <div class="media-relations-state">RELATIONS MIGRATION PENDING · Media remains fully usable. Apply the additive migration separately before editing cultural links.</div>
    </div>`;
  }

  function renderError(host, mediaId, message) {
    host.innerHTML = `<div class="media-relations" data-media-relations data-media-id="${mediaId}">
      <div class="media-relations-head"><div><b>CULTURAL RELATIONS</b><span>MEMORY → ARCHIVE CONTEXT</span></div><span class="media-relations-count">!</span></div>
      <div class="media-relations-state error">${esc(message || 'Unable to load relations')}</div>
      <button class="btn ghost media-relations-retry" type="button">RETRY</button>
    </div>`;
    host.querySelector('.media-relations-retry')?.addEventListener('click', () => load(true));
  }

  function renderEditor(host, mediaId, data) {
    const selected = new Set((Array.isArray(data.relations) ? data.relations : []).map(row => relationKey(row.related_type, row.related_id)));
    const groups = new Map();
    (Array.isArray(data.options) ? data.options : []).forEach(option => {
      const type = String(option.related_type || '');
      if (!groups.has(type)) groups.set(type, []);
      groups.get(type).push(option);
    });

    const lists = [...groups.entries()].map(([type, options]) => `
      <div class="media-relations-group" data-relation-group="${esc(type)}">
        <div class="media-relations-group-head"><span>${esc(groupLabel(type))}</span><b>${options.length}</b></div>
        ${options.map(option => {
          const id = Number(option.related_id || 0);
          const key = relationKey(type, id);
          const title = String(option.title || `#${id}`);
          const status = String(option.status || '').toUpperCase();
          return `<label class="media-relation-option" data-relation-search="${esc(`${groupLabel(type)} ${title} ${status}`.toLowerCase())}">
            <input type="checkbox" data-related-type="${esc(type)}" data-related-id="${id}" ${selected.has(key) ? 'checked' : ''}>
            <span><strong>${esc(title)}</strong><small>${esc(status || 'UNKNOWN')}</small></span>
          </label>`;
        }).join('') || '<div class="media-relations-empty">NO RECORDS</div>'}
      </div>`).join('');

    host.innerHTML = `<div class="media-relations" data-media-relations data-media-id="${mediaId}">
      <div class="media-relations-head"><div><b>CULTURAL RELATIONS</b><span>MEMORY → EVENT / ARTIST / SET / RELEASE</span></div><span class="media-relations-count">${selected.size}</span></div>
      <p>Only explicit links are stored. Draft targets can be prepared here but remain private until the target itself is public.</p>
      <input class="media-relations-search" type="search" placeholder="FILTER RELATION TARGETS…" aria-label="Filter cultural relation targets">
      <div class="media-relations-groups">${lists || '<div class="media-relations-empty">NO RELATION TARGETS AVAILABLE</div>'}</div>
      <button class="btn red media-relations-save" type="button">SAVE RELATIONS</button>
      <div class="media-relations-feedback" role="status" aria-live="polite"></div>
    </div>`;

    const block = host.querySelector('[data-media-relations]');
    const count = block?.querySelector('.media-relations-count');
    const updateCount = () => {
      const n = block?.querySelectorAll('input[type="checkbox"]:checked').length || 0;
      if (count) count.textContent = String(n);
    };
    block?.querySelectorAll('input[type="checkbox"]').forEach(input => input.addEventListener('change', updateCount));

    const search = block?.querySelector('.media-relations-search');
    search?.addEventListener('input', () => {
      const query = String(search.value || '').trim().toLowerCase();
      block.querySelectorAll('.media-relation-option').forEach(option => {
        option.hidden = Boolean(query) && !String(option.dataset.relationSearch || '').includes(query);
      });
    });

    block?.querySelector('.media-relations-save')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      const feedback = block.querySelector('.media-relations-feedback');
      const checked = [...block.querySelectorAll('input[type="checkbox"]:checked')];
      const relations = checked.map((input, index) => ({
        related_type: input.dataset.relatedType,
        related_id: Number(input.dataset.relatedId),
        sort_order: index,
      }));
      button.disabled = true;
      if (feedback) feedback.textContent = 'SAVING…';
      try {
        const saved = await request(mediaId, 'PUT', {relations});
        if (feedback) feedback.textContent = 'RELATIONS SAVED';
        renderEditor(host, mediaId, saved);
        window.BRVTALNotify?.('success', 'Cultural relations saved.');
      } catch (error) {
        if (feedback) feedback.textContent = String(error.message || error);
        window.BRVTALNotify?.('error', `Could not save cultural relations · ${error.message || error}`, {timeout:0});
        button.disabled = false;
      }
    });
  }

  function ensureHost(body) {
    let host = body.querySelector('[data-media-relations-host]');
    if (host) return host;
    host = document.createElement('div');
    host.dataset.mediaRelationsHost = '1';
    const usage = body.querySelector('.media-usage');
    if (usage) usage.before(host);
    else body.querySelector('.media-inspector-actions')?.before(host);
    return host;
  }

  async function load(force = false) {
    const mediaId = currentMediaId();
    const body = inspectorBody();
    if (!mediaId || !body) return;
    const host = ensureHost(body);
    const current = host.querySelector('[data-media-relations]');
    if (!force && Number(current?.dataset.mediaId || 0) === mediaId) return;

    const token = ++requestToken;
    host.innerHTML = `<div class="media-relations" data-media-relations data-media-id="${mediaId}"><div class="media-relations-state">LOADING CULTURAL RELATIONS…</div></div>`;
    try {
      const data = await request(mediaId);
      if (token !== requestToken || currentMediaId() !== mediaId || !host.isConnected) return;
      if (data.available === false) renderPending(host, mediaId);
      else renderEditor(host, mediaId, data);
    } catch (error) {
      if (token !== requestToken || currentMediaId() !== mediaId || !host.isConnected) return;
      renderError(host, mediaId, error.message || String(error));
    }
  }

  function schedule() {
    clearTimeout(renderTimer);
    renderTimer = setTimeout(() => load(false), 35);
  }

  const observer = new MutationObserver(mutations => {
    if (!mutations.some(mutation => mutation.addedNodes.length || mutation.removedNodes.length || mutation.type === 'attributes')) return;
    if (!document.getElementById('media-inspector')) return;
    schedule();
  });
  observer.observe(document.documentElement, {childList:true, subtree:true, attributes:true, attributeFilter:['class']});
  document.addEventListener('click', event => {
    if (event.target.closest?.('[data-media-id]')) setTimeout(() => load(true), 0);
  });
  setTimeout(schedule, 100);
})();
