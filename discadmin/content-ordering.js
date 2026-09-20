(function () {
  'use strict';

  const endpoint = '/api/reorder.php';
  const supported = new Set(['artists','sets','releases','blog']);
  let activeDrag = null;

  function items(container) {
    return [...container.querySelectorAll(':scope > [data-order-id]')];
  }

  function ids(container) {
    return items(container).map(item => Number(item.dataset.orderId)).filter(Number.isInteger);
  }

  function applyOrder(records, orderedIds) {
    const byId = new Map((Array.isArray(records) ? records : []).map(record => [Number(record.id), record]));
    return orderedIds.map((id,index) => {
      const record = byId.get(Number(id));
      if (!record) return null;
      record.sort_order = index;
      return record;
    }).filter(Boolean);
  }

  function updatePositions(container) {
    items(container).forEach((item,index) => {
      const position = item.querySelector('[data-order-position]');
      if (position) position.textContent = String(index + 1).padStart(2,'0');
    });
  }

  function restore(container, orderedIds) {
    const byId = new Map(items(container).map(item => [Number(item.dataset.orderId), item]));
    orderedIds.forEach(id => { const item = byId.get(Number(id)); if (item) container.appendChild(item); });
    updatePositions(container);
  }

  function resourceLabel(resource) {
    return ({artists:'Artists',sets:'Sets',releases:'Releases',blog:'Blog'})[resource] || resource;
  }

  function ensureHelp(container) {
    const resource = container.dataset.orderResource;
    const anchor = container.closest('.table') || container;
    let help = anchor.previousElementSibling;
    if (!help?.classList.contains('content-order-help') || help.dataset.orderResource !== resource) {
      help = document.createElement('div');
      help.className = 'content-order-help';
      help.dataset.orderResource = resource;
      help.innerHTML = '<span class="content-order-help-mark" aria-hidden="true">↕</span><span data-order-help-copy></span><span class="content-order-status" role="status" aria-live="polite"></span>';
      anchor.parentNode?.insertBefore(help, anchor);
    }
    return help;
  }

  function setHelp(container) {
    const help = ensureHelp(container);
    const enabled = container.dataset.orderEnabled !== '0';
    const copy = help.querySelector('[data-order-help-copy]');
    if (copy) copy.textContent = enabled
      ? 'Drag to reorder · Arrow Up/Down on the handle · saves automatically'
      : 'Clear search/filter/sort to reorder the complete collection';
    help.classList.toggle('disabled', !enabled);
    return help;
  }

  function announce(container, message, bad = false) {
    const status = setHelp(container).querySelector('.content-order-status');
    if (!status) return;
    status.textContent = message;
    status.classList.toggle('error', bad);
  }

  async function csrfToken() {
    try { if (typeof csrf !== 'undefined' && csrf) return csrf; } catch (_) {}
    const response = await fetch('/api/index.php/auth',{credentials:'same-origin',cache:'no-store'});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload.authenticated || !payload.csrf) throw new Error('AUTH_REQUIRED');
    return payload.csrf;
  }

  async function persist(resource, orderedIds, previousIds) {
    const response = await fetch(endpoint,{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':await csrfToken()},
      body:JSON.stringify({resource,ids:orderedIds,previous_ids:previousIds})
    });
    const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.error || ('HTTP_' + response.status));
      error.status = response.status;
      throw error;
    }
    return payload;
  }

  async function save(container, previousIds) {
    if (container.dataset.orderSaving === '1') { restore(container, previousIds); return false; }
    const resource = container.dataset.orderResource;
    const orderedIds = ids(container);
    if (!supported.has(resource) || orderedIds.length < 2) return true;
    container.dataset.orderSaving = '1';
    refresh(container);
    announce(container, 'Saving order…');
    try {
      await persist(resource, orderedIds, previousIds);
      updatePositions(container);
      window.dispatchEvent(new CustomEvent('brvtal:content-order-changed',{detail:{resource,ids:orderedIds}}));
      announce(container, 'Order saved');
      window.BRVTALFeedback?.success?.(resourceLabel(resource) + ' order saved.','content-order');
      return true;
    } catch (error) {
      restore(container, previousIds);
      const message = error?.message === 'ORDER_STALE'
        ? 'Order changed elsewhere. Reload the module and try again.'
        : 'Could not save order: ' + (error?.message || 'UNKNOWN_ERROR');
      announce(container, message, true);
      window.BRVTALFeedback?.error?.(message,'content-order');
      return false;
    } finally {
      delete container.dataset.orderSaving;
      refresh(container);
    }
  }

  function moveByKeyboard(event, container, item) {
    if (!['ArrowUp','ArrowDown'].includes(event.key)) return;
    if (container.dataset.orderEnabled === '0' || container.dataset.orderSaving === '1') return;
    const sibling = event.key === 'ArrowUp' ? item.previousElementSibling : item.nextElementSibling;
    if (!sibling?.matches?.('[data-order-id]')) return;
    event.preventDefault();
    const previous = ids(container);
    if (event.key === 'ArrowUp') sibling.before(item);
    else item.before(sibling);
    updatePositions(container);
    item.querySelector('.content-order-handle')?.focus();
    void save(container, previous);
  }

  function pointerDown(event, container, item, handle) {
    if (event.button !== 0 || container.dataset.orderEnabled === '0' || container.dataset.orderSaving === '1') return;
    event.preventDefault();
    activeDrag = {container,item,handle,pointerId:event.pointerId,previous:ids(container),moved:false};
    item.classList.add('content-order-dragging');
    container.classList.add('content-order-active');
    try { handle.setPointerCapture(event.pointerId); } catch (_) {}
  }

  function pointerMove(event) {
    const drag = activeDrag;
    if (!drag || drag.pointerId !== event.pointerId) return;
    const target = document.elementFromPoint(event.clientX,event.clientY)?.closest?.('[data-order-id]');
    if (!target || target === drag.item || target.parentElement !== drag.container) return;
    const rect = target.getBoundingClientRect();
    const before = event.clientY < rect.top + rect.height / 2;
    drag.container.insertBefore(drag.item, before ? target : target.nextSibling);
    drag.moved = true;
    updatePositions(drag.container);
  }

  function finishPointer(event, cancelled = false) {
    const drag = activeDrag;
    if (!drag || drag.pointerId !== event.pointerId) return;
    activeDrag = null;
    drag.item.classList.remove('content-order-dragging');
    drag.container.classList.remove('content-order-active');
    try { drag.handle.releasePointerCapture(event.pointerId); } catch (_) {}
    if (cancelled) { restore(drag.container, drag.previous); return; }
    if (drag.moved) void save(drag.container, drag.previous);
  }

  function configureItem(container, item) {
    item.dataset.orderItem = '1';
    let handle = item.querySelector(':scope > .content-order-handle');
    if (!handle) {
      handle = document.createElement('button');
      handle.type = 'button';
      handle.className = 'content-order-handle';
      handle.innerHTML = '<span aria-hidden="true">⠿</span>';
      item.appendChild(handle);
    }
    const identity = item.querySelector('.title,.release-title,.blog-title')?.textContent?.trim() || 'record';
    handle.setAttribute('aria-label','Reorder ' + identity);
    handle.setAttribute('aria-keyshortcuts','ArrowUp ArrowDown');
    handle.title = 'Drag to reorder · Arrow Up/Down';
    handle.onkeydown = event => moveByKeyboard(event, container, item);
    handle.onpointerdown = event => pointerDown(event, container, item, handle);
  }

  function refresh(container) {
    if (!container?.isConnected) return false;
    const resource = String(container.dataset.orderResource || '').toLowerCase();
    if (!supported.has(resource)) return false;
    const enabled = container.dataset.orderEnabled !== '0' && container.dataset.orderSaving !== '1';
    items(container).forEach(item => {
      configureItem(container,item);
      const handle = item.querySelector(':scope > .content-order-handle');
      if (handle) { handle.disabled = !enabled; handle.hidden = container.dataset.orderEnabled === '0'; }
    });
    updatePositions(container);
    setHelp(container);
    return true;
  }

  function scan() { document.querySelectorAll('[data-order-resource]').forEach(refresh); }

  document.addEventListener('pointermove', pointerMove, {passive:true});
  document.addEventListener('pointerup', event => finishPointer(event,false));
  document.addEventListener('pointercancel', event => finishPointer(event,true));
  const observer = new MutationObserver(records => {
    records.forEach(record => {
      record.addedNodes.forEach(node => {
        if (!(node instanceof Element)) return;
        if (node.matches('[data-order-resource]')) refresh(node);
        node.querySelectorAll('[data-order-resource]').forEach(refresh);
      });
    });
  });
  observer.observe(document.documentElement,{childList:true,subtree:true});
  window.BRVTALContentOrdering = {refresh,scan,applyOrder};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',scan,{once:true});
  else scan();
})();
