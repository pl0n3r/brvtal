(function () {
  'use strict';

  const modal = document.getElementById('modal');
  if (!modal) return;

  let opener = null;
  let wasOpen = false;
  let saving = false;

  const requiredByField = {
    f_title: 'Title',
    f_name: 'Name',
    f_setting_key: 'Setting key'
  };

  function isOpen() {
    return modal.classList.contains('open');
  }

  function saveButton() {
    return document.getElementById('saveBtn');
  }

  function statusSelect() {
    return document.getElementById('f_status');
  }

  function publicationCopy(value) {
    const state = String(value || '').toLowerCase();
    if (state === 'published') return ['PUBLISHED', 'Visible state. Saving will publish the current record.'];
    if (state === 'archived') return ['ARCHIVED', 'Historical state. The record stays available to administrators and archive workflows.'];
    if (state === 'draft') return ['DRAFT', 'Private working state. Save changes without publishing the record.'];
    return ['', ''];
  }

  function syncSaveLabel() {
    const button = saveButton();
    if (!button || saving) return;
    const value = String(statusSelect()?.value || '').toLowerCase();
    const next = value === 'published'
      ? 'SAVE & PUBLISH'
      : value === 'draft'
        ? 'SAVE DRAFT'
        : value === 'archived'
          ? 'SAVE ARCHIVED'
          : 'SAVE CHANGES';
    if (button.textContent !== next) button.textContent = next;
  }

  function syncPublicationHint() {
    const status = statusSelect();
    const existing = modal.querySelector('.admin-publication-state');
    if (!status) {
      existing?.remove();
      syncSaveLabel();
      return;
    }

    let hint = existing;
    if (!hint) {
      hint = document.createElement('div');
      hint.className = 'admin-publication-state';
      hint.setAttribute('role', 'status');
      status.closest('.field')?.appendChild(hint);
    }
    const state = String(status.value || '').toLowerCase();
    const [label, copy] = publicationCopy(state);
    if (hint.dataset.state !== state) {
      hint.dataset.state = state;
      hint.innerHTML = '<strong></strong><span></span>';
    }
    const strong = hint.querySelector('strong');
    const span = hint.querySelector('span');
    if (strong && strong.textContent !== label) strong.textContent = label;
    if (span && span.textContent !== copy) span.textContent = copy;
    syncSaveLabel();
  }

  function connectLooseLabels() {
    modal.querySelectorAll('.field label:not([for])').forEach((label, index) => {
      const field = label.closest('.field');
      const control = field?.querySelector('input,textarea,select');
      if (!control) return;
      if (!control.id) control.id = 'admin-field-' + index;
      label.setAttribute('for', control.id);
    });
  }

  function markRequiredFields() {
    Object.entries(requiredByField).forEach(([id, name]) => {
      const input = document.getElementById(id);
      if (!input || !modal.contains(input)) return;
      input.required = true;
      input.setAttribute('aria-required', 'true');
      input.dataset.requiredLabel = name;
      const label = modal.querySelector(`label[for="${id}"]`);
      if (label && !label.querySelector('.admin-required')) {
        const badge = document.createElement('span');
        badge.className = 'admin-required';
        badge.textContent = 'REQUIRED';
        label.appendChild(badge);
      }
    });
  }

  function clearValidation() {
    modal.querySelectorAll('[aria-invalid="true"]').forEach(el => el.removeAttribute('aria-invalid'));
    const notice = document.getElementById('notice');
    if (notice?.dataset.adminValidation === '1') {
      notice.textContent = '';
      notice.className = 'notice';
      notice.removeAttribute('role');
      delete notice.dataset.adminValidation;
    }
  }

  function invalidControl() {
    const controls = [...modal.querySelectorAll('input,textarea,select')];
    return controls.find(control => {
      if (control.disabled || control.type === 'hidden') return false;
      if (control.required && !String(control.value || '').trim()) return true;
      return typeof control.checkValidity === 'function' && !control.checkValidity();
    }) || null;
  }

  function validate() {
    clearValidation();
    const invalid = invalidControl();
    if (!invalid) return true;

    invalid.setAttribute('aria-invalid', 'true');
    const notice = document.getElementById('notice');
    const label = invalid.dataset.requiredLabel
      || modal.querySelector(`label[for="${invalid.id}"]`)?.childNodes?.[0]?.textContent?.trim()
      || 'Required field';
    if (notice) {
      notice.textContent = `Review the form before saving · ${label} is required.`;
      notice.className = 'notice show error admin-validation-summary';
      notice.setAttribute('role', 'alert');
      notice.dataset.adminValidation = '1';
    }
    invalid.focus({ preventScroll: true });
    invalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
    return false;
  }

  function setSaving(active) {
    const button = saveButton();
    saving = active;
    if (!button) return;
    button.disabled = active;
    button.setAttribute('aria-busy', String(active));
    if (active) button.textContent = 'SAVING…';
    else syncSaveLabel();
  }

  function focusable() {
    return [...modal.querySelectorAll('button:not([disabled]),input:not([disabled]),textarea:not([disabled]),select:not([disabled]),a[href],[tabindex]:not([tabindex="-1"])')]
      .filter(el => el.offsetParent !== null);
  }

  function enhance(options = {}) {
    if (!isOpen()) return;
    const box = modal.querySelector('.modalbox');
    const title = document.getElementById('mtitle');
    if (box) {
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-labelledby', 'mtitle');
      box.setAttribute('tabindex', '-1');
    }
    if (title) title.setAttribute('tabindex', '-1');

    const close = modal.querySelector('.modalhead .iconbtn');
    if (close) {
      close.type = 'button';
      close.setAttribute('aria-label', 'Close dialog');
    }
    modal.querySelectorAll('.modalfoot button').forEach(button => { button.type = 'button'; });

    connectLooseLabels();
    markRequiredFields();
    syncPublicationHint();

    if (options.focus !== false) {
      requestAnimationFrame(() => {
        const target = invalidControl() || modal.querySelector('input,textarea,select,button');
        target?.focus({ preventScroll: true });
      });
    }
  }

  function handleOpenState() {
    const open = isOpen();
    if (open && !wasOpen) {
      opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      setSaving(false);
      clearValidation();
      enhance();
    } else if (!open && wasOpen) {
      setSaving(false);
      clearValidation();
      if (opener?.isConnected) opener.focus({ preventScroll: true });
      opener = null;
    }
    wasOpen = open;
  }

  modal.addEventListener('change', event => {
    if (event.target === statusSelect()) syncPublicationHint();
    if (event.target?.matches?.('[aria-invalid="true"]') && event.target.checkValidity()) {
      event.target.removeAttribute('aria-invalid');
    }
  });
  modal.addEventListener('input', event => {
    if (event.target?.matches?.('[aria-invalid="true"]') && String(event.target.value || '').trim()) {
      event.target.removeAttribute('aria-invalid');
      if (!invalidControl()) clearValidation();
    }
  });

  document.addEventListener('click', event => {
    const button = event.target.closest?.('#saveBtn');
    if (!button || !isOpen()) return;
    if (!validate()) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    setSaving(true);
    setTimeout(() => { if (isOpen() && saving) setSaving(false); }, 8000);
  }, true);

  document.addEventListener('keydown', event => {
    if (!isOpen()) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      if (typeof window.closeModal === 'function') window.closeModal();
      return;
    }
    if (event.key !== 'Tab') return;
    const items = focusable();
    if (!items.length) return;
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

  new MutationObserver(handleOpenState).observe(modal, { attributes: true, attributeFilter: ['class'] });
  const content = document.getElementById('mcontent');
  if (content) new MutationObserver(() => { if (isOpen()) enhance({ focus: false }); }).observe(content, { childList: true });
  const notice = document.getElementById('notice');
  if (notice) new MutationObserver(() => {
    if (isOpen() && notice.classList.contains('error') && saving) setSaving(false);
  }).observe(notice, { attributes: true, attributeFilter: ['class'] });

  window.BRVTALAdminFormDialogs = { enhance, validate, syncPublicationHint };
})();
