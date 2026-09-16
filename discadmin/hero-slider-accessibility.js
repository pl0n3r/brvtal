(() => {
  'use strict';

  if (window.__BRVTAL_HERO_SLIDER_ACCESSIBILITY__) return;
  window.__BRVTAL_HERO_SLIDER_ACCESSIBILITY__ = true;

  const selector = '.hero-slide-row[data-select-slide]';

  function enhance(root = document) {
    root.querySelectorAll?.(selector).forEach(row => {
      row.setAttribute('aria-keyshortcuts', 'Alt+ArrowUp Alt+ArrowDown');
      if (!row.hasAttribute('title')) {
        row.setAttribute('title', 'Alt+↑ / Alt+↓ to move this slide');
      }
    });
  }

  function moveFromKeyboard(event) {
    if (!event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) return;
    const row = event.target?.closest?.(selector);
    if (!row) return;

    const direction = event.key === 'ArrowUp' ? 'up' : 'down';
    const control = row.querySelector(`[data-move="${direction}"]`);
    if (!control) return;

    event.preventDefault();
    event.stopPropagation();
    control.click();
  }

  document.addEventListener('keydown', moveFromKeyboard, true);
  const observer = new MutationObserver(records => {
    records.forEach(record => record.addedNodes.forEach(node => {
      if (!(node instanceof Element)) return;
      if (node.matches(selector)) enhance(node.parentElement || document);
      else if (node.querySelector?.(selector)) enhance(node);
    }));
  });
  observer.observe(document.documentElement, {childList:true, subtree:true});
  enhance();

  window.BRVTALHeroSliderAccessibility = {enhance, moveFromKeyboard};
})();