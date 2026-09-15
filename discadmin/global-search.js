(() => {
  'use strict';

  const ENDPOINT = '/api/admin-search.php';
  const state = { open:false, timer:null, controller:null, lastQuery:'' };

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));

  function ensureStyle() {
    if (document.getElementById('brvtal-global-search-style')) return;
    const style = document.createElement('style');
    style.id = 'brvtal-global-search-style';
    style.textContent = `
      .brvtal-global-search-trigger{margin-left:auto;border:1px solid #34393e;background:#0b0d0e;color:#dfe3e6;padding:8px 11px;font:800 9px/1 monospace;letter-spacing:1.2px;white-space:nowrap}
      .brvtal-global-search-trigger:hover,.brvtal-global-search-trigger:focus-visible{border-color:#fff;outline:none}
      .brvtal-global-search-trigger kbd{margin-left:8px;color:#737b82;font:700 8px/1 monospace}
      .brvtal-global-search-overlay{position:fixed;inset:0;z-index:120;background:rgba(0,0,0,.88);backdrop-filter:blur(12px);display:none;align-items:flex-start;justify-content:center;padding:9vh 18px 18px}
      .brvtal-global-search-overlay.open{display:flex}
      .brvtal-global-search-dialog{width:min(860px,100%);max-height:82vh;overflow:hidden;border:1px solid #34393e;background:#080909;box-shadow:0 28px 90px rgba(0,0,0,.55)}
      .brvtal-global-search-head{display:flex;align-items:center;gap:12px;padding:16px;border-bottom:1px solid #24282c}
      .brvtal-global-search-input{flex:1;min-width:0;border:0;background:transparent;color:#fff;font:800 clamp(19px,3vw,30px)/1.2 Arial,Helvetica,sans-serif;outline:none}
      .brvtal-global-search-input::placeholder{color:#555d63}
      .brvtal-global-search-close{border:1px solid #34393e;background:#101214;color:#fff;padding:8px 10px;font:800 9px/1 monospace;letter-spacing:1px}
      .brvtal-global-search-meta{padding:9px 16px;border-bottom:1px solid #1d2023;color:#697178;font:700 8px/1.3 monospace;letter-spacing:1.3px}
      .brvtal-global-search-results{max-height:62vh;overflow:auto;padding:8px 16px 16px}
      .brvtal-global-search-group{padding-top:12px}
      .brvtal-global-search-group-title{display:flex;justify-content:space-between;align-items:center;padding:0 2px 8px;color:#747c82;font:800 8px/1 monospace;letter-spacing:1.8px}
      .brvtal-global-search-item{width:100%;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;text-align:left;border:0;border-top:1px solid #202428;background:transparent;color:#fff;padding:12px 2px}
      .brvtal-global-search-item:hover,.brvtal-global-search-item:focus-visible{background:#0d0f10;outline:none}
      .brvtal-global-search-title{display:block;font-size:12px;font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .brvtal-global-search-sub{display:block;margin-top:4px;color:#737b82;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .brvtal-global-search-status{border:1px solid #30353a;padding:5px 7px;color:#aab0b5;font:800 8px/1 monospace;text-transform:uppercase}
      .brvtal-global-search-more{width:100%;margin-top:8px;border:1px solid #2d3236;background:#0d0f10;color:#aeb5ba;padding:10px 12px;font:800 8px/1 monospace;letter-spacing:1.4px}
      .brvtal-global-search-more:hover,.brvtal-global-search-more:focus-visible{border-color:#fff;color:#fff;outline:none}.brvtal-global-search-more:disabled{opacity:.5;cursor:wait}
      .brvtal-global-search-empty{padding:42px 8px;text-align:center;color:#676f75;font-size:10px;line-height:1.6}
      .brvtal-global-search-loading{padding:34px 8px;text-align:center;color:#8d949a;font:800 9px/1.5 monospace;letter-spacing:1.5px}
      @media(max-width:850px){.brvtal-global-search-trigger kbd{display:none}.brvtal-global-search-overlay{padding-top:4vh}.brvtal-global-search-dialog{max-height:90vh}.brvtal-global-search-results{max-height:72vh}}
    `;
    document.head.appendChild(style);
  }

  function ensureOverlay() {
    let overlay = document.getElementById('brvtal-global-search');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'brvtal-global-search';
    overlay.className = 'brvtal-global-search-overlay';
    overlay.setAttribute('aria-hidden','true');
    overlay.innerHTML = `
      <div class="brvtal-global-search-dialog" role="dialog" aria-modal="true" aria-labelledby="brvtal-global-search-label">
        <div class="brvtal-global-search-head">
          <input id="brvtal-global-search-input" class="brvtal-global-search-input" type="search" autocomplete="off" spellcheck="false" placeholder="Search BRVTAL content…" aria-label="Search all DISCADMIN content">
          <button class="brvtal-global-search-close" type="button">ESC / CLOSE</button>
        </div>
        <div id="brvtal-global-search-label" class="brvtal-global-search-meta">GLOBAL SEARCH · EVENTS · ARTISTS · SETS · MEDIA · PAGES · RELEASES · BLOG</div>
        <div class="brvtal-global-search-results" aria-live="polite"><div class="brvtal-global-search-empty">TYPE AT LEAST 2 CHARACTERS<br>Results are read-only shortcuts to the canonical editors.</div></div>
      </div>`;
    document.body.appendChild(overlay);
    overlay.querySelector('.brvtal-global-search-close')?.addEventListener('click', close);
    overlay.addEventListener('mousedown', event => { if (event.target === overlay) close(); });
    overlay.querySelector('input')?.addEventListener('input', event => queueSearch(event.target.value));
    return overlay;
  }

  function ensureTrigger() {
    const top = document.querySelector('.main .top');
    if (!top || top.querySelector('.brvtal-global-search-trigger')) return;
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'brvtal-global-search-trigger';
    trigger.setAttribute('aria-label','Open global DISCADMIN search');
    trigger.innerHTML = 'SEARCH <kbd>⌘K / CTRL K</kbd>';
    trigger.addEventListener('click', open);
    const status = top.querySelector('.status');
    if (status) top.insertBefore(trigger,status); else top.appendChild(trigger);
  }

  function open() {
    ensureStyle();
    const overlay = ensureOverlay();
    state.open = true;
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
    const input = overlay.querySelector('input');
    requestAnimationFrame(() => { input?.focus(); input?.select(); });
  }

  function close() {
    const overlay = document.getElementById('brvtal-global-search');
    if (!overlay) return;
    state.open = false;
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
    state.controller?.abort();
  }

  function resultHost() {
    return document.querySelector('#brvtal-global-search .brvtal-global-search-results');
  }

  function resultMarkup(item, group) {
    return `<button class="brvtal-global-search-item" type="button" data-search-module="${esc(item.module)}" data-search-id="${Number(item.id)}" data-search-title="${esc(item.title)}">
      <span><span class="brvtal-global-search-title">${esc(item.title || 'Untitled')}</span><span class="brvtal-global-search-sub">${esc(item.subtitle || ('#' + item.id))}</span></span>
      <span class="brvtal-global-search-status">${esc(item.status || group.label || item.type || '')}</span>
    </button>`;
  }

  function bindResultButtons(root) {
    root.querySelectorAll('[data-search-module]').forEach(button => {
      if (button.dataset.searchBound === '1') return;
      button.dataset.searchBound = '1';
      button.addEventListener('click', () => activate(button));
    });
  }

  function groupTotal(group) {
    const reported = Number(group?.total);
    return Number.isFinite(reported) && reported >= 0 ? reported : Number(group?.items?.length || 0);
  }

  function render(data) {
    const host = resultHost();
    if (!host) return;
    const groups = Array.isArray(data?.groups) ? data.groups : [];
    if (!groups.length) {
      host.innerHTML = `<div class="brvtal-global-search-empty">NO RESULTS FOR “${esc(data?.query || state.lastQuery)}”</div>`;
      return;
    }
    host.innerHTML = groups.map(group => {
      const items = Array.isArray(group.items) ? group.items : [];
      const total = groupTotal(group);
      const remaining = Math.max(0, total - items.length);
      return `<section class="brvtal-global-search-group" data-search-group="${esc(group.type)}" data-search-total="${total}">
        <div class="brvtal-global-search-group-title"><span>${esc(group.label || group.type || '')}</span><span data-search-count>${items.length} / ${total}</span></div>
        <div class="brvtal-global-search-items">${items.map(item => resultMarkup(item, group)).join('')}</div>
        ${group.has_more ? `<button class="brvtal-global-search-more" type="button" data-search-more="${esc(group.type)}">LOAD MORE · ${remaining} MORE</button>` : ''}
      </section>`;
    }).join('');
    bindResultButtons(host);
    host.querySelectorAll('[data-search-more]').forEach(button => button.addEventListener('click', () => loadMore(button)));
  }

  async function loadMore(button) {
    const section = button.closest('[data-search-group]');
    const type = button.dataset.searchMore || '';
    const query = state.lastQuery;
    if (!section || !type || query.length < 2) return;
    const itemsHost = section.querySelector('.brvtal-global-search-items');
    const offset = section.querySelectorAll('.brvtal-global-search-item').length;
    button.disabled = true;
    button.textContent = 'LOADING…';
    state.controller?.abort();
    const controller = new AbortController();
    state.controller = controller;
    try {
      const url = ENDPOINT + '?q=' + encodeURIComponent(query) + '&type=' + encodeURIComponent(type) + '&offset=' + offset;
      const response = await fetch(url, {credentials:'same-origin',cache:'no-store',signal:controller.signal});
      const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
      if (!response.ok || payload.ok === false) throw new Error(payload.error || ('HTTP_' + response.status));
      if (query !== state.lastQuery) return;
      const group = Array.isArray(payload?.data?.groups) ? payload.data.groups[0] : null;
      if (!group || !itemsHost) {
        button.remove();
        return;
      }
      itemsHost.insertAdjacentHTML('beforeend', (group.items || []).map(item => resultMarkup(item, group)).join(''));
      bindResultButtons(itemsHost);
      const loaded = section.querySelectorAll('.brvtal-global-search-item').length;
      const total = groupTotal(group);
      section.dataset.searchTotal = String(total);
      const count = section.querySelector('[data-search-count]');
      if (count) count.textContent = `${loaded} / ${total}`;
      const remaining = Math.max(0, total - loaded);
      if (group.has_more && remaining > 0) {
        button.disabled = false;
        button.textContent = `LOAD MORE · ${remaining} MORE`;
      } else {
        button.remove();
      }
    } catch (error) {
      if (error?.name === 'AbortError') return;
      button.disabled = false;
      button.textContent = 'LOAD MORE · RETRY';
      window.BRVTALFeedback?.error?.('Global search unavailable: ' + (error?.message || 'UNKNOWN_ERROR'),'global-search');
    }
  }

  async function activate(button) {
    const target = {
      module: button.dataset.searchModule || 'dashboard',
      id: Number(button.dataset.searchId || 0),
      title: button.dataset.searchTitle || '',
    };
    window.BRVTALGlobalSearchLast = target;
    close();
    if (typeof window.go === 'function') await window.go(target.module);
    window.dispatchEvent(new CustomEvent('brvtal:global-search-open',{detail:target}));
  }

  function queueSearch(value) {
    clearTimeout(state.timer);
    const query = String(value || '').trim();
    state.lastQuery = query;
    state.controller?.abort();
    const host = resultHost();
    if (query.length < 2) {
      if (host) host.innerHTML = '<div class="brvtal-global-search-empty">TYPE AT LEAST 2 CHARACTERS<br>Results are read-only shortcuts to the canonical editors.</div>';
      return;
    }
    if (host) host.innerHTML = '<div class="brvtal-global-search-loading">SEARCHING BRVTAL…</div>';
    state.timer = setTimeout(() => search(query), 160);
  }

  async function search(query) {
    state.controller?.abort();
    const controller = new AbortController();
    state.controller = controller;
    try {
      const response = await fetch(ENDPOINT + '?q=' + encodeURIComponent(query), {credentials:'same-origin',cache:'no-store',signal:controller.signal});
      const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
      if (!response.ok || payload.ok === false) throw new Error(payload.error || ('HTTP_' + response.status));
      if (query !== state.lastQuery) return;
      render(payload.data || {});
    } catch (error) {
      if (error?.name === 'AbortError') return;
      const host = resultHost();
      if (host) host.innerHTML = '<div class="brvtal-global-search-empty">GLOBAL SEARCH UNAVAILABLE</div>';
      window.BRVTALFeedback?.error?.('Global search unavailable: ' + (error?.message || 'UNKNOWN_ERROR'),'global-search');
    }
  }

  document.addEventListener('keydown', event => {
    if ((event.metaKey || event.ctrlKey) && String(event.key).toLowerCase() === 'k') {
      event.preventDefault();
      state.open ? close() : open();
      return;
    }
    if (event.key === 'Escape' && state.open) {
      event.preventDefault();
      close();
    }
  });

  const observer = new MutationObserver(() => ensureTrigger());
  observer.observe(document.documentElement,{childList:true,subtree:true});
  ensureStyle();
  ensureOverlay();
  ensureTrigger();
  window.BRVTALGlobalSearch = {open,close,search};
})();
