(() => {
  'use strict';

  const menu = document.getElementById('menuToggle');
  const panel = document.getElementById('menuPanel');
  if (!menu || !panel) return;

  let returnFocus = true;
  const focusable = () => [...panel.querySelectorAll('a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])')]
    .filter(el => !el.hidden && el.getAttribute('aria-hidden') !== 'true');

  menu.setAttribute('aria-controls', panel.id);
  menu.setAttribute('aria-expanded', String(panel.getAttribute('aria-hidden') !== 'true'));
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'true');
  panel.setAttribute('aria-label', 'Site navigation');
  panel.setAttribute('tabindex', '-1');

  function sync() {
    const open = panel.getAttribute('aria-hidden') !== 'true';
    menu.setAttribute('aria-expanded', String(open));
    if (open) {
      returnFocus = true;
      requestAnimationFrame(() => (focusable()[0] || panel).focus({ preventScroll: true }));
    } else if (returnFocus) {
      menu.focus({ preventScroll: true });
    }
  }

  panel.addEventListener('click', event => {
    if (event.target.closest('a[href]')) returnFocus = false;
  }, true);

  document.addEventListener('keydown', event => {
    if (panel.getAttribute('aria-hidden') === 'true') return;
    if (event.key === 'Escape') {
      event.preventDefault();
      returnFocus = true;
      menu.click();
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

  new MutationObserver(sync).observe(panel, { attributes: true, attributeFilter: ['aria-hidden'] });
  sync();
})();
