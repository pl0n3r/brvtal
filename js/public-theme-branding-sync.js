(() => {
  'use strict';

  let branding = null;
  let settings = null;
  const mobileQuery = window.matchMedia('(max-width: 900px)');

  function safeAsset(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, location.href);
      if (!/^https?:$/i.test(url.protocol)) return '';
      return url.href;
    } catch (_) {
      return '';
    }
  }

  function versionedAsset(value) {
    const safe = safeAsset(value);
    if (!safe) return '';
    try {
      const url = new URL(safe);
      const version = String(window.BRVTAL_PUBLIC_VERSION || '').trim();
      if (version && url.origin === location.origin && !url.searchParams.has('v')) {
        url.searchParams.set('v', version);
      }
      return url.href;
    } catch (_) {
      return safe;
    }
  }

  function selectedLogo() {
    if (!branding) return '';
    return safeAsset((mobileQuery.matches ? branding.mobileLogo : '') || branding.logo);
  }

  function syncHeroLogo() {
    const logo = selectedLogo();
    if (!logo) return;

    const heroLogo = document.querySelector('.hero-logo');
    if (heroLogo) {
      heroLogo.src = logo;
      heroLogo.removeAttribute('srcset');
      heroLogo.closest('picture')?.querySelectorAll('source').forEach(source => {
        source.srcset = logo;
        source.sizes = '100vw';
      });
    }

    const glitch = document.querySelector('.hero-logo-glitch');
    if (glitch) {
      glitch.style.backgroundImage = `url("${logo}")`;
      glitch.dataset.themeLogo = '1';
    }
  }

  function syncFavicon() {
    const favicon = versionedAsset(branding?.favicon);
    if (!favicon) return;
    let link = document.querySelector('link[rel~="icon"][data-theme-favicon]') || document.querySelector('link[rel~="icon"]');
    if (!link) {
      link = document.createElement('link');
      link.rel = 'icon';
      document.head.appendChild(link);
    }
    link.dataset.themeFavicon = '1';
    link.href = favicon;
  }

  function syncPreloaderLogo() {
    const logo = versionedAsset(branding?.wordmark || branding?.preloaderLogo || branding?.logo);
    if (!logo) return;
    const loaderInner = document.querySelector('.loader-inner');
    if (!loaderInner) return;
    let image = loaderInner.querySelector('.theme-preloader-logo');
    if (!image) {
      image = document.createElement('img');
      image.className = 'theme-preloader-logo';
      image.alt = '';
      image.setAttribute('aria-hidden', 'true');
      loaderInner.querySelector('.loader-mark')?.after(image);
    }
    image.onload = () => {
      if (branding?.wordmark) loaderInner.dataset.themeWordmark = '1';
    };
    image.onerror = () => {
      delete loaderInner.dataset.themeWordmark;
      image.remove();
    };
    image.src = logo;
  }

  function syncSocial() {
    const spotify = safeAsset(settings?.social?.spotify || settings?.social?.spotify_url || '');
    document.querySelectorAll('[data-social="spotify"]').forEach(link => {
      if (spotify) {
        link.href = spotify;
        link.hidden = false;
      } else {
        link.hidden = true;
      }
    });
  }

  function reconcileThemeAuthority() {
    // app.js still supports legacy appearance defaults for old installations.
    // Re-applying the active theme after that async pass keeps Theme Studio as
    // the single visual authority without adding another public script/request.
    if (settings?.theme) window.BRVTALThemeRuntime?.apply?.(settings.theme);
  }

  function sync() {
    syncHeroLogo();
    syncFavicon();
    syncPreloaderLogo();
    syncSocial();
  }

  async function boot() {
    try { await window.BRVTALThemeReady; } catch (_) {}
    if (!window.BRVTALThemeRuntime?.resolveSettings) return false;
    settings = await window.BRVTALThemeRuntime.resolveSettings();
    branding = settings?.theme?.branding || null;
    if (branding) sync();
    else syncSocial();
    mobileQuery.addEventListener?.('change', syncHeroLogo);
    window.setTimeout(reconcileThemeAuthority, 260);
    return true;
  }

  window.BRVTALThemeBrandingSyncReady = boot().catch(error => {
    console.warn('[BRVTAL] Theme branding sync kept static fallbacks.', error);
    return false;
  });
})();
