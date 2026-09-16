(() => {
  'use strict';

  function safeHttp(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, location.href);
      return /^https?:$/i.test(url.protocol) ? url.href : '';
    } catch (_) { return ''; }
  }

  async function reconcile() {
    if (!window.BRVTALThemeRuntime?.resolveSettings) return false;
    const settings = await window.BRVTALThemeRuntime.resolveSettings();

    // Theme Studio is the visual authority. Re-applying the active theme after
    // the legacy dynamic settings pass prevents appearance.defaultAccent from
    // overriding the active theme palette.
    if (settings?.theme) window.BRVTALThemeRuntime.apply(settings.theme);

    const spotify = safeHttp(settings?.social?.spotify || settings?.social?.spotify_url || '');
    document.querySelectorAll('[data-social="spotify"]').forEach(link => {
      if (spotify) { link.href = spotify; link.hidden = false; }
      else link.hidden = true;
    });
    return true;
  }

  function schedule() {
    window.setTimeout(() => reconcile().catch(error => console.warn('[BRVTAL] Settings reconciliation kept static fallbacks.', error)), 260);
  }

  if (document.readyState === 'complete') schedule();
  else window.addEventListener('load', schedule, { once:true });
})();
