(() => {
  'use strict';

  const currentScript = document.currentScript;
  const version = (() => {
    try {
      return new URL(currentScript?.src || '', location.href).searchParams.get('v') || '';
    } catch (_) {
      return '';
    }
  })();

  window.BRVTAL_PUBLIC_VERSION = version;

  const localUrl = path => version ? `${path}?v=${encodeURIComponent(version)}` : path;
  const LOCALE_EVENT = 'brvtal:localechange';
  const SAFE_LOCALES = new Set(['es', 'en']);

  const safePublicText = value => {
    if (typeof value !== 'string') return '';
    const text = value.replace(/\r\n?/g, '\n').trim();
    if (!text || text.length > 10000) return '';
    if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(text)) return '';
    if (text.includes('<') || text.includes('>')) return '';
    return text;
  };

  const activeLocale = () => {
    const locale = String(document.documentElement.dataset.locale || '').toLowerCase();
    return SAFE_LOCALES.has(locale) ? locale : 'es';
  };

  const validLocaleConfig = locale => {
    const config = window.BRVTALI18N;
    return Boolean(
      config?.canonicalLocale === 'es'
      && config?.defaultLocale === 'es'
      && Array.isArray(config?.availableLocales)
      && config.availableLocales.includes(locale)
      && config?.catalog
      && typeof config.catalog === 'object'
    );
  };

  const applyLocalizedNode = (node, locale) => {
    if (!(node instanceof Element)) return;
    const targets = [];
    if (node.matches?.('[data-i18n-key]')) targets.push(node);
    targets.push(...(node.querySelectorAll?.('[data-i18n-key]') || []));
    const catalog = validLocaleConfig(locale) ? window.BRVTALI18N.catalog?.[locale] : null;
    if (!catalog || typeof catalog !== 'object') return;
    targets.forEach(element => {
      const key = String(element.dataset.i18nKey || '').trim();
      const value = safePublicText(catalog[key]);
      if (key && value) element.textContent = value;
    });
  };

  let observedLocale = null;
  const refreshLocaleRuntime = ({announce = true, root = document.documentElement} = {}) => {
    const locale = activeLocale();
    applyLocalizedNode(root, locale);
    if (announce && locale !== observedLocale) {
      observedLocale = locale;
      window.dispatchEvent(new CustomEvent(LOCALE_EVENT, {detail:{locale}}));
    }
    return locale;
  };

  const localeObserver = new MutationObserver(records => {
    let localeChanged = false;
    records.forEach(record => {
      if (record.type === 'attributes' && record.attributeName === 'data-locale') {
        localeChanged = true;
      }
      record.addedNodes?.forEach(node => applyLocalizedNode(node, activeLocale()));
    });
    if (localeChanged) refreshLocaleRuntime();
  });
  localeObserver.observe(document.documentElement, {
    attributes:true,
    attributeFilter:['data-locale'],
    childList:true,
    subtree:true
  });

  window.BRVTALPublicLocaleRuntime = {
    eventName:LOCALE_EVENT,
    currentLocale:activeLocale,
    refresh:refreshLocaleRuntime
  };

  const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const motionScripts = [
    'https://cdn.jsdelivr.net/npm/gsap@3.13.0/dist/gsap.min.js',
    'https://cdn.jsdelivr.net/npm/gsap@3.13.0/dist/ScrollTrigger.min.js',
    'https://cdn.jsdelivr.net/npm/lenis@1.3.4/dist/lenis.min.js'
  ];
  const coreScripts = ['js/menu-scroll-lock.js', 'js/app.js', 'js/public-roster.js', 'js/public-sets-library.js', 'js/public-transmissions.js', 'js/archive.js', 'js/public-media.js'];
  const enhancementScripts = [
    'js/menu-accessibility.js',
    'js/input-accessibility.js',
    'js/mobile-events.js',
    'js/hero-slider.js',
    'js/public-discovery-url-state.js',
    'js/public-canonical-navigation.js',
    'js/public-contact.js',
    'js/public-theme-runtime.js',
    'js/public-theme-branding-sync.js'
  ];

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src;
      script.async = false;
      script.onload = () => resolve(src);
      script.onerror = () => reject(new Error(`SCRIPT_LOAD_FAILED:${src}`));
      document.body.appendChild(script);
    });
  }

  async function loadSequence(sources) {
    for (const source of sources) await loadScript(source);
  }

  async function loadSequenceResilient(sources, phase) {
    const failures = [];
    for (const source of sources) {
      try {
        await loadScript(source);
      } catch (error) {
        failures.push({ phase, source, error });
        console.warn(`[BRVTAL] ${phase} module unavailable; continuing degraded runtime.`, source, error);
      }
    }
    return failures;
  }

  function revealStaticFallback(reason = 'runtime-degraded') {
    document.getElementById('loader')?.remove();
    document.documentElement.dataset.runtimeFallback = reason;
  }

  async function boot() {
    let mode = 'enhanced';
    const failures = [];

    if (coarsePointer) {
      mode = 'touch-lite';
      document.documentElement.dataset.motionRuntime = mode;
      failures.push(...await loadSequenceResilient([localUrl('js/mobile-performance.js')], 'mobile-performance'));
    } else if (reducedMotion) {
      mode = 'reduced-lite';
      document.documentElement.dataset.motionRuntime = mode;
    } else {
      try {
        await loadSequence(motionScripts);
      } catch (error) {
        mode = 'fallback';
        document.documentElement.dataset.motionRuntime = mode;
        console.warn('[BRVTAL] Enhanced motion unavailable; continuing with core runtime.', error);
      }
    }

    if (!document.documentElement.dataset.motionRuntime) {
      document.documentElement.dataset.motionRuntime = mode;
    }

    failures.push(...await loadSequenceResilient(coreScripts.map(localUrl), 'core'));
    failures.push(...await loadSequenceResilient(enhancementScripts.map(localUrl), 'enhancement'));

    if (failures.length) {
      document.documentElement.dataset.runtimeIntegrity = 'degraded';
      revealStaticFallback('module-load-failure');
    } else {
      document.documentElement.dataset.runtimeIntegrity = 'ok';
    }

    refreshLocaleRuntime();

    return {
      mode,
      coarsePointer,
      reducedMotion,
      failures: failures.map(item => ({ phase:item.phase, source:item.source, message:item.error?.message || 'SCRIPT_LOAD_FAILED' }))
    };
  }

  window.BRVTALRuntimeReady = boot().catch(error => {
    document.documentElement.dataset.motionRuntime = 'error';
    document.documentElement.dataset.runtimeIntegrity = 'error';
    revealStaticFallback('boot-error');
    console.error('[BRVTAL] Public runtime failed to initialize; static fallback revealed.', error);
    return { mode:'error', coarsePointer, reducedMotion, failures:[{ phase:'boot', source:'runtime', message:error?.message || 'BOOT_FAILED' }] };
  });
})();
