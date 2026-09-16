(() => {
  'use strict';

  const ENDPOINT = '/api/media-relations.php';
  const TYPES = ['event','artist','set','release'];
  let currentId = 0;
  let loadId = 0;
  let state = {relations:[],options:{},available:false};

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const inspector = () => document.querySelector('#media-inspector');
  const activeId = () => Number(document.querySelector('.media-card.active[data-media-id]')?.dataset.mediaId || 0);

  async function request(id) {
    const response = await fetch(`${ENDPOINT}?id=${encodeURIComponent(id)}`, {credentials:'same-origin',cache:'no-store'});
    const payload = await response.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.error || `HTTP_${response.status}`);
      error.status = response.status;
      error.payload = payload;
      throw error;
    }
    return payload;
  }

  function serializedRelations() {
    if (!state.available) return undefined;
    return state.relations.map(item => ({
      related_type:item.related_type,
      related_id:Number(item.related_id),
    }));
  }

  function registerProvider() {
    window.BRVTALMediaLibrary?.setRelationsProvider?.(serializedRelations);
  }

  function optionRows(type) {
    return Array.isArray(state.options?.[type]) ? state.options[type] : [];
  }

  function relationLabel(relation) {
    const match = optionRows(relation.related_type).find(item => Number(item.id) === Number(relation.related_id));
    return match?.label || `${relation.related_type.toUpperCase()} #${relation.related_id}`;
  }

  function renderRelationList(root) {
    const list = root.querySelector('[data-media-relations-list]');
    if (!list) return;
    list.innerHTML = state.relations.length
      ? state.relations.map((relation,index) => `<div class="media-relation-row">
          <div><span>${esc(relation.related_type.toUpperCase())}</span><strong>${esc(relationLabel(relation))}</strong></div>
          <button type="button" data-media-relation-remove="${index}" aria-label="Remove ${esc(relationLabel(relation))}">REMOVE</button>
        </div>`).join('')
      : '<div class="media-relations-empty">NO ARCHIVE RELATIONS</div>';
    list.querySelectorAll('[data-media-relation-remove]').forEach(button => button.addEventListener('click', () => {
      state.relations.splice(Number(button.dataset.mediaRelationRemove),1);
      renderRelationList(root);
    }));
  }

  function renderTargetOptions(root) {
    const type = root.querySelector('[data-media-relation-type]')?.value || 'event';
    const select = root.querySelector('[data-media-relation-target]');
    if (!select) return;
    const rows = optionRows(type);
    select.innerHTML = '<option value="">SELECT TARGET</option>' + rows.map(item => {
      const suffix = item.status ? ` · ${String(item.status).toUpperCase()}` : '';
      return `<option value="${Number(item.id)}">${esc(item.label)}${esc(suffix)}</option>`;
    }).join('');
    select.disabled = rows.length === 0;
  }

  function decorate(data) {
    const box = inspector();
    if (!box || !data) return;
    box.querySelector('.media-relations-panel')?.remove();

    const anchor = box.querySelector('.media-engine') || box.querySelector('.media-usage');
    if (!anchor) return;
    const panel = document.createElement('section');
    panel.className = 'media-relations-panel';

    if (!data.relations_available) {
      state.available = false;
      registerProvider();
      panel.innerHTML = '<div class="media-relations-head"><b>ARCHIVE RELATIONS</b><span>UNAVAILABLE</span></div><p>Structured Memory relations require the media-relations migration. Existing media editing remains available.</p>';
      anchor.insertAdjacentElement('beforebegin', panel);
      return;
    }

    state.available = true;
    state.relations = Array.isArray(data.relations) ? data.relations.map(item => ({
      related_type:String(item.related_type || ''),
      related_id:Number(item.related_id || 0),
    })).filter(item => TYPES.includes(item.related_type) && item.related_id > 0) : [];
    state.options = data.relation_options && typeof data.relation_options === 'object' ? data.relation_options : {};
    registerProvider();

    panel.innerHTML = `<div class="media-relations-head"><b>ARCHIVE RELATIONS</b><span>STRUCTURED</span></div>
      <p>Connect this Memory explicitly to existing BRVTAL records. No relation is inferred. Relations save with the Media Library SAVE action.</p>
      <div class="media-relations-add">
        <label>TYPE<select data-media-relation-type>${TYPES.map(type => `<option value="${type}">${type.toUpperCase()}</option>`).join('')}</select></label>
        <label>TARGET<select data-media-relation-target></select></label>
        <button type="button" data-media-relation-add>ADD RELATION</button>
      </div>
      <div class="media-relations-list" data-media-relations-list></div>`;
    anchor.insertAdjacentElement('beforebegin', panel);
    renderTargetOptions(panel);
    renderRelationList(panel);

    panel.querySelector('[data-media-relation-type]')?.addEventListener('change', () => renderTargetOptions(panel));
    panel.querySelector('[data-media-relation-add]')?.addEventListener('click', () => {
      const type = panel.querySelector('[data-media-relation-type]')?.value || '';
      const id = Number(panel.querySelector('[data-media-relation-target]')?.value || 0);
      if (!TYPES.includes(type) || id < 1) return;
      if (!state.relations.some(item => item.related_type === type && item.related_id === id)) {
        state.relations.push({related_type:type,related_id:id});
        renderRelationList(panel);
      }
    });
  }

  async function loadForActive() {
    registerProvider();
    const id = activeId();
    const box = inspector();
    if (!box || id < 1 || !box.querySelector('#media-save')) return;
    if (id === currentId && box.querySelector('.media-relations-panel')) return;
    currentId = id;
    state = {relations:[],options:{},available:false};
    const token = ++loadId;
    try {
      const payload = await request(id);
      if (token !== loadId || id !== activeId()) return;
      decorate(payload.data);
    } catch (_) {
      if (token !== loadId || id !== activeId()) return;
      registerProvider();
      const anchor = box.querySelector('.media-engine') || box.querySelector('.media-usage');
      if (!anchor) return;
      const panel = document.createElement('section');
      panel.className = 'media-relations-panel';
      panel.innerHTML = '<div class="media-relations-head"><b>ARCHIVE RELATIONS</b><span>UNAVAILABLE</span></div><p>Relation metadata could not be loaded. Existing media editing remains available.</p>';
      anchor.insertAdjacentElement('beforebegin', panel);
    }
  }

  let timer = 0;
  const observer = new MutationObserver(() => {
    clearTimeout(timer);
    timer = window.setTimeout(loadForActive, 50);
  });
  observer.observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['class']});
  registerProvider();
  window.setTimeout(loadForActive,120);
  window.BRVTALMediaRelations = {serialize:serializedRelations,reload:loadForActive};
})();