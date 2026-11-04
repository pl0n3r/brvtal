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

  const buildSets = (sets) => {
    // --- Sets
    let setsSec = el('section', { class: 'sec', id: 'discos' },
      el('h2', { class: 'u', text: 'Sets' }),
      el('p', { class: 'm', text: 'Los próximos sets aparecerán aquí cuando estén publicados.' }));
    if (sets.length) {
      const [first, ...rest] = sets;
      const firstUrl = safeUrl(first.external_url);
      const featBody = el('div', { style: 'position:relative' }, el('p', { text: 'Último set publicado' }), el('h3', { text: text(first.title) }),
        el('p', { style: 'margin-top:12px', text: [text(first.artist_name), first.event_title ? 'en ' + text(first.event_title) : ''].filter(Boolean).join(', ') + '.' }));
      const listen = el('a', Object.assign({ class: 'btn r', text: 'Escuchar' + (first.platform ? ' en ' + titleCase(text(first.platform)) : ''), href: firstUrl || '/sets/' + encodeURIComponent(first.slug) }, firstUrl ? { target: '_blank', rel: 'noopener noreferrer' } : {}));
      const feat = el('article', { class: 'feat plate chrome-b' }, el('div', { class: 'disc', 'aria-hidden': 'true' }), featBody, el('div', { style: 'position:relative' }, listen));
      const list = el('div', { class: 'sl' }, rest.slice(0, 4).map((s) => {
        const url = safeUrl(s.external_url);
        return el('a', Object.assign({ class: 'plate chrome-b', href: url || '/sets/' + encodeURIComponent(s.slug) }, url ? { target: '_blank', rel: 'noopener noreferrer' } : {}),
          el('span', {}, el('b', { text: text(s.title) }), el('small', { text: text(s.artist_name) })), el('span', { class: 'pl', text: 'Escuchar' }));
      }));
      setsSec = el('section', { class: 'sec', id: 'discos' }, el('h2', { class: 'u', text: 'Sets' }), el('div', { class: 'sets' }, feat, list));
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

  const buildContact = () => {
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
    const end = el('section', { class: 'end', id: 'contacto' }, el('a', { class: 'u', href: '#contacto', text: 'Hablemos' }), form);

    return { end, loadChallenge };
  };

  const buildHero = (next, artists) => {
    // --- Hero
    const word = el('h1', { class: 'u', id: 'word', 'aria-label': 'BRVTAL' }, Array.from('BRVTAL').map((c) => el('span', { 'aria-hidden': 'true', text: c })));
    let nextCard = null;
    if (next) {
      const when = parseDate(next.event_date);
      const days = Math.ceil((new Date(when.getFullYear(), when.getMonth(), when.getDate()) - new Date(new Date().getFullYear(), new Date().getMonth(), new Date().getDate())) / 86400000);
      const k = days <= 0 ? 'Es hoy' : days === 1 ? 'Falta 1 día' : 'Faltan ' + days + ' días';
      const lineup = lineupText(artists);
      const place = [text(next.city), text(next.venue)].filter(Boolean).join(', ');
      nextCard = el('aside', { class: 'next', 'aria-label': 'Próxima noche' },
        el('b', { text: k }), el('span', { text: when.getDate() + ' ' + MONTHS[when.getMonth()] }),
        el('small', { text: text(next.title) + (lineup ? ': ' + lineup : '') + (place ? '. ' + place + '.' : '.') }));
    }
    const hero = el('section', { class: 'hero' },
      el('div', { class: 'core' }, word, el('p', { text: 'Más que fiestas. Una cultura en movimiento.' })),
      nextCard,
      el('div', { class: 'cta' }, el('a', { class: 'btn r', href: '#noches', text: 'Ver la próxima noche' }), el('a', { class: 'btn', href: '#sonido', text: 'Escuchar' })));
    const bandItems = ['Rave till grave', 'Pereira, Colombia', 'Más que fiestas'];
    const band = el('div', { class: 'band', 'aria-hidden': 'true' }, el('div', {}, [...bandItems, ...bandItems].map((t) => el('span', { text: t }))));

    return { word, hero, band };
  };

  const buildNights = (upcoming, next, artists) => {
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

    return nights;
  };

  const buildArtists = (artists) => {
    // --- Artistas
    const roster = [...artists].sort((a, b) => (b.is_collective_member ? 1 : 0) - (a.is_collective_member ? 1 : 0)).slice(0, 5);
    const artistsGrid = el('div', { class: 'artists' }, roster.map((a) => el('a', { class: 'ac plate chrome-b', href: '/artists/' + encodeURIComponent(a.slug) },
      el('i', { class: 'orb', 'aria-hidden': 'true' }), el('span', { class: 'nm', text: text(a.name) }),
      el('span', { class: 'rl', text: (a.is_collective_member ? 'Colectivo. ' : 'Colaborador. ') + text(a.bio, 90) }))));
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
    const ids = (((relations.events || {})[next.id] || {}).artists || []).map(Number);
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

  const EDGES = [
    ['event_artist', 'Eventos', 'Artistas'], ['event_set', 'Eventos', 'Sonido'], ['artist_set', 'Artistas', 'Sonido'],
    ['artist_release', 'Artistas', 'Discos'], ['event_memory', 'Eventos', 'Memorias'], ['artist_memory', 'Artistas', 'Memorias'],
    ['set_memory', 'Sonido', 'Memorias'], ['release_memory', 'Discos', 'Memorias'],
  ];
  // What each kind of link means, in plain words (shown under every relation).
  const NOTE = {
    event_artist: 'Artistas que tocan en cada noche', event_set: 'Sets grabados en cada noche',
    artist_set: 'Sets de cada artista', artist_release: 'Discos de cada artista',
    event_memory: 'Memorias de cada noche', artist_memory: 'Memorias donde aparece cada artista',
    set_memory: 'Memorias de cada set', release_memory: 'Memorias de cada disco',
  };
  const SECTION = { Eventos: '#noches', Artistas: '#artistas', Sonido: '#sonido', Discos: '#discos', Memorias: '#memorias' };

  // Motion timing is read from the shared tokens (css/public-cromo-home.css), never hardcoded here.
  const ms = (name) => parseFloat(getComputedStyle(document.getElementById('cromo-home') || root).getPropertyValue(name)) || 0;
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
    void node.offsetWidth; // force reflow so every transition runs
    node.classList.add('is-swapping');
    node.style.width = w1 + 'px';
    node._softTimer = setTimeout(() => {
      node.classList.remove('is-swapping');
      node.style.removeProperty('width');
      node.textContent = next;
    }, Math.max(ms('--text-soft-dur'), ms('--text-soft-resize-dur')) + 40);
  };

  // The real items behind each category, so choosing "Artistas" shows the artists themselves.
  const itemsOf = (name, data) => {
    const list = (key) => (Array.isArray(data[key]) ? data[key] : []);
    const link = (route, slug) => (slug ? '/' + route + '/' + encodeURIComponent(slug) : '');
    switch (name) {
      case 'Eventos':
        return list('events').map((e) => {
          const when = parseDate(e.event_date);
          return { label: text(e.title, 60), sub: [when ? two(when.getDate()) + ' ' + MONTHS[when.getMonth()] + ' ' + when.getFullYear() : '', text(e.city)].filter(Boolean).join(' · '), href: link('events', e.slug) };
        });
      case 'Artistas':
        return list('artists').map((a) => ({ label: text(a.name, 60), sub: a.is_collective_member ? 'Colectivo' : 'Colaborador', href: link('artists', a.slug) }));
      case 'Sonido':
        return list('sets').map((x) => ({ label: text(x.title, 60), sub: text(x.artist_name || x.platform, 40), href: link('sets', x.slug) }));
      case 'Discos':
        return list('releases').map((r) => ({ label: text(r.title, 60), sub: text(r.release_type, 30), href: link('releases', r.slug) }));
      case 'Memorias':
        return list('memories').map((m) => ({ label: text(m.title, 60), sub: text(m.context, 50), href: '#memorias' }));
      default:
        return [];
    }
  };

  // Fixed layouts (viewBox units): the five categories on an ellipse, one for wide and one for narrow screens.
  const GRAPH = {
    wide: { w: 1000, h: 560, cx: 500, cy: 292, rx: 290, ry: 165, scale: 1, outward: true },
    tall: { w: 600, h: 700, cx: 300, cy: 360, rx: 170, ry: 235, scale: .74, outward: false },
  };
  const SVG_NS = 'http://www.w3.org/2000/svg';
  const svgNode = (tag, attrs) => {
    const node = document.createElementNS(SVG_NS, tag);
    Object.entries(attrs || {}).forEach(([key, value]) => node.setAttribute(key, String(value)));
    return node;
  };

  const buildConnected = (data) => {
    // --- Conectado: a relationship graph. Node size = how many items the category has; edge width =
    // how many verified links join two categories. Only counts the CMS certifies are drawn; nothing is
    // invented. The side panel is the text/table view of the same numbers and the way to act on them.
    const raw = ((data.relations || {}).counts) || {};
    const count = (key) => (Number.isSafeInteger(raw[key]) && raw[key] > 0 ? raw[key] : 0);
    const len = (list) => (Array.isArray(list) ? list.length : 0);
    const nodes = [['Eventos', len(data.events)], ['Artistas', len(data.artists)], ['Sonido', len(data.sets)],
      ['Discos', len(data.releases)], ['Memorias', len(data.memories)]];
    const edges = EDGES.map(([key, a, b]) => [a, b, count(key), key]).filter((e) => e[2] > 0);
    const sumOf = (list) => list.reduce((sum, e) => sum + e[2], 0);
    const plural = (n) => n + (n === 1 ? ' vínculo' : ' vínculos');

    let selected = null;
    let hovered = null;
    let layoutKey = null;
    const narrow = window.matchMedia('(max-width: 700px)');
    const stage = el('div', { class: 'cn-stage plate chrome-b', role: 'group',
      'aria-label': 'Grafo de relaciones del archivo: elige una categoría para ver con qué se conecta' });
    const registry = { nodes: new Map(), edges: [] };

    const geometry = (layout) => {
      const pos = new Map();
      nodes.forEach(([name, n], i) => {
        const angle = (-90 + 72 * i) * Math.PI / 180;
        const radius = (56 + 10 * Math.sqrt(n)) * layout.scale;
        pos.set(name, { x: layout.cx + layout.rx * Math.cos(angle), y: layout.cy + layout.ry * Math.sin(angle), r: radius, n, ox: layout.outward ? Math.cos(angle) : 0, oy: layout.outward ? Math.sin(angle) : (i === 0 ? -1 : 1) });
      });
      return pos;
    };

    const focusName = () => hovered || selected;
    const paint = () => {
      const focus = focusName();
      registry.nodes.forEach((node, name) => {
        const linked = focus && name !== focus && edges.some((e) => (e[0] === focus && e[1] === name) || (e[1] === focus && e[0] === name));
        node.setAttribute('aria-pressed', String(name === selected));
        node.classList.toggle('cr-is-focus', name === focus);
        node.classList.toggle('cr-is-linked', Boolean(linked));
        node.classList.toggle('cr-is-dim', Boolean(focus) && name !== focus && !linked);
      });
      registry.edges.forEach(({ group, a, b }) => {
        const on = focus && (a === focus || b === focus);
        group.classList.toggle('cr-is-on', Boolean(on));
        group.classList.toggle('cr-is-dim', Boolean(focus) && !on);
      });
    };

    const place = (key) => {
      layoutKey = key;
      const layout = GRAPH[key];
      const pos = geometry(layout);
      const svg = svgNode('svg', { viewBox: '0 0 ' + layout.w + ' ' + layout.h, class: 'cr-cn-svg', 'aria-hidden': 'true', focusable: 'false' });
      registry.edges = [];
      registry.nodes.clear();
      edges.forEach(([a, b, n, relation], i) => {
        const from = pos.get(a);
        const to = pos.get(b);
        const group = svgNode('g', { class: 'cr-cn-edge-g', style: '--i:' + i });
        const width = (3 + 2.2 * n) * layout.scale;
        group.append(
          svgNode('line', { x1: from.x, y1: from.y, x2: to.x, y2: to.y, class: 'cr-cn-line', 'stroke-width': width, pathLength: 1 }),
          svgNode('line', { x1: from.x, y1: from.y, x2: to.x, y2: to.y, class: 'cr-cn-hit', 'stroke-width': Math.max(30, width + 14) }));
        // The count tag sits on the line, moved along it until it clears every circle and name plate.
        const clears = (x, y) => [...pos.values()].every((q) => {
          const labelX = q.x + q.ox * (q.r + 18 * layout.scale);
          const labelY = q.y + q.oy * (q.r + 18 * layout.scale);
          const inCircle = Math.hypot(x - q.x, y - q.y) < q.r + 26 * layout.scale;
          const inLabel = Math.abs(x - labelX) < 70 * layout.scale && Math.abs(y - labelY) < 30 * layout.scale;
          return !inCircle && !inLabel;
        });
        const t = [.5, .42, .58, .35, .65, .3, .7].find((value) => clears(from.x + (to.x - from.x) * value, from.y + (to.y - from.y) * value)) || .5;
        const mx = from.x + (to.x - from.x) * t;
        const my = from.y + (to.y - from.y) * t;
        const tag = svgNode('g', { class: 'cr-cn-tag', transform: 'translate(' + mx + ' ' + my + ')' });
        tag.append(svgNode('rect', { x: -20 * layout.scale, y: -15 * layout.scale, width: 40 * layout.scale, height: 30 * layout.scale, class: 'cr-cn-tag-bg' }));
        const label = svgNode('text', { class: 'cr-cn-tag-t', 'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': 24 * layout.scale });
        label.textContent = String(n);
        tag.append(label);
        const title = svgNode('title', {});
        title.textContent = a + ' ↔ ' + b + ': ' + plural(n) + '. ' + NOTE[relation];
        group.append(tag, title);
        group.addEventListener('pointerenter', () => { hovered = null; });
        svg.append(group);
        registry.edges.push({ group, a, b });
      });

      const holder = el('div', { class: 'cn-nodes' });
      nodes.forEach(([name, n]) => {
        const p = pos.get(name);
        const button = el('button', { class: 'cn-node', type: 'button', 'aria-pressed': 'false', 'data-node': name,
          style: '--px:' + (p.x / layout.w * 100).toFixed(3) + ';--py:' + (p.y / layout.h * 100).toFixed(3) + ';--d:' + (p.r * 2 / layout.w * 100).toFixed(3) + ';--ox:' + p.ox.toFixed(3) + ';--oy:' + p.oy.toFixed(3) + ';--i:' + registry.nodes.size,
          'aria-label': name + ': ' + n + (n === 1 ? ' elemento' : ' elementos') + '. Ver sus conexiones' },
          el('span', { class: 'cn-n u', text: String(n) }), el('span', { class: 'cn-name', text: name }));
        button.addEventListener('pointerenter', () => { hovered = name; paint(); });
        button.addEventListener('pointerleave', () => { hovered = null; paint(); });
        button.addEventListener('focus', () => { hovered = name; paint(); });
        button.addEventListener('blur', () => { hovered = null; paint(); });
        button.addEventListener('click', () => choose(name));
        registry.nodes.set(name, button);
        holder.append(button);
      });
      stage.replaceChildren(svg, holder);
      stage.style.setProperty('--ar', layout.w + ' / ' + layout.h);
      paint();
    };

    const heading = el('p', { class: 'cn-sel u' });
    const headingText = document.createElement('span');
    headingText.className = 't-text-soft';
    headingText.textContent = 'Todo el archivo';
    heading.append(headingText);
    const lead = el('p', { class: 'cn-lead' });
    const leadText = document.createElement('span');
    leadText.className = 't-text-soft';
    lead.append(leadText);
    const items = el('ul', { class: 'cn-items' });
    const relHead = el('p', { class: 'cn-h u', text: 'Se conecta con', hidden: true });
    const list = el('ul', { class: 'cn-edges', 'aria-live': 'polite' });
    const totalNode = el('p', { class: 'cn-total' });
    const totalText = document.createElement('span');
    totalText.className = 't-text-soft';
    totalNode.append(totalText);
    const go = el('a', { class: 'btn', href: '#memorias', text: 'Explorar el archivo' });
    const reset = el('button', { class: 'btn r', type: 'button', text: 'Ver todo', hidden: true });

    const visible = () => (selected ? edges.filter((e) => e[0] === selected || e[1] === selected) : edges);
    const leadLabel = () => {
      if (!selected) return 'Cada línea une dos categorías y su grosor es el número de vínculos. Elige un círculo para ver sus elementos y con qué se conecta.';
      const n = (nodes.find((x) => x[0] === selected) || [0, 0])[1];
      return n ? n + (n === 1 ? ' elemento publicado.' : ' elementos publicados.') : 'Todavía no hay elementos publicados.';
    };
    const totalLabel = () => {
      const shown = visible();
      if (!shown.length) return selected ? selected + ' todavía no tiene vínculos publicados.' : 'Sin vínculos publicados aún.';
      return selected ? plural(sumOf(shown)) + ' de ' + selected + ' con otras categorías.' : sumOf(shown) + ' vínculos verificados en el archivo público.';
    };
    const rows = () => {
      const shown = visible();
      if (!shown.length) return [el('li', { class: 'cn-empty', text: 'Todavía no hay relaciones publicadas.' })];
      return shown.map(([a, b, n, key], i) => {
        const other = selected ? (a === selected ? b : a) : b;
        const main = selected ? other : a + ' y ' + b;
        return el('li', { style: '--i:' + i }, el('a', { class: 'cn-edge', href: SECTION[other], 'aria-label': main + ': ' + plural(n) + '. ' + NOTE[key] + '. Ir a ' + other },
          el('span', { class: 'cn-e-t' }, el('strong', { text: main }), el('small', { text: NOTE[key] })),
          el('b', {}, el('span', { class: 'u', text: String(n) }), el('em', { text: n === 1 ? 'vínculo' : 'vínculos' })),
          el('i', { 'aria-hidden': 'true', text: 'Ver' })));
      });
    };
    const itemNodes = () => {
      if (!selected) return [];
      const all = itemsOf(selected, data).filter((item) => item.label);
      const shown = all.slice(0, 12).map((item, i) => el('li', { style: '--i:' + i },
        el(item.href ? 'a' : 'span', { class: 'cn-item', href: item.href || false },
          el('strong', { text: item.label }), item.sub ? el('small', { text: item.sub }) : null)));
      if (all.length > 12) shown.push(el('li', {}, el('a', { class: 'cn-item cn-more', href: SECTION[selected], text: 'y ' + (all.length - 12) + ' más' })));
      return shown;
    };
    const paintList = (animate) => {
      const swap = () => {
        items.replaceChildren(...itemNodes());
        list.replaceChildren(...rows());
        relHead.hidden = !selected || !visible().length;
        list.classList.remove('cr-is-leaving');
        items.classList.remove('cr-is-leaving');
      };
      if (!animate || reduced()) { swap(); return; }
      list.classList.add('cr-is-leaving');
      items.classList.add('cr-is-leaving');
      setTimeout(swap, ms('--duration-quick'));
    };

    const choose = (name) => {
      selected = selected === name ? null : name;
      paint();
      softSwap(headingText, selected || 'Todo el archivo');
      softSwap(leadText, leadLabel());
      softSwap(totalText, totalLabel());
      go.textContent = selected ? 'Ir a ' + selected : 'Explorar el archivo';
      go.setAttribute('href', selected ? SECTION[selected] : '#memorias');
      reset.hidden = !selected;
      paintList(true);
    };
    reset.addEventListener('click', () => choose(selected));

    leadText.textContent = leadLabel();
    leadText.dataset.text = leadText.textContent;
    totalText.textContent = totalLabel();
    totalText.dataset.text = totalText.textContent;
    headingText.dataset.text = 'Todo el archivo';
    paintList(false);
    place(narrow.matches ? 'tall' : 'wide');
    narrow.addEventListener('change', () => { const key = narrow.matches ? 'tall' : 'wide'; if (key !== layoutKey) place(key); });

    // One authored moment: the lines draw in and the circles settle when the graph first scrolls into view.
    const reveal = () => stage.classList.add('cr-is-in');
    if (reduced() || !('IntersectionObserver' in window)) reveal();
    else {
      const io = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) { reveal(); io.disconnect(); }
      }, { threshold: .25 });
      io.observe(stage);
    }

    const section = el('section', { class: 'sec', id: 'conectado' }, el('h2', { class: 'u', text: 'Conectado' }),
      el('div', { class: 'cn' }, stage,
        el('div', { class: 'cn-side plate chrome-b' }, heading, lead, items, relHead, list, totalNode, el('div', { class: 'cn-actions' }, go, reset))));
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
      return el('div', { class: cls, style: vars, text: text(layer.text, 220) });
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
        mediaNode(slide, i === 0), el('div', { class: 'bn-ov' }), layers.length ? layers : legacyCopy(slide));
    });
    // The class helper prefixes everything with cr-, including state classes.
    nodes.forEach((node, i) => { node.classList.remove('is-active'); if (i === 0) node.classList.add('cr-is-active'); });

    const stage = el('div', { class: 'bn-stage chrome-b' }, nodes);
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
    const controls = el('div', { class: 'bn-ctl' }, prev, el('div', { class: 'bn-dots' }, dots), next, playPause);
    stage.append(controls);

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
      if (incoming) { const played = incoming.play(); if (played && played.catch) played.catch(() => {}); }
      dots[index].setAttribute('aria-current', 'true');
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
      schedule();
    });
    stage.addEventListener('mouseenter', () => clearTimeout(timer));
    stage.addEventListener('mouseleave', schedule);
    stage.addEventListener('focusin', () => clearTimeout(timer));
    stage.addEventListener('focusout', (event) => { if (!stage.contains(event.relatedTarget)) schedule(); });
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
    const { word, hero: heroSec, band } = buildHero(next, artists);

    // --- Noches
    const nights = buildNights(upcoming, next, artists);
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
    const { end, loadChallenge } = buildContact();

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

  const mount = (data, slider) => {
    const parts = build(data, slider);
    window.BRVTAL_CROMO_ACCENT = [1, .17, .05];
    // Section titles carry a green glitch layer (same idea as the old Genesis title): the layer
    // copies the text through data-text, so the heading itself stays plain text for assistive tech.
    parts.home.querySelectorAll('h2.cr-u, .cr-end > a.cr-u').forEach((title) => {
      title.setAttribute('data-text', text(title.textContent));
      title.classList.add('cr-glitch');
    });
    document.body.appendChild(parts.home);
    root.classList.add('cromo-home');
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
    // Preserve legacy archive deep links without showing the archive on the new home.
    if (['#events', '#archive'].includes(window.location.hash)) return;
    const json = (url) => fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error('http'))));
    // The banners are optional: if their endpoint fails the home still mounts without them.
    const banners = json('/api/hero-slider.php').then((payload) => (payload && payload.ok === true ? payload.data : null)).catch(() => null);
    Promise.all([json('/api/public.php'), banners])
      .then(([payload, slider]) => { if (payload && payload.data && !document.getElementById('cromo-home')) mount(payload.data, slider); })
      .catch(() => { /* keep the Concept 05 home */ });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
