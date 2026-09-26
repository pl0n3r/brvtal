(() => {
  'use strict';

  const PREFIX = 'brvtal.discadmin.draft.v1';
  let namespaceToken = '';
  let namespacePromise = null;

  function segment(value) {
    return encodeURIComponent(String(value ?? '').trim().toLowerCase());
  }

  async function sessionNamespace() {
    const token = String(window.csrf || '');
    if (!token) throw new Error('DRAFT_SESSION_REQUIRED');
    if (namespacePromise && namespaceToken === token) return namespacePromise;

    namespaceToken = token;
    namespacePromise = crypto.subtle.digest(
      'SHA-256',
      new TextEncoder().encode(token)
    ).then(buffer => [...new Uint8Array(buffer)]
      .map(value => value.toString(16).padStart(2,'0'))
      .join('')
      .slice(0,32));
    return namespacePromise;
  }

  async function key(scope, identity) {
    const safeScope = segment(scope);
    const safeIdentity = segment(identity);
    if (!safeScope || !safeIdentity) throw new Error('DRAFT_ID_REQUIRED');
    const session = await sessionNamespace();
    return `${PREFIX}:${session}:${safeScope}:${safeIdentity}`;
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

  async function load(scope, identity) {
    try {
      const draftKey = await key(scope, identity);
      const raw = localStorage.getItem(draftKey);
      if (!raw) return null;
      const draft = normalize(JSON.parse(raw));
      if (!draft) localStorage.removeItem(draftKey);
      return draft;
    } catch (error) {
      document.documentElement.dataset.brvtalDraftStorage =
        error instanceof SyntaxError ? 'invalid' : 'unavailable';
      return null;
    }
  }

  async function save(scope, identity, payload = {}) {
    const draft = normalize({
      version: 1,
      base_revision: String(payload.base_revision ?? ''),
      saved_at: new Date().toISOString(),
      data: payload.data
    });
    if (!draft) throw new Error('INVALID_DRAFT');
    localStorage.setItem(await key(scope, identity), JSON.stringify(draft));
    return draft;
  }

  async function remove(scope, identity) {
    localStorage.removeItem(await key(scope, identity));
  }

  function sameRevision(draft, revision) {
    return String(draft?.base_revision ?? '') === String(revision ?? '');
  }

  window.BRVTALDrafts = {key, load, save, remove, sameRevision};
})();
