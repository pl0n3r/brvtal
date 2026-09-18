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
  const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const motionScripts = [
    'https://cdn.jsdelivr.net/npm/gsap@3.13.0/dist/gsap.min.js',
    'https://cdn.jsdelivr.net/npm/gsap@3.13.0/dist/ScrollTrigger.min.js',
    'https://cdn.jsdelivr.net/npm/lenis@1.3.4/dist/lenis.min.js'
  ];
  const coreScripts = ['js/menu-scroll-lock.js', 'js/app.js', 'js/public-roster.js', 'js/public-sets-library.js', 'js/archive.js', 'js/public-media.js', 'js/public-transmissions.js'];
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
