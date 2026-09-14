(() => {
  'use strict';

  const originalGo = window.go;
  const originalTech = window.tech;
  const originalOpenModal = window.openModal;
  let routeToken = 0;
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
  const normalize = value => String(value || '').trim().toUpperCase().replace(/\s+/g, ' ');

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

  function syncRouteUrl(section, mode = 'push') {
    if (applyingRoute) return;
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

  function buttonKey(button) {
    const dataKey = String(button.dataset.adminNav || '').toLowerCase();
    if (dataKey) return dataKey;

    const handler = button.getAttribute('onclick') || '';
    const match = handler.match(/(?:go|tech)\('([^']+)'\)/);
    if (match) return match[1].toLowerCase();

    const label = normalize(button.textContent);
    if (label === 'DASHBOARD') return 'dashboard';
    if (label === 'EVENTS') return 'events';
    if (label === 'ARTISTS') return 'artists';
    if (label === 'RELEASES') return 'releases';
    if (label === 'SETS') return 'sets';
    if (label === 'BLOG') return 'blog';
    if (label === 'PAGES') return 'pages';
    if (label === 'MEDIA' || label === 'MEDIA LIBRARY') return 'media';
    if (label.includes('HERO') && label.includes('SLIDER')) return 'hero-slider';
    if (label.includes('THEME')) return 'theme';
    if (label === 'SETTINGS') return 'settings';
    if (label.includes('SECURITY') || label.includes('2FA')) return 'security';
    if (label.includes('SYSTEM STATUS')) return 'system';
    if (label.includes('BACKUP')) return 'backups';
    if (label.includes('ACTIVITY')) return 'activity';
    if (label.includes('CONTENT CORE')) return 'content-core';
    if (label.includes('SEO')) return 'seo';
    return 'other:' + label.toLowerCase();
  }

  const groups = [
    {label:'CONTENT', keys:['events','artists','releases','sets','blog','pages']},
    {label:'MEDIA', keys:['media','hero-slider']},
    {label:'SITE', keys:['theme','settings','seo']},
    {label:'SYSTEM', keys:['security','system','backups','activity']},
  ];

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
        if (key === 'content-core') {
          button.dataset.iaHidden = '1';
          button.setAttribute('aria-hidden','true');
          button.tabIndex = -1;
          return;
        }
        delete button.dataset.iaHidden;
        button.removeAttribute('aria-hidden');
        button.removeAttribute('tabindex');
        if (!keyed.has(key)) keyed.set(key, []);
        keyed.get(key).push(button);
      });

      const dashboard = keyed.get('dashboard') || [];
      dashboard.forEach(button => nav.appendChild(button));
      keyed.delete('dashboard');

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
      if (unknown.length) {
        const heading = document.createElement('div');
        heading.className = 'ia-navgroup';
        heading.textContent = 'MORE';
        nav.appendChild(heading);
        unknown.forEach(button => nav.appendChild(button));
      }
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
    const wrap = root.querySelector('.wrap');
    if (wrap && !wrap.querySelector('[data-ia-events-intro]')) {
      const intro = contextBar(
        'EVENTS',
        'Identity, date and place, lifecycle, tickets and lineup are managed here as one workflow.'
      );
      intro.dataset.iaEventsIntro = '1';
      wrap.prepend(intro);
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
    const visibleSection = context === 'roster' ? 'artists' : 'events';
    await originalGo.call(window, visibleSection);
    if (token !== routeToken) return;

    if (!window.BRVTALAdminModules?.load) return;
    ensureWorkspaceHost();
    await window.BRVTALAdminModules.load('content-core', {syncUrl:false});
    if (token !== routeToken) return;

    const root = document.querySelector('#admin-module-host [data-admin-module="content-core"]');
    if (!root) throw new Error('DISCADMIN internal content workflow failed to mount');

    if (context === 'roster') simplifyRoster(root);
    else simplifyEventEditor(root);
    rebuildNavigation();
    restoreVisibleSection(visibleSection);
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
    if (section === 'events') result = await loadContentCoreContext('events');
    else {
      ++routeToken;
      result = await originalGo.apply(this, [section]);
      if (section === 'artists') setTimeout(enhanceArtistsList, 0);
      setTimeout(rebuildNavigation, 0);
    }
    syncRouteUrl(section);
    return result;
  };

  if (typeof originalTech === 'function') {
    window.tech = async function(section, ...args) {
      if (!applyingRoute) initialRouteApplied = true;
      ++routeToken;
      const result = await originalTech.apply(this, [section, ...args]);
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