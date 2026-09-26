(() => {
  'use strict';

  const PREFIX = 'brvtal.discadmin.draft.v1';

  function segment(value) {
    return encodeURIComponent(String(value ?? '').trim().toLowerCase());
  }

  function key(scope, identity) {
    const safeScope = segment(scope);
    const safeIdentity = segment(identity);
    if (!safeScope || !safeIdentity) throw new Error('DRAFT_ID_REQUIRED');
    return `${PREFIX}:${safeScope}:${safeIdentity}`;
  }

  function normalize(value) {
    if (!value || typeof value !== 'object' || Number(value.version) !== 1) return null;
    if (!value.data || typeof value.data !== 'object' || Array.isArray(value.data)) return null;
    return {
      version: 1,
      base_revision: String(value.base_revision ?? ''),
      saved_at: String(value.saved_at ?? ''),
      data: value.data
    };
  }

  function load(scope, identity) {
    try {
      const raw = localStorage.getItem(key(scope, identity));
      if (!raw) return null;
      const draft = normalize(JSON.parse(raw));
      if (!draft) localStorage.removeItem(key(scope, identity));
      return draft;
    } catch (_) {
      return null;
    }
  }

  function save(scope, identity, payload = {}) {
    const draft = normalize({
      version: 1,
      base_revision: String(payload.base_revision ?? ''),
      saved_at: new Date().toISOString(),
      data: payload.data
    });
    if (!draft) throw new Error('INVALID_DRAFT');
    localStorage.setItem(key(scope, identity), JSON.stringify(draft));
    return draft;
  }

  function remove(scope, identity) {
    localStorage.removeItem(key(scope, identity));
  }

  function sameRevision(draft, revision) {
    return String(draft?.base_revision ?? '') === String(revision ?? '');
  }

  window.BRVTALDrafts = {key, load, save, remove, sameRevision};
})();
