(() => {
  'use strict';

  const FIELD_SELECTOR = '[data-admin-color-field]';
  const HEX_PATTERN = /^#[0-9a-f]{6}$/i;

  function normalize(value) {
    let candidate = String(value ?? '').trim();
    if (/^[0-9a-f]{6}$/i.test(candidate)) candidate = '#' + candidate;
    return HEX_PATTERN.test(candidate) ? candidate.toLowerCase() : null;
  }

  function resolveField(target) {
    if (!target) return null;
    if (target.matches?.(FIELD_SELECTOR)) return target;
    return target.closest?.(FIELD_SELECTOR) || null;
  }

  function render(field, { normalizeText = false } = {}) {
    const text = field.querySelector('[data-color-hex]');
    const picker = field.querySelector('[data-color-picker]');
    const swatch = field.querySelector('[data-color-swatch]');
    const output = field.querySelector('[data-color-value]');
    const error = field.querySelector('[data-color-error]');
    if (!text || !picker) return null;

    const raw = text.value.trim();
    const normalized = raw === '' ? null : normalize(raw);
    const invalid = raw !== '' && normalized === null;
    const fallback = normalize(field.dataset.colorDefault) || '#ff2038';
    const display = normalized || fallback;

    if (normalizeText && normalized) text.value = normalized;
    text.setAttribute('aria-invalid', invalid ? 'true' : 'false');
    field.classList.toggle('is-invalid', invalid);
    picker.value = display;
    if (swatch) swatch.style.backgroundColor = display;
    if (output) output.textContent = normalized ? normalized.toUpperCase() : 'No accent';
    if (error) error.hidden = !invalid;

    return normalized;
  }

  function bind(field) {
    if (!field || field.dataset.colorBound === '1') return;
    const text = field.querySelector('[data-color-hex]');
    const picker = field.querySelector('[data-color-picker]');
    if (!text || !picker) return;

    field.dataset.colorBound = '1';
    text.addEventListener('input', () => render(field));
    text.addEventListener('blur', () => render(field, { normalizeText: true }));
    picker.addEventListener('input', () => {
      text.value = picker.value.toLowerCase();
      render(field, { normalizeText: true });
      text.dispatchEvent(new Event('change', { bubbles: true }));
    });
    render(field, { normalizeText: true });
  }

  function hydrate(root = document) {
    if (root.matches?.(FIELD_SELECTOR)) bind(root);
    root.querySelectorAll?.(FIELD_SELECTOR).forEach(bind);
  }

  function sync(target) {
    const field = resolveField(target);
    if (!field) return null;
    bind(field);
    return render(field, { normalizeText: true });
  }

  window.BRVTALAdminColorField = { normalize, hydrate, sync };

  const start = () => {
    hydrate(document);
    new MutationObserver(records => {
      for (const record of records) {
        for (const node of record.addedNodes) {
          if (node.nodeType === Node.ELEMENT_NODE) hydrate(node);
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
