/* BRVTAL DISCADMIN — Content Core navigation and release footer helper. */
(function () {
  function install() {
    const nav = document.querySelector('.nav');
    if (nav && !nav.querySelector('[data-content-core-nav]')) {
      const buttons = [...nav.querySelectorAll('button')];
      const settings = buttons.find(b => b.textContent.trim().toUpperCase() === 'SETTINGS');
      if (settings) {
        const item = document.createElement('button');
        item.type = 'button';
        item.dataset.contentCoreNav = '1';
        item.textContent = 'CONTENT CORE';
        item.onclick = function () {
          window.location.href = '/discadmin/content-core-entry.php';
        };
        settings.before(item);
      }
    }

    const side = document.querySelector('.side');
    if (!side || side.querySelector('[data-brvtal-release]')) return;
    const foot = side.querySelector('.sidefoot') || side;
    const release = document.createElement('div');
    release.dataset.brvtalRelease = '1';
    release.style.cssText = 'margin-top:12px;color:#555;font:9px/1.5 monospace;letter-spacing:.8px;text-transform:uppercase;';
    release.textContent = 'BRVTAL DISCADMIN · v0.1.0 · BUILD 557d31c · PRODUCTION';
    foot.appendChild(release);
  }
  new MutationObserver(install).observe(document.documentElement, { childList: true, subtree: true });
  install();
})();
