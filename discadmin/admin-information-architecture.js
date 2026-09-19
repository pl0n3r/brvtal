(() => {
  'use strict';

  const originalGo = window.go;
  const originalTech = window.tech;
  const originalOpenModal = window.openModal;
  const originalReq = typeof window.req === 'function' ? window.req : null;
  const STALE_NAVIGATION = 'BRVTAL_STALE_NAVIGATION';
  let routeToken = 0;
  let navigationRequestToken = null;
  let navTimer = null;
  let applyingNav = false;
  let navObserver = null;
  let applyingRoute = false;
  let initialRouteApplied = false;

  const ROUTE_PARAM = 'module';
  const routeSections = new Set([
    'dashboard','events','artists','releases','sets','blog','pages','media','hero-slider',
    'theme','settings','security','system','backups','activity'
  ]);
  const dynamicModuleSections = new Set(['media','releases','blog']);
  const normalize = value => String(value || '').trim().toUpperCase().replace(/\s+/g, ' ');
  const exactNavLabelKeys = new Map([
    ['DASHBOARD', 'dashboard'],
    ['EVENTS', 'events'],
    ['ARTISTS', 'artists'],
    ['RELEASES', 'releases'],
    ['SETS', 'sets'],
    ['BLOG', 'blog'],
    ['PAGES', 'pages'],
    ['MEDIA', 'media'],
    ['MEDIA LIBRARY', 'media'],
    ['BANNERS', 'hero-slider'],
    ['SETTINGS', 'settings']
  ]);
  const partialNavLabelRules = [
    {key:'hero-slider', tokens:['BANNER','HERO SLIDER']},
    {key:'theme', tokens:['THEME']},
    {key:'security', tokens:['SECURITY','2FA']},
    {key:'system', tokens:['SYSTEM STATUS']},
    {key:'backups', tokens:['BACKUP']},
    {key:'activity', tokens:['ACTIVITY']},
    {key:'content-core', tokens:['CONTENT CORE']},
    {key:'seo', tokens:['SEO']}
  ];

  function staleNavigationError() {
    const error = new Error(STALE_NAVIGATION);
    error.code = STALE_NAVIGATION;
    return error;
  }

  function isStaleNavigation(error) {
    return error?.code === STALE_NAVIGATION || error?.message === STALE_NAVIGATION;
  }

  if (originalReq) {
    window.req = async function(...args) {
      const token = navigationRequestToken;
      try {
        const result = await originalReq.apply(this, args);
        if (token !== null && token !== routeToken) throw staleNavigationError();
        return result;
      } catch (error) {
        if (token !== null && token !== routeToken && !isStaleNavigation(error)) throw staleNavigationError();
        throw error;
      }
    };
  }

  async function invokeOriginalGo(section, token) {
    let result;
    navigationRequestToken = token;
    try {
      const moduleNavigate = window.BRVTALAdminModules?.navigate;
      result = dynamicModuleSections.has(section) && typeof moduleNavigate === 'function'
        ? moduleNavigate(section)
        : originalGo.call(window, section);
    } finally {
      navigationRequestToken = null;
    }
    try {
      await result;
      return token === routeToken;
    } catch (error) {
      if (token !== routeToken && isStaleNavigation(error)) return false;
      throw error;
    }
  }

  function canonicalRouteSection(section) {
    const value = String(section || '').trim().toLowerCase();
    if (value === 'content-core') return 'events';
    return routeSections.has(value) ? value : 'dashboard';
  }

  function routeFromUrl() {
    const raw = new URLSearchParams(location.search).get(ROUTE_PARAM);
    if (!raw) return 'dashboard';
    return canonicalRouteSection(raw);
  }

  function syncRouteUrl(section, mode = 'push', force = false) {
    if (applyingRoute && !force) return;
    const canonical = canonicalRouteSection(section);
    const url = new URL(location.href);
    if (canonical === 'dashboard') url.searchParams.delete(ROUTE_PARAM);
    else url.searchParams.set(ROUTE_PARAM, canonical);
    const next = `${url.pathname}${url.search}${url.hash}`;
    const current = `${location.pathname}${location.search}${location.hash}`;
    if (next === current) return;
    history[mode === 'replace' ? 'replaceState' : 'pushState']({brvtalAdminRoute: canonical}, '', next);
  }

  async function navigateRoute(section) {
    const canonical = canonicalRouteSection(section);
    if (canonical === 'system' && typeof window.tech === 'function') return window.tech('system');
    if (typeof window.go === 'function') return window.go(canonical);
  }

  async function applyUrlRoute() {
    if (typeof state === 'undefined' || !state?.authed) return false;
    const section = routeFromUrl();
    if (section !== 'events' && state.section === section) {
      initialRouteApplied = true;
      return true;
    }
    applyingRoute = true;
    try {
      await navigateRoute(section);
      initialRouteApplied = true;
      return true;
    } finally {
      applyingRoute = false;
    }
  }

  function scheduleInitialRoute() {
    if (initialRouteApplied) return;
    queueMicrotask(() => { applyUrlRoute().catch(() => {}); });
  }

  function matchesPartialNavLabel(label, rule) {
    if (rule.mode === 'all') return rule.tokens.every(token => label.includes(token));
    return rule.tokens.some(token => label.includes(token));
  }

  function buttonKey(button) {
    const dataKey = String(button.dataset.adminNav || '').toLowerCase();
    if (dataKey) return dataKey;

    const handler = button.getAttribute('onclick') || '';
    const match = handler.match(/(?:go|tech)\('([^']+)'\)/);
    if (match) return match[1].toLowerCase();

    const label = normalize(button.textContent);
    const exactKey = exactNavLabelKeys.get(label);
    if (exactKey) return exactKey;

    const partialRule = partialNavLabelRules.find(rule => matchesPartialNavLabel(label, rule));
    return partialRule?.key || 'other:' + label.toLowerCase();
  }

  const groups = [
    {label:'SITE / EDITORIAL', keys:['dashboard','hero-slider','events','artists','releases','sets','media','pages','blog']},
    {label:'CONFIGURATION / TECHNICAL', keys:['settings','system']},
  ];
  const hiddenNavigationKeys = new Set(['content-core','theme','security','seo','backups','activity']);

  function observeNavigation() {
    navObserver?.observe(document.documentElement,{childList:true,subtree:true});
  }

  function rebuildNavigation() {
    if (applyingNav) return;
    const nav = document.querySelector('.side .nav');
    if (!nav) return;
    applyingNav = true;
    navObserver?.disconnect();
    try {
      nav.querySelectorAll('.navgroup,.ia-navgroup').forEach(node => node.remove());
      const buttons = [...nav.querySelectorAll(':scope > button')];
      const keyed = new Map();
      const unknown = [];

      buttons.forEach(button => {
        const key = buttonKey(button);
        button.dataset.iaKey = key;
        if (hiddenNavigationKeys.has(key)) {
          button.dataset.iaHidden = '1';
          button.setAttribute('aria-hidden','true');
          button.tabIndex = -1;
          return;
        }
        delete button.dataset.iaHidden;
        button.removeAttribute('aria-hidden');
        button.removeAttribute('tabindex');
        if (button.dataset.memoriesNav === '1') button.dataset.iaSubnav = '1';
        else delete button.dataset.iaSubnav;
        if (!keyed.has(key)) keyed.set(key, []);
        keyed.get(key).push(button);
      });

      groups.forEach(group => {
        const groupButtons = [];
        group.keys.forEach(key => {
          (keyed.get(key) || []).forEach(button => groupButtons.push(button));
          keyed.delete(key);
        });
        if (!groupButtons.length) return;
        const heading = document.createElement('div');
        heading.className = 'ia-navgroup';
        heading.textContent = group.label;
        nav.appendChild(heading);
        groupButtons.forEach(button => nav.appendChild(button));
      });

      keyed.forEach(list => list.forEach(button => unknown.push(button)));
      unknown.forEach(button => nav.appendChild(button));

      const visibleSection = ['theme','security'].includes(String(window.state?.section || ''))
        ? 'settings'
        : String(window.state?.section || '');
      nav.querySelectorAll(':scope > button:not([data-ia-hidden="1"])').forEach(button => {
        button.classList.toggle('active', buttonKey(button) === visibleSection);
      });
    } finally {
      applyingNav = false;
      observeNavigation();
    }
  }

  function scheduleNavigation() {
    clearTimeout(navTimer);
    navTimer = setTimeout(rebuildNavigation, 20);
  }

  function ensureWorkspaceHost() {
    let host = document.getElementById('admin-module-host');
    if (host) return host;
    const main = document.querySelector('.main');
    if (!main) return null;
    host = document.createElement('div');
    host.id = 'admin-module-host';
    host.setAttribute('aria-live','polite');
    main.appendChild(host);
    return host;
  }

  function restoreVisibleSection(section) {
    if (typeof state === 'object' && state) state.section = section;
    const title = document.querySelector('.main > .top h1');
    if (title) title.textContent = section.toUpperCase();
    document.querySelectorAll('.side .nav > button').forEach(button => {
      button.classList.toggle('active', buttonKey(button) === section);
    });
  }

  function contextBar(title, description, actions = []) {
    const bar = document.createElement('div');
    bar.className = 'ia-contextbar';
    const copy = document.createElement('div');
    copy.className = 'ia-contextbar-copy';
    const strong = document.createElement('strong');
    strong.textContent = title;
    const span = document.createElement('span');
    span.textContent = description;
    copy.append(strong, span);
    bar.appendChild(copy);

    if (actions.length) {
      const controls = document.createElement('div');
      controls.className = 'ia-contextbar-actions';
      actions.forEach(action => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = action.primary ? 'btn red' : 'btn ghost';
        button.textContent = action.label;
        button.addEventListener('click', action.onClick);
        controls.appendChild(button);
      });
      bar.appendChild(controls);
    }
    return bar;
  }

  function simplifyEventEditor(root) {
    if (!root) return;
    root.classList.add('ia-events-workspace');
    root.dataset.iaContext = 'events';
    root.querySelector('#eventsTab')?.style.setProperty('display','block');
    root.querySelector('#rosterTab')?.style.setProperty('display','none');
    const modalEyebrow = root.querySelector('#eventModal .ey');
    if (modalEyebrow) modalEyebrow.textContent = 'EVENTS / EDITOR';

    // Events keeps the canonical shell list/search visible. Content Core stays
    // mounted only to provide the guided editor modal and its workflow state.
    const wrap = root.querySelector('.wrap');
    if (wrap) {
      wrap.hidden = true;
      wrap.setAttribute('aria-hidden','true');
      wrap.dataset.iaInternalOnly = '1';
      wrap.style.setProperty('display','none','important');
    }
  }

  function simplifyRoster(root) {
    if (!root) return;
    root.classList.add('ia-roster-workspace');
    root.dataset.iaContext = 'artists-roster';
    const rosterTab = root.querySelector('[data-tab="roster"]');
    rosterTab?.click();
    root.querySelector('#eventsTab')?.style.setProperty('display','none');
    root.querySelector('#rosterTab')?.style.setProperty('display','block');
    const wrap = root.querySelector('.wrap');
    if (wrap && !wrap.querySelector('[data-ia-roster-intro]')) {
      const intro = contextBar(
        'COLLECTIVE STATUS',
        'Manage active BRVTAL members, alumni and collective ordering without changing the artist profile.',
        [{label:'← ARTIST PROFILES', onClick:() => window.go?.('artists')}]
      );
      intro.dataset.iaRosterIntro = '1';
      wrap.prepend(intro);
    }
  }

  async function loadContentCoreContext(context) {
    const token = ++routeToken;
    window.BRVTALAdminModules?.cancel?.();
    const visibleSection = context === 'roster' ? 'artists' : 'events';
    const visibleApplied = await invokeOriginalGo(visibleSection, token);
    if (!visibleApplied || token !== routeToken) return false;

    if (!window.BRVTALAdminModules?.load) return true;
    ensureWorkspaceHost();
    await window.BRVTALAdminModules.load('content-core', {syncUrl:false});
    if (token !== routeToken) return false;

    const root = document.querySelector('#admin-module-host [data-admin-module="content-core"]');
    if (!root) throw new Error('DISCADMIN internal content workflow failed to mount');

    if (context === 'roster') simplifyRoster(root);
    else simplifyEventEditor(root);
    rebuildNavigation();
    restoreVisibleSection(visibleSection);
    return true;
  }

  function enhanceArtistsList() {
    if (typeof state === 'undefined' || state.section !== 'artists') return;
    const toolbar = document.querySelector('.main .toolbar');
    if (!toolbar || toolbar.querySelector('[data-ia-collective-roster]')) return;
    const actions = toolbar.querySelector('.head-actions') || toolbar;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn ghost';
    button.dataset.iaCollectiveRoster = '1';
    button.textContent = 'COLLECTIVE STATUS';
    button.addEventListener('click', () => loadContentCoreContext('roster'));
    actions.appendChild(button);
  }

  window.go = async function(section) {
    if (section === 'content-core') section = 'events';

    if (section === 'dashboard' && !initialRouteApplied) {
      const requested = routeFromUrl();
      if (requested !== 'dashboard') {
        applyingRoute = true;
        try {
          const result = await navigateRoute(requested);
          initialRouteApplied = true;
          return result;
        } finally {
          applyingRoute = false;
        }
      }
    }

    if (!applyingRoute) initialRouteApplied = true;

    let result;
    if (section === 'events') {
      const applied = await loadContentCoreContext('events');
      if (!applied) return;
    } else {
      const token = ++routeToken;
      window.BRVTALAdminModules?.cancel?.();
      if (dynamicModuleSections.has(section)) syncRouteUrl(section, 'push', true);
      const applied = await invokeOriginalGo(section, token);
      if (!applied || token !== routeToken) return;
      result = true;
      if (section === 'artists') setTimeout(enhanceArtistsList, 0);
      setTimeout(rebuildNavigation, 0);
    }
    syncRouteUrl(section);
    return result;
  };

  if (typeof originalTech === 'function') {
    window.tech = async function(section, ...args) {
      if (!applyingRoute) initialRouteApplied = true;
      const token = ++routeToken;
      window.BRVTALAdminModules?.cancel?.();
      const result = await originalTech.apply(this, [section, ...args]);
      if (token !== routeToken) return result;
      syncRouteUrl(section);
      setTimeout(rebuildNavigation, 0);
      return result;
    };
  }

  window.openModal = async function(type, id = null) {
    if (type === 'events') {
      await window.go('events');
      window.BRVTALContentCore?.openEvent?.(id);
      return;
    }
    return originalOpenModal?.apply(this, arguments);
  };

  window.BRVTALAdminIA = {
    rebuildNavigation,
    openEvents:() => loadContentCoreContext('events'),
    openCollectiveStatus:() => loadContentCoreContext('roster'),
    readRoute: routeFromUrl,
    applyRoute: applyUrlRoute
  };

  window.addEventListener('popstate', () => {
    initialRouteApplied = true;
    applyUrlRoute().catch(() => {});
  });

  navObserver = new MutationObserver(mutations => {
    if (applyingNav) return;
    if (mutations.some(mutation => mutation.addedNodes.length || mutation.removedNodes.length)) {
      scheduleNavigation();
      scheduleInitialRoute();
    }
  });
  observeNavigation();

  setTimeout(() => {
    rebuildNavigation();
    scheduleInitialRoute();
    if (typeof state !== 'undefined' && state.authed && state.section === 'content-core') window.go('events');
    else if (typeof state !== 'undefined' && state.authed && state.section === 'artists') enhanceArtistsList();
  }, 50);
})();