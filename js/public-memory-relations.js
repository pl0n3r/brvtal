(() => {
  'use strict';

  let data = null;
  let timer = 0;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const safePath = (type, slug) => {
    const allowed = ['events','artists','sets','releases'];
    const route = String(type || '');
    const value = String(slug || '');
    return allowed.includes(route) && /^[a-z0-9-]{1,190}$/.test(value) ? `/${route}/${encodeURIComponent(value)}` : '';
  };

  function mediaMap() {
    const map = new Map();
    (Array.isArray(data?.media) ? data.media : []).forEach(item => {
      const id = Number(item?.id || 0);
      if (id > 0) map.set(id,item);
    });
    return map;
  }

  function relationLabel(relation) {
    const type = String(relation?.related_type || 'memory').toUpperCase();
    const label = String(relation?.label || '').trim();
    return label ? `${type} / ${label}` : type;
  }

  function decorateMediaGrid() {
    if (!data) return;
    const memories = mediaMap();
    document.querySelectorAll('[data-public-media-open]').forEach(button => {
      const id = Number(button.dataset.publicMediaOpen || 0);
      const item = memories.get(id);
      const figure = button.closest('[data-public-media-item]');
      if (!item || !figure) return;
      const relations = Array.isArray(item.relations) ? item.relations : [];
      const signature = relations.map(row => `${row.related_type}:${row.related_id}:${row.slug}`).join('|');
      if (figure.dataset.memoryRelationsSignature === signature) return;
      figure.dataset.memoryRelationsSignature = signature;
      figure.querySelector('.public-memory-context')?.remove();
      const caption = figure.querySelector('figcaption');
      if (!caption) return;

      const context = document.createElement('div');
      context.className = 'public-memory-context';
      context.setAttribute('aria-label','Related cultural context');
      context.innerHTML = relations.length
        ? relations.map(relation => {
            const href = safePath(relation.route_type, relation.slug);
            return href ? `<a href="${esc(href)}">${esc(relationLabel(relation))}</a>` : '';
          }).join('')
        : '<span class="public-memory-context-empty">UNATTACHED MEMORY</span>';
      caption.appendChild(context);

      const labels = relations.map(relation => relation.label || '').filter(Boolean).join(' ');
      if (labels) {
        const current = String(figure.dataset.publicMediaSearchValue || '');
        figure.dataset.publicMediaSearchValue = `${current} ${labels.toLowerCase()}`.trim();
      }
    });
  }

  function memoryItem(item) {
    const image = String(item?.type || '') === 'image' ? String(item?.file_path || '') : '';
    const visual = image
      ? `<span class="related-memory-visual"><img src="${esc(image)}" alt="" loading="lazy" decoding="async"></span>`
      : `<span class="related-memory-visual"><span>${esc(String(item?.type || 'MEDIA').toUpperCase())}</span></span>`;
    return `<a class="related-item" href="/#media">${visual}<span class="related-item-copy"><strong>${esc(item?.title || 'Memory')}</strong><small class="mono">MEMORY / ${esc(String(item?.type || 'MEDIA').toUpperCase())}</small></span><span class="related-item-arrow">↗</span></a>`;
  }

  function decorateConnectedDetail() {
    if (!data) return;
    const root = document.getElementById('network');
    if (!root || root.hidden) return;
    const active = root.querySelector('.related-network-entity.active[data-related-type][data-related-id]');
    const groups = root.querySelector('[data-related-detail] .related-groups');
    if (!active || !groups) return;
    const type = String(active.dataset.relatedType || '');
    const id = Number(active.dataset.relatedId || 0);
    if (!['artists','events','sets','releases'].includes(type) || id < 1) return;
    const ids = data?.relations?.[type]?.[String(id)]?.memories;
    const memoryIds = Array.isArray(ids) ? ids.map(Number).filter(value => value > 0) : [];
    const signature = `${type}:${id}:${memoryIds.join(',')}`;
    const existing = groups.querySelector('[data-related-memories]');
    if (existing?.dataset.signature === signature) return;
    existing?.remove();
    if (!memoryIds.length) return;
    const memories = mediaMap();
    const items = memoryIds.map(memoryId => memories.get(memoryId)).filter(Boolean);
    if (!items.length) return;
    const section = document.createElement('section');
    section.className = 'related-group';
    section.dataset.relatedMemories = '1';
    section.dataset.signature = signature;
    section.innerHTML = `<div class="related-group-head"><h4>MEMORIES</h4><span class="mono">${items.length}</span></div><div class="related-group-list">${items.map(memoryItem).join('')}</div>`;
    groups.appendChild(section);
  }

  function decorate() {
    decorateMediaGrid();
    decorateConnectedDetail();
  }

  function schedule() {
    window.clearTimeout(timer);
    timer = window.setTimeout(decorate, 40);
  }

  window.addEventListener('brvtal:public-data', event => {
    if (!event.detail || typeof event.detail !== 'object') return;
    data = event.detail;
    schedule();
  }, {passive:true});

  const observer = new MutationObserver(schedule);
  observer.observe(document.documentElement, {childList:true,subtree:true});
  window.BRVTALPublicMemoryRelations = {decorate,getData:() => data};
})();
