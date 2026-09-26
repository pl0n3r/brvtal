(() => {
  'use strict';

  const DEBOUNCE_MS = 650;
  const configs = new Map([
    ['pages', {
      label:'page',
      read() {
        const value = id => document.getElementById('f_' + id)?.value ?? '';
        return {
          title:value('title'),
          slug:value('slug'),
          locale:value('locale') || 'en',
          content_json:value('content_json'),
          seo_title:value('seo_title'),
          seo_description:value('seo_description'),
          status:value('status') || 'draft'
        };
      },
      apply(data) {
        const values = {
          title:data.title,
          slug:data.slug,
          locale:data.locale,
          content_json:data.content_json,
          seo_title:data.seo_title,
          seo_description:data.seo_description,
          status:data.status
        };
        Object.entries(values).forEach(([name,next]) => {
          const control = document.getElementById('f_' + name);
          if (control && next !== undefined && next !== null) control.value = String(next);
        });
      }
    }],
    ['artists', {
      label:'artist',
      read() {
        const value = id => document.getElementById('f_' + id)?.value ?? '';
        return {
          name:value('name'),
          slug:value('slug'),
          bio:value('bio'),
          photo:value('photo'),
          instagram_url:value('instagram_url'),
          soundcloud_url:value('soundcloud_url'),
          website_url:value('website_url'),
          is_collective_member:Boolean(document.getElementById('f_is_collective_member')?.checked),
          status:value('status') || 'draft'
        };
      },
      apply(data) {
        const values = {
          name:data.name,
          slug:data.slug,
          bio:data.bio,
          photo:data.photo,
          instagram_url:data.instagram_url,
          soundcloud_url:data.soundcloud_url,
          website_url:data.website_url,
          status:data.status
        };
        Object.entries(values).forEach(([name,next]) => {
          const control = document.getElementById('f_' + name);
          if (control && next !== undefined && next !== null) control.value = String(next);
        });
        const membership = document.getElementById('f_is_collective_member');
        if (membership && data.is_collective_member !== undefined) {
          membership.checked = Boolean(data.is_collective_member);
        }
      }
    }]
  ]);

  let context = null;
  let timer = 0;
  let bindController = null;
  let editorGeneration = 0;

  function invalidateEditorWrites() {
    editorGeneration += 1;
    window.BRVTALDrafts?.invalidateWrites?.();
    return editorGeneration;
  }

  function supported(type) {
    return configs.has(String(type || ''));
  }

  function identity(id) {
    return id !== null && id !== '' && Number(id) > 0 ? String(Number(id)) : 'new';
  }

  function revision(record) {
    return String(record?.updated_at || '');
  }

  function sameData(left, right) {
    if (!left || typeof left !== 'object' || Array.isArray(left)) {
      return JSON.stringify(left ?? null) === JSON.stringify(right ?? null);
    }
    return Object.entries(left).every(
      ([key,value]) => JSON.stringify(value) === JSON.stringify(right?.[key])
    );
  }

  function editorLabel(type) {
    return String(configs.get(String(type || ''))?.label || 'item');
  }

  function stopTimer() {
    if (!timer) return;
    clearTimeout(timer);
    timer = 0;
  }

  function stateNode() {
    return document.querySelector('[data-legacy-draft-state]');
  }

  function recoveryNode() {
    return document.querySelector('[data-legacy-draft-recovery]');
  }

  function setState(message, state = '') {
    const node = stateNode();
    if (!node) return;
    node.textContent = message;
    node.dataset.state = state;
  }

  function ensureUi() {
    const form = document.querySelector('#mcontent .form');
    if (!form) return false;
    let strip = form.querySelector('.legacy-draft-strip');
    if (!strip) {
      strip = document.createElement('div');
      strip.className = 'legacy-draft-strip';
      strip.innerHTML = `
        <div data-legacy-draft-state role="status" aria-live="polite" data-state="server">Saved to server</div>
        <div data-legacy-draft-recovery role="alert" hidden>
          <span data-legacy-draft-message></span>
          <span class="legacy-draft-actions">
            <button class="iconbtn" type="button" data-legacy-draft-restore>RESTORE DRAFT</button>
            <button class="iconbtn" type="button" data-legacy-draft-discard>DISCARD DRAFT</button>
          </span>
        </div>`;
      form.prepend(strip);
    }
    return true;
  }

  function currentData() {
    if (!context) return null;
    return configs.get(context.type)?.read?.() || null;
  }

  async function persist({announce = true} = {}) {
    const active = context;
    const storage = window.BRVTALDrafts;
    const generation = editorGeneration;
    if (!active || !storage) return false;
    stopTimer();
    if (announce) setState('Saving draft…', 'saving');
    try {
      await storage.save(active.type, active.identity, {
        base_revision:active.baseRevision,
        data:currentData()
      });
      if (context !== active || generation !== editorGeneration) return false;
      if (announce) setState('Draft saved locally', 'saved');
      return true;
    } catch (error) {
      if (context !== active || generation !== editorGeneration || error?.name === 'AbortError') return false;
      const reason = error instanceof DOMException && error.name === 'QuotaExceededError'
        ? 'storage full'
        : 'storage unavailable';
      setState(`Save failed · ${reason} · changes remain in this editor`, 'error');
      return false;
    }
  }

  function schedule() {
    if (!context) return;
    setState('Unsaved', 'unsaved');
    stopTimer();
    timer = setTimeout(() => { void persist(); }, DEBOUNCE_MS);
  }

  async function removeDraft(scope, itemIdentity) {
    if (!window.BRVTALDrafts || !itemIdentity) return true;
    try {
      await window.BRVTALDrafts.remove(scope, itemIdentity);
      return true;
    } catch (error) {
      document.documentElement.dataset.brvtalDraftStorage =
        error instanceof SyntaxError ? 'invalid' : 'unavailable';
      setState('Draft cleanup failed · storage unavailable', 'error');
      return false;
    }
  }

  function applyDraft(draft) {
    if (!context || !draft?.data) return false;
    configs.get(context.type)?.apply?.(draft.data);
    window.BRVTALUnsavedChanges?.touch?.(document.getElementById('modal'));
    setState('Unsaved · restored locally', 'unsaved');
    return true;
  }

  async function showRecovery() {
    const active = context;
    const recovery = recoveryNode();
    const storage = window.BRVTALDrafts;
    if (!active || !recovery || !storage) return;

    const draft = await storage.load(active.type, active.identity);
    if (context !== active) return;
    if (!draft) {
      recovery.hidden = true;
      return;
    }

    const conflict = active.baseRevision !== ''
      && !storage.sameRevision(draft, active.baseRevision);
    recovery.hidden = false;
    recovery.dataset.conflict = conflict ? '1' : '0';
    const message = recovery.querySelector('[data-legacy-draft-message]');
    if (message) {
      message.textContent = conflict
        ? 'SERVER CHANGED SINCE THIS DRAFT · Restore only to review locally before an explicit Save.'
        : 'A recoverable local draft exists for this editor.';
    }

    recovery.querySelector('[data-legacy-draft-restore]')?.addEventListener('click', () => {
      if (!applyDraft(draft)) return;
      recovery.hidden = true;
    }, {once:true});

    recovery.querySelector('[data-legacy-draft-discard]')?.addEventListener('click', async () => {
      if (!await removeDraft(active.type, active.identity)) return;
      recovery.hidden = true;
      setState(active.id ? 'Saved to server' : `Unsaved new ${editorLabel(active.type)}`, active.id ? 'server' : 'unsaved');
    }, {once:true});
  }

  async function bind(type, id = null, record = {}) {
    invalidateEditorWrites();
    stopTimer();
    bindController?.abort();
    bindController = null;
    context = null;
    if (!supported(type) || !ensureUi()) return false;

    bindController = new AbortController();
    context = {
      type:String(type),
      id:id !== null && id !== '' ? Number(id) : null,
      identity:identity(id),
      baseRevision:revision(record)
    };

    const content = document.getElementById('mcontent');
    content?.addEventListener('input', schedule, {capture:true,signal:bindController.signal});
    content?.addEventListener('change', schedule, {capture:true,signal:bindController.signal});
    setState(context.id ? 'Saved to server' : `Unsaved new ${editorLabel(context.type)}`, context.id ? 'server' : 'unsaved');
    await showRecovery();
    return true;
  }

  async function serverSaved({type,id,payload,result} = {}) {
    invalidateEditorWrites();
    const active = context;
    if (active?.type !== String(type) || !supported(type)) {
      return {keepOpen:false,id};
    }

    stopTimer();
    const current = currentData();
    const savedId = Number(result?.id || id || active.id || 0) || active.id || null;
    const oldIdentity = active.identity;
    const newerEdits = Boolean(current && payload && !sameData(current, payload));

    active.id = savedId;
    active.identity = identity(savedId);

    if (newerEdits) {
      if (oldIdentity !== active.identity) await removeDraft(active.type, oldIdentity);
      const retained = await persist({announce:false});
      window.BRVTALUnsavedChanges?.touch?.(document.getElementById('modal'));
      setState(
        retained
          ? 'Draft saved locally · newer edits remain unsaved'
          : 'Save succeeded · newer edits are not stored locally; keep this editor open',
        retained ? 'saved' : 'error'
      );
      return {keepOpen:true,id:savedId};
    }

    const cleared = await removeDraft(active.type, oldIdentity);
    if (oldIdentity !== active.identity) await removeDraft(active.type, active.identity);
    window.BRVTALUnsavedChanges?.markClean?.(document.getElementById('modal'));
    setState(
      cleared ? 'Saved to server' : 'Saved to server · local draft cleanup failed',
      cleared ? 'server' : 'error'
    );
    return {keepOpen:false,id:savedId};
  }

  async function saveFailed({type} = {}) {
    if (context?.type !== String(type) || !supported(type)) return false;
    const retained = await persist({announce:false});
    setState(
      retained
        ? 'Save failed · local draft kept'
        : 'Save failed · latest changes not stored locally; keep this editor open',
      'error'
    );
    return retained;
  }

  window.addEventListener('brvtal:auth-required', () => {
    invalidateEditorWrites();
    stopTimer();
    bindController?.abort();
    bindController = null;
    context = null;
  });

  window.BRVTALLegacyDrafts = {bind,serverSaved,saveFailed,supported};
})();
