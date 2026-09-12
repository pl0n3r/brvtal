(() => {
  'use strict';

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

  window.BRVTALContentCoreNavigation = {goToStep};
})();
