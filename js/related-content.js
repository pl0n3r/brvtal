const BRVTALRelatedContent = (() => {
  'use strict';

  const state = { data: null, mode: 'artists', selectedType: null, selectedId: 0, root: null };
  const qs = (selector, root = document) => root.querySelector(selector);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const cleanUrl = value => {
    try {
      const url = new URL(String(value || ''), location.href);
      return /^https?:$/i.test(url.protocol) ? url.href : '';
    } catch (_) { return ''; }
  };
  const imgUrl = value => {
    const raw = String(value || '').trim();
    if (!raw || /^(?:https?:)?\/\//i.test(raw) || raw.startsWith('/')) return raw;
    return raw.replace(/^\.?\//, '');
  };
  const formatDate = value => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat(document.documentElement.lang || 'en', { day:'2-digit', month:'2-digit', year:'numeric' }).format(date);
  };

  function ensureStyles() {
    if (document.querySelector('link[data-related-content-style]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/css/related-content.css';
    link.dataset.relatedContentStyle = '1';
    document.head.appendChild(link);
  }

  function byId(items) {
    const map = new Map();
    (Array.isArray(items) ? items : []).forEach(item => {
      const id = Number(item?.id) || 0;
      if (id > 0) map.set(id, item);
    });
    return map;
  }

  function indexes() {
    const data = state.data || {};
    const events = [...(Array.isArray(data.events) ? data.events : []), ...(Array.isArray(data.archive?.events) ? data.archive.events : [])];
    return {
      events: byId(events),
      artists: byId(data.artists),
      sets: byId(data.sets),
      releases: byId(data.releases),
    };
  }

  function graphBucket(type) {
    const bucket = state.data?.relations?.[type];
    return bucket && typeof bucket === 'object' ? bucket : {};
  }

  function relation(type, id) {
    return graphBucket(type)[String(Number(id) || 0)] || null;
  }

  function degree(type, id) {
    const rel = relation(type, id) || {};
    if (type === 'artists') return (rel.events?.length || 0) + (rel.sets?.length || 0) + (rel.releases?.length || 0);
    if (type === 'events') return (rel.artists?.length || 0) + (rel.sets?.length || 0);
    return 0;
  }

  function mount() {
    if (state.root?.isConnected) return state.root;
    ensureStyles();
    const host = qs('main') || document.body;
    const before = qs('#media', host);
    const section = document.createElement('section');
    section.id = 'network';
    section.className = 'related-network';
    section.hidden = true;
    section.innerHTML = `
      <div class="related-network-noise" aria-hidden="true"></div>
      <div class="related-network-head">
        <div><div class="mono related-network-kicker">RELATED CONTENT / PUBLIC GRAPH</div><h2>CONNECTED</h2></div>
        <div class="mono related-network-summary" data-related-summary></div>
      </div>
      <div class="related-network-layout">
        <aside class="related-network-browser" aria-label="Explore related BRVTAL content">
          <div class="related-network-tabs" role="tablist" aria-label="Content type">
            <button type="button" role="tab" aria-selected="true" data-related-mode="artists">ARTISTS</button>
            <button type="button" role="tab" aria-selected="false" data-related-mode="events">EVENTS</button>
          </div>
          <div class="related-network-list" data-related-list></div>
        </aside>
        <div class="related-network-detail" data-related-detail aria-live="polite"></div>
      </div>`;
    if (before) host.insertBefore(section, before);
    else host.appendChild(section);

    section.addEventListener('click', event => {
      const modeButton = event.target.closest('[data-related-mode]');
      if (modeButton) {
        state.mode = modeButton.dataset.relatedMode || 'artists';
        state.selectedType = null;
        state.selectedId = 0;
        render();
        return;
      }
      const selectButton = event.target.closest('[data-related-select]');
      if (selectButton) {
        select(selectButton.dataset.relatedType || state.mode, Number(selectButton.dataset.relatedId) || 0);
      }
    });

    state.root = section;
    return section;
  }

  function entityLabel(type, entity) {
    if (type === 'artists') return String(entity?.name || 'UNKNOWN ARTIST');
    return String(entity?.title || 'UNTITLED EVENT');
  }

  function entityMeta(type, entity, id) {
    if (type === 'artists') {
      const rel = relation(type, id) || {};
      return `${rel.events?.length || 0} EVENTS / ${rel.sets?.length || 0} SETS / ${rel.releases?.length || 0} RELEASES`;
    }
    const rel = relation(type, id) || {};
    const status = String(entity?.status || 'published').toUpperCase().replaceAll('_', ' ');
    return `${status} / ${rel.artists?.length || 0} ARTISTS / ${rel.sets?.length || 0} SETS`;
  }

  function renderList() {
    const root = mount();
    const list = qs('[data-related-list]', root);
    const maps = indexes();
    const map = state.mode === 'events' ? maps.events : maps.artists;
    const entries = [...map.entries()].sort((a, b) => {
      const degreeDiff = degree(state.mode, b[0]) - degree(state.mode, a[0]);
      return degreeDiff || entityLabel(state.mode, a[1]).localeCompare(entityLabel(state.mode, b[1]));
    });

    list.innerHTML = entries.map(([id, entity], index) => `
      <button type="button" class="related-network-entity ${state.selectedType===state.mode && state.selectedId===id?'active':''}" data-related-select data-related-type="${state.mode}" data-related-id="${id}">
        <span class="mono">${String(index + 1).padStart(2, '0')}</span>
        <strong>${esc(entityLabel(state.mode, entity))}</strong>
        <small class="mono">${esc(entityMeta(state.mode, entity, id))}</small>
      </button>`).join('');

    if (!entries.length) list.innerHTML = '<div class="related-network-empty mono">NO PUBLIC CONTENT IN THIS LAYER</div>';
    return entries;
  }

  function relationItem(type, entity, options = {}) {
    const title = type === 'artist' ? entity?.name : entity?.title;
    const image = imgUrl(entity?.photo || entity?.cover_image || entity?.artwork || '');
    const meta = options.meta || '';
    const navigable = type === 'artist' || type === 'event';
    const selectType = type === 'artist' ? 'artists' : type === 'event' ? 'events' : '';
    const href = options.href ? cleanUrl(options.href) : '';
    const inner = `${image ? `<span class="related-item-image"><img src="${esc(image)}" alt="" loading="lazy"></span>` : '<span class="related-item-image related-item-placeholder"></span>'}
      <span class="related-item-copy"><strong>${esc(title || 'UNTITLED')}</strong><small class="mono">${esc(meta)}</small></span><span class="related-item-arrow">${navigable || href ? '↗' : '—'}</span>`;

    if (navigable) {
      return `<button type="button" class="related-item" data-related-select data-related-type="${selectType}" data-related-id="${Number(entity?.id)||0}">${inner}</button>`;
    }
    if (href) return `<a class="related-item" href="${esc(href)}" target="_blank" rel="noopener">${inner}</a>`;
    return `<div class="related-item">${inner}</div>`;
  }

  function group(title, items, renderItem) {
    const list = Array.isArray(items) ? items.filter(Boolean) : [];
    return `<section class="related-group"><div class="related-group-head"><h4>${esc(title)}</h4><span class="mono">${list.length}</span></div><div class="related-group-list">${list.length ? list.map(renderItem).join('') : '<div class="related-group-empty mono">NO PUBLIC RELATIONS</div>'}</div></section>`;
  }

  function releaseHref(release) {
    return release?.spotify_url || release?.soundcloud_url || release?.bandcamp_url || release?.youtube_url || release?.beatport_url || '';
  }

  function renderArtistDetail(artist, rel, maps) {
    const eventItems = (rel.events || []).map(id => maps.events.get(Number(id))).filter(Boolean);
    const setItems = (rel.sets || []).map(id => maps.sets.get(Number(id))).filter(Boolean);
    const releaseItems = (rel.releases || []).map(id => maps.releases.get(Number(id))).filter(Boolean);
    const photo = imgUrl(artist.photo);
    return `<div class="related-detail-hero">
      <div class="related-detail-image">${photo ? `<img src="${esc(photo)}" alt="${esc(artist.name || 'Artist')}" loading="lazy">` : '<div class="related-detail-placeholder mono">BRVTAL / ARTIST</div>'}</div>
      <div class="related-detail-copy"><div class="mono">ARTIST / CONTENT PATH</div><h3>${esc(artist.name || 'UNKNOWN')}</h3><p>${esc(artist.bio || 'BRVTAL ARTIST')}</p></div>
    </div>
    <div class="related-groups">
      ${group('EVENTS', eventItems, event => relationItem('event', event, { meta: [formatDate(event.event_date), event.city].filter(Boolean).join(' / ') }))}
      ${group('SETS', setItems, set => relationItem('set', set, { meta: [String(set.platform || '').toUpperCase(), set.event_title].filter(Boolean).join(' / '), href: set.external_url }))}
      ${group('RELEASES', releaseItems, release => relationItem('release', release, { meta: [String(release.release_type || '').toUpperCase(), formatDate(release.release_date)].filter(Boolean).join(' / '), href: releaseHref(release) }))}
    </div>`;
  }

  function renderEventDetail(event, rel, maps) {
    const artistItems = (rel.artists || []).map(id => maps.artists.get(Number(id))).filter(Boolean);
    const setItems = (rel.sets || []).map(id => maps.sets.get(Number(id))).filter(Boolean);
    const image = imgUrl(event.cover_image);
    const status = String(event.status || 'published').toUpperCase().replaceAll('_', ' ');
    return `<div class="related-detail-hero">
      <div class="related-detail-image">${image ? `<img src="${esc(image)}" alt="${esc(event.title || 'Event')}" loading="lazy">` : '<div class="related-detail-placeholder mono">BRVTAL / EVENT</div>'}</div>
      <div class="related-detail-copy"><div class="mono">EVENT / ${esc(status)}</div><h3>${esc(event.title || 'UNTITLED EVENT')}</h3><p>${esc([formatDate(event.event_date), event.venue, event.city].filter(Boolean).join(' / '))}</p></div>
    </div>
    <div class="related-groups">
      ${group('ARTISTS', artistItems, artist => relationItem('artist', artist, { meta: artist.bio || 'BRVTAL ARTIST' }))}
      ${group('SETS', setItems, set => relationItem('set', set, { meta: [String(set.platform || '').toUpperCase(), set.artist_name].filter(Boolean).join(' / '), href: set.external_url }))}
    </div>`;
  }

  function select(type, id) {
    const maps = indexes();
    const map = type === 'events' ? maps.events : maps.artists;
    const entity = map.get(Number(id));
    if (!entity) return;
    state.mode = type;
    state.selectedType = type;
    state.selectedId = Number(id);
    const detail = qs('[data-related-detail]', mount());
    const rel = relation(type, id) || (type === 'artists' ? {events:[],sets:[],releases:[]} : {artists:[],sets:[]});
    detail.innerHTML = type === 'artists' ? renderArtistDetail(entity, rel, maps) : renderEventDetail(entity, rel, maps);
    renderList();
    qs('[data-related-detail]', state.root)?.scrollTo?.({ top: 0, behavior: 'smooth' });
  }

  function renderSummary() {
    const summary = qs('[data-related-summary]', mount());
    const counts = state.data?.relations?.counts || {};
    const total = Object.values(counts).reduce((sum, value) => sum + (Number(value) || 0), 0);
    summary.textContent = `${total} PUBLIC LINKS / ${Number(counts.event_artist || 0)} EVENT↔ARTIST / ${Number(counts.artist_release || 0)} ARTIST↔RELEASE`;
  }

  function render() {
    const root = mount();
    const graph = state.data?.relations;
    if (!graph || typeof graph !== 'object') {
      root.hidden = true;
      return;
    }
    root.hidden = false;
    root.querySelectorAll('[data-related-mode]').forEach(button => {
      const active = button.dataset.relatedMode === state.mode;
      button.setAttribute('aria-selected', String(active));
      button.classList.toggle('active', active);
    });
    renderSummary();
    const entries = renderList();
    if (!state.selectedType || state.selectedType !== state.mode || !state.selectedId) {
      const first = entries[0];
      if (first) select(state.mode, first[0]);
      else qs('[data-related-detail]', root).innerHTML = '<div class="related-network-empty mono">NO CONNECTED CONTENT YET</div>';
    }
    if (window.ScrollTrigger) window.ScrollTrigger.refresh();
  }

  function init(data) {
    if (!data || typeof data !== 'object') return { ok:false, reason:'NO_DATA' };
    state.data = data;
    render();
    document.documentElement.dataset.related = 'live';
    return { ok:true };
  }

  window.addEventListener('brvtal:public-data', event => init(event.detail), { passive:true });
  const existing = window.BRVTALPublicArchive?.getData?.();
  if (existing) init(existing);

  return { init, render, select, getData: () => state.data };
})();

window.BRVTALRelatedContent = BRVTALRelatedContent;
