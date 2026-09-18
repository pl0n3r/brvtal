(() => {
  'use strict';

  const ENDPOINT = '/api/admin-activity.php';
  const sectionFor = resource => ({events:'events',artists:'artists',sets:'sets',pages:'pages',releases:'releases',blog:'blog',ticket_types:'events',event_lineup:'events'})[resource] || 'dashboard';
  const resources = ['','events','artists','sets','releases','blog','pages','ticket_types','event_lineup'];
  let loading = false;
  let detailLoadId = 0;
  let detailController = null;

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  }

  function ensureStyle() {
    if (document.getElementById('brvtal-admin-activity-style')) return;
    const style = document.createElement('style');
    style.id = 'brvtal-admin-activity-style';
    style.textContent = `
      .activity-panel{margin-top:18px;border:1px solid #292d31;background:#080909;padding:18px}
      .activity-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:14px}
      .activity-head h2{margin:2px 0 4px;font-size:clamp(24px,3vw,42px);letter-spacing:-.04em}
      .activity-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
      .activity-filter{background:#050606;border:1px solid #353a3f;color:#fff;padding:9px 11px;font:800 9px/1 monospace;letter-spacing:1px}
      .activity-list{display:grid;gap:0;border-top:1px solid #202428}
      .activity-row{display:grid;grid-template-columns:88px minmax(0,1fr) 170px 115px auto;gap:10px;align-items:center;padding:11px 0;border-bottom:1px solid #1d2023}
      .activity-action{font:900 8px/1 monospace;letter-spacing:1.1px;color:#fff}.activity-action.update,.activity-action.seo_update{color:#ffd166}.activity-action.create{color:#49d98a}.activity-action.delete{color:#ff6677}.activity-action.bulk_status{color:#b79cff}.activity-action.lineup_update{color:#66c7ff}
      .activity-title{font-size:11px;font-weight:850;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.activity-meta{font-size:9px;color:#737b81;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .activity-actor{font-size:9px;color:#9aa1a7;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.activity-time{font:700 8px/1.35 monospace;color:#737b81}
      .activity-actions{display:flex;gap:6px;justify-content:flex-end}.activity-btn{border:1px solid #34393e;background:#101214;color:#fff;padding:7px 9px;font:800 8px/1 monospace;letter-spacing:.8px}.activity-btn:hover{border-color:#fff}
      .activity-note{font-size:9px;color:#747c82;line-height:1.5;margin-top:12px}
      .activity-modal{position:fixed;inset:0;z-index:90;background:rgba(0,0,0,.86);backdrop-filter:blur(8px);display:grid;place-items:center;padding:18px}.activity-modal[hidden]{display:none}.activity-modal-card{width:min(1100px,96vw);max-height:92vh;overflow:auto;border:1px solid #34393e;background:#080909}.activity-modal-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:18px;border-bottom:1px solid #24282c}.activity-modal-head h3{margin:3px 0 0;font-size:22px}.activity-modal-body{padding:18px}.activity-diff{display:grid;grid-template-columns:1fr 1fr;gap:10px}.activity-diff section{min-width:0}.activity-diff h4{font:900 9px/1 monospace;letter-spacing:1.5px;color:#8a9298}.activity-diff pre{margin:8px 0 0;max-height:48vh;overflow:auto;white-space:pre-wrap;word-break:break-word;background:#030404;border:1px solid #22272b;padding:13px;font:10px/1.5 monospace;color:#b7bdc2}.activity-fields{font-size:10px;color:#939ba1;margin-bottom:12px}
      .history-layout{display:grid;grid-template-columns:minmax(230px,32%) 1fr;gap:14px}.history-timeline{border:1px solid #22272b;max-height:62vh;overflow:auto}.history-version{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #22272b;background:#070808;color:#fff;padding:13px;cursor:pointer}.history-version:hover,.history-version.is-active{background:#131516}.history-version b{display:block;font:900 9px/1.3 monospace;letter-spacing:1px}.history-version span{display:block;margin-top:6px;font-size:9px;color:#858d93}.history-summary{margin-bottom:12px;font-size:10px;color:#a4abb0}.history-field{border:1px solid #24282c;margin-bottom:8px}.history-field h4{margin:0;padding:9px 11px;background:#111315;font:900 9px/1 monospace;letter-spacing:1px}.history-values{display:grid;grid-template-columns:1fr 1fr}.history-value{min-width:0;padding:10px 11px;white-space:pre-wrap;word-break:break-word;font:10px/1.45 monospace;color:#c0c5c9}.history-value+ .history-value{border-left:1px solid #24282c}.history-value small{display:block;color:#686f75;margin-bottom:6px}
      @media(max-width:850px){.activity-row{grid-template-columns:78px minmax(0,1fr) auto}.activity-actor,.activity-time{grid-column:2/3}.activity-actions{grid-column:3;grid-row:1/4;flex-direction:column}.activity-diff,.history-layout,.history-values{grid-template-columns:1fr}.history-value+ .history-value{border-left:0;border-top:1px solid #24282c}.activity-head{flex-direction:column}}
    `;
    document.head.appendChild(style);
  }

  function formatTime(value) {
    if (!value) return '';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat(undefined,{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'}).format(date);
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url,{credentials:'same-origin',cache:'no-store',...options,headers:{Accept:'application/json',...(options.headers||{})}});
    const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || payload.ok === false) throw new Error(payload.error || ('HTTP_' + response.status));
    return payload.data;
  }

  async function fetchList(resource = '') {
    const query = new URLSearchParams({limit:'12'});
    if (resource) query.set('resource', resource);
    return fetchJson(`${ENDPOINT}?${query}`);
  }

  async function fetchDetail(id, options = {}) {
    return fetchJson(`${ENDPOINT}?id=${encodeURIComponent(id)}`, options);
  }

  async function fetchHistory(resource, resourceId, options = {}) {
    const query = new URLSearchParams({history:'1',resource,resource_id:String(resourceId),limit:'50'});
    return fetchJson(`${ENDPOINT}?${query}`, options);
  }

  function removeDetailModal() {
    document.getElementById('brvtal-activity-modal')?.remove();
  }

  function closeDetail() {
    detailLoadId += 1;
    detailController?.abort(); detailController = null;
    removeDetailModal();
  }

  function beginDetailLoad() {
    detailLoadId += 1;
    detailController?.abort();
    const controller = new AbortController();
    detailController = controller;
    return {loadId:detailLoadId,controller};
  }

  async function openDetail(id) {
    const {loadId,controller} = beginDetailLoad();
    try {
      const item = await fetchDetail(id,{signal:controller.signal});
      if (loadId !== detailLoadId) return;
      removeDetailModal();
      const modal = document.createElement('div');
      modal.id = 'brvtal-activity-modal';
      modal.className = 'activity-modal';
      modal.innerHTML = `
        <div class="activity-modal-card" role="dialog" aria-modal="true" aria-label="Activity detail">
          <div class="activity-modal-head">
            <div><div class="eyebrow">ADMIN HISTORY / #${Number(item.id || 0)}</div><h3>${esc(item.resource_label || item.resource || 'CHANGE')}</h3><div class="helper">${esc(String(item.action || '').toUpperCase())} · ${esc(item.admin_name || item.admin_email || 'Unknown admin')} · ${esc(formatTime(item.created_at))}</div></div>
            <button type="button" class="activity-btn" data-activity-close>CLOSE</button>
          </div>
          <div class="activity-modal-body">
            <div class="activity-fields"><strong>CHANGED:</strong> ${esc((item.changed_fields || []).join(' · ') || 'metadata / relation change')}</div>
            <div class="activity-diff"><section><h4>BEFORE</h4><pre data-activity-before></pre></section><section><h4>AFTER</h4><pre data-activity-after></pre></section></div>
          </div>
        </div>`;
      document.body.appendChild(modal);
      modal.querySelector('[data-activity-before]').textContent = JSON.stringify(item.before ?? null, null, 2);
      modal.querySelector('[data-activity-after]').textContent = JSON.stringify(item.after ?? null, null, 2);
      modal.querySelector('[data-activity-close]').addEventListener('click', closeDetail);
      modal.addEventListener('click', event => { if (event.target === modal) closeDetail(); });
    } catch (error) {
      if (error?.name === 'AbortError' || loadId !== detailLoadId) return;
      window.BRVTALFeedback?.error?.('Activity detail unavailable: ' + (error?.message || error),'admin-activity-detail');
    } finally {
      if (loadId === detailLoadId) detailController = null;
    }
  }

  function displayValue(value) {
    if (value === null || typeof value === 'undefined' || value === '') return '—';
    return typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value);
  }

  function historyDiff(item) {
    const fields = Array.isArray(item.changed_fields) ? item.changed_fields : [];
    if (!fields.length) return '<div class="empty">This version records a relation or metadata change.</div>';
    return fields.map(field => `<section class="history-field"><h4>${esc(field.replaceAll('_',' ').toUpperCase())}</h4><div class="history-values"><div class="history-value"><small>BEFORE</small>${esc(displayValue(item.before?.[field]))}</div><div class="history-value"><small>AFTER</small>${esc(displayValue(item.after?.[field]))}</div></div></section>`).join('');
  }

  async function openHistory(resource, resourceId, label = '') {
    const {loadId,controller} = beginDetailLoad();
    try {
      const data = await fetchHistory(resource, resourceId,{signal:controller.signal});
      if (loadId !== detailLoadId) return;
      const items = Array.isArray(data?.items) ? data.items : [];
      removeDetailModal();
      const modal = document.createElement('div');
      modal.id = 'brvtal-activity-modal';
      modal.className = 'activity-modal';
      modal.innerHTML = `<div class="activity-modal-card" role="dialog" aria-modal="true" aria-label="Editorial version history">
        <div class="activity-modal-head"><div><div class="eyebrow">EDITORIAL VERSION HISTORY</div><h3>${esc(label || `${resource} #${resourceId}`)}</h3><div class="helper">${items.length} recorded version${items.length===1?'':'s'} · read-only</div></div><button type="button" class="activity-btn" data-activity-close>CLOSE</button></div>
        <div class="activity-modal-body"><div class="history-layout"><nav class="history-timeline" aria-label="Recorded versions">${items.map((item,index) => `<button type="button" class="history-version${index===0?' is-active':''}" data-history-index="${index}"><b>${esc(String(item.action||'update').replaceAll('_',' ').toUpperCase())}</b><span>${esc(formatTime(item.created_at))}<br>${esc(item.admin_name || item.admin_email || 'Unknown admin')}</span></button>`).join('') || '<div class="empty">No versions recorded.</div>'}</nav><div data-history-diff>${items.length ? `<div class="history-summary">Changed in this version: ${esc((items[0].changed_fields||[]).join(' · ') || 'metadata / relation')}</div>${historyDiff(items[0])}` : ''}</div></div></div>
      </div>`;
      document.body.appendChild(modal);
      const diff = modal.querySelector('[data-history-diff]');
      modal.querySelectorAll('[data-history-index]').forEach(button => button.addEventListener('click', () => {
        const item = items[Number(button.dataset.historyIndex) || 0];
        modal.querySelectorAll('[data-history-index]').forEach(entry => entry.classList.toggle('is-active', entry === button));
        diff.innerHTML = `<div class="history-summary">Changed in this version: ${esc((item.changed_fields||[]).join(' · ') || 'metadata / relation')}</div>${historyDiff(item)}`;
      }));
      modal.querySelector('[data-activity-close]').addEventListener('click', closeDetail);
      modal.addEventListener('click', event => { if (event.target === modal) closeDetail(); });
    } catch (error) {
      if (error?.name === 'AbortError' || loadId !== detailLoadId) return;
      window.BRVTALFeedback?.error?.('Version history unavailable: ' + (error?.message || error),'editorial-version-history');
    } finally {
      if (loadId === detailLoadId) detailController = null;
    }
  }

  function rowHtml(item) {
    const fields = Array.isArray(item.changed_fields) ? item.changed_fields : [];
    const actor = item.admin_name || item.admin_email || (item.admin_id ? `Admin #${item.admin_id}` : 'Unknown admin');
    const action = String(item.action || 'update').toLowerCase();
    const resource = String(item.resource || 'content');
    return `<div class="activity-row">
      <div class="activity-action ${esc(action)}">${esc(action.toUpperCase().replaceAll('_',' '))}</div>
      <div><div class="activity-title">${esc(item.resource_label || `${resource} #${item.resource_id || ''}`)}</div><div class="activity-meta">${esc(resource.toUpperCase())}${fields.length ? ' · ' + esc(fields.slice(0,5).join(' · ')) : ''}</div></div>
      <div class="activity-actor">${esc(actor)}</div>
      <div class="activity-time">${esc(formatTime(item.created_at))}</div>
      <div class="activity-actions"><button type="button" class="activity-btn" data-activity-view="${Number(item.id)||0}">DETAIL</button>${Number(item.resource_id)>0 ? `<button type="button" class="activity-btn" data-activity-history="${Number(item.resource_id)}" data-activity-resource="${esc(resource)}" data-activity-label="${esc(item.resource_label || '')}">HISTORY</button>` : ''}<button type="button" class="activity-btn" data-activity-open="${esc(sectionFor(resource))}">OPEN</button></div>
    </div>`;
  }

  function render(data, selectedResource = '') {
    const main = document.querySelector('.main');
    if (!main || typeof state === 'undefined' || !state.authed || state.section !== 'dashboard' || document.getElementById('brvtal-dashboard-v2')) return;
    document.getElementById('brvtal-admin-activity')?.remove();

    const items = Array.isArray(data?.items) ? data.items : [];
    const panel = document.createElement('section');
    panel.id = 'brvtal-admin-activity';
    panel.className = 'activity-panel';
    panel.innerHTML = `
      <div class="activity-head">
        <div><div class="eyebrow">TRACEABILITY / ADMIN HISTORY</div><h2>ADMIN ACTIVITY</h2><div class="helper">Append-only audit history for important editorial changes.</div></div>
        <div class="activity-tools"><select class="activity-filter" data-activity-filter>${resources.map(resource => `<option value="${esc(resource)}"${resource===selectedResource?' selected':''}>${esc(resource ? resource.toUpperCase().replaceAll('_',' ') : 'ALL CONTENT')}</option>`).join('')}</select><button type="button" class="activity-btn" data-activity-refresh>REFRESH</button></div>
      </div>
      <div class="activity-list">${items.length ? items.map(rowHtml).join('') : '<div class="empty">No activity recorded for this filter.</div>'}</div>
      <div class="activity-note">Read-only. This log does not expose passwords, TOTP secrets, recovery codes, sessions or raw Settings values. Restore/revert actions are intentionally not available in v1.</div>`;
    main.appendChild(panel);

    panel.querySelector('[data-activity-filter]')?.addEventListener('change', event => mount(String(event.target.value || '')));
    panel.querySelector('[data-activity-refresh]')?.addEventListener('click', () => mount(selectedResource));
    panel.querySelectorAll('[data-activity-view]').forEach(button => button.addEventListener('click', () => openDetail(button.dataset.activityView)));
    panel.querySelectorAll('[data-activity-history]').forEach(button => button.addEventListener('click', () => openHistory(button.dataset.activityResource, button.dataset.activityHistory, button.dataset.activityLabel)));
    panel.querySelectorAll('[data-activity-open]').forEach(button => button.addEventListener('click', () => window.go?.(button.dataset.activityOpen)));
  }

  async function mount(resource = '') {
    if (loading || typeof state === 'undefined' || !state.authed || state.section !== 'dashboard' || document.getElementById('brvtal-dashboard-v2')) return;
    loading = true;
    try {
      ensureStyle();
      const activity = await fetchList(resource);
      if (document.getElementById('brvtal-dashboard-v2')) return;
      render(activity, resource);
    } catch (error) {
      if (error?.message === 'ACTIVITY_SCHEMA_MISSING') return;
      window.BRVTALFeedback?.error?.('Admin Activity unavailable: ' + (error?.message || error),'admin-activity');
    } finally {
      loading = false;
    }
  }

  const originalGo = window.go;
  if (typeof originalGo === 'function') {
    window.go = async function(section) {
      const result = await originalGo.apply(this, arguments);
      if (section === 'dashboard') setTimeout(() => mount(''), 0);
      return result;
    };
  }

  const observer = new MutationObserver(() => {
    if (typeof state === 'undefined' || !state.authed || state.section !== 'dashboard' || document.getElementById('brvtal-dashboard-v2') || document.getElementById('brvtal-admin-activity')) return;
    clearTimeout(observer._activityTimer);
    observer._activityTimer = setTimeout(() => mount(''), 25);
  });
  observer.observe(document.documentElement,{childList:true,subtree:true});

  ensureStyle();
  setTimeout(() => mount(''), 80);
  window.BRVTALAdminActivity = {mount,openDetail,openHistory,closeDetail};
})();
