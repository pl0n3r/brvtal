(() => {
  'use strict';

  let lenisInstance = null;
  let resumeLenisAfterUnlock = false;
  let scrollX = 0;
  let scrollY = 0;
  let previousStyles = null;
  let viewportFrame = 0;
  const owners = new Set();

  const OriginalLenis = window.Lenis;
  if (typeof OriginalLenis === 'function') {
    window.Lenis = new Proxy(OriginalLenis, {
      construct(target, args) {
        const instance = Reflect.construct(target, args, target);
        lenisInstance = instance;
        return instance;
      }
    });
  }

  const rememberStyles = () => ({
    htmlOverflow: document.documentElement.style.overflow,
    bodyPosition: document.body.style.position,
    bodyTop: document.body.style.top,
    bodyLeft: document.body.style.left,
    bodyRight: document.body.style.right,
    bodyWidth: document.body.style.width,
    bodyOverflow: document.body.style.overflow
  });

  const restoreStyles = styles => {
    document.documentElement.style.overflow = styles.htmlOverflow;
    document.body.style.position = styles.bodyPosition;
    document.body.style.top = styles.bodyTop;
    document.body.style.left = styles.bodyLeft;
    document.body.style.right = styles.bodyRight;
    document.body.style.width = styles.bodyWidth;
    document.body.style.overflow = styles.bodyOverflow;
  };

  const refreshMotionRuntime = () => {
    if (lenisInstance && typeof lenisInstance.resize === 'function') {
      lenisInstance.resize();
    }
    if (window.ScrollTrigger && typeof window.ScrollTrigger.refresh === 'function') {
      window.ScrollTrigger.refresh();
    }
  };

  const syncViewport = () => {
    cancelAnimationFrame(viewportFrame);
    viewportFrame = requestAnimationFrame(() => {
      if (owners.size > 0) return;
      refreshMotionRuntime();
    });
  };

  const freezeDocument = () => {
    scrollX = window.scrollX;
    scrollY = window.scrollY;
    previousStyles = rememberStyles();

    document.documentElement.classList.add('public-scroll-locked');
    document.body.classList.add('public-scroll-locked');
    document.documentElement.style.overflow = 'hidden';
    document.body.style.position = 'fixed';
    document.body.style.top = `-${scrollY}px`;
    document.body.style.left = `-${scrollX}px`;
    document.body.style.right = '0';
    document.body.style.width = '100%';
    document.body.style.overflow = 'hidden';

    resumeLenisAfterUnlock = Boolean(
      lenisInstance &&
      typeof lenisInstance.stop === 'function' &&
      !lenisInstance.isStopped
    );
    if (lenisInstance && typeof lenisInstance.stop === 'function') {
      lenisInstance.stop();
    }
  };

  const restoreDocument = () => {
    document.documentElement.classList.remove('public-scroll-locked');
    document.body.classList.remove('public-scroll-locked');
    if (previousStyles) restoreStyles(previousStyles);
    previousStyles = null;

    window.scrollTo(scrollX, scrollY);
    refreshMotionRuntime();
    if (resumeLenisAfterUnlock && lenisInstance && typeof lenisInstance.start === 'function') {
      lenisInstance.start();
    }
    resumeLenisAfterUnlock = false;
  };

  const normalizeOwner = owner => String(owner || 'anonymous');

  const lock = owner => {
    const key = normalizeOwner(owner);
    if (owners.has(key)) return;
    const wasUnlocked = owners.size === 0;
    owners.add(key);
    if (wasUnlocked) freezeDocument();
  };

  const unlock = owner => {
    const key = normalizeOwner(owner);
    if (!owners.delete(key)) return;
    if (owners.size === 0) restoreDocument();
  };

  const manager = {
    lock,
    unlock,
    syncViewport,
    isLocked: () => owners.size > 0,
    has: owner => owners.has(normalizeOwner(owner))
  };
  window.BRVTALScrollLock = manager;

  window.addEventListener('resize', syncViewport, { passive: true });
  window.addEventListener('orientationchange', syncViewport, { passive: true });

  const initMenu = () => {
    const panel = document.getElementById('menuPanel');
    if (!panel) return;

    const sync = () => {
      const open = panel.getAttribute('aria-hidden') !== 'true';
      document.documentElement.classList.toggle('menu-scroll-locked', open);
      document.body.classList.toggle('menu-scroll-locked', open);
      if (open) manager.lock('menu');
      else manager.unlock('menu');
    };

    new MutationObserver(sync).observe(panel, {
      attributes: true,
      attributeFilter: ['aria-hidden']
    });
    sync();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMenu, { once: true });
  } else {
    initMenu();
  }
})();
