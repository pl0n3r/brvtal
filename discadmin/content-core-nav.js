(() => {
  'use strict';

  const style = document.createElement('style');
  style.textContent = `
    [data-admin-module="content-core"] .step[role="button"]{cursor:pointer;transition:background .14s,color .14s}
    [data-admin-module="content-core"] .step[role="button"]:hover{background:#0d0f10;color:#fff}
    [data-admin-module="content-core"] .step[role="button"]:focus-visible{outline:1px solid #6b7278;outline-offset:-2px}
  `;
  document.head.appendChild(style);

  function contentCoreRoot() {
    return document.querySelector('[data-admin-module="content-core"]');
  }

  function showNavigationError(root, message) {
    const notice = root?.querySelector('#eventNotice');
    if (!notice) return;
    notice.textContent = message;
    notice.className = 'notice show err';
    window.setTimeout(() => notice.classList.remove('show'), 5000);
  }

  function goToStep(stepElement) {
    const root = contentCoreRoot();
    if (!root || !stepElement || typeof window.BRVTALContentCore?.step !== 'function') return;

    const target = Number(stepElement.dataset.step || 0);
    const active = root.querySelector('.step.active');
    const current = Number(active?.dataset.step || 1);
    if (!Number.isInteger(target) || target < 1 || target > 5 || target === current) return;

    if (target > 1 && !String(root.querySelector('#e_title')?.value || '').trim()) {
      showNavigationError(root, 'Event name is required before continuing.');
      root.querySelector('#e_title')?.focus();
      return;
    }

    window.BRVTALContentCore.step(target - current);
  }

  document.addEventListener('click', event => {
    const step = event.target instanceof Element
      ? event.target.closest('[data-admin-module="content-core"] .step[data-step]')
      : null;
    if (!step) return;
    event.preventDefault();
    goToStep(step);
  });

  document.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const step = event.target instanceof Element
      ? event.target.closest('[data-admin-module="content-core"] .step[data-step]')
      : null;
    if (!step) return;
    event.preventDefault();
    goToStep(step);
  });

  const sectionForRecord = resource => ({
    event:'events', events:'events', event_lineup:'events', ticket_types:'events',
    artist:'artists', artists:'artists',
    set:'sets', sets:'sets',
    page:'pages', pages:'pages',
    release:'releases', releases:'releases',
    blog:'blog', post:'blog', media:'media',
  })[String(resource || '').trim().toLowerCase()] || 'dashboard';

  const canonicalRecordResource = resource => ({
    event:'events', event_lineup:'events', artist:'artists', set:'sets', page:'pages',
    release:'releases', post:'blog',
  })[String(resource || '').trim().toLowerCase()] || String(resource || '').trim().toLowerCase();

  function reportRecordOpenError(error) {
    const message = error?.message || String(error || 'UNKNOWN_ERROR');
    window.BRVTALFeedback?.error?.('Unable to open record: ' + message, 'admin-record-navigation');
  }

  function waitForRecordElement(selector, timeout = 2500) {
    const existing = document.querySelector(selector);
    if (existing) return Promise.resolve(existing);
    return new Promise(resolve => {
      let settled = false;
      let timer = 0;
      const observer = new MutationObserver(() => {
        const node = document.querySelector(selector);
        if (node) finish(node);
      });
      const finish = value => {
        if (settled) return;
        settled = true;
        window.clearTimeout(timer);
        observer.disconnect();
        resolve(value);
      };
      observer.observe(document.documentElement, {childList:true,subtree:true});
      timer = window.setTimeout(() => finish(null), timeout);
    });
  }

  async function clickRecordEditor(selector, buttonSelector) {
    const row = await waitForRecordElement(selector);
    const button = row?.querySelector(buttonSelector);
    if (!button) return false;
    button.click();
    return true;
  }

  async function revealRecord(resource, id) {
    const key = canonicalRecordResource(resource);
    const recordId = Number(id || 0);
    if (!Number.isInteger(recordId) || recordId <= 0) return false;

    if (key === 'events') {
      if (typeof window.BRVTALContentCore?.openEvent !== 'function') return false;
      await window.BRVTALContentCore.openEvent(recordId);
      return true;
    }

    if (['artists','sets','pages'].includes(key)) {
      if (typeof window.openModal !== 'function') return false;
      await window.openModal(key, recordId);
      return true;
    }

    if (key === 'releases') return clickRecordEditor(`[data-release-id="${recordId}"]`, '[data-edit]');
    if (key === 'blog') return clickRecordEditor(`[data-blog-id="${recordId}"]`, '[data-edit]');

    if (key === 'media') {
      const card = await waitForRecordElement(`[data-media-id="${recordId}"]`);
      if (!card) return false;
      card.click();
      return true;
    }

    return false;
  }

  async function openRecord(resource, id, options = {}) {
    const destination = String(options.section || sectionForRecord(resource));
    if (typeof window.go === 'function') await window.go(destination);
    return revealRecord(resource, id);
  }

  function activityRecordTarget(button) {
    const history = button.closest('.activity-row')?.querySelector('[data-activity-history]');
    const id = Number(history?.dataset.activityHistory || 0);
    if (!history || !id) return null;
    return {
      resource: history.dataset.activityResource || button.dataset.activityOpen || '',
      section: button.dataset.activityOpen || sectionForRecord(history.dataset.activityResource),
      id,
    };
  }

  function healthRecordTarget(button) {
    const id = Number(button.dataset.healthId || 0);
    if (!id) return null;
    return {
      resource: button.dataset.healthType || button.dataset.healthOpen || '',
      section: button.dataset.healthOpen || sectionForRecord(button.dataset.healthType),
      id,
    };
  }

  document.addEventListener('click', event => {
    const button = event.target instanceof Element
      ? event.target.closest('[data-health-open],[data-activity-open]')
      : null;
    if (!button) return;
    const target = button.hasAttribute('data-health-open')
      ? healthRecordTarget(button)
      : activityRecordTarget(button);
    if (!target) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openRecord(target.resource, target.id, {section:target.section}).catch(reportRecordOpenError);
  }, true);

  window.addEventListener('brvtal:global-search-open', event => {
    const target = event.detail || {};
    revealRecord(target.module || target.type, target.id).catch(reportRecordOpenError);
  });

  window.BRVTALContentCoreNavigation = {goToStep};
  window.BRVTALAdminRecordNavigation = {open:openRecord,reveal:revealRecord,sectionFor:sectionForRecord};
  window.BRVTALOpenAdminRecord = openRecord;
})();
