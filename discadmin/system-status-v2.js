(() => {
  'use strict';

  const TECH = '/discadmin/technical.php?action=overview';
  const HEALTH = '/api/content-health.php';
  const ACTIVITY = '/api/admin-activity.php?limit=5';
  let mountTimer = null;
  let refreshTimer = null;
  let requestId = 0;

  const esc = value => String(value ?? '')
    .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
    .replaceAll('"','&quot;').replaceAll("'",'&#039;');

  const number = value => new Intl.NumberFormat('en-US').format(Number(value || 0));
  const clamp = value => Math.max(0, Math.min(100, Number(value || 0)));

  function isSystemStatus() {
    const activeNav = document.querySelector('.nav button.active')?.textContent?.trim().toUpperCase();
    if (activeNav === 'SYSTEM STATUS') return true;
    const title = document.querySelector('.main .top h1')?.textContent?.trim().toUpperCase();
    return title === 'SYSTEM' || title === 'SYSTEM STATUS';
  }

  function relativeTime(value) {
    const then = Date.parse(String(value || '').replace(' ', 'T') + (String(value || '').includes('T') ? '' : 'Z'));
    if (!Number.isFinite(then)) return String(value || '');
    const diff = Math.max(0, Date.now() - then);
    const minutes = Math.floor(diff / 60000);
    if (minutes < 1) return 'NOW';
    if (minutes < 60) return `${minutes}M`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}H`;
    return `${Math.floor(hours / 24)}D`;
  }

  async function fetchJson(url) {
    const response = await fetch(url, {credentials:'same-origin', cache:'no-store'});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.error || `HTTP_${response.status}`);
    return payload;
  }

  function skeleton() {
    return `
      <section class="ssv2" id="system-status-v2" aria-live="polite">
        <div class="ssv2-loading"><span></span> READING PLATFORM STATUS…</div>
      </section>`;
  }

  function ring(value, label, sub, className='') {
    const v = clamp(value);
    return `<div class="ssv2-ring ${className}" style="--value:${v}">
      <div class="ssv2-ring-inner"><strong>${Math.round(v)}%</strong><span>${esc(label)}</span><small>${esc(sub)}</small></div>
    </div>`;
  }

  function serviceCards(data, latency) {
    return (data.checks || []).map(check => {
      const meta = check.key === 'api' ? `${Math.round(latency)} ms` : check.key === 'database' ? data.database?.server || '' : '';
      return `<div class="ssv2-service" data-state="${esc(check.status)}">
        <div class="ssv2-service-label"><i></i>${esc(check.label)}</div>
        <strong>${esc(check.value)}</strong>
        <small>${esc(meta)}</small>
      </div>`;
    }).join('');
  }

  function databaseBars(counts={}) {
    const labels = [
      ['events','EVENTS'],['artists','ARTISTS'],['sets','SETS'],['releases','RELEASES'],
      ['media','MEDIA'],['pages','PAGES'],['blog','BLOG']
    ];
    const max = Math.max(1, ...labels.map(([key]) => Number(counts[key] || 0)));
    return labels.map(([key,label]) => {
      const value = Number(counts[key] || 0);
      const width = Math.max(value ? 4 : 0, (value / max) * 100);
      return `<div class="ssv2-bar-row"><span>${label}</span><div class="ssv2-bar-track"><i style="width:${width}%"></i></div><b>${number(value)}</b></div>`;
    }).join('');
  }

  function languageBars(repository={}) {
    const rows = Object.entries(repository.by_language || {});
    const max = Math.max(1, ...rows.map(([,value]) => Number(value.lines || 0)));
    if (!rows.length) return '<div class="ssv2-empty">NO SOURCE BREAKDOWN</div>';
    return rows.map(([label,value]) => `<div class="ssv2-lang-row">
      <span>${esc(label)}</span><div class="ssv2-lang-track"><i style="width:${Math.max(3,(Number(value.lines||0)/max)*100)}%"></i></div><b>${number(value.lines)} LOC</b>
    </div>`).join('');
  }

  function issueList(issues=[]) {
    if (!issues.length) return `<div class="ssv2-no-issues"><i></i><div><strong>NO ACTIVE ISSUES</strong><span>All monitored platform checks are operating normally.</span></div></div>`;
    return issues.map(issue => `<div class="ssv2-issue ${esc(issue.severity || 'warning')}"><i></i><div><strong>${esc(issue.title)}</strong><span>${esc(issue.detail)}</span></div></div>`).join('');
  }

  function activityList(activity) {
    const items = activity?.data?.items || [];
    if (!items.length) return '<div class="ssv2-empty">NO RECENT ACTIVITY</div>';
    return items.map(item => `<div class="ssv2-activity-row">
      <i></i><div class="ssv2-activity-main"><strong>${esc(String(item.action || '').replaceAll('_',' ').toUpperCase())}</strong><span>${esc(item.resource_label || `${item.resource || 'CONTENT'} #${item.resource_id || ''}`)}</span></div>
      <div class="ssv2-activity-meta"><b>${esc(item.admin_name || 'ADMIN')}</b><span>${esc(relativeTime(item.created_at))}</span></div>
    </div>`).join('');
  }

  function contentHealthBlock(health) {
    const data = health?.data || {};
    return `<div class="ssv2-content-health">
      ${ring(data.score ?? 100,'CONTENT HEALTH',`${number(data.ready || 0)} ready`,'small')}
      <div class="ssv2-mini-grid">
        <div><strong>${number(data.ready)}</strong><span>READY</span></div>
        <div><strong>${number(data.needs_attention)}</strong><span>ATTENTION</span></div>
        <div><strong>${number(data.missing_visuals)}</strong><span>MISSING MEDIA</span></div>
        <div><strong>${number(data.seo_gaps)}</strong><span>SEO GAPS</span></div>
      </div>
    </div>`;
  }

  function repositoryBlock(data) {
    const repo = data.repository || {};
    const github = repo.github || {};
    const deploy = data.deployment || {};
    const commitUrl = `https://github.com/pl0n3r/brvtal/commit/${encodeURIComponent(deploy.commit || deploy.short_commit || '')}`;
    return `<div class="ssv2-repo-metrics">
      <a href="https://github.com/pl0n3r/brvtal/commits/main" target="_blank" rel="noopener"><strong>${github.commits == null ? '—' : number(github.commits)}</strong><span>COMMITS / MAIN</span></a>
      <a href="https://github.com/pl0n3r/brvtal/pulls?q=is%3Apr+is%3Amerged" target="_blank" rel="noopener"><strong>${github.merged_prs == null ? '—' : number(github.merged_prs)}</strong><span>MERGED PRs</span></a>
      <div><strong>${number(repo.source_lines)}</strong><span>SOURCE LOC</span><small>${number(repo.source_files)} source files</small></div>
    </div>
    <div class="ssv2-repo-foot"><a href="${esc(commitUrl)}" target="_blank" rel="noopener">DEPLOY ${esc(deploy.short_commit || 'UNKNOWN')} ↗</a><span>${esc(github.cache === 'stale' ? 'GitHub cached' : 'GitHub synced')}</span></div>
    <div class="ssv2-language-bars">${languageBars(repo)}</div>`;
  }

  function render(root, data, health, activity, latency) {
    if (!root.isConnected || !isSystemStatus()) return;
    const storage = data.storage || {};
    const deployment = data.deployment || {};
    const runtime = data.runtime || {};
    const database = data.database || {};
    const storageUsed = clamp(storage.used_percent || 0);
    const statusText = String(data.health?.status || 'unknown').toUpperCase();

    root.innerHTML = `
      <div class="ssv2-hero">
        <div class="ssv2-hero-copy"><span>BRVTAL / OPERATIONS</span><h2>PLATFORM CONTROL ROOM</h2><p>Live operational health, deployment, content and engineering signals.</p></div>
        <div class="ssv2-hero-actions"><span id="ssv2-last-check">${esc(new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}))}</span><button type="button" id="ssv2-refresh">REFRESH</button></div>
        <div class="ssv2-health-summary">${ring(data.health?.score || 0,statusText,`${data.health?.checks_ok || 0}/${data.health?.checks_total || 0} checks`)}</div>
      </div>

      <div class="ssv2-services">${serviceCards(data,latency)}</div>

      <div class="ssv2-grid two">
        <section class="ssv2-panel storage"><div class="ssv2-panel-head"><span>STORAGE</span><b>${storageUsed}% USED</b></div>
          <div class="ssv2-storage-wrap"><div class="ssv2-storage-ring" style="--value:${storageUsed}"><div><strong>${storageUsed}%</strong><span>USED</span></div></div>
          <div class="ssv2-storage-copy"><strong>${esc(storage.used || 'N/A')} / ${esc(storage.total || 'N/A')}</strong><span>${esc(storage.free || 'N/A')} FREE</span><small>${number(storage.uploads_items)} upload items</small></div></div>
        </section>
        <section class="ssv2-panel"><div class="ssv2-panel-head"><span>DATABASE CONTENT</span><b>${esc(database.driver || '')} ${esc(database.server || '')}</b></div><div class="ssv2-bars">${databaseBars(database.counts)}</div></section>
      </div>

      <div class="ssv2-grid two">
        <section class="ssv2-panel"><div class="ssv2-panel-head"><span>REPOSITORY</span><b>GITHUB + DEPLOYED SOURCE</b></div>${repositoryBlock(data)}</section>
        <section class="ssv2-panel"><div class="ssv2-panel-head"><span>EDITORIAL HEALTH</span><b>CONTENT CORE</b></div>${contentHealthBlock(health)}</section>
      </div>

      <div class="ssv2-grid split">
        <section class="ssv2-panel"><div class="ssv2-panel-head"><span>RUNTIME / DEPLOYMENT</span><b>${esc(deployment.environment || '')}</b></div>
          <div class="ssv2-runtime-grid">
            <div><span>DEPLOY</span><strong>${esc(deployment.short_commit || '—')}</strong><small>${esc(deployment.source || '')}</small></div>
            <div><span>PHP</span><strong>${esc(runtime.php || '—')}</strong><small>${esc(runtime.sapi || '')}</small></div>
            <div><span>MEMORY</span><strong>${esc(runtime.memory_limit || '—')}</strong><small>limit</small></div>
            <div><span>UPLOAD</span><strong>${esc(runtime.upload_max_filesize || '—')}</strong><small>max file</small></div>
          </div>
        </section>
        <section class="ssv2-panel"><div class="ssv2-panel-head"><span>ATTENTION REQUIRED</span><b>${number((data.issues || []).length)} SIGNALS</b></div><div class="ssv2-issues">${issueList(data.issues)}</div></section>
      </div>

      <section class="ssv2-panel"><div class="ssv2-panel-head"><span>RECENT ADMIN ACTIVITY</span><b>${number(activity?.data?.total || 0)} TOTAL</b></div><div class="ssv2-activity">${activityList(activity)}</div></section>

      <details class="ssv2-advanced"><summary>ADVANCED DIAGNOSTICS <span>RAW DATA / LOGS</span></summary>
        <div class="ssv2-advanced-actions"><button type="button" id="ssv2-load-logs">LOAD RECENT LOGS</button><span>Diagnostics are read-only. No automatic repair actions.</span></div>
        <pre id="ssv2-raw">${esc(JSON.stringify(data,null,2))}</pre><pre id="ssv2-logs" hidden></pre>
      </details>`;

    root.querySelector('#ssv2-refresh')?.addEventListener('click', () => load(root, true));
    root.querySelector('#ssv2-load-logs')?.addEventListener('click', async event => {
      const button = event.currentTarget;
      const output = root.querySelector('#ssv2-logs');
      button.disabled = true; button.textContent = 'LOADING…';
      try {
        const logs = await fetchJson('/discadmin/technical.php?action=logs');
        output.hidden = false; output.textContent = logs.content || 'No log entries.';
        button.textContent = 'REFRESH LOGS';
      } catch (error) {
        output.hidden = false; output.textContent = `LOG ERROR: ${error.message}`;
        button.textContent = 'RETRY LOGS';
      } finally { button.disabled = false; }
    });
  }

  async function load(root, manual=false) {
    const id = ++requestId;
    if (manual) root.classList.add('is-refreshing');
    const started = performance.now();
    try {
      const [data,health,activity] = await Promise.all([
        fetchJson(TECH),
        fetchJson(HEALTH).catch(() => ({ok:false,data:{score:0}})),
        fetchJson(ACTIVITY).catch(() => ({ok:false,data:{items:[],total:0}})),
      ]);
      if (id !== requestId || !root.isConnected) return;
      render(root,data,health,activity,performance.now()-started);
    } catch (error) {
      if (id !== requestId || !root.isConnected) return;
      root.innerHTML = `<div class="ssv2-fatal"><strong>SYSTEM STATUS UNAVAILABLE</strong><span>${esc(error.message)}</span><button type="button">RETRY</button></div>`;
      root.querySelector('button')?.addEventListener('click',()=>load(root,true));
    } finally {
      root.classList.remove('is-refreshing');
    }
  }

  function mount() {
    if (!isSystemStatus()) return;
    const main = document.querySelector('.main');
    const top = main?.querySelector(':scope > .top');
    if (!main || !top) return;
    let root = main.querySelector(':scope > #system-status-v2');
    if (root) return;

    [...main.children].forEach(child => { if (child !== top) child.remove(); });
    top.insertAdjacentHTML('afterend', skeleton());
    root = document.getElementById('system-status-v2');
    if (!root) return;
    load(root);
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(function autoRefresh(){
      const current = document.getElementById('system-status-v2');
      if (current?.isConnected && isSystemStatus()) {
        load(current);
        refreshTimer = setTimeout(autoRefresh, 60000);
      }
    },60000);
  }

  const observer = new MutationObserver(() => {
    clearTimeout(mountTimer);
    mountTimer = setTimeout(mount, 40);
  });
  observer.observe(document.documentElement,{childList:true,subtree:true});
  setTimeout(mount,80);
})();
