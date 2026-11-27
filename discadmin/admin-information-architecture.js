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
    'theme','settings','security','system','backups','activity','ticketing','forms'
  ]);
  const dynamicModuleSections = new Set(['media','releases','blog','seo','forms']);
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
    const next = `${url.pathname}${url.search}${url.hash}`;
    const current = `${location.pathname}${location.search}${location.hash}`;
    if (next === current) return;
    history[mode === 'replace' ? 'replaceState' : 'pushState']({brvtalAdminRoute: canonical}, '', next);
  }

  async function navigateRoute(section) {
    const canonical = canonicalRouteSection(section);
    if (canonical === 'system' && typeof window.tech === 'function') return window.tech('system');
    if (canonical === 'ticketing' && window.BRVTALTicketing?.open) return window.BRVTALTicketing.open({replace:true});
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

  // #1892 · Menú DISCADMIN V3 aprobado por el dueño: exactamente 8 raíces en este
  // orden. El menú solo navega a módulos; crear/editar/publicar viven en el workspace.
  const SOON = 'Próximamente';
  const NAV_TREE = [
    {key:'dashboard', label:'ESCRITORIO', route:'dashboard'},
    {key:'events', label:'EVENTOS', route:'events'},
    {key:'artists', label:'ARTISTAS', route:'artists'},
    {key:'music', label:'MÚSICA', children:[
      {key:'sets', label:'SETS', route:'sets'},
      {key:'releases', label:'RELEASES', route:'releases'}
    ]},
    {key:'ticketing', label:'BOLETERÍA', route:'ticketing', children:[
      {key:'ticketing:orders', label:'PEDIDOS', tab:'orders'},
      {key:'ticketing:entries', label:'BOLETAS', tab:'entries'},
      {key:'ticketing:buyers', label:'COMPRADORES', tab:'buyers'},
      {key:'ticketing:door', label:'CONTROL DE ACCESO', tab:'door'},
      {key:'ticketing:courtesies', label:'CORTESÍAS', tab:'courtesies'},
      {key:'ticketing:promos', label:'PROMOCIONES', tab:'promos'},
      {key:'ticketing:promoters', label:'PROMOTORES', tab:'promoters'},
      {key:'ticketing:finance', label:'FINANZAS', tab:'finance'},
      {key:'ticketing:refunds', label:'DEVOLUCIONES', tab:'finance'}
    ]},
    {key:'mail', label:'CORREOS', children:[
      {key:'mail:history', label:'HISTORIAL DE ENVÍOS', tab:'mail'},
      {key:'mail:templates', label:'PLANTILLAS', soon:true, requires:'mail'},
      {key:'mail:tests', label:'PRUEBAS DE ENVÍO', route:'settings', settings:'integrations'}
    ]},
    {key:'website', label:'SITIO WEB', children:[
      {key:'pages', label:'PÁGINAS', route:'pages'},
      {key:'blog', label:'BLOG', route:'blog'},
      {key:'media', label:'MULTIMEDIA', route:'media'},
      {key:'hero-slider', label:'BANNERS', route:'hero-slider'},
      {key:'theme', label:'APARIENCIA', route:'theme'},
      {key:'seo', label:'SEO', route:'seo'},
      {key:'forms', label:'FORMULARIOS', route:'forms'}
    ]},
    {key:'settings', label:'CONFIGURACIÓN', children:[
      {key:'settings:general', label:'GENERAL', route:'settings', settings:'general'},
      {key:'settings:social', label:'REDES SOCIALES Y CONTACTO', route:'settings', settings:'social'},
      {key:'settings:integrations', label:'INTEGRACIONES', route:'settings', settings:'integrations'},
      {key:'security', label:'SEGURIDAD', route:'security'},
      {key:'system', label:'ESTADO DEL SISTEMA', route:'system'},
      {key:'backups', label:'COPIAS DE SEGURIDAD', route:'backups'}
    ]}
  ];
  const expandedMenus = {};
  let lastActiveKey = '';
  let lastScrolledKey = '';
  // Pestañas de Boletería permitidas por el servidor para el rol actual. null = aún desconocido.
  let ticketingTabs = null;
  document.addEventListener('brvtal:ticketing-tabs', event => {
    const tabs = event.detail?.tabs;
    if (!Array.isArray(tabs)) return;
    ticketingTabs = tabs.slice();
    scheduleNavigation();
  });

  function observeNavigation() {
    navObserver?.observe(document.documentElement,{childList:true,subtree:true});
  }

  function urlParam(name) {
    return new URLSearchParams(location.search).get(name) || '';
  }

  // Clave del destino visible ahora mismo, para resaltar la hoja y abrir su raíz.
  function activeNavKey() {
    const section = String(window.state?.section || 'dashboard');
    if (section === 'content-core') return 'events';
    if (section === 'ticketing') {
      const tab = urlParam('tab');
      if (tab === 'mail') return 'mail:history';
      if (tab && tab !== 'dashboard') return 'ticketing:' + tab;
      return 'ticketing';
    }
    if (section === 'settings') {
      const tab = document.querySelector('#app [data-settings-tab].active')?.dataset.settingsTab
        || urlParam('settings') || 'general';
      return ['general','social','integrations'].includes(tab) ? 'settings:' + tab : 'settings:general';
    }
    return section;
  }

  // El servidor filtra las pestañas por rol en el fragmento existente de Boletería;
  // hasta conocerlas, sus hojas no se muestran (fallo cerrado para el rol Puerta).
  let ticketingTabsRequest = null;
  function loadTicketingTabs() {
    if (ticketingTabs !== null || ticketingTabsRequest || window.state?.authed !== true) return;
    ticketingTabsRequest = fetch('/discadmin/ticketing.php', {
      credentials:'same-origin', cache:'no-store', headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}
    }).then(async response => {
      if (response.status === 403) return [];
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const fragment = new DOMParser().parseFromString(await response.text(), 'text/html');
      return [...fragment.querySelectorAll('[data-ticketing-tab]')].map(tab => tab.dataset.ticketingTab);
    }).then(tabs => {
      if (ticketingTabs === null) ticketingTabs = tabs;
      scheduleNavigation();
    }).catch(() => {}).finally(() => { ticketingTabsRequest = null; });
  }

  function leafAllowed(item) {
    const capability = item.tab || item.requires;
    if (!capability) return true;
    if (ticketingTabs === null) { loadTicketingTabs(); return false; }
    return ticketingTabs.includes(capability);
  }

  function visibleChildren(root) {
    return (root.children || []).filter(leafAllowed);
  }

  function rootContains(root, key) {
    return root.key === key || (root.children || []).some(child => child.key === key);
  }

  async function navigateTo(item) {
    if (item.soon) return false;
    const url = new URL(location.href);
    if (item.tab) {
      url.searchParams.set(ROUTE_PARAM,'ticketing');
      url.searchParams.set('tab',item.tab);
      url.searchParams.delete('settings');
      history.pushState({brvtalAdminRoute:'ticketing'},'',url);
      if (window.state?.section === 'ticketing' && window.BRVTALTicketing?.selectTab?.(item.tab)) {
        rebuildNavigation();
        return true;
      }
      return window.BRVTALTicketing?.open ? window.BRVTALTicketing.open({replace:true}) : window.go('ticketing');
    }
    if (item.settings) {
      url.searchParams.delete('tab');
      url.searchParams.set(ROUTE_PARAM,'settings');
      url.searchParams.set('settings',item.settings);
      history.pushState({brvtalAdminRoute:'settings'},'',url);
      const tabButton = window.state?.section === 'settings'
        && document.querySelector(`[data-settings-tab="${item.settings}"]`);
      if (tabButton) { tabButton.click(); rebuildNavigation(); return true; }
      return window.go('settings');
    }
    let result;
    if (item.route === 'system' && typeof window.tech === 'function') result = await window.tech('system');
    else if (item.route === 'ticketing' && window.BRVTALTicketing?.open) result = await window.BRVTALTicketing.open();
    else result = await window.go(item.route);
    // Solo se limpia la entrada nueva: la anterior conserva su pestaña para Atrás.
    if (result !== false) dropStaleParams(['tab','settings']);
    return result;
  }

  function dropStaleParams(names) {
    const next = new URL(location.href);
    let changed = false;
    for (const name of names) {
      if (!next.searchParams.has(name)) continue;
      next.searchParams.delete(name);
      changed = true;
    }
    if (changed) history.replaceState(history.state,'',next);
  }

  function navButton(item, depth, activeKey) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = item.label;
    button.dataset.adminNav = item.key;
    button.dataset.iaKey = item.key;
    if (depth) button.dataset.iaSubnav = '1';
    if (item.soon) {
      button.setAttribute('aria-disabled','true');
      button.dataset.iaSoon = '1';
      const badge = document.createElement('span');
      badge.className = 'ia-nav-soon';
      badge.textContent = SOON;
      button.append(badge);
    } else {
      button.addEventListener('click', event => {
        event.preventDefault();
        navigateTo(item).catch(() => {});
      });
    }
    const active = item.key === activeKey;
    button.classList.toggle('active',active);
    if (active) button.setAttribute('aria-current','page');
    return button;
  }

  // Se abre/cierra en su sitio: no se reconstruye el DOM, así el foco no se pierde.
  function toggleMenu(key) {
    expandedMenus[key] = !expandedMenus[key];
    const open = expandedMenus[key];
    const nav = document.querySelector('.side .nav');
    const toggle = nav?.querySelector(`[data-ia-toggle="${key}"]`);
    const list = document.getElementById('brvtal-nav-' + key);
    if (!toggle || !list) { rebuildNavigation(); return; }
    toggle.setAttribute('aria-expanded', String(open));
    if (toggle.classList.contains('ia-nav-chevron')) {
      const label = NAV_TREE.find(root => root.key === key)?.label.toLowerCase() || '';
      toggle.setAttribute('aria-label', (open ? 'Contraer ' : 'Desplegar ') + label);
    }
    list.hidden = !open;
    if (nav) nav.dataset.iaSig = navigationSignature();
  }

  function navigationSignature() {
    return JSON.stringify([activeNavKey(), expandedMenus, ticketingTabs, window.state?.authed === true]);
  }

  function appendRoot(nav, root, activeKey) {
    const children = visibleChildren(root);
    if (!children.length) {
      nav.appendChild(navButton(root, 0, activeKey));
      return;
    }
    const containsActive = rootContains(root, activeKey);
    if (containsActive && lastActiveKey !== activeKey) expandedMenus[root.key] = true;
    const open = Boolean(expandedMenus[root.key]);
    const group = document.createElement('div');
    group.className = 'ia-navgroup';
    group.dataset.iaGroup = root.key;
    const listId = 'brvtal-nav-' + root.key;
    const head = document.createElement('div');
    head.className = 'ia-navhead';
    let toggle;
    if (root.route) {
      // Boletería: el padre abre su resumen y un chevron aparte despliega los hijos.
      const parent = navButton(root, 0, activeKey);
      parent.classList.toggle('ia-branch-active', containsActive);
      toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'ia-nav-chevron';
      toggle.setAttribute('aria-label', (open ? 'Contraer ' : 'Desplegar ') + root.label.toLowerCase());
      head.append(parent, toggle);
    } else {
      toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'ia-nav-parent';
      toggle.textContent = root.label;
      toggle.dataset.adminNav = root.key;
      toggle.dataset.iaKey = root.key;
      toggle.classList.toggle('ia-branch-active', containsActive);
      head.append(toggle);
    }
    toggle.dataset.iaToggle = root.key;
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-controls', listId);
    toggle.addEventListener('click', () => toggleMenu(root.key));
    const list = document.createElement('div');
    list.className = 'ia-navchildren';
    list.id = listId;
    list.hidden = !open;
    list.setAttribute('role','group');
    list.setAttribute('aria-label', root.label.toLowerCase());
    for (const child of children) list.appendChild(navButton(child, 1, activeKey));
    group.append(head, list);
    nav.appendChild(group);
  }

  function rebuildNavigation() {
    if (applyingNav) return;
    const nav = document.querySelector('.side .nav');
    if (!nav) return;
    // Sin cambios reales (destino, desplegables, permisos, sesión) no se toca el DOM.
    if (nav.dataset.iaTree === 'v3' && nav.querySelector('.ia-navgroup, [data-ia-key]')
      && nav.dataset.iaSig === navigationSignature()) return;
    applyingNav = true;
    navObserver?.disconnect();
    try {
      const focusedToggle = document.activeElement?.dataset?.iaToggle || '';
      const focusedKey = !focusedToggle && nav.contains(document.activeElement)
        ? document.activeElement?.dataset?.iaKey || '' : '';
      if (window.state?.authed !== true) ticketingTabs = null;
      const activeKey = activeNavKey();
      const scrollTop = nav.scrollTop;
      nav.replaceChildren();
      for (const root of NAV_TREE) appendRoot(nav, root, activeKey);
      // Memorias vive dentro de Multimedia; el marcador oculto evita que memories.js
      // vuelva a insertar su propio botón en el menú.
      const memories = document.createElement('button');
      memories.type = 'button';
      memories.hidden = true;
      memories.tabIndex = -1;
      memories.dataset.memoriesNav = '1';
      memories.dataset.iaHidden = '1';
      memories.setAttribute('aria-hidden','true');
      nav.appendChild(memories);
      nav.dataset.iaTree = 'v3';
      nav.dataset.iaSig = navigationSignature();
      lastActiveKey = activeKey;
      nav.scrollTop = scrollTop;
      const activeButton = nav.querySelector('button.active');
      if (activeButton && lastScrolledKey !== activeKey) {
        lastScrolledKey = activeKey;
        activeButton.scrollIntoView?.({block:'nearest'});
      }
      if (focusedToggle) nav.querySelector(`[data-ia-toggle="${focusedToggle}"]`)?.focus();
      else if (focusedKey) nav.querySelector(`[data-ia-key="${CSS.escape(focusedKey)}"]`)?.focus();
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
    if (title) {
      const navLabel = [...document.querySelectorAll('.side .nav button')]
        .find(button => buttonKey(button) === section)?.textContent?.trim();
      title.textContent = navLabel || section.toUpperCase();
    }
    rebuildNavigation();
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
