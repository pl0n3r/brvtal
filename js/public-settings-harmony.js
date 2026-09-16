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

  function restoreServerSeo() {
    const serverTitle = document.querySelector('meta[property="og:title"]')?.content || '';
    const serverDescription = document.querySelector('meta[property="og:description"]')?.content || '';
    const serverImage = document.querySelector('meta[name="twitter:image"]')?.content || '';
    if (serverTitle) document.title = serverTitle;
    const description = document.querySelector('meta[name="description"]');
    if (description && serverDescription) description.setAttribute('content', serverDescription);
    const ogImage = document.querySelector('meta[property="og:image"]');
    if (ogImage && serverImage) ogImage.setAttribute('content', serverImage);
  }

  async function reconcile() {
    if (!window.BRVTALThemeRuntime?.resolveSettings) return false;
    const settings = await window.BRVTALThemeRuntime.resolveSettings();

    // Theme Studio is the visual authority. Re-applying the active theme after
    // the legacy dynamic settings pass prevents appearance.defaultAccent from
    // overriding the active theme palette.
    if (settings?.theme) window.BRVTALThemeRuntime.apply(settings.theme);

    // Canonical SEO is rendered by PHP. Theme SEO is retained only as a legacy
    // compatibility value, so restore the server-owned title/description/image
    // after applying an older theme that still contains theme.seo.
    restoreServerSeo();

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
