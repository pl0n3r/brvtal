(() => {
  'use strict';

  const PARAMS = {
    archiveYear: 'archive_year',
    archiveRelation: 'archive_relation',
    archiveQuery: 'archive_q',
    mediaType: 'media_type',
    mediaQuery: 'media_q',
    networkType: 'network_type',
    networkId: 'network_id'
  };
  let applying = false;
  let searchTimer = null;
  let networkExplicit = false;

  const qs = (selector, root = document) => root.querySelector(selector);
  const allowedRelation = value => ['all', 'artists', 'sets'].includes(value) ? value : 'all';
  const allowedMediaType = value => ['all', 'image', 'video', 'audio'].includes(value) ? value : 'all';
  const allowedNetworkType = value => ['artists', 'events', 'sets', 'releases'].includes(value) ? value : '';
  const cleanQuery = value => String(value || '').trim().slice(0, 120);
  const cleanYear = value => /^\d{4}$/.test(String(value || '')) ? String(value) : 'all';
  const cleanNetworkId = value => {
    const id = Number(value);
    return Number.isSafeInteger(id) && id > 0 ? String(id) : '';
  };

  function urlState() {
    const params = new URLSearchParams(location.search);
    const networkType = allowedNetworkType(params.get(PARAMS.networkType));
    const networkId = cleanNetworkId(params.get(PARAMS.networkId));
    return {
      archiveYear: cleanYear(params.get(PARAMS.archiveYear)),
      archiveRelation: allowedRelation(params.get(PARAMS.archiveRelation) || 'all'),
      archiveQuery: cleanQuery(params.get(PARAMS.archiveQuery)),
      mediaType: allowedMediaType(params.get(PARAMS.mediaType) || 'all'),
      mediaQuery: cleanQuery(params.get(PARAMS.mediaQuery)),
      networkType: networkType && networkId ? networkType : '',
      networkId: networkType && networkId ? networkId : ''
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
    writeParam(params, PARAMS.networkType, networkExplicit ? allowedNetworkType(next.networkType) : '');
    writeParam(params, PARAMS.networkId, networkExplicit ? cleanNetworkId(next.networkId) : '');
    const query = params.toString();
    const url = `${location.pathname}${query ? `?${query}` : ''}${location.hash}`;
    history[mode === 'push' ? 'pushState' : 'replaceState']({ brvtalDiscovery: true }, '', url);
  }

  function currentControlsState() {
    const selectedNetwork = networkExplicit ? qs('[data-related-select].active') : null;
    return {
      archiveYear: qs('[data-archive-filter].active')?.dataset.archiveFilter || 'all',
      archiveRelation: qs('[data-archive-relation].active')?.dataset.archiveRelation || 'all',
      archiveQuery: qs('[data-archive-search]')?.value || '',
      mediaType: qs('[data-public-media-type].active')?.dataset.publicMediaType || 'all',
      mediaQuery: qs('[data-public-media-search]')?.value || '',
      networkType: selectedNetwork?.dataset.relatedType || '',
      networkId: selectedNetwork?.dataset.relatedId || ''
    };
  }

  function clickMatching(selector, value, datasetKey) {
    const target = [...document.querySelectorAll(selector)].find(button => button.dataset[datasetKey] === value);
    target?.click();
    return target || null;
  }

  function setInput(selector, value) {
    const input = qs(selector);
    if (!input) return;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function applyNetworkState(state) {
    networkExplicit = Boolean(state.networkType && state.networkId);
    if (!qs('[data-related-mode]')) return;

    if (!networkExplicit) {
      clickMatching('[data-related-mode]', 'artists', 'relatedMode');
      return;
    }

    clickMatching('[data-related-mode]', state.networkType, 'relatedMode');
    window.BRVTALRelatedContent?.select?.(state.networkType, Number(state.networkId));
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
      applyNetworkState(state);
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
    if (event.target.closest('[data-related-mode],[data-related-select]')) {
      networkExplicit = true;
      queueMicrotask(() => updateUrl(currentControlsState(), 'push'));
      return;
    }
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
