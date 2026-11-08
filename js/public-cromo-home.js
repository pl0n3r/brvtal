/*
 * BRVTAL — Cromo home (public).
 * Builds the approved artifact (docs/design/cromo-prototype.html) from the real
 * public data (/api/public.php) and mounts it as #cromo-home. If the data does
 * not load or anything throws, nothing is mounted and the Concept 05 home stays.
 * Every string from the CMS is inserted with textContent, never as HTML.
 */
(() => {
  const root = document.documentElement;
  const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

  const el = (tag, attrs, ...kids) => {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([key, value]) => {
      if (value === false || value === null || value === undefined) return;
      if (key === 'class') node.className = String(value).split(/\s+/).filter(Boolean).map((c) => 'cr-' + c).join(' ');
      else if (key === 'text') node.textContent = value;
      else node.setAttribute(key, value === true ? '' : String(value));
    });
    kids.flat().forEach((kid) => {
      if (kid === null || kid === undefined || kid === false) return;
      node.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
    });
    return node;
  };
  const text = (value, max) => {
    const clean = String(value ?? '').replace(/\s+/g, ' ').trim();
    return max && clean.length > max ? clean.slice(0, max - 1).trimEnd() + '…' : clean;
  };
  const safeUrl = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (raw.startsWith('/') && !raw.startsWith('//')) return raw;
    try {
      const url = new URL(raw, location.origin);
      return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : '';
    } catch (error) { return ''; }
  };
  const parseDate = (value) => {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
    if (!match) return null;
    return new Date(+match[1], +match[2] - 1, +match[3], +(match[4] || 0), +(match[5] || 0));
  };
  const two = (n) => String(n).padStart(2, '0');
  const titleCase = (s) => s.toLowerCase().replace(/(^|\s)(\S)/g, (m, a, b) => a + b.toUpperCase());
  const joinNames = (names) => (names.length < 2 ? names.join('') : names.slice(0, -1).join(', ') + ' y ' + names[names.length - 1]);

  const nextEvent = (events) => {
    const now = Date.now() - 6 * 3600 * 1000;
    return events
      .filter((e) => !e.cancelled_at && !e.finished_at && parseDate(e.event_date) && parseDate(e.event_date).getTime() >= now)
      .sort((a, b) => parseDate(a.event_date) - parseDate(b.event_date));
  };

  const lineupText = (artists) => {
    const node = document.querySelector('#genesis .experience-lineup p');
    const raw = node ? text(node.textContent) : '';
    if (!raw) return '';
    const names = raw.split('/').map((s) => s.trim()).filter(Boolean).map((name) => {
      const known = artists.find((a) => String(a.name).toLowerCase() === name.toLowerCase());
      return known ? known.name : titleCase(name);
    });
    return joinNames(names);
  };

  // SoundCloud-only track/playlist embeds. Profiles, untrusted origins and CMS
  // iframe HTML must never become an in-page player.
  const soundcloudTrackUrl = (raw) => {
    try {
      const url = new URL(String(raw || ''));
      if (url.protocol !== 'https:' || !['soundcloud.com', 'www.soundcloud.com'].includes(url.hostname)
        || url.username || url.password || url.port) return '';
      const path = url.pathname.split('/').filter(Boolean);
      if (!(path.length === 2 || (path.length === 3 && path[1] === 'sets'))) return '';
      if (path.some((part) => !/^[a-z0-9_-]{1,120}$/i.test(part))
        || ['discover', 'stream', 'search', 'you', 'charts', 'upload', 'tracks', 'settings'].includes(path[0].toLowerCase())) return '';
      return 'https://soundcloud.com/' + path.join('/');
    } catch (_) { return ''; }
  };
  const soundcloudArtworkUrl = (raw) => {
    try {
      const url = new URL(String(raw || ''));
      return url.protocol === 'https:' && /^i[0-9]{1,2}\.sndcdn\.com$/i.test(url.hostname)
        && /^\/artworks-[a-z0-9_-]+\.(?:jpg|jpeg|png|webp)$/i.test(url.pathname)
        && !url.username && !url.password && !url.port ? url.origin + url.pathname : '';
    } catch (_) { return ''; }
  };
  const buildSets = (sets) => {
    let active = null;
    // Transitions.dev timing + optional GSAP: progressive enhancement only.
    // All cards are already in the DOM and usable without animation or GSAP.
    const revealBatch = (cards) => {
      if (!cards.length || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      const gsap = window.gsap;
      if (typeof gsap?.fromTo !== 'function') return;
      gsap.fromTo(cards, { opacity: 0, y: 12 }, {
        opacity: 1, y: 0, duration: .36, ease: 'power3.out', stagger: .045,
        overwrite: 'auto', clearProps: 'opacity,transform',
      });
    };
    const stop = () => {
      if (!active) return;
      active.player.remove(); // Destroy the actual provider iframe; stops audio.
      active.button.textContent = 'ESCUCHAR';
      active.button.setAttribute('aria-expanded', 'false');
      active.card.classList.remove('cr-is-playing');
      active = null;
    };
    window.addEventListener('brvtal:cromo-ambient-start', stop);

    const artwork = (set, featured = false) => {
      const fallback = safeUrl(set.cover_image);
      const box = el('span', { class: 'set-art is-empty' + (featured ? ' set-art-featured' : ''), 'aria-hidden': 'true' });
      const image = el('img', { alt: '', loading: featured ? 'eager' : 'lazy', decoding: 'async' });
      const show = (source) => {
        if (!source) { image.removeAttribute('src'); box.classList.add('cr-is-empty'); return; }
        image.src = source;
        box.classList.remove('cr-is-empty');
      };
      image.addEventListener('error', () => {
        if (fallback && image.getAttribute('src') !== fallback) show(fallback);
        else show('');
      });
      box.append(image);
      show(fallback);

      const url = soundcloudTrackUrl(set.external_url);
      const ready = (button) => {
        if (!url) return;
        // Requests are to our same-origin, allowlisted and cached oEmbed adapter.
        fetch('/api/soundcloud-artwork.php?url=' + encodeURIComponent(url),
          { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(async (response) => ({ status: response.status, body: await response.json() }))
          .then(({ status, body }) => {
            if (!box.isConnected) return;
            const original = body?.ok ? soundcloudArtworkUrl(body.artwork_url) : '';
            if (original) show(original);
            if (status === 404 && button) {
              button.disabled = true;
              button.textContent = 'NO DISPONIBLE';
              button.title = 'El enlace de SoundCloud no permite reproducir este set';
              if (active?.button === button) stop();
            }
          }).catch(() => { /* Keep CMS artwork and external link on metadata failure. */ });
      };
      return { box, ready, url };
    };

    const makeActions = (set, card, art) => {
      const actions = el('div', { class: 'sound-actions' });
      const playerId = 'cromo-set-player-' + String(set.id || Math.random()).replace(/[^a-z0-9_-]/gi, '');
      const button = el('button', {
        class: 'pl sound-listen', type: 'button', 'aria-expanded': 'false',
        'aria-controls': playerId, text: art.url ? 'ESCUCHAR' : 'NO DISPONIBLE',
      });
      button.disabled = !art.url;
      button.addEventListener('click', () => {
        if (active?.card === card) { stop(); return; }
        stop();
        window.BRVTALCromoSound?.stop?.();
        const player = el('div', { class: 'set-player', id: playerId });
        const embed = 'https://w.soundcloud.com/player/?url=' + encodeURIComponent(art.url)
          + '&auto_play=true&show_artwork=true&show_comments=false&hide_related=true';
        const iframe = el('iframe', {
          src: embed, title: 'Escuchar ' + text(set.title || 'set BRVTAL'),
          loading: 'eager', allow: 'autoplay; encrypted-media',
          sandbox: 'allow-scripts allow-same-origin allow-presentation',
          referrerpolicy: 'no-referrer',
        });
        player.append(iframe);
        card.append(player);
        card.classList.add('cr-is-playing');
        button.textContent = 'CERRAR';
        button.setAttribute('aria-expanded', 'true');
        active = { card, player, button };
      });
      actions.append(button);
      const external = safeUrl(set.external_url);
      if (external) actions.append(el('a', {
        class: 'sound-external', href: external, target: '_blank', rel: 'noopener noreferrer',
        text: 'ABRIR EN ' + (String(set.platform || 'SOUNDCLOUD').toUpperCase()) + ' ↗',
      }));
      art.ready(button);
      return actions;
    };

    // The public API already filters published records and applies the
    // editorial sort_order. The first record is the featured Set, not
    // necessarily the most recently published one.
    const publishedArtistNames = (set) => {
      const names = Array.isArray(set.artist_names)
        ? set.artist_names.filter((name) => typeof name === 'string').map((name) => text(name)).filter(Boolean)
        : [];
      return names.length ? joinNames(names) : text(set.artist_name);
    };
    const makeRow = (set) => {
      const art = artwork(set);
      const row = el('article', { class: 'plate chrome-b sound-row' },
        art.box,
        el('span', { class: 'sound-row-meta' },
          el('b', { text: text(set.title) }),
          el('small', { text: publishedArtistNames(set) })));
      row.append(makeActions(set, row, art));
      return row;
    };

    let setsSec = el('section', { class: 'sec', id: 'discos' },
      el('h2', { class: 'u', text: 'Sets' }),
      el('p', { class: 'm', text: 'Los próximos sets aparecerán aquí cuando estén publicados.' }),
      el('p', { class: 'set-count', role: 'status', 'aria-live': 'polite', text: '0 sets publicados' }));
    if (sets.length) {
      const [first, ...rest] = sets;
      const featureArt = artwork(first, true);
      const featBody = el('div', { class: 'sound-feature-meta' },
        el('p', { text: 'Set destacado' }),
        featureArt.box,
        el('h3', { text: text(first.title) }),
        el('p', { text: [publishedArtistNames(first), first.event_title ? 'en ' + text(first.event_title) : ''].filter(Boolean).join(', ') }));
      const feat = el('article', { class: 'feat plate chrome-b' },
        el('div', { class: 'disc', 'aria-hidden': 'true' }),
        featBody);
      feat.append(makeActions(first, feat, featureArt));

      let visible = Math.min(4, rest.length);
      const list = el('div', { class: 'sl', id: 'cromo-set-list',
        'aria-live': 'polite', 'aria-relevant': 'additions' }, rest.slice(0, visible).map(makeRow));
      const count = el('p', { class: 'set-count', role: 'status', 'aria-live': 'polite', tabindex: '-1' });
      const more = el('button', {
        class: 'set-more', type: 'button', 'aria-controls': 'cromo-set-list',
        'aria-expanded': 'false', text: 'VER MÁS SETS',
      });
      const updateCount = () => {
        count.textContent = (visible + 1) + ' de ' + sets.length + ' ' +
          (sets.length === 1 ? 'set publicado' : 'sets publicados') +
          (visible >= rest.length ? ' · Catálogo completo' : '');
        more.hidden = visible >= rest.length;
      };
      more.addEventListener('click', () => {
        if (visible >= rest.length) return;
        const added = rest.slice(visible, visible + 4).map(makeRow);
        added.forEach((row) => list.append(row));
        visible += added.length;
        more.setAttribute('aria-expanded', 'true');
        updateCount();
        // Keep the first newly focused card visible during the GSAP reveal.
        // A hidden final button must not strand keyboard focus.
        let nextFocus = null;
        if (more.hidden) {
          nextFocus = added.map((row) =>
            row.querySelector('button.cr-sound-listen:not([disabled]), a.cr-sound-external')).find(Boolean);
          (nextFocus || count).focus();
        }
        revealBatch(nextFocus ? added.filter((row) => !row.contains(nextFocus)) : added);
      });
      updateCount();
      setsSec = el('section', { class: 'sec', id: 'discos' },
        el('h2', { class: 'u', text: 'Sets' }),
        el('div', { class: 'sets' },
          feat, el('div', { class: 'sets-catalog' }, list, count, more)));
    }
    return setsSec;
  };

  const buildMemories = (memories) => {
    // --- Memorias
    const tiles = memories.slice(0, 9).map((m, index) => {
      const url = safeUrl(m.file_path);
      const isImage = m.type === 'image' && url;
      const tile = el('a', Object.assign({ class: 't chrome-b' + (isImage ? ' has-img' : ''), href: url || '#memorias', 'data-type': m.type || 'image', style: (index === 0 ? 'grid-column:span 2;grid-row:span 2;' : '') + '--a:' + ((index * 90 + 20) % 360) + 'deg' + (isImage ? ';--img:url("' + url.replace(/["()\\]/g, '') + '")' : '') }, url ? { target: '_blank', rel: 'noopener noreferrer' } : {}), el('span', { text: text(m.title) }));
      return tile;
    });
    const chipDefs = [['Todo', ''], ['Imágenes', 'image'], ['Video', 'video'], ['Audio', 'audio']];
    const chips = el('div', { class: 'chips', role: 'group', 'aria-label': 'Filtrar medios' }, chipDefs.map(([label, type], i) => {
      const chip = el('button', { class: 'chip' + (i === 0 ? ' on' : ''), type: 'button', 'aria-pressed': i === 0 ? 'true' : 'false', text: label });
      chip.addEventListener('click', () => {
        chips.querySelectorAll('.cr-chip').forEach((c) => { c.classList.toggle('cr-on', c === chip); c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
        tiles.forEach((tile) => { tile.hidden = !!type && tile.dataset.type !== type; });
      });
      return chip;
    }));
    const memoriesSec = el('section', { class: 'sec', id: 'memorias' }, el('h2', { class: 'u', text: 'Memorias' }), chips,
      tiles.length ? el('div', { class: 'mem' }, tiles) : el('p', { style: 'background:#030303;display:inline-block;padding:10px 16px;font:600 22px/1.4 var(--body)', text: 'Aquí irán las fotos y videos reales de cada noche.' }));

    return memoriesSec;
  };


  // Only public, absolute HTTP(S) destinations may leave the site.
  const safeSocialUrl = (value) => {
    if (typeof value !== 'string') return '';
    const raw = value.trim();
    const authority = /^https?:\/\/([^/?#]+)/i.exec(raw)?.[1] || '';
    if (!authority || authority.includes('@') || /\s/.test(raw) || /[\\\u0000-\u001f\u007f]/.test(raw)) return '';
    try {
      const parsed = new URL(raw);
      return ['http:', 'https:'].includes(parsed.protocol) && parsed.hostname && !parsed.username && !parsed.password ? parsed.href : '';
    } catch (error) {
      // URL validation only normalizes known invalid-input errors; unexpected failures reach the home fallback.
      if (error instanceof TypeError) return '';
      throw error;
    }
  };

  const buildSocialLinks = (settings) => {
    const social = settings && typeof settings === 'object' && !Array.isArray(settings) ? settings : {};
    const destinations = [
      ['instagram', 'Instagram'], ['soundcloud', 'SoundCloud'], ['youtube', 'YouTube'],
      ['spotify', 'Spotify'], ['website', 'Sitio web'],
    ];
    const links = destinations.map(([key, label]) => {
      const href = safeSocialUrl(social[key] || social[key + '_url']);
      return href ? el('a', {
        class: 'social-link', href, target: '_blank', rel: 'noopener noreferrer',
        text: label + ' ↗', 'aria-label': label + ' (abre en una nueva pestaña)',
      }) : null;
    }).filter(Boolean);
    if (!links.length) return null;
    return el('div', { class: 'social', 'aria-label': 'Redes y enlaces oficiales' },
      el('h2', { class: 'u social-title', text: 'Enlaces oficiales' }),
      el('div', { class: 'social-grid' }, links));
  };

  const buildContact = (social) => {
    // --- Contacto
    const status = el('p', { class: 'form-status', role: 'status', 'aria-live': 'polite', 'data-state': 'idle' });
    const captchaLabel = el('label', { for: 'cromo-c', text: 'Cargando…' });
    const token = el('input', { type: 'hidden', name: 'captcha_token' });
    const submit = el('button', { class: 'btn r', type: 'submit', text: 'Enviar mensaje' });
    const field = (id, label, control, name) => el('div', {}, el('label', { for: id, text: label }), control, el('span', { class: 'form-err', 'data-error-for': name }));
    const form = el('form', { class: 'form plate chrome-b', novalidate: true },
      field('cromo-n', 'Nombre', el('input', { id: 'cromo-n', name: 'name', type: 'text', autocomplete: 'name', maxlength: '100', required: true }), 'name'),
      field('cromo-e', 'Correo', el('input', { id: 'cromo-e', name: 'email', type: 'email', autocomplete: 'email', maxlength: '190', required: true }), 'email'),
      field('cromo-m', 'Mensaje', el('textarea', { id: 'cromo-m', name: 'message', rows: '4', maxlength: '5000', required: true }), 'message'),
      el('div', { class: 'hp', 'aria-hidden': 'true' }, el('input', { name: 'website', tabindex: '-1', autocomplete: 'off' })),
      token,
      el('div', { style: 'display:grid;grid-template-columns:140px 1fr;gap:14px;align-items:end' },
        el('div', {}, captchaLabel, el('input', { id: 'cromo-c', name: 'captcha_answer', type: 'text', inputmode: 'numeric', pattern: '[0-9]*', required: true, autocomplete: 'off' }), el('span', { class: 'form-err', 'data-error-for': 'captcha' })),
        submit),
      status);
    const setStatus = (msg, state) => { status.textContent = msg; status.dataset.state = state; };
    const loadChallenge = async () => {
      captchaLabel.textContent = 'Cargando…';
      token.value = '';
      form.elements.captcha_answer.value = '';
      try {
        const res = await fetch('/api/contact.php', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
        const payload = await res.json();
        if (!res.ok || !payload.data || !payload.data.token) throw new Error('captcha');
        captchaLabel.textContent = text(payload.data.question).replace(/\?\s*$/, '');
        token.value = String(payload.data.token);
      } catch (error) {
        captchaLabel.textContent = 'Control anti-bot';
        setStatus('El control anti-bot no está disponible. Intenta más tarde.', 'error');
      }
    };
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      form.querySelectorAll('[aria-invalid]').forEach((n) => n.removeAttribute('aria-invalid'));
      form.querySelectorAll('.cr-form-err').forEach((n) => { n.textContent = ''; });
      const body = Object.fromEntries(new FormData(form).entries());
      body.subject = 'Contacto desde el home';
      submit.disabled = true;
      setStatus('Enviando…', 'idle');
      try {
        const res = await fetch('/api/contact.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) });
        const payload = await res.json().catch(() => ({}));
        if (res.ok && payload.ok !== false) { form.reset(); setStatus('Mensaje enviado. Gracias, te respondemos pronto.', 'success'); await loadChallenge(); return; }
        if (res.status === 422) {
          Object.keys(payload.fields || {}).forEach((name) => {
            const key = name === 'form' ? 'captcha' : name;
            const control = form.elements[key === 'captcha' ? 'captcha_answer' : key];
            const slot = form.querySelector('[data-error-for="' + key + '"]');
            if (slot) slot.textContent = 'Revisa este campo.';
            if (control) control.setAttribute('aria-invalid', 'true');
          });
          setStatus('Revisa los campos marcados.', 'error');
          if (payload.fields && payload.fields.captcha) await loadChallenge();
          return;
        }
        setStatus(res.status === 429 ? 'Demasiados intentos. Espera un momento e inténtalo de nuevo.' : 'No pudimos enviar el mensaje. Inténtalo de nuevo.', 'error');
      } catch (error) {
        setStatus('No pudimos enviar el mensaje. Revisa tu conexión.', 'error');
      } finally {
        submit.disabled = false;
      }
    });
    const end = el('section', { class: 'end', id: 'contacto' }, el('a', { class: 'u', href: '#contacto', text: 'Hablemos' }), form, buildSocialLinks(social));

    return { end, loadChallenge };
  };

  const buildNextCard = (next, artists) => {
    const when = parseDate(next.event_date);
    const today = new Date();
    // Compare calendar dates, not elapsed local-midnight hours (DST may add one).
    const dayUTC = (date) => Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
    const days = Math.round((dayUTC(when) - dayUTC(today)) / 86400000);
    const k = days <= 0 ? 'Es hoy' : days === 1 ? 'Falta 1 día' : 'Faltan ' + days + ' días';
    const lineup = lineupText(artists);
    const place = [text(next.city), text(next.venue)].filter(Boolean).join(', ');
    const slug = typeof next.slug === 'string' ? next.slug.trim() : '';
    const href = slug ? '/events/' + encodeURIComponent(slug) : '';
    const details = [lineup, place].filter(Boolean).join(' · ');
    const attrs = {
      class: href ? 'next next-link' : 'next',
      'aria-label': href ? 'Ver evento ' + text(next.title, 90) + '. ' + k : 'Próxima noche',
      ...(href ? { href } : {}),
    };
    return el(href ? 'a' : 'aside', attrs,
      el('span', { class: 'next-tag', text: 'PRÓXIMA NOCHE' }),
      el('b', { class: 'next-count', text: k }),
      el('span', { class: 'next-date', text: when.getDate() + ' ' + MONTHS[when.getMonth()] }),
      el('strong', { class: 'next-event', text: text(next.title, 90) }),
      details ? el('small', { class: 'next-details', text: details }) : null,
      href ? el('span', { class: 'next-action' },
        el('span', { text: 'VER EVENTO' }), el('span', { class: 'next-arrow', 'aria-hidden': 'true', text: '↗' })) : null
    );
  };

  const buildHero = (next, artists, site) => {
    // --- Hero
    const word = el('h1', { class: 'u', id: 'word', 'aria-label': 'BRVTAL' }, Array.from('BRVTAL').map((c) => el('span', { 'aria-hidden': 'true', text: c })));
    const nextCard = next ? buildNextCard(next, artists) : null;
    const hero = el('section', { class: 'hero' },
      el('div', { class: 'core' }, word, el('p', { text: text(site?.description, 240) || 'Más que fiestas. Una cultura en movimiento.' })),
      nextCard,
      el('div', { class: 'cta' }, el('a', { class: 'btn r', href: '#noches', text: 'Ver la próxima noche' }), el('a', { class: 'btn', href: '#sonido', text: 'Escuchar' })));
    const bandItems = ['Rave till grave', 'Pereira, Colombia', 'Más que fiestas'];
    const band = el('div', { class: 'band', 'aria-hidden': 'true' }, el('div', {}, [...bandItems, ...bandItems].map((t) => el('span', { text: t }))));

    return { word, hero, band };
  };

  const buildNights = (upcoming, next, artists, archive) => {
    // --- Noches
    const nights = el('section', { class: 'sec', id: 'noches' }, el('h2', { class: 'u', text: 'Noches' }));
    upcoming.slice(0, 6).forEach((event) => {
      const when = parseDate(event.event_date);
      const place = [text(event.city), text(event.venue)].filter(Boolean).join(', ');
      const meta = (event === next ? lineupText(artists) : '') ;
      const line = [meta, place + ', ' + two(when.getHours()) + ':' + two(when.getMinutes())].filter(Boolean).join('. ') + '.';
      nights.append(el('a', { class: 'night', href: '/events/' + encodeURIComponent(event.slug) },
        el('span', { class: 'u n', text: two(when.getDate()) + '.' + two(when.getMonth() + 1) }),
        el('span', {}, el('span', { class: 't', text: text(event.title) }), el('span', { class: 'm', text: line })),
        el('span', { class: 'go', text: safeUrl(event.ticket_url) ? 'Comprar tickets' : 'Ver noche' })));
    });
    nights.append(el('a', { class: 'night', href: '#contacto' },
      el('span', { class: 'u n', text: 'Sigue' }),
      el('span', {}, el('span', { class: 't', text: 'Aún sin fecha' }), el('span', { class: 'm', text: 'Escríbenos y te avisamos cuando se anuncie la siguiente.' })),
      el('span', { class: 'go', text: 'Avísame' })));

    // --- Últimas noches: the archive, as posters (the old #events / #archive links land here).
    const past = (Array.isArray(archive) ? archive : []).filter((event) => event?.slug && parseDate(event.event_date))
      .sort((a, b) => parseDate(b.event_date) - parseDate(a.event_date));
    if (past.length) {
      const LIMIT_PAST = 8;
      const cards = past.map((event, i) => {
        const when = parseDate(event.event_date);
        const cover = safeUrl(event.cover_image);
        const meta = [two(when.getDate()) + ' ' + MONTHS[when.getMonth()] + ' ' + when.getFullYear(), text(event.city)].filter(Boolean).join(' · ');
        const card = el('a', { class: 'pst chrome-b', href: '/events/' + encodeURIComponent(event.slug) },
          cover ? el('img', { src: cover, alt: '', loading: 'lazy', decoding: 'async' }) : el('i', { class: 'orb', 'aria-hidden': 'true' }),
          el('span', { class: 'pst-t u', text: text(event.title, 40) }), el('span', { class: 'pst-m', text: meta }));
        if (i >= LIMIT_PAST) card.classList.add('cr-is-extra');
        return card;
      });
      const title = el('h3', { class: 'u glitch', 'data-text': 'Últimas noches', text: 'Últimas noches' });
      const wrap = el('div', { class: 'arch', id: 'archivo' }, title, el('div', { class: 'pst-grid' }, cards));
      if (past.length > LIMIT_PAST) {
        const more = el('button', { class: 'pst-more', type: 'button', 'aria-expanded': 'false', text: 'Ver todas (' + past.length + ')' });
        more.addEventListener('click', () => {
          const open = wrap.classList.toggle('cr-is-open');
          more.setAttribute('aria-expanded', String(open));
          more.textContent = open ? 'Ver menos' : 'Ver todas (' + past.length + ')';
        });
        wrap.append(more);
      }
      nights.append(wrap);
    }

    return nights;
  };

  const buildArtists = (artists) => {
    // --- Artistas
    const roster = [...artists].sort((a, b) => (b.is_collective_member ? 1 : 0) - (a.is_collective_member ? 1 : 0)).slice(0, 5);
    const artistCard = (a) => {
      const card = el('a', { class: 'ac plate chrome-b', href: '/artists/' + encodeURIComponent(a.slug) });
      const fallback = el('i', { class: 'orb', 'aria-hidden': 'true' });
      const photo = safeUrl(a.photo);
      // CMS uploads are public same-origin media; never inject untrusted remote URLs.
      if (photo && new URL(photo, location.origin).origin === location.origin) {
        const image = el('img', { class: 'photo', alt: '', loading: 'lazy', decoding: 'async' });
        image.addEventListener('error', () => {
          image.remove();
          card.classList.remove('cr-has-photo');
          fallback.hidden = false;
        }, { once: true });
        image.src = photo;
        fallback.hidden = true;
        card.classList.add('cr-has-photo');
        card.append(image);
      }
      card.append(fallback,
        el('span', { class: 'nm', text: text(a.name) }),
        el('span', { class: 'rl', text: (a.is_collective_member ? 'Colectivo. ' : 'Colaborador. ') + text(a.bio, 90) }));
      return card;
    };
    const artistsGrid = el('div', { class: 'artists' }, roster.map(artistCard));
    artistsGrid.append(el('a', { class: 'ac plate chrome-b', href: '#contacto' },
      el('i', { class: 'orb', 'aria-hidden': 'true' }), el('span', { class: 'nm', text: 'Tú' }),
      el('span', { class: 'rl', text: '¿Quieres sumarte al roster? Escríbenos y cuéntanos qué suenas.' })));
    const artistsSec = el('section', { class: 'sec', id: 'artistas' }, el('h2', { class: 'u', text: 'Artistas' }), artistsGrid);

    return artistsSec;
  };

  const buildJournal = (posts) => {
    // --- Journal
    const post = posts[0];
    const cards = [];
    if (post) {
      cards.push(el('article', { class: 'jn plate chrome-b' },
        el('div', {}, el('p', { class: 'k', text: 'Crónica' }), el('h3', { style: 'margin-top:10px', text: text(post.title) })),
        el('p', { text: text(post.excerpt, 180) }),
        el('div', {}, el('a', { class: 'btn', href: '/blog/' + encodeURIComponent(post.slug), text: 'Leer la crónica' }))));
    }
    cards.push(el('article', { class: 'jn plate chrome-b' },
      el('div', {}, el('p', { class: 'k', text: 'Archivo' }), el('h3', { style: 'margin-top:10px', text: 'Todo está conectado' })),
      el('p', { text: 'De una noche puedes llegar a su música y a quien la tocó: eventos, artistas, sets y memorias, enlazados.' }),
      el('div', {}, el('a', { class: 'btn', href: '#memorias', text: 'Explorar el archivo' }))));
    const journalSec = el('section', { class: 'sec', id: 'journal' }, el('h2', { class: 'u', text: 'Journal' }), el('div', { class: 'journal' }, cards));

    return journalSec;
  };

  const buildExperience = (next, artists, relations) => {
    // --- Experiencia: the next night as a feature, from the same data as Noches.
    if (!next) return null;
    const when = parseDate(next.event_date);
    const ids = (relations.events?.[next.id]?.artists || []).map(Number);
    const names = ids.map((id) => artists.find((a) => Number(a.id) === id)).filter(Boolean).map((a) => text(a.name));
    const cover = safeUrl(next.cover_image);
    const tickets = safeUrl(next.ticket_url);
    const fact = (k, v) => el('div', {}, el('dt', { text: k }), el('dd', { text: v }));
    const body = el('div', { class: 'xp-body plate chrome-b' },
      el('p', { class: 'k', text: 'Próxima experiencia' }),
      el('h3', { class: 'u', text: text(next.title) }),
      el('dl', { class: 'xp-facts' },
        fact('Fecha', two(when.getDate()) + ' ' + MONTHS[when.getMonth()] + ' ' + when.getFullYear()),
        fact('Hora', two(when.getHours()) + ':' + two(when.getMinutes())),
        fact('Lugar', [text(next.venue), text(next.city)].filter(Boolean).join(', '))),
      next.description ? el('p', { class: 'xp-desc', text: text(next.description, 240) }) : null,
      names.length ? el('p', { class: 'xp-line' }, el('span', { text: 'Cartel' }), ' ' + joinNames(names)) : null,
      el('div', { class: 'xp-cta' },
        tickets ? el('a', { class: 'btn r', href: tickets, rel: 'noopener noreferrer', text: 'Comprar tickets' }) : null,
        el('a', { class: 'btn', href: '/events/' + encodeURIComponent(next.slug), text: 'Ver la noche' })));
    const fly = cover ? el('a', { class: 'xp-fly chrome-b', href: '/events/' + encodeURIComponent(next.slug), 'aria-label': text(next.title) },
      el('img', { src: cover, alt: '', loading: 'lazy', decoding: 'async' })) : null;
    return el('section', { class: 'sec', id: 'experiencia' }, el('h2', { class: 'u', text: 'Experiencia' }),
      el('div', { class: fly ? 'xp' : 'xp xp-solo' }, fly, body));
  };

  // Motion timing is read from the shared tokens (css/public-cromo-home.css), never hardcoded here.
  const ms = (name) => Number.parseFloat(getComputedStyle(document.getElementById('cromo-home') || root).getPropertyValue(name)) || 0;
  const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // transitions.dev "text swap soft": old and new text cross-blur in place and the width eases.
  const softSwap = (node, next) => {
    const prev = node.dataset.text ?? node.textContent;
    if (prev === next) return;
    node.dataset.text = next;
    clearTimeout(node._softTimer);
    if (reduced()) { node.textContent = next; return; }
    const out = document.createElement('span');
    out.className = 't-text-soft-old';
    out.setAttribute('aria-hidden', 'true');
    out.textContent = prev;
    const inn = document.createElement('span');
    inn.className = 't-text-soft-new';
    inn.textContent = next;
    const w0 = node.getBoundingClientRect().width;
    node.classList.remove('is-swapping');
    node.replaceChildren(out, inn);
    const w1 = inn.getBoundingClientRect().width;
    node.style.width = w0 + 'px';
    node.getBoundingClientRect(); // force reflow so every transition runs
    node.classList.add('is-swapping');
    node.style.width = w1 + 'px';
    node._softTimer = setTimeout(() => {
      node.classList.remove('is-swapping');
      node.style.removeProperty('width');
      node.textContent = next;
    }, Math.max(ms('--text-soft-dur'), ms('--text-soft-resize-dur')) + 40);
  };

  const SVG_NS = 'http://www.w3.org/2000/svg';

  const buildConnected = (data) => {
    // --- Conectado: an explorer of real connections. Three columns (noches, artistas, sonido) list the
    // actual items; choosing one highlights what it is linked to, draws the links and says it in a sentence.
    // Only relations the CMS publishes are used; nothing is invented.
    const list = (key) => (Array.isArray(data[key]) ? data[key] : []);
    const rel = data.relations || {};
    const entities = new Map();
    const columns = { noches: [], artistas: [], sonido: [], memorias: [] };
    const route = (path, slug) => (slug ? '/' + path + '/' + encodeURIComponent(slug) : '');
    const add = (column, key, kind, label, sub, href) => {
      if (!label) return;
      const entity = { key, column, kind, label, sub, href };
      entities.set(key, entity);
      columns[column].push(entity);
    };
    list('events').slice().sort((a, b) => String(a.event_date).localeCompare(String(b.event_date))).forEach((e) => {
      const when = parseDate(e.event_date);
      add('noches', 'e' + e.id, 'noche', text(e.title, 60),
        [when ? two(when.getDate()) + ' ' + MONTHS[when.getMonth()] + ' ' + when.getFullYear() : '', text(e.city)].filter(Boolean).join(' · '), route('events', e.slug));
    });
    list('artists').slice().sort((a, b) => (b.is_collective_member ? 1 : 0) - (a.is_collective_member ? 1 : 0)).forEach((a) =>
      add('artistas', 'a' + a.id, 'artista', text(a.name, 60), a.is_collective_member ? 'Colectivo' : 'Colaborador', route('artists', a.slug)));
    list('sets').forEach((x) => add('sonido', 's' + x.id, 'set', text(x.title, 60), 'Set' + (x.artist_name ? ' · ' + text(x.artist_name, 30) : ''), route('sets', x.slug)));
    list('releases').forEach((r) => add('sonido', 'r' + r.id, 'disco', text(r.title, 60), 'Disco' + (r.release_type ? ' · ' + text(r.release_type, 20) : ''), route('releases', r.slug)));

    const adjacency = new Map();
    const join = (a, b) => {
      if (!entities.has(a) || !entities.has(b) || a === b) return;
      if (!adjacency.has(a)) adjacency.set(a, new Set());
      if (!adjacency.has(b)) adjacency.set(b, new Set());
      adjacency.get(a).add(b);
      adjacency.get(b).add(a);
    };
    const ids = (value) => {
      if (Array.isArray(value)) return value;
      return value === null || value === undefined ? [] : [value];
    };
    const entries = (value) => (value && typeof value === 'object' && !Array.isArray(value) ? Object.entries(value) : []);
    entries(rel.events).forEach(([id, r]) => { ids(r?.artists).forEach((x) => join('e' + id, 'a' + x)); ids(r?.sets).forEach((x) => join('e' + id, 's' + x)); });
    entries(rel.artists).forEach(([id, r]) => {
      ids(r?.events).forEach((x) => join('a' + id, 'e' + x));
      ids(r?.sets).forEach((x) => join('a' + id, 's' + x));
      ids(r?.releases).forEach((x) => join('a' + id, 'r' + x));
    });
    entries(rel.sets).forEach(([id, r]) => { ids(r?.artist).forEach((x) => join('s' + id, 'a' + x)); ids(r?.event).forEach((x) => join('s' + id, 'e' + x)); });
    entries(rel.releases).forEach(([id, r]) => { ids(r?.artist || r?.artists).forEach((x) => join('r' + id, 'a' + x)); ids(r?.event).forEach((x) => join('r' + id, 'e' + x)); });

    // The API already filters Memories and their target relations to published records.
    // Only put a Memory on the board when its target also has a visible node.
    const memoryTargets = new Map([['event', 'e'], ['artist', 'a'], ['set', 's'], ['release', 'r']]);
    list('memories').forEach((memory) => {
      const memoryId = Number(memory?.id);
      if (!Number.isSafeInteger(memoryId) || memoryId < 1) return;
      const links = (Array.isArray(memory.relations) ? memory.relations : []).map((relation) => {
        const prefix = memoryTargets.get(relation?.related_type);
        const id = Number(relation?.related_id);
        return prefix && Number.isSafeInteger(id) && id > 0 ? prefix + id : null;
      }).filter((key) => key && entities.has(key));
      if (!links.length) return;
      const key = 'm' + memoryId;
      add('memorias', key, 'memoria', text(memory.title, 60), 'Memoria', '#memorias');
      if (!entities.has(key)) return;
      links.forEach((target) => join(key, target));
    });

    const related = (key) => [...(adjacency.get(key) || [])].map((k) => entities.get(k)).filter(Boolean);
    const labels = (items) => joinNames(items.map((x) => x.label));
    const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);
    const memoryCaption = (entity, memories) => {
      if (!memories.length) return '';
      const verb = entity.kind === 'noche' ? 'Quedó registrada' : 'Aparece';
      return verb + ' en ' + plural(memories.length, 'memoria', 'memorias') + ': ' + labels(memories) + '.';
    };
    const detailForNight = ({ artists, sounds }) => [
      artists.length ? 'Tocan ' + labels(artists) + '.' : 'Aún no tiene artistas enlazados.',
      sounds.length ? 'Quedó registrada en ' + plural(sounds.length, 'pieza de sonido', 'piezas de sonido') + ': ' + labels(sounds) + '.' : ''
    ].filter(Boolean).join(' ');
    const detailForArtist = (entity, { nights, sounds }) => [
      nights.length ? entity.label + ' toca en ' + labels(nights) + '.' : entity.label + ' aún no tiene noches publicadas.',
      sounds.length ? 'Puedes escuchar ' + labels(sounds) + '.' : ''
    ].filter(Boolean).join(' ');
    const detailForMemory = ({ nights, artists, sounds }) => [
      nights.length ? 'Recuerda ' + labels(nights) + '.' : '',
      artists.length ? 'Conecta con ' + labels(artists) + '.' : '',
      sounds.length ? 'Incluye ' + labels(sounds) + '.' : ''
    ].filter(Boolean).join(' ');
    const detailForSound = (entity, { nights, artists }) => [
      artists.length ? 'Lo hace ' + labels(artists) + '.' : '',
      nights.length ? 'Se grabó en ' + labels(nights) + '.' : ''
    ].filter(Boolean).join(' ') || entity.label + '.';

    const sentenceOf = (entity) => {
      const around = related(entity.key);
      if (!around.length) return entity.label + ' todavía no tiene conexiones publicadas.';
      const groups = {
        nights: around.filter((x) => x.kind === 'noche'),
        artists: around.filter((x) => x.kind === 'artista'),
        sounds: around.filter((x) => x.column === 'sonido'),
        memories: around.filter((x) => x.kind === 'memoria')
      };
      let message;
      if (entity.kind === 'noche') message = detailForNight(groups);
      else if (entity.kind === 'artista') message = detailForArtist(entity, groups);
      else if (entity.kind === 'memoria') message = detailForMemory(groups);
      else message = detailForSound(entity, groups);
      const memoryText = memoryCaption(entity, groups.memories);
      const withMemories = (message) => memoryText ? message + ' ' + memoryText : message;
      return withMemories(message);
    };
    const actionOf = (entity) => ({ noche: 'Ver la noche', artista: 'Ver el artista', set: 'Escuchar el set', disco: 'Ver el disco', memoria: 'Ver la memoria' }[entity.kind]);

    let selected = null;
    let hovered = null;
    const items = new Map();
    const narrow = window.matchMedia('(max-width: 700px)');
    const TITLES = { noches: 'Noches', artistas: 'Artistas', sonido: 'Sonido', memorias: 'Memorias' };
    const LIMIT = 6;
    const board = el('div', { class: 'cn2-board' });
    if (columns.memorias.length) board.classList.add('cr-has-memories');
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', 'cr-cn2-lines');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');

    const columnNodes = Object.keys(columns).filter((name) => columns[name].length).map((name) => {
      const ul = el('ul', { class: 'cn2-list' });
      const more = columns[name].length > LIMIT ? el('button', { class: 'cn2-more', type: 'button', 'aria-expanded': 'false', text: 'Ver todos (' + columns[name].length + ')' }) : null;
      columns[name].forEach((entity, i) => {
        const button = el('button', { class: 'cn2-item', type: 'button', 'aria-pressed': 'false', 'data-key': entity.key },
          el('strong', { text: entity.label }), entity.sub ? el('small', { text: entity.sub }) : null);
        if (i >= LIMIT) button.classList.add('cr-is-extra');
        items.set(entity.key, button);
        button.addEventListener('pointerenter', () => { hovered = entity.key; paint(); });
        button.addEventListener('pointerleave', () => { hovered = null; paint(); });
        button.addEventListener('focus', () => { hovered = entity.key; paint(); });
        button.addEventListener('blur', () => { hovered = null; paint(); });
        button.addEventListener('click', () => choose(entity.key));
        ul.append(el('li', {}, button));
      });
      const column = el('div', { class: 'cn2-col', 'data-col': name }, el('h3', { class: 'u', text: TITLES[name] }), ul, more);
      if (more) more.addEventListener('click', () => {
        const open = column.classList.toggle('cr-is-open');
        more.setAttribute('aria-expanded', String(open));
        more.textContent = open ? 'Ver menos' : 'Ver todos (' + columns[name].length + ')';
        window.requestAnimationFrame(drawLines);
      });
      return column;
    });
    board.append(svg, ...columnNodes);

    const heading = el('p', { class: 'cn2-sel u' });
    const headingText = document.createElement('span');
    headingText.className = 't-text-soft';
    heading.append(headingText);
    const sentence = el('p', { class: 'cn2-sentence' });
    const sentenceText = document.createElement('span');
    sentenceText.className = 't-text-soft';
    sentence.append(sentenceText);
    const open = el('a', { class: 'btn r', href: '#', hidden: true });
    const clear = el('button', { class: 'btn', type: 'button', text: 'Quitar selección', hidden: true });
    const intro = el('p', { class: 'cn2-intro', text: columns.memorias.length
      ? 'Toca una noche, un artista, un set o una memoria y mira con qué se conecta.'
      : 'Toca una noche, un artista o un set y mira con qué se conecta.' });

    const focusKey = () => hovered || selected;
    const visibleEl = (button) => button && button.offsetParent !== null;
    function drawLines() {
      svg.replaceChildren();
      const key = focusKey();
      if (!key || narrow.matches) return;
      const from = items.get(key);
      if (!visibleEl(from)) return;
      const area = board.getBoundingClientRect();
      const a = from.getBoundingClientRect();
      const order = Object.keys(columns).filter((name) => columns[name].length);
      const fromIndex = order.indexOf(entities.get(key).column);
      related(key).forEach((other, i) => {
        const target = items.get(other.key);
        const toIndex = order.indexOf(other.column);
        if (!visibleEl(target) || toIndex === fromIndex) return;
        const b = target.getBoundingClientRect();
        const forward = toIndex > fromIndex;
        const x1 = (forward ? a.right : a.left) - area.left;
        const x2 = (forward ? b.left : b.right) - area.left;
        const y1 = a.top + a.height / 2 - area.top;
        const y2 = b.top + b.height / 2 - area.top;
        const bend = (x2 - x1) / 2;
        const path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', 'M' + x1 + ' ' + y1 + ' C' + (x1 + bend) + ' ' + y1 + ' ' + (x2 - bend) + ' ' + y2 + ' ' + x2 + ' ' + y2);
        path.setAttribute('pathLength', '1');
        path.setAttribute('class', 'cr-cn2-path');
        path.style.setProperty('--i', String(i));
        svg.append(path);
      });
      svg.setAttribute('width', area.width);
      svg.setAttribute('height', area.height);
    }
    function paint() {
      const key = focusKey();
      const around = key ? new Set((adjacency.get(key) || [])) : new Set();
      items.forEach((button, k) => {
        button.setAttribute('aria-pressed', String(k === selected));
        button.classList.toggle('cr-is-focus', k === key);
        button.classList.toggle('cr-is-linked', around.has(k));
        button.classList.toggle('cr-is-dim', Boolean(key) && k !== key && !around.has(k));
      });
      window.requestAnimationFrame(drawLines);
    }

    function choose(key) {
      selected = selected === key ? null : key;
      const entity = selected ? entities.get(selected) : null;
      if (entity) {
        // A hidden (collapsed) item becomes visible when chosen from a link or the keyboard.
        const column = board.querySelector('[data-col="' + entity.column + '"]');
        if (column && items.get(key).classList.contains('cr-is-extra')) { column.classList.add('cr-is-open'); const more = column.querySelector('.cr-cn2-more'); if (more) { more.setAttribute('aria-expanded', 'true'); more.textContent = 'Ver menos'; } }
      }
      softSwap(headingText, entity ? entity.label : 'Elige un punto de partida');
      softSwap(sentenceText, entity ? sentenceOf(entity) : 'Cada noche enlaza a sus artistas y cada artista a su música. Elige uno y míralo.');
      open.hidden = !entity?.href;
      if (entity?.href) { open.setAttribute('href', entity.href); open.textContent = actionOf(entity); }
      clear.hidden = !entity;
      paint();
    }
    clear.addEventListener('click', () => choose(selected));

    headingText.textContent = 'Elige un punto de partida';
    headingText.dataset.text = headingText.textContent;
    sentenceText.textContent = 'Cada noche enlaza a sus artistas y cada artista a su música. Elige uno y míralo.';
    sentenceText.dataset.text = sentenceText.textContent;
    window.addEventListener('resize', () => window.requestAnimationFrame(drawLines), { passive: true });
    narrow.addEventListener('change', drawLines);

    // Start with a real example so the section says something before any click: the next night.
    const first = columns.noches[0] || columns.artistas[0] || columns.sonido[0];
    const section = el('section', { class: 'sec', id: 'conectado' }, el('h2', { class: 'u', text: 'Conectado' }), intro, board,
      el('div', { class: 'cn2-sum plate chrome-b' }, heading, sentence, el('div', { class: 'cn2-actions' }, open, clear)));
    if (first) choose(first.key);

    // One authored moment: the lines draw in the first time the explorer scrolls into view.
    const reveal = () => { board.classList.add('cr-is-in'); window.requestAnimationFrame(drawLines); };
    if (reduced() || !('IntersectionObserver' in window)) reveal();
    else {
      const io = new IntersectionObserver((entriesSeen) => { if (entriesSeen.some((x) => x.isIntersecting)) { reveal(); io.disconnect(); } }, { threshold: .2 });
      io.observe(board);
    }
    section.addEventListener('keydown', (event) => { if (event.key === 'Escape' && selected) choose(selected); });
    return section;
  };

  const buildBanners = (cfg) => {
    // --- Destacados: the slides managed in DISCADMIN (Banners). Same data and options as before
    // (autoplay, interval, fade/slide/zoom, positioned layers with mobile overrides, video), shown in the
    // Cromo frame. Every string is inserted as text; URLs go through safeUrl.
    const list = (Array.isArray(cfg.slides) ? cfg.slides : []).filter((slide) => safeUrl(slide.desktopSrc));
    if (!cfg.enabled || !list.length) return null;
    const mobile = window.matchMedia('(max-width: 700px)');
    const pct = (value) => (value === null || value === undefined || value === '' || !Number.isFinite(Number(value)) ? '' : Number(value) + '%');
    const align = (value) => (['left', 'center', 'right'].includes(value) ? value : 'left');

    const mediaNode = (slide, first) => {
      const desktop = safeUrl(slide.desktopSrc);
      const small = safeUrl(slide.mobileSrc);
      if (slide.mediaType === 'video') {
        const video = el('video', { class: 'bn-media', loop: true, playsinline: true, preload: 'metadata', poster: safeUrl(slide.poster) || false, 'aria-hidden': 'true' });
        video.muted = true;
        video.append(el('source', { src: mobile.matches && small ? small : desktop }));
        return video;
      }
      const picture = document.createElement('picture');
      if (small) {
        const source = document.createElement('source');
        source.media = '(max-width: 700px)';
        source.srcset = small;
        picture.append(source);
      }
      picture.append(el('img', { class: 'bn-media', src: desktop, alt: '', decoding: 'async', loading: first ? 'eager' : 'lazy' }));
      return picture;
    };

    const layerNode = (layer) => {
      const vars = ['--x:' + pct(layer.x), '--y:' + pct(layer.y), '--w:' + pct(layer.width),
        pct(layer.mobileX) ? '--mx:' + pct(layer.mobileX) : '', pct(layer.mobileY) ? '--my:' + pct(layer.mobileY) : '',
        pct(layer.mobileWidth) ? '--mw:' + pct(layer.mobileWidth) : '',
        '--d:' + (Number(layer.delay) || 0) + 'ms', '--t:' + (Number(layer.duration) || 650) + 'ms'].filter((v) => v.length > 4).join(';');
      const animation = ['none', 'fade', 'slide-up', 'slide-left', 'zoom'].includes(layer.animation) ? layer.animation : 'fade';
      const cls = 'bn-layer bn-' + (['text', 'image', 'logo', 'cta'].includes(layer.type) ? layer.type : 'text') + ' bn-a-' + animation + ' bn-al-' + align(layer.align) + (layer.hiddenMobile ? ' bn-hm' : '');
      if (layer.type === 'image' || layer.type === 'logo') {
        const desktop = safeUrl(layer.src);
        if (!desktop) return null;
        const picture = document.createElement('picture');
        const small = safeUrl(layer.mobileSrc);
        if (small) { const source = document.createElement('source'); source.media = '(max-width: 700px)'; source.srcset = small; picture.append(source); }
        picture.append(el('img', { src: desktop, alt: '', decoding: 'async', loading: 'lazy' }));
        return el('div', { class: cls, style: vars }, picture);
      }
      if (layer.type === 'cta') {
        const href = safeUrl(layer.url);
        return el(href ? 'a' : 'span', { class: cls + ' btn r', href: href || false, style: vars, text: text(layer.text, 80) });
      }
      // The text sits on a plate (like a subtitle) so it reads over any artwork, including flyers that carry their own text.
      return el('div', { class: cls, style: vars }, el('span', { class: 'bn-t', text: text(layer.text, 220) }));
    };

    const legacyCopy = (slide) => {
      const href = safeUrl(slide.ctaUrl);
      const title = text(slide.title, 140);
      if (!slide.kicker && !title && !slide.body && !(href && slide.ctaLabel)) return [];
      return [el('div', { class: 'bn-copy bn-al-' + align(slide.contentAlign) },
        slide.kicker ? el('p', { class: 'k', text: text(slide.kicker, 120) }) : null,
        title ? el('h3', { class: 'u glitch', 'data-text': title, text: title }) : null,
        slide.body ? el('p', { class: 'bn-body', text: text(slide.body, 320) }) : null,
        href && slide.ctaLabel ? el('a', { class: 'btn r', href, text: text(slide.ctaLabel, 80) }) : null)];
    };

    const nodes = list.map((slide, i) => {
      const transition = ['fade', 'slide', 'zoom'].includes(slide.transition) ? slide.transition : 'fade';
      const layers = (Array.isArray(slide.layers) ? slide.layers : []).map(layerNode).filter(Boolean);
      const overlay = Math.max(0, Math.min(85, Number(slide.overlay) || 0)) / 100;
      return el('div', { class: 'bn-slide bn-t-' + transition + (i === 0 ? ' is-active' : ''), role: 'group', 'aria-roledescription': 'slide',
        'aria-label': (i + 1) + ' de ' + list.length, 'aria-hidden': i === 0 ? false : 'true', style: '--ov:' + overlay },
        mediaNode(slide, i === 0), el('div', { class: 'bn-tint' }), el('div', { class: 'bn-ov' }), layers.length ? layers : legacyCopy(slide));
    });
    // The class helper prefixes everything with cr-, including state classes.
    nodes.forEach((node, i) => { node.classList.remove('is-active'); if (i === 0) node.classList.add('cr-is-active'); });

    const stage = el('div', { class: 'bn-stage', style: '--bn-ms:' + Math.max(2500, Number(cfg.interval) || 7000) + 'ms' }, nodes);
    const section = el('section', { class: 'sec bn', id: 'destacados', 'aria-roledescription': 'carousel', 'aria-label': 'Destacados' },
      el('h2', { class: 'sr', text: 'Destacados' }), stage);
    if (list.length < 2) return section;

    let index = 0;
    let paused = false;
    let timer = null;
    const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const dots = list.map((slide, i) => el('button', { class: 'bn-dot', type: 'button', 'aria-label': 'Ir al banner ' + (i + 1), 'aria-current': i === 0 ? 'true' : false }));
    const playPause = el('button', { class: 'bn-pp', type: 'button', 'aria-pressed': 'false', 'aria-label': 'Pausar los banners', text: 'Pausa' });
    const prev = el('button', { class: 'bn-nav', type: 'button', 'aria-label': 'Banner anterior', text: '←' });
    const next = el('button', { class: 'bn-nav', type: 'button', 'aria-label': 'Banner siguiente', text: '→' });
    const pad = (n) => String(n).padStart(2, '0');
    const countNow = el('b', { text: pad(1) });
    const counter = el('p', { class: 'bn-count u', 'aria-hidden': 'true' }, countNow, el('span', { text: '/ ' + pad(list.length) }));
    const controls = el('div', { class: 'bn-ctl' }, el('div', { class: 'bn-dots' }, dots), prev, next, playPause);
    stage.append(counter, controls);

    const schedule = () => {
      clearTimeout(timer);
      if (!cfg.autoplay || paused || reduced() || document.hidden) return;
      timer = setTimeout(() => show(index + 1), Math.max(2500, Number(cfg.interval) || 7000));
    };
    const show = (to) => {
      const target = (to + nodes.length) % nodes.length;
      if (target === index) { schedule(); return; }
      nodes[index].classList.remove('cr-is-active');
      nodes[index].setAttribute('aria-hidden', 'true');
      const outgoing = nodes[index].querySelector('video');
      if (outgoing) outgoing.pause();
      dots[index].removeAttribute('aria-current');
      index = target;
      nodes[index].classList.add('cr-is-active');
      nodes[index].removeAttribute('aria-hidden');
      const incoming = nodes[index].querySelector('video');
      incoming?.play()?.catch(() => {});
      dots[index].setAttribute('aria-current', 'true');
      countNow.textContent = pad(index + 1);
      schedule();
    };
    prev.addEventListener('click', () => show(index - 1));
    next.addEventListener('click', () => show(index + 1));
    dots.forEach((dot, i) => dot.addEventListener('click', () => show(i)));
    playPause.addEventListener('click', () => {
      paused = !paused;
      playPause.setAttribute('aria-pressed', String(paused));
      playPause.setAttribute('aria-label', paused ? 'Reanudar los banners' : 'Pausar los banners');
      playPause.textContent = paused ? 'Seguir' : 'Pausa';
      stage.classList.toggle('cr-is-paused', paused);
      schedule();
    });
    const hold = () => { clearTimeout(timer); stage.classList.add('cr-is-held'); };
    const release = () => { stage.classList.remove('cr-is-held'); schedule(); };
    stage.addEventListener('mouseenter', hold);
    stage.addEventListener('mouseleave', release);
    stage.addEventListener('focusin', hold);
    stage.addEventListener('focusout', (event) => { if (!stage.contains(event.relatedTarget)) release(); });
    document.addEventListener('visibilitychange', schedule);
    // The site's own pulse: the frame flashes on each kick of the sound loop.
    window.addEventListener('cromo:beat', () => {
      if (reduced()) return;
      stage.classList.add('cr-beat');
      setTimeout(() => stage.classList.remove('cr-beat'), ms('--duration-quick') || 150);
    });
    schedule();
    return section;
  };

  const build = (data, slider) => {
    const events = Array.isArray(data.events) ? data.events : [];
    const artists = Array.isArray(data.artists) ? data.artists : [];
    const sets = Array.isArray(data.sets) ? data.sets : [];
    const memories = Array.isArray(data.memories) ? data.memories : [];
    const posts = Array.isArray(data.blog) ? data.blog : [];
    const upcoming = nextEvent(events);
    const next = upcoming[0] || null;
    const nextTicket = next ? safeUrl(next.ticket_url) : '';
    const home = el('div', { id: 'cromo-home' });

    // --- Header
    const sndLabel = el('span', { 'data-cromo-sound-label': true, text: 'Activar sonido' });
    const snd = el('button', { class: 'snd', id: 'snd', type: 'button', 'aria-pressed': 'false', 'data-cromo-sound': true, 'data-cromo-sound-off': 'Activar sonido', 'data-cromo-sound-on': 'Apagar el pulso' }, el('i'), sndLabel);
    const navLinks = [['noches', 'Noches'], ['artistas', 'Artistas'], ['sonido', 'Sonido'], ['memorias', 'Memorias'], ['journal', 'Journal'], ['contacto', 'Contacto']];
    const nav = el('nav', { 'aria-label': 'Secciones' }, navLinks.map(([id, label]) => el('a', { href: '#' + id, text: label })));
    const ticketsLink = el('a', Object.assign({ class: 'tickets', text: 'Tickets', href: nextTicket || '#noches' }, nextTicket ? { target: '_blank', rel: 'noopener noreferrer' } : {}));
    const header = el('header', { class: 'top' },
      el('a', { href: '#cromo-top', class: 'u', text: 'BRVTAL' }), nav,
      el('div', { style: 'display:flex;gap:10px;align-items:center' }, snd, ticketsLink));

    // --- Hero
    const { word, hero: heroSec, band } = buildHero(next, artists, data.settings?.site);

    // --- Noches
    const nights = buildNights(upcoming, next, artists, data.archive && data.archive.events);
    const experience = buildExperience(next, artists, data.relations || {});
    const connected = buildConnected(data);
    const banners = slider ? buildBanners(slider) : null;

    // --- Artistas
    const artistsSec = buildArtists(artists);

    // --- Sonido
    const snd2Label = el('span', { 'data-cromo-sound-label': true, text: 'Encender el pulso' });
    const snd2 = el('button', { class: 'btn r', id: 'snd2', type: 'button', 'aria-pressed': 'false', 'data-cromo-sound': true, 'data-cromo-sound-off': 'Encender el pulso', 'data-cromo-sound-on': 'Apagar el pulso' }, snd2Label);
    const sound = el('section', { class: 'sound', id: 'sonido' }, el('h2', { class: 'u', text: 'Sonido' }),
      el('p', { text: 'Enciende el pulso y el metal líquido late con la pista.' }), el('div', {}, snd2));

    // --- Sets
    const setsSec = buildSets(sets);

    // --- Memorias
    const memoriesSec = buildMemories(memories);

    // --- Journal
    const journalSec = buildJournal(posts);

    // --- Contacto
    const { end, loadChallenge } = buildContact(data.settings?.social);

    // --- Footer
    const cookiePrefs = document.querySelector('.brvtal-cookie-preferences');
    const footer = el('footer', {}, el('span', { text: 'BRVTAL, Pereira, Colombia' }), el('span', { text: 'Rave till grave' }),
      el('a', { href: '/privacy', text: 'Privacidad y mis derechos' }),
      cookiePrefs ? el('button', { type: 'button', text: 'Preferencias de cookies' }) : null);
    const cookieBtn = footer.querySelector('button');
    if (cookieBtn) cookieBtn.addEventListener('click', () => cookiePrefs.click());

    home.append(el('a', { class: 'skip', href: '#noches', text: 'Saltar al contenido' }), header,
      el('main', { id: 'cromo-top' }, heroSec, band, banners, nights, artistsSec, experience, sound, setsSec, memoriesSec, journalSec, connected, end), footer);
    return { home, snd, snd2, nav, loadChallenge, word };
  };

  // Progressive GSAP enhancement: the CMS event link works before/without motion scripts.
  const enhanceNextCard = (home) => {
    const card = home.querySelector('.cr-next-link');
    if (!card || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    Promise.resolve(window.BRVTALRuntimeReady).then(() => {
      const gsap = window.gsap;
      if (!card.isConnected || !gsap || typeof gsap.matchMedia !== 'function'
        || typeof gsap.context !== 'function' || typeof gsap.timeline !== 'function') return;
      const media = gsap.matchMedia();
      media.add('(prefers-reduced-motion: no-preference)', () => {
        const arrow = card.querySelector('.cr-next-arrow');
        const context = gsap.context(() => {
          const intro = gsap.timeline();
          intro.from(card, { opacity: 0, x: 22, y: 12, rotation: -5,
            duration: .7, ease: 'power3.out', clearProps: 'opacity,transform' });
          intro.from(card.querySelector('.cr-next-action'), { opacity: 0, y: 8,
            duration: .25, ease: 'power2.out', clearProps: 'opacity,transform' }, '-=.24');
        }, card);
        const enter = () => { if (arrow) gsap.to(arrow, { x: 7, duration: .2, ease: 'power2.out', overwrite: 'auto' }); };
        const leave = () => { if (arrow) gsap.to(arrow, { x: 0, duration: .2, ease: 'power2.out', overwrite: 'auto' }); };
        card.addEventListener('pointerenter', enter);
        card.addEventListener('pointerleave', leave);
        card.addEventListener('focusin', enter);
        card.addEventListener('focusout', leave);
        return () => {
          // A reduced-motion switch can happen mid-tween: stop the hover
          // animation and reset its position before dropping the context.
          if (arrow) {
            if (typeof gsap.killTweensOf === 'function') gsap.killTweensOf(arrow);
            if (typeof gsap.set === 'function') gsap.set(arrow, { x: 0 });
            else arrow.style.removeProperty('transform');
          }
          card.removeEventListener('pointerenter', enter);
          card.removeEventListener('pointerleave', leave);
          card.removeEventListener('focusin', enter);
          card.removeEventListener('focusout', leave);
          context.revert();
        };
      });
      const onPageHide = (event) => {
        // In bfcache the document resumes with the same context and listeners.
        if (event.persisted) return;
        window.removeEventListener('pagehide', onPageHide);
        media.revert();
      };
      window.addEventListener('pagehide', onPageHide);
    }).catch(() => { /* GSAP is optional; the link and CSS remain usable. */ });
  };

  const mount = (data, slider) => {
    const parts = build(data, slider);
    window.BRVTAL_CROMO_ACCENT = [1, .17, .05];
    // Section titles carry a green glitch layer (same idea as the old Genesis title): the layer
    // copies the text through data-text, so the heading itself stays plain text for assistive tech.
    parts.home.querySelectorAll('h2.cr-u, .cr-end > a.cr-u').forEach((title) => {
      title.dataset.text = text(title.textContent);
      title.classList.add('cr-glitch');
    });
    document.body.appendChild(parts.home);
    root.classList.add('cromo-home');
    enhanceNextCard(parts.home);
    const sound = window.BRVTALCromoSound;
    if (sound && sound.bind) { sound.bind(parts.snd); sound.bind(parts.snd2); }
    // Captcha fetch handles its own errors; initialization is intentionally non-blocking.
    void parts.loadChallenge();

    const links = Array.from(parts.nav.querySelectorAll('a'));
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          links.forEach((a) => a.removeAttribute('aria-current'));
          const link = links.find((a) => a.getAttribute('href') === '#' + entry.target.id);
          if (link) link.setAttribute('aria-current', 'true');
        });
      }, { rootMargin: '-45% 0px -50% 0px' });
      parts.home.querySelectorAll('section[id]').forEach((s) => io.observe(s));
    }
    // Resolve legacy hashes after mounting: an empty archive has no #archivo.
    const legacyTarget = { '#events': 'noches', '#archive': 'archivo' }[window.location.hash];
    if (legacyTarget) {
      // #archivo is only mounted when a published archive event exists.
      const target = legacyTarget === 'archivo' && !document.getElementById('archivo')
        ? 'noches' : legacyTarget;
      const node = document.getElementById(target);
      if (node) {
        window.history.replaceState(null, '', '#' + target);
        // Programmatic focus preserves the keyboard destination for old links.
        if (!node.hasAttribute('tabindex')) node.setAttribute('tabindex', '-1');
        window.requestAnimationFrame(() => {
          node.focus({ preventScroll: true });
          node.scrollIntoView();
        });
        // Fonts/images can shift layout; never steal focus after a user's Tab.
        window.setTimeout(() => {
          if (document.activeElement === node) node.scrollIntoView();
        }, 400);
      }
    }
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (!reduce.matches) {
      let px = .5, py = .5, beat = 0;
      window.addEventListener('pointermove', (e) => { px = e.clientX / window.innerWidth; py = e.clientY / window.innerHeight; }, { passive: true });
      window.addEventListener('cromo:beat', () => { beat = 1; });
      const tick = () => {
        beat *= .88;
        parts.word.style.transform = 'translate(' + ((px - .5) * -40) + 'px,' + ((py - .5) * 30) + 'px) scale(' + (1 + beat * .05) + ')';
        window.requestAnimationFrame(tick);
      };
      window.requestAnimationFrame(tick);
    }
  };

  const start = () => {
    const json = (url) => fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error('http'))));
    // The banners are optional: if their endpoint fails the home still mounts without them.
    const banners = json('/api/hero-slider.php').then((payload) => (payload?.ok === true ? payload.data : null)).catch(() => null);
    Promise.all([json('/api/public.php'), banners])
      .then(([payload, slider]) => { if (payload?.data && !document.getElementById('cromo-home')) mount(payload.data, slider); })
      .catch(() => { /* keep the Concept 05 home */ });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
