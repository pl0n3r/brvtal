(() => {
  'use strict';

  const ENDPOINT = '/api/bulk-actions.php';
  const SPECS = {
    events: {label:'EVENTS', endpoint:'/api/index.php/events', title:'title', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH'],['archived','ARCHIVE']]},
    artists: {label:'ARTISTS', endpoint:'/api/index.php/artists', title:'name', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH']]},
    sets: {label:'SETS', endpoint:'/api/index.php/sets', title:'title', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH']]},
    pages: {label:'PAGES', endpoint:'/api/index.php/pages', title:'title', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH']]},
    releases: {label:'RELEASES', endpoint:'/api/releases.php', title:'title', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH'],['archived','ARCHIVE']]},
    blog: {label:'BLOG', endpoint:'/api/blog.php', title:'title', statuses:[['draft','MOVE TO DRAFT'],['published','PUBLISH'],['archived','ARCHIVE']]},
  };

  const state = {open:false,module:null,rows:[],selected:new Set(),query:''};
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));

  function currentModule() {
    const active = document.querySelector('.nav button.active');
    if (active?.dataset.adminNav && SPECS[active.dataset.adminNav]) return active.dataset.adminNav;
    const handler = active?.getAttribute('onclick') || '';
    const match = handler.match(/(?:go|tech)\('([^']+)'\)/);
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
      .brvtal-bulk-dialog{width:min(900px,100%);max-height:86vh;overflow:hidden;border:1px solid #34393e;background:#080909;box-shadow:0 28px 90px rgba(0,0,0,.6)}
      .brvtal-bulk-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:17px 18px;border-bottom:1px solid #24282c}
      .brvtal-bulk-title{font-size:18px;font-weight:950;letter-spacing:-.4px}.brvtal-bulk-kicker{color:#747c82;font:800 8px/1 monospace;letter-spacing:1.8px;margin-bottom:6px}
      .brvtal-bulk-close{border:1px solid #34393e;background:#101214;color:#fff;padding:8px 10px;font:800 9px/1 monospace;letter-spacing:1px}
      .brvtal-bulk-toolbar{display:grid;grid-template-columns:minmax(0,1fr) 210px auto;gap:9px;padding:13px 16px;border-bottom:1px solid #24282c}
      .brvtal-bulk-search,.brvtal-bulk-status{width:100%;border:1px solid #30353a;background:#070808;color:#fff;padding:10px 11px;font-size:11px}
      .brvtal-bulk-select-all{border:1px solid #34393e;background:#101214;color:#fff;padding:9px 12px;font:800 8px/1 monospace;letter-spacing:1px}
      .brvtal-bulk-meta{display:flex;justify-content:space-between;gap:12px;padding:9px 16px;border-bottom:1px solid #1d2023;color:#737b82;font:700 8px/1.3 monospace;letter-spacing:1.2px}
      .brvtal-bulk-list{max-height:53vh;overflow:auto;padding:8px 16px}
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
        <div class="brvtal-bulk-toolbar"><input class="brvtal-bulk-search" type="search" placeholder="Filter current module…" aria-label="Filter bulk action items"><select class="brvtal-bulk-status" aria-label="Bulk status action"></select><button type="button" class="brvtal-bulk-select-all">SELECT ALL</button></div>
        <div class="brvtal-bulk-meta"><span class="brvtal-bulk-count">0 SELECTED</span><span>MAX 100 · NO BULK DELETE</span></div>
        <div class="brvtal-bulk-list" aria-live="polite"><div class="brvtal-bulk-empty">OPEN BULK ACTIONS FROM A SUPPORTED MODULE.</div></div>
        <div class="brvtal-bulk-foot"><small>Status changes are transactional and require confirmation.</small><button type="button" class="brvtal-bulk-apply" disabled>APPLY STATUS</button></div>
      </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.brvtal-bulk-close')?.addEventListener('click', close);
    overlay.addEventListener('mousedown', event => { if (event.target === overlay) close(); });
    overlay.querySelector('.brvtal-bulk-search')?.addEventListener('input', event => { state.query = event.target.value || ''; renderRows(); });
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
    trigger.type = 'button';
    trigger.className = 'brvtal-bulk-trigger';
    trigger.dataset.module = module;
    trigger.setAttribute('aria-label','Open bulk actions for ' + SPECS[module].label);
    trigger.textContent = 'BULK ACTIONS';
    trigger.addEventListener('click', () => open(module));
    const status = top.querySelector('.status');
    if (status) top.insertBefore(trigger,status); else top.appendChild(trigger);
  }

  async function getCsrf() {
    try { if (typeof csrf !== 'undefined' && csrf) return csrf; } catch (_) {}
    const response = await fetch('/api/index.php/auth',{credentials:'same-origin',cache:'no-store'});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.authenticated || !payload.csrf) throw new Error('AUTH_REQUIRED');
    return payload.csrf;
  }

  async function open(module = currentModule()) {
    if (!module || !SPECS[module]) return;
    ensureStyle();
    const overlay = ensureOverlay();
    state.open = true; state.module = module; state.rows = []; state.selected.clear(); state.query = '';
    overlay.classList.add('open'); overlay.setAttribute('aria-hidden','false'); document.body.style.overflow = 'hidden';
    overlay.querySelector('.brvtal-bulk-title').textContent = 'BULK · ' + SPECS[module].label;
    overlay.querySelector('.brvtal-bulk-search').value = '';
    overlay.querySelector('.brvtal-bulk-status').innerHTML = '<option value="">CHOOSE STATUS…</option>' + SPECS[module].statuses.map(([value,label]) => `<option value="${esc(value)}">${esc(label)}</option>`).join('');
    overlay.querySelector('.brvtal-bulk-list').innerHTML = '<div class="brvtal-bulk-empty">LOADING CONTENT…</div>';
    updateControls();
    try {
      const response = await fetch(SPECS[module].endpoint,{credentials:'same-origin',cache:'no-store'});
      const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
      if (!response.ok || payload.ok === false) throw new Error(payload.error || ('HTTP_' + response.status));
      state.rows = Array.isArray(payload.data) ? payload.data.slice(0,500) : [];
      renderRows();
      requestAnimationFrame(() => overlay.querySelector('.brvtal-bulk-search')?.focus());
    } catch (error) {
      overlay.querySelector('.brvtal-bulk-list').innerHTML = '<div class="brvtal-bulk-empty">BULK ACTIONS UNAVAILABLE</div>';
      window.BRVTALFeedback?.error?.('Bulk actions unavailable: ' + (error?.message || 'UNKNOWN_ERROR'),'bulk-actions');
    }
  }

  function close() {
    const overlay = document.getElementById('brvtal-bulk-actions');
    if (!overlay) return;
    state.open = false; state.selected.clear();
    overlay.classList.remove('open'); overlay.setAttribute('aria-hidden','true'); document.body.style.overflow = '';
  }

  function visibleRows() {
    const q = state.query.trim().toLowerCase();
    if (!q) return state.rows;
    const spec = SPECS[state.module];
    return state.rows.filter(row => `${row[spec.title] || ''} ${row.slug || ''} ${row.status || ''}`.toLowerCase().includes(q));
  }

  function renderRows() {
    const host = document.querySelector('#brvtal-bulk-actions .brvtal-bulk-list');
    if (!host || !state.module) return;
    const spec = SPECS[state.module];
    const rows = visibleRows();
    if (!rows.length) {
      host.innerHTML = '<div class="brvtal-bulk-empty">NO ITEMS IN THIS VIEW.</div>';
      updateControls(); return;
    }
    host.innerHTML = rows.map(row => {
      const id = Number(row.id || 0);
      const title = row[spec.title] || ('#' + id);
      return `<label class="brvtal-bulk-row" data-bulk-row="${id}"><input type="checkbox" data-bulk-id="${id}" ${state.selected.has(id)?'checked':''}><span><span class="brvtal-bulk-row-title">${esc(title)}</span><span class="brvtal-bulk-row-sub">#${id}${row.slug?' · '+esc(row.slug):''}</span></span><span class="brvtal-bulk-pill">${esc(row.status || '—')}</span></label>`;
    }).join('');
    host.querySelectorAll('[data-bulk-id]').forEach(input => input.addEventListener('change', () => {
      const id = Number(input.dataset.bulkId || 0); if (!id) return;
      input.checked ? state.selected.add(id) : state.selected.delete(id); updateControls();
    }));
    updateControls();
  }

  function selectAllVisible() {
    const ids = visibleRows().map(row => Number(row.id || 0)).filter(Boolean);
    const allSelected = ids.length > 0 && ids.every(id => state.selected.has(id));
    ids.forEach(id => allSelected ? state.selected.delete(id) : state.selected.add(id));
    renderRows();
  }

  function updateControls() {
    const overlay = document.getElementById('brvtal-bulk-actions');
    if (!overlay) return;
    const status = overlay.querySelector('.brvtal-bulk-status')?.value || '';
    const count = state.selected.size;
    const countNode = overlay.querySelector('.brvtal-bulk-count'); if (countNode) countNode.textContent = count + ' SELECTED';
    const button = overlay.querySelector('.brvtal-bulk-apply'); if (button) button.disabled = !status || count < 1 || count > 100;
    const visible = visibleRows().map(row => Number(row.id || 0)).filter(Boolean);
    const allSelected = visible.length > 0 && visible.every(id => state.selected.has(id));
    const selectAll = overlay.querySelector('.brvtal-bulk-select-all'); if (selectAll) selectAll.textContent = allSelected ? 'CLEAR VISIBLE' : 'SELECT ALL';
  }

  async function apply() {
    if (!state.module || !SPECS[state.module]) return;
    const overlay = ensureOverlay();
    const status = overlay.querySelector('.brvtal-bulk-status')?.value || '';
    const ids = [...state.selected];
    if (!status || !ids.length || ids.length > 100) return;
    if (!window.confirm(`Set ${ids.length} ${SPECS[state.module].label.toLowerCase()} item${ids.length===1?'':'s'} to ${status.toUpperCase()}?`)) return;
    const button = overlay.querySelector('.brvtal-bulk-apply'); if (button) button.disabled = true;
    try {
      const token = await getCsrf();
      const response = await fetch(ENDPOINT,{
        method:'POST', credentials:'same-origin', cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':token},
        body:JSON.stringify({action:'set_status',resource:state.module,status,ids})
      });
      const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
      if (!response.ok || payload.ok === false) throw new Error(payload.error || ('HTTP_' + response.status));
      const module = state.module;
      close();
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
  window.BRVTALBulkActions = {open,close,currentModule};
})();
