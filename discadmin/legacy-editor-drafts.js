(() => {
  'use strict';

  const DEBOUNCE_MS = 650;
  const configs = new Map([
    ['pages', {
      read() {
        const value = id => document.getElementById('f_' + id)?.value ?? '';
        return {
          title:value('title'), slug:value('slug'), locale:value('locale') || 'en',
          content_json:value('content_json'), seo_title:value('seo_title'),
          seo_description:value('seo_description'), status:value('status') || 'draft'
        };
      },
      apply(data) {
        const values = {title:data.title,slug:data.slug,locale:data.locale,content_json:data.content_json,seo_title:data.seo_title,seo_description:data.seo_description,status:data.status};
        Object.entries(values).forEach(([name,next]) => {
          const control = document.getElementById('f_' + name);
          if (control && next !== undefined && next !== null) control.value = String(next);
        });
      }
    }],
    ['events', {
      read() {
        const value = id => document.getElementById('e_' + id)?.value ?? '';
        const tickets = [...document.querySelectorAll('#tickets .ticket-row')].map(row => {
          const read = key => row.querySelector('[data-k="' + key + '"]')?.value ?? '';
          return {id:row.dataset.id || '',name:read('name'),price:read('price'),currency:read('currency'),status:read('status'),description:read('description'),external_url:read('external_url'),available_from:read('available_from'),available_until:read('available_until'),payment_instructions:read('payment_instructions'),qr_image:read('qr_image')};
        });
        const lineup = [...document.querySelectorAll('#eventArtists [data-artist]')].filter(input => input.checked).map(input => ({artist_id:Number(input.dataset.artist)})).filter(item => item.artist_id > 0);
        return {title:value('title'),slug:value('slug'),description:value('description'),cover_image:value('cover_image'),accent:value('accent'),featured:value('featured'),event_date:value('event_date'),city:value('city'),venue:value('venue'),archive_year:value('archive_year'),status:value('status') || 'draft',ticket_instructions:value('ticket_instructions'),ticket_qr:value('ticket_qr'),ticket_url:value('ticket_url'),tickets,lineup};
      },
      apply(data) {
        const value = (id,next) => {
          const control = document.getElementById('e_' + id);
          if (control && next !== undefined && next !== null) control.value = String(next);
        };
        ['title','slug','description','cover_image','accent','featured','event_date','city','venue','archive_year','status','ticket_instructions','ticket_qr','ticket_url'].forEach(id => value(id,data[id]));
        const ticketsHost = document.getElementById('tickets');
        if (ticketsHost && window.BRVTALContentCore?.addTicket && Array.isArray(data.tickets)) {
          ticketsHost.innerHTML = '';
          data.tickets.forEach(ticket => window.BRVTALContentCore.addTicket(ticket));
        }
        const selected = new Set((data.lineup || []).map(item => Number(item.artist_id)).filter(Boolean));
        document.querySelectorAll('#eventArtists [data-artist]').forEach(input => { input.checked = selected.has(Number(input.dataset.artist)); });
        window.BRVTALAdminColorField?.sync?.(document.getElementById('e_accent'));
        window.BRVTALUnsavedChanges?.touch?.(document.getElementById('eventModal'));
      }
    }],
    ['artists', {
      read() {
        const value = id => document.getElementById('f_' + id)?.value ?? '';
        return {name:value('name'),slug:value('slug'),bio:value('bio'),photo:value('photo'),instagram_url:value('instagram_url'),soundcloud_url:value('soundcloud_url'),website_url:value('website_url'),is_collective_member:Boolean(document.getElementById('f_is_collective_member')?.checked),status:value('status') || 'draft'};
      },
      apply(data) {
        const values = {name:data.name,slug:data.slug,bio:data.bio,photo:data.photo,instagram_url:data.instagram_url,soundcloud_url:data.soundcloud_url,website_url:data.website_url,status:data.status};
        Object.entries(values).forEach(([name,next]) => {
          const control = document.getElementById('f_' + name);
          if (control && next !== undefined && next !== null) control.value = String(next);
        });
        const member = document.getElementById('f_is_collective_member');
        if (member && data.is_collective_member !== undefined) member.checked = Boolean(data.is_collective_member);
        window.BRVTALUnsavedChanges?.touch?.(document.getElementById('modal'));
      }
    }],
    ['sets', {
      read() {
        const value = id => document.getElementById('f_' + id)?.value ?? '';
        return {title:value('title'),slug:value('slug'),platform:value('platform') || 'soundcloud',external_url:value('external_url'),embed_url:value('embed_url'),cover_image:value('cover_image'),artist_id:value('artist_id') ? Number(value('artist_id')) : null,event_id:value('event_id') ? Number(value('event_id')) : null,status:value('status') || 'draft',description:value('description')};
      },
      apply(data) {
        const values = {title:data.title,slug:data.slug,platform:data.platform,external_url:data.external_url,embed_url:data.embed_url,cover_image:data.cover_image,artist_id:data.artist_id,event_id:data.event_id,status:data.status,description:data.description};
        Object.entries(values).forEach(([name,next]) => {
          const control = document.getElementById('f_' + name);
          if (control && next !== undefined && next !== null) control.value = String(next);
        });
        window.BRVTALUnsavedChanges?.touch?.(document.getElementById('modal'));
      }
    }],
    ['releases', {
      read() {
        const value = id => document.getElementById('release_' + id)?.value ?? '';
        const artists = [...document.querySelectorAll('[data-release-artist]:checked')].map((checkbox,index) => ({artist_id:Number(checkbox.dataset.releaseArtist),role:document.querySelector('[data-release-role="' + Number(checkbox.dataset.releaseArtist) + '"]')?.value?.trim() || 'Primary',sort_order:index})).filter(item => item.artist_id > 0);
        return {title:value('title'),slug:value('slug'),release_type:value('type'),catalog_number:value('catalog'),release_date:value('date'),description:value('description'),artwork:value('artwork'),spotify_url:value('spotify'),soundcloud_url:value('soundcloud'),bandcamp_url:value('bandcamp'),youtube_url:value('youtube'),beatport_url:value('beatport'),status:value('status_field') || 'draft',featured:Boolean(document.getElementById('release_featured')?.checked),artists};
      },
      apply(data) {
        const values = {title:data.title,slug:data.slug,type:data.release_type,catalog:data.catalog_number,date:data.release_date,description:data.description,artwork:data.artwork,spotify:data.spotify_url,soundcloud:data.soundcloud_url,bandcamp:data.bandcamp_url,youtube:data.youtube_url,beatport:data.beatport_url,status_field:data.status};
        Object.entries(values).forEach(([name,next]) => {
          const control = document.getElementById('release_' + name);
          if (control && next !== undefined && next !== null) control.value = String(next);
        });
        const featured = document.getElementById('release_featured');
        if (featured && data.featured !== undefined) featured.checked = Boolean(data.featured);
        const selected = new Map((data.artists || []).map(item => [Number(item.artist_id), item]));
        document.querySelectorAll('[data-release-artist]').forEach(checkbox => {
          const item = selected.get(Number(checkbox.dataset.releaseArtist));
          checkbox.checked = Boolean(item);
          const role = document.querySelector('[data-release-role="' + Number(checkbox.dataset.releaseArtist) + '"]');
          if (role && item) role.value = String(item.role || 'Primary');
        });
        window.BRVTALUnsavedChanges?.touch?.(document.getElementById('modal'));
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

  function editorRoot(type) {
    return String(type || '') === 'events' ? document.getElementById('eventModal') : document.getElementById('modal');
  }

  function stateNode() {
    return context?.root?.querySelector('[data-legacy-draft-state]') || null;
  }

  function recoveryNode() {
    return context?.root?.querySelector('[data-legacy-draft-recovery]') || null;
  }

  function setState(message, state = '') {
    const node = stateNode();
    if (!node) return;
    node.textContent = message;
    node.dataset.state = state;
  }

  function ensureUi(root) {
    if (!root) return false;
    const host = root.querySelector('#eventForm') || root.querySelector('#mcontent .form') || root.querySelector('.form') || root;
    if (!host) return false;
    let strip = root.querySelector('.legacy-draft-strip');
    if (!strip) {
      strip = document.createElement('div');
      strip.className = 'legacy-draft-strip';
      strip.innerHTML =
        '<div data-legacy-draft-state role="status" aria-live="polite" data-state="server">Saved to server</div>' +
        '<div data-legacy-draft-recovery role="alert" hidden>' +
        '<span data-legacy-draft-message></span>' +
        '<span class="legacy-draft-actions">' +
        '<button class="iconbtn" type="button" data-legacy-draft-restore>RESTORE DRAFT</button>' +
        '<button class="iconbtn" type="button" data-legacy-draft-discard>DISCARD DRAFT</button>' +
        '</span></div>';
      host.prepend(strip);
    }
    return true;
  }

  function currentData() {
    if (!context) return null;
    return configs.get(context.type)?.read?.() || null;
  }

  function resultRevision(result, fallback = '') {
    const candidate = result?.data && typeof result.data === 'object' && !Array.isArray(result.data) ? result.data : result;
    return String(candidate?.updated_at || result?.updated_at || fallback || '');
  }

  function applyDraft(draft) {
    if (!context || !draft?.data) return false;
    configs.get(context.type)?.apply?.(draft.data);
    setState('Unsaved · restored locally', 'unsaved');
    return true;
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
    if (!supported(type)) return false;

    const root = editorRoot(type);
    context = {
      type:String(type),
      id:id !== null && id !== '' ? Number(id) : null,
      identity:identity(id),
      baseRevision:revision(record),
      root
    };
    if (!ensureUi(root)) {
      context = null;
      return false;
    }

    bindController = new AbortController();
    const content = root.querySelector('#mcontent') || root;
    content.addEventListener('input', schedule, {capture:true,signal:bindController.signal});
    content.addEventListener('change', schedule, {capture:true,signal:bindController.signal});
    setState(context.id ? 'Saved to server' : 'Unsaved new editor', context.id ? 'server' : 'unsaved');
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
    const savedRevision = resultRevision(result, active.baseRevision);
    if (savedRevision) active.baseRevision = savedRevision;

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

  window.BRVTALLegacyDrafts = {bind,serverSaved,saveFailed,supported,snapshot:currentData};
})();
