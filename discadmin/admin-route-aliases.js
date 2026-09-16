(() => {
  'use strict';

  const nativeGo = window.go;

  function canonicalModule(section) {
    const value = String(section || '').trim().toLowerCase();
    if (value === 'backups') return 'system';
    if (value === 'activity') return 'dashboard';
    return value;
  }

  function canonicalizeLocation() {
    const url = new URL(window.location.href);
    const raw = String(url.searchParams.get('module') || '').trim().toLowerCase();
    const canonical = canonicalModule(raw);
    if (!raw || canonical === raw) return canonical;

    if (canonical === 'dashboard') url.searchParams.delete('module');
    else url.searchParams.set('module', canonical);

    window.history.replaceState(
      {brvtalAdminRoute: canonical},
      '',
      `${url.pathname}${url.search}${url.hash}`
    );
    return canonical;
  }

  canonicalizeLocation();
  window.addEventListener('popstate', canonicalizeLocation);

  if (typeof nativeGo === 'function') {
    window.go = function brvtalCanonicalAdminGo(section, ...args) {
      const canonical = canonicalModule(section);
      if (canonical === 'system' && String(section || '').trim().toLowerCase() === 'backups') {
        if (typeof window.tech === 'function') return window.tech('system', ...args);
      }
      return nativeGo.call(this, canonical, ...args);
    };
  }

  window.BRVTALAdminRouteAliases = {canonicalModule, canonicalizeLocation};
})();
