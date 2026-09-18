<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/version.php';
require_once __DIR__ . '/../config/deployment.php';
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>BRVTAL / DISCADMIN</title>
<style>
:root{--bg:#050505;--p:#0a0b0c;--p2:#101214;--line:#292d31;--muted:#7d848b;--text:#f4f5f6;--red:#ff2038;--green:#49d98a}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Arial,Helvetica,sans-serif}button,input,textarea,select{font:inherit}button{cursor:pointer}
.shell{min-height:100vh;display:grid;grid-template-columns:245px 1fr}.side{border-right:1px solid #202326;background:#070707;padding:24px;position:sticky;top:0;height:100vh}.logo{font-size:38px;font-weight:950;letter-spacing:-3px}.sub{font-size:9px;letter-spacing:2.5px;color:#73787d;margin:3px 0 38px}.nav button{width:100%;display:block;text-align:left;background:none;border:0;color:#777;padding:11px 0;font-size:11px;letter-spacing:1px}.nav button:hover,.nav button.active{color:#fff}.nav button.active:before{content:"";display:inline-block;width:5px;height:5px;background:var(--red);margin:0 9px 2px 0}.sidefoot{position:absolute;left:24px;right:24px;bottom:24px}
.main{padding:28px 32px;max-width:1500px}.top{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:1px solid #202326;padding-bottom:20px}.eyebrow{font-size:9px;letter-spacing:2.4px;color:#73787d}.top h1{font-size:34px;margin:7px 0 0;letter-spacing:-1.4px}.status{font-size:9px;border:1px solid #34393e;padding:8px 10px;letter-spacing:1px}.status i{display:inline-block;width:5px;height:5px;background:var(--green);border-radius:50%;margin-right:6px}
.btn{border:0;background:#fff;color:#000;padding:12px 17px;font-weight:900}.btn.red{background:var(--red);color:#fff}.btn.ghost{background:transparent;color:#fff;border:1px solid #363b40}.toolbar{display:flex;justify-content:space-between;gap:12px;margin:22px 0}.search{width:min(400px,100%);background:#090a0b;border:1px solid #34383d;color:#fff;padding:12px}.table{border:1px solid #25292d;background:#080909}.thead,.tr{display:grid;grid-template-columns:2fr 1.1fr 1fr .8fr 165px;gap:12px;padding:13px 15px;align-items:center}.thead{font-size:9px;letter-spacing:1.5px;color:#656b71;background:#0d0f10}.tr{font-size:12px;border-top:1px solid #1d2023}.tr:hover{background:#0d0f10}.title{font-weight:800}.meta{color:#737980;font-size:10px;margin-top:4px}.pill{display:inline-block;border:1px solid #34393e;padding:5px 7px;font-size:9px;text-transform:uppercase}.actions{display:flex;gap:6px;justify-content:flex-end}.iconbtn{background:#111315;color:#fff;border:1px solid #34383c;padding:7px 9px;font-size:10px}.empty{padding:55px;text-align:center;color:#6f757b}.avatar{width:42px;height:42px;object-fit:cover;border:1px solid #333;background:#111}.cell{display:flex;gap:12px;align-items:center}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:22px 0}.stat{border:1px solid #24282c;background:#090a0b;padding:18px}.stat .n{font-size:36px;font-weight:900;margin-top:7px}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.88);backdrop-filter:blur(8px);display:none;align-items:center;justify-content:center;padding:18px;z-index:50}.modal.open{display:flex}.modalbox{width:min(1100px,100%);max-height:94vh;overflow:auto;background:#080909;border:1px solid #30353a}.modalhead{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid #24282c}.modalhead h2{margin:0;font-size:20px}.micro{font-size:9px;letter-spacing:2px;color:#70767c;margin-top:5px}.form{padding:24px}.section{border:1px solid #24282c;background:#0a0b0c;padding:20px;margin-bottom:15px}.sectionhead{display:flex;justify-content:space-between;border-bottom:1px solid #24282c;padding-bottom:12px;margin-bottom:18px}.sectionhead strong{font-size:10px;letter-spacing:2px}.helper{font-size:10px;color:#747b82;line-height:1.5}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{margin-bottom:14px}.field label{display:block;color:#8b9197;font-size:9px;text-transform:uppercase;letter-spacing:1.4px;margin-bottom:7px}.field input,.field textarea,.field select{width:100%;background:#050606;border:1px solid #353a3f;color:#fff;padding:11px}.field textarea{min-height:110px;resize:vertical}.full{grid-column:1/-1}.modalfoot{display:flex;justify-content:space-between;align-items:center;border-top:1px solid #25292d;padding:16px 24px}.footactions{display:flex;gap:8px}.notice{display:none;margin:0 24px 10px;padding:11px;border:1px solid #3a3f44;font-size:11px}.notice.show{display:block}.error{color:#ff5555}
.lineup{display:grid;grid-template-columns:1fr 1fr;gap:18px}.linebox{border:1px solid #25292d;background:#0a0b0c;padding:14px}.linebox h3{font-size:10px;letter-spacing:2px;color:#9aa0a6;margin:0 0 12px}.artistpick{display:flex;align-items:center;gap:10px;border:1px solid #25292d;background:#0e1011;padding:10px;margin-bottom:7px}.artistpick button{margin-left:auto}.drag{cursor:grab}.lineitem{display:flex;align-items:center;gap:10px;border:1px solid #30353a;background:#0d0f10;padding:10px;margin-bottom:7px}.handle{color:#777}.lineitem .remove{margin-left:auto}.role{width:100px!important;padding:7px!important}
.login{min-height:100vh;display:grid;place-items:center}.loginbox{width:min(420px,90vw);background:#090a0b;border:1px solid #282c30;padding:30px}.loginbox h1{margin:0;font-size:38px;letter-spacing:-2px}.loginbox .sub{margin-bottom:28px}.loginbox input{width:100%;background:#050606;color:#fff;border:1px solid #34383d;padding:12px;margin:7px 0 14px}.syscheck{margin:18px 0;border:1px solid #24282c;background:#070808;padding:12px}.sysrow{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid #181b1d;font-size:9px;letter-spacing:1.2px}.sysrow:last-child{border-bottom:0}.sysok{color:var(--green)}.syserr{color:#ff5555}.syswait{color:#8b9197}.navgroup{font-size:8px;letter-spacing:2px;color:#4f555a;margin:25px 0 7px}.thumb{width:58px;height:58px;object-fit:cover;border:1px solid #333;background:#111;flex:0 0 auto}.thumb.lg{width:84px;height:84px}.thumbph{width:58px;height:58px;border:1px solid #292e33;background:linear-gradient(135deg,#0c0d0e,#15181a);display:grid;place-items:center;color:#454b50;font:9px monospace;flex:0 0 auto}.dashgrid{display:grid;grid-template-columns:1.5fr 1fr;gap:14px;margin-top:14px}.dashlist{display:grid;gap:8px}.dashitem{display:flex;gap:12px;align-items:center;border:1px solid #24282c;background:#090a0b;padding:10px}.dashitem .grow{min-width:0;flex:1}.dashitem .title{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.metricrow{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.metric{border:1px solid #24282c;background:#080909;padding:12px}.metric b{display:block;font-size:18px;margin-top:6px}.bar{height:5px;background:#171a1d;margin-top:10px}.bar i{display:block;height:100%;background:var(--green);width:0}.quickgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.quickgrid button{min-height:58px}.dashsection{border:1px solid #24282c;background:#080909;padding:18px}.dashsection .sectionhead{margin-bottom:12px}.thumbcell{display:flex;gap:12px;align-items:center;min-width:0}.techgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.techcard{border:1px solid #24282c;background:#090a0b;padding:18px}.techcard h3{font-size:11px;letter-spacing:1.5px;margin:0 0 9px}.techcard p{font-size:10px;color:#737980;line-height:1.5;min-height:30px}.techvalue{font-size:18px;font-weight:900;margin:8px 0}.techactions{display:flex;gap:7px;flex-wrap:wrap}.logbox{background:#030404;border:1px solid #22272b;padding:15px;white-space:pre-wrap;word-break:break-word;font:10px/1.5 monospace;color:#aeb4b9;max-height:58vh;overflow:auto}.tester{display:grid;grid-template-columns:220px 1fr;gap:12px}.tester pre{margin:0;background:#030404;border:1px solid #22272b;padding:14px;overflow:auto;min-height:260px;font:10px/1.5 monospace}
@media(max-width:850px){.dashgrid{grid-template-columns:1fr}.quickgrid{grid-template-columns:1fr 1fr}.metricrow{grid-template-columns:1fr 1fr}.shell{grid-template-columns:1fr}.side{position:static;height:auto;border-right:0;border-bottom:1px solid #202326}.sidefoot{position:static;margin-top:20px}.stats{grid-template-columns:1fr 1fr}.grid2,.lineup{grid-template-columns:1fr}.thead{display:none}.tr{grid-template-columns:1fr 1fr}.tr>div:nth-child(2),.tr>div:nth-child(3),.tr>div:nth-child(4){display:none}}

/* BRVTAL SYSTEM PULSE V2 */
.system-pulse{border:1px solid #292d31;background:#080909;padding:22px;margin:22px 0 14px}
.system-pulse-head{display:flex;justify-content:space-between;align-items:center;gap:18px;padding-bottom:16px;margin-bottom:14px;border-bottom:1px solid #24282c}
.system-pulse-kicker{font-size:9px;letter-spacing:2.6px;color:#6f767c}
.system-pulse-title{font-size:23px;font-weight:900;letter-spacing:1px;margin-top:5px}
.system-pulse-sub{font-size:10px;color:#6f767c;margin-top:4px}
.system-health{display:flex;align-items:center;gap:10px;border:1px solid #34393e;padding:9px 13px;text-decoration:none;color:#fff;min-width:112px;justify-content:center}
.system-health-dot{width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 9px rgba(73,217,138,.35)}
.system-health strong{font-size:20px}
.system-health span:last-child{font-size:9px;letter-spacing:1.4px;color:#777e84}
.system-pulse-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.system-pulse-card{display:block;min-width:0;text-decoration:none;color:#fff;background:#090a0b;border:1px solid #292d31;padding:16px;transition:border-color .16s,transform .16s,background .16s}
.system-pulse-card:hover{border-color:#5a6066;background:#0c0e0f;transform:translateY(-1px)}
.system-pulse-label{display:flex;align-items:center;gap:7px;color:#7c8389;font-size:9px;letter-spacing:1.5px}
.system-pulse-label i{width:7px;height:7px;border-radius:50%;background:#8a9095;display:inline-block}
.system-pulse-card[data-state="ok"] .system-pulse-label i{background:var(--green);box-shadow:0 0 8px rgba(73,217,138,.25)}
.system-pulse-card[data-state="bad"] .system-pulse-label i{background:#ff5555}
.system-pulse-value{display:block;font-size:22px;font-weight:900;margin-top:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.system-pulse-meta{display:block;color:#686f75;font-size:10px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.system-status-shell{border:1px solid #292d31;background:#080909;padding:22px}
.system-status-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px}
.system-status-card{border:1px solid #292d31;background:#090a0b;padding:16px;min-height:115px}
.system-status-card .eyebrow{margin-bottom:7px}
.system-status-card b{font-size:22px}
.system-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.system-detail{border:1px solid #292d31;background:#070808}
.system-detail summary{cursor:pointer;list-style:none;padding:15px 16px;font-size:10px;font-weight:900;letter-spacing:1.7px}
.system-detail summary::-webkit-details-marker{display:none}
.system-detail summary:after{content:"+";float:right;color:#777}
.system-detail[open] summary:after{content:"−"}
.system-detail pre{margin:0;border-top:1px solid #22262a;padding:14px;background:#030404;white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto;font:10px/1.5 monospace;color:#aeb4b9}
.system-detail.wide{grid-column:1/-1}
.system-tester-grid{display:grid;grid-template-columns:220px 1fr;gap:10px;padding:14px;border-top:1px solid #22262a}
.system-tester-grid pre{max-height:360px}
@media(max-width:900px){.system-pulse-grid,.system-status-grid{grid-template-columns:repeat(2,1fr)}.system-detail-grid{grid-template-columns:1fr}.system-detail.wide{grid-column:auto}}
@media(max-width:560px){.system-pulse{padding:16px}.system-pulse-head{align-items:flex-start}.system-pulse-grid,.system-status-grid{grid-template-columns:1fr}.system-health{min-width:95px}.system-tester-grid{grid-template-columns:1fr}}


/* BRVTAL THEME STUDIO */
.theme-shell{display:grid;grid-template-columns:330px minmax(0,1fr);gap:14px;margin-top:22px}
.theme-panel,.theme-preview{border:1px solid #25292d;background:#080909}
.theme-panel{max-height:calc(100vh - 150px);overflow:auto}
.theme-panel-head,.theme-preview-head{padding:17px 19px;border-bottom:1px solid #24282c}
.theme-panel-head strong,.theme-preview-head strong{font-size:10px;letter-spacing:2px}
.theme-panel-head .helper,.theme-preview-head .helper{margin-top:5px}
.theme-tabs{display:flex;overflow:auto;border-bottom:1px solid #24282c}
.theme-tab{background:none;border:0;border-right:1px solid #24282c;color:#747b81;padding:11px 13px;font-size:9px;letter-spacing:1.2px;white-space:nowrap}
.theme-tab.active{color:#fff;background:#101214}
.theme-pane{display:none;padding:18px}.theme-pane.active{display:block}
.theme-group{border:1px solid #24282c;background:#090a0b;padding:14px;margin-bottom:10px}
.theme-group h3{font-size:9px;letter-spacing:1.8px;margin:0 0 13px;color:#a2a8ad}
.theme-field{margin-bottom:12px}.theme-field:last-child{margin-bottom:0}
.theme-field label{display:block;font-size:8px;letter-spacing:1.3px;color:#777e84;text-transform:uppercase;margin-bottom:6px}
.theme-field input,.theme-field select,.theme-field textarea{width:100%;background:#050606;border:1px solid #34393e;color:#fff;padding:9px;font-size:11px}
.theme-field textarea{min-height:90px;resize:vertical;font-family:monospace}
.theme-color{display:grid;grid-template-columns:42px 1fr;gap:8px}.theme-color input[type=color]{width:42px;height:38px;padding:2px}
.theme-two{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.theme-check{display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #252a2e;padding:9px;margin-bottom:6px;font-size:10px;color:#c0c5c9}
.theme-check input{accent-color:var(--green)}
.theme-actions{display:flex;gap:7px;flex-wrap:wrap}
.theme-preview-wrap{padding:20px;background:#050505}
.theme-preview-canvas{min-height:680px;border:1px solid #292e33;position:relative;overflow:hidden;background:var(--preview-bg,#050505);color:var(--preview-text,#f5f5f5)}
.preview-nav{height:58px;border-bottom:1px solid color-mix(in srgb,var(--preview-text,#fff) 18%,transparent);display:flex;align-items:center;justify-content:space-between;padding:0 22px;position:relative;z-index:2}
.preview-logo{font-size:24px;font-weight:950;letter-spacing:-2px}
.preview-menu{font-size:9px;letter-spacing:2px}
.preview-hero{min-height:500px;padding:70px 40px;display:flex;flex-direction:column;justify-content:flex-end;position:relative}
.preview-gridline{position:absolute;inset:0;background-image:linear-gradient(to right,rgba(255,255,255,.05) 1px,transparent 1px),linear-gradient(to bottom,rgba(255,255,255,.04) 1px,transparent 1px);background-size:80px 80px;pointer-events:none}
.preview-kicker{font-size:9px;letter-spacing:3px;opacity:.55;position:relative}
.preview-title{font-size:clamp(58px,9vw,132px);font-weight:950;line-height:.82;letter-spacing:-.07em;margin:13px 0;position:relative}
.preview-accent{color:var(--preview-accent,#ff2038)}
.preview-copy{max-width:480px;font-size:12px;line-height:1.6;opacity:.65;position:relative}
.preview-cta{display:inline-block;margin-top:22px;padding:11px 15px;border:1px solid var(--preview-accent,#ff2038);color:var(--preview-text,#fff);font-size:9px;letter-spacing:1.7px;position:relative;width:max-content}
.preview-footer{position:absolute;bottom:15px;left:22px;right:22px;display:flex;justify-content:space-between;font:8px monospace;opacity:.45}
.theme-swatches{display:flex;gap:6px;margin-top:9px}.theme-swatches i{width:20px;height:20px;border:1px solid #333;display:block}
.theme-note{font-size:9px;line-height:1.5;color:#737a80}
.theme-file{display:none}
@media(max-width:1050px){.theme-shell{grid-template-columns:1fr}.theme-panel{max-height:none}.theme-preview-canvas{min-height:560px}}
@media(max-width:650px){.theme-two{grid-template-columns:1fr}.theme-preview-wrap{padding:10px}.preview-hero{padding:45px 22px}.preview-title{font-size:60px}}


/* BRVTAL SETTINGS V3 */
.settings-home{margin-top:22px}
.settings-hero{border:1px solid #292d31;background:#080909;padding:22px;margin-bottom:14px;display:flex;justify-content:space-between;gap:18px;align-items:center}
.settings-hero h2{margin:3px 0;font-size:25px;letter-spacing:.02em}
.settings-hero p{margin:0;color:#737a80;font-size:11px;max-width:620px;line-height:1.5}
.settings-search{width:260px;background:#050606;border:1px solid #34393e;color:#fff;padding:10px 12px}
.settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.settings-card{border:1px solid #292d31;background:#090a0b;padding:18px;min-height:160px;display:flex;flex-direction:column;justify-content:space-between}
.settings-card:hover{border-color:#555b61}
.settings-card .icon{font-size:22px;margin-bottom:10px}
.settings-card h3{font-size:13px;letter-spacing:1.1px;margin:0 0 7px}
.settings-card p{font-size:10px;line-height:1.5;color:#737a80;margin:0 0 14px}
.settings-card .meta{font-size:9px;color:#565d63;letter-spacing:1px}
.settings-card .actions{display:flex;gap:7px;flex-wrap:wrap}
.settings-advanced{margin-top:14px;border:1px solid #292d31;background:#080909}
.settings-advanced summary{cursor:pointer;list-style:none;padding:17px 19px;font-size:10px;font-weight:900;letter-spacing:1.8px}
.settings-advanced summary::-webkit-details-marker{display:none}
.settings-advanced summary:after{content:"+";float:right;color:#777}
.settings-advanced[open] summary:after{content:"−"}
.settings-raw{border-top:1px solid #25292d}
.settings-raw-head{padding:14px 18px;display:grid;grid-template-columns:1fr 90px 2fr 90px;gap:14px;color:#777e84;font-size:8px;letter-spacing:1.4px}
.settings-raw-row{display:grid;grid-template-columns:1fr 90px 2fr 90px;gap:14px;padding:14px 18px;border-top:1px solid #202428;align-items:center}
.settings-raw-row .key{font-weight:800;font-size:11px}.settings-raw-row .value{font-size:10px;color:#666e74;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.settings-pill{font-size:8px;border:1px solid #353a3e;padding:5px 7px;color:#9aa0a5;display:inline-block;width:max-content}
.settings-modal-help{font-size:9px;color:#747b81;line-height:1.5;margin-top:6px}
@media(max-width:1000px){.settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.settings-hero{align-items:flex-start}.settings-search{width:220px}}
@media(max-width:650px){.settings-grid{grid-template-columns:1fr}.settings-hero{display:block}.settings-search{width:100%;margin-top:15px}.settings-raw-head{display:none}.settings-raw-row{grid-template-columns:1fr auto}.settings-raw-row .value{grid-column:1/-1}}

</style><link rel="stylesheet" href="/discadmin/admin-modules.css?v=<?= rawurlencode(brvtal_deployment_short_sha()) ?>"><link rel="stylesheet" href="/discadmin/content-core.css?v=<?= rawurlencode(brvtal_deployment_short_sha()) ?>"><link rel="stylesheet" href="/discadmin/security.css?v=<?= rawurlencode(brvtal_deployment_short_sha()) ?>"></head><body><div id="app"></div>
<div class="modal" id="modal">
  <div class="modalbox">
    <div class="modalhead">
      <div>
        <h2 id="mtitle">DISCADMIN EDITOR</h2>
        <div class="micro">BRVTAL / DISCADMIN</div>
      </div>
      <button class="iconbtn" onclick="closeModal()">ESC</button>
    </div>
    <div id="notice" class="notice"></div>
    <div id="mcontent"></div>
    <div class="modalfoot">
      <span class="helper">Cambios guardados directamente en MySQL.</span>
      <div class="footactions">
        <button class="btn ghost" onclick="closeModal()">CANCELAR</button>
        <button class="btn red" id="saveBtn">GUARDAR</button>
      </div>
    </div>
  </div>
</div>
<script>
const API='../api/index.php';let csrf='';let state={authed:false,section:'dashboard',rows:[],editing:null,events:[],artists:[],dashboard:{},recent:{events:[],artists:[],sets:[],media:[]},themeSettings:[],themeMedia:[],theme:{}};
async function req(u='',o={}){const h={'Content-Type':'application/json',...(o.headers||{})};if(csrf)h['X-CSRF-Token']=csrf;const r=await fetch(API+u,{...o,headers:h});let d={};try{d=await r.json()}catch(e){}if(r.status===401){state.authed=false;render();throw Error('AUTH_REQUIRED')}if(!r.ok)throw Error(d.error||'ERROR');return d}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
function imgSrc(v){const s=String(v??'').trim();if(!s)return '';if(/^https?:\/\//i.test(s)||s.startsWith('/'))return s;return '/'+s.replace(/^\/+/, '')}
function thumb(v,label='NO IMAGE',large=false){const u=imgSrc(v);return u?`<img class="thumb ${large?'lg':''}" src="${esc(u)}" alt="${esc(label)}" loading="lazy" onerror="this.outerHTML='<div class=\'thumbph\'>NO IMAGE</div>'">`:`<div class="${large?'thumb lg':'thumbph'}">${large?'NO IMAGE':'NO IMG'}</div>`}
function pageImage(json){try{const o=typeof json==='string'?JSON.parse(json):json;let found='';(function walk(v){if(found)return;if(!v)return;if(Array.isArray(v)){for(const x of v)walk(x);return}if(typeof v==='object'){for(const [k,x] of Object.entries(v)){if(typeof x==='string'&&/^(image|image_url|src|url|background|cover|photo)$/i.test(k)&&x.trim()){found=x;return}walk(x)}}})(o);return found}catch(e){return ''}}

function slug(s){return String(s).normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')}
async function checkSystem(){const api=document.getElementById('st_api'),dbs=document.getElementById('st_db');if(!api||!dbs)return;try{const r=await fetch(API+'/health',{cache:'no-store'});const d=await r.json();if(r.ok&&d.ok){api.textContent='ONLINE';api.className='sysok';dbs.textContent=d.database==='connected'?'CONNECTED':'ERROR';dbs.className=d.database==='connected'?'sysok':'syserr';const da=document.getElementById('dash_api'),dd=document.getElementById('dash_db');if(da){da.textContent='ONLINE';da.className='sysok'}if(dd){dd.textContent=d.database==='connected'?'CONNECTED':'ERROR';dd.className=d.database==='connected'?'sysok':'syserr'}}else{api.textContent='ERROR';api.className='syserr';dbs.textContent='ERROR';dbs.className='syserr'}}catch(e){api.textContent='OFFLINE';api.className='syserr';dbs.textContent='UNKNOWN';dbs.className='syserr'}}
async function login(e){e.preventDefault();const f=new FormData(e.target),btn=e.target.querySelector('button[type=submit],button');btn.disabled=true;btn.textContent='AUTHENTICATING...';try{const d=await req('/auth',{method:'POST',body:JSON.stringify({email:f.get('email'),password:f.get('password')})});csrf=d.csrf||'';state.authed=true;await go('dashboard')}catch(x){const er=document.querySelector('.error');if(er)er.textContent=x.message==='AUTH_REQUIRED'?'Sesión no válida.':'Credenciales inválidas o error de servidor.';btn.disabled=false;btn.textContent='ENTER'}}
async function restoreSession(){try{const d=await req('/auth',{method:'GET'});if(d.authenticated){csrf=d.csrf||'';state.authed=true;await go(BRVTALAdminModules.initialSection());return true}}catch(e){}render();return false}
async function logout(){try{await req('/auth',{method:'DELETE'})}catch(e){}csrf='';state.authed=false;render()}
async function go(s){state.section=s;if(s==='content-core'||s==='security'){render();await BRVTALAdminModules.load(s);return;}if(s==='dashboard'){try{const d=await req('/dashboard');state.dashboard=d.data||{};state.rows=state.dashboard;state.recent=d.recent||{events:[],artists:[],sets:[],media:[]}}catch(e){state.dashboard={};state.recent={events:[],artists:[],sets:[],media:[]}}render();if(state.authed){setTimeout(checkSystem,300);setTimeout(loadAllTech,350)}return}if(s==='theme'){render();setTimeout(loadThemeStudio,20);return}if(s==='events'||s==='artists'||s==='sets'||s==='media'||s==='pages'||s==='settings'){const d=await req('/'+s);state.rows=d.data||[];render()}else render()}
function openModal(type,id=null){state.editing=id;document.getElementById('modal').classList.add('open');document.getElementById('notice').className='notice';document.getElementById('mtitle').textContent=(id?'EDIT ':'NEW ')+type.toUpperCase();document.getElementById('saveBtn').onclick=()=>save(type,id);let r=id?state.rows.find(x=>Number(x.id)===Number(id)):null;
 if(type==='events')eventForm(r);else if(type==='artists')artistForm(r);else if(type==='sets')setForm(r);else if(type==='media')mediaForm(r);else if(type==='pages')pageForm(r);else settingsForm(r);
}
function closeModal(){document.getElementById('modal').classList.remove('open')}
function field(id,label,val='',type='text',full=false){return `<div class="field ${full?'full':''}"><label for="f_${id}">${label}</label><input id="f_${id}" type="${type}" value="${esc(val)}"></div>`}
function area(id,label,val='',full=true){return `<div class="field ${full?'full':''}"><label for="f_${id}">${label}</label><textarea id="f_${id}">${esc(val)}</textarea></div>`}
function select(id,label,val,opts){return `<div class="field"><label for="f_${id}">${label}</label><select id="f_${id}">${opts.map(o=>`<option value="${o[0]}" ${o[0]===val?'selected':''}>${o[1]}</option>`).join('')}</select></div>`}
function eventForm(r){r=r||{};document.getElementById('mcontent').innerHTML=`<div class="form"><div class="section"><div class="sectionhead"><strong>EVENT DATA</strong><span class="helper">Contenido y publicación</span></div><div class="grid2">${field('title','Title *',r.title)}${field('slug','Slug',r.slug)}${field('event_date','Date / time',r.event_date,'datetime-local')}${select('status','Status',r.status||'draft',[['draft','Draft'],['published','Published'],['archived','Archived']])}${field('venue','Venue',r.venue)}${field('city','City',r.city)}${area('description','Description',r.description)}</div></div><div class="section"><div class="sectionhead"><strong>EVENT SKIN</strong><span class="helper">Identidad visual</span></div><div class="grid2">${field('skin','Skin key',r.skin||'CORE')}${field('accent','Accent',r.accent||'#FF2038')}<div class="field full"><label>Cover image URL / path</label><div class="thumbcell" style="margin-bottom:8px">${thumb(r.cover_image,r.title,true)}<input id="f_cover_image" type="text" value="${esc(r.cover_image)}"></div></div>${field('ticket_url','Ticket URL',r.ticket_url)}${field('sort_order','Sort order',r.sort_order||0,'number')}</div></div></div>`}
function artistForm(r){r=r||{};document.getElementById('mcontent').innerHTML=`<div class="form"><div class="section"><div class="sectionhead"><strong>ARTIST PROFILE</strong><span class="helper">Roster BRVTAL</span></div><div class="grid2">${field('name','Name *',r.name)}${field('slug','Slug',r.slug)}${area('bio','Bio',r.bio)}<div class="field"><label>Photo URL / path</label><div class="thumbcell">${thumb(r.photo,r.name,true)}<input id="f_photo" type="text" value="${esc(r.photo)}"></div></div>${select('status','Status',r.status||'draft',[['draft','Draft'],['published','Published']])}${field('sort_order','Sort order',r.sort_order||0,'number')}</div></div><div class="section"><div class="sectionhead"><strong>LINKS</strong><span class="helper">Social / music</span></div><div class="grid2">${field('instagram_url','Instagram',r.instagram_url)}${field('soundcloud_url','SoundCloud',r.soundcloud_url)}${field('website_url','Website',r.website_url)}</div></div></div>`}
function setForm(r){r=r||{};document.getElementById('mcontent').innerHTML=`<div class="form"><div class="section"><div class="sectionhead"><strong>SET / SOUND</strong><span class="helper">SoundCloud, YouTube, Spotify</span></div><div class="grid2">${field('title','Title *',r.title)}${field('slug','Slug',r.slug)}${select('platform','Platform',r.platform||'soundcloud',[['soundcloud','SoundCloud'],['youtube','YouTube'],['spotify','Spotify'],['other','Other']])}${field('external_url','External URL',r.external_url)}${field('embed_url','Embed URL',r.embed_url)}<div class="field"><label>Cover image</label><div class="thumbcell">${thumb(r.cover_image,r.title,true)}<input id="f_cover_image" type="text" value="${esc(r.cover_image)}"></div></div>${select('artist_id','Artist',String(r.artist_id||''),[['','—'],...state.artists.map(a=>[String(a.id),a.name])])}${select('event_id','Event',String(r.event_id||''),[['','—'],...state.events.map(a=>[String(a.id),a.title])])}${select('status','Status',r.status||'draft',[['draft','Draft'],['published','Published']])}${field('sort_order','Sort order',r.sort_order||0,'number')}${area('description','Description',r.description)}</div></div></div>`}
function mediaForm(r){r=r||{};document.getElementById('mcontent').innerHTML=`<div class="form"><div class="section"><div class="sectionhead"><strong>MEDIA RECORD</strong><span class="helper">Use Upload in Media Library for real files</span></div><div class="grid2">${field('title','Title *',r.title)}${select('type','Type',r.type||'image',[['image','Image'],['video','Video'],['audio','Audio'],['document','Document']])}${field('file_path','File path / URL',r.file_path)}${field('mime_type','MIME type',r.mime_type)}${field('file_size','File size',r.file_size||0,'number')}${field('alt_text','Alt text',r.alt_text)}${select('status','Status',r.status||'published',[['published','Published'],['draft','Draft']])}</div></div></div>`}
function pageForm(r){r=r||{};document.getElementById('mcontent').innerHTML=`<div class="form"><div class="section"><div class="sectionhead"><strong>PAGE BUILDER DATA</strong><span class="helper">JSON blocks — editor visual comes next</span></div><div class="grid2">${field('title','Title *',r.title)}${field('slug','Slug',r.slug)}${select('locale','Locale',r.locale||'es',[['es','Español'],['en','English']])}${select('status','Status',r.status||'draft',[['draft','Draft'],['published','Published']])}${area('content_json','Content JSON',r.content_json)}${field('seo_title','SEO title',r.seo_title)}${area('seo_description','SEO description',r.seo_description)}</div></div></div>`}
function settingsForm(r){
 r=r||{};
 document.getElementById('mcontent').innerHTML=`<div class="form"><div class="section"><div class="sectionhead"><strong>SETTING</strong><span class="helper">Advanced configuration record</span></div><div class="grid2">${field('setting_key','Setting key',r.setting_key||'')}${select('is_json','Value type',String(r.is_json??1),[['0','Text'],['1','JSON']])}${area('setting_value','Value',r.setting_value||'')}</div><div class="settings-modal-help">Use this editor only for system-level or advanced values. Visual identity, colors, effects, navigation, SEO and analytics have dedicated interfaces.</div></div></div>`;
}
function show(x){const n=document.getElementById('notice');n.textContent=x;n.className='notice show error'}
async function loadRefs(){try{state.events=(await req('/events')).data||[];state.artists=(await req('/artists')).data||[]}catch(e){}}
async function openLineup(eventId){await loadRefs();let d=await req('/events/'+eventId+'/lineup');let current=d.data||[];document.getElementById('mtitle').textContent='LINEUP / '+(state.events.find(e=>Number(e.id)===Number(eventId))?.title||'EVENT');document.getElementById('modal').classList.add('open');document.getElementById('notice').className='notice';document.getElementById('saveBtn').onclick=async()=>{const items=[...document.querySelectorAll('#lineupCurrent .lineitem')].map((el,i)=>({artist_id:Number(el.dataset.id),role:el.querySelector('input')?.value||''}));try{await req('/events/'+eventId+'/lineup',{method:'POST',body:JSON.stringify({lineup:items})});closeModal()}catch(e){show(e.message)}};document.getElementById('mcontent').innerHTML=`<div class="form"><div class="lineup"><div class="linebox"><h3>AVAILABLE ARTISTS</h3><div id="available">${state.artists.filter(a=>!current.some(x=>Number(x.artist_id)===Number(a.id))).map(a=>`<div class="artistpick drag"><span>${esc(a.name)}</span><button class="iconbtn" onclick="addLine(${a.id})">ADD</button></div>`).join('')||'<div class="empty">No hay artistas disponibles.</div>'}</div></div><div class="linebox"><h3>LINEUP / DRAG ORDER</h3><div id="lineupCurrent">${current.map(x=>lineHtml(x.artist_id,x.name,x.role)).join('')||'<div class="empty">Añade artistas.</div>'}</div><div class="helper">Puedes reordenar con ↑ ↓. El orden se guarda como lineup.</div></div></div></div>`}
function lineHtml(id,name,role=''){return `<div class="lineitem" data-id="${id}"><span class="handle">☷</span><strong>${esc(name)}</strong><input class="role" placeholder="ROLE" value="${esc(role)}"><button class="iconbtn" onclick="moveLine(this,-1)">↑</button><button class="iconbtn" onclick="moveLine(this,1)">↓</button><button class="iconbtn" onclick="this.parentElement.remove()">×</button></div>`}
function addLine(id){const a=state.artists.find(x=>Number(x.id)===Number(id));if(!a)return;const box=document.getElementById('lineupCurrent');if(box.querySelector('.empty'))box.innerHTML='';box.insertAdjacentHTML('beforeend',lineHtml(a.id,a.name));document.querySelector(`#available .artistpick button[onclick="addLine(${id})"]`)?.closest('.artistpick')?.remove()}
function moveLine(btn,dir){const el=btn.closest('.lineitem');if(dir<0&&el.previousElementSibling)el.parentNode.insertBefore(el,el.previousElementSibling);if(dir>0&&el.nextElementSibling)el.parentNode.insertBefore(el.nextElementSibling,el)}
async function del(type,id){if(!confirm('Eliminar este registro?'))return;try{await req('/'+type+'/'+id,{method:'DELETE'});await go(type)}catch(e){alert(e.message)}}
async function uploadMedia(){alert('El uploader avanzado se activará en Media Library en la siguiente iteración. Puedes gestionar registros ahora.')}
async function tech(path){
  state.section = (path==='technical') ? 'system' : path;
  render();
  if(['system','health','logs','database','storage','php'].includes(state.section)) setTimeout(loadAllTech,30);
}
let techTimer=null;
function scheduleTechRefresh(){clearInterval(techTimer);techTimer=setInterval(()=>{if(state.authed&&state.section==='system')loadAllTech()},30000)}
async function loadTech(action){
  try{
    const r=await fetch('technical.php?action='+encodeURIComponent(action),{cache:'no-store',credentials:'same-origin'});
    const d=await r.json();
    if(!r.ok||!d.ok)throw Error(d.error||'TECH_ERROR');
    return d;
  }catch(e){return {ok:false,error:e.message}}
}
async function loadAllTech(){
  const ids={system:'tech-system',health:'tech-health',database:'tech-database',storage:'tech-storage',php:'tech-php',logs:'tech-logs'};
  const actions=Object.keys(ids);
  const results=await Promise.all(actions.map(a=>loadTech(a)));
  actions.forEach((a,i)=>{const el=document.getElementById(ids[a]);if(el)el.textContent=JSON.stringify(results[i],null,2)});
  const sys=results[0]||{}, health=results[1]||{}, db=results[2]||{}, storage=results[3]||{}, php=results[4]||{};
  const okHealth=!!health.ok, okDb=!!db.ok, okStorage=!!storage.ok&&storage.uploads_writable!==false&&storage.logs_writable!==false;
  const setCard=(valueId,value,good)=>{
    const el=document.getElementById(valueId);if(el){el.textContent=value;el.className=good?'sysok':'syserr'}
  };
  setCard('status-php',php.version||sys.php||'ONLINE',!!php.ok||!!sys.ok);
  const phpMeta=document.getElementById('status-php-meta');if(phpMeta)phpMeta.textContent=(php.sapi||sys.sapi)?'PHP '+(php.version||sys.php)+' · '+(php.sapi||sys.sapi):'Runtime';
  setCard('status-api-value',okHealth?'LIVE':'ERROR',okHealth);
  setCard('status-db-value',okDb?'CONNECTED':'ERROR',okDb);
  const dbMeta=document.getElementById('status-db-meta');if(dbMeta)dbMeta.textContent=db.server?((db.driver||'DB')+' · '+db.server):'Engine & records';
  setCard('status-storage-value',okStorage?'READY':'CHECK',okStorage);
  updateDashboardPulse(sys,health,db,storage,php);
}
function updateDashboardPulse(sys,health,db,storage,php){
  const root=document.getElementById('system-pulse'); if(!root)return;
  const good=[!!sys.ok,!!health.ok,!!db.ok,!!storage.ok&&storage.uploads_writable!==false&&storage.logs_writable!==false];
  const labels=['pulse-web','pulse-api','pulse-db','pulse-storage'];
  const values=[sys.ok?'ONLINE':'ERROR',health.ok?'LIVE':'ERROR',db.ok?'LIVE':'ERROR',good[3]?'READY':'CHECK'];
  labels.forEach((id,i)=>{const el=document.getElementById(id);if(el){el.textContent=values[i];const card=el.closest('.system-pulse-card');if(card)card.dataset.state=good[i]?'ok':'bad'}});
  const wm=document.getElementById('pulse-web-meta');if(wm)wm.textContent=php.version?'PHP '+php.version:'Runtime';
  const score=Math.round(good.filter(Boolean).length/4*100);
  const h=document.getElementById('pulse-health');if(h)h.textContent=score+'%';
  const hd=document.querySelector('.system-health-dot');if(hd)hd.style.background=score===100?'var(--green)':'#ff5555';
}
async function runApiTest(endpoint){
  const box=document.getElementById('techout');if(!box)return;
  box.textContent='GET /'+endpoint+'\n\nLOADING...';
  try{const r=await fetch(API+'/'+endpoint,{cache:'no-store',credentials:'same-origin'});const text=await r.text();box.textContent='HTTP '+r.status+'\n\n'+text}
  catch(e){box.textContent='ERROR: '+e.message}
}

const THEME_DEFAULT={
  name:"BRVTAL CORE", slug:"core",
  branding:{siteName:"BRVTAL",tagline:"RAVE TILL GRAVE",logo:"",mobileLogo:"",favicon:"",preloaderLogo:""},
  colors:{bg:"#050505",surface:"#0A0B0C",text:"#F4F5F6",muted:"#7D848B",primary:"#FF2038",accent:"#B6FF00",border:"#292D31"},
  typography:{display:"Arial, Helvetica, sans-serif",body:"Arial, Helvetica, sans-serif",mono:"monospace",h1:"clamp(58px,9vw,132px)",bodySize:"16px",tracking:"-0.04em"},
  navigation:{fixed:true,transparentHero:true,blur:true,menuStyle:"fullscreen",menuAnimation:"glitch",logoPosition:"left",sceneIndicator:true,soundToggle:true},
  effects:{grain:true,scanlines:true,glitch:true,distortion:true,cursor:true,magnetic:true,pageTransitions:true,parallax:true,horizontalScroll:true,webgl:false,motion:"brvtal"},
  sound:{enabled:true,defaultState:"off",uiSounds:true,masterVolume:0.65},
  preloader:{enabled:true,animation:"glitch",duration:1200,text:"LOADING SYSTEM..."},
  responsive:{desktopScale:1,tabletScale:.82,mobileScale:.55},
  analytics:{google:"",gtm:"",meta:"",headCode:"",bodyCode:""},
  seo:{siteTitle:"BRVTAL",description:"BRVTAL — RAVE TILL GRAVE",ogImage:"",canonical:"",sitemap:true,robots:true,schema:true,openGraph:true,twitterCards:true},
  customCode:{head:"",bodyStart:"",bodyEnd:"",css:"",js:""}
};
let activeThemeSlug='core';

function deepMergeTheme(base,extra){
  const out=JSON.parse(JSON.stringify(base));
  const merge=(a,b)=>{Object.keys(b||{}).forEach(k=>{if(b[k]&&typeof b[k]==='object'&&!Array.isArray(b[k])&&a[k]&&typeof a[k]==='object')merge(a[k],b[k]);else a[k]=b[k]})};
  merge(out,extra||{});return out;
}
function themeValue(id){return document.getElementById('th_'+id)?.value??''}
function themeChecked(id){return !!document.getElementById('th_'+id)?.checked}
function themeColor(id){return themeValue(id)||'#000000'}
function mediaOptions(selected=''){
  const imgs=(state.themeMedia||[]).filter(x=>x.type==='image');
  return '<option value="">— NONE —</option>'+imgs.map(x=>`<option value="${esc(x.file_path)}" ${x.file_path===selected?'selected':''}>${esc(x.title||x.file_path)}</option>`).join('');
}
function themeInput(id,label,val,type='text',extra=''){return `<div class="theme-field"><label>${label}</label><input id="th_${id}" type="${type}" value="${esc(val??'')}" ${extra}></div>`}
function themeSelect(id,label,val,opts){return `<div class="theme-field"><label>${label}</label><select id="th_${id}">${opts.map(o=>`<option value="${esc(o[0])}" ${String(o[0])===String(val)?'selected':''}>${esc(o[1])}</option>`).join('')}</select></div>`}
function themeColorField(id,label,val){return `<div class="theme-field"><label>${label}</label><div class="theme-color"><input id="th_${id}_picker" type="color" value="${esc(val||'#000000')}" oninput="document.getElementById('th_${id}').value=this.value;updateThemePreview()"><input id="th_${id}" value="${esc(val||'#000000')}" oninput="document.getElementById('th_${id}_picker').value=this.value;updateThemePreview()"></div></div>`}
function themeCheck(id,label,val){return `<label class="theme-check"><span>${label}</span><input id="th_${id}" type="checkbox" ${val?'checked':''} onchange="updateThemePreview()"></label>`}
function themeMediaField(id,label,val){return `<div class="theme-field"><label>${label}</label><select id="th_${id}" onchange="updateThemePreview()">${mediaOptions(val)}</select><input style="margin-top:6px" id="th_${id}_custom" placeholder="O escribe ruta/URL..." value="${esc(val||'')}" oninput="updateThemePreview()"></div>`}
function currentThemeFromForm(){
  const t=deepMergeTheme(THEME_DEFAULT,state.theme||{});
  t.name=themeValue('name')||t.name;t.slug=themeValue('slug')||t.slug;
  t.branding={...t.branding,siteName:themeValue('siteName'),tagline:themeValue('tagline'),logo:themeValue('logo_custom')||themeValue('logo'),mobileLogo:themeValue('mobileLogo_custom')||themeValue('mobileLogo'),favicon:themeValue('favicon_custom')||themeValue('favicon'),preloaderLogo:themeValue('preloaderLogo_custom')||themeValue('preloaderLogo')};
  t.colors={...t.colors,bg:themeColor('bg'),surface:themeColor('surface'),text:themeColor('text'),muted:themeColor('muted'),primary:themeColor('primary'),accent:themeColor('accent'),border:themeColor('border')};
  t.typography={...t.typography,display:themeValue('display'),body:themeValue('body'),mono:themeValue('mono'),h1:themeValue('h1'),bodySize:themeValue('bodySize'),tracking:themeValue('tracking')};
  t.navigation={...t.navigation,fixed:themeChecked('navFixed'),transparentHero:themeChecked('navTransparent'),blur:themeChecked('navBlur'),menuStyle:themeValue('menuStyle'),menuAnimation:themeValue('menuAnimation'),logoPosition:themeValue('logoPosition'),sceneIndicator:themeChecked('sceneIndicator'),soundToggle:themeChecked('soundToggle')};
  t.effects={...t.effects,grain:themeChecked('grain'),scanlines:themeChecked('scanlines'),glitch:themeChecked('glitch'),distortion:themeChecked('distortion'),cursor:themeChecked('cursor'),magnetic:themeChecked('magnetic'),pageTransitions:themeChecked('pageTransitions'),parallax:themeChecked('parallax'),horizontalScroll:themeChecked('horizontalScroll'),webgl:themeChecked('webgl'),motion:themeValue('motion')};
  t.sound={...t.sound,enabled:themeChecked('soundEnabled'),defaultState:themeValue('soundDefault'),uiSounds:themeChecked('uiSounds'),masterVolume:Number(themeValue('masterVolume')||.65)};
  t.preloader={...t.preloader,enabled:themeChecked('preloaderEnabled'),animation:themeValue('preloaderAnimation'),duration:Number(themeValue('preloaderDuration')||1200),text:themeValue('preloaderText')};
  t.responsive={...t.responsive,desktopScale:Number(themeValue('desktopScale')||1),tabletScale:Number(themeValue('tabletScale')||.82),mobileScale:Number(themeValue('mobileScale')||.55)};
  t.analytics={...t.analytics,google:themeValue('analyticsGoogle'),gtm:themeValue('analyticsGtm'),meta:themeValue('analyticsMeta'),headCode:themeValue('analyticsHead'),bodyCode:themeValue('analyticsBody')};
  t.seo={...t.seo,siteTitle:themeValue('seoTitle'),description:themeValue('seoDescription'),ogImage:themeValue('ogImage_custom')||themeValue('ogImage'),canonical:themeValue('canonical'),sitemap:themeChecked('seoSitemap'),robots:themeChecked('seoRobots'),schema:themeChecked('seoSchema'),openGraph:themeChecked('seoOG'),twitterCards:themeChecked('seoTwitter')};
  t.customCode={...t.customCode,head:themeValue('codeHead'),bodyStart:themeValue('codeBodyStart'),bodyEnd:themeValue('codeBodyEnd'),css:themeValue('codeCss'),js:themeValue('codeJs')};
  return t;
}
function themePanel(t){
  const b=t.branding||{},c=t.colors||{},ty=t.typography||{},n=t.navigation||{},e=t.effects||{},so=t.sound||{},p=t.preloader||{},r=t.responsive||{},a=t.analytics||{},seo=t.seo||{},cc=t.customCode||{};
  return `<div class="theme-shell">
    <section class="theme-panel">
      <div class="theme-panel-head"><strong>THEME STUDIO</strong><div class="helper">Configura la identidad visual sin editar código.</div></div>
      <div class="theme-tabs">
        ${[['identity','IDENTITY'],['colors','COLORS'],['nav','NAV'],['effects','EFFECTS'],['sound','SOUND'],['seo','SEO'],['code','CODE']].map((x,i)=>`<button class="theme-tab ${i===0?'active':''}" data-pane="${x[0]}" onclick="themeTab('${x[0]}')">${x[1]}</button>`).join('')}
      </div>
      <div class="theme-pane active" id="pane-identity">
        <div class="theme-group"><h3>THEME</h3>${themeInput('name','Theme name',t.name)}${themeInput('slug','Theme slug',t.slug)}</div>
        <div class="theme-group"><h3>BRANDING</h3>${themeInput('siteName','Site name',b.siteName)}${themeInput('tagline','Tagline',b.tagline)}${themeMediaField('logo','Main logo',b.logo)}${themeMediaField('mobileLogo','Mobile logo',b.mobileLogo)}${themeMediaField('favicon','Favicon',b.favicon)}${themeMediaField('preloaderLogo','Preloader logo',b.preloaderLogo)}</div>
        <div class="theme-group"><h3>PRESETS</h3><div class="theme-actions"><button class="btn ghost" onclick="loadThemePreset('core')">BRVTAL CORE</button><button class="btn ghost" onclick="loadThemePreset('genesis')">GENESIS</button><button class="btn ghost" onclick="duplicateTheme()">DUPLICATE</button></div><div class="theme-note" style="margin-top:9px">Los presets son configuraciones completas. Guardar crea/actualiza <b>theme.&lt;slug&gt;</b>.</div></div>
      </div>
      <div class="theme-pane" id="pane-colors">
        <div class="theme-group"><h3>GLOBAL COLORS</h3>${themeColorField('bg','Background',c.bg)}${themeColorField('surface','Surface',c.surface)}${themeColorField('text','Text',c.text)}${themeColorField('muted','Muted',c.muted)}${themeColorField('primary','Primary',c.primary)}${themeColorField('accent','Accent',c.accent)}${themeColorField('border','Border',c.border)}<div class="theme-swatches">${[c.bg,c.surface,c.text,c.primary,c.accent,c.border].map(x=>`<i style="background:${esc(x)}"></i>`).join('')}</div></div>
      </div>
      <div class="theme-pane" id="pane-nav">
        <div class="theme-group"><h3>HEADER / MENU</h3>${themeCheck('navFixed','Fixed header',n.fixed)}${themeCheck('navTransparent','Transparent over hero',n.transparentHero)}${themeCheck('navBlur','Blur background',n.blur)}${themeSelect('menuStyle','Menu style',n.menuStyle,[['fullscreen','Fullscreen'],['dropdown','Dropdown'],['slide','Slide panel']])}${themeSelect('menuAnimation','Menu animation',n.menuAnimation,[['glitch','Glitch'],['fade','Fade'],['slide','Slide'],['none','None']])}${themeSelect('logoPosition','Logo position',n.logoPosition,[['left','Left'],['center','Center']])}${themeCheck('sceneIndicator','Scene indicator',n.sceneIndicator)}${themeCheck('soundToggle','Sound toggle',n.soundToggle)}</div>
        <div class="theme-group"><h3>TYPOGRAPHY</h3>${themeInput('display','Display font',ty.display)}${themeInput('body','Body font',ty.body)}${themeInput('mono','Monospace',ty.mono)}${themeInput('h1','H1 scale',ty.h1)}${themeInput('bodySize','Body size',ty.bodySize)}${themeInput('tracking','Display tracking',ty.tracking)}</div>
        <div class="theme-group"><h3>RESPONSIVE</h3><div class="theme-two">${themeInput('desktopScale','Desktop scale',r.desktopScale,'number','step="0.01" min="0.5" max="2"')}${themeInput('tabletScale','Tablet scale',r.tabletScale,'number','step="0.01" min="0.4" max="1.5"')}${themeInput('mobileScale','Mobile scale',r.mobileScale,'number','step="0.01" min="0.3" max="1.2"')}</div></div>
      </div>
      <div class="theme-pane" id="pane-effects">
        <div class="theme-group"><h3>VISUAL EFFECTS</h3>${themeCheck('grain','Grain',e.grain)}${themeCheck('scanlines','Scanlines',e.scanlines)}${themeCheck('glitch','Glitch',e.glitch)}${themeCheck('distortion','Image distortion',e.distortion)}${themeCheck('cursor','Cursor',e.cursor)}${themeCheck('magnetic','Magnetic buttons',e.magnetic)}${themeCheck('pageTransitions','Page transitions',e.pageTransitions)}${themeCheck('parallax','Parallax',e.parallax)}${themeCheck('horizontalScroll','Horizontal scroll',e.horizontalScroll)}${themeCheck('webgl','WebGL effects',e.webgl)}${themeSelect('motion','Motion profile',e.motion,[['brvtal','BRVTAL'],['subtle','SUBTLE'],['minimal','MINIMAL'],['reduced','REDUCED']])}</div>
        <div class="theme-group"><h3>PRELOADER</h3>${themeCheck('preloaderEnabled','Enable preloader',p.enabled)}${themeSelect('preloaderAnimation','Animation',p.animation,[['glitch','Glitch'],['scale','Scale'],['fade','Fade'],['none','None']])}${themeInput('preloaderDuration','Minimum duration (ms)',p.duration,'number')}${themeInput('preloaderText','Loading text',p.text)}</div>
      </div>
      <div class="theme-pane" id="pane-sound">
        <div class="theme-group"><h3>SOUND EXPERIENCE</h3>${themeCheck('soundEnabled','Enable sound system',so.enabled)}${themeSelect('soundDefault','Default state',so.defaultState,[['off','OFF'],['on','ON']])}${themeCheck('uiSounds','UI sounds',so.uiSounds)}${themeInput('masterVolume','Master volume',so.masterVolume,'number','step="0.05" min="0" max="1"')}</div>
      </div>
      <div class="theme-pane" id="pane-seo">
        <div class="theme-group"><h3>ANALYTICS / TRACKING</h3>${themeInput('analyticsGoogle','Google Analytics ID',a.google)}${themeInput('analyticsGtm','Google Tag Manager ID',a.gtm)}${themeInput('analyticsMeta','Meta Pixel ID',a.meta)}${themeInput('analyticsHead','Head code',a.headCode)}${themeInput('analyticsBody','Body code',a.bodyCode)}</div>
        <div class="theme-group"><h3>GLOBAL SEO</h3>${themeInput('seoTitle','Site title',seo.siteTitle)}${themeInput('seoDescription','Meta description',seo.description)}${themeMediaField('ogImage', 'Default OG image', seo.ogImage)}${themeInput('canonical','Canonical domain',seo.canonical)}${themeCheck('seoSitemap','Generate sitemap',seo.sitemap)}${themeCheck('seoRobots','Generate robots.txt',seo.robots)}${themeCheck('seoSchema','Generate Schema.org',seo.schema)}${themeCheck('seoOG','Open Graph',seo.openGraph)}${themeCheck('seoTwitter','Twitter/X cards',seo.twitterCards)}</div>
      </div>
      <div class="theme-pane" id="pane-code">
        <div class="theme-group"><h3>CUSTOM CODE</h3><div class="theme-note" style="margin-bottom:10px">Usa esto para integraciones como Analytics, GTM o scripts propios. El CMS guarda el código en la configuración del tema.</div>${`<div class="theme-field"><label>Head code</label><textarea id="th_codeHead">${esc(cc.head||'')}</textarea></div>`}${`<div class="theme-field"><label>Body start</label><textarea id="th_codeBodyStart">${esc(cc.bodyStart||'')}</textarea></div>`}${`<div class="theme-field"><label>Body end</label><textarea id="th_codeBodyEnd">${esc(cc.bodyEnd||'')}</textarea></div>`}${`<div class="theme-field"><label>Custom CSS</label><textarea id="th_codeCss">${esc(cc.css||'')}</textarea></div>`}${`<div class="theme-field"><label>Custom JS</label><textarea id="th_codeJs">${esc(cc.js||'')}</textarea></div>`}</div>
      </div>
      <div class="theme-panel-head" style="border-top:1px solid #24282c;border-bottom:0;display:flex;gap:7px;flex-wrap:wrap"><button class="btn red" onclick="saveTheme()">SAVE THEME</button><button class="btn ghost" onclick="activateTheme()">ACTIVATE</button><button class="btn ghost" onclick="exportTheme()">EXPORT</button><label class="btn ghost" style="cursor:pointer">IMPORT<input class="theme-file" type="file" accept="application/json" onchange="importTheme(event)"></label></div>
    </section>
    <section class="theme-preview">
      <div class="theme-preview-head"><strong>LIVE PREVIEW</strong><div class="helper">La previsualización cambia al instante; guardar publica la configuración del tema.</div></div>
      <div class="theme-preview-wrap"><div class="theme-preview-canvas" id="theme-preview">
        <div class="preview-nav"><div class="preview-logo" id="pv-logo">BRVTAL</div><div class="preview-menu">+ MENU</div></div>
        <div class="preview-hero"><div class="preview-gridline"></div><div class="preview-kicker" id="pv-kicker">RAVE TILL GRAVE / SYSTEM ONLINE</div><div class="preview-title">RAVE<br><span class="preview-accent">TILL</span><br>GRAVE</div><div class="preview-copy">Underground electronic music. Events, artists, sets and visual systems — built as one living identity.</div><div class="preview-cta">ENTER THE SYSTEM</div><div class="preview-footer"><span>BRVTAL / PREVIEW</span><span id="pv-status">CORE</span></div></div>
      </div></div>
    </section>
  </div>`;
}
function themeTab(name){
  document.querySelectorAll('.theme-tab').forEach(x=>x.classList.toggle('active',x.dataset.pane===name));
  document.querySelectorAll('.theme-pane').forEach(x=>x.classList.toggle('active',x.id==='pane-'+name));
}
function updateThemePreview(){
  const bg=themeColor('bg'),text=themeColor('text'),accent=themeColor('accent')||themeColor('primary');
  const pv=document.getElementById('theme-preview');if(!pv)return;
  pv.style.setProperty('--preview-bg',bg);pv.style.setProperty('--preview-text',text);pv.style.setProperty('--preview-accent',accent);
  const logo=document.getElementById('pv-logo');if(logo)logo.textContent=themeValue('siteName')||'BRVTAL';
  const kicker=document.getElementById('pv-kicker');if(kicker)kicker.textContent=(themeValue('tagline')||'RAVE TILL GRAVE')+' / SYSTEM ONLINE';
  const status=document.getElementById('pv-status');if(status)status.textContent=(themeValue('name')||'CORE').toUpperCase();
}
function loadThemePreset(kind){
  const base=deepMergeTheme(THEME_DEFAULT,{});
  let t=base;
  if(kind==='genesis'){
    t.name='GENESIS';t.slug='genesis';t.branding.tagline='GENESIS / BRVTAL';t.colors.primary='#B6FF00';t.colors.accent='#B6FF00';t.colors.border='#343b2b';
    t.effects.glitch=true;t.effects.distortion=true;
  }
  state.theme=t;activeThemeSlug=t.slug;renderThemeStudio(t);updateThemePreview();
}
async function loadThemeStudio(){
  try{const [sr,mr]=await Promise.all([req('/settings'),req('/media')]);state.themeSettings=sr.data||[];state.themeMedia=mr.data||[];}catch(e){state.themeSettings=[];state.themeMedia=[]}
  const settings=state.themeSettings;
  const active=settings.find(x=>x.setting_key==='theme.active');
  activeThemeSlug=active?String(active.setting_value||'core'):'core';
  const rec=settings.find(x=>x.setting_key==='theme.'+activeThemeSlug);
  let t=rec?rec.setting_value:THEME_DEFAULT;
  try{if(typeof t==='string')t=JSON.parse(t)}catch(e){t=THEME_DEFAULT}
  state.theme=deepMergeTheme(THEME_DEFAULT,t||{});
  renderThemeStudio(state.theme);
}
function renderThemeStudio(t){
  const root=document.getElementById('theme-root');if(root)root.innerHTML=themePanel(t);
  setTimeout(updateThemePreview,20);
}
async function saveTheme(){
  const t=currentThemeFromForm();if(!t.slug)return;
  t.name=t.name||t.slug.toUpperCase();
  try{
    await req('/settings',{method:'POST',body:JSON.stringify({setting_key:'theme.'+t.slug,setting_value:JSON.stringify(t),is_json:1})});
    state.theme=t;activeThemeSlug=t.slug;
    await req('/settings',{method:'POST',body:JSON.stringify({setting_key:'theme.active',setting_value:t.slug,is_json:0})});
    showThemeNotice('THEME SAVED · '+t.name);
    await loadThemeStudio();
  }catch(e){showThemeNotice('ERROR · '+e.message,true)}
}
async function activateTheme(){
  const t=currentThemeFromForm();
  try{await req('/settings',{method:'POST',body:JSON.stringify({setting_key:'theme.'+t.slug,setting_value:JSON.stringify(t),is_json:1})});await req('/settings',{method:'POST',body:JSON.stringify({setting_key:'theme.active',setting_value:t.slug,is_json:0})});showThemeNotice('ACTIVE THEME · '+t.name);await loadThemeStudio()}catch(e){showThemeNotice('ERROR · '+e.message,true)}
}
function duplicateTheme(){
  const t=currentThemeFromForm();t.slug=(t.slug||'theme')+'-'+Date.now().toString().slice(-4);t.name=(t.name||'THEME')+' COPY';state.theme=t;renderThemeStudio(t);showThemeNotice('COPY READY · SAVE THEME')}
function showThemeNotice(msg,bad=false){
  let n=document.getElementById('theme-notice');if(!n){n=document.createElement('div');n.id='theme-notice';n.className='notice';document.querySelector('.main')?.prepend(n)}
  n.textContent=msg;n.className='notice show '+(bad?'error':'');setTimeout(()=>n.className='notice',3500)
}
function exportTheme(){
  const t=currentThemeFromForm();const blob=new Blob([JSON.stringify(t,null,2)],{type:'application/json'});const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=(t.slug||'brvtal-theme')+'.json';a.click();setTimeout(()=>URL.revokeObjectURL(a.href),500)
}
function importTheme(e){
  const f=e.target.files?.[0];if(!f)return;const rd=new FileReader();rd.onload=()=>{try{const t=deepMergeTheme(THEME_DEFAULT,JSON.parse(rd.result));state.theme=t;renderThemeStudio(t);showThemeNotice('THEME IMPORTED · SAVE TO APPLY')}catch(x){showThemeNotice('INVALID THEME JSON',true)}};rd.readAsText(f)
}

function technicalContent(){
  if(state.section==='technical'||state.section==='system'||state.section==='health'||state.section==='logs'||state.section==='database'||state.section==='storage'||state.section==='php'||state.section==='tester'){
    return `<div class="system-status-shell" id="system-status">
      <div class="sectionhead"><div><strong>SYSTEM STATUS</strong><div class="helper" style="margin-top:5px">BRVTAL technical control · all diagnostics in one place</div></div><div class="techactions"><button class="btn" onclick="loadAllTech()">REFRESH ALL</button></div></div>
      <div class="system-status-grid">
        <div class="system-status-card" id="status-web-php"><div class="eyebrow">WEB / PHP</div><b id="status-php">CHECKING</b><div class="meta" id="status-php-meta">Runtime</div></div>
        <div class="system-status-card" id="status-api"><div class="eyebrow">API</div><b id="status-api-value">CHECKING</b><div class="meta">API + database connectivity</div></div>
        <div class="system-status-card" id="status-db"><div class="eyebrow">DATABASE</div><b id="status-db-value">CHECKING</b><div class="meta" id="status-db-meta">Engine &amp; records</div></div>
        <div class="system-status-card" id="status-storage"><div class="eyebrow">STORAGE</div><b id="status-storage-value">CHECKING</b><div class="meta">Disk, uploads &amp; logs</div></div>
      </div>
      <div class="system-detail-grid">
        <details class="system-detail" open><summary>SYSTEM RUNTIME</summary><pre id="tech-system">LOADING...</pre></details>
        <details class="system-detail"><summary>API HEALTH</summary><pre id="tech-health">LOADING...</pre></details>
        <details class="system-detail"><summary>DATABASE</summary><pre id="tech-database">LOADING...</pre></details>
        <details class="system-detail"><summary>STORAGE</summary><pre id="tech-storage">LOADING...</pre></details>
        <details class="system-detail"><summary>PHP INFO</summary><pre id="tech-php">LOADING...</pre></details>
        <details class="system-detail"><summary>SYSTEM LOGS</summary><pre id="tech-logs">LOADING...</pre></details>
        <details class="system-detail wide"><summary>API TESTER — ADVANCED</summary>
          <div class="system-tester-grid"><div>${['health','public','dashboard','events','artists','sets','media','pages','settings'].map(a=>`<button class="iconbtn" style="display:block;width:100%;margin-bottom:7px;text-align:left" onclick="runApiTest('${a}')">GET /${a}</button>`).join('')}</div><pre id="techout">Select an endpoint.</pre></div>
        </details>
      </div>
    </div>`;
  }
  return '';
}
function techPanel(title,action,label){return `<div class="section"><div class="sectionhead"><strong>${title}</strong><div class="techactions"><button class="btn ghost" onclick="tech('technical')">← TECHNICAL</button><button class="btn" onclick="loadTech('${action}')">REFRESH</button></div></div><div class="helper" style="margin-bottom:10px">Authenticated diagnostic · auto-refresh every 30s for live status panels.</div><pre class="logbox" id="techout">Loading...</pre></div>`}
async function runApiTest(endpoint){const box=document.getElementById('techout');box.textContent='GET /'+endpoint+'\n\nLOADING...';try{const r=await fetch(API+'/'+endpoint,{cache:'no-store',credentials:'same-origin'});const text=await r.text();box.textContent='HTTP '+r.status+'\n\n'+text}catch(e){box.textContent='ERROR: '+e.message}}

function settingRecord(key){return (state.rows||[]).find(x=>String(x.setting_key)===String(key))||null}
function settingSummary(key){
  const r=settingRecord(key); if(!r)return 'Not configured';
  const v=String(r.setting_value??'').trim(); if(!v)return 'Configured · empty value';
  try{const j=JSON.parse(v);if(j&&typeof j==='object')return Object.keys(j).length+' configuration fields'}catch(e){}
  return 'Configured';
}
function openSettingByKey(key){
  const r=settingRecord(key);
  state.editing=key;
  document.getElementById('modal').classList.add('open');
  document.getElementById('notice').className='notice';
  document.getElementById('mtitle').textContent=(r?'EDIT ':'NEW ')+key.toUpperCase();
  document.getElementById('saveBtn').onclick=()=>save('settings',key);
  settingsForm(r);
}
function settingsHome(rows){
  rows=rows||[];
  const categories=[
    ['site','◉','GENERAL','Site identity, language and core website configuration.'],
    ['social','◎','SOCIAL','Instagram, SoundCloud, YouTube, Spotify and social links.'],
    ['appearance','✦','APPEARANCE','Legacy visual settings. New visual controls live in Theme Studio.'],
    ['theme.active','◈','ACTIVE THEME','Which visual theme is currently active.'],
    ['analytics','⌁','ANALYTICS','Google Analytics, Tag Manager, Meta Pixel and tracking.'],
    ['seo','⌖','SEO','Global title, descriptions, canonical and search configuration.']
  ];
  const known=new Set(categories.map(x=>x[0]));
  const extra=rows.filter(r=>!known.has(String(r.setting_key)) && !String(r.setting_key).startsWith('theme.'));
  const themeCount=rows.filter(r=>String(r.setting_key).startsWith('theme.')).length;
  return `<div class="settings-home">
    <div class="settings-hero">
      <div><div class="eyebrow">BRVTAL CMS / CONFIGURATION</div><h2>SETTINGS</h2><p>Configuración del sistema organizada por función. Para cambiar la apariencia del sitio utiliza <b>THEME STUDIO</b>; aquí quedan los ajustes globales, integraciones y opciones avanzadas.</p></div>
      <input class="settings-search" id="settings-filter" placeholder="Search settings..." oninput="filterSettingsHome(this.value)">
    </div>
    <div class="settings-grid" id="settings-grid">
      ${categories.map(c=>`<article class="settings-card" data-setting-card="${c[0]}"><div><div class="icon">${c[1]}</div><h3>${c[2]}</h3><p>${c[3]}</p><div class="meta">${c[0]==='theme.active'?'ACTIVE: '+(settingRecord('theme.active')?.setting_value||'CORE'):settingSummary(c[0])}</div></div><div class="actions"><button class="iconbtn" onclick="openSettingByKey('${c[0]}')">EDIT</button>${c[0]==='theme.active'?`<button class="iconbtn" onclick="go('theme')">THEME STUDIO</button>`:''}</div></article>`).join('')}
    </div>
    <details class="settings-advanced">
      <summary>ADVANCED / RAW SETTINGS · ${extra.length} ITEMS · ${themeCount} THEME RECORDS</summary>
      <div class="settings-raw">
        <div class="settings-raw-head"><span>SETTING</span><span>TYPE</span><span>VALUE</span><span></span></div>
        ${extra.length?extra.map(r=>`<div class="settings-raw-row"><div class="key">${esc(r.setting_key)}</div><div><span class="settings-pill">${r.is_json?'JSON':'TEXT'}</span></div><div class="value">${esc(String(r.setting_value??''))}</div><div><button class="iconbtn" onclick="openSettingByKey('${esc(String(r.setting_key).replace(/'/g,"&#039;"))}')">EDIT</button></div></div>`).join(''):'<div style="padding:20px;color:#666">No advanced settings.</div>'}
      </div>
    </details>
  </div>`;
}
function filterSettingsHome(q){
  q=String(q||'').toLowerCase();
  document.querySelectorAll('[data-setting-card]').forEach(el=>el.style.display=el.textContent.toLowerCase().includes(q)?'':'none');
}

function content(){
if(state.section==='theme')return '<div id="theme-root"></div>';
if(state.section==='settings')return settingsHome(state.rows||[]);

 if(['technical','system','health','logs','database','storage','php','tester'].includes(state.section)) return technicalContent();
 if(state.section==='dashboard'){const d=state.dashboard||{};const recent=state.recent||{};const events=recent.events||[],artists=recent.artists||[],sets=recent.sets||[],media=recent.media||[];const pubE=d.published_events??0,pubA=d.published_artists??0,analytics=d.analytics_30d??0;const evPct=d.events?Math.round(pubE/d.events*100):0,arPct=d.artists?Math.round(pubA/d.artists*100):0;return `<div class="stats"><div class="stat"><div class="eyebrow">EVENTS</div><div class="n">${d.events??0}</div><div class="meta">${pubE} published</div></div><div class="stat"><div class="eyebrow">ARTISTS</div><div class="n">${d.artists??0}</div><div class="meta">${pubA} published</div></div><div class="stat"><div class="eyebrow">SETS / SOUND</div><div class="n">${d.sets_media??0}</div><div class="meta">Audio archive</div></div><div class="stat"><div class="eyebrow">MEDIA</div><div class="n">${d.media??0}</div><div class="meta">Assets library</div></div></div><div class="system-pulse" id="system-pulse">
<div class="system-pulse-head"><div><div class="system-pulse-kicker">BRVTAL SYSTEM</div><div class="system-pulse-title">SYSTEM PULSE</div><div class="system-pulse-sub">Estado técnico en tiempo real</div></div><a class="system-health" href="#" onclick="tech('system');return false"><i class="system-health-dot"></i><strong id="pulse-health">—</strong><span>HEALTH</span></a></div>
<div class="system-pulse-grid">
<a class="system-pulse-card" href="#" onclick="tech('system');return false" data-pulse-target="web"><span class="system-pulse-label"><i></i>WEB / PHP</span><b class="system-pulse-value" id="pulse-web">CHECKING</b><small class="system-pulse-meta" id="pulse-web-meta">Runtime</small></a>
<a class="system-pulse-card" href="#" onclick="tech('system');return false" data-pulse-target="api"><span class="system-pulse-label"><i></i>API</span><b class="system-pulse-value" id="pulse-api">CHECKING</b><small class="system-pulse-meta">Connectivity</small></a>
<a class="system-pulse-card" href="#" onclick="tech('system');return false" data-pulse-target="database"><span class="system-pulse-label"><i></i>DATABASE</span><b class="system-pulse-value" id="pulse-db">CHECKING</b><small class="system-pulse-meta">Engine &amp; connection</small></a>
<a class="system-pulse-card" href="#" onclick="tech('system');return false" data-pulse-target="storage"><span class="system-pulse-label"><i></i>STORAGE</span><b class="system-pulse-value" id="pulse-storage">CHECKING</b><small class="system-pulse-meta">Uploads &amp; logs</small></a>
</div></div><div class="dashsection"><div class="sectionhead"><strong>QUICK ACTIONS</strong><span class="helper">Crear contenido</span></div><div class="quickgrid"><button class="btn red" onclick="openModal('events')">+ EVENT</button><button class="btn ghost" onclick="openModal('artists')">+ ARTIST</button><button class="btn ghost" onclick="openModal('sets')">+ SET</button><button class="btn ghost" onclick="openModal('media')">+ MEDIA</button></div></div><div class="dashgrid"><div class="dashsection"><div class="sectionhead"><strong>RECENT EVENTS</strong><button class="iconbtn" onclick="go('events')">VIEW ALL</button></div><div class="dashlist">${events.map(x=>`<div class="dashitem">${thumb(x.cover_image,x.title)}<div class="grow"><div class="title">${esc(x.title)}</div><div class="meta">${esc(x.event_date||'No date')} · ${esc(x.city||x.venue||'No location')}</div></div><span class="pill">${esc(x.status)}</span></div>`).join('')||'<div class="empty">No events.</div>'}</div></div><div class="dashsection"><div class="sectionhead"><strong>RECENT ARTISTS</strong><button class="iconbtn" onclick="go('artists')">VIEW ALL</button></div><div class="dashlist">${artists.map(x=>`<div class="dashitem">${thumb(x.photo,x.name)}<div class="grow"><div class="title">${esc(x.name)}</div><div class="meta">${esc(x.slug)}</div></div><span class="pill">${esc(x.status)}</span></div>`).join('')||'<div class="empty">No artists.</div>'}</div></div></div><div class="dashgrid"><div class="dashsection"><div class="sectionhead"><strong>RECENT SETS</strong><button class="iconbtn" onclick="go('sets')">VIEW ALL</button></div><div class="dashlist">${sets.map(x=>`<div class="dashitem">${thumb(x.cover_image,x.title)}<div class="grow"><div class="title">${esc(x.title)}</div><div class="meta">${esc(x.artist_name||'Unknown artist')} · ${esc(x.event_title||'Unlinked event')}</div></div><span class="pill">${esc(x.platform)}</span></div>`).join('')||'<div class="empty">No sets.</div>'}</div></div><div class="dashsection"><div class="sectionhead"><strong>MEDIA / LATEST</strong><button class="iconbtn" onclick="go('media')">VIEW ALL</button></div><div class="dashlist">${media.map(x=>`<div class="dashitem">${x.type==='image'?thumb(x.file_path,x.title):`<div class="thumbph">${esc(x.type).toUpperCase()}</div>`}<div class="grow"><div class="title">${esc(x.title)}</div><div class="meta">${esc(x.mime_type||x.type)}</div></div><span class="pill">${esc(x.status)}</span></div>`).join('')||'<div class="empty">No media.</div>'}</div></div></div><div class="dashgrid"><div class="dashsection"><div class="sectionhead"><strong>PUBLICATION HEALTH</strong><span class="helper">Content visibility</span></div><div class="metricrow"><div class="metric"><span class="eyebrow">EVENTS PUBLISHED</span><b>${evPct}%</b><div class="bar"><i style="width:${evPct}%"></i></div></div><div class="metric"><span class="eyebrow">ARTISTS PUBLISHED</span><b>${arPct}%</b><div class="bar"><i style="width:${arPct}%"></i></div></div><div class="metric"><span class="eyebrow">ANALYTICS / 30D</span><b>${analytics}</b><div class="meta">tracked events</div></div></div></div><div class="dashsection"><div class="sectionhead"><strong>TECHNICAL SNAPSHOT</strong><button class="btn ghost" onclick="go('theme')">THEME STUDIO</button><button class="btn ghost" onclick="tech('system')">SYSTEM STATUS</button></div><div class="techgrid"><div class="techcard"><h3>API</h3><div class="techvalue" id="dash_api">CHECKING</div></div><div class="techcard"><h3>DATABASE</h3><div class="techvalue" id="dash_db">CHECKING</div></div><div class="techcard"><h3>PHP</h3><div class="techvalue">8.3+</div></div></div></div></div>`} 
 const r=state.rows||[];let button=`<button class="btn red" onclick="openModal('${state.section}')">+ NEW ${state.section.slice(0,-1).toUpperCase()}</button>`;
 if(state.section==='events')button='<button class="btn red" onclick="openModal(\'events\')">+ NEW EVENT</button>';
 if(state.section==='artists')button='<button class="btn red" onclick="openModal(\'artists\')">+ NEW ARTIST</button>';
 if(state.section==='sets')button='<button class="btn red" onclick="openModal(\'sets\')">+ NEW SET</button>';
 if(state.section==='media')button='<button class="btn red" onclick="openModal(\'media\')">+ NEW MEDIA</button>';
 if(state.section==='pages')button='<button class="btn red" onclick="openModal(\'pages\')">+ NEW PAGE</button>';
 if(state.section==='settings')button='<button class="btn ghost" onclick="openModal(\'settings\')">+ ADVANCED SETTING</button>';
 let rows='';
 if(state.section==='events')rows=r.map(x=>`<div class="tr"><div class="thumbcell">${thumb(x.cover_image,x.title)}<div><div class="title">${esc(x.title)}</div><div class="meta">${esc(x.slug)}</div></div></div><div>${esc(x.event_date||'—')}</div><div>${esc(x.city||x.venue||'—')}</div><div><span class="pill">${esc(x.status)}</span></div><div class="actions"><button class="iconbtn" onclick="openLineup(${x.id})">LINEUP</button><button class="iconbtn" onclick="openModal('events',${x.id})">EDIT</button><button class="iconbtn" onclick="del('events',${x.id})">DEL</button></div></div>`).join('');
 else if(state.section==='artists')rows=r.map(x=>`<div class="tr"><div class="cell">${x.photo?`<img class="avatar" src="${esc(x.photo)}">`:'<div class="avatar"></div>'}<div><div class="title">${esc(x.name)}</div><div class="meta">${esc(x.slug)}</div></div></div><div>${x.soundcloud_url?'SOUNDCLOUD':''}</div><div><span class="pill">${esc(x.status)}</span></div><div>${esc(x.sort_order??0)}</div><div class="actions"><button class="iconbtn" onclick="openModal('artists',${x.id})">EDIT</button><button class="iconbtn" onclick="del('artists',${x.id})">DEL</button></div></div>`).join('');
 else if(state.section==='sets')rows=r.map(x=>`<div class="tr"><div class="thumbcell">${thumb(x.cover_image,x.title)}<div><div class="title">${esc(x.title)}</div><div class="meta">${esc(x.artist_name||'—')} · ${esc(x.event_title||'—')}</div></div></div><div>${esc(x.artist_name||x.artist_id||'—')}</div><div>${esc(x.event_title||x.event_id||'—')}</div><div><span class="pill">${esc(x.status)}</span></div><div class="actions"><button class="iconbtn" onclick="openModal('sets',${x.id})">EDIT</button><button class="iconbtn" onclick="del('sets',${x.id})">DEL</button></div></div>`).join('');
 else if(state.section==='media')rows=r.map(x=>`<div class="tr"><div class="thumbcell">${x.type==='image'?thumb(x.file_path,x.title):`<div class="thumbph">${esc(x.type).toUpperCase()}</div>`}<div><div class="title">${esc(x.title)}</div><div class="meta">${esc(x.file_path)}</div></div></div><div>${esc(x.type)}</div><div>${esc(x.mime_type||'')}</div><div><span class="pill">${esc(x.status)}</span></div><div class="actions"><button class="iconbtn" onclick="openModal('media',${x.id})">EDIT</button><button class="iconbtn" onclick="del('media',${x.id})">DEL</button></div></div>`).join('');
 else if(state.section==='pages')rows=r.map(x=>`<div class="tr"><div class="thumbcell">${thumb(pageImage(x.content_json),x.title)}<div><div class="title">${esc(x.title)}</div><div class="meta">${esc(x.slug)}</div></div></div><div>${esc(x.locale)}</div><div>${esc(x.status)}</div><div></div><div class="actions"><button class="iconbtn" onclick="openModal('pages',${x.id})">EDIT</button><button class="iconbtn" onclick="del('pages',${x.id})">DEL</button></div></div>`).join('');
 else if(state.section==='settings')rows='';
 const headers=state.section==='events'?['EVENT','DATE','LOCATION','STATUS','']:state.section==='artists'?['ARTIST','LINKS','STATUS','ORDER','']:state.section==='sets'?['SET','ARTIST','EVENT','STATUS','']:state.section==='media'?['MEDIA','TYPE','MIME','STATUS','']:state.section==='pages'?['PAGE','LOCALE','STATUS','','']:['SETTING','TYPE','VALUE','',''];
 return `<div class="toolbar"><input class="search" placeholder="Search ${state.section}..." oninput="filterRows(this.value)">${button}</div><div class="table"><div class="thead">${headers.map(h=>`<div>${h}</div>`).join('')}</div><div id="rows">${rows||'<div class="empty">Sin registros todavía.</div>'}</div></div>`;
}
function filterRows(q){q=q.toLowerCase();document.querySelectorAll('#rows .tr').forEach(x=>x.style.display=x.innerText.toLowerCase().includes(q)?'grid':'none')}
function render(){if(window.BRVTALAdminModules)BRVTALAdminModules.cancel();const root=document.getElementById('app');if(!state.authed){root.innerHTML='<div class="login"><form class="loginbox" onsubmit="login(event)"><h1>BRVTAL</h1><div class="sub">DISCADMIN / CONTROL ROOM</div><div class="syscheck"><div class="sysrow"><span>WEB / PHP</span><b class="sysok">ONLINE</b></div><div class="sysrow"><span>API</span><b id="st_api" class="syswait">CHECKING</b></div><div class="sysrow"><span>DATABASE</span><b id="st_db" class="syswait">CHECKING</b></div><div class="sysrow"><span>SESSION</span><b class="sysok">READY</b></div></div><label class="eyebrow" for="admin-login-email">EMAIL</label><input id="admin-login-email" name="email" type="email" required><label class="eyebrow" for="admin-login-password">PASSWORD</label><input id="admin-login-password" name="password" type="password" required><button class="btn" type="submit" style="width:100%">ENTER</button><div class="error"></div></form></div>';setTimeout(checkSystem,300);return}const previousSide=root.querySelector('.side');document.body.classList.remove('admin-nav-open');root.innerHTML=`<div class="shell"><button type="button" class="admin-nav-scrim" aria-label="Close navigation" onclick="closeAdminNav()"></button><aside class="side" id="admin-primary-nav" aria-label="Primary navigation"><div class="logo">BRVTAL</div><div class="sub">DISCADMIN / CONTROL</div><div class="nav">${['dashboard','events','artists','sets','media','pages','content-core','theme','settings','security'].map(x=>`<button class="${state.section===x?'active':''}" onclick="go('${x}')">${x==='theme'?'THEME STUDIO':x==='content-core'?'CONTENT CORE':x==='security'?'SECURITY / 2FA':x.toUpperCase()}</button>`).join('')}<div class="navgroup">TECHNICAL</div>${[['system','SYSTEM STATUS']].map(x=>`<button class="${state.section===x[0]?'active':''}" onclick="tech('${x[0]}')">${x[1]}</button>`).join('')}</div><div class="sidefoot"><div class="brvtal-admin-release"><span class="version">v<?= htmlspecialchars(BRVTAL_APP_VERSION, ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars(BRVTAL_APP_ENV, ENT_QUOTES, "UTF-8") ?></span><span class="build">DEPLOY <?= htmlspecialchars(brvtal_deployment_short_sha(), ENT_QUOTES, "UTF-8") ?></span></div><button class="btn ghost" style="width:100%" onclick="logout()">LOG OUT</button></div></aside><main class="main"><div class="top"><button type="button" class="admin-menu-toggle" aria-controls="admin-primary-nav" aria-expanded="false" onclick="toggleAdminNav()"><span aria-hidden="true">☰</span><span>MENU</span></button><div class="admin-page-title"><div class="eyebrow">BRVTAL CMS</div><h1>${state.section==='content-core'?'CONTENT CORE':state.section==='security'?'SECURITY / 2FA':state.section.toUpperCase()}</h1></div><span class="status"><i></i>ONLINE</span></div>${state.section==='content-core'||state.section==='security'?'<div id="admin-module-host" aria-live="polite">LOADING…</div>':content()}</main></div>`;if(previousSide){root.querySelector('.side').replaceWith(previousSide);previousSide.querySelectorAll('.nav button').forEach(button=>{const handler=button.getAttribute('onclick')||'';button.classList.toggle('active',handler===`go('${state.section}')`||handler===`tech('${state.section}')`);});}}
document.addEventListener('keydown',function(e){if((e.metaKey||e.ctrlKey)&&e.key.toLowerCase()==='k'){e.preventDefault();if(typeof state!=='undefined'&&state.authed)go('theme')}});
scheduleTechRefresh();
</script><script src="/discadmin/qrcode.min.js?v=<?= rawurlencode(brvtal_deployment_short_sha()) ?>"></script><script src="/discadmin/content-core-lineup.js?v=<?= rawurlencode(brvtal_deployment_short_sha()) ?>"></script><script src="/discadmin/content-core.js?v=<?= rawurlencode(brvtal_deployment_short_sha()) ?>"></script><script src="/discadmin/security.js?v=<?= rawurlencode(brvtal_deployment_short_sha()) ?>"></script><script src="/discadmin/admin-modules.js?v=<?= rawurlencode(brvtal_deployment_short_sha()) ?>"></script><script src="/discadmin/totp-login.js?v=<?= rawurlencode(brvtal_deployment_short_sha()) ?>"></script><script>restoreSession();</script></body></html>
