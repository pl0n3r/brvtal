(() => {
  'use strict';

  const PREF_ENDPOINT = '/api/admin-grid-preferences.php';
  const instances = new WeakMap();
  const selections = new Map();
  const preferences = new Map();

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[ch]));

  function imgSrc(value) {
    const raw = String(value ?? '').trim();
    if (!raw) return '';
    if (/^(?:https?:)?\/\//i.test(raw) || raw.startsWith('/')) return raw;
    return '/' + raw.replace(/^\.?\//,'').replace(/^\/+/, '');
  }

  function imageCell(src, title, meta = '') {
    const url = imgSrc(src);
    const visual = url
      ? `<img class="admin-grid-thumb" src="${esc(url)}" alt="${esc(title || '')}" loading="lazy">`
      : '<span class="admin-grid-thumb admin-grid-thumb-ph">NO IMG</span>';
    return `<div class="admin-grid-primary">${visual}<span><strong class="title">${esc(title || 'Untitled')}</strong><small>${esc(meta || '')}</small></span></div>`;
  }

  function statusCell(value) {
    const status = String(value || '—');
    return `<span class="admin-grid-status ${esc(status.toLowerCase())}">${esc(status)}</span>`;
  }

  function linksCell(row) {
    const links = [];
    if (row.instagram_url) links.push('INSTAGRAM');
    if (row.soundcloud_url) links.push('SOUNDCLOUD');
    if (row.website_url) links.push('WEBSITE');
    return esc(links.join(' · ') || '—');
  }

  function membershipCell(row) {
    return statusCell(Number(row.is_collective_member || 0) === 1 ? 'MEMBER' : 'EXTERNAL');
  }

  function artistsLabel(row) {
    const names = Array.isArray(row.artists) ? row.artists.map(item => item?.name).filter(Boolean) : [];
    return names.join(' · ') || 'NO ARTIST LINKED';
  }

  function bytes(value) {
    const n = Number(value || 0);
    if (!Number.isFinite(n) || n <= 0) return '—';
    const units = ['B','KB','MB','GB'];
    let size = n, unit = 0;
    while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit += 1; }
    let rendered;
    if (unit === 0) rendered = Math.round(size);
    else rendered = size.toFixed(size >= 10 ? 0 : 1);
    return rendered + ' ' + units[unit];
  }

  const SPECS = {
    events: {
      columns:[
        {key:'primary',label:'EVENT',width:'minmax(230px,1.6fr)',sort:true,value:r=>r.title||'',render:r=>imageCell(r.cover_image,r.title,r.slug)},
        {key:'date',label:'DATE',width:'minmax(150px,.9fr)',sort:true,value:r=>r.event_date||'',render:r=>esc(r.event_date||'—')},
        {key:'location',label:'LOCATION',width:'minmax(150px,.9fr)',sort:true,value:r=>r.city||r.venue||'',render:r=>esc(r.city||r.venue||'—')},
        {key:'status',label:'STATUS',width:'minmax(110px,.65fr)',sort:true,value:r=>r.status||'',render:r=>statusCell(r.status)}
      ]
    },
    artists: {
      orderable:true,
      columns:[
        {key:'primary',label:'ARTIST',width:'minmax(230px,1.6fr)',sort:true,value:r=>r.name||'',render:r=>imageCell(r.photo,r.name,r.slug)},
        {key:'links',label:'LINKS',width:'minmax(150px,.8fr)',sort:false,value:r=>linksCell(r),render:r=>linksCell(r)},
        {key:'collective',label:'BRVTAL',width:'120px',sort:true,numeric:true,value:r=>Number(r.is_collective_member||0),render:r=>membershipCell(r)},
        {key:'status',label:'STATUS',width:'minmax(110px,.65fr)',sort:true,value:r=>r.status||'',render:r=>statusCell(r.status)},
        {key:'position',label:'POSITION',width:'90px',sort:false,value:r=>Number(r.sort_order||0),render:()=>'<span data-order-position>—</span>'}
      ]
    },
    releases: {
      orderable:true,
      columns:[
        {key:'primary',label:'RELEASE',width:'minmax(240px,1.7fr)',sort:true,value:r=>r.title||'',render:r=>imageCell(r.artwork,r.title,[r.catalog_number,String(r.release_type||'').toUpperCase()].filter(Boolean).join(' · '))},
        {key:'artists',label:'ARTISTS',width:'minmax(170px,1fr)',sort:true,value:r=>artistsLabel(r),render:r=>esc(artistsLabel(r))},
        {key:'type',label:'TYPE',width:'110px',sort:true,value:r=>r.release_type||'',render:r=>esc(String(r.release_type||'—').toUpperCase())},
        {key:'date',label:'RELEASE DATE',width:'140px',sort:true,value:r=>r.release_date||'',render:r=>esc(r.release_date||'DATE TBD')},
        {key:'status',label:'STATUS',width:'120px',sort:true,value:r=>r.status||'',render:r=>statusCell(r.status)},
        {key:'position',label:'POSITION',width:'90px',sort:false,value:r=>Number(r.sort_order||0),render:()=>'<span data-order-position>—</span>'}
      ]
    },
    sets: {
      orderable:true,
      columns:[
        {key:'primary',label:'SET',width:'minmax(230px,1.5fr)',sort:true,value:r=>r.title||'',render:r=>imageCell(r.cover_image,r.title,r.slug)},
        {key:'artist',label:'ARTIST',width:'minmax(150px,.8fr)',sort:true,value:r=>r.artist_name||r.artist_id||'',render:r=>esc(r.artist_name||r.artist_id||'—')},
        {key:'event',label:'EVENT',width:'minmax(160px,.9fr)',sort:true,value:r=>r.event_title||r.event_id||'',render:r=>esc(r.event_title||r.event_id||'—')},
        {key:'status',label:'STATUS',width:'110px',sort:true,value:r=>r.status||'',render:r=>statusCell(r.status)},
        {key:'position',label:'POSITION',width:'90px',sort:false,value:r=>Number(r.sort_order||0),render:()=>'<span data-order-position>—</span>'}
      ]
    },
    media: {
      columns:[
        {key:'primary',label:'MEDIA',width:'minmax(260px,1.6fr)',sort:true,value:r=>r.title||'',render:r=>imageCell(r.type==='image'?r.file_path:'',r.title,r.type==='image'?r.file_path:String(r.type||'FILE').toUpperCase())},
        {key:'type',label:'TYPE',width:'110px',sort:true,value:r=>r.type||'',render:r=>esc(String(r.type||'—').toUpperCase())},
        {key:'mime',label:'MIME',width:'minmax(150px,.8fr)',sort:true,value:r=>r.mime_type||'',render:r=>esc(r.mime_type||'—')},
        {key:'size',label:'SIZE',width:'100px',sort:true,numeric:true,value:r=>Number(r.file_size||0),render:r=>esc(bytes(r.file_size))},
        {key:'status',label:'STATUS',width:'110px',sort:true,value:r=>r.status||'',render:r=>statusCell(r.status)}
      ]
    },
    pages: {
      columns:[
        {key:'primary',label:'PAGE',width:'minmax(250px,1.7fr)',sort:true,value:r=>r.title||'',render:r=>imageCell('',r.title,r.slug)},
        {key:'locale',label:'LOCALE',width:'100px',sort:true,value:r=>r.locale||'',render:r=>esc(String(r.locale||'—').toUpperCase())},
        {key:'status',label:'STATUS',width:'120px',sort:true,value:r=>r.status||'',render:r=>statusCell(r.status)}
      ]
    },
    blog: {
      orderable:true,
      columns:[
        {key:'primary',label:'POST',width:'minmax(240px,1.5fr)',sort:true,value:r=>r.title||'',render:r=>imageCell(r.cover_image,r.title,'/'+String(r.slug||''))},
        {key:'excerpt',label:'EXCERPT',width:'minmax(220px,1.4fr)',sort:true,value:r=>r.excerpt||'',render:r=>esc(r.excerpt||'NO EXCERPT')},
        {key:'published',label:'PUBLISHED',width:'160px',sort:true,value:r=>r.published_at||r.updated_at||'',render:r=>esc(r.published_at||'NOT YET')},
        {key:'status',label:'STATUS',width:'120px',sort:true,value:r=>r.status||'',render:r=>statusCell(r.status)},
        {key:'position',label:'POSITION',width:'90px',sort:false,value:r=>Number(r.sort_order||0),render:()=>'<span data-order-position>—</span>'}
      ]
    }
  };

  function actionsFor(module) {
    if (module === 'events') return [
      ['lineup','LINEUP'],['edit','EDIT'],['delete','DELETE']
    ];
    if (['artists','sets','pages'].includes(module)) return [['edit','EDIT'],['delete','DELETE']];
    if (module === 'releases') return [['edit','EDIT'],['delete','DELETE']];
    if (module === 'blog') return [['edit','EDIT'],['delete','DELETE']];
    if (module === 'media') return [['details','DETAILS']];
    return [];
  }

  const ACTION_HANDLERS = {
    events: {
      lineup:id => window.openLineup?.(id),
      edit:id => window.openModal?.('events',id),
      delete:id => window.del?.('events',id)
    },
    artists: {
      edit:id => window.openModal?.('artists',id),
      delete:id => window.del?.('artists',id)
    },
    sets: {
      edit:id => window.openModal?.('sets',id),
      delete:id => window.del?.('sets',id)
    },
    pages: {
      edit:id => window.openModal?.('pages',id),
      delete:id => window.del?.('pages',id)
    },
    releases: {
      edit:id => window.BRVTALReleases?.openEditor?.(id),
      delete:id => window.BRVTALReleases?.remove?.(id)
    },
    blog: {
      edit:id => window.BRVTALBlog?.openEditor?.(id),
      delete:id => window.BRVTALBlog?.remove?.(id)
    },
    media: {
      details:id => window.BRVTALMediaLibrary?.select?.(id,{reveal:true})
    }
  };

  function runAction(module, action, id) {
    return ACTION_HANDLERS[module]?.[action]?.(id);
  }

  function selection(module) {
    if (!selections.has(module)) selections.set(module,new Set());
    return selections.get(module);
  }

  function defaultColumns(module) {
    return SPECS[module].columns.map(column => column.key);
  }

  function normalizedVisible(module, requested) {
    const defaults = defaultColumns(module);
    const values = Array.isArray(requested) ? requested.filter(key => defaults.includes(key)) : defaults;
    const unique = [...new Set(values)];
    return unique.length ? unique : [defaults[0]];
  }

  async function csrfToken() {
    if (window.BRVTALAdminAuthBoundary?.csrfToken) {
      return window.BRVTALAdminAuthBoundary.csrfToken();
    }
    try { if (window.csrf) return window.csrf; } catch (_) {}
    throw new Error('AUTH_REQUIRED');
  }

  async function loadPreferences(module) {
    if (!preferences.has(module)) {
      preferences.set(module,(async () => {
        const response = await fetch(PREF_ENDPOINT + '?module=' + encodeURIComponent(module),{
          credentials:'same-origin',cache:'no-store'
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok === false) throw new Error(payload.error || 'GRID_PREFERENCES_LOAD_FAILED');
        return normalizedVisible(module,payload.data?.columns);
      })().catch(() => defaultColumns(module)));
    }
    return preferences.get(module);
  }

  async function savePreferences(module, columns) {
    const token = await csrfToken();
    const response = await fetch(PREF_ENDPOINT + '?module=' + encodeURIComponent(module),{
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':token},
      body:JSON.stringify({columns})
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.error || 'GRID_PREFERENCES_SAVE_FAILED');
    const normalized = normalizedVisible(module,payload.data?.columns);
    preferences.set(module,Promise.resolve(normalized));
    return normalized;
  }

  function ensureInstance(module, host) {
    let state = instances.get(host);
    if (!state || state.module !== module) {
      state = {
        module,host,rows:[],allRows:[],options:{},
        visible:new Set(defaultColumns(module)),
        preferencesLoaded:false,preferencesLoading:false,preferencePromise:null,
        sortKey:null,sortDirection:null,chooserOpen:false,membershipFilter:'all',pendingFocusSelector:null,preferenceRevision:0
      };
      instances.set(host,state);
    }
    return state;
  }

  function compare(a,b,column) {
    const av = column.value(a), bv = column.value(b);
    if (column.numeric) return Number(av||0) - Number(bv||0);
    return String(av ?? '').localeCompare(String(bv ?? ''),undefined,{numeric:true,sensitivity:'base'});
  }

  function filteredRows(state) {
    if (state.module !== 'artists' || state.membershipFilter === 'all') return state.rows.slice();
    const expected = state.membershipFilter === 'member' ? 1 : 0;
    return state.rows.filter(row => Number(row.is_collective_member || 0) === expected);
  }

  function sortedRows(state) {
    const rows = filteredRows(state);
    if (!state.sortKey || !state.sortDirection) return rows;
    const column = SPECS[state.module].columns.find(item => item.key === state.sortKey);
    if (!column) return rows;
    const sign = state.sortDirection === 'asc' ? 1 : -1;
    return rows.sort((a,b) => {
      const primary = compare(a,b,column);
      if (primary !== 0) return primary * sign;
      const aid = Number(a.id), bid = Number(b.id);
      if (Number.isFinite(aid) && Number.isFinite(bid)) return aid - bid;
      return String(a.id ?? '').localeCompare(String(b.id ?? ''),undefined,{numeric:true});
    });
  }

  function sortLabel(state,column) {
    if (state.sortKey !== column.key || !state.sortDirection) return '';
    return state.sortDirection === 'asc' ? '↑' : '↓';
  }

  function ariaSort(state,column) {
    if (state.sortKey !== column.key || !state.sortDirection) return 'none';
    return state.sortDirection === 'asc' ? 'ascending' : 'descending';
  }

  function renderActions(module,id) {
    return actionsFor(module).map(([action,label]) =>
      `<button type="button" class="iconbtn admin-grid-action" data-grid-action="${esc(action)}" data-grid-id="${Number(id)}">${esc(label)}</button>`
    ).join('');
  }

  function gridTemplate(columns) {
    return ['42px',...columns.map(column => column.width || 'minmax(120px,1fr)'),'minmax(150px,auto)'].join(' ');
  }

  function renderChooser(state) {
    const spec = SPECS[state.module];
    const visibility = state.chooserOpen ? '' : ' hidden';
    const choices = spec.columns.map(column => {
      const checked = state.visible.has(column.key) ? 'checked' : '';
      return `<label><input type="checkbox" data-grid-column="${esc(column.key)}" ${checked}><span>${esc(column.label)}</span></label>`;
    }).join('');
    return `<div class="admin-grid-column-panel"${visibility} data-grid-column-panel>
      <div class="admin-grid-column-panel-head"><strong>COLUMNS / VIEW</strong><button type="button" data-grid-columns-close aria-label="Close column chooser">×</button></div>
      ${choices}
      <button type="button" class="btn ghost admin-grid-reset-columns" data-grid-columns-reset>RESTORE DEFAULTS</button>
    </div>`;
  }

  function sortHeaderMarkup(state, column) {
    if (!column.sort) {
      return `<span class="admin-grid-head-label">${esc(column.label)}</span>`;
    }
    return `<button type="button" class="admin-grid-sort" data-grid-sort="${esc(column.key)}"><span>${esc(column.label)}</span><span aria-hidden="true">${sortLabel(state,column)}</span></button>`;
  }

  function headerCellsMarkup(state, visibleColumns) {
    return visibleColumns.map(column =>
      `<div role="columnheader" aria-sort="${ariaSort(state,column)}" data-grid-column-key="${esc(column.key)}">${sortHeaderMarkup(state,column)}</div>`
    ).join('');
  }

  function orderAttributes(spec, state, orderEnabled, id) {
    if (!spec.orderable) return '';
    if (id === undefined) {
      return `data-order-resource="${esc(state.module)}" data-order-enabled="${orderEnabled ? '1' : '0'}"`;
    }
    return `data-order-id="${Number(id)}"`;
  }

  function rowMarkup(state, spec, row, visibleColumns, template, selected) {
    const id = Number(row.id);
    const checked = selected.has(id);
    const checkedAttribute = checked ? 'checked' : '';
    const selectedClass = checked ? ' selected' : '';
    const cells = visibleColumns.map(column =>
      `<div role="cell" class="admin-grid-cell admin-grid-cell-${esc(column.key)}" data-grid-cell="${esc(column.key)}">${column.render(row)}</div>`
    ).join('');
    return `<div class="admin-data-grid-row${selectedClass}" role="row" data-grid-row-id="${id}" ${orderAttributes(spec,state,false,id)} style="--admin-grid-template:${esc(template)}">
      <div role="cell" class="admin-grid-select-cell"><input type="checkbox" data-grid-select="${id}" aria-label="Select record ${id}" ${checkedAttribute}></div>
      ${cells}
      <div role="cell" class="admin-grid-row-actions">${renderActions(state.module,id)}</div>
    </div>`;
  }

  function rowsMarkup(state, spec, rows, visibleColumns, template, selected) {
    if (!rows.length) return '<div class="admin-grid-empty">NO RECORDS MATCH THIS VIEW.</div>';
    return rows.map(row => rowMarkup(state,spec,row,visibleColumns,template,selected)).join('');
  }

  function bindSelection(state, selected, visibleIds, allVisibleSelected) {
    const selectAll = state.host.querySelector('[data-grid-select-all]');
    if (selectAll) {
      selectAll.indeterminate = !allVisibleSelected && visibleIds.some(id => selected.has(id));
      selectAll.addEventListener('change',() => {
        state.pendingFocusSelector = '[data-grid-select-all]';
        const shouldSelect = selectAll.checked;
        visibleIds.forEach(id => shouldSelect ? selected.add(id) : selected.delete(id));
        draw(state);
      });
    }
    state.host.querySelectorAll('[data-grid-select]').forEach(input => {
      input.addEventListener('change',() => {
        const id = Number(input.dataset.gridSelect);
        state.pendingFocusSelector = '[data-grid-select="' + id + '"]';
        if (input.checked) selected.add(id);
        else selected.delete(id);
        draw(state);
      });
    });
  }

  function bindSorting(state) {
    state.host.querySelectorAll('[data-grid-sort]').forEach(button => {
      button.addEventListener('click',() => {
        const key = button.dataset.gridSort;
        state.pendingFocusSelector = '[data-grid-sort="' + key + '"]';
        if (state.sortKey !== key) {
          state.sortKey = key;
          state.sortDirection = 'asc';
        } else if (state.sortDirection === 'asc') {
          state.sortDirection = 'desc';
        } else if (state.sortDirection === 'desc') {
          state.sortKey = null;
          state.sortDirection = null;
        } else {
          state.sortDirection = 'asc';
        }
        draw(state);
      });
    });
  }

  function restoreFocus(state) {
    const selector = state.pendingFocusSelector;
    if (!selector) return;
    state.pendingFocusSelector = null;
    queueMicrotask(() => {
      state.host.querySelector(selector)?.focus();
    });
  }

  function bindColumnChooser(state) {
    state.host.querySelector('[data-grid-columns-toggle]')?.addEventListener('click',async () => {
      await hydratePreferences(state);
      state.chooserOpen = !state.chooserOpen;
      draw(state);
    });
    state.host.querySelector('[data-grid-columns-close]')?.addEventListener('click',() => {
      state.chooserOpen = false;
      draw(state);
    });
    state.host.querySelectorAll('[data-grid-column]').forEach(input => {
      const markInteraction = () => { state.preferenceRevision += 1; };
      input.addEventListener('pointerdown',markInteraction,{once:true});
      input.addEventListener('keydown',markInteraction,{once:true});
      input.addEventListener('input',async () => {
        state.pendingFocusSelector = '[data-grid-column="' + input.dataset.gridColumn + '"]';
        const previous = new Set(state.visible);
        const next = new Set(state.visible);
        if (input.checked) next.add(input.dataset.gridColumn);
        else next.delete(input.dataset.gridColumn);
        if (!next.size) {
          input.checked = true;
          return;
        }
        state.visible = next;
        state.chooserOpen = true;
        try {
          const columns = defaultColumns(state.module).filter(key => next.has(key));
          const saved = await savePreferences(state.module,columns);
          state.visible = new Set(saved);
        } catch (error) {
          console.error('Admin grid column preferences could not be saved.',error);
          state.visible = previous;
          window.BRVTALFeedback?.error?.('Column preferences could not be saved.','admin-grid-columns');
        }
        requestAnimationFrame(() => draw(state));
      });
    });
    state.host.querySelector('[data-grid-columns-reset]')?.addEventListener('click',async () => {
      state.pendingFocusSelector = '[data-grid-columns-reset]';
      const previous = new Set(state.visible);
      const defaults = defaultColumns(state.module);
      state.visible = new Set(defaults);
      state.chooserOpen = true;
      draw(state);
      try {
        const saved = await savePreferences(state.module,defaults);
        state.visible = new Set(saved);
      } catch (error) {
        console.error('Admin grid column preferences could not be reset.',error);
        state.visible = previous;
        window.BRVTALFeedback?.error?.('Column preferences could not be reset.','admin-grid-columns');
      }
      draw(state);
    });
  }

  function bindMembershipFilter(state) {
    const control = state.host.querySelector('[data-grid-membership-filter]');
    if (!control) return;
    control.value = state.membershipFilter;
    control.addEventListener('change',() => {
      state.membershipFilter = ['member','external'].includes(control.value) ? control.value : 'all';
      draw(state);
    });
  }

  function bindPagination(state) {
    const pagination = state.options.pagination;
    if (!pagination || typeof state.options.onPageChange !== 'function') return;
    const move = page => {
      const target = Math.max(1, Math.min(Number(pagination.pages || 1), Number(page || 1)));
      if (target === Number(pagination.page || 1)) return;
      state.options.onPageChange(target);
    };
    state.host.querySelector('[data-grid-page-prev]')?.addEventListener('click',() => move(Number(pagination.page || 1) - 1));
    state.host.querySelector('[data-grid-page-next]')?.addEventListener('click',() => move(Number(pagination.page || 1) + 1));
  }

  function bindRowActions(state, selected) {
    state.host.querySelector('[data-grid-clear]')?.addEventListener('click',() => {
      state.pendingFocusSelector = '[data-grid-columns-toggle]';
      selected.clear();
      draw(state);
    });
    state.host.querySelector('[data-grid-bulk]')?.addEventListener('click',() => {
      window.BRVTALBulkActions?.open?.(state.module,[...selected]);
    });
    state.host.querySelectorAll('[data-grid-action]').forEach(button => {
      button.addEventListener('click',() => {
        runAction(state.module,button.dataset.gridAction,Number(button.dataset.gridId));
      });
    });
  }

  function draw(state) {
    if (!state.host?.isConnected) return;
    const spec = SPECS[state.module];
    const visibleColumns = spec.columns.filter(column => state.visible.has(column.key));
    const rows = sortedRows(state);
    const selected = selection(state.module);
    const visibleIds = rows.map(row => Number(row.id)).filter(Number.isInteger);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every(id => selected.has(id));
    const template = gridTemplate(visibleColumns);
    const orderEnabled = Boolean(
      spec.orderable
      && state.options.orderingEnabled !== false
      && !state.sortDirection
    );
    const canBulk = Boolean(window.BRVTALBulkActions?.supports?.(state.module));
    const selectedCount = selected.size;
    const resultSuffix = rows.length === 1 ? '' : 'S';
    const pagination = state.options.pagination;
    const totalResults = Number(pagination?.total ?? rows.length);
    const pageNumber = Number(pagination?.page || 1);
    const pageCount = Number(pagination?.pages || 1);
    const chooserExpanded = state.chooserOpen ? 'true' : 'false';
    const selectionHidden = selectedCount ? '' : ' hidden';
    const allChecked = allVisibleSelected ? 'checked' : '';
    const bulkButton = canBulk
      ? '<button type="button" class="btn red" data-grid-bulk>CHANGE STATUS</button>'
      : '';
    const membershipFilter = state.module === 'artists'
      ? `<label class="admin-grid-membership-filter"><span>MEMBERSHIP</span><select data-grid-membership-filter><option value="all">ALL</option><option value="member">BRVTAL MEMBERS</option><option value="external">EXTERNAL / NETWORK</option></select></label>`
      : '';
    const headers = headerCellsMarkup(state,visibleColumns);
    const bodyRows = rowsMarkup(state,spec,rows,visibleColumns,template,selected);
    const ordering = orderAttributes(spec,state,orderEnabled);
    const paginationControls = pagination
      ? `<div class="admin-grid-pagination" role="navigation" aria-label="Records pagination">
          <button type="button" class="btn ghost" data-grid-page-prev ${pagination.has_previous?'':'disabled'}>← PREVIOUS</button>
          <span>PAGE ${pageNumber} / ${pageCount} · ${totalResults} TOTAL</span>
          <button type="button" class="btn ghost" data-grid-page-next ${pagination.has_next?'':'disabled'}>NEXT →</button>
        </div>`
      : '';

    state.host.innerHTML = `
      <div class="admin-data-grid-shell" data-grid-module="${esc(state.module)}">
        <div class="admin-grid-controls">
          <span class="admin-grid-result-count">${rows.length} RESULT${resultSuffix} ON THIS PAGE · ${totalResults} TOTAL</span>
          ${membershipFilter}
          <div class="admin-grid-view-control">
            <button type="button" class="btn ghost" data-grid-columns-toggle aria-expanded="${chooserExpanded}">COLUMNS / VIEW</button>
            ${renderChooser(state)}
          </div>
        </div>
        <div class="admin-grid-selection-bar"${selectionHidden} role="status" aria-live="polite">
          <strong>${selectedCount} SELECTED</strong>
          <span class="admin-grid-selection-actions">
            ${bulkButton}
            <button type="button" class="btn ghost" data-grid-clear>CLEAR</button>
          </span>
        </div>
        <div class="admin-data-grid-scroll">
          <div class="admin-data-grid" role="table" aria-label="${esc(state.module)} records">
            <div class="admin-data-grid-head" role="row" style="--admin-grid-template:${esc(template)}">
              <div role="columnheader" class="admin-grid-select-cell"><input type="checkbox" data-grid-select-all aria-label="Select all current results" ${allChecked}></div>
              ${headers}
              <div role="columnheader" class="admin-grid-actions-head"><span>ACTIONS</span></div>
            </div>
            <div class="admin-data-grid-body" role="rowgroup" ${ordering}>
              ${bodyRows}
            </div>
          </div>
        </div>
        ${paginationControls}
      </div>`;

    bindSelection(state,selected,visibleIds,allVisibleSelected);
    bindSorting(state);
    bindMembershipFilter(state);
    bindColumnChooser(state);
    bindRowActions(state,selected);
    bindPagination(state);

    const body = state.host.querySelector('.admin-data-grid-body');
    if (body && spec.orderable) window.BRVTALContentOrdering?.refresh?.(body);
    restoreFocus(state);
  }

  async function hydratePreferences(state) {
    if (state.preferencesLoaded) return;
    if (state.preferencePromise) return state.preferencePromise;
    const revision = state.preferenceRevision;
    state.preferencesLoading = true;
    state.preferencePromise = (async () => {
      const columns = await loadPreferences(state.module);
      state.preferencesLoaded = true;
      if (state.preferenceRevision !== revision) return;
      state.visible = new Set(columns);
      draw(state);
    })().finally(() => {
      state.preferencesLoading = false;
      state.preferencePromise = null;
    });
    return state.preferencePromise;
  }

  function render(module, host, rows, options = {}) {
    if (!host || !SPECS[module]) return false;
    const state = ensureInstance(module,host);
    state.rows = Array.isArray(rows) ? rows.slice() : [];
    state.allRows = Array.isArray(options.allRows) ? options.allRows.slice() : state.rows.slice();
    state.options = {...options};
    const validIds = new Set(state.allRows.map(row => Number(row.id)).filter(Number.isInteger));
    const selected = selection(module);
    [...selected].forEach(id => { if (!validIds.has(id)) selected.delete(id); });
    draw(state);
    void hydratePreferences(state);
    return true;
  }

  function clearSelection(module) {
    selection(module).clear();
    document.querySelectorAll('[data-grid-module="'+CSS.escape(module)+'"]').forEach(shell => {
      const host = shell.parentElement;
      const state = host ? instances.get(host) : null;
      if (state) draw(state);
    });
  }

  function supports(module) { return Boolean(SPECS[module]); }

  window.BRVTALDataGrid = {render,clearSelection,supports,defaultColumns};
})();
