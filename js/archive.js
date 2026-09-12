(() => {
  'use strict';

  const API_CANDIDATES = ['/api/public.php', '/api/public', '/api/public/'];
  const state = { data:null, observer:null, timer:null, archiveYear:'all', archiveRelation:'all', archiveQuery:'' };
  const qs = (selector, root=document) => root.querySelector(selector);

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
    return raw.replace(/^\.?\//,'');
  };
  const formatDate = value => {
    if (!value) return '';
    const date = new Date(String(value).replace(' ','T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat(document.documentElement.lang || 'en',{day:'2-digit',month:'2-digit',year:'numeric'}).format(date);
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

  function renderActive(items) {
    const track = qs('.events-track');
    if (!track) return;
    const events = Array.isArray(items) ? items : [];
    const expected = activeSignature(events);
    if (currentActiveSignature(track) === expected) return;

    if (!events.length) {
      track.innerHTML = '<div class="events-empty mono">NO UPCOMING EVENTS / THE ARCHIVE REMAINS ACTIVE</div>';
      return;
    }

    track.innerHTML = events.map((event,index) => {
      const title = String(event.title || 'UNTITLED EVENT');
      const image = imgUrl(event.cover_image);
      const date = formatDate(event.event_date);
      const city = String(event.city || '');
      const venue = String(event.venue || '');
      const description = String(event.description || 'BRVTAL');
      const status = String(event.status || 'published').toLowerCase();
      const ticket = status === 'sold_out' ? '' : cleanUrl(event.ticket_url);
      return `<article class="event-card ${index===0?'event-active':''}" data-public-event-id="${Number(event.id)||0}">
        <div class="event-img">${image?`<img src="${esc(image)}" alt="${esc(title)}" loading="${index?'lazy':'eager'}">`:''}</div>
        <div class="event-info">
          <span class="mono">${esc([date,city].filter(Boolean).join(' / '))}</span>
          <h3>${esc(title)}</h3>
          <p>${esc([venue,description].filter(Boolean).join(' / '))}</p>
          <span class="event-status">${esc(activeStatusLabel(status,index))}</span>
          ${ticket?`<a class="event-ticket mono" href="${esc(ticket)}" target="_blank" rel="noopener">TICKETS ↗</a>`:''}
        </div>
      </article>`;
    }).join('');
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
    const names = lineup.map(item => item.name).filter(Boolean).slice(0,4);
    const relationParts = [];
    if (lineup.length) relationParts.push(`${lineup.length} ARTIST${lineup.length===1?'':'S'}`);
    if (sets.length) relationParts.push(`${sets.length} SET${sets.length===1?'':'S'}`);
    const status = String(event.status || 'archive').toUpperCase().replaceAll('_',' ');
    const slug = String(event.slug || '').trim();
    const href = slug ? `/events/${encodeURIComponent(slug)}` : '';
    const search = [title,city,venue,...names].join(' ').toLowerCase();

    return `<article class="archive-event" data-archive-event data-archive-year="${year||''}" data-archive-artists="${lineup.length?'1':'0'}" data-archive-sets="${sets.length?'1':'0'}" data-archive-search-value="${esc(search)}" data-archive-id="${Number(event.id)||0}">
      <div class="archive-event-image">${image?`<img src="${esc(image)}" alt="${esc(title)}" loading="lazy">`:'<div class="archive-event-placeholder mono">BRVTAL / ARCHIVE</div>'}</div>
      <div class="archive-event-copy">
        <div class="archive-event-meta mono"><span>${esc([date,city].filter(Boolean).join(' / '))}</span><span>${esc(status)}</span></div>
        <h3>${esc(title)}</h3>
        <p>${esc([venue,names.join(' / ')].filter(Boolean).join(' — '))}</p>
        <div class="archive-event-relations mono">${esc(relationParts.join(' / ') || 'HISTORICAL RECORD')}</div>
        ${href?`<a class="archive-event-link mono" href="${esc(href)}" aria-label="View ${esc(title)}">OPEN RECORD ↗</a>`:''}
      </div>
    </article>`;
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
      yearHost.innerHTML = `<button type="button" class="active" data-archive-filter="all">ALL YEARS</button>${years.map(year => `<button type="button" data-archive-filter="${year}">${year}</button>`).join('')}`;
      yearHost.querySelectorAll('[data-archive-filter]').forEach(button => button.addEventListener('click', () => applyYearFilter(button.dataset.archiveFilter || 'all')));
    }
    if (grid) grid.innerHTML = events.map(archiveCard).join('');
    root.querySelectorAll('[data-archive-relation]').forEach(button => button.addEventListener('click', () => {
      state.archiveRelation = button.dataset.archiveRelation || 'all';
      applyArchiveFilters();
    }));
    const search = qs('[data-archive-search]', root);
    if (search) search.addEventListener('input', () => {
      state.archiveQuery = search.value.trim().toLowerCase();
      applyArchiveFilters();
    });
    if (summary) {
      summary.textContent = `${Number(counts.events ?? events.length)} NIGHTS / ${Number(counts.sets ?? 0)} RELATED SETS / ${Number(counts.media ?? 0)} VISUAL RECORDS`;
    }
    applyArchiveFilters();
  }

  async function fetchPublicData() {
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

  import('/js/related-content.js').catch(() => {
    document.documentElement.dataset.related = 'unavailable';
  });
})();
