/* Ticketing T17: read-only workspace inside the canonical DISCADMIN shell. */
window.BRVTALTicketing = (() => {
  'use strict';
  const descriptions = {
    dashboard:'Las métricas aparecerán cuando existan pedidos confirmados.',
    orders:'No hay pedidos para mostrar.', entries:'No hay boletas individuales emitidas.',
    buyers:'No hay compradores para mostrar.', door:'El escáner aún no está habilitado.',
    finance:'No hay conciliaciones disponibles.', promos:'No hay promociones disponibles.',
    promoters:'No hay promotores configurados.', mail:'No hay correos transaccionales.',
    settings:'Los proveedores permanecen inactivos hasta su configuración y autorización.'
  };
  const titles = {
    dashboard:'Dashboard', orders:'Pedidos', entries:'Boletas', buyers:'Compradores',
    door:'Puerta', finance:'Finanzas', promos:'Promos', promoters:'Promotores',
    mail:'Correos', settings:'Ajustes'
  };
  let stopDoor = null;
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
    for (const tab of tabs) {
      const chosen = tab === active;
      tab.setAttribute('aria-selected', String(chosen));
      tab.tabIndex = chosen ? 0 : -1;
    }
    const panel = root.querySelector('[data-ticketing-panel]');
    if (!panel) return false;
    panel.setAttribute('aria-labelledby', active.id);
    const generation = ++selectionGeneration;
    if (stopDoor) { stopDoor(); stopDoor = null; }
    panel.replaceChildren(element('h2', titles[key]), element('p', descriptions[key], 'ticketing-empty'));
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
          const module = await import('/discadmin/ticketing-door.js');
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
    if (key === 'entries') {
      import('/discadmin/ticketing-entries.js').then(mod => {
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
      import('/discadmin/ticketing-orders.js').then(mod => {
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
      import('/discadmin/ticketing-finance.js').then(async mod => {
        if (!panel.isConnected || panel.getAttribute('aria-labelledby') !== active.id) return;
        await mod.mount(panel);
        if (!panel.isConnected || panel.getAttribute('aria-labelledby') !== active.id) return;
        // T21 shares the single finance workspace. The API still enforces staff capability.
        if (['superadmin','root','owner','platform_owner'].includes(root.dataset.ticketingRole)) {
          const openRefunds = element('button', 'Gestionar devoluciones · pruebas', 'btn ghost');
          openRefunds.type = 'button';
          openRefunds.addEventListener('click', async () => {
            openRefunds.disabled = true;
            try {
              const refunds = await import('/discadmin/ticketing-refunds.js');
              if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id)
                await refunds.mount(panel);
            } catch {
              if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id)
                panel.replaceChildren(element('p', 'Devoluciones no disponibles.', 'ticketing-error'));
            } finally { openRefunds.disabled = false; }
          });
          panel.append(openRefunds);
        }
      }).catch(() => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id)
          panel.replaceChildren(element('p', 'Finanzas no disponibles.', 'ticketing-error'));
      });
    }
    if (key === 'promos') {
      import('/discadmin/ticketing-promos.js').then(mod => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id)
          mod.mount(panel);
      }).catch(() => {
        if (panel.isConnected && panel.getAttribute('aria-labelledby') === active.id)
          panel.replaceChildren(element('p', 'Promos no disponibles.', 'ticketing-error'));
      });
    }
    if (key === 'settings') {
      const group = element('fieldset', undefined, 'ticketing-settings');
      group.appendChild(element('legend', 'Integraciones (solo consulta)'));
      for (const [name, mode] of [['Wompi', root.dataset.wompiMode], ['Resend', root.dataset.resendMode]]) {
        const line = element('label', name + ': ' + (mode === 'disabled' ? 'INACTIVO' : 'NO VERIFICADO'));
        const toggle = document.createElement('input');
        toggle.type = 'checkbox'; toggle.disabled = true; toggle.checked = false;
        line.prepend(toggle); group.appendChild(line);
      }
      group.appendChild(element('p', 'Salud: no verificada. No se exponen credenciales ni se activan cobros.'));
      for (const label of ['Guardar', 'Guardar y cerrar']) {
        const button = element('button', label, 'btn ghost');
        button.type = 'button'; button.disabled = true;
        button.title = 'Sin configuración autorizada para persistir';
        group.appendChild(button);
      }
      panel.appendChild(group);
    }
    if (focus) active.focus();
    return true;
  }
  function wire(root) {
    const list = root.querySelector('[role="tablist"]');
    if (!list) return;
    list.addEventListener('click', e => {
      const tab = e.target.closest('[data-ticketing-tab]');
      if (tab && list.contains(tab)) select(root, tab.dataset.ticketingTab);
    });
    list.addEventListener('keydown', e => {
      if (!['ArrowRight','ArrowLeft','Home','End'].includes(e.key)) return;
      const tabs = [...list.querySelectorAll('[data-ticketing-tab]')];
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
      select(root, tabs[next].dataset.ticketingTab, true);
    });
    const first = list.querySelector('[aria-selected="true"]') || list.querySelector('[data-ticketing-tab]');
    if (first) select(root, first.dataset.ticketingTab);
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
  return {mount, open, select};
})();
