const BRVTALRelatedContent = (() => {
  'use strict';

  const state = { data: null, mode: 'artists', selectedType: null, selectedId: 0, root: null };
  const qs = (selector, root = document) => root.querySelector(selector);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const cleanUrl = value => {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, location.href);
      return /^https?:$/i.test(url.protocol) ? url.href : '';
    } catch (_) { return ''; }
  };
  const entityUrl = (type, slug) => {
    const allowed = ['artists', 'events', 'sets', 'releases'];
    const value = String(slug || '');
    return allowed.includes(type) && /^[a-z0-9-]{1,190}$/.test(value) ? `/${type}/${encodeURIComponent(value)}` : '';
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

  function archiveEventUrl(event) {
    const id = Number(event?.id) || 0;
    if (!id) return '';
    const archived = (Array.isArray(state.data?.archive?.events) ? state.data.archive.events : [])
      .find(item => Number(item?.id) === id);
    if (!archived) return '';

    const title = String(archived.title || event?.title || '').trim().slice(0, 120);
    const eventDate = archived.event_date ? new Date(String(archived.event_date).replace(' ', 'T')) : null;
    const derivedYear = eventDate && !Number.isNaN(eventDate.getTime()) ? eventDate.getFullYear() : 0;
    const numericYear = Number(archived.archive_year) || derivedYear;
    const year = Number.isInteger(numericYear) && numericYear >= 1900 && numericYear <= 9999 ? String(numericYear) : '';

    try {
      const url = new URL(location.href);
      url.searchParams.delete('network_type');
      url.searchParams.delete('network_id');
      if (year) url.searchParams.set('archive_year', year);
      else url.searchParams.delete('archive_year');
      if (title) url.searchParams.set('archive_q', title);
      else url.searchParams.delete('archive_q');
      url.hash = 'eventArchive';
      return `${url.pathname}${url.search}${url.hash}`;
    } catch (_) { return ''; }
  }

  function ensureStyles() {
    if (document.querySelector('link[data-related-content-style]')) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    const archiveScript = document.querySelector('script[src*="/js/archive.js"]');
    const version = archiveScript ? new URL(archiveScript.src).searchParams.get('v') : '';
    link.href = `/css/related-content.css${version ? `?v=${encodeURIComponent(version)}` : ''}`;
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
    if (type === 'sets') return (Number(rel.artist) > 0 ? 1 : 0) + (Number(rel.event) > 0 ? 1 : 0);
    if (type === 'releases') return rel.artists?.length || 0;
    return 0;
  }

  function activateTab(button) {
    if (!button) return;
    button.click();
    button.focus();
  }

  function handleTabKeydown(event) {
    const current = event.target.closest('[data-related-mode]');
    if (!current) return;
    const tabs = [...current.closest('[role="tablist"]')?.querySelectorAll('[data-related-mode]') || []];
    if (!tabs.length) return;
    const currentIndex = tabs.indexOf(current);
    if (currentIndex < 0) return;

    let targetIndex = -1;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') targetIndex = (currentIndex + 1) % tabs.length;
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') targetIndex = (currentIndex - 1 + tabs.length) % tabs.length;
    else if (event.key === 'Home') targetIndex = 0;
    else if (event.key === 'End') targetIndex = tabs.length - 1;
    else return;

    event.preventDefault();
    activateTab(tabs[targetIndex]);
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
            <button type="button" id="related-tab-artists" role="tab" aria-selected="true" aria-controls="related-network-panel" tabindex="0" data-related-mode="artists">ARTISTS</button>
            <button type="button" id="related-tab-events" role="tab" aria-selected="false" aria-controls="related-network-panel" tabindex="-1" data-related-mode="events">EVENTS</button>
            <button type="button" id="related-tab-sets" role="tab" aria-selected="false" aria-controls="related-network-panel" tabindex="-1" data-related-mode="sets">SETS</button>
            <button type="button" id="related-tab-releases" role="tab" aria-selected="false" aria-controls="related-network-panel" tabindex="-1" data-related-mode="releases">RELEASES</button>
          </div>
          <div class="related-network-list" id="related-network-panel" role="tabpanel" aria-labelledby="related-tab-artists" tabindex="0" data-related-list></div>
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
    section.addEventListener('keydown', handleTabKeydown);

    state.root = section;
    return section;
  }

  function entityLabel(type, entity) {
    if (type === 'artists') return String(entity?.name || 'UNKNOWN ARTIST');
    if (type === 'events') return String(entity?.title || 'UNTITLED EVENT');
    if (type === 'sets') return String(entity?.title || 'UNTITLED SET');
    if (type === 'releases') return String(entity?.title || 'UNTITLED RELEASE');
    return 'UNTITLED';
  }

  function entityMeta(type, entity, id) {
    const rel = relation(type, id) || {};
    if (type === 'artists') {
      return `${rel.events?.length || 0} EVENTS / ${rel.sets?.length || 0} SETS / ${rel.releases?.length || 0} RELEASES`;
    }
    if (type === 'events') {
      const status = String(entity?.status || 'published').toUpperCase().replaceAll('_', ' ');
      return `${status} / ${rel.artists?.length || 0} ARTISTS / ${rel.sets?.length || 0} SETS`;
    }
    if (type === 'sets') {
      const platform = String(entity?.platform || 'SET').toUpperCase();
      return `${platform} / ${Number(rel.artist) > 0 ? '1 ARTIST' : 'NO ARTIST'} / ${Number(rel.event) > 0 ? '1 EVENT' : 'NO EVENT'}`;
    }
    if (type === 'releases') {
      const releaseType = String(entity?.release_type || 'RELEASE').toUpperCase();
      const date = formatDate(entity?.release_date);
      return [releaseType, `${rel.artists?.length || 0} ARTISTS`, date].filter(Boolean).join(' / ');
    }
    return '';
  }

  function mapFor(type, maps) {
    return maps[type] instanceof Map ? maps[type] : new Map();
  }

  function syncTabs() {
    const root = mount();
    let activeTab = null;
    root.querySelectorAll('[data-related-mode]').forEach(button => {
      const active = button.dataset.relatedMode === state.mode;
      button.setAttribute('aria-selected', String(active));
      button.tabIndex = active ? 0 : -1;
      button.classList.toggle('active', active);
      if (active) activeTab = button;
    });
    const panel = qs('[data-related-list]', root);
    if (panel && activeTab?.id) panel.setAttribute('aria-labelledby', activeTab.id);
  }

  function renderList() {
    const root = mount();
    const list = qs('[data-related-list]', root);
    const maps = indexes();
    const map = mapFor(state.mode, maps);
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
    const selectType = `${type}s`;
    const navigable = ['artists','events','sets','releases'].includes(selectType) && Number(entity?.id) > 0;
    const href = entityUrl(selectType, entity?.slug) || (options.href ? cleanUrl(options.href) : '');
    const inner = `${image ? `<span class="related-item-image"><img src="${esc(image)}" alt="" loading="lazy" decoding="async"></span>` : '<span class="related-item-image related-item-placeholder"></span>'}
      <span class="related-item-copy"><strong>${esc(title || 'UNTITLED')}</strong><small class="mono">${esc(meta)}</small></span><span class="related-item-arrow">${navigable || href ? '↗' : '—'}</span>`;

    if (navigable) {
      return `<button type="button" class="related-item" data-related-select data-related-type="${selectType}" data-related-id="${Number(entity?.id)||0}">${inner}</button>`;
    }
    if (href) return `<a class="related-item" href="${esc(href)}"${href.startsWith('/') ? '' : ' target="_blank" rel="noopener"'}>${inner}</a>`;
    return `<div class="related-item">${inner}</div>`;
  }

  function group(title, items, renderItem) {
    const list = Array.isArray(items) ? items.filter(Boolean) : [];
    return `<section class="related-group"><div class="related-group-head"><h4>${esc(title)}</h4><span class="mono">${list.length}</span></div><div class="related-group-list">${list.length ? list.map(renderItem).join('') : '<div class="related-group-empty mono">NO PUBLIC RELATIONS</div>'}</div></section>`;
  }

  function releaseHref(release) {
    return release?.spotify_url || release?.soundcloud_url || release?.bandcamp_url || release?.youtube_url || release?.beatport_url || '';
  }

  function detailLinks(items) {
    const links = (Array.isArray(items) ? items : []).filter(item => item?.href);
    if (!links.length) return '';
    return `<div class="related-detail-links">${links.map(item => `<a class="related-detail-link mono" href="${esc(item.href)}"${item.external ? ' target="_blank" rel="noopener"' : ''}>${esc(item.label)} ↗</a>`).join('')}</div>`;
  }

  function renderArtistDetail(artist, rel, maps) {
    const eventItems = (rel.events || []).map(id => maps.events.get(Number(id))).filter(Boolean);
    const setItems = (rel.sets || []).map(id => maps.sets.get(Number(id))).filter(Boolean);
    const releaseItems = (rel.releases || []).map(id => maps.releases.get(Number(id))).filter(Boolean);
    const photo = imgUrl(artist.photo);
    const canonical = entityUrl('artists', artist.slug);
    return `<div class="related-detail-hero">
      <div class="related-detail-image">${photo ? `<img src="${esc(photo)}" alt="${esc(artist.name || 'Artist')}" loading="lazy" decoding="async">` : '<div class="related-detail-placeholder mono">BRVTAL / ARTIST</div>'}</div>
      <div class="related-detail-copy"><div class="mono">ARTIST / CONTENT PATH</div><h3>${esc(artist.name || 'UNKNOWN')}</h3><p>${esc(artist.bio || 'BRVTAL ARTIST')}</p>${detailLinks([{href:canonical,label:'VIEW ARTIST'}])}</div>
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
    const canonical = entityUrl('events', event.slug);
    const archive = archiveEventUrl(event);
    return `<div class="related-detail-hero">
      <div class="related-detail-image">${image ? `<img src="${esc(image)}" alt="${esc(event.title || 'Event')}" loading="lazy" decoding="async">` : '<div class="related-detail-placeholder mono">BRVTAL / EVENT</div>'}</div>
      <div class="related-detail-copy"><div class="mono">EVENT / ${esc(status)}</div><h3>${esc(event.title || 'UNTITLED EVENT')}</h3><p>${esc([formatDate(event.event_date), event.venue, event.city].filter(Boolean).join(' / '))}</p>${detailLinks([{href:canonical,label:'VIEW EVENT'},{href:archive,label:'VIEW IN ARCHIVE'}])}</div>
    </div>
    <div class="related-groups">
      ${group('ARTISTS', artistItems, artist => relationItem('artist', artist, { meta: artist.bio || 'BRVTAL ARTIST' }))}
      ${group('SETS', setItems, set => relationItem('set', set, { meta: [String(set.platform || '').toUpperCase(), set.artist_name].filter(Boolean).join(' / '), href: set.external_url }))}
    </div>`;
  }

  function renderSetDetail(set, rel, maps) {
    const artist = Number(rel.artist) > 0 ? maps.artists.get(Number(rel.artist)) : null;
    const event = Number(rel.event) > 0 ? maps.events.get(Number(rel.event)) : null;
    const image = imgUrl(set.cover_image);
    const platform = String(set.platform || 'SET').toUpperCase();
    const canonical = entityUrl('sets', set.slug);
    const external = cleanUrl(set.external_url);
    const description = set.description || [set.artist_name, set.event_title].filter(Boolean).join(' / ') || 'BRVTAL SET';
    return `<div class="related-detail-hero">
      <div class="related-detail-image">${image ? `<img src="${esc(image)}" alt="${esc(set.title || 'Set')}" loading="lazy" decoding="async">` : '<div class="related-detail-placeholder mono">BRVTAL / SET</div>'}</div>
      <div class="related-detail-copy"><div class="mono">SET / ${esc(platform)}</div><h3>${esc(set.title || 'UNTITLED SET')}</h3><p>${esc(description)}</p>${detailLinks([{href:canonical,label:'VIEW SET'},{href:external,label:'OPEN PLATFORM',external:true}])}</div>
    </div>
    <div class="related-groups">
      ${group('ARTIST', artist ? [artist] : [], item => relationItem('artist', item, { meta: item.bio || 'BRVTAL ARTIST' }))}
      ${group('EVENT', event ? [event] : [], item => relationItem('event', item, { meta: [formatDate(item.event_date), item.city].filter(Boolean).join(' / ') }))}
    </div>`;
  }

  function renderReleaseDetail(release, rel, maps) {
    const artistItems = (rel.artists || []).map(id => maps.artists.get(Number(id))).filter(Boolean);
    const image = imgUrl(release.artwork);
    const releaseType = String(release.release_type || 'RELEASE').toUpperCase();
    const canonical = entityUrl('releases', release.slug);
    const external = cleanUrl(releaseHref(release));
    const meta = [formatDate(release.release_date), release.catalog_number].filter(Boolean).join(' / ');
    return `<div class="related-detail-hero">
      <div class="related-detail-image">${image ? `<img src="${esc(image)}" alt="${esc(release.title || 'Release')}" loading="lazy" decoding="async">` : '<div class="related-detail-placeholder mono">BRVTAL / RELEASE</div>'}</div>
      <div class="related-detail-copy"><div class="mono">${esc(releaseType)} / CATALOG</div><h3>${esc(release.title || 'UNTITLED RELEASE')}</h3><p>${esc(release.description || meta || 'BRVTAL RELEASE')}</p>${detailLinks([{href:canonical,label:'VIEW RELEASE'},{href:external,label:'LISTEN',external:true}])}</div>
    </div>
    <div class="related-groups">
      ${group('ARTISTS', artistItems, artist => relationItem('artist', artist, { meta: artist.bio || 'BRVTAL ARTIST' }))}
    </div>`;
  }

  function select(type, id) {
    const maps = indexes();
    const map = mapFor(type, maps);
    const entity = map.get(Number(id));
    if (!entity) return;

    state.mode = type;
    state.selectedType = type;
    state.selectedId = Number(id);
    syncTabs();

    const detail = qs('[data-related-detail]', mount());
    const defaults = {
      artists: {events:[],sets:[],releases:[]},
      events: {artists:[],sets:[]},
      sets: {artist:null,event:null},
      releases: {artists:[]},
    };
    const rel = relation(type, id) || defaults[type] || {};
    const renderers = {
      artists: renderArtistDetail,
      events: renderEventDetail,
      sets: renderSetDetail,
      releases: renderReleaseDetail,
    };
    detail.innerHTML = renderers[type]?.(entity, rel, maps) || '';
    renderList();
    qs('[data-related-detail]', state.root)?.scrollTo?.({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
  }

  function renderSummary() {
    const summary = qs('[data-related-summary]', mount());
    const counts = state.data?.relations?.counts || {};
    const total = Object.values(counts).reduce((sum, value) => sum + (Number(value) || 0), 0);
    summary.textContent = `${total} PUBLIC LINKS / ${Number(counts.event_artist || 0)} EVENT↔ARTIST / ${Number(counts.event_set || 0)} EVENT↔SET / ${Number(counts.artist_set || 0)} ARTIST↔SET / ${Number(counts.artist_release || 0)} ARTIST↔RELEASE`;
  }

  function render() {
    const root = mount();
    const graph = state.data?.relations;
    if (!graph || typeof graph !== 'object') {
      root.hidden = true;
      return;
    }
    root.hidden = false;
    syncTabs();
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