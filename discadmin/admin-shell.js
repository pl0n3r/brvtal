(function () {
  'use strict';

  function shell() { return document.querySelector('#app .shell'); }
  function toggle(open, restoreFocus) {
    const current = shell();
    if (!current) return;
    const expanded = Boolean(open && matchMedia('(max-width: 850px)').matches);
    current.classList.toggle('admin-nav-open', expanded);
    document.body.classList.toggle('admin-nav-open', expanded);
    const button = current.querySelector('.admin-menu-toggle');
    if (button) button.setAttribute('aria-expanded', String(expanded));
    if (expanded) {
      const target = current.querySelector('.nav .active') || current.querySelector('.nav button');
      if (target) target.focus();
    } else if (restoreFocus && button) button.focus();
  }

  window.toggleAdminNav = function () {
    const current = shell();
    if (current) toggle(!current.classList.contains('admin-nav-open'), false);
  };
  window.closeAdminNav = function () { toggle(false, true); };

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && shell()?.classList.contains('admin-nav-open')) {
      event.preventDefault();
      toggle(false, true);
    }
  });
  document.addEventListener('click', function (event) {
    if (event.target.closest('#app .side .nav button, #app .sidefoot button')) toggle(false, false);
  }, true);
  matchMedia('(max-width: 850px)').addEventListener('change', function () {
    toggle(false, false);
  });
})();
