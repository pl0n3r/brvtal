(() => {
  'use strict';

  let originalSetForm = null;
  let originalSave = null;

  const byId = id => document.getElementById(id);

  function published() {
    return String(byId('f_status')?.value || 'draft') === 'published';
  }

  function listeningUrl() {
    return String(byId('f_external_url')?.value || '').trim();
  }

  function validHttpUrl(value) {
    if (!value) return false;
    try {
      const url = new URL(value);
      return url.protocol === 'http:' || url.protocol === 'https:';
    } catch (_) {
      return false;
    }
  }

  function syncFieldContract() {
    const input = byId('f_external_url');
    const status = byId('f_status');
    if (!input || !status) return;

    const required = published();
    input.required = required;
    input.setAttribute('aria-required', required ? 'true' : 'false');
    input.placeholder = required ? 'https://soundcloud.com/... — required to publish' : 'https://soundcloud.com/...';

    const label = document.querySelector('label[for="f_external_url"]');
    if (label) label.textContent = required ? 'Listening URL *' : 'Listening URL';
  }

  function validateForPublish({ focus = true } = {}) {
    if (!published()) return true;
    const input = byId('f_external_url');
    const url = listeningUrl();
    if (validHttpUrl(url)) return true;

    if (typeof window.show === 'function') {
      window.show(url ? 'Listening URL must be a valid http(s) URL before publishing.' : 'Listening URL is required before publishing this Set.');
    } else if (window.BRVTALFeedback?.error) {
      window.BRVTALFeedback.error(url ? 'Listening URL must be a valid http(s) URL before publishing.' : 'Listening URL is required before publishing this Set.', 'set-publication');
    }
    input?.setAttribute('aria-invalid', 'true');
    if (focus) input?.focus();
    return false;
  }

  function bind() {
    const input = byId('f_external_url');
    const status = byId('f_status');
    if (!input || !status) return;
    const sync = () => {
      input.removeAttribute('aria-invalid');
      syncFieldContract();
    };
    status.addEventListener('change', sync);
    input.addEventListener('input', () => input.removeAttribute('aria-invalid'));
    syncFieldContract();
  }

  function install() {
    if (typeof window.setForm === 'function' && !window.setForm.__setPublicationContract) {
      originalSetForm = window.setForm;
      const wrappedSetForm = function(record) {
        const result = originalSetForm.apply(this, arguments);
        bind();
        return result;
      };
      wrappedSetForm.__setPublicationContract = true;
      window.setForm = wrappedSetForm;
    }

    if (typeof window.save === 'function' && !window.save.__setPublicationContract) {
      originalSave = window.save;
      const wrappedSave = async function(type, id, ...args) {
        if (type === 'sets' && !validateForPublish()) return false;
        return originalSave.apply(this, [type, id, ...args]);
      };
      wrappedSave.__setPublicationContract = true;
      window.save = wrappedSave;
    }
  }

  window.BRVTALSetPublicationContract = {
    install,
    validate:validateForPublish,
    sync:syncFieldContract,
  };

  install();
})();
