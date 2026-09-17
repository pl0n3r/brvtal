(() => {
  'use strict';

  const menu = document.getElementById('menuToggle');
  const panel = document.getElementById('menuPanel');
  if (!menu || !panel) return;

  let returnFocus = true;
  const icon = menu.querySelector('strong');
  const focusable = () => [...panel.querySelectorAll('a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])')]
    .filter(el => !el.hidden && el.getAttribute('aria-hidden') !== 'true');

  menu.type = 'button';
  menu.dataset.cursor = '';
  menu.setAttribute('aria-controls', panel.id);
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'true');
  panel.setAttribute('aria-label', 'Site navigation');
  panel.setAttribute('tabindex', '-1');

  function isOpen() {
    return panel.getAttribute('aria-hidden') !== 'true';
  }

  function renderState(open) {
    menu.setAttribute('aria-expanded', String(open));
    if (icon) {
      icon.textContent = open ? '×' : '+';
      icon.setAttribute('aria-hidden', 'true');
    }
    panel.style.transform = open ? 'translateY(0)' : 'translateY(-100%)';
    panel.style.visibility = open ? 'visible' : 'hidden';
    panel.style.pointerEvents = open ? 'auto' : 'none';
    panel.inert = !open;
  }

  function setOpen(open, { focus = true } = {}) {
    const changed = isOpen() !== open;
    renderState(open);
    panel.setAttribute('aria-hidden', String(!open));

    if (!changed) return;
    if (open && focus) {
      returnFocus = true;
      requestAnimationFrame(() => (focusable()[0] || panel).focus({ preventScroll: true }));
    } else if (!open && returnFocus && focus) {
      menu.focus({ preventScroll: true });
    }
  }

  // Own the toggle in the capture phase. app.js retains a legacy bubble listener,
  // but this keeps touch/mobile state derived from aria-hidden instead of a private
  // boolean that can drift when other accessibility controls close the dialog.
  menu.addEventListener('click', event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    setOpen(!isOpen());
  }, true);

  panel.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link) return;
    returnFocus = false;
    setOpen(false, { focus: false });
  }, true);

  document.addEventListener('keydown', event => {
    if (!isOpen()) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      returnFocus = true;
      setOpen(false);
      return;
    }
    if (event.key !== 'Tab') return;
    const items = focusable();
    if (!items.length) {
      event.preventDefault();
      panel.focus();
      return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  // Keep semantics honest if another runtime path changes aria-hidden.
  new MutationObserver(() => renderState(isOpen())).observe(panel, {
    attributes: true,
    attributeFilter: ['aria-hidden']
  });

  renderState(isOpen());
})();
