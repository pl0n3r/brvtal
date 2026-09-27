(() => {
  'use strict';

  if (window.__BRVTAL_ADMIN_AUTH_BOUNDARY__) return;
  window.__BRVTAL_ADMIN_AUTH_BOUNDARY__ = true;

  let expiring = false;
  let authPromise = null;
  let authSnapshot = null;
  let authGeneration = 0;
  let revalidationCount = 0;
  let revalidationAttempted = 0;
  let revalidationSucceeded = 0;
  let revalidationFailed = 0;

  function adminState() {
    return window.state && typeof window.state === 'object' ? window.state : null;
  }

  function clearCsrf() {
    if ('csrf' in window) window.csrf = '';
  }

  function rememberAuth(payload) {
    const normalized = payload && typeof payload === 'object' ? payload : {};
    authSnapshot = normalized;
    if (normalized.authenticated && normalized.csrf && 'csrf' in window) {
      window.csrf = String(normalized.csrf);
    }
    return normalized;
  }

  function clearAuthCache() {
    authGeneration += 1;
    authPromise = null;
    authSnapshot = null;
  }

  function hasUnsavedChanges() {
    return window.BRVTALUnsavedChanges?.hasDirtyChanges?.() === true;
  }

  function preserveUnsavedAuthState() {
    document.documentElement.dataset.brvtalAuthRequired = 'unsaved';
    const root = document.querySelector('#eventModal.open,#modal.open');
    if (root && !root.querySelector('[data-brvtal-auth-required]')) {
      const notice = document.createElement('div');
      notice.dataset.brvtalAuthRequired = '1';
      notice.setAttribute('role','status');
      notice.setAttribute('aria-live','assertive');
      notice.className = 'error';
      notice.textContent = 'SESSION ENDED. UNSAVED CHANGES ARE PRESERVED; COPY THEM BEFORE SIGNING IN AGAIN.';
      root.prepend(notice);
    }
    return true;
  }

  function clearDeferredAuthState() {
    delete document.documentElement.dataset.brvtalAuthRequired;
    document.querySelectorAll('[data-brvtal-auth-required]').forEach(node => node.remove());
  }

  function renderExpiredSession() {
    clearDeferredAuthState();
    try {
      if (typeof closeModal === 'function') closeModal();
      else if (typeof window.closeModal === 'function') window.closeModal();
    } catch (_) {}

    try {
      if (typeof render === 'function') render();
      else if (typeof window.render === 'function') window.render();
    } catch (_) {}
  }

  function applyExpiredSessionState(preserveUnsaved) {
    if (preserveUnsaved) preserveUnsavedAuthState();
    else renderExpiredSession();
  }

  function expireSession() {
    if (expiring) return false;
    const currentState = adminState();
    if (currentState && currentState.authed === false) return false;

    expiring = true;
    try {
      if (currentState) currentState.authed = false;
      clearCsrf();
      clearAuthCache();

      const preserveUnsaved = hasUnsavedChanges();
      applyExpiredSessionState(preserveUnsaved);
      window.dispatchEvent(new CustomEvent('brvtal:auth-required',{
        detail:{preservedUnsavedChanges:preserveUnsaved}
      }));
      return true;
    } finally {
      expiring = false;
    }
  }

  function isAdminRequest(input) {
    let raw = '';
    if (typeof input === 'string') raw = input;
    else if (input && typeof input.url === 'string') raw = input.url;
    if (!raw) return false;

    try {
      const url = new URL(raw, window.location.href);
      if (url.origin !== window.location.origin) return false;
      return url.pathname === '/api'
        || url.pathname.startsWith('/api/')
        || url.pathname === '/discadmin'
        || url.pathname.startsWith('/discadmin/');
    } catch (_) {
      return false;
    }
  }

  const originalFetch = window.fetch.bind(window);

  async function auth(options = {}) {
    const force = options?.force === true;
    if (!force && authSnapshot?.authenticated && authSnapshot?.csrf) {
      return authSnapshot;
    }
    if (authPromise) return authPromise;

    const generation = authGeneration;
    const pending = (async () => {
      const response = await originalFetch('/api/index.php/auth', {
        method:'GET',
        credentials:'same-origin',
        cache:'no-store'
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) {
        if (response.status === 401) expireSession();
        throw new Error(payload.error || 'AUTH_REQUIRED');
      }
      if (generation !== authGeneration) throw new Error('AUTH_REVALIDATION_STALE');
      return rememberAuth(payload);
    })();

    authPromise = pending;
    try {
      return await pending;
    } finally {
      if (authPromise === pending) authPromise = null;
    }
  }

  async function csrfToken() {
    try {
      if (typeof window.csrf === 'string' && window.csrf) return window.csrf;
    } catch (_) {}
    const payload = await auth();
    if (!payload.authenticated || !payload.csrf) throw new Error('AUTH_REQUIRED');
    return String(payload.csrf);
  }

  function requestMethod(input, init) {
    const explicit = String(init?.method || '').trim();
    if (explicit) return explicit.toUpperCase();
    if (input && typeof input.method === 'string') return input.method.toUpperCase();
    return 'GET';
  }

  async function revalidateAdminGet(input, init, response) {
    if (response.status !== 401 || !isAdminRequest(input)) return response;
    if (requestMethod(input, init) !== 'GET') {
      expireSession();
      return response;
    }

    revalidationCount += 1;
    revalidationAttempted += 1;
    let snapshot = null;
    try {
      snapshot = await auth({force:true});
    } catch (_) {
      revalidationFailed += 1;
      window.dispatchEvent(new Event('brvtal:auth-revalidation-failed'));
    }
    if (snapshot?.authenticated !== true || !snapshot?.csrf || adminState()?.authed === false) {
      if (snapshot) revalidationFailed += 1;
      expireSession();
      return response;
    }
    revalidationSucceeded += 1;

    const retry = await originalFetch(input, init);
    if (retry.status === 401) expireSession();
    return retry;
  }

  const guardedFetch = async function(input, init) {
    const response = await originalFetch(input, init);
    return revalidateAdminGet(input, init, response);
  };
  guardedFetch.__brvtalAuthBoundary = true;
  guardedFetch.__brvtalOriginalFetch = originalFetch;
  window.fetch = guardedFetch;

  window.BRVTALAdminAuthBoundary = {
    expireSession,
    isAdminRequest,
    preserveUnsavedAuthState,
    clearDeferredAuthState,
    auth,
    csrfToken,
    rememberAuth,
    clearAuthCache,
    diagnostics: () => ({
      revalidationCount,
      revalidationAttempted,
      revalidationSucceeded,
      revalidationFailed
    })
  };
})();