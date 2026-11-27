/* Ticketing T17: read-only workspace inside the canonical DISCADMIN shell. */
window.BRVTALTicketing = (() => {
  'use strict';
  // #1986: modules are served immutable for a year; carry this deploy's ?v= to every import.
  const moduleVersion = (() => { try { return new URL(document.currentScript.src).search; } catch { return ''; } })();
  const modulePath = name => '/discadmin/' + name + '.js' + moduleVersion;
  // #1964 (A): task-oriented copy; provider state lives in the status strip above.
  const descriptions = {
    dashboard:'Aquí verás ventas, ingresos y lo que necesita atención de cada evento.',
    orders:'Busca pedidos por evento, estado o comprador.', entries:'Consulta y gestiona las boletas emitidas.',
    buyers:'Busca compradores y su historial.', door:'Abre el lector para validar boletas en la entrada.', courtesies:'Consulta y gestiona invitaciones sin cobro.',
    finance:'Concilia pagos, comisiones y caja por evento.', promos:'Crea y gestiona los cupones de descuento de cada evento.',
    promoters:'Todavía no hay promotores configurados.', mail:'Edita los correos que reciben los compradores.',
    recovery:'Recuerda a quien dejó una compra sin terminar.'
  };
  const titles = {
    dashboard:'Resumen', orders:'Pedidos', entries:'Boletas', buyers:'Compradores',
    door:'Puerta', courtesies:'Cortesías', finance:'Finanzas', promos:'Cupones', promoters:'Promotores',
    mail:'Correos', recovery:'Recuperación de compras'
  };
  let stopDoor = null;
  let stopMail = null;
  let selectionGeneration = 0;
  function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  }
  function select(root, key, focus = false) {
    const tabs = [...root.querySelectorAll('[role="tab"][data-ticketing-tab]')];
    const active = tabs.find(tab => tab.dataset.ticketingTab === key);
    if (!active) return false;
    // #1964: only the active group's sections are shown; the group button reflects it.
    const group = active.dataset.ticketingTabGroup;
    for (const tab of tabs) {
      const chosen = tab === active;
      tab.setAttribute('aria-selected', String(chosen));
      tab.tabIndex = chosen ? 0 : -1;
      if (group) tab.hidden = tab.dataset.ticketingTabGroup !== group;
    }
    for (const button of root.querySelectorAll('[data-ticketing-group]')) {
      button.setAttribute('aria-pressed', String(button.dataset.ticketingGroup === group));
    }
    const panel = root.querySelector('[data-ticketing-panel]');
    if (!panel) return false;
    panel.setAttribute('aria-labelledby', active.id);
    const generation = ++selectionGeneration;
    if (stopDoor) { stopDoor(); stopDoor = null; }
    if (stopMail) { stopMail(); stopMail = null; }
    panel.replaceChildren(element('h2', titles[key]), element('p', descriptions[key], 'ticketing-empty'));
    if (key === 'recovery') {
      const frame = document.createElement('iframe');
      frame.title = 'Panel de recuperación de compras';
      frame.src = '/discadmin/ticketing-recovery.php';
      frame.loading = 'lazy';
      frame.referrerPolicy = 'no-referrer';
      frame.className = 'ticketing-frame';
      panel.appendChild(frame);
    }
    if (key === 'door') {
      const launch = element('button', 'Abrir lector móvil de puerta', 'btn ghost');
      launch.type = 'button';
      launch.addEventListener('click', async () => {
        launch.disabled = true;
        try {
          const response = await fetch('/discadmin/ticketing-door.php', {
            credentials: 'same-origin', cache: 'no-store',
            headers: {'X-BRVTAL-ADMIN-FRAGMENT': '1'}
          });
          if (!response.ok) throw new Error('Puerta no disponible');
          const fragment = new DOMParser().parseFromString(await response.text(), 'text/html');
          const door = fragment.querySelector('[data-door]');
          if (!door) throw new Error('Respuesta inválida');
          const module = await import(modulePath('ticketing-door'));
          if (!panel.isConnected || panel.getAttribute('aria-labelledby') !== active.id) return;
          if (!document.querySelector('link[data-brvtal-ticketing-door]')) {
            const style = document.createElement('link');
            style.rel = 'stylesheet';
            style.href = '/discadmin/ticketing-door.css';
            style.dataset.brvtalTicketingDoor = '1';
            document.head.appendChild(style);
          }
          panel.replaceChildren(document.importNode(door, true));
          stopDoor = module.mount(panel.querySelector('[data-door]'));
        } catch {
          if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id) {
            launch.disabled = false;
            panel.replaceChildren(element('p', 'No se pudo cargar el lector de puerta.', 'ticketing-error'), launch);
          }
        }
      });
      panel.appendChild(launch);
    }
    if (key === 'courtesies') {
      const current = () => panel.isConnected && generation === selectionGeneration
        && panel.getAttribute('aria-labelledby') === active.id;
      import(modulePath('ticketing-courtesy')).then(mod => {
        if (current()) mod.mount(panel, current, root.dataset.ticketingCsrf || '');
      }).catch(() => {
        if (current()) panel.replaceChildren(element('p', 'Cortesías no disponibles.', 'ticketing-error'));
      });
    }
    if (key === 'entries') {
      import(modulePath('ticketing-entries')).then(mod => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id
            && generation === selectionGeneration)
          return mod.mount(panel, () => panel.isConnected
            && panel.getAttribute('aria-labelledby') === active.id
            && generation === selectionGeneration);
      }).catch(() => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id)
          panel.replaceChildren(element('p', 'No se pudo cargar Boletas.', 'ticketing-error'));
      });
    }
    if (key === 'orders' || key === 'buyers') {
      import(modulePath('ticketing-orders')).then(mod => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id) {
          return mod.mount(panel, key);
        }
      }).catch(() => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id) {
          panel.replaceChildren(element('p', 'No se pudo cargar Pedidos y Compradores.', 'ticketing-error'));
        }
      });
    }
    if (key === 'finance') {
      import(modulePath('ticketing-finance')).then(async mod => {
        if (!panel.isConnected || panel.getAttribute('aria-labelledby') !== active.id) return;
        await mod.mount(panel);
        if (!panel.isConnected || panel.getAttribute('aria-labelledby') !== active.id) return;
        // T21 shares the single finance workspace. The API still enforces staff capability.
        if (['superadmin','root','owner','platform_owner','admin'].includes(root.dataset.ticketingRole)) {
          const openRefunds = element('button', 'Devoluciones', 'tk-button');
          openRefunds.type = 'button';
          openRefunds.dataset.financeOpenRefunds = '';
          openRefunds.setAttribute('aria-pressed','false');
          openRefunds.addEventListener('click', async () => {
            openRefunds.disabled = true;
            const outlet = panel.querySelector('[data-finance-tool-panel]') || panel;
            try {
              const refunds = await import(modulePath('ticketing-refunds'));
              if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id) {
                if (outlet !== panel) outlet.replaceChildren();
                await refunds.mount(outlet);
                openRefunds.setAttribute('aria-pressed','true');
                panel.querySelector('[data-finance-open-pos]')?.setAttribute('aria-pressed','false');
              }
            } catch {
              if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id)
                outlet.append(element('p','Devoluciones no disponibles.','ticketing-error'));
            } finally { openRefunds.disabled = false; }
          });
          (panel.querySelector('[data-finance-actions]') || panel).append(openRefunds);
        }
      }).catch(() => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id)
          panel.replaceChildren(element('p', 'Finanzas no disponibles.', 'ticketing-error'));
      });
    }
    if (key === 'promos') {
      import(modulePath('ticketing-promos')).then(mod => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id)
          mod.mount(panel);
      }).catch(() => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id)
          panel.replaceChildren(element('p', 'Cupones no disponibles.', 'ticketing-error'));
      });
    }
    if (key === 'mail') {
      // Load one editor surface under the existing ticketing panel; no second shell.
      if (!document.querySelector('link[data-brvtal-ticketing-mail]')) {
        const style = document.createElement('link');
        style.rel = 'stylesheet';
        style.href = '/discadmin/ticketing-mail-templates.css';
        style.dataset.brvtalTicketingMail = '1';
        document.head.append(style);
      }
      import(modulePath('ticketing-mail-templates')).then(mod => {
        if (!panel.isConnected || panel.getAttribute('aria-labelledby') !== active.id
            || generation !== selectionGeneration) return;
        stopMail = mod.mount(panel, () => panel.isConnected
          && panel.getAttribute('aria-labelledby') === active.id
          && generation === selectionGeneration, root.dataset.ticketingRole || '');
      }).catch(() => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id
            && generation === selectionGeneration) {
          const retry = element('button', 'Reintentar editor de correos', 'btn ghost');
          retry.type = 'button';
          retry.addEventListener('click', () => select(root, 'mail'));
          panel.replaceChildren(element('p', 'No se pudo cargar el editor de correos.', 'ticketing-error'), retry);
        }
      });
    }
    if (focus) active.focus();
    return true;
  }
  function wire(root) {
    const list = root.querySelector('[role="tablist"]');
    if (!list) return;
    list.addEventListener('click', e => {
      const tab = e.target.closest('[data-ticketing-tab]');
      if (tab && list.contains(tab) && select(root, tab.dataset.ticketingTab)) rememberTab(tab.dataset.ticketingTab, true);
    });
    // #1964: a group opens its first section; Configurar jumps to Configuración › Integraciones.
    root.querySelector('.ticketing-groups')?.addEventListener('click', e => {
      const button = e.target.closest('[data-ticketing-group]');
      if (!button) return;
      const firstTab = list.querySelector(`[data-ticketing-tab-group="${button.dataset.ticketingGroup}"]`);
      if (firstTab && select(root, firstTab.dataset.ticketingTab)) rememberTab(firstTab.dataset.ticketingTab, true);
    });
    root.querySelector('[data-ticketing-configure]')?.addEventListener('click', async () => {
      if (typeof window.go !== 'function') return;
      await window.go('settings');
      document.querySelector('[data-settings-tab="integrations"]')?.click();
    });
    list.addEventListener('keydown', e => {
      if (!['ArrowRight','ArrowLeft','Home','End'].includes(e.key)) return;
      const tabs = [...list.querySelectorAll('[data-ticketing-tab]:not([hidden])')];
      const current = tabs.indexOf(document.activeElement);
      if (current < 0) return;
      e.preventDefault();
      let next = current;
      switch(e.key) {
        case 'Home': next = 0; break;
        case 'End': next = tabs.length - 1; break;
        case 'ArrowRight': next = (current + 1) % tabs.length; break;
        case 'ArrowLeft': next = (current - 1 + tabs.length) % tabs.length; break;
      }
      if (select(root, tabs[next].dataset.ticketingTab, true)) rememberTab(tabs[next].dataset.ticketingTab, true);
    });
    // #1892: el menú abre una pestaña concreta con ?module=ticketing&tab=…; solo
    // se aceptan pestañas que el servidor ya filtró por rol.
    const requested = new URLSearchParams(location.search).get('tab') || '';
    const tabs = [...list.querySelectorAll('[data-ticketing-tab]')];
    const first = tabs.find(tab => tab.dataset.ticketingTab === requested)
      || list.querySelector('[aria-selected="true"]') || tabs[0];
    if (first) select(root, first.dataset.ticketingTab);
    document.dispatchEvent(new CustomEvent('brvtal:ticketing-tabs', {
      detail:{tabs:tabs.map(tab => tab.dataset.ticketingTab)}
    }));
  }
  function rememberTab(key, push = false) {
    const url = new URL(location.href);
    if (url.searchParams.get('module') !== 'ticketing') return;
    if (url.searchParams.get('tab') === key) return;
    url.searchParams.set('tab', key);
    history[push ? 'pushState' : 'replaceState']({brvtalAdminRoute:'ticketing'}, '', url);
  }
  function selectTab(key) {
    const root = document.querySelector('#admin-module-host [data-admin-module="ticketing"]');
    if (!root || !select(root, key)) return false;
    rememberTab(key, true);
    return true;
  }
  async function mount() {
    const host = document.getElementById('admin-module-host');
    if (!host || window.state?.authed !== true || window.state?.section !== 'ticketing') return false;
    host.replaceChildren(element('p', 'Cargando Boletería…', 'ticketing-loading'));
    try {
      const response = await fetch('/discadmin/ticketing.php', {
        credentials:'same-origin', cache:'no-store',
        headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}
      });
      if (response.status === 401) {
        if (window.state) window.state.authed = false;
        if (typeof window.render === 'function') window.render();
        return false;
      }
      if (response.status === 403) {
        host.replaceChildren(element('p', 'Sin permiso para acceder a Boletería.', 'ticketing-denied'));
        return true; // The shell must preserve the explicit denied workspace.
      }
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html');
      const module = documentFragment.querySelector('[data-admin-module="ticketing"]');
      if (!module) throw new Error('Respuesta inválida');
      if (!host.isConnected || window.state?.section !== 'ticketing') return false;
      host.replaceChildren(document.importNode(module, true));
      wire(host.firstElementChild);
      return true;
    } catch {
      // Intentionally suppress raw provider/transport error details; expose a safe retry.
      if (!host.isConnected || window.state?.section !== 'ticketing') return false;
      const retry = element('button', 'Reintentar', 'btn ghost');
      retry.type = 'button'; retry.addEventListener('click', () => mount());
      host.replaceChildren(element('p', 'No se pudo cargar Boletería.', 'ticketing-error'), retry);
      return true; // Keep retry visible inside the canonical workspace.
    }
  }
  async function open(options = {}) {
    if (typeof window.go !== 'function') return false;
    const result = await window.go('ticketing');
    if (result === false || window.state?.section !== 'ticketing') return false;
    const url = new URL(location.href);
    url.searchParams.set('module', 'ticketing');
    history[options.replace ? 'replaceState' : 'pushState']({brvtalAdminRoute:'ticketing'}, '', url);
    return true;
  }
  document.addEventListener('click', e => {
    if (e.target.closest('#app [data-admin-nav="ticketing"]')) {
      e.preventDefault(); e.stopImmediatePropagation();
      open().catch(() => {});
    }
  }, true);
  window.addEventListener('popstate', e => {
    if (new URLSearchParams(location.search).get('module') !== 'ticketing') return;
    e.stopImmediatePropagation();
    open({replace:true}).catch(() => {});
  }, true);
  return {mount, open, select, selectTab};
})();
