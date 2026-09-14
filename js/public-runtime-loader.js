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
  const coreScripts = ['js/app.js', 'js/archive.js', 'js/public-media.js'];
  const enhancementScripts = [
    'js/menu-accessibility.js',
    'js/input-accessibility.js',
    'js/mobile-events.js',
    'js/hero-slider.js',
    'js/public-discovery-url-state.js'
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

  async function boot() {
    let mode = 'enhanced';

    if (coarsePointer) {
      mode = 'touch-lite';
      document.documentElement.dataset.motionRuntime = mode;
      await loadScript(localUrl('js/mobile-performance.js'));
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

    await loadSequence(coreScripts.map(localUrl));
    await loadSequence(enhancementScripts.map(localUrl));
    return { mode, coarsePointer, reducedMotion };
  }

  window.BRVTALRuntimeReady = boot().catch(error => {
    document.documentElement.dataset.motionRuntime = 'error';
    console.error('[BRVTAL] Public runtime failed to initialize.', error);
    throw error;
  });
})();
