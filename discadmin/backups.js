(() => {
  'use strict';

  const ENDPOINT = '/discadmin/backups.php';
  let mountTimer = null;
  let loading = false;

  const esc = value => String(value ?? '')
    .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
    .replaceAll('"','&quot;').replaceAll("'",'&#039;');

  function csrfToken() {
    try {
      if (typeof csrf === 'string' && csrf) return csrf;
    } catch (_) {}
    return '';
  }

  async function ensureCsrf() {
    const existing = csrfToken();
    if (existing) return existing;
    if (window.BRVTALAdminAuthBoundary?.csrfToken) {
      return window.BRVTALAdminAuthBoundary.csrfToken();
    }
    throw new Error('CSRF_UNAVAILABLE');
  }

  async function fetchJson(url, options={}) {
    const response = await fetch(url, {credentials:'same-origin', cache:'no-store', ...options});
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.ok === false) throw new Error(data.error || `HTTP_${response.status}`);
    return data;
  }

  function dateLabel(value) {
    const date = new Date(value || '');
    if (!Number.isFinite(date.getTime())) return String(value || 'UNKNOWN');
    return date.toLocaleString([], {year:'numeric',month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit'});
  }

  function downloadLink(item, component, label) {
    const info = item.components?.[component] || {};
    if (!info.available) return '';
    const href = `${ENDPOINT}?action=download&id=${encodeURIComponent(item.id)}&component=${encodeURIComponent(component)}`;
    return `<a class="backup-download" href="${href}">${esc(label)} ↗</a>`;
  }

  function backupRows(items=[]) {
    if (!items.length) {
      return `<div class="backup-empty"><strong>NO BACKUPS YET</strong><span>Create the first private database + media inventory backup.</span></div>`;
    }
    return items.map(item => {
      const archive = item.components?.media_archive || {};
      const status = String(item.status || 'unknown').toLowerCase();
      return `<div class="backup-row" data-status="${esc(status)}">
        <div class="backup-state"><i></i><div><strong>${esc(status.toUpperCase())}</strong><span>${esc(dateLabel(item.created_at))}</span></div></div>
        <div class="backup-id"><strong>${esc(item.id)}</strong><span>${esc(String(item.trigger || 'manual').toUpperCase())} · ${esc(String(item.scope || 'full').toUpperCase())} · DEPLOY ${esc(item.deployment?.short_commit || '—')} · ${esc(item.artifacts_size || '0 B')}</span></div>
        <div class="backup-components">
          ${downloadLink(item,'database','DATABASE SQL')}
          ${downloadLink(item,'media_manifest','MEDIA MANIFEST')}
          ${archive.available ? downloadLink(item,'media_archive','MEDIA ZIP') : (archive.status === 'unavailable' ? `<span title="${esc(archive.reason || '')}">MEDIA ZIP UNAVAILABLE</span>` : '')}
          ${downloadLink(item,'manifest','MANIFEST')}
        </div>
      </div>`;
    }).join('');
  }

  function render(panel, payload) {
    const data = payload?.data || {};
    const items = Array.isArray(data.items) ? data.items : [];
    const latest = items[0] || null;
    const zipAvailable = !!data.capabilities?.media_archive;
    const automation = data.automation || {};
    const schedule = automation.config || {};
    const cadence = schedule.cadence || {type:'daily',interval:1,time:'03:00'};
    const drive = schedule.drive || {enabled:false,folder:null};

    panel.innerHTML = `
      <div class="ssv2-panel-head"><span>BACKUPS</span><b>PRIVATE / AUTOMATED</b></div>
      <div class="backup-summary">
        <div><strong>${items.length}</strong><span>BACKUPS</span></div>
        <div><strong>${esc(latest?.status?.toUpperCase() || 'NONE')}</strong><span>LATEST STATUS</span></div>
        <div><strong>${esc(schedule.enabled ? 'ON' : 'OFF')}</strong><span>AUTOMATION</span></div>
        <div><strong>${esc(automation.next_run_at ? dateLabel(automation.next_run_at) : 'PAUSED')}</strong><span>NEXT RUN</span></div>
      </div>
      <div class="backup-actions">
        <div><button type="button" class="backup-create" data-media="0">CREATE BACKUP</button><span>Database SQL + media inventory manifest.</span></div>
        <div><button type="button" class="backup-create ghost" data-media="1" ${zipAvailable ? '' : 'disabled'}>CREATE + MEDIA ZIP</button><span>${zipAvailable ? 'Also archive current uploads. Can take longer.' : 'ZipArchive unavailable on this runtime.'}</span></div>
        <button type="button" class="backup-refresh">REFRESH</button>
      </div>
      <section class="backup-automation" aria-label="Backup automation">
        <div class="backup-section-head"><div><strong>AUTOMATION</strong><span>Server-side · America/Bogota · Hostinger Cron compatible</span></div><b>${schedule.enabled ? 'ENABLED' : 'PAUSED'}</b></div>
        <div class="backup-grid">
          <label><span>ENABLED</span><input type="checkbox" data-backup-enabled ${schedule.enabled ? 'checked' : ''}></label>
          <label><span>CADENCE</span><select data-backup-cadence>
            <option value="hours" ${cadence.type==='hours'?'selected':''}>EVERY N HOURS</option>
            <option value="days" ${cadence.type==='days'?'selected':''}>EVERY N DAYS</option>
            <option value="daily" ${cadence.type==='daily'?'selected':''}>DAILY</option>
          </select></label>
          <label><span>INTERVAL</span><input type="number" min="1" max="168" value="${esc(cadence.interval || 1)}" data-backup-interval></label>
          <label><span>LOCAL TIME</span><input type="time" value="${esc(cadence.time || '03:00')}" data-backup-time></label>
          <label><span>SCOPE</span><select data-backup-scope>
            <option value="full" ${schedule.scope==='full'?'selected':''}>FULL</option>
            <option value="database" ${schedule.scope==='database'?'selected':''}>DATABASE ONLY</option>
            <option value="media" ${schedule.scope==='media'?'selected':''}>MEDIA ONLY</option>
          </select></label>
          <label><span>KEEP AUTO BACKUPS</span><input type="number" min="1" max="90" value="${esc(schedule.retention_local || 7)}" data-backup-retention></label>
        </div>
        <div class="backup-automation-actions">
          <label class="backup-check"><input type="checkbox" data-backup-media-zip ${schedule.include_media_archive?'checked':''}> INCLUDE MEDIA ZIP</label>
          <button type="button" class="backup-save">SAVE AUTOMATION</button>
        </div>
        <div class="backup-last">LAST RESULT · ${esc(automation.last_result?.status?.toUpperCase() || 'NONE')} · ${esc(automation.last_run_at ? dateLabel(automation.last_run_at) : 'NEVER')}</div>
      </section>
      <section class="backup-offsite" aria-label="Google Drive off-site backup">
        <div class="backup-section-head"><div><strong>OFF-SITE / GOOGLE DRIVE</strong><span>Local backup remains authoritative if Drive delivery fails.</span></div><b>${automation.drive?.connected ? 'CONNECTED' : 'AUTH REQUIRED'}</b></div>
        <div class="backup-grid backup-grid-drive">
          <label><span>UPLOAD ENABLED</span><input type="checkbox" data-drive-enabled ${drive.enabled?'checked':''}></label>
          <label><span>TARGET FOLDER</span><input type="text" maxlength="160" value="${esc(drive.folder || '')}" placeholder="BRVTAL Backups" data-drive-folder></label>
        </div>
        <div class="backup-drive-note">OAuth tokens are not stored in this form or returned to the browser. Connection credentials require the protected server-side authorization flow before Drive delivery can succeed.</div>
      </section>
      <div class="backup-warning"><i></i><span>Backups contain sensitive database state. Files are stored in private server storage and downloads require an authenticated admin session. Store downloaded copies securely.</span></div>
      <div class="backup-list">${backupRows(items)}</div>
      <div class="backup-foot"><span>NO AUTOMATIC RESTORE</span><span>${data.private_storage ? 'PRIVATE STORAGE VERIFIED' : 'CHECK STORAGE PRIVACY'}</span></div>`;

    panel.querySelectorAll('.backup-create').forEach(button => button.addEventListener('click', () => createBackup(panel, button.dataset.media === '1')));
    panel.querySelector('.backup-refresh')?.addEventListener('click', () => load(panel));
    panel.querySelector('.backup-save')?.addEventListener('click', () => saveAutomation(panel));
  }

  async function load(panel) {
    if (loading || !panel?.isConnected) return;
    loading = true;
    panel.classList.add('is-loading');
    try {
      const payload = await fetchJson(`${ENDPOINT}?action=list`);
      render(panel, payload);
    } catch (error) {
      panel.innerHTML = `<div class="backup-error"><strong>BACKUPS UNAVAILABLE</strong><span>${esc(error.message)}</span><button type="button">RETRY</button></div>`;
      panel.querySelector('button')?.addEventListener('click', () => load(panel));
    } finally {
      loading = false;
      panel.classList.remove('is-loading');
    }
  }

  async function saveAutomation(panel) {
    const token = await ensureCsrf();
    const body = {
      enabled: !!panel.querySelector('[data-backup-enabled]')?.checked,
      cadence: {
        type: panel.querySelector('[data-backup-cadence]')?.value || 'daily',
        interval: Number(panel.querySelector('[data-backup-interval]')?.value || 1),
        time: panel.querySelector('[data-backup-time]')?.value || '03:00',
      },
      scope: panel.querySelector('[data-backup-scope]')?.value || 'full',
      include_media_archive: !!panel.querySelector('[data-backup-media-zip]')?.checked,
      retention_local: Number(panel.querySelector('[data-backup-retention]')?.value || 7),
      drive: {
        enabled: !!panel.querySelector('[data-drive-enabled]')?.checked,
        folder: panel.querySelector('[data-drive-folder]')?.value || null,
      },
    };
    await fetchJson(`${ENDPOINT}?action=automation`, {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':token},
      body:JSON.stringify(body),
    });
    await load(panel);
  }

  async function createBackup(panel, includeMedia) {
    const message = includeMedia
      ? 'Create a private database backup, media inventory and media ZIP now? This may take longer and consume additional hosting storage.'
      : 'Create a private database backup and media inventory now?';
    if (!window.confirm(message)) return;

    const buttons = [...panel.querySelectorAll('button')];
    buttons.forEach(button => button.disabled = true);
    panel.classList.add('is-creating');
    try {
      const token = await ensureCsrf();
      await fetchJson(`${ENDPOINT}?action=create`, {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-Token':token},
        body:JSON.stringify({include_media_archive:includeMedia,scope:'full'}),
      });
      await load(panel);
    } catch (error) {
      window.alert(`BACKUP ERROR · ${error.message}`);
    } finally {
      panel.classList.remove('is-creating');
      buttons.forEach(button => button.disabled = false);
    }
  }

  function mount() {
    const root = document.getElementById('system-status-v2');
    if (!root) return;
    let panel = root.querySelector('#ssv2-backups');
    if (panel) return;

    const advanced = root.querySelector('.ssv2-advanced');
    if (!advanced) return;
    panel = document.createElement('section');
    panel.id = 'ssv2-backups';
    panel.className = 'ssv2-panel ssv2-backups';
    panel.innerHTML = '<div class="backup-loading"><span></span> READING PRIVATE BACKUPS…</div>';
    advanced.before(panel);
    load(panel);
  }

  const observer = new MutationObserver(() => {
    clearTimeout(mountTimer);
    mountTimer = setTimeout(mount, 60);
  });
  observer.observe(document.documentElement, {childList:true,subtree:true});
  setTimeout(mount,120);

  window.BRVTALBackupsUI = {backupRows, render};
})();
