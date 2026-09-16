(() => {
  'use strict';

  if (window.__BRVTAL_ADMIN_AUTH_BOUNDARY__) return;
  window.__BRVTAL_ADMIN_AUTH_BOUNDARY__ = true;

  let expiring = false;

  function adminState() {
    try {
      if (typeof state === 'object' && state) return state;
    } catch (_) {}
    return window.state && typeof window.state === 'object' ? window.state : null;
  }

  function clearCsrf() {
    try {
      if (typeof csrf !== 'undefined') csrf = '';
    } catch (_) {}
    try {
      if ('csrf' in window) window.csrf = '';
    } catch (_) {}
  }

  function expireSession() {
    if (expiring) return false;
    const currentState = adminState();
    if (currentState && currentState.authed === false) return false;

    expiring = true;
    try {
      if (currentState) currentState.authed = false;
      clearCsrf();

      try {
        if (typeof closeModal === 'function') closeModal();
        else if (typeof window.closeModal === 'function') window.closeModal();
      } catch (_) {}

      try {
        if (typeof render === 'function') render();
        else if (typeof window.render === 'function') window.render();
      } catch (_) {}

      window.dispatchEvent(new CustomEvent('brvtal:auth-required'));
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
  const guardedFetch = async function(input, init) {
    const response = await originalFetch(input, init);
    if (response.status === 401 && isAdminRequest(input)) expireSession();
    return response;
  };
  guardedFetch.__brvtalAuthBoundary = true;
  guardedFetch.__brvtalOriginalFetch = originalFetch;
  window.fetch = guardedFetch;

  window.BRVTALAdminAuthBoundary = {expireSession, isAdminRequest};
})();