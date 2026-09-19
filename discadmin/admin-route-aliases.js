(() => {
  'use strict';

  const nativeGo = window.go;

  function canonicalModule(section) {
    const value = String(section || '').trim().toLowerCase();
    if (value === 'backups') return 'system';
    if (value === 'activity') return 'dashboard';
    if (value === 'security') return 'settings';
    return value;
  }

  function canonicalizeLocation() {
    const url = new URL(window.location.href);
    const raw = String(url.searchParams.get('module') || '').trim().toLowerCase();
    const canonical = canonicalModule(raw);
    if (!raw || canonical === raw) return canonical;

    if (canonical === 'dashboard') url.searchParams.delete('module');
    else url.searchParams.set('module', canonical);
    if (raw === 'security') url.searchParams.set('settings','advanced');

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
    window.go = async function brvtalCanonicalAdminGo(section, ...args) {
      const requested = String(section || '').trim().toLowerCase();
      const canonical = canonicalModule(requested);
      if (canonical === 'system' && requested === 'backups') {
        if (typeof window.tech === 'function') return window.tech('system', ...args);
      }
      const result = await nativeGo.call(this, canonical, ...args);
      if (requested === 'security') window.BRVTALSettingsV2?.activate?.('advanced');
      return result;
    };
  }

  window.BRVTALAdminRouteAliases = {canonicalModule, canonicalizeLocation};
})();
