(() => {
  'use strict';

  const ENDPOINTS = {
    overview:'/api/dashboard-overview.php',
    content:'/api/content-health.php',
    health:'/api/index.php/health',
    storage:'/discadmin/storage-metrics.php',
    activity:'/api/admin-activity.php?limit=5',
    preferences:'/api/admin-dashboard-preferences.php'
  };
  const sectionFor = type => ({events:'events',artists:'artists',sets:'sets',releases:'releases',pages:'pages',blog:'blog',ticket_types:'events',event_lineup:'events'})[type] || 'dashboard';
  let mounting = false;
  let mountSerial = 0;
  let layoutSaveSerial = 0;
  let layoutSaveChain = Promise.resolve();

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  }

  function normalizePath(value) {
    const raw = String(value || '').trim();
    if (!raw || /^(?:https?:\/\/|data:|blob:)/i.test(raw) || raw.startsWith('/')) return raw;
    return '/' + raw.replace(/^\.?\//,'').replace(/^\/+/, '');
  }

  function stateClass(score) {
    const n = Number(score || 0);
    if (n >= 80) return 'good';
    if (n >= 60) return 'warn';
    return 'bad';
  }

  async function fetchData(url) {
    const response = await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
    const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || payload.ok === false) throw new Error(payload.error || ('HTTP_' + response.status));
    return payload.data ?? payload;
  }

  function resultValue(result) {
    return result?.status === 'fulfilled' ? result.value : null;
  }

  function resultError(result) {
    return result?.status === 'rejected' ? String(result.reason?.message || result.reason || 'UNAVAILABLE') : '';
  }

  function setShellStatus(kind, label) {
    const badge = document.querySelector('.top .status');
    if (!badge) return;
    badge.classList.remove('dashboard-degraded','dashboard-offline');
    if (kind === 'degraded') badge.classList.add('dashboard-degraded');
    if (kind === 'offline') badge.classList.add('dashboard-offline');
    badge.innerHTML = `<i aria-hidden="true"></i>${esc(label)}`;
  }

  function clearLegacyDashboard(main) {
    main.querySelector('#brvtal-content-health')?.remove();
    main.querySelector('#brvtal-admin-activity')?.remove();
    [...main.children].forEach(child => {
      if (child.classList.contains('top')) return;
      if (child.id === 'brvtal-dashboard-v2') return;
      if (child.matches('.stats,.system-pulse,.dashsection,.dashgrid')) child.remove();
    });
  }

  function ensureDashboardRoot(main) {
    let root = document.getElementById('brvtal-dashboard-v2');
    if (root) return root;
    root = document.createElement('div');
    root.id = 'brvtal-dashboard-v2';
    root.className = 'dashboard-v2';
    const top = main.querySelector('.top');
    top?.insertAdjacentElement('afterend', root);
    if (!top) main.prepend(root);
    return root;
  }

  function summaryCard(label, value, destination = '') {
    if (!destination) return `<div class="dashboard-v2-summary-card"><span>${esc(label)}</span><b>${esc(value)}</b></div>`;
    return `<button type="button" class="dashboard-v2-summary-card dashboard-v2-summary-action" data-dashboard-go="${esc(destination)}"><span>${esc(label)}</span><b>${esc(value)}</b><small>OPEN</small></button>`;
  }

  function sourceError(message) {
    return `<div class="dashboard-v2-source-error">SOURCE UNAVAILABLE · ${esc(message || 'UNKNOWN ERROR')}</div>`;
  }

  function daysUntil(value) {
    if (!value) return null;
    const target = new Date(String(value).replace(' ','T'));
    if (Number.isNaN(target.getTime())) return null;
    const now = new Date();
    const diff = Math.ceil((target.getTime() - now.getTime()) / 86400000);
    return diff;
  }

  function nextEventUnavailablePanel(overviewError) {
    return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">LIVE LIFECYCLE</div><h2>NEXT EVENT</h2></div><span class="dashboard-v2-state bad">UNAVAILABLE</span></div>${sourceError(overviewError)}</section>`;
  }

  function nextEventEmptyPanel() {
    return '<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">LIVE LIFECYCLE</div><h2>NEXT EVENT</h2><p>No active future event is currently scheduled.</p></div><span class="dashboard-v2-state muted">NONE</span></div><div class="dashboard-v2-empty">Create or schedule an Event when the next date is confirmed.</div></section>';
  }

  function nextEventWarnings(event) {
    const warnings = [];
    if (!event.cover_image) warnings.push('Missing cover');
    if (!event.city && !event.venue) warnings.push('Missing location');
    if (!event.ticket_url && String(event.status || '') !== 'sold_out') warnings.push('Check ticket destination');
    return warnings;
  }

  function nextEventTicketState(event) {
    if (event.status === 'sold_out') return {label:'SOLD OUT', className:'good'};
    if (event.ticket_url) return {label:'TICKETS LINKED', className:'good'};
    return {label:'NO DIRECT TICKET URL', className:''};
  }

  function nextEventTiming(days) {
    if (days === null) return '';
    if (days <= 0) return ' · TODAY';
    const suffix = days === 1 ? '' : 's';
    return ` · ${days} day${suffix}`;
  }

  function nextEventMeta(event) {
    const date = String(event.event_date || '');
    const days = daysUntil(date);
    const location = [event.venue,event.city].filter(Boolean).join(' · ') || 'Location pending';
    return {date, location, timing:nextEventTiming(days)};
  }

  function nextEventPanel(overview, overviewError) {
    if (!overview) return nextEventUnavailablePanel(overviewError);
    const event = overview.next_event;
    if (!event) return nextEventEmptyPanel();

    const image = normalizePath(event.cover_image);
    const warnings = nextEventWarnings(event);
    const ticket = nextEventTicketState(event);
    const meta = nextEventMeta(event);
    const readinessClass = warnings.length ? 'warn' : 'ok';
    const readinessLabel = warnings.length ? 'CHECK' : 'READY';

    return `<section class="dashboard-v2-panel">
      <div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">LIVE LIFECYCLE</div><h2>NEXT EVENT</h2><p>The next active public Event, using the canonical lifecycle policy.</p></div><span class="dashboard-v2-state ${readinessClass}">${readinessLabel}</span></div>
      <div class="dashboard-next-event">
        ${image ? `<img class="dashboard-next-event-media" src="${esc(image)}" alt="" loading="lazy">` : '<div class="dashboard-next-event-placeholder">NO COVER</div>'}
        <div><h3>${esc(event.title || 'Untitled event')}</h3><div class="dashboard-next-event-meta">${esc(meta.date || 'Date pending')} · ${esc(meta.location)}${meta.timing}</div><div class="dashboard-next-event-status"><span class="dashboard-v2-chip accent">${esc(String(event.status || '').replaceAll('_',' '))}</span><span class="dashboard-v2-chip ${ticket.className}">${esc(ticket.label)}</span>${warnings.map(item=>`<span class="dashboard-v2-chip">${esc(item)}</span>`).join('')}</div></div>
      </div>
      <div class="dashboard-v2-actions" style="margin-top:14px"><button class="dashboard-v2-button" type="button" data-dashboard-go="events">OPEN EVENTS</button></div>
    </section>`;
  }

  function attentionPanel(content, contentError) {
    if (!content) return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">PUBLIC READINESS</div><h2>NEEDS ATTENTION</h2></div><span class="dashboard-v2-state bad">UNAVAILABLE</span></div>${sourceError(contentError)}</section>`;
    const publicHealth = content.public || content;
    const items = (Array.isArray(publicHealth.items) ? publicHealth.items : []).filter(item => Number(item.score || 0) < 80).slice(0,4);
    return `<section class="dashboard-v2-panel">
      <div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">PUBLIC READINESS</div><h2>NEEDS ATTENTION</h2><p>Only public/publishable records affect this signal. Draft completeness is tracked separately.</p></div><span class="dashboard-v2-state ${stateClass(publicHealth.score)}">${Number(publicHealth.score ?? 100)}%</span></div>
      <div class="dashboard-v2-list">${items.length ? items.map(item => `<div class="dashboard-v2-row"><div><div class="dashboard-v2-row-title">${esc(item.title || 'Untitled')}</div><div class="dashboard-v2-row-meta">${esc(String(item.type || '').toUpperCase())} · ${esc((item.issues || []).slice(0,3).join(' · ') || 'Review required')}</div></div><div class="dashboard-v2-row-actions"><span class="dashboard-v2-score ${stateClass(item.score)}">${Number(item.score || 0)}%</span><button class="dashboard-v2-button" type="button" data-dashboard-go="${esc(sectionFor(item.type))}" data-dashboard-resource="${esc(item.type || '')}" data-dashboard-id="${Number(item.id ?? 0)}">OPEN</button></div></div>`).join('') : '<div class="dashboard-v2-empty">No public records currently require attention.</div>'}</div>
    </section>`;
  }

  function draftsPanel(content, contentError) {
    if (!content) return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">EDITORIAL BACKLOG</div><h2>DRAFTS IN PROGRESS</h2></div><span class="dashboard-v2-state bad">UNAVAILABLE</span></div>${sourceError(contentError)}</section>`;
    const drafts = content.drafts || {total:0,ready:0,needs_attention:0,items:[]};
    const average = drafts.total ? Math.round((drafts.items || []).reduce((sum,item)=>sum+Number(item.score || 0),0) / drafts.total) : 100;
    return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">EDITORIAL BACKLOG</div><h2>DRAFTS IN PROGRESS</h2><p>Incomplete drafts are normal and do not reduce public Content Health.</p></div><span class="dashboard-v2-state muted">BACKLOG</span></div><div class="dashboard-v2-drafts"><div class="dashboard-v2-draft"><span>DRAFTS</span><b>${Number(drafts.total || 0)}</b></div><div class="dashboard-v2-draft"><span>AVG COMPLETENESS</span><b>${Number(average)}%</b></div><div class="dashboard-v2-draft"><span>≥80 COMPLETE</span><b>${Number(drafts.ready || 0)}</b></div></div></section>`;
  }

  function systemPanel(health, healthError, storage, storageError) {
    const apiOk = !!health?.ok;
    const dbOk = health?.database === 'connected';
    const php = health?.php || '—';
    const storageValue = storage ? `${storage.used || '0 B'} / ${storage.quota || '—'}` : 'UNAVAILABLE';
    const storageMeta = storage ? `${Number(storage.used_percent || 0)}% · ${Number(storage.managed_files || 0)} files · managed data` : storageError;
    const release = document.querySelector('.brvtal-admin-release');
    const productVersion = release?.querySelector('[data-testid="admin-product-version"]')?.textContent?.trim()
      || 'BRVTAL version unavailable';
    const environment = release?.querySelector('[data-testid="admin-product-environment"]')?.textContent?.trim() || '';
    const deploySource = release?.querySelector('[data-testid="admin-deploy-source"]')?.textContent?.trim() || 'SOURCE UNAVAILABLE';
    return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">SITE HEALTH</div><h2>OPERATIONS</h2><p>Compact runtime signals. Detailed diagnostics remain in System Status.</p></div><button class="dashboard-v2-button" type="button" data-dashboard-system>SYSTEM STATUS</button></div>
      ${!health ? sourceError(healthError) : ''}
      <div class="dashboard-v2-health">
        <div class="dashboard-v2-health-card"><span>API</span><b>${apiOk?'ONLINE':'CHECK'}</b><small>${health?.latency_ms != null ? esc(health.latency_ms + ' ms') : esc(healthError || 'Connectivity')}</small></div>
        <div class="dashboard-v2-health-card"><span>DATABASE</span><b>${dbOk?'CONNECTED':'CHECK'}</b><small>${esc(health?.driver || 'Runtime source')}</small></div>
        <div class="dashboard-v2-health-card"><span>PHP</span><b>${esc(php)}</b><small>Runtime-reported version</small></div>
        <div class="dashboard-v2-health-card"><span>STORAGE</span><b>${esc(storageValue)}</b><small>${esc(storageMeta || 'Managed quota')}</small></div>
      </div><div class="dashboard-v2-row" style="margin-top:12px" data-testid="dashboard-product-version"><div><div class="dashboard-v2-row-title">${esc(productVersion)}</div><div class="dashboard-v2-row-meta">${esc([environment,deploySource].filter(Boolean).join(' · '))}</div></div><span class="dashboard-v2-state ${apiOk&&dbOk?'ok':'warn'}">${apiOk&&dbOk?'HEALTHY':'CHECK'}</span></div>
    </section>`;
  }

  function activityRow(item) {
    const resource = item.resource || 'content';
    const resourceLabel = item.resource_label || (resource + ' #' + (item.resource_id || ''));
    return `<div class="dashboard-v2-row"><div><div class="dashboard-v2-row-title">${esc(resourceLabel)}</div><div class="dashboard-v2-row-meta">${esc(String(item.action || 'update').replaceAll('_',' ').toUpperCase())} · ${esc(item.admin_name || item.admin_email || 'Unknown admin')} · ${esc(item.created_at || '')}</div></div><button class="dashboard-v2-button" type="button" data-dashboard-go="${esc(sectionFor(resource))}" data-dashboard-resource="${esc(resource)}" data-dashboard-id="${Number(item.resource_id ?? 0)}">OPEN</button></div>`;
  }

  function activityPanel(activity, activityError) {
    if (!activity) return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">EDITORIAL ACTIVITY</div><h2>RECENT CHANGES</h2></div><span class="dashboard-v2-state bad">UNAVAILABLE</span></div>${sourceError(activityError)}</section>`;
    const items = Array.isArray(activity.items) ? activity.items : [];
    const rows = items.length ? items.map(activityRow).join('') : '<div class="dashboard-v2-empty">No recorded activity yet.</div>';
    const more = activity.has_more
      ? `<button class="dashboard-v2-button dashboard-v2-more" type="button" data-dashboard-activity-more data-dashboard-cursor="${esc(activity.next_cursor || '')}">VIEW MORE</button>`
      : '';
    return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">EDITORIAL ACTIVITY</div><h2>RECENT CHANGES</h2><p>Five records at a time from the append-only Admin Activity log.</p></div><span class="dashboard-v2-state muted">${Number(activity.total || items.length)} TOTAL</span></div><div class="dashboard-v2-list" data-dashboard-activity-list>${rows}</div>${more}</section>`;
  }

  function analyticsPanel() {
    return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">ANALYTICS</div><h2>PERFORMANCE</h2><p>Admin-safe analytics source required before metrics or visualizations can be configured.</p></div><span class="dashboard-v2-state muted">NOT CONFIGURED</span></div><div class="dashboard-v2-empty" data-dashboard-analytics-unavailable>DATA UNAVAILABLE · BRVTAL does not invent GA4 or client-side metrics.</div></section>`;
  }

  function actionsPanel() {
    return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">SECONDARY</div><h2>QUICK CREATE</h2><p>Shortcuts stay available without dominating the Dashboard.</p></div></div><div class="dashboard-v2-actions"><button class="dashboard-v2-button accent" type="button" data-dashboard-create="events">+ EVENT</button><button class="dashboard-v2-button" type="button" data-dashboard-create="artists">+ ARTIST</button><button class="dashboard-v2-button" type="button" data-dashboard-create="sets">+ SET</button><button class="dashboard-v2-button" type="button" data-dashboard-go="media">MEDIA LIBRARY</button></div></section>`;
  }

  function defaultLayout() {
    return {
      modules:[
        {id:'next_event',width:2,height:1,visible:true},
        {id:'attention',width:2,height:1,visible:true},
        {id:'drafts',width:2,height:1,visible:true},
        {id:'operations',width:2,height:1,visible:true},
        {id:'activity',width:2,height:1,visible:true},
        {id:'quick_create',width:2,height:1,visible:true},
        {id:'analytics',width:2,height:1,visible:false}
      ]
    };
  }

  function normalizeLayout(value) {
    const defaults = defaultLayout();
    const source = Array.isArray(value?.modules) ? value.modules : defaults.modules;
    const allowed = new Map(defaults.modules.map(item => [item.id,item]));
    const seen = new Set();
    const modules = [];
    source.forEach(item => {
      const id = String(item?.id || '');
      if (!allowed.has(id) || seen.has(id)) return;
      seen.add(id);
      modules.push({
        id,
        width:Math.max(1,Math.min(4,Number(item.width || allowed.get(id).width))),
        height:Math.max(1,Math.min(2,Number(item.height || allowed.get(id).height))),
        visible:item.visible !== false
      });
    });
    defaults.modules.forEach(item => { if (!seen.has(item.id)) modules.push({...item}); });
    if (!modules.some(item => item.visible)) modules[0].visible = true;
    return {modules};
  }

  async function dashboardCsrfToken() {
    if (globalThis.BRVTALAdminAuthBoundary?.csrfToken) {
      return globalThis.BRVTALAdminAuthBoundary.csrfToken();
    }
    try { if (globalThis.csrf) return globalThis.csrf; } catch (_) {}
    throw new Error('AUTH_REQUIRED');
  }

  async function saveLayout(layout) {
    const token = await dashboardCsrfToken();
    const response = await fetch(ENDPOINTS.preferences,{
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':String(token)},
      body:JSON.stringify(layout)
    });
    const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || payload.ok === false) throw new Error(payload.error || 'DASHBOARD_PREFERENCES_SAVE_FAILED');
    return normalizeLayout(payload.data);
  }

  function moduleControls(item) {
    return `<div class="dashboard-v2-module-controls" aria-label="Dashboard module controls">
      <button type="button" data-dashboard-move="up" aria-label="Move module earlier">↑</button>
      <button type="button" data-dashboard-move="down" aria-label="Move module later">↓</button>
      <button type="button" data-dashboard-resize="narrower" aria-label="Make module narrower">−W</button>
      <button type="button" data-dashboard-resize="wider" aria-label="Make module wider">+W</button>
      <button type="button" data-dashboard-resize="shorter" aria-label="Make module shorter">−H</button>
      <button type="button" data-dashboard-resize="taller" aria-label="Make module taller">+H</button>
      <button type="button" data-dashboard-hide aria-label="Hide module">HIDE</button>
    </div>`;
  }

  function moduleShell(item, markup) {
    return `<div class="dashboard-v2-module" draggable="true" data-dashboard-module="${esc(item.id)}" data-dashboard-width="${item.width}" data-dashboard-height="${item.height}" style="--dashboard-col-span:${item.width};--dashboard-row-span:${item.height}">${moduleControls(item)}${markup}</div>`;
  }

  function customizationPanel(layout) {
    const hidden = layout.modules.filter(item => !item.visible);
    return `<section class="dashboard-v2-customize" aria-label="Dashboard customization">
      <div><strong>LAYOUT</strong><span>Drag modules or use keyboard/touch controls. Sizes snap to grid cells.</span></div>
      <div class="dashboard-v2-customize-actions">
        ${hidden.map(item => `<button type="button" class="dashboard-v2-button" data-dashboard-show="${esc(item.id)}">+ ${esc(item.id.replaceAll('_',' ').toUpperCase())}</button>`).join('')}
        <button type="button" class="dashboard-v2-button" data-dashboard-reset>RESET TO DEFAULT</button>
      </div>
    </section>`;
  }

  function reorder(layout, id, delta) {
    const index = layout.modules.findIndex(item => item.id === id);
    const target = index + delta;
    if (index < 0 || target < 0 || target >= layout.modules.length) return false;
    const [item] = layout.modules.splice(index,1);
    layout.modules.splice(target,0,item);
    return true;
  }

  function bindNavigation(root) {
    root.querySelectorAll('[data-dashboard-go]:not([data-dashboard-bound])').forEach(button => {
      button.dataset.dashboardBound = '1';
      button.addEventListener('click', () => {
      const id = Number(button.dataset.dashboardId ?? 0);
      const resource = button.dataset.dashboardResource ?? '';
      if (id > 0 && resource && typeof globalThis.BRVTALAdminRecordNavigation?.open === 'function') {
        globalThis.BRVTALAdminRecordNavigation.open(resource,id,{section:button.dataset.dashboardGo})
          .catch(error => globalThis.BRVTALFeedback?.error?.('Unable to open record: ' + (error?.message || error),'dashboard-record-navigation'));
        return;
      }
      globalThis.go?.(button.dataset.dashboardGo);
      });
    });
    root.querySelectorAll('[data-dashboard-create]').forEach(button => button.addEventListener('click', () => window.openModal?.(button.dataset.dashboardCreate)));
    root.querySelector('[data-dashboard-system]')?.addEventListener('click', () => window.tech?.('system'));
  }

  function bindActivityMore(root) {
    root.querySelector('[data-dashboard-activity-more]')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      const cursor = String(button.dataset.dashboardCursor || '');
      if (!cursor) return;
      button.disabled = true;
      try {
        const next = await fetchData(ENDPOINTS.activity + '&cursor=' + encodeURIComponent(cursor));
        const list = root.querySelector('[data-dashboard-activity-list]');
        (next.items || []).forEach(item => list?.insertAdjacentHTML('beforeend',activityRow(item)));
        if (next.has_more && next.next_cursor) {
          button.dataset.dashboardCursor = String(next.next_cursor);
          button.disabled = false;
        } else {
          button.remove();
        }
        list?.querySelectorAll('[data-dashboard-go]:not([data-dashboard-bound])').forEach(button => {
          button.dataset.dashboardBound = '1';
          button.addEventListener('click', () => {
            const id = Number(button.dataset.dashboardId ?? 0);
            const resource = button.dataset.dashboardResource ?? '';
            if (id > 0 && resource && typeof globalThis.BRVTALAdminRecordNavigation?.open === 'function') {
              globalThis.BRVTALAdminRecordNavigation.open(resource,id,{section:button.dataset.dashboardGo});
            } else globalThis.go?.(button.dataset.dashboardGo);
          });
        });
      } catch (error) {
        button.disabled = false;
        globalThis.BRVTALFeedback?.error?.('Recent Changes could not load more records.','dashboard-activity');
      }
    });
  }

  function persistDashboardLayout(layout, results, serial) {
    const snapshot = normalizeLayout({
      modules:layout.modules.map(item => ({...item}))
    });
    const saveSerial = ++layoutSaveSerial;
    layoutSaveChain = layoutSaveChain
      .catch(() => undefined)
      .then(() => saveLayout(snapshot))
      .then(saved => {
        if (saveSerial !== layoutSaveSerial) return saved;
        results[5] = {status:'fulfilled',value:saved};
        render(results,serial);
        return saved;
      })
      .catch(() => {
        if (saveSerial === layoutSaveSerial) {
          globalThis.BRVTALFeedback?.error?.('Dashboard layout could not be saved.','dashboard-layout');
        }
        return null;
      });
    return layoutSaveChain;
  }

  function bindDashboardModule(module, layout, persist, dragState) {
    module.addEventListener('dragstart',() => {
      dragState.current = module.dataset.dashboardModule || '';
    });
    module.addEventListener('dragover',event => event.preventDefault());
    module.addEventListener('drop',event => {
      event.preventDefault();
      const target = module.dataset.dashboardModule || '';
      if (!dragState.current || dragState.current === target) return;
      const from = layout.modules.findIndex(item => item.id === dragState.current);
      const to = layout.modules.findIndex(item => item.id === target);
      if (from < 0 || to < 0) return;
      const [item] = layout.modules.splice(from,1);
      layout.modules.splice(to,0,item);
      persist();
    });

    module.querySelectorAll('[data-dashboard-move]').forEach(button => {
      button.addEventListener('click',() => {
        if (reorder(layout,module.dataset.dashboardModule,button.dataset.dashboardMove === 'up' ? -1 : 1)) persist();
      });
    });

    module.querySelectorAll('[data-dashboard-resize]').forEach(button => {
      button.addEventListener('click',() => {
        const item = layout.modules.find(entry => entry.id === module.dataset.dashboardModule);
        if (!item) return;
        const action = button.dataset.dashboardResize;
        if (action === 'narrower') item.width = Math.max(1,item.width - 1);
        if (action === 'wider') item.width = Math.min(4,item.width + 1);
        if (action === 'shorter') item.height = Math.max(1,item.height - 1);
        if (action === 'taller') item.height = Math.min(2,item.height + 1);
        persist();
      });
    });

    module.querySelector('[data-dashboard-hide]')?.addEventListener('click',() => {
      const item = layout.modules.find(entry => entry.id === module.dataset.dashboardModule);
      if (!item || layout.modules.filter(entry => entry.visible).length <= 1) return;
      item.visible = false;
      persist();
    });
  }

  function bindCustomization(root, layout, results, serial) {
    const dragState = {current:''};
    const persist = () => persistDashboardLayout(layout,results,serial);

    root.querySelectorAll('[data-dashboard-module]').forEach(module => {
      bindDashboardModule(module,layout,persist,dragState);
    });

    root.querySelectorAll('[data-dashboard-show]').forEach(button => {
      button.addEventListener('click',() => {
        const item = layout.modules.find(entry => entry.id === button.dataset.dashboardShow);
        if (!item) return;
        item.visible = true;
        persist();
      });
    });

    root.querySelector('[data-dashboard-reset]')?.addEventListener('click',() => {
      layout.modules = defaultLayout().modules.map(item => ({...item}));
      persist();
    });
  }

  function bind(root, layout, results, serial) {
    bindNavigation(root);
    bindActivityMore(root);
    bindCustomization(root,layout,results,serial);
  }

  function render(results, serial) {
    if (serial !== mountSerial || typeof state === 'undefined' || !state.authed || state.section !== 'dashboard') return false;
    const main = document.querySelector('.main');
    if (!main) return false;

    const root = ensureDashboardRoot(main);
    clearLegacyDashboard(main);

    const overviewResult = results[0], contentResult = results[1], healthResult = results[2], storageResult = results[3], activityResult = results[4];
    const preferenceResult = results[5];
    const overview = resultValue(overviewResult), content = resultValue(contentResult), health = resultValue(healthResult), storage = resultValue(storageResult), activity = resultValue(activityResult);
    const layout = normalizeLayout(resultValue(preferenceResult));
    const summary = overview?.summary || {};

    const modules = {
      next_event:nextEventPanel(overview,resultError(overviewResult)),
      attention:attentionPanel(content,resultError(contentResult)),
      drafts:draftsPanel(content,resultError(contentResult)),
      operations:systemPanel(resultValue(results[2]),resultError(results[2]),storage,resultError(storageResult)),
      activity:activityPanel(activity,resultError(activityResult)),
      quick_create:actionsPanel(),
      analytics:analyticsPanel()
    };
    const visibleModules = layout.modules
      .filter(item => item.visible && modules[item.id])
      .map(item => moduleShell(item,modules[item.id]))
      .join('');

    root.innerHTML = `
      <section class="dashboard-v2-hero"><div><div class="dashboard-v2-kicker">BRVTAL / COMMAND OVERVIEW</div><h2 class="dashboard-v2-title">WHAT NEEDS<br>ATTENTION NOW</h2><div class="dashboard-v2-sub">Operational and editorial signals first. Counts are derived from active data sources; unavailable sources stay explicit instead of becoming misleading zeroes.</div></div><div class="dashboard-v2-summary">${summaryCard('Public records', overview ? Number(summary.public_records || 0) : '—')}${summaryCard('Draft backlog', overview ? Number(summary.draft_records || 0) : '—')}${summaryCard('Active events', overview ? Number(summary.active_events || 0) : '—','events')}${summaryCard('Media assets', overview ? Number(summary.media_assets || 0) : '—','media')}</div></section>
      ${customizationPanel(layout)}
      <div class="dashboard-v2-grid dashboard-v2-grid-configurable" data-dashboard-grid>${visibleModules}</div>`;
    bind(root,layout,results,serial);

    if (!overview || !health || health.database !== 'connected') setShellStatus(!health ? 'offline' : 'degraded', !health ? 'OFFLINE / CHECK' : 'DEGRADED');
    else setShellStatus('ok','ONLINE');
    return true;
  }

  async function mount(force = false) {
    if (mounting || typeof state === 'undefined' || !state.authed || state.section !== 'dashboard') return;
    const existingRoot = document.getElementById('brvtal-dashboard-v2');
    if (!force && existingRoot) return;
    const main = document.querySelector('.main');
    if (!main) return;

    const reservedRoot = existingRoot || ensureDashboardRoot(main);
    mounting = true;
    const serial = ++mountSerial;
    let rendered = false;
    try {
      const results = await Promise.allSettled([
        fetchData(ENDPOINTS.overview),
        fetchData(ENDPOINTS.content),
        fetchData(ENDPOINTS.health),
        fetchData(ENDPOINTS.storage),
        fetchData(ENDPOINTS.activity),
        fetchData(ENDPOINTS.preferences)
      ]);
      rendered = render(results,serial);
    } finally {
      if (!rendered && !existingRoot && reservedRoot.isConnected) reservedRoot.remove();
      if (serial === mountSerial) mounting = false;
    }
  }

  const observer = new MutationObserver(() => {
    if (typeof state === 'undefined' || !state.authed || state.section !== 'dashboard' || document.getElementById('brvtal-dashboard-v2')) return;
    clearTimeout(observer._timer);
    observer._timer = setTimeout(() => mount(),25);
  });
  observer.observe(document.documentElement,{childList:true,subtree:true});

  setTimeout(() => mount(),0);
  window.BRVTALDashboardV2 = {mount:() => mount(true)};
})();
