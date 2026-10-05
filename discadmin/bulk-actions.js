(() => {
  'use strict';

  const CATALOG_ENDPOINT = '/api/bulk-catalog.php';
  const MUTATION_ENDPOINT = '/api/bulk-actions.php';
  const PAGE_SIZE = 50;
  const MAX_SELECTED = 100;
  const SEARCH_DEBOUNCE_MS = 250;
  const REQUEST_TIMEOUT_MS = 10000;
  const SPECS = {
    events: {label:'EVENTS', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH'],['archived','ARCHIVE']]},
    artists: {label:'ARTISTS', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH']]},
    sets: {label:'SETS', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH']]},
    pages: {label:'PAGES', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH']]},
    releases: {label:'RELEASES', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH'],['archived','ARCHIVE']]},
    blog: {label:'BLOG', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH'],['archived','ARCHIVE']]},
  };

  const state = {
    open:false, module:null, rows:[], selected:new Set(), query:'',
    pageIndex:0, cursorStack:[''], nextCursor:'', pagination:null,
    ready:false, unavailable:false, loadId:0, loadController:null, searchTimer:null,
  };
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => (
    {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]
  ));

  function currentModule() {
    const active = document.querySelector('.nav button.active');
    if (active?.dataset.adminNav && SPECS[active.dataset.adminNav]) return active.dataset.adminNav;
    const match = /(?:go|tech)\('([^']+)'\)/.exec(active?.getAttribute('onclick') || '');
    if (match && SPECS[match[1]]) return match[1];
    const heading = document.querySelector('.main .top h1')?.textContent?.trim().toLowerCase() || '';
    return Object.keys(SPECS).find(key => SPECS[key].label.toLowerCase() === heading) || null;
  }

  function ensureStyle() {
    if (document.getElementById('brvtal-bulk-actions-style')) return;
    const style = document.createElement('style');
    style.id = 'brvtal-bulk-actions-style';
    style.textContent = `
      .brvtal-bulk-trigger{border:1px solid #34393e;background:#0b0d0e;color:#dfe3e6;padding:8px 11px;font:800 9px/1 monospace;letter-spacing:1.2px;white-space:nowrap}
      .brvtal-bulk-trigger:hover,.brvtal-bulk-trigger:focus-visible{border-color:#fff;outline:none}
      .brvtal-bulk-overlay{position:fixed;inset:0;z-index:125;background:rgba(0,0,0,.9);backdrop-filter:blur(12px);display:none;align-items:flex-start;justify-content:center;padding:7vh 18px 18px}
      .brvtal-bulk-overlay.open{display:flex}
      .brvtal-bulk-dialog{display:flex;flex-direction:column;width:min(900px,100%);max-height:86vh;overflow:hidden;border:1px solid #34393e;background:#080909;box-shadow:0 28px 90px rgba(0,0,0,.6)}
      .brvtal-bulk-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:17px 18px;border-bottom:1px solid #24282c}
      .brvtal-bulk-title{font-size:18px;font-weight:950;letter-spacing:-.4px}.brvtal-bulk-kicker{color:#747c82;font:800 8px/1 monospace;letter-spacing:1.8px;margin-bottom:6px}
      .brvtal-bulk-close{border:1px solid #34393e;background:#101214;color:#fff;padding:8px 10px;font:800 9px/1 monospace;letter-spacing:1px}
      .brvtal-bulk-toolbar{display:grid;grid-template-columns:minmax(0,1fr) 210px auto;gap:9px;padding:13px 16px;border-bottom:1px solid #24282c}
      .brvtal-bulk-search,.brvtal-bulk-status{width:100%;border:1px solid #30353a;background:#070808;color:#fff;padding:10px 11px;font-size:11px}
      .brvtal-bulk-select-all{border:1px solid #34393e;background:#101214;color:#fff;padding:9px 12px;font:800 8px/1 monospace;letter-spacing:1px}
      .brvtal-bulk-meta{display:flex;justify-content:space-between;gap:12px;padding:9px 16px;border-bottom:1px solid #1d2023;color:#737b82;font:700 8px/1.3 monospace;letter-spacing:1.2px}
      .brvtal-bulk-list{flex:1 1 auto;min-height:0;max-height:48vh;overflow:auto;padding:8px 16px}
      .brvtal-bulk-pages{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;padding:9px 16px;border-top:1px solid #1d2023;color:#aab0b5;font:700 9px/1.4 monospace}
      .brvtal-bulk-pages button{min-height:44px;border:1px solid #34393e;background:#101214;color:#fff;padding:8px 11px;font:800 9px/1 monospace}
      .brvtal-bulk-pages button:disabled{opacity:.35;cursor:not-allowed}.brvtal-bulk-page-info{flex:1 1 180px;text-align:center;min-width:0;overflow-wrap:anywhere}
      .brvtal-bulk-row{display:grid;grid-template-columns:24px minmax(0,1fr) auto;gap:11px;align-items:center;border-top:1px solid #202428;padding:11px 2px}
      .brvtal-bulk-row:first-child{border-top:0}.brvtal-bulk-row input{width:16px;height:16px;accent-color:#ff2038}
      .brvtal-bulk-row-title{font-size:12px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.brvtal-bulk-row-sub{margin-top:4px;color:#737b82;font-size:9px}
      .brvtal-bulk-pill{border:1px solid #30353a;padding:5px 7px;color:#aab0b5;font:800 8px/1 monospace;text-transform:uppercase}
      .brvtal-bulk-empty{padding:44px 8px;text-align:center;color:#676f75;font-size:10px;line-height:1.6}
      .brvtal-bulk-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px;border-top:1px solid #24282c}.brvtal-bulk-foot small{color:#737b82;font-size:9px}
      .brvtal-bulk-apply{border:0;background:#ff2038;color:#fff;padding:11px 15px;font-weight:900}.brvtal-bulk-apply:disabled{opacity:.35;cursor:not-allowed}
      @media(max-width:700px){.brvtal-bulk-toolbar{grid-template-columns:1fr}.brvtal-bulk-overlay{padding-top:3vh}.brvtal-bulk-dialog{max-height:92vh}.brvtal-bulk-list{max-height:55vh}}
    `;
    document.head.appendChild(style);
  }

  function ensureOverlay() {
    let overlay = document.getElementById('brvtal-bulk-actions');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'brvtal-bulk-actions';
    overlay.className = 'brvtal-bulk-overlay';
    overlay.setAttribute('aria-hidden','true');
    overlay.innerHTML = `
      <div class="brvtal-bulk-dialog" role="dialog" aria-modal="true" aria-labelledby="brvtal-bulk-title">
        <div class="brvtal-bulk-head"><div><div class="brvtal-bulk-kicker">DISCADMIN / SAFE BULK STATUS</div><div class="brvtal-bulk-title" id="brvtal-bulk-title">BULK ACTIONS</div></div><button type="button" class="brvtal-bulk-close">ESC / CLOSE</button></div>
        <div class="brvtal-bulk-toolbar"><input class="brvtal-bulk-search" type="search" maxlength="120" placeholder="Search this module…" aria-label="Search bulk action items"><select class="brvtal-bulk-status" aria-label="Bulk status action"></select><button type="button" class="brvtal-bulk-select-all">SELECT PAGE</button></div>
        <div class="brvtal-bulk-meta"><span class="brvtal-bulk-count">0 SELECTED</span><span>MAX 100 · NO BULK DELETE</span></div>
        <div class="brvtal-bulk-list" aria-live="polite"><div class="brvtal-bulk-empty">OPEN BULK ACTIONS FROM A SUPPORTED MODULE.</div></div>
        <div class="brvtal-bulk-pages"><button type="button" class="brvtal-bulk-prev" disabled>PREVIOUS</button><span class="brvtal-bulk-page-info" role="status" aria-live="polite">OPEN A MODULE</span><button type="button" class="brvtal-bulk-next" disabled>NEXT</button></div>
        <div class="brvtal-bulk-foot"><small>Status changes are transactional and require confirmation.</small><button type="button" class="brvtal-bulk-apply" disabled>APPLY STATUS</button></div>
      </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.brvtal-bulk-close')?.addEventListener('click', close);
    overlay.addEventListener('mousedown', event => { if (event.target === overlay) close(); });
    overlay.querySelector('.brvtal-bulk-search')?.addEventListener('input', event => {
      state.query = String(event.target.value || '').trim().slice(0, 120);
      scheduleSearch();
    });
    overlay.querySelector('.brvtal-bulk-prev')?.addEventListener('click', previousPage);
    overlay.querySelector('.brvtal-bulk-next')?.addEventListener('click', nextPage);
    overlay.querySelector('.brvtal-bulk-select-all')?.addEventListener('click', selectAllVisible);
    overlay.querySelector('.brvtal-bulk-status')?.addEventListener('change', updateControls);
    overlay.querySelector('.brvtal-bulk-apply')?.addEventListener('click', apply);
    return overlay;
  }

  function ensureTrigger() {
    const top = document.querySelector('.main .top');
    const existing = document.querySelector('.brvtal-bulk-trigger');
    const module = currentModule();
    if (!top || !module) { existing?.remove(); return; }
    if (existing && existing.dataset.module === module) return;
    existing?.remove();
    const trigger = document.createElement('button');
    trigger.type = 'button'; trigger.className = 'brvtal-bulk-trigger'; trigger.dataset.module = module;
    trigger.setAttribute('aria-label','Open bulk actions for ' + SPECS[module].label);
    trigger.textContent = 'BULK ACTIONS'; trigger.addEventListener('click', () => open(module));
    const status = top.querySelector('.status');
    if (status) status.before(trigger); else top.appendChild(trigger);
  }

  async function getCsrf() {
    if (window.BRVTALAdminAuthBoundary?.csrfToken) return window.BRVTALAdminAuthBoundary.csrfToken();
    try { if (typeof csrf !== 'undefined' && csrf) return csrf; } catch (_) {}
    throw new Error('AUTH_REQUIRED');
  }

  function cancelLoad() {
    state.loadId += 1;
    state.loadController?.abort();
    state.loadController = null;
  }

  function clearSearchTimer() {
    if (state.searchTimer !== null) clearTimeout(state.searchTimer);
    state.searchTimer = null;
  }

  function resetNavigation() {
    state.pageIndex = 0; state.cursorStack = ['']; state.nextCursor = ''; state.pagination = null;
  }

  function renderMessage(message) {
    const host = document.querySelector('#brvtal-bulk-actions .brvtal-bulk-list');
    if (host) host.innerHTML = `<div class="brvtal-bulk-empty">${esc(message)}</div>`;
  }

  function setLoading(message = 'LOADING CONTENT…') {
    state.ready = false; state.unavailable = false; state.rows = []; state.pagination = null;
    renderMessage(message); updateControls();
  }

  function setUnavailable(error) {
    state.ready = false; state.unavailable = true; state.rows = []; state.pagination = null; state.nextCursor = '';
    renderMessage('BULK ACTIONS UNAVAILABLE');
    window.BRVTALFeedback?.error?.('Bulk actions unavailable: ' + error,'bulk-actions');
    updateControls();
  }

  function normalizeCatalog(payload, module, query, pageIndex) {
    const data = payload?.ok === true && payload.data && typeof payload.data === 'object' ? payload.data : null;
    const page = data?.pagination;
    if (!data || data.resource !== module || data.q !== query || !Array.isArray(data.items) || !page) {
      throw new Error('INVALID_CATALOG_RESPONSE');
    }
    if (page.limit !== PAGE_SIZE
      || !Number.isInteger(page.returned) || page.returned !== data.items.length
      || !Number.isInteger(page.total) || page.total < page.returned
      || !Number.isInteger(pageIndex) || pageIndex < 0
      || typeof page.has_more !== 'boolean' || typeof page.snapshot_complete !== 'boolean'
      || !page.range || !Number.isInteger(page.range.after_id) || !Number.isInteger(page.range.last_id)
      || page.range.after_id < 0 || page.range.last_id < page.range.after_id) {
      throw new Error('INVALID_CATALOG_RESPONSE');
    }
    const next = page.next_cursor;
    const progressed = (pageIndex * PAGE_SIZE) + page.returned;
    if (page.has_more) {
      if (typeof next !== 'string' || next === '' || page.snapshot_complete
        || page.returned !== PAGE_SIZE || progressed >= page.total) {
        throw new Error('INCOMPLETE_CATALOG_RESPONSE');
      }
    } else if ((next !== null && next !== '') || !page.snapshot_complete || progressed !== page.total) {
      throw new Error('INCOMPLETE_CATALOG_RESPONSE');
    }
    const ids = [];
    for (const item of data.items) {
      if (!Number.isInteger(item?.id) || item.id < 1 || typeof item.label !== 'string'
        || typeof item.slug !== 'string' || typeof item.status !== 'string') {
        throw new Error('INVALID_CATALOG_RESPONSE');
      }
      ids.push(item.id);
    }
    if (new Set(ids).size !== ids.length
      || ids.some((id, index) => index > 0 && id <= ids[index - 1])
      || (ids.length > 0 && (ids[0] <= page.range.after_id || ids.at(-1) !== page.range.last_id))
      || (ids.length === 0 && page.range.last_id !== page.range.after_id)) {
      throw new Error('INVALID_CATALOG_RESPONSE');
    }
    return data;
  }

  async function loadPage(cursor = '', pageIndex = 0) {
    if (!state.open || !state.module) return;
    clearSearchTimer(); cancelLoad();
    const module = state.module;
    const query = state.query;
    const loadId = ++state.loadId;
    const controller = new AbortController();
    state.loadController = controller;
    let timedOut = false;
    const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, REQUEST_TIMEOUT_MS);
    setLoading(query ? 'SEARCHING CONTENT…' : 'LOADING CONTENT…');
    try {
      const params = new URLSearchParams({resource:module,q:query,limit:String(PAGE_SIZE)});
      if (cursor) params.set('cursor', cursor);
      const response = await fetch(`${CATALOG_ENDPOINT}?${params.toString()}`, {
        credentials:'same-origin', cache:'no-store', signal:controller.signal,
      });
      const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
      if (!response.ok || payload.ok === false) throw new Error(payload.error || ('HTTP_' + response.status));
      if (!state.open || state.module !== module || state.loadId !== loadId) return;
      const data = normalizeCatalog(payload, module, query, pageIndex);
      state.rows = data.items; state.pagination = data.pagination; state.ready = true; state.unavailable = false;
      state.pageIndex = pageIndex; state.nextCursor = data.pagination.next_cursor || '';
      state.cursorStack[pageIndex] = cursor; state.cursorStack = state.cursorStack.slice(0, pageIndex + 1);
      renderRows();
    } catch (error) {
      if (state.loadId !== loadId || !state.open || state.module !== module) return;
      if (error?.name === 'AbortError' && !timedOut) return;
      setUnavailable(timedOut ? 'REQUEST_TIMEOUT' : (error?.message || 'UNKNOWN_ERROR'));
    } finally {
      clearTimeout(timeout);
      if (state.loadId === loadId) state.loadController = null;
    }
  }

  function scheduleSearch() {
    clearSearchTimer(); cancelLoad(); resetNavigation();
    setLoading('SEARCHING CONTENT…');
    state.searchTimer = setTimeout(() => loadPage('', 0), SEARCH_DEBOUNCE_MS);
  }

  async function open(module = currentModule(), initialIds = []) {
    if (!module || !SPECS[module]) return;
    ensureStyle();
    const overlay = ensureOverlay();
    clearSearchTimer(); cancelLoad();
    state.open = true; state.module = module; state.rows = []; state.query = ''; state.ready = false; state.unavailable = false;
    state.selected = new Set(
      [...new Set((Array.isArray(initialIds) ? initialIds : []).map(Number))]
        .filter(id => Number.isInteger(id) && id > 0).slice(0, MAX_SELECTED)
    );
    resetNavigation();
    overlay.classList.add('open'); overlay.setAttribute('aria-hidden','false'); document.body.style.overflow = 'hidden';
    overlay.querySelector('.brvtal-bulk-title').textContent = 'BULK · ' + SPECS[module].label;
    overlay.querySelector('.brvtal-bulk-search').value = '';
    overlay.querySelector('.brvtal-bulk-status').innerHTML = '<option value="">CHOOSE STATUS…</option>'
      + SPECS[module].statuses.map(([value,label]) => `<option value="${esc(value)}">${esc(label)}</option>`).join('');
    await loadPage('', 0);
    requestAnimationFrame(() => {
      if (state.open && state.module === module) overlay.querySelector('.brvtal-bulk-search')?.focus();
    });
  }

  function close() {
    const overlay = document.getElementById('brvtal-bulk-actions');
    if (!overlay) return;
    clearSearchTimer(); cancelLoad();
    state.open = false; state.module = null; state.rows = []; state.selected.clear(); state.query = '';
    state.ready = false; state.unavailable = false; resetNavigation();
    overlay.classList.remove('open'); overlay.setAttribute('aria-hidden','true'); document.body.style.overflow = '';
  }

  function previousPage() {
    if (!state.ready || state.pageIndex < 1) return;
    const index = state.pageIndex - 1;
    void loadPage(state.cursorStack[index] || '', index);
  }

  function nextPage() {
    if (!state.ready || !state.nextCursor) return;
    const index = state.pageIndex + 1;
    state.cursorStack[index] = state.nextCursor;
    void loadPage(state.nextCursor, index);
  }

  function recordIds(rows = state.rows) {
    return rows.map(row => Number(row.id || 0)).filter(id => Number.isInteger(id) && id > 0);
  }

  function renderRows() {
    const host = document.querySelector('#brvtal-bulk-actions .brvtal-bulk-list');
    if (!host || !state.module || !state.ready) return;
    if (!state.rows.length) {
      renderMessage('NO ITEMS IN THIS VIEW.'); updateControls(); return;
    }
    host.innerHTML = state.rows.map(row => {
      const id = Number(row.id);
      return `<label class="brvtal-bulk-row" data-bulk-row="${id}"><input type="checkbox" data-bulk-id="${id}" ${state.selected.has(id)?'checked':''}><span><span class="brvtal-bulk-row-title">${esc(row.label || ('#' + id))}</span><span class="brvtal-bulk-row-sub">#${id}${row.slug?' · '+esc(row.slug):''}</span></span><span class="brvtal-bulk-pill">${esc(row.status || '—')}</span></label>`;
    }).join('');
    host.querySelectorAll('[data-bulk-id]').forEach(input => input.addEventListener('change', () => {
      const id = Number(input.dataset.bulkId || 0); if (!id) return;
      if (input.checked && !state.selected.has(id) && state.selected.size >= MAX_SELECTED) {
        input.checked = false; selectionLimitError(); return;
      }
      input.checked ? state.selected.add(id) : state.selected.delete(id);
      updateControls();
    }));
    updateControls();
  }

  function selectionLimitError() {
    window.BRVTALFeedback?.error?.('Select at most 100 records per bulk action.','bulk-actions');
  }

  function selectAllVisible() {
    if (!state.ready) return;
    const ids = recordIds();
    const allSelected = ids.length > 0 && ids.every(id => state.selected.has(id));
    if (allSelected) {
      ids.forEach(id => state.selected.delete(id));
    } else {
      const available = MAX_SELECTED - state.selected.size;
      ids.filter(id => !state.selected.has(id)).slice(0, available).forEach(id => state.selected.add(id));
      if (!ids.every(id => state.selected.has(id))) selectionLimitError();
    }
    renderRows();
  }

  function pageInfoText() {
    if (state.unavailable) return 'CATALOG UNAVAILABLE';
    if (!state.ready || !state.pagination) return 'LOADING CATALOG…';
    const page = state.pagination;
    const range = `IDS >${page.range.after_id}–${page.range.last_id}`;
    const completeness = page.snapshot_complete ? 'SNAPSHOT COMPLETE' : 'MORE AVAILABLE';
    return `PAGE ${state.pageIndex + 1} · ${page.returned} SHOWN OF ${page.total} · ${range} · ${completeness}`;
  }

  function updateControls() {
    const overlay = document.getElementById('brvtal-bulk-actions');
    if (!overlay) return;
    const status = overlay.querySelector('.brvtal-bulk-status')?.value || '';
    const count = state.selected.size;
    const countNode = overlay.querySelector('.brvtal-bulk-count');
    if (countNode) countNode.textContent = count + ' SELECTED';
    const info = overlay.querySelector('.brvtal-bulk-page-info');
    if (info) info.textContent = pageInfoText();
    const prev = overlay.querySelector('.brvtal-bulk-prev');
    const next = overlay.querySelector('.brvtal-bulk-next');
    if (prev) prev.disabled = !state.ready || state.pageIndex < 1;
    if (next) next.disabled = !state.ready || !state.nextCursor;
    const ids = state.ready ? recordIds() : [];
    const allSelected = ids.length > 0 && ids.every(id => state.selected.has(id));
    const selectAll = overlay.querySelector('.brvtal-bulk-select-all');
    if (selectAll) {
      selectAll.textContent = allSelected ? 'CLEAR PAGE' : 'SELECT PAGE';
      selectAll.disabled = !state.ready || ids.length === 0;
    }
    const button = overlay.querySelector('.brvtal-bulk-apply');
    if (button) button.disabled = !state.ready || !status || count < 1 || count > MAX_SELECTED;
  }

  async function apply() {
    const module = state.module;
    if (!module || !SPECS[module] || !state.ready) return;
    const overlay = ensureOverlay();
    const status = overlay.querySelector('.brvtal-bulk-status')?.value || '';
    const ids = [...state.selected];
    if (!status || !ids.length || ids.length > MAX_SELECTED || ids.some(id => !Number.isInteger(id) || id < 1)) return;
    const intentId = state.loadId;
    if (!window.confirm(`Set ${ids.length} ${SPECS[module].label.toLowerCase()} item${ids.length===1?'':'s'} to ${status.toUpperCase()}?`)) return;
    const button = overlay.querySelector('.brvtal-bulk-apply'); if (button) button.disabled = true;
    try {
      const token = await getCsrf();
      if (!state.open || state.module !== module || !state.ready || state.loadId !== intentId) return;
      const response = await fetch(MUTATION_ENDPOINT,{
        method:'POST', credentials:'same-origin', cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':token},
        body:JSON.stringify({action:'set_status',resource:module,status,ids})
      });
      const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
      if (!response.ok || payload.ok === false) throw new Error(payload.error || ('HTTP_' + response.status));
      close();
      window.BRVTALDataGrid?.clearSelection?.(module);
      window.BRVTALFeedback?.success?.(`${payload.data?.matched ?? ids.length} ${SPECS[module].label.toLowerCase()} updated to ${status}.`,'bulk-actions');
      if (typeof window.go === 'function') await window.go(module);
    } catch (error) {
      window.BRVTALFeedback?.error?.('Bulk action failed: ' + (error?.message || 'UNKNOWN_ERROR'),'bulk-actions');
      updateControls();
    }
  }

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && state.open) { event.preventDefault(); close(); }
  });
  const observer = new MutationObserver(() => ensureTrigger());
  observer.observe(document.documentElement,{childList:true,subtree:true});
  ensureStyle(); ensureOverlay(); ensureTrigger();
  window.BRVTALBulkActions = {open,close,currentModule,supports:module=>Boolean(SPECS[module])};
})();
