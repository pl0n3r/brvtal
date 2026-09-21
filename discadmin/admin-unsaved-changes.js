(() => {
  'use strict';

  const states = new WeakMap();
  const activeRoots = new Set();
  const ROOT_SELECTOR = '#modal,#eventModal';
  const EDITABLE_SELECTOR = 'input:not([type="button"]):not([type="submit"]):not([type="reset"]),textarea,select,[contenteditable="true"]';
  const STRUCTURAL_BUTTON_SELECTOR = '#tickets button,#lineupCurrent button,#available button,[onclick*="addTicket"],[onclick*="addLine"],[onclick*="moveLine"],[onclick*=".remove()"]';
  const DISCARD_MESSAGE = 'You have unsaved changes. Discard them?';
  let pendingNavigation = null;
  let navigationOperation = 0;

  function stateFor(root) {
    let state = states.get(root);
    if (!state) {
      state = {active:false,baseline:'',touched:false,observer:null,baselineQueued:false};
      states.set(root,state);
    }
    return state;
  }

  function rowIdentity(control) {
    const row = control.closest('[data-id],[data-artist-id],[data-artist],.ticket-row,.lineitem');
    if (!row) return '';
    return [
      row.dataset.id || '',
      row.dataset.artistId || '',
      row.dataset.artist || '',
      row.classList.contains('ticket-row') ? 'ticket-row' : '',
      row.classList.contains('lineitem') ? 'lineitem' : ''
    ].join(':');
  }

  function controlValue(control) {
    if (control instanceof HTMLInputElement) {
      if (control.type === 'checkbox' || control.type === 'radio') return control.checked ? '1' : '0';
      if (control.type === 'file') {
        return [...(control.files || [])].map(file => file.name + ':' + file.size + ':' + file.lastModified).join('|');
      }
      return control.value;
    }
    if (control instanceof HTMLSelectElement && control.multiple) {
      return [...control.selectedOptions].map(option => option.value).join('|');
    }
    if ('value' in control) return String(control.value ?? '');
    return String(control.textContent ?? '');
  }

  function serialize(root) {
    if (!(root instanceof Element)) return '';
    const controls = [...root.querySelectorAll(EDITABLE_SELECTOR)].map((control,index) => ({
      key: [
        rowIdentity(control),
        control.id || '',
        control.getAttribute('name') || '',
        control.dataset.k || '',
        control.dataset.field || '',
        control.getAttribute('type') || control.tagName.toLowerCase(),
        index
      ].join('|'),
      value: controlValue(control)
    }));
    const structure = [...root.querySelectorAll('[data-id],[data-artist-id],[data-artist],.ticket-row,.lineitem')].map((node,index) => [
      node.dataset.id || '',
      node.dataset.artistId || '',
      node.dataset.artist || '',
      node.classList.contains('ticket-row') ? 'ticket-row' : '',
      node.classList.contains('lineitem') ? 'lineitem' : '',
      index
    ].join('|'));
    return JSON.stringify({controls,structure});
  }

  function syncFlag(root) {
    if (!(root instanceof Element)) return;
    if (isDirty(root)) root.dataset.unsavedChanges = 'dirty';
    else delete root.dataset.unsavedChanges;
  }

  function begin(root) {
    if (!(root instanceof Element)) return false;
    bind(root);
    const state = stateFor(root);
    state.active = true;
    state.touched = false;
    state.baseline = serialize(root);
    activeRoots.add(root);
    syncFlag(root);
    return true;
  }

  function queuePristineBaseline(root) {
    const state = stateFor(root);
    if (!state.active || state.touched || state.baselineQueued) return;
    state.baselineQueued = true;
    queueMicrotask(() => {
      state.baselineQueued = false;
      if (!state.active || state.touched || !root.classList.contains('open')) return;
      state.baseline = serialize(root);
      syncFlag(root);
    });
  }

  function touch(root) {
    const state = stateFor(root);
    if (!state.active) return;
    state.touched = true;
    syncFlag(root);
  }

  function markClean(root) {
    if (!(root instanceof Element)) return false;
    const state = stateFor(root);
    state.baseline = serialize(root);
    state.touched = false;
    syncFlag(root);
    return true;
  }

  function isDirty(root) {
    if (!(root instanceof Element)) return false;
    const state = stateFor(root);
    return Boolean(state.active && state.baseline !== serialize(root));
  }

  function end(root) {
    if (!(root instanceof Element)) return;
    const state = stateFor(root);
    state.active = false;
    state.touched = false;
    state.baseline = '';
    activeRoots.delete(root);
    delete root.dataset.unsavedChanges;
  }

  function requestClose(root, close, options = {}) {
    if (!(root instanceof Element) || typeof close !== 'function') return false;
    if (options.force === true) {
      markClean(root);
      close();
      end(root);
      return true;
    }
    if (isDirty(root) && !window.confirm(DISCARD_MESSAGE)) {
      syncFlag(root);
      return false;
    }
    markClean(root);
    close();
    end(root);
    return true;
  }

  function navigationRoots() {
    return [...activeRoots].filter(root =>
      root.isConnected && root.classList.contains('open')
    );
  }

  function releaseNavigationLocks(token) {
    const pending = pendingNavigation;
    if (!pending || pending.token !== token) return [];
    pendingNavigation = null;
    pending.entries.forEach(entry => {
      if (!(entry.root instanceof Element)) return;
      entry.root.inert = entry.wasInert;
    });
    return pending.entries;
  }

  function cancelNavigation(token) {
    const pending = releaseNavigationLocks(token);
    pending.forEach(entry => syncFlag(entry.root));
    return pending.length > 0;
  }

  function requestNavigation() {
    if (pendingNavigation) releaseNavigationLocks(pendingNavigation.token);
    const roots = navigationRoots();
    const dirty = roots.some(root => isDirty(root));
    if (dirty && !window.confirm(DISCARD_MESSAGE)) {
      roots.forEach(syncFlag);
      return 0;
    }

    const token = ++navigationOperation;
    const entries = dirty ? roots.map(root => {
      const entry = {root,snapshot:serialize(root),wasInert:Boolean(root.inert)};
      root.inert = true;
      return entry;
    }) : [];
    pendingNavigation = {token,entries};
    return token;
  }

  function commitNavigation(token) {
    if (!pendingNavigation) return true;
    if (pendingNavigation.token !== token) return null;

    const pending = releaseNavigationLocks(token);
    let stable = true;
    pending.forEach(entry => {
      const root = entry.root;
      if (!(root instanceof Element) || !stateFor(root).active) return;
      if (serialize(root) !== entry.snapshot) {
        stable = false;
        syncFlag(root);
        return;
      }
      markClean(root);
      end(root);
    });
    return stable;
  }

  function hasDirtyChanges() {
    return navigationRoots().some(root => isDirty(root));
  }

  function bind(root) {
    if (!(root instanceof Element) || root.dataset.unsavedChangesBound === '1') return;
    root.dataset.unsavedChangesBound = '1';
    stateFor(root);

    root.addEventListener('input', () => touch(root), true);
    root.addEventListener('change', () => touch(root), true);
    root.addEventListener('click', event => {
      if (event.target?.closest?.(STRUCTURAL_BUTTON_SELECTOR)) touch(root);
    }, true);

    const observer = new MutationObserver(records => {
      const state = stateFor(root);
      if (!state.active) return;
      const structuralMutation = records.some(record => record.type === 'childList');
      if (structuralMutation && !state.touched) queuePristineBaseline(root);
      else syncFlag(root);
    });
    observer.observe(root,{childList:true,subtree:true});
    stateFor(root).observer = observer;
  }

  function syncRoots() {
    document.querySelectorAll(ROOT_SELECTOR).forEach(root => {
      bind(root);
      const state = stateFor(root);
      const open = root.classList.contains('open');
      if (open && !state.active) {
        begin(root);
        requestAnimationFrame(() => queuePristineBaseline(root));
      } else if (!open && state.active) {
        end(root);
      }
    });
  }

  new MutationObserver(syncRoots).observe(document.documentElement,{
    childList:true,
    subtree:true,
    attributes:true,
    attributeFilter:['class']
  });

  window.addEventListener('beforeunload', event => {
    if (!navigationRoots().some(root => isDirty(root))) return;
    event.preventDefault();
  });

  window.BRVTALUnsavedChanges = {
    begin,
    touch,
    markClean,
    isDirty,
    hasDirtyChanges,
    requestClose,
    requestNavigation,
    commitNavigation,
    cancelNavigation,
    end,
    syncRoots,
    serialize,
    message:DISCARD_MESSAGE
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',syncRoots,{once:true});
  else syncRoots();
})();
