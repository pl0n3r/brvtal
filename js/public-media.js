(() => {
  'use strict';

  const state = {items:[],type:'all',query:'',lastFocus:null};
  const qs = (selector, root=document) => root.querySelector(selector);
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
    document.getElementById('public-media-viewer')?.remove();
    state.lastFocus?.focus?.();
  }

  function openViewer(item, trigger) {
    const url = fileUrl(item.file_path);
    if (!url || mediaType(item) !== 'image') return;
    closeViewer();
    state.lastFocus = trigger || null;
    const title = item.title || item.alt_text || 'BRVTAL media';
    const viewer = document.createElement('div');
    viewer.id = 'public-media-viewer';
    viewer.className = 'public-media-viewer';
    viewer.innerHTML = `<div class="public-media-viewer-card" role="dialog" aria-modal="true" aria-label="${esc(title)}"><button type="button" class="public-media-close mono" data-public-media-close>CLOSE ×</button><img src="${esc(url)}" alt="${esc(item.alt_text || title)}"><div><strong>${esc(title)}</strong><span class="mono">IMAGE / BRVTAL ARCHIVE</span></div></div>`;
    document.body.appendChild(viewer);
    viewer.querySelector('[data-public-media-close]').addEventListener('click', closeViewer);
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
    return `<figure class="public-media-item" data-public-media-item data-public-media-type-value="${type}" data-public-media-search-value="${esc(`${title} ${item.alt_text || ''}`.toLowerCase())}">${visual}<figcaption><strong>${esc(title)}</strong><span class="mono">${esc(type.toUpperCase())}</span></figcaption></figure>`;
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
      search.addEventListener('input', () => { state.query = search.value.trim().toLowerCase(); applyFilters(); });
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

  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeViewer(); });
  window.addEventListener('brvtal:public-data', event => render(event.detail?.media || []));
  window.BRVTALPublicMedia = {render,applyFilters,openViewer,closeViewer};
})();
