(() => {
  'use strict';

  const ENDPOINT = '/discadmin/storage-metrics.php';
  let timer = null;
  let loading = false;
  let lastFetchAt = 0;

  const percentLabel = value => {
    const n = Number(value || 0);
    return `${n < 0.1 ? n.toFixed(2) : n.toFixed(1)}%`;
  };

  function storagePanel() {
    const root = document.getElementById('system-status-v2');
    const panel = root?.querySelector('.ssv2-panel.storage');
    return root && panel ? panel : null;
  }

  function applyUnavailable() {
    const panel = storagePanel();
    if (!panel) return false;

    const headValue = panel.querySelector('.ssv2-panel-head b');
    const ring = panel.querySelector('.ssv2-storage-ring');
    const ringValue = ring?.querySelector('strong');
    const copy = panel.querySelector('.ssv2-storage-copy');

    panel.dataset.storageScope = 'unavailable';
    panel.title = 'BRVTAL managed storage metrics are unavailable. Host filesystem capacity is diagnostic only and is not shown as the application quota.';
    if (headValue) headValue.textContent = 'UNAVAILABLE · BRVTAL DATA';
    if (ring) ring.style.setProperty('--value', '0');
    if (ringValue) ringValue.textContent = 'CHECK';

    if (copy) {
      const primary = copy.querySelector('strong');
      const secondary = copy.querySelector('span');
      const detail = copy.querySelector('small');
      if (primary) primary.textContent = 'MANAGED STORAGE UNAVAILABLE';
      if (secondary) secondary.textContent = 'RETRY METRICS';
      if (detail) detail.textContent = 'Host filesystem capacity remains available only in Advanced Diagnostics.';
    }
    return true;
  }

  function applyStorage(data) {
    const panel = storagePanel();
    if (!panel || !data?.ok) return false;

    const usedPercent = Math.max(0, Math.min(100, Number(data.used_percent || 0)));
    const usedLabel = percentLabel(usedPercent);
    const headValue = panel.querySelector('.ssv2-panel-head b');
    const ring = panel.querySelector('.ssv2-storage-ring');
    const ringValue = ring?.querySelector('strong');
    const copy = panel.querySelector('.ssv2-storage-copy');

    panel.dataset.storageScope = 'brvtal-managed-data';
    panel.title = 'BRVTAL managed application data against the configured hosting quota. Host filesystem capacity remains available only in Advanced Diagnostics.';
    if (headValue) headValue.textContent = `${usedLabel} USED · BRVTAL DATA`;
    if (ring) ring.style.setProperty('--value', String(usedPercent));
    if (ringValue) ringValue.textContent = usedLabel;

    if (copy) {
      const primary = copy.querySelector('strong');
      const secondary = copy.querySelector('span');
      const detail = copy.querySelector('small');
      if (primary) primary.textContent = `${data.used || 'N/A'} / ${data.quota || 'N/A'}`;
      if (secondary) secondary.textContent = `${data.free || 'N/A'} FREE`;
      if (detail) detail.textContent = `${Number(data.managed_files || 0).toLocaleString('en-US')} managed files · configured quota`;
    }
    return true;
  }

  async function refresh(force = false) {
    const root = document.getElementById('system-status-v2');
    if (!root || loading) return;
    if (!force && Date.now() - lastFetchAt < 5000) return;
    loading = true;
    try {
      const suffix = force ? '?refresh=1' : '';
      const response = await fetch(ENDPOINT + suffix, {credentials:'same-origin', cache:'no-store'});
      const data = await response.json().catch(() => ({}));
      lastFetchAt = Date.now();
      if (!response.ok || !data.ok) {
        applyUnavailable();
        return;
      }
      applyStorage(data);
    } catch (_) {
      lastFetchAt = Date.now();
      applyUnavailable();
    } finally {
      loading = false;
    }
  }

  function bindRefresh() {
    const button = document.querySelector('#system-status-v2 #ssv2-refresh');
    if (!button || button.dataset.storageMetricsBound === '1') return;
    button.dataset.storageMetricsBound = '1';
    button.addEventListener('click', () => setTimeout(() => refresh(true), 80));
  }

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(() => {
      if (!document.getElementById('system-status-v2')) return;
      bindRefresh();
      refresh(false);
    }, 80);
  }

  const observer = new MutationObserver(schedule);
  observer.observe(document.documentElement, {childList:true, subtree:true});
  setTimeout(schedule, 120);

  window.BRVTALManagedStorageStatus = {applyStorage, applyUnavailable, refresh};
})();