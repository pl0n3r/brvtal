/* BRVTAL DISCADMIN — Content Core navigation helper. */
(function () {
  function install() {
    const nav = document.querySelector('.nav');
    if (!nav || nav.querySelector('[data-content-core-nav]')) return;
    const buttons = [...nav.querySelectorAll('button')];
    const settings = buttons.find(b => b.textContent.trim().toUpperCase() === 'SETTINGS');
    if (!settings) return;
    const item = document.createElement('button');
    item.type = 'button';
    item.dataset.contentCoreNav = '1';
    item.textContent = 'CONTENT CORE';
    item.onclick = function () {
      window.location.href = '/discadmin/content-core-entry.php';
    };
    settings.before(item);
  }
  new MutationObserver(install).observe(document.documentElement, { childList: true, subtree: true });
  install();
})();
