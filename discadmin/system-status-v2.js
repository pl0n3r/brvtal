(() => {
  'use strict';

  const TECH = '/discadmin/technical.php?action=overview';
  const HEALTH = '/api/content-health.php';
  const ACTIVITY = '/api/admin-activity.php?limit=5';
  const LOGS = '/discadmin/technical.php?action=logs';
  const LOG_RESET = '/discadmin/logs.php?action=clear&format=json';
  const AUTH = '/api/index.php/auth';
  const GITHUB_ISSUES = 'https://github.com/pl0n3r/brvtal/issues';
  let mountTimer = null;
  let refreshTimer = null;
  let requestId = 0;
  let logOperationId = 0;
  let logResetInFlight = false;

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
    const raw = String(value || '');
    const then = Date.parse(raw.replace(' ', 'T') + (raw.includes('T') ? '' : 'Z'));
    if (!Number.isFinite(then)) return raw;
    const diff = Math.max(0, Date.now() - then);
    const minutes = Math.floor(diff / 60000);
    if (minutes < 1) return 'NOW';
    if (minutes < 60) return `${minutes}M`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}H`;
    return `${Math.floor(hours / 24)}D`;
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
      credentials:'same-origin',
      cache:'no-store',
      ...options,
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) {
      const requestError = String(payload.error ?? '').trim();
      throw new Error(requestError === '' ? `HTTP_${response.status}` : requestError);
    }
    return payload;
  }

  async function csrfToken() {
    if (globalThis.csrf) {
      return globalThis.csrf;
    }
    const auth = await fetchJson(AUTH);
    if (!auth.authenticated || !auth.csrf) {
      throw new Error('AUTH_REQUIRED');
    }
    globalThis.csrf = auth.csrf;
    return auth.csrf;
  }

  function invalidateCachedCsrf(error) {
    const code = String(error?.message ?? '');
    if (['CSRF','AUTH_REQUIRED','HTTP_419','HTTP_401'].includes(code)) {
      delete globalThis.csrf;
    }
  }

  function renderLogPayload(root, payload) {
    const output = root.querySelector('#ssv2-logs');
    const meta = root.querySelector('#ssv2-log-meta');
    if (!output || !meta) {
      return;
    }
    output.hidden = false;
    const logContent = String(payload.content ?? '');
    output.textContent = logContent === '' ? 'No log entries.' : logContent;
    meta.textContent = `${number(payload.lines)} LINES · ${number(payload.bytes)} B`;
  }

  function setLogMeta(root, message) {
    const meta = root.querySelector('#ssv2-log-meta');
    if (meta) {
      meta.textContent = message;
    }
  }

  function setLogActionsDisabled(root, disabled) {
    root.querySelectorAll('#ssv2-load-logs,#ssv2-reset-logs')
      .forEach(action => { action.disabled = disabled; });
  }

  function nextLogOperation() {
    logOperationId += 1;
    return logOperationId;
  }

  function isCurrentLogOperation(operationId) {
    return operationId === logOperationId;
  }

  async function loadLogs(root, button) {
    const operationId = nextLogOperation();
    button.disabled = true;
    button.textContent = 'LOADING…';
    try {
      const payload = await fetchJson(LOGS);
      if (!isCurrentLogOperation(operationId)) {
        return;
      }
      renderLogPayload(root, payload);
      button.textContent = 'REFRESH LOGS';
    } catch (error) {
      if (!isCurrentLogOperation(operationId)) {
        return;
      }
      setLogMeta(root, `LOAD FAILED · ${error.message}`);
      button.textContent = 'RETRY LOGS';
    } finally {
      if (isCurrentLogOperation(operationId)) {
        button.disabled = false;
      }
    }
  }

  function requestLogReset(token) {
    return fetchJson(LOG_RESET, {
      method:'POST',
      headers:{
        'Accept':'application/json',
        'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body:new URLSearchParams({csrf:token}).toString(),
    });
  }

  function currentSystemStatusRoot(fallback) {
    return document.getElementById('system-status-v2') ?? fallback;
  }

  async function refreshLogsAfterReset(root, operationId) {
    try {
      const payload = await fetchJson(LOGS);
      if (isCurrentLogOperation(operationId)) {
        renderLogPayload(currentSystemStatusRoot(root), payload);
      }
    } catch (error) {
      if (isCurrentLogOperation(operationId)) {
        setLogMeta(currentSystemStatusRoot(root), `RESET COMPLETE · REFRESH FAILED · ${error.message}`);
      }
    }
  }

  function armLogReset(button) {
    if (button.dataset.resetArmed === 'true') {
      delete button.dataset.resetArmed;
      return true;
    }
    button.dataset.resetArmed = 'true';
    button.textContent = 'CONFIRM RESET';
    globalThis.setTimeout(() => {
      if (button.dataset.resetArmed !== 'true') {
        return;
      }
      delete button.dataset.resetArmed;
      if (!button.disabled) {
        button.textContent = 'RESET LOG';
      }
    }, 7000);
    return false;
  }

  function showResetSuccess(root) {
    const currentRoot = currentSystemStatusRoot(root);
    setLogMeta(currentRoot, 'RESET COMPLETE');
    const currentOutput = currentRoot.querySelector('#ssv2-logs');
    if (currentOutput) {
      currentOutput.hidden = false;
      currentOutput.textContent = 'No log entries.';
    }
    return currentRoot;
  }

  function showResetFailure(root, previousLogState, error) {
    const currentRoot = currentSystemStatusRoot(root);
    setLogMeta(currentRoot, `RESET FAILED · ${error.message}`);
    const currentButton = currentRoot.querySelector('#ssv2-reset-logs');
    const currentOutput = currentRoot.querySelector('#ssv2-logs');
    if (currentButton) {
      currentButton.textContent = 'RETRY RESET';
    }
    if (currentOutput && previousLogState) {
      currentOutput.toggleAttribute('hidden', previousLogState.hidden);
      currentOutput.textContent = previousLogState.textContent;
    }
  }

  function setResetButtonText(root, text) {
    const currentButton = currentSystemStatusRoot(root).querySelector('#ssv2-reset-logs');
    if (currentButton) {
      currentButton.textContent = text;
    }
  }

  async function resetLogs(root, button) {
    if (logResetInFlight) {
      return;
    }
    if (!armLogReset(button)) {
      return;
    }

    logResetInFlight = true;
    const operationId = nextLogOperation();
    const output = root.querySelector('#ssv2-logs');
    const previousLogState = output
      ? {hidden:output.hidden, textContent:output.textContent}
      : null;
    setLogActionsDisabled(root, true);
    button.textContent = 'RESETTING…';
    try {
      const token = await csrfToken();
      await requestLogReset(token);
      if (!isCurrentLogOperation(operationId)) {
        return;
      }
      const successRoot = showResetSuccess(root);
      await refreshLogsAfterReset(successRoot, operationId);
      if (isCurrentLogOperation(operationId)) {
        setResetButtonText(root, 'RESET LOG');
      }
    } catch (error) {
      if (!isCurrentLogOperation(operationId)) {
        return;
      }
      invalidateCachedCsrf(error);
      showResetFailure(root, previousLogState, error);
    } finally {
      logResetInFlight = false;
      if (isCurrentLogOperation(operationId)) {
        setLogActionsDisabled(currentSystemStatusRoot(root), false);
      }
    }
  }

  function unavailableSource(error) {
    return {ok:false,error:String(error?.message || error || 'UNAVAILABLE')};
  }

  function sourceIssue(source, title) {
    if (source?.ok !== false) return null;
    return {
      severity:'warning',
      title,
      detail:`UNAVAILABLE — ${String(source.error || 'request failed')}`,
    };
  }

  function supplementalIssues(health, activity) {
    return [
      sourceIssue(health, 'CONTENT HEALTH'),
      sourceIssue(activity, 'ADMIN ACTIVITY'),
    ].filter(Boolean);
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

  function platformIssueList(issues=[]) {
    if (!issues.length) return `<div class="ssv2-no-issues"><i></i><div><strong>NO ACTIVE PLATFORM SIGNALS</strong><span>All monitored platform checks are operating normally.</span></div></div>`;
    return issues.map(issue => `<div class="ssv2-issue ${esc(issue.severity || 'warning')}"><i></i><div><strong>${esc(issue.title)}</strong><span>${esc(issue.detail)}</span></div></div>`).join('');
  }

  function repositoryDiagnosticList(diagnostics=[]) {
    if (!diagnostics.length) return '';
    return `<div class="ssv2-repository-diagnostics">${diagnostics.map(issue => `<div class="ssv2-issue ${esc(issue.severity || 'info')}"><i></i><div><strong>${esc(issue.title)}</strong><span>${esc(issue.detail)}</span></div></div>`).join('')}</div>`;
  }

  function githubBacklog(github={}) {
    const state = String(github.backlog_state || 'unavailable');
    const known = github.open_issues !== null && github.open_issues !== undefined && state !== 'unavailable';
    if (!known) {
      return `<div class="ssv2-backlog-unavailable"><strong>GITHUB BACKLOG UNAVAILABLE</strong><span>Open Issue metadata could not be refreshed. Platform health is unaffected.</span></div>`;
    }

    const total = Math.max(0, Number(github.open_issues || 0));
    if (total === 0) {
      return `<div class="ssv2-backlog-empty"><strong>NO OPEN GITHUB ISSUES</strong><span>The repository backlog is currently empty.</span></div>`;
    }

    const items = Array.isArray(github.recent_issues) ? github.recent_issues : [];
    const rows = items.map(issue => {
      const issueNumber = Math.max(0, Number(issue?.number || 0));
      if (!issueNumber) return '';
      const labels = Array.isArray(issue?.labels) ? issue.labels.slice(0, 4).filter(Boolean) : [];
      const meta = [labels.join(' / '), relativeTime(issue?.updated_at)].filter(Boolean).join(' · ');
      const url = `${GITHUB_ISSUES}/${encodeURIComponent(issueNumber)}`;
      return `<a class="ssv2-backlog-item" href="${esc(url)}" target="_blank" rel="noopener">
        <span>GITHUB #${number(issueNumber)} · BACKLOG</span><strong>${esc(issue?.title || 'Untitled issue')}</strong><small>${esc(meta || 'OPEN')}</small>
      </a>`;
    }).join('');

    return `${state === 'stale' ? '<div class="ssv2-backlog-state">CACHED GITHUB DATA</div>' : ''}
      <div class="ssv2-backlog-list">${rows || '<div class="ssv2-empty">RECENT ISSUE LIST UNAVAILABLE</div>'}</div>
      <a class="ssv2-view-all" href="${GITHUB_ISSUES}" target="_blank" rel="noopener">VIEW ALL ${number(total)} ↗</a>`;
  }

  function activityList(activity) {
    if (activity?.ok === false) {
      return `<div class="ssv2-empty"><strong>ADMIN ACTIVITY UNAVAILABLE</strong><br>${esc(activity.error || 'Request failed')} · use REFRESH to retry.</div>`;
    }
    const items = activity?.data?.items || [];
    if (!items.length) return '<div class="ssv2-empty">NO RECENT ACTIVITY</div>';
    return items.map(item => `<div class="ssv2-activity-row">
      <i></i><div class="ssv2-activity-main"><strong>${esc(String(item.action || '').replaceAll('_',' ').toUpperCase())}</strong><span>${esc(item.resource_label || `${item.resource || 'CONTENT'} #${item.resource_id || ''}`)}</span></div>
      <div class="ssv2-activity-meta"><b>${esc(item.admin_name || 'ADMIN')}</b><span>${esc(relativeTime(item.created_at))}</span></div>
    </div>`).join('');
  }

  function contentHealthBlock(health) {
    if (health?.ok === false) {
      return `<div class="ssv2-empty"><strong>CONTENT HEALTH UNAVAILABLE</strong><br>${esc(health.error || 'Request failed')} · use REFRESH to retry.</div>`;
    }
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
    const backlogKnown = github.open_issues !== null && github.open_issues !== undefined && github.backlog_state !== 'unavailable';
    const syncText = github.cache === 'stale' ? 'GitHub cached' : github.ok ? 'GitHub synced' : 'GitHub unavailable';
    return `<div class="ssv2-repo-metrics">
      <a href="https://github.com/pl0n3r/brvtal/commits/main" target="_blank" rel="noopener"><strong>${github.commits == null ? '—' : number(github.commits)}</strong><span>COMMITS / MAIN</span></a>
      <a href="https://github.com/pl0n3r/brvtal/pulls?q=is%3Apr+is%3Amerged" target="_blank" rel="noopener"><strong>${github.merged_prs == null ? '—' : number(github.merged_prs)}</strong><span>MERGED PRs</span></a>
      <a href="${GITHUB_ISSUES}" target="_blank" rel="noopener"><strong>${backlogKnown ? number(github.open_issues) : '—'}</strong><span>OPEN ISSUES</span><small>${backlogKnown ? esc(String(github.backlog_state || 'fresh').toUpperCase()) : 'UNAVAILABLE'}</small></a>
      <div><strong>${number(repo.source_lines)}</strong><span>SOURCE LOC</span><small>${number(repo.source_files)} source files</small></div>
    </div>
    <div class="ssv2-repo-foot"><a href="${esc(commitUrl)}" target="_blank" rel="noopener">DEPLOY ${esc(deploy.short_commit || 'UNKNOWN')} ↗</a><span>${esc(syncText)}</span></div>
    <div class="ssv2-language-bars">${languageBars(repo)}</div>`;
  }

  function render(root, data, health, activity, latency) {
    if (!root.isConnected || !isSystemStatus()) return;
    const advancedOpen = root.querySelector('.ssv2-advanced')?.open === true;
    const storage = data.storage || {};
    const deployment = data.deployment || {};
    const runtime = data.runtime || {};
    const database = data.database || {};
    const github = data.repository?.github || {};
    const storageUsed = clamp(storage.used_percent || 0);
    const sourceIssues = supplementalIssues(health, activity);
    const platformIssues = [...(data.issues || []), ...sourceIssues];
    const repositoryDiagnostics = Array.isArray(data.repository_diagnostics) ? data.repository_diagnostics : [];
    const githubKnown = github.open_issues !== null && github.open_issues !== undefined && github.backlog_state !== 'unavailable';
    const githubSummary = githubKnown ? `${number(github.open_issues)} GITHUB` : 'GITHUB —';
    const statusText = sourceIssues.length
      ? 'DEGRADED'
      : String(data.health?.status || 'unknown').toUpperCase();
    const activityTotal = activity?.ok === false ? 'UNAVAILABLE' : `${number(activity?.data?.total || 0)} TOTAL`;

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
        <section class="ssv2-panel"><div class="ssv2-panel-head"><span>EDITORIAL HEALTH</span><b>${health?.ok === false ? 'UNAVAILABLE' : 'CONTENT CORE'}</b></div>${contentHealthBlock(health)}</section>
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
        <section class="ssv2-panel ssv2-attention"><div class="ssv2-panel-head"><span>ATTENTION REQUIRED</span><b>${number(platformIssues.length)} PLATFORM · ${githubSummary}</b></div>
          <div class="ssv2-attention-groups">
            <div class="ssv2-attention-group"><div class="ssv2-attention-label"><span>PLATFORM SIGNALS</span><b>${number(platformIssues.length)}</b></div><div class="ssv2-issues">${platformIssueList(platformIssues)}</div></div>
            <div class="ssv2-attention-group ssv2-github-backlog"><div class="ssv2-attention-label"><span>GITHUB BACKLOG</span><b>${githubKnown ? number(github.open_issues) : '—'}</b></div>${repositoryDiagnosticList(repositoryDiagnostics)}${githubBacklog(github)}</div>
          </div>
        </section>
      </div>

      <section class="ssv2-panel"><div class="ssv2-panel-head"><span>RECENT ADMIN ACTIVITY</span><b>${esc(activityTotal)}</b></div><div class="ssv2-activity">${activityList(activity)}</div></section>

      <details class="ssv2-advanced"><summary>ADVANCED DIAGNOSTICS <span>RAW DATA / LOGS</span></summary>
        <div class="ssv2-advanced-actions"><div class="ssv2-log-actions"><button type="button" id="ssv2-load-logs">LOAD RECENT LOGS</button><button type="button" id="ssv2-reset-logs" class="ssv2-danger">RESET LOG</button></div><span id="ssv2-log-meta">LOG NOT LOADED</span></div>
        <pre id="ssv2-raw">${esc(JSON.stringify({overview:data,content_health:health,activity},null,2))}</pre><pre id="ssv2-logs" hidden></pre>
      </details>`;

    const advanced = root.querySelector('.ssv2-advanced');
    if (advanced && (advancedOpen || logResetInFlight)) {
      advanced.open = true;
    }
    setLogActionsDisabled(root, logResetInFlight);
    root.querySelector('#ssv2-refresh')?.addEventListener('click', () => load(root, true));
    root.querySelector('#ssv2-load-logs')?.addEventListener('click', event => {
      void loadLogs(root, event.currentTarget);
    });
    root.querySelector('#ssv2-reset-logs')?.addEventListener('click', event => {
      void resetLogs(root, event.currentTarget);
    });
  }

  async function load(root, manual=false) {
    const id = ++requestId;
    if (manual) root.classList.add('is-refreshing');
    const started = performance.now();
    try {
      const [data,health,activity] = await Promise.all([
        fetchJson(TECH),
        fetchJson(HEALTH).catch(error => unavailableSource(error)),
        fetchJson(ACTIVITY).catch(error => unavailableSource(error)),
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
