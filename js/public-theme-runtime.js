(() => {
  'use strict';

  const html = document.documentElement;
  const queryAll = (selector, root = document) => [...root.querySelectorAll(selector)];
  let mobileQuery = null;
  let currentBranding = null;
  let menuObserver = null;

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

  function safeColor(value, fallback = '') {
    const raw = String(value || '').trim();
    return raw && window.CSS?.supports?.('color', raw) ? raw : fallback;
  }

  function safeCssValue(property, value, fallback = '') {
    const raw = String(value || '').trim();
    return raw && window.CSS?.supports?.(property, raw) ? raw : fallback;
  }

  function safeFont(value, fallback = '') {
    const raw = String(value || '').trim();
    return /^[a-z0-9\s,"'_-]{1,180}$/i.test(raw) ? raw : fallback;
  }

  function bool(value, fallback = true) {
    return typeof value === 'boolean' ? value : fallback;
  }

  function ensureRuntimeStyle() {
    if (document.getElementById('brvtal-theme-runtime-style')) return;
    const style = document.createElement('style');
    style.id = 'brvtal-theme-runtime-style';
    style.textContent = `
      html[data-theme-nav-fixed="0"] .nav{position:absolute}
      html[data-theme-nav-transparent="0"] .nav{background:var(--theme-surface,var(--bg));}
      html[data-theme-nav-blur="0"] .nav{backdrop-filter:none;-webkit-backdrop-filter:none}
      html[data-theme-brand-position="center"] .brand{position:absolute;left:50%;transform:translateX(-50%);align-items:center;text-align:center}
      .theme-brand-image{display:block;width:auto;max-width:132px;height:36px;object-fit:contain;object-position:left center}
      .brand[data-theme-logo="1"]>span[data-site-name]{display:none}
      html[data-theme-brand-position="center"] .theme-brand-image{object-position:center}
      html[data-theme-scene-indicator="0"] .nav-center{display:none!important}
      html[data-theme-sound-toggle="0"] #soundToggle{display:none!important}
      html[data-theme-grain="0"] #fxCanvas{display:none!important}
      html[data-theme-scanlines="0"] .hero-scan,
      html[data-theme-scanlines="0"] .genesis-scanline,
      html[data-theme-scanlines="0"] .genesis-noise,
      html[data-theme-scanlines="0"] .manifesto-noise,
      html[data-theme-scanlines="0"] .loader-noise{display:none!important}
      html[data-theme-glitch="0"] .hero-title:after,
      html[data-theme-glitch="0"] .loader-mark:after,
      html[data-theme-glitch="0"] .genesis-copy h2:after{display:none!important;animation:none!important;text-shadow:none!important}
      html[data-theme-cursor="0"] body{cursor:auto!important}
      html[data-theme-cursor="0"] .cursor,
      html[data-theme-cursor="0"] .cursor-label{display:none!important}
      html[data-theme-magnetic="0"] .magnetic{transform:none!important}
      html[data-theme-motion="subtle"] [class*="glitch"],html[data-theme-motion="subtle"] .audio-bg{animation-duration:8s!important}
      html[data-theme-motion="minimal"] [class*="glitch"],html[data-theme-motion="minimal"] .audio-bg{animation:none!important}
      html[data-theme-motion="reduced"] *,html[data-theme-motion="reduced"] *:before,html[data-theme-motion="reduced"] *:after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important;scroll-behavior:auto!important}
      html[data-theme-menu-style="slide"] .menu-panel{top:0;left:auto;width:min(560px,100vw);transform:translateX(102%)!important;transition:transform .55s cubic-bezier(.16,1,.3,1)}
      html[data-theme-menu-style="slide"][data-theme-menu-open="1"] .menu-panel{transform:translateX(0)!important}
      html[data-theme-menu-style="dropdown"] .menu-panel{bottom:auto;min-height:min(720px,86vh);transform:translateY(-102%)!important;transition:transform .55s cubic-bezier(.16,1,.3,1)}
      html[data-theme-menu-style="dropdown"][data-theme-menu-open="1"] .menu-panel{transform:translateY(0)!important}
      html[data-theme-menu-style="dropdown"] .menu-panel-inner{padding-top:105px}
      html[data-theme-menu-style="dropdown"] .menu-panel nav a{font-size:clamp(42px,7vw,96px)}
      .theme-preloader-logo{display:block;max-width:min(390px,72vw);max-height:110px;object-fit:contain;margin:20px 0 14px}
      .loader-inner[data-theme-wordmark="1"] .loader-mark{display:none}
      html{--theme-display-font:"Barlow Condensed",Arial,sans-serif;--theme-body-font:"Barlow Condensed",Arial,sans-serif;--theme-mono-font:"Space Mono",monospace}
      body{font-family:var(--theme-body-font)}
      h1,h2,h3,h4,.brand,.menu-panel nav a,.loader-mark{font-family:var(--theme-display-font)}
      .mono,.eyebrow,.hero-sub,.hero-bottom,.scene-index,.nav-center,.sound,.menu,.event-status,.set-num{font-family:var(--theme-mono-font)}
      .hero-title{font-size:var(--theme-h1,clamp(100px,22vw,340px));letter-spacing:var(--theme-tracking,-.07em)}
      body{font-size:var(--theme-body-size,16px)}
      @media(max-width:900px){.theme-brand-image{max-width:112px;height:30px}.theme-preloader-logo{max-width:min(300px,74vw);max-height:82px}}
    `;
    document.head.appendChild(style);
  }

  function setToken(name, value) {
    if (value) html.style.setProperty(name, value);
  }

  function loadControlledImage(image, src, onReady, onError) {
    image.onload = () => onReady?.(image);
    image.onerror = () => onError?.(image);
    image.src = src;
    if (image.complete && image.naturalWidth > 0) onReady?.(image);
  }

  function updateBrandLogo() {
    const branding = currentBranding || {};
    if (!mobileQuery) mobileQuery = window.matchMedia('(max-width: 900px)');
    const selectedVisualLogo = safeAsset((mobileQuery.matches ? branding.mobileLogo : '') || branding.logo);
    const mainLogo = safeAsset(branding.logo);
    const wordmark = safeAsset(branding.wordmark);
    const headerLogo = wordmark || selectedVisualLogo;

    const brand = document.querySelector('.brand');
    if (brand) {
      let image = brand.querySelector('.theme-brand-image');
      delete brand.dataset.themeLogo;
      if (headerLogo) {
        if (!image) {
          image = document.createElement('img');
          image.className = 'theme-brand-image';
          image.alt = String(branding.siteName || 'BRVTAL');
          brand.prepend(image);
        }
        loadControlledImage(image, headerLogo, () => {
          brand.dataset.themeLogo = '1';
        }, failed => {
          delete brand.dataset.themeLogo;
          failed.remove();
        });
      } else {
        image?.remove();
      }
    }

    const heroLogo = document.querySelector('.hero-logo');
    if (heroLogo && selectedVisualLogo) {
      heroLogo.src = selectedVisualLogo;
      heroLogo.removeAttribute('srcset');
      heroLogo.alt = String(branding.siteName || 'BRVTAL') + ' logo';
      heroLogo.closest('picture')?.querySelectorAll('source').forEach(source => { source.srcset = selectedVisualLogo; source.sizes = '100vw'; });
    } else if (heroLogo && mainLogo) {
      heroLogo.src = mainLogo;
    }
  }

  function wireMenuObserver(style) {
    const panel = document.getElementById('menuPanel');
    html.dataset.themeMenuStyle = ['fullscreen','dropdown','slide'].includes(style) ? style : 'fullscreen';
    if (!panel) return;
    const sync = () => { html.dataset.themeMenuOpen = panel.getAttribute('aria-hidden') === 'false' ? '1' : '0'; };
    menuObserver?.disconnect();
    menuObserver = new MutationObserver(sync);
    menuObserver.observe(panel, { attributes:true, attributeFilter:['aria-hidden'] });
    sync();
  }

  function applyBranding(branding) {
    currentBranding = branding || {};
    const siteName = String(currentBranding.siteName || '').trim();
    const tagline = String(currentBranding.tagline || '').trim();
    if (siteName) {
      queryAll('[data-site-name]').forEach(node => { node.textContent = siteName; });
      const heroTitle = document.querySelector('.hero-title');
      if (heroTitle) { heroTitle.textContent = siteName; heroTitle.dataset.text = siteName; }
      const loaderMark = document.querySelector('.loader-mark');
      if (loaderMark) { loaderMark.textContent = siteName; loaderMark.dataset.text = siteName; }
    }
    if (tagline) queryAll('[data-site-tagline]').forEach(node => { node.textContent = tagline; });

    updateBrandLogo();
    if (!mobileQuery) mobileQuery = window.matchMedia('(max-width: 900px)');
    if (!mobileQuery.__brvtalThemeBound) {
      mobileQuery.addEventListener?.('change', updateBrandLogo);
      mobileQuery.__brvtalThemeBound = true;
    }

    const favicon = safeAsset(currentBranding.favicon);
    if (favicon) {
      let link = document.querySelector('link[rel~="icon"][data-theme-favicon]') || document.querySelector('link[rel~="icon"]');
      if (!link) { link = document.createElement('link'); link.rel = 'icon'; document.head.appendChild(link); }
      link.dataset.themeFavicon = '1';
      link.href = favicon;
    }

    const wordmark = safeAsset(currentBranding.wordmark);
    const preloadLogo = wordmark || safeAsset(currentBranding.preloaderLogo || currentBranding.logo);
    const loaderInner = document.querySelector('.loader-inner');
    if (loaderInner) {
      let image = loaderInner.querySelector('.theme-preloader-logo');
      delete loaderInner.dataset.themeWordmark;
      if (preloadLogo) {
        if (!image) {
          image = document.createElement('img');
          image.className = 'theme-preloader-logo';
          image.alt = '';
          image.setAttribute('aria-hidden', 'true');
          loaderInner.querySelector('.loader-mark')?.after(image);
        }
        loadControlledImage(image, preloadLogo, () => {
          if (wordmark) loaderInner.dataset.themeWordmark = '1';
        }, failed => {
          delete loaderInner.dataset.themeWordmark;
          failed.remove();
        });
      } else {
        image?.remove();
      }
    }
  }

  function applyColors(colors) {
    const c = colors || {};
    const bg = safeColor(c.bg);
    const text = safeColor(c.text);
    const muted = safeColor(c.muted);
    const primary = safeColor(c.primary);
    const accent = safeColor(c.accent);
    const border = safeColor(c.border);
    const surface = safeColor(c.surface);
    setToken('--bg', bg);
    setToken('--fg', text);
    setToken('--muted', muted);
    setToken('--red', primary);
    setToken('--acid', accent);
    setToken('--signal', accent);
    setToken('--line', border);
    setToken('--theme-surface', surface);
    const themeColor = document.querySelector('meta[name="theme-color"]');
    if (themeColor && bg) themeColor.setAttribute('content', bg);
  }

  function applyTypography(typography) {
    const t = typography || {};
    setToken('--theme-display-font', safeFont(t.display));
    setToken('--theme-body-font', safeFont(t.body));
    setToken('--theme-mono-font', safeFont(t.mono));
    setToken('--theme-h1', safeCssValue('font-size', t.h1));
    setToken('--theme-body-size', safeCssValue('font-size', t.bodySize));
    setToken('--theme-tracking', safeCssValue('letter-spacing', t.tracking));
  }

  function applyNavigation(navigation, sound) {
    const n = navigation || {};
    html.dataset.themeNavFixed = bool(n.fixed, true) ? '1' : '0';
    html.dataset.themeNavTransparent = bool(n.transparentHero, true) ? '1' : '0';
    html.dataset.themeNavBlur = bool(n.blur, true) ? '1' : '0';
    html.dataset.themeBrandPosition = n.logoPosition === 'center' ? 'center' : 'left';
    html.dataset.themeSceneIndicator = bool(n.sceneIndicator, true) ? '1' : '0';
    html.dataset.themeSoundToggle = bool(n.soundToggle, true) && bool(sound?.enabled, true) ? '1' : '0';
    wireMenuObserver(String(n.menuStyle || 'fullscreen'));
  }

  function applyEffects(effects) {
    const e = effects || {};
    html.dataset.themeGrain = bool(e.grain, true) ? '1' : '0';
    html.dataset.themeScanlines = bool(e.scanlines, true) ? '1' : '0';
    html.dataset.themeGlitch = bool(e.glitch, true) ? '1' : '0';
    html.dataset.themeCursor = bool(e.cursor, true) ? '1' : '0';
    html.dataset.themeMagnetic = bool(e.magnetic, true) ? '1' : '0';
    html.dataset.themeMotion = ['brvtal','subtle','minimal','reduced'].includes(String(e.motion || '')) ? String(e.motion) : 'brvtal';
  }

  function applyTheme(theme) {
    if (!theme || typeof theme !== 'object') return false;
    ensureRuntimeStyle();
    html.dataset.brvtalTheme = String(theme.slug || 'core').slice(0, 60);
    applyColors(theme.colors);
    applyTypography(theme.typography);
    applyNavigation(theme.navigation, theme.sound);
    applyEffects(theme.effects);
    applyBranding(theme.branding);
    // Canonical SEO is rendered server-side. Legacy theme.seo values are kept
    // in storage for round-trip compatibility but never mutate document meta.
    window.dispatchEvent(new CustomEvent('brvtal:theme-applied', { detail:{ slug:html.dataset.brvtalTheme } }));
    return true;
  }

  function settingsFromPayload(source) {
    const payload = source?.payload ?? source;
    const root = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
    return root?.settings && typeof root.settings === 'object' ? root.settings : {};
  }

  async function resolveSettings() {
    if (window.BRVTALPublicDataPromise) {
      try { return settingsFromPayload(await window.BRVTALPublicDataPromise); }
      catch (_) {}
    }
    const response = await fetch('/api/public.php', { credentials:'same-origin', headers:{ Accept:'application/json' }, cache:'no-store' });
    if (!response.ok) throw new Error('THEME_PUBLIC_API_' + response.status);
    return settingsFromPayload(await response.json());
  }

  window.BRVTALThemeRuntime = { apply:applyTheme, resolveSettings };
  window.BRVTALThemeReady = resolveSettings()
    .then(settings => applyTheme(settings.theme || null))
    .catch(error => {
      console.warn('[BRVTAL] Theme runtime kept static fallbacks.', error);
      return false;
    });
})();
