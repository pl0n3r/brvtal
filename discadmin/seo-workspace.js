window.BRVTALSEOWorkspace = (() => {
  'use strict';

  const endpoint = '/api/seo-workspace.php';
  const state = {
    root:null,
    items:[],
    summary:{total:0,auto:0,manual:0,issues:0},
    editing:null,
    dirty:false,
    lastFocus:null,
    loading:false,
    keyboardBound:false,
  };


  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[ch]));
  }

  function node(id) {
    return state.root?.querySelector('#' + id) || null;
  }

  async function csrfToken() {
    try {
      if (window.csrf) return String(window.csrf);
    } catch (_) {}
    const response = await fetch('/api/index.php/auth',{credentials:'same-origin',cache:'no-store'});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.authenticated || !payload.csrf) throw new Error('AUTH_REQUIRED');
    window.csrf = payload.csrf;
    return String(payload.csrf);
  }

  function setStatus(message = '', error = false) {
    const output = node('seo-workspace-status');
    if (!output) return;
    output.textContent = message;
    output.classList.toggle('error', Boolean(error));
  }

  function healthLabel(item) {
    return Array.isArray(item.warnings) && item.warnings.length ? 'NEEDS ATTENTION' : 'OK';
  }

  function filters() {
    return {
      query:String(node('seo-workspace-search')?.value || '').trim().toLowerCase(),
      type:String(node('seo-workspace-type')?.value || ''),
      status:String(node('seo-workspace-status-filter')?.value || ''),
      mode:String(node('seo-workspace-mode')?.value || ''),
      health:String(node('seo-workspace-health')?.value || ''),
    };
  }

  function visibleItems() {
    const f = filters();
    return state.items.filter(item => {
      const haystack = [item.label,item.path,item.canonical,item.type,item.effective_title]
        .join(' ').toLowerCase();
      if (f.query && !haystack.includes(f.query)) return false;
      if (f.type && item.type !== f.type) return false;
      if (f.mode && item.mode !== f.mode) return false;
      if (f.status === 'public' && !item.public) return false;
      if (f.status === 'private' && item.public) return false;
      const hasWarnings = Array.isArray(item.warnings) && item.warnings.length > 0;
      if (f.health === 'issues' && !hasWarnings) return false;
      if (f.health === 'ok' && hasWarnings) return false;
      return true;
    });
  }

  function renderSummary() {
    const values = {
      'seo-workspace-total':state.summary.total,
      'seo-workspace-auto':state.summary.auto,
      'seo-workspace-manual':state.summary.manual,
      'seo-workspace-issues':state.summary.issues,
    };
    Object.entries(values).forEach(([id,value]) => {
      const target = node(id);
      if (target) target.textContent = String(value ?? 0);
    });
  }

  function renderInventory() {
    const host = node('seo-workspace-inventory');
    if (!host) return;
    const items = visibleItems();
    if (!items.length) {
      host.innerHTML = '<div class="seo-workspace-empty">NO SEO DESTINATIONS MATCH THESE FILTERS.</div>';
      return;
    }

    host.innerHTML = items.map(item => {
      const warnings = Array.isArray(item.warnings) ? item.warnings : [];
      const publicState = item.public ? 'PUBLIC' : String(item.status || 'PRIVATE').toUpperCase();
      return `<article class="seo-workspace-row" data-seo-key="${esc(item.key)}">
        <div class="seo-workspace-row-main">
          <strong>${esc(item.label || item.effective_title || item.path)}</strong>
          <code>${esc(item.path)}</code>
        </div>
        <div class="seo-workspace-effective" title="${esc(item.effective_title)}">${esc(item.effective_title)}</div>
        <span class="seo-workspace-pill">${esc(item.type)}</span>
        <span class="seo-workspace-pill ${String(item.mode || '').toLowerCase()}">${esc(item.mode)}</span>
        <span class="seo-workspace-health ${warnings.length ? 'issue' : ''}">${esc(warnings.length ? healthLabel(item) + ' · ' + warnings.length : publicState)}</span>
        <button type="button" class="seo-workspace-edit" data-seo-open="${esc(item.key)}">EDIT</button>
      </article>`;
    }).join('');
  }

  function rerender() {
    renderSummary();
    renderInventory();
  }

  async function load() {
    if (state.loading) return;
    state.loading = true;
    setStatus('LOADING SEO INVENTORY…');
    try {
      const response = await fetch(endpoint,{credentials:'same-origin',cache:'no-store'});
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) throw new Error(payload.error || ('HTTP_' + response.status));
      state.items = Array.isArray(payload.data) ? payload.data : [];
      state.summary = payload.summary || {
        total:state.items.length,auto:0,manual:0,issues:0
      };
      rerender();
      setStatus(`${state.items.length} DESTINATIONS · SERVER-RENDERED AUTHORITY`);
    } catch (error) {
      setStatus('SEO inventory could not be loaded: ' + (error?.message || error), true);
      window.BRVTALFeedback?.error?.('SEO inventory failed: ' + (error?.message || error),'seo-workspace');
    } finally {
      state.loading = false;
    }
  }

  function currentItem(key) {
    return state.items.find(item => String(item.key) === String(key)) || null;
  }

  function editor() {
    return node('seo-workspace-editor');
  }

  function editorValues() {
    return {
      title:String(node('seo-editor-title-input')?.value || '').trim(),
      description:String(node('seo-editor-description-input')?.value || '').trim(),
      image:String(node('seo-editor-image-input')?.value || '').trim(),
    };
  }

  function renderedTitle(item, manualTitle) {
    if (!manualTitle) return String(item.automatic_title || '');
    if (item.kind === 'entity' && !manualTitle.toUpperCase().includes('BRVTAL')) {
      return manualTitle + ' — BRVTAL';
    }
    return manualTitle;
  }

  function formMode(item, values) {
    const fields = item.kind === 'static'
      ? [values.title,values.description,values.image]
      : [values.title,values.description];
    const manual = fields.filter(Boolean).length;
    if (manual === 0) return 'AUTO';
    if (manual === fields.length) return 'MANUAL';
    return 'MIXED';
  }

  function previewImage(item, manualImage) {
    if (item.kind !== 'static') return String(item.effective_image || item.automatic_image || '');
    if (!manualImage) return String(item.automatic_image || '');
    if (/^https?:\/\//i.test(manualImage)) return manualImage;
    if (manualImage.startsWith('/')) return location.origin + manualImage;
    return '';
  }

  function setText(id, value) {
    const target = node(id);
    if (target) target.textContent = value;
  }

  function previewWarnings(title, description) {
    const warnings = [];
    if (!title) warnings.push('MISSING TITLE');
    if (!description) warnings.push('MISSING DESCRIPTION');
    if (title.length > 70) warnings.push('TITLE LONG');
    if (description.length > 180) warnings.push('DESCRIPTION LONG');
    return warnings;
  }

  function renderPreviewImage(image) {
    const imageNode = node('seo-editor-preview-image');
    if (imageNode) {
      imageNode.hidden = !image;
      if (image) imageNode.src = image;
      else imageNode.removeAttribute('src');
    }
    setText('seo-editor-preview-image-label', image || 'No effective social image');
  }

  function renderPreviewWarnings(warnings) {
    const target = node('seo-editor-warnings');
    if (!target) return;
    target.innerHTML = warnings
      .map(warning => `<span class="seo-editor-warning">${esc(warning)}</span>`)
      .join('');
  }

  function updatePreview() {
    const item = state.editing;
    if (!item) return;

    const values = editorValues();
    const title = renderedTitle(item, values.title);
    const description = values.description || String(item.automatic_description || '');
    const image = previewImage(item, values.image);

    setText('seo-editor-preview-title', title);
    setText('seo-editor-preview-description', description);
    setText('seo-editor-mode', formMode(item,values));
    setText(
      'seo-editor-title-count',
      `${title.length}/60 recommended · ${values.title ? 'MANUAL' : item.fallback_title_source}`
    );
    setText(
      'seo-editor-description-count',
      `${description.length}/160 recommended · ${values.description ? 'MANUAL' : item.fallback_description_source}`
    );
    renderPreviewImage(image);
    renderPreviewWarnings(previewWarnings(title, description));
  }

  function openEditor(key, trigger = null) {
    const item = currentItem(key);
    const overlay = editor();
    if (!item || !overlay) return;
    state.editing = item;
    state.dirty = false;
    state.lastFocus = trigger || document.activeElement;

    node('seo-editor-kicker').textContent = `${item.type} / ${item.mode}`;
    node('seo-editor-title').textContent = item.label || item.path || 'SEARCH METADATA';
    node('seo-editor-canonical').textContent = item.canonical || item.path || '';
    node('seo-editor-state').textContent = item.public ? 'PUBLIC' : String(item.status || 'PRIVATE').toUpperCase();
    node('seo-editor-mode').textContent = item.mode || 'AUTO';
    node('seo-editor-title-source').textContent = item.fallback_title_source || '';
    node('seo-editor-description-source').textContent = item.fallback_description_source || '';
    node('seo-editor-title-input').value = item.seo_title || '';
    node('seo-editor-title-input').placeholder = item.automatic_title || '';
    node('seo-editor-description-input').value = item.seo_description || '';
    node('seo-editor-description-input').placeholder = item.automatic_description || '';
    node('seo-editor-image-input').value = item.share_image || '';
    node('seo-editor-image-field').hidden = item.kind !== 'static';
    node('seo-editor-preview-url').textContent = item.canonical || item.path || '';
    if (typeof overlay.showModal === 'function') overlay.showModal();
    else overlay.setAttribute('open','');
    document.body.classList.add('seo-editor-open');
    updatePreview();
    requestAnimationFrame(() => node('seo-editor-title-input')?.focus());
  }

  function closeEditor(force = false) {
    const overlay = editor();
    if (!overlay?.hasAttribute('open')) return true;
    if (!force && state.dirty && !window.confirm('Discard unsaved SEO changes?')) return false;
    if (typeof overlay.close === 'function') overlay.close();
    else overlay.removeAttribute('open');
    document.body.classList.remove('seo-editor-open');
    state.editing = null;
    state.dirty = false;
    const focus = state.lastFocus;
    state.lastFocus = null;
    focus?.focus?.();
    return true;
  }

  async function saveEditor(event) {
    event.preventDefault();
    const item = state.editing;
    if (!item) return;
    const values = editorValues();
    const button = node('seo-editor-save');
    const previous = button?.textContent || 'SAVE SEO';
    if (button) {
      button.disabled = true;
      button.textContent = 'SAVING…';
    }
    try {
      const token = await csrfToken();
      const body = {
        kind:item.kind,
        key:item.kind === 'static' ? item.key : undefined,
        resource:item.kind === 'entity' ? item.resource : undefined,
        id:item.kind === 'entity' ? item.id : undefined,
        seo_title:values.title,
        seo_description:values.description,
      };
      if (item.kind === 'static') body.share_image = values.image;
      const response = await fetch(endpoint,{
        method:'PUT',
        credentials:'same-origin',
        cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':token},
        body:JSON.stringify(body),
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) {
        throw new Error(payload.error || ('HTTP_' + response.status));
      }
      state.dirty = false;
      window.BRVTALFeedback?.success?.('SEO metadata saved.','seo-workspace');
      await load();
      closeEditor(true);
    } catch (error) {
      const message = error?.message || 'SEO_SAVE_FAILED';
      setStatus('SEO save failed: ' + message, true);
      window.BRVTALFeedback?.error?.('SEO save failed: ' + message,'seo-workspace');
    } finally {
      if (button?.isConnected) {
        button.disabled = false;
        button.textContent = previous;
      }
    }
  }

  function bind() {
    const root = state.root;
    if (!root || root.dataset.seoWorkspaceBound === '1') return;
    root.dataset.seoWorkspaceBound = '1';

    ['seo-workspace-search','seo-workspace-type','seo-workspace-status-filter','seo-workspace-mode','seo-workspace-health']
      .forEach(id => node(id)?.addEventListener(id === 'seo-workspace-search' ? 'input' : 'change', renderInventory));

    root.addEventListener('click', event => {
      const open = event.target.closest('[data-seo-open]');
      if (open) {
        openEditor(open.dataset.seoOpen,open);
        return;
      }
      if (event.target.closest('[data-seo-editor-close]')) {
        closeEditor(false);
        return;
      }
      const reset = event.target.closest('[data-seo-reset]');
      if (!reset) return;
      const target = {
        title:'seo-editor-title-input',
        description:'seo-editor-description-input',
        image:'seo-editor-image-input',
      }[reset.dataset.seoReset];
      if (target && node(target)) {
        node(target).value = '';
        state.dirty = true;
        updatePreview();
        node(target).focus();
      }
    });

    node('seo-workspace-form')?.addEventListener('submit',saveEditor);
    editor()?.addEventListener('cancel', event => {
      event.preventDefault();
      closeEditor(false);
    });
    ['seo-editor-title-input','seo-editor-description-input','seo-editor-image-input'].forEach(id => {
      node(id)?.addEventListener('input',() => {
        state.dirty = true;
        updatePreview();
      });
    });

    if (!state.keyboardBound) {
      state.keyboardBound = true;
      document.addEventListener('keydown', event => {
        const overlay = editor();
        if (event.key === 'Escape' && overlay?.hasAttribute('open')) {
          event.preventDefault();
          closeEditor(false);
        }
      });
    }
  }

  async function mount(root) {
    state.root = root;
    bind();
    await load();
  }

  return {mount,load,openEditor,closeEditor};
})();
