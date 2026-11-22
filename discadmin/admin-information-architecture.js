(() => {
  'use strict';

  const originalGo = window.go;
  const originalTech = window.tech;
  const originalOpenModal = window.openModal;
  const originalCloseModal = window.closeModal;
  const originalReq = typeof window.req === 'function' ? window.req : null;
  const STALE_NAVIGATION = 'BRVTAL_STALE_NAVIGATION';
  let routeToken = 0;
  let navigationRequestToken = null;
  let navTimer = null;
  let applyingNav = false;
  let navObserver = null;
  let applyingRoute = false;
  let initialRouteApplied = false;
  let lastNavigationDiagnostic = {
    status:'idle',
    reason:'',
    requested:'',
    previousSection:'',
    currentSection:'',
    route:'',
    heroDirty:false,
    unsavedDirty:false
  };

  function recordNavigationDiagnostic(status, reason, requested, previousSection = '') {
    const heroGuard = window.BRVTALHeroSliderGuard;
    const unsavedGuard = window.BRVTALUnsavedChanges;
    lastNavigationDiagnostic = {
      status:String(status || ''),
      reason:String(reason || ''),
      requested:String(requested || ''),
      previousSection:String(previousSection || ''),
      currentSection:String(window.state?.section || ''),
      route:String(new URLSearchParams(location.search).get(ROUTE_PARAM) || 'dashboard'),
      heroDirty:Boolean(heroGuard?.hasUnsavedChanges?.()),
      unsavedDirty:Boolean(unsavedGuard?.hasDirtyChanges?.()),
      dashboard:window.BRVTALDashboardV2?.diagnostics?.() || null
    };
  }

  const ROUTE_PARAM = 'module';
  const routeSections = new Set([
    'dashboard','events','artists','releases','sets','blog','pages','media','seo','hero-slider',
    'theme','settings','security','system','backups','activity','ticketing'
  ]);
  const dynamicModuleSections = new Set(['media','releases','blog','seo']);
  const normalize = value => String(value || '').trim().toUpperCase().replace(/\s+/g, ' ');
  const exactNavLabelKeys = new Map([
    ['DASHBOARD', 'dashboard'],
    ['EVENTS', 'events'],
    ['ARTISTS', 'artists'],
    ['RELEASES', 'releases'],
    ['SETS', 'sets'],
    ['BLOG', 'blog'],
    ['PAGES', 'pages'],
    ['SEO', 'seo'],
    ['MEDIA', 'media'],
    ['MEDIA LIBRARY', 'media'],
    ['BANNERS', 'hero-slider'],
    ['APPEARANCE', 'appearance'],
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

  function requestWorkspaceNavigation(section) {
    const operation = {accepted:true,unsavedToken:0,reason:''};
    const heroGuard = window.BRVTALHeroSliderGuard;
    if (typeof heroGuard?.requestNavigation === 'function'
      && heroGuard.requestNavigation(section) === false) {
      operation.accepted = false;
      operation.reason = 'hero-guard-rejected';
      return operation;
    }

    const unsavedGuard = window.BRVTALUnsavedChanges;
    if (typeof unsavedGuard?.requestNavigation === 'function') {
      operation.unsavedToken = unsavedGuard.requestNavigation(section);
      if (operation.unsavedToken === 0) { operation.accepted = false; operation.reason = 'unsaved-guard-rejected'; }
    }
    return operation;
  }

  function commitWorkspaceNavigation(section, operation) {
    const unsavedGuard = window.BRVTALUnsavedChanges;
    const unsavedResult = typeof unsavedGuard?.commitNavigation === 'function'
      ? unsavedGuard.commitNavigation(operation?.unsavedToken)
      : true;
    if (unsavedResult !== true) return unsavedResult;
    window.BRVTALHeroSliderGuard?.commitNavigation?.(section);
    return true;
  }

  function cancelWorkspaceNavigation(operation) {
    window.BRVTALUnsavedChanges?.cancelNavigation?.(operation?.unsavedToken);
  }

  function retireLegacyEditor() {
    const modal = document.getElementById('modal');
    if (!modal?.classList.contains('open')) return false;
    if (typeof originalCloseModal === 'function') originalCloseModal.call(window);
    else modal.classList.remove('open');
    if (typeof state === 'object' && state) state.editing = null;
    const saveButton = document.getElementById('saveBtn');
    if (saveButton) saveButton.onclick = null;
    return true;
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
    const previousState = typeof state === 'undefined'
      ? null
      : {section:state.section, rows:state.rows};
    const previousUrl = `${location.pathname}${location.search}${location.hash}`;
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
      const outcome = await result;
      if (outcome === false) return false;
      return token === routeToken ? true : undefined;
    } catch (error) {
      if (token !== routeToken && isStaleNavigation(error)) return undefined;
      if (token === routeToken && previousState) {
        state.section = previousState.section;
        state.rows = previousState.rows;
        const currentUrl = `${location.pathname}${location.search}${location.hash}`;
        if (currentUrl !== previousUrl) {
          history.replaceState(
            {brvtalAdminRoute:canonicalRouteSection(previousState.section)},
            '',
            previousUrl
          );
        }
        setTimeout(rebuildNavigation, 0);
      }
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
    if (canonical !== 'ticketing') url.searchParams.delete('tab');
    if (canonical !== 'settings') url.searchParams.delete('settings');
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
    const previousSection = state.section;
    applyingRoute = true;
    try {
      const result = await navigateRoute(section);
      if (result === false) {
        syncRouteUrl(previousSection, 'replace', true);
        setTimeout(rebuildNavigation, 0);
        return false;
      }
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
    const dataKey = String(button.dataset.iaChild || button.dataset.adminNav || '').toLowerCase();
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


  // DISCADMIN V3: eight canonical roots, and only destination links as children.
  // Server-side ticketing.php remains the authority for staff capabilities.
  const NAV_TREE = [
    {key:'dashboard', label:'Escritorio', route:'dashboard'},
    {key:'events', label:'Eventos', route:'events'},
    {key:'artists', label:'Artistas', route:'artists'},
    {key:'music', label:'Música', children:[
      {key:'sets', label:'Sets', route:'sets'},
      {key:'releases', label:'Releases', route:'releases'}
    ]},
    {key:'ticketing', label:'Boletería', route:'ticketing', children:[
      {key:'orders', label:'Pedidos', ticketTab:'orders'},
      {key:'entries', label:'Boletas', ticketTab:'entries'},
      {key:'buyers', label:'Compradores', ticketTab:'buyers'},
      {key:'door', label:'Control de acceso', ticketTab:'door'},
      {key:'courtesy', label:'Cortesías', ticketTab:'orders', privileged:true},
      {key:'promos', label:'Promociones', ticketTab:'promos'},
      {key:'promoters', label:'Promotores', ticketTab:'promoters'},
      {key:'finance', label:'Finanzas', ticketTab:'finance'},
      {key:'refunds', label:'Devoluciones', ticketTab:'finance', privileged:true}
    ]},
    {key:'mail', label:'Correos', children:[
      {key:'mail-history', label:'Historial de envíos', ticketTab:'mail'},
      {key:'mail-templates', label:'Plantillas', comingSoon:true},
      {key:'mail-test', label:'Pruebas de envío', comingSoon:true}
    ]},
    {key:'website', label:'Sitio web', children:[
      {key:'pages', label:'Páginas', route:'pages'},
      {key:'blog', label:'Blog', route:'blog'},
      {key:'media', label:'Multimedia', route:'media'},
      {key:'hero-slider', label:'Banners', route:'hero-slider'},
      {key:'theme', label:'Apariencia', route:'theme'},
      {key:'seo', label:'SEO', route:'seo'}
    ]},
    {key:'settings', label:'Configuración', children:[
      {key:'general', label:'General', settingsTab:'general'},
      {key:'social', label:'Redes sociales y contacto', settingsTab:'social'},
      {key:'integrations', label:'Integraciones', settingsTab:'integrations'},
      {key:'security', label:'Seguridad', settingsTab:'advanced'},
      {key:'system', label:'Estado del sistema', route:'system'},
      {key:'backups', label:'Copias de seguridad', route:'system', systemView:'backups'}
    ]}
  ];
  const MENU_STORAGE_KEY = 'brvtal.discadmin.nav-v3';
  const EXPANDABLE_ROOTS = new Set(NAV_TREE.filter(root => root.children).map(root => root.key));
  const expandedMenus = new Set();
  try {
    const saved = JSON.parse(sessionStorage.getItem(MENU_STORAGE_KEY) || '[]');
    if (Array.isArray(saved)) saved.forEach(key => {
      if (EXPANDABLE_ROOTS.has(key)) expandedMenus.add(key);
    });
  } catch (_) { /* private browsing or malformed storage: keep safe defaults */ }
  let lastMenuRoute = '';
  let ticketAccess = {status:'unknown',role:'',tabs:[]};
  let permissionRequest = null;

  function storeMenuState() {
    try { sessionStorage.setItem(MENU_STORAGE_KEY, JSON.stringify([...expandedMenus])); }
    catch (_) { /* navigation still works without storage */ }
  }

  // GET-only authorization preview. No client-side grant ever replaces the PHP
  // ticketing capability checks, and inaccessible operations are not linked.
  function requestTicketAccess() {
    if (!window.state?.authed || ticketAccess.status !== 'unknown' || permissionRequest) return;
    permissionRequest = fetch('/discadmin/ticketing.php', {
      credentials:'same-origin',cache:'no-store',
      headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}
    }).then(async response => {
      if (!response.ok) return {status:'denied',role:'',tabs:[]};
      const doc = new DOMParser().parseFromString(await response.text(),'text/html');
      const root = doc.querySelector('[data-admin-module="ticketing"]');
      if (!root) return {status:'denied',role:'',tabs:[]};
      const tabs = [...root.querySelectorAll('[data-ticketing-tab]')]
        .map(button => button.dataset.ticketingTab);
      return {status:'ready',role:root.dataset.ticketingRole || '',tabs};
    }).catch(() => ({status:'denied',role:'',tabs:[]}))
      .then(result => {
        if (!window.state?.authed) return;
        ticketAccess = result;
        rebuildNavigation();
      }).finally(() => { permissionRequest = null; });
  }

  function currentNavigationContext() {
    const section = String(window.state?.section || 'dashboard');
    const url = new URL(location.href);
    const tab = String(url.searchParams.get('tab') || 'dashboard');
    const settings = String(url.searchParams.get('settings') || 'general');
    const activeRoot = section === 'ticketing' ? (tab === 'mail' ? 'mail' : 'ticketing')
      : ['sets','releases'].includes(section) ? 'music'
      : ['pages','blog','media','hero-slider','theme','seo'].includes(section) ? 'website'
      : ['settings','security','system','backups'].includes(section) ? 'settings'
      : section;
    const menuRoute = activeRoot + ':' + section + ':' + tab + ':' + settings;
    if (lastMenuRoute !== menuRoute) {
      if (EXPANDABLE_ROOTS.has(activeRoot)) expandedMenus.add(activeRoot);
      lastMenuRoute = menuRoute;
    }
    return {section,tab,settings,activeRoot};
  }

  function isVisibleMenuItem(root,item) {
    if (root.key !== 'ticketing' && root.key !== 'mail') return true;
    if (ticketAccess.status !== 'ready') return false;
    if (item.privileged && !['superadmin','owner','root','platform_owner'].includes(ticketAccess.role)) return false;
    return !item.ticketTab || ticketAccess.tabs.includes(item.ticketTab);
  }

  function isVisibleRoot(root) {
    if (root.key !== 'ticketing' && root.key !== 'mail') return true;
    if (ticketAccess.status === 'denied') return false;
    if (ticketAccess.status === 'unknown') return true; // aria-disabled pending resolution
    return root.key === 'mail'
      ? ticketAccess.tabs.includes('mail')
      : ticketAccess.tabs.length > 0;
  }

  function activeChild(root, child, context) {
    if (root.key === 'ticketing')
      return child.key === context.tab && context.section === 'ticketing';
    if (root.key === 'mail') return child.ticketTab === 'mail'
      && context.section === 'ticketing' && context.tab === 'mail';
    if (root.key === 'settings') {
      if (child.route === 'system') return context.section === 'system'
        && (child.systemView ? new URLSearchParams(location.search).get('view') === child.systemView
                             : new URLSearchParams(location.search).get('view') !== 'backups');
      return context.section === 'settings' && child.settingsTab === context.settings;
    }
    return child.route === context.section;
  }

  async function followMenuDestination(child) {
    if (child.comingSoon) return false;
    if (child.ticketTab) {
      return window.BRVTALTicketing?.open?.({tab:child.ticketTab});
    }
    if (child.settingsTab) {
      const wasSettings = String(window.state?.section || '') === 'settings';
      const result = await window.go('settings');
      if (result === false || window.state?.section !== 'settings') return false;
      const url = new URL(location.href);
      url.searchParams.set('module','settings');
      url.searchParams.set('settings',child.settingsTab);
      history[wasSettings ? 'pushState' : 'replaceState'](
        {brvtalAdminRoute:'settings'},'',url
      );
      window.BRVTALSettingsV2?.activate?.(child.settingsTab);
      rebuildNavigation();
      return true;
    }
    if (child.route === 'system') {
      const wasSystem = String(window.state?.section || '') === 'system';
      const result = await window.tech?.('system');
      if (result === false) return false;
      const url = new URL(location.href);
      if (child.systemView) url.searchParams.set('view',child.systemView);
      else url.searchParams.delete('view');
      history[wasSystem ? 'pushState' : 'replaceState'](
        {brvtalAdminRoute:'system'},'',url
      );
      rebuildNavigation();
      return true;
    }
    return window.go?.(child.route);
  }

  function rootButton(root, expanded, active) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'ia-root-button';
    button.dataset.adminNav = root.key;
    button.dataset.iaRootButton = root.key;
    button.textContent = root.label;
    button.classList.toggle('active',active);
    if ((root.key === 'ticketing' || root.key === 'mail') && ticketAccess.status !== 'ready') {
      button.disabled = true;
      button.setAttribute('aria-label',root.label + ' · verificando permisos');
      return button;
    }
    if (!root.children) {
      button.addEventListener('click',() => { followMenuDestination(root)?.catch?.(() => {}); });
    } else if (root.key === 'ticketing') {
      button.addEventListener('click',() => { window.BRVTALTicketing?.open?.({tab:'dashboard'})?.catch?.(() => {}); });
    } else {
      button.setAttribute('aria-expanded',String(expanded));
      button.setAttribute('aria-controls','brvtal-menu-' + root.key);
      button.addEventListener('click',() => toggleMenu(root.key));
    }
    return button;
  }

  function toggleMenu(key) {
    if (expandedMenus.has(key)) expandedMenus.delete(key);
    else expandedMenus.add(key);
    storeMenuState();
    const focus = '[data-ia-disclosure="' + key + '"]';
    rebuildNavigation(focus);
    // The canonical mobile shell closes on ANY nav button click. A disclosure
    // changes only the tree; reopen the existing panel rather than hiding it.
    const shell = document.querySelector('#app .shell');
    if (matchMedia('(max-width: 850px)').matches && shell && !shell.classList.contains('admin-nav-open')) {
      window.toggleAdminNav?.();
      document.querySelector('.side .nav ' + focus)?.focus({preventScroll:true});
    }
  }

  function rebuildNavigation(focusSelector = '') {
    if (applyingNav) return;
    if (!window.state?.authed) {
      ticketAccess = {status:'unknown',role:'',tabs:[]};
      return;
    }
    const nav = document.querySelector('.side .nav');
    if (!nav) return;
    applyingNav = true;
    navObserver?.disconnect();
    const focus = focusSelector || (document.activeElement?.dataset?.iaRootButton
      ? '[data-ia-root-button="' + document.activeElement.dataset.iaRootButton + '"]'
      : document.activeElement?.dataset?.iaDisclosure
        ? '[data-ia-disclosure="' + document.activeElement.dataset.iaDisclosure + '"]'
        : document.activeElement?.dataset?.iaChild
          ? '[data-ia-child="' + document.activeElement.dataset.iaChild + '"]' : '');
    try {
      const context = currentNavigationContext();
      const fragment = document.createDocumentFragment();
      for (const root of NAV_TREE) {
        if (!isVisibleRoot(root)) continue;
        const expanded = expandedMenus.has(root.key);
        const entry = document.createElement('div');
        entry.className = 'ia-root';
        entry.dataset.iaRoot = root.key;
        const row = document.createElement('div');
        row.className = 'ia-root-row';
        row.appendChild(rootButton(root,expanded,context.activeRoot === root.key));
        if (root.children) {
          const disclosure = document.createElement('button');
          disclosure.type = 'button';
          disclosure.className = 'ia-disclosure';
          disclosure.dataset.iaDisclosure = root.key;
          disclosure.setAttribute('aria-label',(expanded ? 'Contraer ' : 'Expandir ') + root.label);
          disclosure.setAttribute('aria-expanded',String(expanded));
          disclosure.setAttribute('aria-controls','brvtal-menu-' + root.key);
          disclosure.addEventListener('click',() => toggleMenu(root.key));
          row.appendChild(disclosure);
        }
        entry.appendChild(row);
        if (root.children) {
          const subnav = document.createElement('div');
          subnav.className = 'ia-subnav';
          subnav.id = 'brvtal-menu-' + root.key;
          subnav.setAttribute('role','group');
          subnav.setAttribute('aria-label',root.label);
          subnav.hidden = !expanded;
          for (const child of root.children) {
            if (!isVisibleMenuItem(root,child)) continue;
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.iaChild = child.key;
            button.textContent = child.label;
            button.className = 'ia-child';
            if (activeChild(root,child,context)) button.classList.add('active');
            if (child.comingSoon) {
              button.disabled = true;
              button.title = 'Próximamente · módulo aún no disponible';
              button.appendChild(document.createTextNode(' · Próximamente'));
            } else {
              button.addEventListener('click',() => { followMenuDestination(child)?.catch?.(() => {}); });
            }
            subnav.appendChild(button);
          }
          entry.appendChild(subnav);
        }
        fragment.appendChild(entry);
      }
      nav.replaceChildren(fragment);
      if (focus) nav.querySelector(focus)?.focus({preventScroll:true});
    } finally {
      applyingNav = false;
      observeNavigation();
    }
    requestTicketAccess();
  }

  function observeNavigation() {
    navObserver?.observe(document.documentElement,{childList:true,subtree:true});
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
    if (title) {
      const navLabel = [...document.querySelectorAll('.side .nav button')]
        .find(button => buttonKey(button) === section)?.textContent?.trim();
      title.textContent = (navLabel || section).toUpperCase();
    }
    document.querySelectorAll('.side .nav > button').forEach(button => {
      button.classList.toggle('active', buttonKey(button) === section);
    });
  }

  function captureWorkspaceSnapshot() {
    const currentState = typeof state === 'object' && state ? state : null;
    return {
      section:String(currentState?.section || 'dashboard'),
      rows:currentState?.rows,
      url:`${location.pathname}${location.search}${location.hash}`,
      contentContext:document.querySelector('#admin-module-host [data-admin-module="content-core"]')?.dataset.iaContext || '',
      roots:['modal','eventModal']
        .map(id => ({id,root:document.getElementById(id)}))
        .filter(entry => entry.root?.classList.contains('open'))
    };
  }

  async function restoreWorkspaceSnapshot(snapshot, expectedToken = routeToken) {
    if (!snapshot || expectedToken !== routeToken) return false;
    window.BRVTALAdminModules?.cancel?.();
    try {
      if (snapshot.contentContext === 'events') {
        const restored = await invokeOriginalGo('events', expectedToken);
        if (!restored || expectedToken !== routeToken) return false;
        if (window.BRVTALAdminModules?.load) {
          ensureWorkspaceHost();
          await window.BRVTALAdminModules.load('content-core', {syncUrl:false});
          if (expectedToken !== routeToken) return false;
          const root = document.querySelector('#admin-module-host [data-admin-module="content-core"]');
          if (root) simplifyEventEditor(root);
        }
      } else if (snapshot.section === 'system' && typeof originalTech === 'function') {
        await originalTech.call(window,'system');
      } else {
        await invokeOriginalGo(snapshot.section, expectedToken);
      }
    } catch (_) {
      if (expectedToken !== routeToken) return false;
      restoreVisibleSection(snapshot.section);
    }

    if (expectedToken !== routeToken) return false;
    if (typeof state === 'object' && state) {
      state.section = snapshot.section;
      if (snapshot.rows !== undefined) state.rows = snapshot.rows;
    }
    snapshot.roots.forEach(entry => {
      if (entry.root.isConnected) return;
      const replacement = document.getElementById(entry.id);
      if (replacement) replacement.replaceWith(entry.root);
      else document.body.appendChild(entry.root);
    });
    const currentUrl = `${location.pathname}${location.search}${location.hash}`;
    if (currentUrl !== snapshot.url) {
      history.replaceState({brvtalAdminRoute:canonicalRouteSection(snapshot.section)},'',snapshot.url);
    }
    restoreVisibleSection(snapshot.section);
    setTimeout(rebuildNavigation,0);
    return true;
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

  async function loadContentCoreContext() {
    const token = ++routeToken;
    window.BRVTALAdminModules?.cancel?.();
    const visibleSection = 'events';
    const visibleApplied = await invokeOriginalGo(visibleSection, token);
    if (visibleApplied === false) return false;
    if (!visibleApplied || token !== routeToken) return undefined;

    if (!window.BRVTALAdminModules?.load) return true;
    ensureWorkspaceHost();
    await window.BRVTALAdminModules.load('content-core', {syncUrl:false});
    if (token !== routeToken) return undefined;

    const root = document.querySelector('#admin-module-host [data-admin-module="content-core"]');
    if (!root) throw new Error('DISCADMIN internal content workflow failed to mount');

    simplifyEventEditor(root);
    rebuildNavigation();
    restoreVisibleSection(visibleSection);
    return true;
  }

  window.go = async function(section) {
    if (section === 'content-core') section = 'events';

    if (section === 'dashboard' && !initialRouteApplied && String(window.state?.section || 'dashboard') === 'dashboard') {
      const requested = routeFromUrl();
      if (requested !== 'dashboard') {
        applyingRoute = true;
        try {
          const result = await navigateRoute(requested);
          if (result === false) return false;
          initialRouteApplied = true;
          return result;
        } finally {
          applyingRoute = false;
        }
      }
    }

    const previousWorkspace = captureWorkspaceSnapshot();
    const navigationOperation = requestWorkspaceNavigation(section);
    if (!navigationOperation.accepted) {
      recordNavigationDiagnostic('rejected', navigationOperation.reason || 'guard-rejected', section, previousWorkspace.section);
      return false;
    }
    if (!applyingRoute) initialRouteApplied = true;

    let result;
    let navigationToken = null;
    let dashboardRouteProvisional = false;
    try {
      if (section === 'events') {
        const applied = await loadContentCoreContext();
        if (applied === false) {
          cancelWorkspaceNavigation(navigationOperation);
          return false;
        }
        if (!applied) {
          cancelWorkspaceNavigation(navigationOperation);
          return undefined;
        }
      } else {
        const token = ++routeToken;
        navigationToken = token;
        window.BRVTALAdminModules?.cancel?.();
        if (dynamicModuleSections.has(section)) syncRouteUrl(section, 'push', true);
        if (section === 'dashboard') {
          syncRouteUrl(section, 'replace', true);
          dashboardRouteProvisional = true;
        }
        const applied = await invokeOriginalGo(section, token);
        if (applied === false) {
          cancelWorkspaceNavigation(navigationOperation);
          if (token === routeToken) await restoreWorkspaceSnapshot(previousWorkspace, token);
          recordNavigationDiagnostic('failed', 'original-go-false', section, previousWorkspace.section);
          return false;
        }
        if (!applied || token !== routeToken) {
          cancelWorkspaceNavigation(navigationOperation);
          recordNavigationDiagnostic('stale', 'route-token-changed', section, previousWorkspace.section);
          return undefined;
        }
        result = true;
      }
    } catch (error) {
      cancelWorkspaceNavigation(navigationOperation);
      if (section === 'dashboard' && navigationToken === routeToken) {
        await restoreWorkspaceSnapshot(previousWorkspace, navigationToken);
        recordNavigationDiagnostic('failed', 'original-go-rejected', section, previousWorkspace.section);
      }
      throw error;
    }
    const committed = commitWorkspaceNavigation(section,navigationOperation);
    if (committed === null) {
      recordNavigationDiagnostic('stale', 'unsaved-commit-token-changed', section, previousWorkspace.section);
      return undefined;
    }
    if (committed === false) {
      recordNavigationDiagnostic('failed', 'unsaved-commit-unstable', section, previousWorkspace.section);
      await restoreWorkspaceSnapshot(previousWorkspace);
      return false;
    }
    retireLegacyEditor();
    if (dashboardRouteProvisional) {
      const previousUrl = previousWorkspace.url;
      const currentUrl = `${location.pathname}${location.search}${location.hash}`;
      if (currentUrl !== previousUrl) {
        history.replaceState(
          {brvtalAdminRoute:canonicalRouteSection(previousWorkspace.section)},
          '',
          previousUrl
        );
      }
    }
    syncRouteUrl(section);
    rebuildNavigation();
    recordNavigationDiagnostic('committed', '', section, previousWorkspace.section);
    return result;
  };

  if (typeof originalTech === 'function') {
    window.tech = async function(section, ...args) {
      const previousWorkspace = captureWorkspaceSnapshot();
      const navigationOperation = requestWorkspaceNavigation(section);
      if (!navigationOperation.accepted) return false;
      if (!applyingRoute) initialRouteApplied = true;
      const token = ++routeToken;
      window.BRVTALAdminModules?.cancel?.();
      let result;
      try {
        result = await originalTech.apply(this, [section, ...args]);
      } catch (error) {
        cancelWorkspaceNavigation(navigationOperation);
        throw error;
      }
      if (token !== routeToken) {
        cancelWorkspaceNavigation(navigationOperation);
        return result;
      }
      const committed = commitWorkspaceNavigation(section,navigationOperation);
      if (committed === null) return undefined;
      if (committed === false) {
        await restoreWorkspaceSnapshot(previousWorkspace);
        return false;
      }
      retireLegacyEditor();
      syncRouteUrl(section);
      setTimeout(rebuildNavigation, 0);
      return result;
    };
  }

  window.openModal = async function(type, id = null) {
    if (type === 'events') {
      // A grid EDIT already has a mounted workflow. Re-navigating it would
      // discard its loaded record cache and race a NEW EVENT form against EDIT.
      const context = document.querySelector('#admin-module-host [data-admin-module="content-core"][data-ia-context="events"]');
      if (window.state?.section !== 'events' || !context) {
        const navigated = await window.go('events');
        if (navigated === false) return false;
      }
      const workflow = window.BRVTALContentCore;
      const mounted = document.querySelector('#admin-module-host [data-admin-module="content-core"][data-ia-context="events"]');
      if (!mounted || typeof workflow?.openEvent !== 'function') {
        throw new Error('DISCADMIN Events editor is not available');
      }
      const editor = mounted.querySelector('#eventModal');
      if (editor?.classList.contains('open')) {
        if (editor.inert) return false; // Navigation guard has the editor locked.
        const targetId = id == null ? 'new' : String(id);
        if (editor.dataset.eventId === targetId) return true; // Preserve in-progress edits.
        const unsaved = window.BRVTALUnsavedChanges;
        if (unsaved?.isDirty?.(editor)) {
          // The mounted path bypasses go('events'), so honor its discard guard
          // before switching records. A rejected confirmation preserves edits.
          if (typeof unsaved.requestClose !== 'function'
            || unsaved.requestClose(editor, () => editor.classList.remove('open')) !== true) return false;
        }
      }
      workflow.openEvent(id);
      return true;
    }
    return originalOpenModal?.apply(this, arguments);
  };

  window.BRVTALAdminIA = {
    guardsUnsavedChanges:true,
    rebuildNavigation,
    openEvents:() => window.go('events'),
    readRoute: routeFromUrl,
    applyRoute: applyUrlRoute,
    navigationDiagnostics:() => ({...lastNavigationDiagnostic})
  };

  window.addEventListener('popstate', () => {
    initialRouteApplied = true;
    applyUrlRoute().catch(() => {});
  });

  // Only replace the navigation tree when the *shell* is recreated.
  // Watching every workspace mutation caused continuous detaches: live
  // dashboard telemetry could repaint the sidebar between pointerdown/click.
  navObserver = new MutationObserver(mutations => {
    if (applyingNav) return;
    const replacedShell = mutations.some(mutation =>
      [...mutation.addedNodes, ...mutation.removedNodes].some(node =>
        node.nodeType === 1 && (
          node.matches?.('#app,.shell,.side,.nav')
          || !!node.querySelector?.('.shell .side .nav')
        )
      )
    );
    if (replacedShell) {
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