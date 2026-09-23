/** Enhance the public Sets archive with browsing controls backed by shared data. */
(() => {
  'use strict';

  const section = document.querySelector('.sets');
  const list = section?.querySelector('.set-list');
  const intro = section?.querySelector('.sets-intro');
  if (!section || !list || !intro) return;

  const escapeHtml = value => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const safeHttpUrl = value => {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.href);
      return /^https?:$/i.test(url.protocol) ? url.href : '';
    } catch (_) {
      return '';
    }
  };

  const safeMediaUrl = value => {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.href);
      return /^https?:$/i.test(url.protocol) ? raw : '';
    } catch (error) {
      if (error instanceof TypeError) return '';
      throw error;
    }
  };

  const routeUrl = (type, slug) => {
    const clean = String(slug ?? '').trim();
    return clean ? `/${type}/${encodeURIComponent(clean)}` : '';
  };

  const platformLabel = item => String(item?.platform ?? 'LISTEN').trim().toUpperCase() || 'LISTEN';
  const hasArtist = item => Number(item?.artist_id || 0) > 0 && String(item?.artist_name ?? '').trim() !== '';
  const hasEvent = item => Number(item?.event_id || 0) > 0 && String(item?.event_title ?? '').trim() !== '';

  const state = { mode: 'latest', relation: '' };
  let sets = [];

  const controls = document.createElement('div');
  controls.className = 'sets-library-controls';
  controls.innerHTML = `
    <div class="sets-library-modes" role="group" aria-label="Browse sets">
      <button type="button" class="active" data-sets-mode="latest" aria-pressed="true">LATEST</button>
      <button type="button" data-sets-mode="artist" aria-pressed="false">ARTIST</button>
      <button type="button" data-sets-mode="event" aria-pressed="false">EVENT</button>
    </div>
    <div class="sets-library-options" data-sets-options hidden></div>
    <div class="sets-library-count mono" data-sets-count aria-live="polite"></div>`;
  intro.after(controls);

  const modesRoot = controls.querySelector('.sets-library-modes');
  const optionsRoot = controls.querySelector('[data-sets-options]');
  const countRoot = controls.querySelector('[data-sets-count]');

  const uniqueRelations = mode => {
    const map = new Map();
    sets.forEach(item => {
      if (mode === 'artist' && hasArtist(item)) {
        map.set(String(item.artist_id), String(item.artist_name));
      }
      if (mode === 'event' && hasEvent(item)) {
        map.set(String(item.event_id), String(item.event_title));
      }
    });
    return [...map.entries()].sort((a, b) => a[1].localeCompare(b[1], 'en', { sensitivity: 'base' }));
  };

  const optionMarkup = mode => {
    if (mode === 'latest') return '';
    const label = mode === 'artist' ? 'ALL ARTISTS' : 'ALL EVENTS';
    const options = uniqueRelations(mode);
    return [
      `<button type="button" class="${state.relation === '' ? 'active' : ''}" data-sets-relation="" aria-pressed="${state.relation === ''}">${label}</button>`,
      ...options.map(([id, name]) => `<button type="button" class="${state.relation === id ? 'active' : ''}" data-sets-relation="${escapeHtml(id)}" aria-pressed="${state.relation === id}">${escapeHtml(name)}</button>`),
    ].join('');
  };

  const visibleSets = () => {
    // Preserve the canonical API order: editorial sort_order first, then newest created_at.
    const ordered = [...sets];
    if (state.mode === 'artist') {
      return ordered.filter(item => hasArtist(item) && (!state.relation || String(item.artist_id) === state.relation));
    }
    if (state.mode === 'event') {
      return ordered.filter(item => hasEvent(item) && (!state.relation || String(item.event_id) === state.relation));
    }
    return ordered;
  };

  const relationLinks = item => {
    const links = [];
    if (hasArtist(item)) {
      const href = routeUrl('artists', item.artist_slug);
      links.push(href
        ? `<a href="${escapeHtml(href)}">ARTIST / ${escapeHtml(item.artist_name)}</a>`
        : `<span>ARTIST / ${escapeHtml(item.artist_name)}</span>`);
    }
    if (hasEvent(item)) {
      const href = routeUrl('events', item.event_slug);
      links.push(href
        ? `<a href="${escapeHtml(href)}">EVENT / ${escapeHtml(item.event_title)}</a>`
        : `<span>EVENT / ${escapeHtml(item.event_title)}</span>`);
    }
    return links.join('');
  };

  const renderRows = () => {
    const visible = visibleSets();
    const emptyCopy = sets.length === 0
      ? '<div class="sets-library-empty"><strong>NO PUBLISHED SETS YET.</strong><span>The listening archive will appear here when a Set is published.</span></div>'
      : '<div class="sets-library-empty"><strong>NO SETS IN THIS VIEW.</strong><span>Choose another artist or event.</span></div>';

    list.innerHTML = visible.length ? visible.map((item, index) => {
      const title = String(item?.title ?? item?.name ?? 'BRVTAL SET');
      const slug = String(item?.slug ?? '').trim();
      const canonical = routeUrl('sets', slug) || '/#sets';
      const external = safeHttpUrl(item?.external_url ?? '');
      const description = String(item?.description ?? '').trim();
      const relations = relationLinks(item);
      const cover = safeMediaUrl(item?.cover_image ?? '');
      const coverMarkup = cover
        ? `<figure class="set-library-cover" data-set-cover><img src="${escapeHtml(cover)}" alt="" loading="lazy" decoding="async"></figure>`
        : '<figure class="set-library-cover set-library-cover--empty" data-set-cover aria-hidden="true"></figure>';
      return `<article class="set-item set-library-item" data-set-id="${escapeHtml(item?.id ?? '')}" data-sets-artist="${escapeHtml(item?.artist_id ?? '')}" data-sets-event="${escapeHtml(item?.event_id ?? '')}">
        <div class="set-num mono">${String(index + 1).padStart(3, '0')}</div>
        ${coverMarkup}
        <div class="set-main">
          <span class="mono">${escapeHtml(platformLabel(item))} / BRVTAL SOUND</span>
          <a class="set-record-link" href="${escapeHtml(canonical)}"><h4>${escapeHtml(title)}</h4><span class="mono">OPEN RECORD →</span></a>
          ${relations ? `<div class="set-library-relations mono">${relations}</div>` : '<div class="set-library-relations mono"><span>INDEPENDENT RECORD</span></div>'}
          ${description ? `<p class="set-library-description">${escapeHtml(description)}</p>` : ''}
        </div>
        ${external ? `<a href="${escapeHtml(external)}" target="_blank" rel="noopener noreferrer" class="set-action set-listen-action magnetic" data-cursor="${escapeHtml(platformLabel(item))}"><span>LISTEN</span> ↗</a>` : ''}
      </article>`;
    }).join('') : emptyCopy;

    list.querySelectorAll('[data-set-cover] img').forEach(image => {
      const cover = image.closest('[data-set-cover]');
      if (!cover) return;
      const failCover = () => {
        image.hidden = true;
        cover.classList.add('set-library-cover--empty');
        cover.setAttribute('aria-hidden', 'true');
      };
      image.addEventListener('error', failCover, {once:true});
      if (image.complete && image.naturalWidth === 0) failCover();
    });

    if (countRoot) countRoot.textContent = `${String(visible.length).padStart(2, '0')} / ${String(sets.length).padStart(2, '0')} RECORDS`;
    const sectionCount = section.querySelector('.section-head > span:first-child');
    const concept05 = document.querySelector('[data-concept="05"]');
    if (sectionCount && !concept05) {
      sectionCount.textContent = `SOUND LIBRARY / ${String(sets.length).padStart(2, '0')}`;
    }
    section.dataset.publicSetCount = String(sets.length);
  };

  const renderOptions = () => {
    if (!optionsRoot) return;
    const markup = optionMarkup(state.mode);
    optionsRoot.innerHTML = markup;
    optionsRoot.hidden = state.mode === 'latest' || sets.length === 0;
    optionsRoot.querySelectorAll('[data-sets-relation]').forEach(button => {
      button.addEventListener('click', () => {
        state.relation = button.dataset.setsRelation || '';
        renderOptions();
        renderRows();
      });
    });
  };

  const selectMode = mode => {
    if (!['latest', 'artist', 'event'].includes(mode)) return;
    state.mode = mode;
    state.relation = '';
    controls.querySelectorAll('[data-sets-mode]').forEach(button => {
      const active = button.dataset.setsMode === mode;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', String(active));
    });
    renderOptions();
    renderRows();
  };

  controls.querySelectorAll('[data-sets-mode]').forEach(button => {
    button.addEventListener('click', () => selectMode(button.dataset.setsMode || 'latest'));
  });

  const render = items => {
    if (!Array.isArray(items)) return false;
    sets = items.filter(item => item && typeof item === 'object');
    if (modesRoot) modesRoot.hidden = sets.length === 0;
    selectMode('latest');
    document.documentElement.dataset.publicSets = 'library';
    window.dispatchEvent(new CustomEvent('brvtal:sets-library-rendered', { detail: { count: sets.length } }));
    return true;
  };

  const dataFromSharedRequest = async () => {
    for (let attempt = 0; attempt < 12; attempt += 1) {
      const request = window.BRVTALPublicDataPromise;
      if (request && typeof request.then === 'function') {
        const result = await request;
        const payload = result?.payload ?? result;
        const root = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
        return Array.isArray(root?.sets) ? root.sets : [];
      }
      await new Promise(resolve => window.setTimeout(resolve, 40));
    }
    return null;
  };

  window.BRVTALPublicSetsLibrary = {
    render,
    selectMode,
    visibleSets,
  };

  window.addEventListener('load', () => {
    window.setTimeout(async () => {
      if (document.documentElement.dataset.publicSets === 'library') return;
      try {
        const items = await dataFromSharedRequest();
        if (items === null) {
          controls.remove();
          return;
        }
        render(items);
      } catch (_) {
        controls.remove();
        // Existing static/dynamic fallback remains visible if the shared request fails.
      }
    }, 220);
  }, { once: true });
})();
