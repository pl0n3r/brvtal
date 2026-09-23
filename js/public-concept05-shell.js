(() => {
  'use strict';

  const root = document.documentElement;
  const bottomNav = document.querySelector('.c5-bottom-nav');
  const navLinks = [...document.querySelectorAll('[data-c5-nav-section]')];
  const visible = new Map();
  const sectionMap = new Map([
    ['events', 'events'],
    ['artists', 'artists'],
    ['sets', 'sets'],
    ['media', ''],
    ['transmissions', 'transmissions'],
    ['connected', 'connected'],
  ]);

  function setActive(section) {
    navLinks.forEach(link => {
      const active = link.dataset.c5NavSection === section;
      if (active) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
  }

  function activeFromHash() {
    const hash = String(location.hash || '').replace(/^#/, '');
    if (!sectionMap.has(hash)) return false;
    setActive(sectionMap.get(hash));
    return true;
  }

  function chooseVisibleSection() {
    let bestSection = '';
    let bestRatio = 0;
    visible.forEach((ratio, id) => {
      if (ratio <= bestRatio) return;
      bestRatio = ratio;
      bestSection = sectionMap.get(id) || '';
    });
    setActive(bestSection);
  }

  function observeSections() {
    if (!('IntersectionObserver' in window)) {
      activeFromHash();
      return;
    }
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        const id = entry.target.id;
        if (!sectionMap.has(id)) return;
        if (entry.isIntersecting) visible.set(id, entry.intersectionRatio);
        else visible.delete(id);
      });
      chooseVisibleSection();
    }, {
      rootMargin: '-20% 0px -48% 0px',
      threshold: [0.05, 0.2, 0.45, 0.7],
    });
    sectionMap.forEach((_section, id) => {
      const element = document.getElementById(id);
      if (element) observer.observe(element);
    });
  }

  function syncMenuState() {
    if (!bottomNav) return;
    const locked = root.classList.contains('menu-scroll-locked');
    bottomNav.dataset.menuState = locked ? 'hidden' : 'visible';
    bottomNav.setAttribute('aria-hidden', locked ? 'true' : 'false');
    bottomNav.inert = locked;
  }

  function watchMenuState() {
    syncMenuState();
    const observer = new MutationObserver(syncMenuState);
    observer.observe(root, {attributes:true, attributeFilter:['class']});
  }

  function safePageSlug(value) {
    const slug = String(value || '').trim().toLowerCase();
    return /^[a-z0-9-]{1,190}$/.test(slug) ? slug : '';
  }

  function applyPages(data) {
    const privacy = document.querySelector('[data-footer-privacy]');
    if (!privacy) return false;
    const pages = Array.isArray(data?.pages) ? data.pages : [];
    const preferred = ['privacy', 'privacy-policy', 'privacy-notice'];
    let selected = null;
    for (const slug of preferred) {
      selected = pages.find(page => safePageSlug(page?.slug) === slug);
      if (selected) break;
    }
    if (!selected) {
      privacy.hidden = true;
      privacy.removeAttribute('href');
      return false;
    }
    const slug = safePageSlug(selected.slug);
    if (!slug) return false;
    privacy.href = '/pages/' + encodeURIComponent(slug);
    privacy.hidden = false;
    return true;
  }

  async function waitForPublicData(maxWaitMs) {
    const started = Date.now();
    while (!window.BRVTALPublicDataPromise && Date.now() - started < maxWaitMs) {
      await new Promise(resolve => window.setTimeout(resolve, 80));
    }
    return window.BRVTALPublicDataPromise || null;
  }

  async function hydratePages() {
    const request = await waitForPublicData(4500);
    if (!request) {
      root.dataset.c5ShellData = 'static';
      return false;
    }
    try {
      const result = await request;
      const data = result?.payload?.data || result?.data || result?.payload || result || {};
      const hydrated = applyPages(data);
      root.dataset.c5ShellData = hydrated ? 'managed' : 'managed-no-privacy';
      return hydrated;
    } catch (error) {
      root.dataset.c5ShellData = 'unavailable';
      console.warn('[BRVTAL] Concept 05 shell kept fail-closed footer links.', error);
      return false;
    }
  }

  function syncYear() {
    document.querySelectorAll('[data-footer-year]').forEach(node => {
      node.textContent = String(new Date().getFullYear());
    });
  }

  function init() {
    syncYear();
    watchMenuState();
    observeSections();
    window.addEventListener('hashchange', activeFromHash);
    hydratePages();
    root.dataset.publicShell = 'concept05';
    return true;
  }

  window.BRVTALConcept05Shell = {setActive, applyPages, syncMenuState, init};
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, {once:true});
  } else {
    init();
  }
})();