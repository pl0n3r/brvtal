(() => {
  'use strict';

  const CONSENT_KEY = 'brvtal.analytics.choice.v1';
  const EVENT_RE = /^brvtal_[a-z0-9_]{1,56}$/;
  const TEXT_KEYS = new Set([
    'page_type', 'content_type', 'content_slug', 'content_title', 'section',
    'action', 'destination', 'media_type', 'control', 'source', 'status',
    'filter_type', 'relation_type', 'platform', 'result_state',
  ]);
  const NUMBER_KEYS = new Set(['content_id', 'position', 'depth']);
  const SECTION_MAP = [
    ['hero', 'hero'],
    ['manifesto', 'statement'],
    ['genesis', 'experience'],
    ['events', 'events'],
    ['artists', 'roster'],
    ['sets', 'sets'],
    ['media', 'memories'],
    ['footer', 'contact'],
  ];
  const SCROLL_MILESTONES = [25, 50, 75, 90];

  let started = false;
  let scrollQueued = false;
  const seenSections = new Set();
  const seenDepths = new Set();

  function analyticsAccepted() {
    try { return localStorage.getItem(CONSENT_KEY) === 'accepted'; }
    catch { return false; }
  }

  function pageContext() {
    const path = location.pathname.replace(/\/+$/, '') || '/';
    const segments = path.split('/').filter(Boolean);
    const routeTypes = {
      artists: 'artist',
      events: 'event',
      sets: 'set',
      releases: 'release',
      blog: 'blog',
      pages: 'page',
    };

    if (path === '/' || path === '/index.php') return { page_type: 'home' };
    if (segments[0] === 'contact') return { page_type: 'contact' };

    const contentType = routeTypes[segments[0]] || '';
    if (!contentType) return { page_type: 'public' };
    const context = { page_type: contentType, content_type: contentType };
    if (segments[1]) context.content_slug = decodeURIComponent(segments[1]).slice(0, 160);
    return context;
  }

  function sanitizeParams(params = {}) {
    const clean = {};
    for (const [key, value] of Object.entries(params)) {
      if (TEXT_KEYS.has(key)) {
        const text = String(value ?? '').trim();
        if (text) clean[key] = text.slice(0, 180);
      } else if (NUMBER_KEYS.has(key)) {
        const number = Number(value);
        if (Number.isFinite(number)) clean[key] = number;
      }
    }
    return clean;
  }

  function push(eventName, params = {}) {
    if (!analyticsAccepted() || !EVENT_RE.test(String(eventName || ''))) return false;
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({
      event: eventName,
      ...pageContext(),
      ...sanitizeParams(params),
    });
    return true;
  }

  function safeDestination(rawHref) {
    if (!rawHref) return '';
    if (rawHref.startsWith('#')) return rawHref.slice(0, 180);
    try {
      const url = new URL(rawHref, location.href);
      if (!/^https?:$/.test(url.protocol)) return '';
      return (url.origin === location.origin ? url.pathname + url.hash : url.origin + url.pathname).slice(0, 180);
    } catch {
      return '';
    }
  }

  function sectionName(section) {
    for (const [className, name] of SECTION_MAP) {
      if (section.classList.contains(className)) return name;
    }
    const id = String(section.id || '').toLowerCase();
    return /^[a-z0-9_-]{1,60}$/.test(id) ? id : '';
  }

  function observeSections() {
    if (!('IntersectionObserver' in window)) return;
    const sections = [...document.querySelectorAll('main .scene, footer.scene')];
    if (!sections.length) return;
    const observer = new IntersectionObserver(entries => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        const section = sectionName(entry.target);
        if (!section || seenSections.has(section)) continue;
        seenSections.add(section);
        push('brvtal_section_view', { section });
        observer.unobserve(entry.target);
      }
    }, { rootMargin: '-35% 0px -35% 0px', threshold: 0 });
    sections.forEach(section => observer.observe(section));
  }

  function scrollDepth() {
    const root = document.documentElement;
    const max = Math.max(0, root.scrollHeight - innerHeight);
    if (max <= 0) return 0;
    return Math.max(0, Math.min(100, Math.round((scrollY / max) * 100)));
  }

  function measureScrollDepth() {
    scrollQueued = false;
    const depth = scrollDepth();
    for (const milestone of SCROLL_MILESTONES) {
      if (depth < milestone || seenDepths.has(milestone)) continue;
      seenDepths.add(milestone);
      push('brvtal_scroll_depth', { depth: milestone });
    }
  }

  function startScrollDepth() {
    const baseline = scrollDepth();
    SCROLL_MILESTONES.forEach(milestone => {
      if (baseline >= milestone) seenDepths.add(milestone);
    });
    addEventListener('scroll', () => {
      if (scrollQueued) return;
      scrollQueued = true;
      requestAnimationFrame(measureScrollDepth);
    }, { passive: true });
  }

  function declarativeParams(node) {
    return sanitizeParams({
      content_type: node.dataset.measureContentType,
      content_id: node.dataset.measureContentId,
      content_slug: node.dataset.measureContentSlug,
      content_title: node.dataset.measureContentTitle,
      section: node.dataset.measureSection,
      action: node.dataset.measureAction,
      destination: node.dataset.measureDestination,
      position: node.dataset.measurePosition,
      media_type: node.dataset.measureMediaType,
      control: node.dataset.measureControl,
      source: node.dataset.measureSource,
      status: node.dataset.measureStatus,
      filter_type: node.dataset.measureFilterType,
      relation_type: node.dataset.measureRelationType,
      platform: node.dataset.measurePlatform,
      result_state: node.dataset.measureResultState,
    });
  }

  function handleClick(event) {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const declared = target.closest('[data-measure-event]');
    if (declared) push(declared.dataset.measureEvent, declarativeParams(declared));

    const menuToggle = target.closest('#menuToggle');
    if (menuToggle) {
      setTimeout(() => {
        const isOpen = document.getElementById('menuPanel')?.getAttribute('aria-hidden') === 'false';
        push('brvtal_menu_toggle', { action: isOpen ? 'open' : 'close', control: 'menu' });
      }, 0);
    }

    const link = target.closest('a[href]');
    if (!link) return;
    const rawHref = link.getAttribute('href') || '';
    const destination = safeDestination(rawHref);
    if (!destination) return;

    if (link.closest('.nav, .menu-panel') || link.classList.contains('brand')) {
      push('brvtal_navigation_click', {
        destination,
        source: link.closest('.menu-panel') ? 'menu' : 'header',
      });
    }

    try {
      const url = new URL(rawHref, location.href);
      if (/^https?:$/.test(url.protocol) && url.origin !== location.origin) {
        push('brvtal_outbound_click', { destination });
      }
    } catch { /* Ignore malformed/non-URL href values. */ }
  }

  function start() {
    if (started || !analyticsAccepted()) return;
    started = true;
    push('brvtal_page_view');
    observeSections();
    startScrollDepth();
    document.addEventListener('click', handleClick, true);
  }

  window.BRVTALMeasure = Object.freeze({
    push,
    context: pageContext,
    enabled: analyticsAccepted,
  });

  window.addEventListener('brvtal:analytics-ready', start);
  window.addEventListener('brvtal:analytics-settings-open', () => {
    if (started) push('brvtal_analytics_settings_open', { control: 'analytics_settings' });
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
