(() => {
  'use strict';

  const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[contenteditable="true"],[tabindex]:not([tabindex="-1"])';
  const origins = new WeakMap();
  let current = null;
  let queued = false;

  function visible(element) {
    if (!(element instanceof HTMLElement) || !element.isConnected) return false;
    if (element.hidden || element.closest('[hidden],[aria-hidden="true"]')) return false;
    const style = getComputedStyle(element);
    return style.display !== 'none' && style.visibility !== 'hidden';
  }

  function focusable(root) {
    if (!(root instanceof HTMLElement)) return [];
    return [...root.querySelectorAll(FOCUSABLE)].filter(visible);
  }

  function descriptor(container, root, kind, closeSelector = '') {
    return container && root ? { container, root, kind, closeSelector } : null;
  }

  function activeDescriptors() {
    const items = [];
    const bulk = document.querySelector('#brvtal-bulk-actions.open');
    if (bulk) items.push(descriptor(bulk, bulk.querySelector('.brvtal-bulk-dialog'), 'bulk', '.brvtal-bulk-close'));
    const search = document.querySelector('#brvtal-global-search.open');
    if (search) items.push(descriptor(search, search.querySelector('.brvtal-global-search-dialog'), 'search', '.brvtal-global-search-close'));
    const activity = document.querySelector('.activity-modal');
    if (activity) items.push(descriptor(activity, activity.querySelector('.activity-modal-card'), 'activity', '[data-activity-close]'));
    const shell = document.querySelector('#app .shell.admin-nav-open');
    if (shell && matchMedia('(max-width: 850px)').matches) items.push(descriptor(shell, shell.querySelector('.side'), 'sidebar'));
    return items.filter(Boolean);
  }

  function topModal() {
    return activeDescriptors()[0] || null;
  }

  function stillActive(item) {
    return Boolean(item && activeDescriptors().some(entry => entry.container === item.container));
  }

  function openerFor(item) {
    if (item.kind === 'sidebar') return document.querySelector('#app .admin-menu-toggle');
    const active = document.activeElement;
    return active instanceof HTMLElement && !item.root.contains(active) ? active : null;
  }

  function focusInside(item) {
    if (!item || item.root.contains(document.activeElement)) return;
    const target = focusable(item.root)[0] || item.root;
    if (target === item.root && !target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
    requestAnimationFrame(() => {
      const latest = topModal();
      if (latest?.container === item.container && !item.root.contains(document.activeElement)) target.focus();
    });
  }

  function restore(item, next) {
    const origin = item ? origins.get(item.container) : null;
    if (!(origin instanceof HTMLElement) || !origin.isConnected) return;
    if (next && !next.root.contains(origin)) return;
    requestAnimationFrame(() => {
      if (origin.isConnected) origin.focus();
    });
  }

  function sync() {
    queued = false;
    const next = topModal();
    if (current?.container === next?.container) return;

    const previous = current;
    if (next && !origins.has(next.container)) origins.set(next.container, openerFor(next));
    current = next;

    if (previous && previous.container !== next?.container && !stillActive(previous)) {
      restore(previous, next);
      origins.delete(previous.container);
    }
    if (next) focusInside(next);
  }

  function scheduleSync() {
    if (queued) return;
    queued = true;
    queueMicrotask(sync);
  }

  function trapTab(event, item) {
    const items = focusable(item.root);
    if (!items.length) {
      event.preventDefault();
      if (!item.root.hasAttribute('tabindex')) item.root.setAttribute('tabindex', '-1');
      item.root.focus();
      return;
    }

    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;
    if (!item.root.contains(active)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
      return;
    }
    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function closeModal(item) {
    if (!item) return;
    if (item.kind === 'sidebar') {
      window.closeAdminNav?.();
      return;
    }
    const button = item.closeSelector ? item.container.querySelector(item.closeSelector) : null;
    if (button instanceof HTMLElement) button.click();
  }

  document.addEventListener('keydown', event => {
    const shortcut = (event.metaKey || event.ctrlKey) && String(event.key).toLowerCase() === 'k';
    if (shortcut && window.BRVTALGlobalSearch?.open) {
      event.preventDefault();
      event.stopImmediatePropagation();
      const open = document.querySelector('#brvtal-global-search.open');
      open ? window.BRVTALGlobalSearch.close?.() : window.BRVTALGlobalSearch.open();
      scheduleSync();
      return;
    }

    const item = topModal();
    if (!item) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopImmediatePropagation();
      closeModal(item);
      scheduleSync();
      return;
    }
    if (event.key === 'Tab') trapTab(event, item);
  }, true);

  const observer = new MutationObserver(scheduleSync);
  observer.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'aria-hidden'] });
  scheduleSync();

  window.BRVTALAdminModalAccessibility = { sync: scheduleSync };
})();
