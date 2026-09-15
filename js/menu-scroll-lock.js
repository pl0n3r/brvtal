(() => {
  'use strict';

  let lenisInstance = null;
  let resumeLenisAfterUnlock = false;
  let locked = false;
  let scrollX = 0;
  let scrollY = 0;
  let previousStyles = null;

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

  const lock = () => {
    if (locked) return;
    locked = true;
    scrollX = window.scrollX;
    scrollY = window.scrollY;
    previousStyles = rememberStyles();

    document.documentElement.classList.add('menu-scroll-locked');
    document.body.classList.add('menu-scroll-locked');
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

  const unlock = () => {
    if (!locked) return;
    locked = false;

    document.documentElement.classList.remove('menu-scroll-locked');
    document.body.classList.remove('menu-scroll-locked');
    if (previousStyles) restoreStyles(previousStyles);
    previousStyles = null;

    window.scrollTo(scrollX, scrollY);
    if (lenisInstance && typeof lenisInstance.resize === 'function') {
      lenisInstance.resize();
    }
    if (resumeLenisAfterUnlock && lenisInstance && typeof lenisInstance.start === 'function') {
      lenisInstance.start();
    }
    resumeLenisAfterUnlock = false;
  };

  const init = () => {
    const panel = document.getElementById('menuPanel');
    if (!panel) return;

    const sync = () => {
      if (panel.getAttribute('aria-hidden') === 'true') unlock();
      else lock();
    };

    new MutationObserver(sync).observe(panel, {
      attributes: true,
      attributeFilter: ['aria-hidden']
    });
    sync();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
