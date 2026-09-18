(() => {
  'use strict';

  const ENDPOINT = '/api/content-health.php';
  const sectionFor = type => ({events:'events',artists:'artists',sets:'sets',releases:'releases',pages:'pages',blog:'blog'})[type] || 'dashboard';
  let loading = false;

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  }

  function ensureStyle() {
    if (document.getElementById('brvtal-content-health-style')) return;
    const style = document.createElement('style');
    style.id = 'brvtal-content-health-style';
    style.textContent = `
      .content-health-panel{margin-top:18px;border:1px solid #292d31;background:#090a0b;padding:18px}
      .content-health-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:16px}
      .content-health-head h2{margin:2px 0 4px;font-size:clamp(24px,3vw,42px);letter-spacing:-.04em}
      .content-health-score{min-width:88px;text-align:right;font:900 34px/1 monospace;color:#fff}
      .content-health-score small{display:block;margin-top:5px;font:700 8px/1.2 monospace;letter-spacing:1.5px;color:#7d848b}
      .content-health-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}
      .content-health-stat{border:1px solid #25292d;padding:12px;background:#070808}
      .content-health-stat span{display:block;font:700 8px/1.2 monospace;letter-spacing:1.4px;color:#777f86;margin-bottom:8px}
      .content-health-stat b{font:900 20px/1 monospace}
      .content-health-drafts{display:grid;grid-template-columns:1.3fr repeat(3,minmax(0,1fr));gap:8px;margin:0 0 16px;padding:10px;border:1px solid #25292d;background:#070808}
      .content-health-draft-copy{font-size:9px;color:#747c82;line-height:1.45}.content-health-draft-copy strong{display:block;color:#c9ced2;font:900 9px/1.2 monospace;letter-spacing:1.1px;margin-bottom:5px}
      .content-health-draft-metric{border-left:1px solid #24282c;padding-left:12px}.content-health-draft-metric span{display:block;color:#747c82;font:800 8px/1.2 monospace;letter-spacing:1px}.content-health-draft-metric b{display:block;margin-top:6px;font:900 18px/1 monospace}
      .content-health-list{display:grid;gap:6px}
      .content-health-row{display:grid;grid-template-columns:80px minmax(0,1fr) 62px auto;gap:10px;align-items:center;border-top:1px solid #1f2326;padding:10px 0}
      .content-health-type{font:800 8px/1 monospace;letter-spacing:1.3px;color:#8b9399}
      .content-health-title{font-size:11px;font-weight:800;color:#f4f5f6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .content-health-issues{font-size:9px;color:#7f878d;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .content-health-pill{font:900 11px/1 monospace;text-align:right}.content-health-pill.good{color:#49d98a}.content-health-pill.warn{color:#ffd166}.content-health-pill.bad{color:#ff5566}
      .content-health-open{border:1px solid #34393e;background:#101214;color:#fff;padding:7px 9px;font:800 8px/1 monospace;letter-spacing:1px;cursor:pointer}
      .content-health-open:hover,.content-health-open:focus-visible{border-color:#fff;outline:none}
      .content-health-note{font-size:9px;color:#747c82;line-height:1.5;margin-top:12px}
      @media(max-width:800px){.content-health-stats{grid-template-columns:1fr 1fr}.content-health-drafts{grid-template-columns:1fr 1fr}.content-health-draft-metric{border-left:0;border-top:1px solid #24282c;padding:9px 0 0}.content-health-row{grid-template-columns:70px minmax(0,1fr) 50px}.content-health-open{grid-column:2/4;justify-self:start}}
      @media(max-width:480px){.content-health-drafts{grid-template-columns:1fr}.content-health-stats{grid-template-columns:1fr}.content-health-row{grid-template-columns:1fr}.content-health-open{grid-column:auto}}
    `;
    document.head.appendChild(style);
  }

  function scoreClass(score) {
    if (score >= 80) return 'good';
    if (score >= 60) return 'warn';
    return 'bad';
  }

  async function fetchHealth() {
    const r = await fetch(ENDPOINT,{credentials:'same-origin',cache:'no-store'});
    const j = await r.json().catch(() => ({ok:false,error:'INVALID_RESPONSE'}));
    if (!r.ok || j.ok === false) throw new Error(j.error || ('HTTP_' + r.status));
    return j.data || {};
  }

  function draftAverage(drafts) {
    const items = Array.isArray(drafts?.items) ? drafts.items : [];
    if (!items.length) return 100;
    return Math.round(items.reduce((sum,item) => sum + Number(item.score || 0),0) / items.length);
  }

  function render(data) {
    const main = document.querySelector('.main');
    if (!main || typeof state === 'undefined' || !state.authed || state.section !== 'dashboard' || document.getElementById('brvtal-dashboard-v2')) return;
    document.getElementById('brvtal-content-health')?.remove();

    const publicHealth = data.public || data;
    const drafts = data.drafts || {total:0,ready:0,needs_attention:0,items:[]};
    const items = Array.isArray(publicHealth.items) ? publicHealth.items.slice(0,5) : [];
    const panel = document.createElement('section');
    panel.id = 'brvtal-content-health';
    panel.className = 'content-health-panel';
    panel.innerHTML = `
      <div class="content-health-head">
        <div><div class="eyebrow">SEO / CONTENT QUALITY</div><h2>CONTENT HEALTH</h2><div class="helper">Read-only diagnostics. Public readiness and draft completeness are intentionally separate.</div></div>
        <div class="content-health-score">${Number(publicHealth.score ?? 100)}%<small>PUBLIC SCORE</small></div>
      </div>
      <div class="content-health-stats">
        <div class="content-health-stat"><span>PUBLIC READY ≥80</span><b>${Number(publicHealth.ready ?? 0)}</b></div>
        <div class="content-health-stat"><span>PUBLIC ATTENTION</span><b>${Number(publicHealth.needs_attention ?? 0)}</b></div>
        <div class="content-health-stat"><span>PUBLIC MISSING VISUALS</span><b>${Number(publicHealth.missing_visuals ?? 0)}</b></div>
        <div class="content-health-stat"><span>PUBLIC SEO GAPS</span><b>${Number(publicHealth.seo_gaps ?? 0)}</b></div>
      </div>
      <div class="content-health-drafts">
        <div class="content-health-draft-copy"><strong>DRAFT BACKLOG</strong>Drafts may be incomplete without lowering the public health score. Completeness remains visible as editorial progress.</div>
        <div class="content-health-draft-metric"><span>DRAFTS</span><b>${Number(drafts.total ?? 0)}</b></div>
        <div class="content-health-draft-metric"><span>AVG COMPLETE</span><b>${draftAverage(drafts)}%</b></div>
        <div class="content-health-draft-metric"><span>≥80 COMPLETE</span><b>${Number(drafts.ready ?? 0)}</b></div>
      </div>
      <div class="sectionhead"><strong>PUBLIC PRIORITY FIXES</strong><span class="helper">Lowest-scoring public content first</span></div>
      <div class="content-health-list">
        ${items.length ? items.map(item => `
          <div class="content-health-row">
            <div class="content-health-type">${esc(String(item.type || '').toUpperCase())}</div>
            <div><div class="content-health-title">${esc(item.title || 'Untitled')}</div><div class="content-health-issues">${esc((item.issues || []).join(' · ') || 'No issues detected')}</div></div>
            <div class="content-health-pill ${scoreClass(Number(item.score || 0))}">${Number(item.score || 0)}%</div>
            <button class="content-health-open" type="button" data-health-open="${esc(sectionFor(item.type))}" data-health-type="${esc(item.type || '')}" data-health-id="${Number(item.id || 0)}">OPEN</button>
          </div>`).join('') : '<div class="empty">No public content records require review.</div>'}
      </div>
      <div class="content-health-note">SEO title/description are scored only for content types that persist those fields. Diagnostics never publish, modify or block drafts automatically.</div>`;
    main.appendChild(panel);
    panel.querySelectorAll('[data-health-open]').forEach(button => button.addEventListener('click', () => {
      const section = button.dataset.healthOpen || sectionFor(button.dataset.healthType);
      window.go?.(section);
    }));
  }

  async function mount() {
    if (loading || typeof state === 'undefined' || !state.authed || state.section !== 'dashboard' || document.getElementById('brvtal-dashboard-v2')) return;
    loading = true;
    try {
      ensureStyle();
      const health = await fetchHealth();
      if (document.getElementById('brvtal-dashboard-v2')) return;
      render(health);
    } catch (error) {
      window.BRVTALFeedback?.error?.('Content Health unavailable: ' + (error?.message || error),'content-health');
    } finally {
      loading = false;
    }
  }

  const originalGo = window.go;
  if (typeof originalGo === 'function') {
    window.go = async function(section) {
      const result = await originalGo.apply(this, arguments);
      if (section === 'dashboard') setTimeout(mount, 0);
      return result;
    };
  }

  const observer = new MutationObserver(() => {
    if (typeof state === 'undefined' || !state.authed || state.section !== 'dashboard' || document.getElementById('brvtal-dashboard-v2') || document.getElementById('brvtal-content-health')) return;
    clearTimeout(observer._timer);
    observer._timer = setTimeout(mount, 20);
  });
  observer.observe(document.documentElement,{childList:true,subtree:true});

  ensureStyle();
  setTimeout(mount, 50);
  window.BRVTALContentHealth = {mount,refresh:mount};
})();
