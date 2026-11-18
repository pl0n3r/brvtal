(() => {
  'use strict';

  function syncHeroSlides(root = document) {
    root.querySelectorAll?.('[data-hero-slide]').forEach(slide => {
      const hidden = slide.getAttribute('aria-hidden') === 'true';
      if (hidden) slide.setAttribute('inert', '');
      else slide.removeAttribute('inert');
    });
  }

  function labelSetActions(root = document) {
    root.querySelectorAll?.('.set-action[href]').forEach(link => {
      if (link.hasAttribute('aria-label')) return;
      const item = link.closest('.set-item');
      const title = item?.querySelector('.set-main h4')?.textContent?.trim() || 'set';
      const platform = item?.querySelector('.set-main > span')?.textContent?.trim()
        || link.dataset.cursor?.trim()
        || 'external platform';
      link.setAttribute('aria-label', `Listen to ${title} on ${platform}`);
    });
  }

  function disablePlaceholderFallbackActions(root = document) {
    root.querySelectorAll?.('.artist[href="#"]').forEach(link => {
      link.removeAttribute('href');
      link.setAttribute('aria-disabled', 'true');
    });

    root.querySelectorAll?.('.set-action[href="https://soundcloud.com/"], .set-action[href="https://soundcloud.com"]').forEach(link => {
      link.removeAttribute('href');
      link.removeAttribute('target');
      link.removeAttribute('rel');
      link.setAttribute('aria-disabled', 'true');
      link.setAttribute('aria-hidden', 'true');
      link.setAttribute('tabindex', '-1');
    });
  }

  function syncPublicInteractions(root = document) {
    syncHeroSlides(root);
    labelSetActions(root);
    disablePlaceholderFallbackActions(root);
  }

  syncPublicInteractions();

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => syncPublicInteractions(), {once:true});
  }

  if (typeof MutationObserver === 'function') {
    const observer = new MutationObserver(() => syncPublicInteractions());
    observer.observe(document.documentElement, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['aria-hidden']
    });
  }

  window.BRVTALPublicQuickWins = {
    syncHeroSlides,
    labelSetActions,
    disablePlaceholderFallbackActions,
    syncPublicInteractions
  };
})();
