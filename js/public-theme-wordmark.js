(() => {
  'use strict';

  function safeAsset(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, location.href);
      if (!/^https?:$/i.test(url.protocol)) return '';
      const version = String(window.BRVTAL_PUBLIC_VERSION || '').trim();
      if (version && url.origin === location.origin && !url.searchParams.has('v')) url.searchParams.set('v', version);
      return url.href;
    } catch (_) {
      return '';
    }
  }

  function ensureStyle() {
    if (document.getElementById('brvtal-wordmark-style')) return;
    const style = document.createElement('style');
    style.id = 'brvtal-wordmark-style';
    style.textContent = `
      .theme-wordmark-image{display:none;width:auto;max-width:132px;height:34px;object-fit:contain;object-position:left center}
      .brand[data-theme-wordmark-ready="1"] .theme-wordmark-image{display:block}
      .brand[data-theme-wordmark-ready="1"]>span[data-site-name]{display:none}
      html[data-theme-brand-position="center"] .theme-wordmark-image{object-position:center}
      .theme-loader-wordmark{display:none;width:auto;max-width:min(390px,72vw);max-height:100px;object-fit:contain;margin:18px 0}
      .loader-inner[data-theme-wordmark-ready="1"] .theme-loader-wordmark{display:block}
      .loader-inner[data-theme-wordmark-ready="1"] .loader-mark{display:none}
      @media(max-width:900px){.theme-wordmark-image{max-width:112px;height:30px}.theme-loader-wordmark{max-width:min(300px,74vw);max-height:82px}}
    `;
    document.head.appendChild(style);
  }

  function imageWithFallback(src, className, alt, ready, failed) {
    const image = document.createElement('img');
    image.className = className;
    image.alt = alt;
    image.decoding = 'async';
    image.addEventListener('load', () => ready?.(image), { once:true });
    image.addEventListener('error', () => { image.remove(); failed?.(); }, { once:true });
    image.src = src;
    return image;
  }

  function applyHeader(src, siteName) {
    const brand = document.querySelector('.brand');
    if (!brand) return;
    brand.querySelector('.theme-wordmark-image')?.remove();
    delete brand.dataset.themeWordmarkReady;
    const image = imageWithFallback(src,'theme-wordmark-image',siteName || 'BRVTAL',() => {
      brand.dataset.themeWordmarkReady = '1';
    },() => { delete brand.dataset.themeWordmarkReady; });
    brand.prepend(image);
  }

  function applyLoader(src) {
    const inner = document.querySelector('.loader-inner');
    const mark = inner?.querySelector('.loader-mark');
    if (!inner || !mark) return;
    inner.querySelector('.theme-loader-wordmark')?.remove();
    delete inner.dataset.themeWordmarkReady;
    const image = imageWithFallback(src,'theme-loader-wordmark','',() => {
      image.setAttribute('aria-hidden','true');
      inner.dataset.themeWordmarkReady = '1';
    },() => { delete inner.dataset.themeWordmarkReady; });
    mark.before(image);
  }

  async function boot() {
    try { await window.BRVTALThemeReady; } catch (_) {}
    if (!window.BRVTALThemeRuntime?.resolveSettings) return false;
    const settings = await window.BRVTALThemeRuntime.resolveSettings();
    const branding = settings?.theme?.branding || {};
    const src = safeAsset(branding.wordmark);
    if (!src) return false;
    ensureStyle();
    applyHeader(src,String(branding.siteName || 'BRVTAL'));
    applyLoader(src);
    return true;
  }

  window.BRVTALThemeWordmarkReady = boot().catch(error => {
    console.warn('[BRVTAL] Wordmark kept text fallback.', error);
    return false;
  });
})();
