(() => {
  'use strict';

  const state = {items:[],type:'all',query:'',lastFocus:null,viewerItems:[],viewerIndex:0};
  const qs = (selector, root=document) => root.querySelector(selector);
  const searchText = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const fileUrl = value => {
    const raw = String(value || '').trim();
    if (!raw || /^(?:javascript|data):/i.test(raw)) return '';
    if (/^https?:\/\//i.test(raw) || raw.startsWith('/')) return raw;
    return raw.replace(/^\.?\//,'');
  };
  const mediaType = item => {
    const declared = String(item?.type || '').toLowerCase();
    if (['image','video','audio'].includes(declared)) return declared;
    const mime = String(item?.mime_type || '').toLowerCase().split('/')[0];
    return ['image','video','audio'].includes(mime) ? mime : 'other';
  };

  function closeViewer() {
    const viewer = document.getElementById('public-media-viewer');
    if (!viewer) return;
    viewer.remove();
    state.lastFocus?.focus?.();
    state.lastFocus = null;
    state.viewerItems = [];
  }

  function showViewerItem() {
    const item = state.viewerItems[state.viewerIndex];
    const viewer = document.getElementById('public-media-viewer');
    if (!item || !viewer) return;
    const title = item.title || item.alt_text || 'BRVTAL media';
    const dialog = qs('[role="dialog"]', viewer);
    dialog.setAttribute('aria-label', title);
    const image = qs('img', viewer);
    image.src = fileUrl(item.file_path);
    image.alt = item.alt_text || title;
    qs('[data-public-media-title]', viewer).textContent = title;
    qs('[data-public-media-position]', viewer).textContent = `IMAGE ${state.viewerIndex + 1} / ${state.viewerItems.length}`;
  }

  function moveViewer(direction) {
    if (state.viewerItems.length < 2) return;
    state.viewerIndex = (state.viewerIndex + direction + state.viewerItems.length) % state.viewerItems.length;
    showViewerItem();
  }

  function openViewer(item, trigger) {
    if (!fileUrl(item.file_path) || mediaType(item) !== 'image') return;
    document.getElementById('public-media-viewer')?.remove();
    state.lastFocus = trigger || null;
    const visibleIds = new Set([...document.querySelectorAll('[data-public-media-item]:not([hidden]) [data-public-media-open]')].map(button => Number(button.dataset.publicMediaOpen)));
    state.viewerItems = state.items.filter(entry => mediaType(entry) === 'image' && fileUrl(entry.file_path) && visibleIds.has(Number(entry.id)));
    if (!state.viewerItems.some(entry => Number(entry.id) === Number(item.id))) state.viewerItems = [item];
    state.viewerIndex = state.viewerItems.findIndex(entry => Number(entry.id) === Number(item.id));
    const viewer = document.createElement('div');
    viewer.id = 'public-media-viewer';
    viewer.className = 'public-media-viewer';
    viewer.innerHTML = '<div class="public-media-viewer-card" role="dialog" aria-modal="true" aria-label="BRVTAL media"><button type="button" class="public-media-close mono" data-public-media-close>CLOSE ×</button><img alt=""><div class="public-media-viewer-meta"><strong data-public-media-title></strong><span class="mono" data-public-media-position></span></div><div class="public-media-viewer-controls"><button type="button" class="mono" data-public-media-prev aria-label="Previous image">← PREVIOUS</button><button type="button" class="mono" data-public-media-next aria-label="Next image">NEXT →</button></div></div>';
    document.body.appendChild(viewer);
    showViewerItem();
    viewer.querySelectorAll('[data-public-media-prev],[data-public-media-next]').forEach(button => { button.hidden = state.viewerItems.length < 2; });
    viewer.querySelector('[data-public-media-close]').addEventListener('click', closeViewer);
    viewer.querySelector('[data-public-media-prev]').addEventListener('click', () => moveViewer(-1));
    viewer.querySelector('[data-public-media-next]').addEventListener('click', () => moveViewer(1));
    viewer.addEventListener('click', event => { if (event.target === viewer) closeViewer(); });
    viewer.querySelector('[data-public-media-close]').focus();
  }

  function itemHtml(item) {
    const type = mediaType(item);
    const url = fileUrl(item.file_path);
    const title = item.title || item.alt_text || 'BRVTAL media';
    if (!url || type === 'other') return '';
    const visual = type === 'image'
      ? `<button type="button" class="public-media-open" data-public-media-open="${Number(item.id)||0}" aria-label="Open ${esc(title)}"><img src="${esc(url)}" alt="${esc(item.alt_text || title)}" loading="lazy" decoding="async"></button>`
      : type === 'video'
        ? `<video src="${esc(url)}" controls preload="metadata" aria-label="${esc(title)}"></video>`
        : `<div class="public-media-audio"><span class="mono">AUDIO SIGNAL</span><audio src="${esc(url)}" controls preload="none" aria-label="${esc(title)}"></audio></div>`;
    return `<figure class="public-media-item" data-public-media-item data-public-media-type-value="${type}" data-public-media-search-value="${esc(searchText(`${title} ${item.alt_text || ''}`))}">${visual}<figcaption><strong>${esc(title)}</strong><span class="mono">${esc(type.toUpperCase())}</span></figcaption></figure>`;
  }

  function applyFilters() {
    let visible = 0;
    document.querySelectorAll('[data-public-media-item]').forEach(item => {
      const typeMatch = state.type === 'all' || item.dataset.publicMediaTypeValue === state.type;
      const queryMatch = !state.query || String(item.dataset.publicMediaSearchValue || '').includes(state.query);
      item.hidden = !(typeMatch && queryMatch);
      if (!item.hidden) visible++;
    });
    document.querySelectorAll('[data-public-media-type]').forEach(button => button.classList.toggle('active', button.dataset.publicMediaType === state.type));
    const count = qs('[data-public-media-count]');
    if (count) count.textContent = `${visible} ${visible===1?'MEMORY':'MEMORIES'} FOUND`;
    const empty = qs('[data-public-media-empty]');
    if (empty) empty.hidden = visible !== 0;
  }

  function resetFilters() {
    state.type = 'all';
    state.query = '';
    const search = qs('[data-public-media-search]');
    if (search) search.value = '';
    applyFilters();
    search?.focus();
  }

  function bindTools() {
    document.querySelectorAll('[data-public-media-type]').forEach(button => {
      if (button.dataset.bound) return;
      button.dataset.bound = '1';
      button.addEventListener('click', () => { state.type = button.dataset.publicMediaType || 'all'; applyFilters(); });
    });
    const search = qs('[data-public-media-search]');
    if (search && !search.dataset.bound) {
      search.dataset.bound = '1';
      search.addEventListener('input', () => { state.query = searchText(search.value.trim()); applyFilters(); });
    }
    const reset = qs('[data-public-media-reset]');
    if (reset && !reset.dataset.bound) {
      reset.dataset.bound = '1';
      reset.addEventListener('click', resetFilters);
    }
  }

  function render(items) {
    const grid = qs('.media-grid');
    if (!grid || !Array.isArray(items)) return false;
    state.items = items.filter(item => fileUrl(item?.file_path) && mediaType(item) !== 'other');
    if (!state.items.length) return false;
    grid.classList.add('public-media-grid');
    grid.innerHTML = state.items.map(itemHtml).join('');
    grid.querySelectorAll('[data-public-media-open]').forEach(button => button.addEventListener('click', () => {
      const item = state.items.find(entry => Number(entry.id) === Number(button.dataset.publicMediaOpen));
      if (item) openViewer(item, button);
    }));
    bindTools();
    applyFilters();
    return true;
  }

  document.addEventListener('keydown', event => {
    const viewer = document.getElementById('public-media-viewer');
    if (!viewer) return;
    if (event.key === 'Escape') { event.preventDefault(); closeViewer(); return; }
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); moveViewer(event.key === 'ArrowLeft' ? -1 : 1); return; }
    if (event.key !== 'Tab') return;
    const controls = [...viewer.querySelectorAll('button:not([hidden])')];
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  window.addEventListener('brvtal:public-data', event => render(event.detail?.media || []));
  window.BRVTALPublicMedia = {render,applyFilters,openViewer,closeViewer};
})();
