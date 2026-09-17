(() => {
  'use strict';

  const API_CANDIDATES = ['/api/public.php', '/api/public', '/api/public/'];
  const state = { data:null, observer:null, timer:null, archiveYear:'all', archiveRelation:'all', archiveQuery:'' };
  const qs = (selector, root=document) => root.querySelector(selector);
  const searchText = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

  function create(tag, className = '', text = null) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== null) element.textContent = String(text);
    return element;
  }

  const cleanUrl = value => {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, `${location.origin}/`);
      return /^https?:$/i.test(url.protocol) ? url.href : '';
    } catch (_) { return ''; }
  };

  const imgUrl = value => {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, `${location.origin}/`);
      if (!/^https?:$/i.test(url.protocol)) return '';
      return url.origin === location.origin ? `${url.pathname}${url.search}${url.hash}` : url.href;
    } catch (_) { return ''; }
  };

  const formatDate = value => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ','T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat(document.documentElement.lang || 'en',{day:'2-digit',month:'2-digit',year:'numeric'}).format(date);
  };

  const connectedEventUrl = id => {
    const eventId = Number(id) || 0;
    if (!eventId) return '';
    try {
      const url = new URL(location.pathname || '/', location.origin);
      url.searchParams.set('network_type', 'events');
      url.searchParams.set('network_id', String(eventId));
      url.hash = 'network';
      return `${url.pathname}${url.search}${url.hash}`;
    } catch (_) { return ''; }
  };

  function activeStatusLabel(status, index) {
    const labels = {
      upcoming:'UPCOMING',
      tickets_available:'TICKETS AVAILABLE',
      last_tickets:'LAST TICKETS',
      sold_out:'SOLD OUT',
      published:index === 0 ? 'NEXT EXPERIENCE' : 'UPCOMING',
    };
    return labels[String(status || '').toLowerCase()] || 'UPCOMING';
  }

  function activeSignature(items) {
    return (items || []).map((event,index) => `${Number(event.id)||0}:${activeStatusLabel(event.status,index)}`).join('|');
  }

  function currentActiveSignature(track) {
    return [...track.querySelectorAll('[data-public-event-id]')].map(card => `${Number(card.dataset.publicEventId)||0}:${card.querySelector('.event-status')?.textContent?.trim()||''}`).join('|');
  }

  function activeCard(event, index) {
    const title = String(event.title || 'UNTITLED EVENT');
    const image = imgUrl(event.cover_image);
    const date = formatDate(event.event_date);
    const city = String(event.city || '');
    const venue = String(event.venue || '');
    const description = String(event.description || 'BRVTAL');
    const status = String(event.status || 'published').toLowerCase();
    const ticket = status === 'sold_out' ? '' : cleanUrl(event.ticket_url);

    const article = create('article', `event-card${index === 0 ? ' event-active' : ''}`);
    article.dataset.publicEventId = String(Number(event.id) || 0);

    const imageHost = create('div', 'event-img');
    if (image) {
      const img = create('img');
      img.src = image;
      img.alt = title;
      img.loading = index ? 'lazy' : 'eager';
      imageHost.appendChild(img);
    }

    const info = create('div', 'event-info');
    info.appendChild(create('span', 'mono', [date,city].filter(Boolean).join(' / ')));
    info.appendChild(create('h3', '', title));
    info.appendChild(create('p', '', [venue,description].filter(Boolean).join(' / ')));
    info.appendChild(create('span', 'event-status', activeStatusLabel(status,index)));
    if (ticket) {
      const link = create('a', 'event-ticket mono', 'TICKETS ↗');
      link.href = ticket;
      link.target = '_blank';
      link.rel = 'noopener';
      info.appendChild(link);
    }

    article.append(imageHost, info);
    return article;
  }

  function renderActive(items) {
    const track = qs('.events-track');
    if (!track) return;
    const events = Array.isArray(items) ? items : [];
    const expected = activeSignature(events);
    if (currentActiveSignature(track) === expected) return;

    if (!events.length) {
      track.replaceChildren(create('div', 'events-empty mono', 'NO UPCOMING EVENTS / THE ARCHIVE REMAINS ACTIVE'));
      return;
    }

    track.replaceChildren(...events.map((event, index) => activeCard(event, index)));
  }

  function archiveCard(event) {
    const title = String(event.title || 'UNTITLED EVENT');
    const image = imgUrl(event.cover_image);
    const date = formatDate(event.event_date);
    const city = String(event.city || '');
    const venue = String(event.venue || '');
    const year = Number(event.archive_year) || (event.event_date ? new Date(String(event.event_date).replace(' ','T')).getFullYear() : 0);
    const lineup = Array.isArray(event.lineup) ? event.lineup : [];
    const sets = Array.isArray(event.related_sets) ? event.related_sets : [];
    const names = lineup.map(item => String(item?.name || '')).filter(Boolean);
    const relationParts = [];
    if (lineup.length) relationParts.push(`${lineup.length} ARTIST${lineup.length===1?'':'S'}`);
    if (sets.length) relationParts.push(`${sets.length} SET${sets.length===1?'':'S'}`);
    const status = String(event.status || 'archive').toUpperCase().replaceAll('_',' ');
    const slug = String(event.slug || '').trim();
    const href = slug ? `/events/${encodeURIComponent(slug)}` : '';
    const connectionsHref = relationParts.length ? connectedEventUrl(event.id) : '';
    const search = searchText([title,city,venue,...names].join(' '));

    const article = create('article', 'archive-event');
    article.dataset.archiveEvent = '';
    article.dataset.archiveYear = String(year || '');
    article.dataset.archiveArtists = lineup.length ? '1' : '0';
    article.dataset.archiveSets = sets.length ? '1' : '0';
    article.dataset.archiveSearchValue = search;
    article.dataset.archiveId = String(Number(event.id) || 0);

    const imageHost = create('div', 'archive-event-image');
    if (image) {
      const img = create('img');
      img.src = image;
      img.alt = title;
      img.loading = 'lazy';
      imageHost.appendChild(img);
    } else {
      imageHost.appendChild(create('div', 'archive-event-placeholder mono', 'BRVTAL / ARCHIVE'));
    }

    const copy = create('div', 'archive-event-copy');
    const meta = create('div', 'archive-event-meta mono');
    meta.append(
      create('span', '', [date,city].filter(Boolean).join(' / ')),
      create('span', '', status)
    );
    copy.appendChild(meta);
    copy.appendChild(create('h3', '', title));
    copy.appendChild(create('p', '', [venue,names.slice(0,4).join(' / ')].filter(Boolean).join(' — ')));
    copy.appendChild(create('div', 'archive-event-relations mono', relationParts.join(' / ') || 'HISTORICAL RECORD'));

    if (connectionsHref) {
      const connections = create('a', 'archive-event-link mono', 'EXPLORE CONNECTIONS ↗');
      connections.dataset.archiveConnections = '';
      connections.href = connectionsHref;
      connections.setAttribute('aria-label', `Explore connections for ${title}`);
      copy.appendChild(connections);
    }
    if (href) {
      const record = create('a', 'archive-event-link mono', 'OPEN RECORD ↗');
      record.href = href;
      record.setAttribute('aria-label', `View ${title}`);
      copy.appendChild(record);
    }

    article.append(imageHost, copy);
    return article;
  }

  function applyArchiveFilters() {
    const root = qs('#eventArchive');
    if (!root) return;
    const year = state.archiveYear;
    const relation = state.archiveRelation;
    const query = state.archiveQuery;
    let visible = 0;
    root.querySelectorAll('[data-archive-filter]').forEach(button => button.classList.toggle('active', button.dataset.archiveFilter === year));
    root.querySelectorAll('[data-archive-relation]').forEach(button => button.classList.toggle('active', button.dataset.archiveRelation === relation));
    root.querySelectorAll('[data-archive-event]').forEach(card => {
      const matchesYear = year === 'all' || card.dataset.archiveYear === year;
      const matchesRelation = relation === 'all' || card.dataset[`archive${relation[0].toUpperCase()}${relation.slice(1)}`] === '1';
      const matchesQuery = !query || String(card.dataset.archiveSearchValue || '').includes(query);
      card.hidden = !(matchesYear && matchesRelation && matchesQuery);
      if (!card.hidden) visible++;
    });
    const results = qs('[data-archive-results]', root);
    if (results) results.textContent = `${visible} RECORD${visible===1?'':'S'} FOUND`;
    const empty = qs('[data-archive-empty]', root);
    if (empty) empty.hidden = visible !== 0;
  }

  function resetArchiveFilters() {
    state.archiveYear = 'all';
    state.archiveRelation = 'all';
    state.archiveQuery = '';
    const search = qs('[data-archive-search]', qs('#eventArchive'));
    if (search) search.value = '';
    applyArchiveFilters();
    search?.focus();
  }

  function applyYearFilter(year) {
    state.archiveYear = String(year || 'all');
    applyArchiveFilters();
  }

  function renderArchive(archive) {
    const root = qs('#eventArchive');
    if (!root) return;
    const events = Array.isArray(archive?.events) ? archive.events : [];
    const years = Array.isArray(archive?.years) ? archive.years.map(Number).filter(Boolean) : [];
    const counts = archive?.counts && typeof archive.counts === 'object' ? archive.counts : {};
    const yearHost = qs('.archive-years', root);
    const grid = qs('.archive-grid', root);
    const summary = qs('.archive-summary', root);

    if (!events.length) {
      root.hidden = true;
      return;
    }
    root.hidden = false;

    if (yearHost) {
      const allYears = create('button', 'active', 'ALL YEARS');
      allYears.type = 'button';
      allYears.dataset.archiveFilter = 'all';
      const yearButtons = years.map(year => {
        const button = create('button', '', String(year));
        button.type = 'button';
        button.dataset.archiveFilter = String(year);
        return button;
      });
      yearHost.replaceChildren(allYears, ...yearButtons);
      yearHost.querySelectorAll('[data-archive-filter]').forEach(button => button.addEventListener('click', () => applyYearFilter(button.dataset.archiveFilter || 'all')));
    }
    if (grid) grid.replaceChildren(...events.map(event => archiveCard(event)));
    root.querySelectorAll('[data-archive-relation]').forEach(button => button.addEventListener('click', () => {
      state.archiveRelation = button.dataset.archiveRelation || 'all';
      applyArchiveFilters();
    }));
    const search = qs('[data-archive-search]', root);
    if (search) search.addEventListener('input', () => {
      state.archiveQuery = searchText(search.value.trim());
      applyArchiveFilters();
    });
    const reset = qs('[data-archive-reset]', root);
    if (reset && !reset.dataset.bound) {
      reset.dataset.bound = '1';
      reset.addEventListener('click', resetArchiveFilters);
    }
    if (summary) {
      summary.textContent = `${Number(counts.events ?? events.length)} NIGHTS / ${Number(counts.sets ?? 0)} RELATED SETS / ${Number(counts.media ?? 0)} VISUAL RECORDS`;
    }
    applyArchiveFilters();
  }

  async function fetchPublicData() {
    if (window.BRVTALPublicDataPromise) {
      const {payload} = await window.BRVTALPublicDataPromise;
      const data = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
      if (!data || typeof data !== 'object') throw new Error('INVALID_PUBLIC_PAYLOAD');
      return data;
    }
    let lastError = null;
    for (const endpoint of API_CANDIDATES) {
      try {
        const response = await fetch(endpoint,{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});
        if (!response.ok) throw new Error(`API ${response.status}`);
        const payload = await response.json();
        const data = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
        if (!data || typeof data !== 'object') throw new Error('INVALID_PUBLIC_PAYLOAD');
        return data;
      } catch (error) { lastError = error; }
    }
    throw lastError || new Error('PUBLIC_API_UNAVAILABLE');
  }

  function observeActiveTrack() {
    const track = qs('.events-track');
    if (!track || state.observer) return;
    state.observer = new MutationObserver(() => {
      if (!state.data) return;
      clearTimeout(state.timer);
      state.timer = setTimeout(() => renderActive(state.data.events), 0);
    });
    state.observer.observe(track,{childList:true,subtree:true,characterData:true});
  }

  async function init() {
    try {
      const data = await fetchPublicData();
      state.data = data;
      window.dispatchEvent(new CustomEvent('brvtal:public-data', { detail:data }));
      renderActive(data.events);
      renderArchive(data.archive || {});
      observeActiveTrack();
      if (window.ScrollTrigger) window.ScrollTrigger.refresh();
      document.documentElement.dataset.archive = 'live';
      return {ok:true,events:Array.isArray(data.archive?.events)?data.archive.events.length:0};
    } catch (error) {
      document.documentElement.dataset.archive = 'fallback';
      return {ok:false,error:error?.message || 'PUBLIC_ARCHIVE_UNAVAILABLE'};
    }
  }

  if (document.readyState === 'complete') setTimeout(init, 220);
  else window.addEventListener('load', () => setTimeout(init, 220), {once:true});

  window.BRVTALPublicArchive = {init,renderArchive,renderActive,applyYearFilter,applyArchiveFilters,getData:()=>state.data};

  function runtimeAssetVersion() {
    const archiveScript = [...document.scripts].find(script => {
      try {
        return new URL(script.src, location.href).pathname.endsWith('/js/archive.js');
      } catch (_) {
        return false;
      }
    });
    if (!archiveScript) return '';
    try {
      return new URL(archiveScript.src, location.href).searchParams.get('v') || '';
    } catch (_) {
      return '';
    }
  }

  function relatedAssetUrl(path) {
    const version = runtimeAssetVersion();
    return `${path}${version ? `?v=${encodeURIComponent(version)}` : ''}`;
  }

  if (!document.querySelector('link[data-related-content-style]')) {
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = relatedAssetUrl('/css/related-content.css');
    link.dataset.relatedContentStyle = '1';
    document.head.appendChild(link);
  }

  import(relatedAssetUrl('/js/related-content.js')).catch(() => {
    document.documentElement.dataset.related = 'unavailable';
  });
})();
