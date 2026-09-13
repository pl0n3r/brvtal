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
    f_setting_key: 'Setting key',
    blog_title: 'Title',
    release_title: 'Title'
  };
  const statusFieldIds = ['f_status', 'blog_status_field', 'release_status_field'];

  function connectLabels(container, prefix = 'admin-field') {
    container.querySelectorAll('.field label:not([for])').forEach((label, index) => {
      const field = label.closest('.field');
      const control = field?.querySelector('input,textarea,select');
      if (!control) return;
      if (!control.id) control.id = `${prefix}-${index}`;
      label.setAttribute('for', control.id);
    });
  }

  function setRequiredField(container, id, name, required) {
    const control = container.querySelector('#' + id);
    if (!control) return;
    control.required = Boolean(required);
    control.setAttribute('aria-required', String(Boolean(required)));
    if (required) control.dataset.requiredLabel = name;
    else delete control.dataset.requiredLabel;

    const label = container.querySelector(`label[for="${id}"]`);
    if (!label) return;
    let badge = label.querySelector('.admin-required');
    if (required && !badge) {
      badge = document.createElement('span');
      badge.className = 'admin-required';
      badge.textContent = 'REQUIRED';
      label.appendChild(badge);
    } else if (!required && badge) {
      badge.remove();
    }
  }

  function focusableWithin(container) {
    return [...container.querySelectorAll('button:not([disabled]),input:not([disabled]),textarea:not([disabled]),select:not([disabled]),a[href],[tabindex]:not([tabindex="-1"])')]
      .filter(el => el.offsetParent !== null);
  }

  function isOpen() {
    return modal.classList.contains('open');
  }

  function saveButton() {
    return document.getElementById('saveBtn');
  }

  function statusSelect() {
    for (const id of statusFieldIds) {
      const field = document.getElementById(id);
      if (field && modal.contains(field)) return field;
    }
    return null;
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

  function markRequiredFields() {
    Object.entries(requiredByField).forEach(([id, name]) => {
      if (modal.querySelector('#' + id)) setRequiredField(modal, id, name, true);
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
    button.setAttribute('aria-busy', String(active));
    button.setAttribute('aria-disabled', String(active));
    if (active) button.textContent = 'SAVING…';
    else syncSaveLabel();
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

    connectLabels(modal);
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
    if (saving) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    if (!validate()) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    setSaving(true);
    setTimeout(() => { if (isOpen() && saving) setSaving(false); }, 8000);
  }, true);

  let contentCoreModal = null;
  let contentCoreWasOpen = false;
  let contentCoreOpener = null;

  function syncContentCoreState(cc) {
    connectLabels(cc, 'content-core-field');
    const status = cc.querySelector('#e_status');
    const isDraft = !status || String(status.value).toLowerCase() === 'draft';
    setRequiredField(cc, 'e_title', 'Name', true);
    setRequiredField(cc, 'e_event_date', 'Date & time', !isDraft);
    setRequiredField(cc, 'e_city', 'City', !isDraft);

    if (!status) return;
    let hint = cc.querySelector('.admin-content-core-state');
    if (!hint) {
      hint = document.createElement('div');
      hint.className = 'admin-publication-state admin-content-core-state';
      hint.setAttribute('role', 'status');
      status.closest('.field')?.appendChild(hint);
    }
    const state = String(status.value || 'draft').toLowerCase();
    const label = state.replace(/_/g, ' ').toUpperCase();
    const copy = state === 'draft'
      ? 'Draft can be saved with a name only. Date and city may remain incomplete.'
      : 'This non-draft lifecycle state requires name, date and city before saving.';
    hint.dataset.state = state;
    hint.innerHTML = '<strong></strong><span></span>';
    hint.querySelector('strong').textContent = label;
    hint.querySelector('span').textContent = copy;
  }

  function enhanceContentCore(cc, options = {}) {
    const box = cc.querySelector('.modalbox');
    const heading = cc.querySelector('#eventHeading');
    if (box) {
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      box.setAttribute('aria-labelledby', 'eventHeading');
      box.setAttribute('tabindex', '-1');
    }
    if (heading) heading.setAttribute('tabindex', '-1');
    const close = cc.querySelector('.modal-actions .icon');
    if (close) close.setAttribute('aria-label', 'Close event editor');
    const notice = cc.querySelector('#eventNotice');
    if (notice) {
      notice.setAttribute('aria-live', 'assertive');
      notice.setAttribute('aria-atomic', 'true');
    }
    cc.querySelectorAll('button').forEach(button => { if (!button.type) button.type = 'button'; });
    syncContentCoreState(cc);

    if (options.focus !== false) {
      requestAnimationFrame(() => cc.querySelector('#e_title')?.focus({ preventScroll: true }));
    }
  }

  function handleContentCoreModal() {
    const current = document.getElementById('eventModal');
    if (current !== contentCoreModal) {
      if (contentCoreWasOpen && contentCoreOpener?.isConnected) contentCoreOpener.focus({ preventScroll: true });
      contentCoreModal = current;
      contentCoreWasOpen = false;
      contentCoreOpener = null;
    }
    if (!current) return;

    const open = current.classList.contains('open');
    if (open && !contentCoreWasOpen) {
      contentCoreOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      enhanceContentCore(current);
    } else if (!open && contentCoreWasOpen) {
      if (contentCoreOpener?.isConnected) contentCoreOpener.focus({ preventScroll: true });
      contentCoreOpener = null;
    }
    contentCoreWasOpen = open;
  }

  document.addEventListener('change', event => {
    if (event.target?.id === 'e_status' && event.target.closest('#eventModal')) {
      syncContentCoreState(event.target.closest('#eventModal'));
    }
  });

  document.addEventListener('keydown', event => {
    if (isOpen()) {
      if (event.key === 'Escape') {
        event.preventDefault();
        if (typeof window.closeModal === 'function') window.closeModal();
        return;
      }
      if (event.key === 'Tab') {
        const items = focusableWithin(modal);
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
      }
      return;
    }

    const cc = contentCoreModal;
    if (!cc?.classList.contains('open')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      if (window.BRVTALContentCore?.closeEvent) window.BRVTALContentCore.closeEvent();
      else cc.classList.remove('open');
      return;
    }
    if (event.key !== 'Tab') return;
    const items = focusableWithin(cc);
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

  new MutationObserver(handleContentCoreModal).observe(document.body, {
    childList: true,
    subtree: true,
    attributes: true,
    attributeFilter: ['class']
  });
  handleContentCoreModal();

  window.BRVTALAdminFormDialogs = { enhance, validate, syncPublicationHint, enhanceContentCore };
})();
