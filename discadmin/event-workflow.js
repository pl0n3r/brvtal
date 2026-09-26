(() => {
  'use strict';

  const core = window.BRVTALContentCore;
  if (!core || typeof core.mount !== 'function') return;

  const originalMount = core.mount.bind(core);

  function notice(root, text, ok = true) {
    const node = root.querySelector('#eventNotice');
    if (!node) return;
    node.textContent = text;
    node.className = 'notice show ' + (ok ? 'ok' : 'err');
    if (ok) setTimeout(() => node.classList.remove('show'), 5000);
  }

  function field(root, id) {
    return root.querySelector('#' + id);
  }

  function value(root, id) {
    return String(field(root, id)?.value ?? '');
  }

  function normalizedAccent(root) {
    const raw = value(root, 'e_accent').trim();
    if (!raw) return '';
    const shared = window.BRVTALAdminColorField?.normalize?.(raw);
    if (shared) return shared;
    const candidate = /^[0-9a-f]{6}$/i.test(raw) ? '#' + raw : raw;
    if (!/^#[0-9a-f]{6}$/i.test(candidate)) throw new Error('INVALID_ACCENT');
    return candidate.toLowerCase();
  }

  function errorMessage(code) {
    const messages = {
      TITLE_REQUIRED:'Event name is required.',
      EVENT_DATE_REQUIRED:'Date and time are required before leaving draft.',
      EVENT_CITY_REQUIRED:'City is required before leaving draft.',
      TICKET_NAME_REQUIRED:'Every ticket type needs a name.',
      INVALID_PRICE:'Ticket price must be a valid non-negative number.',
      INVALID_CURRENCY:'Ticket currency must use a 3-letter code such as COP or USD.',
      INVALID_EXTERNAL_URL:'Ticket purchase URL must use http(s).',
      INVALID_TICKET_URL:'Event ticket URL must use http(s).',
      INVALID_ACCENT:'Event Accent must be a 6-digit HEX color.',
      INVALID_AVAILABILITY_WINDOW:'Ticket availability end must not be before its start.',
      LINEUP_ARTIST_NOT_FOUND:'One selected artist no longer exists. Reload the Event and try again.',
      DUPLICATE_SLUG:'That Event slug is already in use.',
      EVENT_NOT_FOUND:'This Event no longer exists. Reload Events.',
      ACTIVITY_SCHEMA_MISSING:'Admin Activity storage is unavailable, so the Event was not changed.'
    };
    return messages[code] || String(code || 'EVENT_WORKFLOW_FAILED').replace(/_/g, ' ').toLowerCase().replace(/^\w/, c => c.toUpperCase());
  }

  async function csrfToken() {
    if (window.BRVTALAdminAuthBoundary?.csrfToken) {
      return window.BRVTALAdminAuthBoundary.csrfToken();
    }
    try { if (typeof csrf !== 'undefined' && csrf) return csrf; } catch (_) {}
    throw new Error('AUTH_REQUIRED');
  }

  async function existingLineup(eventId) {
    if (!eventId) return [];
    const response = await fetch('/api/index.php/events/' + encodeURIComponent(eventId) + '/lineup', {
      credentials:'same-origin', cache:'no-store'
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.error || 'LINEUP_LOAD_FAILED');
    return Array.isArray(payload.data) ? payload.data : [];
  }

  function eventPayload(root, eventId) {
    const rawDate = value(root, 'e_event_date');
    const event = {
      id:eventId || null,
      title:value(root, 'e_title').trim(),
      slug:value(root, 'e_slug').trim(),
      description:value(root, 'e_description'),
      cover_image:value(root, 'e_cover_image'),
      accent:normalizedAccent(root),
      featured:Number(value(root, 'e_featured')) || 0,
      event_date:rawDate ? rawDate.replace('T', ' ') : null,
      city:value(root, 'e_city').trim(),
      venue:value(root, 'e_venue').trim(),
      archive_year:value(root, 'e_archive_year') ? Number(value(root, 'e_archive_year')) : null,
      status:value(root, 'e_status') || 'draft',
      ticket_instructions:value(root, 'e_ticket_instructions'),
      ticket_qr:value(root, 'e_ticket_qr'),
      ticket_url:value(root, 'e_ticket_url')
    };
    if (!event.title) throw new Error('TITLE_REQUIRED');
    if (event.status !== 'draft' && !event.event_date) throw new Error('EVENT_DATE_REQUIRED');
    if (event.status !== 'draft' && !event.city) throw new Error('EVENT_CITY_REQUIRED');
    return event;
  }

  function ticketPayloads(root) {
    return [...root.querySelectorAll('#tickets .ticket-row')].map((row, index) => {
      const read = key => String(row.querySelector('[data-k="' + key + '"]')?.value ?? '');
      const name = read('name').trim();
      if (!name) throw new Error('TICKET_NAME_REQUIRED');
      const price = read('price');
      if (price !== '' && (!Number.isFinite(Number(price)) || Number(price) < 0)) throw new Error('INVALID_PRICE');
      const currency = (read('currency').trim() || 'COP').toUpperCase();
      if (!/^[A-Z]{3}$/.test(currency)) throw new Error('INVALID_CURRENCY');
      const externalUrl = read('external_url').trim();
      if (externalUrl) {
        let parsed;
        try { parsed = new URL(externalUrl); } catch (_) { throw new Error('INVALID_EXTERNAL_URL'); }
        if (!['http:','https:'].includes(parsed.protocol) || externalUrl.length > 700) throw new Error('INVALID_EXTERNAL_URL');
      }
      const availableFrom = read('available_from');
      const availableUntil = read('available_until');
      if (availableFrom && availableUntil && availableUntil < availableFrom) throw new Error('INVALID_AVAILABILITY_WINDOW');
      const payload = {
        name,
        description:read('description'),
        price:price === '' ? null : price,
        currency,
        status:read('status') || 'active',
        external_url:externalUrl || null,
        payment_instructions:read('payment_instructions'),
        qr_image:read('qr_image').trim(),
        available_from:availableFrom || null,
        available_until:availableUntil || null,
        sort_order:index
      };
      const id = Number(row.dataset.id || 0);
      if (id > 0) payload.id = id;
      return payload;
    });
  }

  function lineupPayload(root, before) {
    const previous = new Map((before || []).map(item => [Number(item.artist_id), item]));
    let nextOrder = (before || []).reduce((max, item) => Math.max(max, Number(item.lineup_order) || 0), -1) + 1;
    const selected = [...root.querySelectorAll('#eventArtists [data-artist]:checked')].map(el => {
      const artistId = Number(el.dataset.artist || 0);
      const existing = previous.get(artistId);
      return {
        artist_id:artistId,
        lineup_order:existing ? Number(existing.lineup_order) || 0 : nextOrder++,
        role:String(existing?.role || '')
      };
    }).filter(item => item.artist_id > 0);
    selected.sort((a,b) => a.lineup_order - b.lineup_order || a.artist_id - b.artist_id);
    return selected;
  }

  function install(root) {
    if (!root || core.saveEvent?.__brvtalAtomicWorkflow) return;

    let activeEventId = null;
    const originalOpenEvent = core.openEvent;
    core.openEvent = function(id = null) {
      activeEventId = Number(id || 0) || null;
      return originalOpenEvent.apply(this, arguments);
    };

    const atomicSave = async function() {
      const buttons = [field(root,'cc-saveBtn'), field(root,'cc-top-saveBtn')].filter(Boolean);
      const labels = buttons.map(button => button.textContent);
      buttons.forEach(button => { button.disabled = true; button.textContent = 'SAVING…'; });

      try {
        if (activeEventId) {
          const ticketsState = root.querySelector('#tickets')?.dataset.loadState;
          const lineupState = root.querySelector('#eventArtists')?.dataset.loadState;
          if (ticketsState && ticketsState !== 'ready') throw new Error('TICKETS_NOT_READY');
          if (lineupState && lineupState !== 'ready') throw new Error('LINEUP_NOT_READY');
        }

        const event = eventPayload(root, activeEventId);
        const tickets = ticketPayloads(root);
        const beforeLineup = await existingLineup(activeEventId);
        const lineup = lineupPayload(root, beforeLineup);
        const token = await csrfToken();
        const response = await fetch('/api/event-workflow.php', {
          method:'POST',
          credentials:'same-origin',
          cache:'no-store',
          headers:{'Content-Type':'application/json','X-CSRF-Token':token},
          body:JSON.stringify({event, ticket_types:tickets, lineup})
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false) throw new Error(payload.error || ('HTTP_' + response.status));

        const eventId = Number(payload.data?.event?.id || 0);
        if (!eventId) throw new Error('EVENT_ID_MISSING');
        activeEventId = eventId;
        await core.loadEvents();
        core.openEvent(eventId);
        notice(root, 'Event, tickets and roster saved together.');
        return true;
      } catch (error) {
        const code = error?.message || 'EVENT_WORKFLOW_FAILED';
        const message = code === 'TICKETS_NOT_READY'
          ? 'Wait for ticket types to load before saving this Event.'
          : code === 'LINEUP_NOT_READY'
            ? 'Wait for event participation to load before saving this Event.'
            : errorMessage(code);
        notice(root, message, false);
        return false;
      } finally {
        buttons.forEach((button, index) => {
          if (!button.isConnected) return;
          button.disabled = false;
          button.textContent = labels[index] || 'SAVE';
        });
      }
    };
    atomicSave.__brvtalAtomicWorkflow = true;
    core.saveEvent = atomicSave;
  }

  core.mount = function(root) {
    const result = originalMount(root);
    install(root);
    return result;
  };
})();
