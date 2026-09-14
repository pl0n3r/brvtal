(() => {
  'use strict';

  const PARAMS = {
    archiveYear: 'archive_year',
    archiveRelation: 'archive_relation',
    archiveQuery: 'archive_q',
    mediaType: 'media_type',
    mediaQuery: 'media_q'
  };
  let applying = false;
  let searchTimer = null;

  const qs = (selector, root = document) => root.querySelector(selector);
  const allowedRelation = value => ['all', 'artists', 'sets'].includes(value) ? value : 'all';
  const allowedMediaType = value => ['all', 'image', 'video', 'audio'].includes(value) ? value : 'all';
  const cleanQuery = value => String(value || '').trim().slice(0, 120);
  const cleanYear = value => /^\d{4}$/.test(String(value || '')) ? String(value) : 'all';

  function urlState() {
    const params = new URLSearchParams(location.search);
    return {
      archiveYear: cleanYear(params.get(PARAMS.archiveYear)),
      archiveRelation: allowedRelation(params.get(PARAMS.archiveRelation) || 'all'),
      archiveQuery: cleanQuery(params.get(PARAMS.archiveQuery)),
      mediaType: allowedMediaType(params.get(PARAMS.mediaType) || 'all'),
      mediaQuery: cleanQuery(params.get(PARAMS.mediaQuery))
    };
  }

  function writeParam(params, key, value, defaultValue = '') {
    if (!value || value === defaultValue) params.delete(key);
    else params.set(key, value);
  }

  function updateUrl(next, mode = 'replace') {
    if (applying) return;
    const params = new URLSearchParams(location.search);
    writeParam(params, PARAMS.archiveYear, cleanYear(next.archiveYear), 'all');
    writeParam(params, PARAMS.archiveRelation, allowedRelation(next.archiveRelation), 'all');
    writeParam(params, PARAMS.archiveQuery, cleanQuery(next.archiveQuery));
    writeParam(params, PARAMS.mediaType, allowedMediaType(next.mediaType), 'all');
    writeParam(params, PARAMS.mediaQuery, cleanQuery(next.mediaQuery));
    const query = params.toString();
    const url = `${location.pathname}${query ? `?${query}` : ''}${location.hash}`;
    history[mode === 'push' ? 'pushState' : 'replaceState']({ brvtalDiscovery: true }, '', url);
  }

  function currentControlsState() {
    return {
      archiveYear: qs('[data-archive-filter].active')?.dataset.archiveFilter || 'all',
      archiveRelation: qs('[data-archive-relation].active')?.dataset.archiveRelation || 'all',
      archiveQuery: qs('[data-archive-search]')?.value || '',
      mediaType: qs('[data-public-media-type].active')?.dataset.publicMediaType || 'all',
      mediaQuery: qs('[data-public-media-search]')?.value || ''
    };
  }

  function clickMatching(selector, value, datasetKey) {
    const target = [...document.querySelectorAll(selector)].find(button => button.dataset[datasetKey] === value);
    target?.click();
  }

  function setInput(selector, value) {
    const input = qs(selector);
    if (!input) return;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function applyUrlState() {
    const state = urlState();
    applying = true;
    try {
      clickMatching('[data-archive-filter]', state.archiveYear, 'archiveFilter');
      clickMatching('[data-archive-relation]', state.archiveRelation, 'archiveRelation');
      setInput('[data-archive-search]', state.archiveQuery);
      clickMatching('[data-public-media-type]', state.mediaType, 'publicMediaType');
      setInput('[data-public-media-search]', state.mediaQuery);
    } finally {
      applying = false;
    }
  }

  function scheduleSearchSync() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => updateUrl(currentControlsState(), 'replace'), 180);
  }

  document.addEventListener('click', event => {
    if (applying) return;
    if (event.target.closest('[data-archive-filter],[data-archive-relation],[data-public-media-type],[data-archive-reset],[data-public-media-reset]')) {
      queueMicrotask(() => updateUrl(currentControlsState(), 'push'));
    }
  });

  document.addEventListener('input', event => {
    if (applying) return;
    if (event.target.matches?.('[data-archive-search],[data-public-media-search]')) scheduleSearchSync();
  });

  window.addEventListener('popstate', applyUrlState);
  window.addEventListener('brvtal:public-data', () => queueMicrotask(applyUrlState));

  if (document.readyState === 'complete') queueMicrotask(applyUrlState);
  else window.addEventListener('load', () => queueMicrotask(applyUrlState), { once: true });

  window.BRVTALDiscoveryUrlState = { apply: applyUrlState, read: urlState };
})();
