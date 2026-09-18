(() => {
  'use strict';

  const ENDPOINTS = {
    overview:'/api/dashboard-overview.php',
    content:'/api/content-health.php',
    health:'/api/index.php/health',
    storage:'/discadmin/storage-metrics.php',
    activity:'/api/admin-activity.php?limit=4'
  };
  const sectionFor = type => ({events:'events',artists:'artists',sets:'sets',releases:'releases',pages:'pages',blog:'blog',ticket_types:'events',event_lineup:'events'})[type] || 'dashboard';
  let mounting = false;
  let mountSerial = 0;

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

  function summaryCard(label, value) {
    return `<div class="dashboard-v2-summary-card"><span>${esc(label)}</span><b>${esc(value)}</b></div>`;
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

  function nextEventMeta(event) {
    const date = String(event.event_date || '');
    const days = daysUntil(date);
    const location = [event.venue,event.city].filter(Boolean).join(' · ') || 'Location pending';
    const timing = days === null ? '' : ` · ${days <= 0 ? 'TODAY' : days + ' day' + (days===1?'':'s')}`;
    return {date, location, timing};
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
    const build = document.querySelector('.brvtal-admin-release .build')?.textContent?.trim() || 'DEPLOY —';
    return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">SITE HEALTH</div><h2>OPERATIONS</h2><p>Compact runtime signals. Detailed diagnostics remain in System Status.</p></div><button class="dashboard-v2-button" type="button" data-dashboard-system>SYSTEM STATUS</button></div>
      ${!health ? sourceError(healthError) : ''}
      <div class="dashboard-v2-health">
        <div class="dashboard-v2-health-card"><span>API</span><b>${apiOk?'ONLINE':'CHECK'}</b><small>${health?.latency_ms != null ? esc(health.latency_ms + ' ms') : esc(healthError || 'Connectivity')}</small></div>
        <div class="dashboard-v2-health-card"><span>DATABASE</span><b>${dbOk?'CONNECTED':'CHECK'}</b><small>${esc(health?.driver || 'Runtime source')}</small></div>
        <div class="dashboard-v2-health-card"><span>PHP</span><b>${esc(php)}</b><small>Runtime-reported version</small></div>
        <div class="dashboard-v2-health-card"><span>STORAGE</span><b>${esc(storageValue)}</b><small>${esc(storageMeta || 'Managed quota')}</small></div>
      </div><div class="dashboard-v2-row" style="margin-top:12px"><div><div class="dashboard-v2-row-title">CURRENT BUILD</div><div class="dashboard-v2-row-meta">${esc(build)}</div></div><span class="dashboard-v2-state ${apiOk&&dbOk?'ok':'warn'}">${apiOk&&dbOk?'HEALTHY':'CHECK'}</span></div>
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
    return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">EDITORIAL ACTIVITY</div><h2>RECENT CHANGES</h2><p>Latest entries from the append-only Admin Activity log.</p></div><span class="dashboard-v2-state muted">${Number(activity.total || items.length)} TOTAL</span></div><div class="dashboard-v2-list">${rows}</div></section>`;
  }

  function actionsPanel() {
    return `<section class="dashboard-v2-panel"><div class="dashboard-v2-panel-head"><div><div class="dashboard-v2-kicker">SECONDARY</div><h2>QUICK CREATE</h2><p>Shortcuts stay available without dominating the Dashboard.</p></div></div><div class="dashboard-v2-actions"><button class="dashboard-v2-button accent" type="button" data-dashboard-create="events">+ EVENT</button><button class="dashboard-v2-button" type="button" data-dashboard-create="artists">+ ARTIST</button><button class="dashboard-v2-button" type="button" data-dashboard-create="sets">+ SET</button><button class="dashboard-v2-button" type="button" data-dashboard-go="media">MEDIA LIBRARY</button></div></section>`;
  }

  function bind(root) {
    root.querySelectorAll('[data-dashboard-go]').forEach(button => button.addEventListener('click', () => {
      const id = Number(button.dataset.dashboardId ?? 0);
      const resource = button.dataset.dashboardResource ?? '';
      if (id > 0 && resource && typeof globalThis.BRVTALAdminRecordNavigation?.open === 'function') {
        globalThis.BRVTALAdminRecordNavigation.open(resource, id, {section:button.dataset.dashboardGo})
          .catch(error => globalThis.BRVTALFeedback?.error?.('Unable to open record: ' + (error?.message || error),'dashboard-record-navigation'));
        return;
      }
      globalThis.go?.(button.dataset.dashboardGo);
    }));
    root.querySelectorAll('[data-dashboard-create]').forEach(button => button.addEventListener('click', () => window.openModal?.(button.dataset.dashboardCreate)));
    root.querySelector('[data-dashboard-system]')?.addEventListener('click', () => window.tech?.('system'));
  }

  function render(results, serial) {
    if (serial !== mountSerial || typeof state === 'undefined' || !state.authed || state.section !== 'dashboard') return false;
    const main = document.querySelector('.main');
    if (!main) return false;

    const root = ensureDashboardRoot(main);
    clearLegacyDashboard(main);

    const overviewResult = results[0], contentResult = results[1], healthResult = results[2], storageResult = results[3], activityResult = results[4];
    const overview = resultValue(overviewResult), content = resultValue(contentResult), health = resultValue(healthResult), storage = resultValue(storageResult), activity = resultValue(activityResult);
    const summary = overview?.summary || {};

    root.innerHTML = `
      <section class="dashboard-v2-hero"><div><div class="dashboard-v2-kicker">BRVTAL / COMMAND OVERVIEW</div><h2 class="dashboard-v2-title">WHAT NEEDS<br>ATTENTION NOW</h2><div class="dashboard-v2-sub">Operational and editorial signals first. Counts are derived from active data sources; unavailable sources stay explicit instead of becoming misleading zeroes.</div></div><div class="dashboard-v2-summary">${summaryCard('Public records', overview ? Number(summary.public_records || 0) : '—')}${summaryCard('Draft backlog', overview ? Number(summary.draft_records || 0) : '—')}${summaryCard('Active events', overview ? Number(summary.active_events || 0) : '—')}${summaryCard('Media assets', overview ? Number(summary.media_assets || 0) : '—')}</div></section>
      <div class="dashboard-v2-grid"><div class="dashboard-v2-stack">${nextEventPanel(overview,resultError(overviewResult))}${attentionPanel(content,resultError(contentResult))}${draftsPanel(content,resultError(contentResult))}</div><div class="dashboard-v2-stack">${systemPanel(health,resultError(healthResult),storage,resultError(storageResult))}${activityPanel(activity,resultError(activityResult))}${actionsPanel()}</div></div>`;
    bind(root);

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
        fetchData(ENDPOINTS.activity)
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
