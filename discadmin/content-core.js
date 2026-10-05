const BRVTAL_CONTENT_CORE_SCRIPT_SRC=document.currentScript?.src||'';
function brvtalEventInsightsScriptSrc(){
  const target=new URL('/discadmin/event-insights.js',location.origin);
  if(!BRVTAL_CONTENT_CORE_SCRIPT_SRC)return target.pathname;
  const source=new URL(BRVTAL_CONTENT_CORE_SCRIPT_SRC,location.origin);
  const version=source.searchParams.get('v');
  if(version)target.searchParams.set('v',version);
  return target.pathname+target.search;
}
function brvtalEventHistoryScriptSrc(){
  const target=new URL('/discadmin/admin-activity.js',location.origin);
  if(!BRVTAL_CONTENT_CORE_SCRIPT_SRC)return target.pathname;
  const source=new URL(BRVTAL_CONTENT_CORE_SCRIPT_SRC,location.origin);
  const version=source.searchParams.get('v');
  if(version)target.searchParams.set('v',version);
  return target.pathname+target.search;
}
function brvtalEventRestoreScriptSrc(){
  const target=new URL('/discadmin/event-version-restore.js',location.origin);
  if(!BRVTAL_CONTENT_CORE_SCRIPT_SRC)return target.pathname;
  const source=new URL(BRVTAL_CONTENT_CORE_SCRIPT_SRC,location.origin);
  const version=source.searchParams.get('v');
  if(version)target.searchParams.set('v',version);
  return target.pathname+target.search;
}

function brvtalEventRestoreValue(field,control){
  if(!control)return null;
  if(field==='featured')return Number(control.value||0);
  if(field==='archive_year')return control.value===''?null:Number(control.value);
  if(field==='event_date'){
    const raw=String(control.value||'').trim();
    if(!raw)return null;
    const normalized=raw.replace('T',' ');
    return normalized.length===16?normalized+':00':normalized;
  }
  return control.value;
}
function brvtalEventRestoreInputValue(field,value){
  if(value===null||value===undefined)return '';
  if(field==='featured')return Number(value)?'1':'0';
  if(field==='event_date')return String(value).replace(' ','T').slice(0,16);
  return String(value);
}
function brvtalTicketDateInputValue(value){return value?String(value).replace(' ','T').slice(0,16):''}
function brvtalTicketPayload(row,eventId,index){
  const payload={event_id:eventId,sort_order:index};
  row.querySelectorAll('[data-k]').forEach(element=>{
    const key=element.dataset.k;
    let value=element.value;
    if(key==='currency')value=value.trim().toUpperCase();
    if(key==='price')value=value===''?null:value;
    if(key==='available_from'||key==='available_until')value=value?value.replace('T',' '):null;
    if(key==='external_url'||key==='qr_image')value=value.trim();
    payload[key]=value;
  });
  return payload;
}
function brvtalTicketRowValidation(row){
  const name=row.querySelector('[data-k="name"]');
  if(!name?.value.trim())return {field:name,message:'needs a name before saving.'};

  const price=row.querySelector('[data-k="price"]');
  if(price?.value&&(!Number.isFinite(Number(price.value))||Number(price.value)<0)){
    return {field:price,message:'needs a valid non-negative price.'};
  }

  const currency=row.querySelector('[data-k="currency"]');
  if(!/^[A-Za-z]{3}$/.test(currency?.value.trim()||'')){
    return {field:currency,message:'needs a 3-letter currency code.'};
  }

  const url=row.querySelector('[data-k="external_url"]');
  if(url?.value.trim()){
    const rawUrl=url.value.trim();
    if(rawUrl.length>700||!URL.canParse(rawUrl)){
      return {field:url,message:'needs a valid http(s) purchase URL.'};
    }
    const parsed=new URL(rawUrl);
    if(!['http:','https:'].includes(parsed.protocol)){
      return {field:url,message:'needs a valid http(s) purchase URL.'};
    }
  }

  const from=row.querySelector('[data-k="available_from"]');
  const until=row.querySelector('[data-k="available_until"]');
  if(from?.value&&until?.value&&until.value<from.value){
    return {field:until,message:'availability end must not be before its start.'};
  }
  return null;
}

let brvtalEventInsightsModulePromise=null;
function brvtalDiscardEventInsightsScript(script){
  if(!script)return;
  script.dataset.eventInsightsFailed='1';
  script.remove();
}
function brvtalLoadEventInsightsModule(){
  if(window.BRVTALEventInsights)return Promise.resolve(window.BRVTALEventInsights);
  if(brvtalEventInsightsModulePromise)return brvtalEventInsightsModulePromise;

  brvtalEventInsightsModulePromise=new Promise((resolve,reject)=>{
    let script=document.querySelector('script[data-event-insights-script="1"]');
    if(script?.dataset.eventInsightsFailed==='1'){
      brvtalDiscardEventInsightsScript(script);
      script=null;
    }

    const fail=message=>{
      brvtalDiscardEventInsightsScript(script);
      reject(new Error(message));
    };
    const finish=()=>{
      if(window.BRVTALEventInsights){
        script.dataset.eventInsightsReady='1';
        resolve(window.BRVTALEventInsights);
        return;
      }
      fail('Event Insights module unavailable');
    };

    if(!script){
      script=document.createElement('script');
      script.src=brvtalEventInsightsScriptSrc();
      script.async=true;
      script.dataset.eventInsightsScript='1';
      document.head.appendChild(script);
    }
    script.addEventListener('load',finish,{once:true});
    script.addEventListener('error',()=>fail('Event Insights module unavailable'),{once:true});
  }).catch(error=>{
    brvtalEventInsightsModulePromise=null;
    throw error;
  });
  return brvtalEventInsightsModulePromise;
}

let brvtalEventHistoryModulePromise=null;
function brvtalDiscardEventHistoryScript(script){
  if(!script)return;
  script.dataset.eventHistoryFailed='1';
  script.remove();
}
function brvtalLoadEventHistoryModule(){
  if(typeof window.BRVTALAdminActivity?.openHistory==='function'){
    return Promise.resolve(window.BRVTALAdminActivity);
  }
  if(brvtalEventHistoryModulePromise)return brvtalEventHistoryModulePromise;

  brvtalEventHistoryModulePromise=new Promise((resolve,reject)=>{
    let script=document.querySelector('script[data-event-history-script="1"]');
    if(script?.dataset.eventHistoryFailed==='1'){
      brvtalDiscardEventHistoryScript(script);
      script=null;
    }

    const fail=message=>{
      brvtalDiscardEventHistoryScript(script);
      reject(new Error(message));
    };
    const finish=()=>{
      if(typeof window.BRVTALAdminActivity?.openHistory==='function'){
        script.dataset.eventHistoryReady='1';
        resolve(window.BRVTALAdminActivity);
        return;
      }
      fail('Event Version History module unavailable');
    };

    if(!script){
      script=document.createElement('script');
      script.src=brvtalEventHistoryScriptSrc();
      script.async=true;
      script.dataset.eventHistoryScript='1';
      document.head.appendChild(script);
    }
    script.addEventListener('load',finish,{once:true});
    script.addEventListener('error',()=>fail('Event Version History module unavailable'),{once:true});
  }).catch(error=>{
    brvtalEventHistoryModulePromise=null;
    throw error;
  });
  return brvtalEventHistoryModulePromise;
}

let brvtalEventRestoreModulePromise=null;
function brvtalDiscardEventRestoreScript(script){
  if(!script)return;
  script.dataset.eventRestoreFailed='1';
  script.remove();
}
function brvtalLoadEventRestoreModule(){
  if(typeof window.BRVTALEventVersionRestore?.buildPlan==='function'){
    return Promise.resolve(window.BRVTALEventVersionRestore);
  }
  if(brvtalEventRestoreModulePromise)return brvtalEventRestoreModulePromise;

  brvtalEventRestoreModulePromise=new Promise((resolve,reject)=>{
    let script=document.querySelector('script[data-event-restore-script="1"]');
    if(script?.dataset.eventRestoreFailed==='1'){
      brvtalDiscardEventRestoreScript(script);
      script=null;
    }
    const fail=message=>{
      brvtalDiscardEventRestoreScript(script);
      reject(new Error(message));
    };
    const finish=()=>{
      if(typeof window.BRVTALEventVersionRestore?.buildPlan==='function'){
        script.dataset.eventRestoreReady='1';
        resolve(window.BRVTALEventVersionRestore);
        return;
      }
      fail('Event Version Restore module unavailable');
    };
    if(!script){
      script=document.createElement('script');
      script.src=brvtalEventRestoreScriptSrc();
      script.async=true;
      script.dataset.eventRestoreScript='1';
      document.head.appendChild(script);
    }
    script.addEventListener('load',finish,{once:true});
    script.addEventListener('error',()=>fail('Event Version Restore module unavailable'),{once:true});
  }).catch(error=>{
    brvtalEventRestoreModulePromise=null;
    throw error;
  });
  return brvtalEventRestoreModulePromise;
}

window.BRVTALContentCore = {mount(root) {

const API='/api/index.php';let events=[],artists=[],currentEvent=null,currentStep=1,csrf='',ticketRowSeq=0;
const $=s=>root.querySelector(s), $$=s=>[...root.querySelectorAll(s)];
async function api(path,opts={}){const {headers:optHeaders,...rest}=opts;const r=await fetch(API+path,{credentials:'same-origin',...rest,headers:{'Content-Type':'application/json',...optHeaders}});let j;try{j=await r.json()}catch(_){throw new Error(`API ${r.status} returned invalid JSON`)}if(r.status===401){location.href='/discadmin/';throw new Error(j?.error||'Authentication required')}if(!r.ok||j?.ok===false)throw new Error(j?.error||`API request failed (${r.status})`);return j}
function msg(text,ok=true,target='cc-notice'){const n=$('#'+target);n.textContent=text;n.className='notice show '+(ok?'ok':'err');setTimeout(()=>n.classList.remove('show'),5000)}
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function syncEventHistoryControl(){
  const button=$('#cc-historyBtn');
  if(!button)return;
  const eventId=Number(currentEvent?.id||0);
  button.hidden=eventId<1;
  button.disabled=eventId<1;
}
const EVENT_RESTORE_CONTROL_IDS=Object.freeze({
  title:'e_title',
  slug:'e_slug',
  event_date:'e_event_date',
  archive_year:'e_archive_year',
  venue:'e_venue',
  city:'e_city',
  description:'e_description',
  skin:'e_skin',
  accent:'e_accent',
  cover_image:'e_cover_image',
  ticket_url:'e_ticket_url',
  ticket_instructions:'e_ticket_instructions',
  ticket_qr:'e_ticket_qr',
  featured:'e_featured',
  seo_title:'e_seo_title',
  seo_description:'e_seo_description',
});
function eventRestoreControl(field){
  const id=EVENT_RESTORE_CONTROL_IDS[field];
  return id?$('#'+id):null;
}
function eventRestoreEditorSnapshot(){
  const snapshot={id:Number(currentEvent?.id||0)};
  Object.keys(EVENT_RESTORE_CONTROL_IDS).forEach(field=>{
    const control=eventRestoreControl(field);
    if(control)snapshot[field]=brvtalEventRestoreValue(field,control);
  });
  return snapshot;
}
function eventRestorePlanReady(plan){
  return Boolean(
    plan?.ok
    && Array.isArray(plan.changes)
    && plan.changes.length
    && plan.changes.every(change=>eventRestoreControl(change.field))
  );
}
function applyEventRestorePlan(plan,eventId){
  const modal=$('#eventModal');
  if(!eventRestorePlanReady(plan)||Number(currentEvent?.id||0)!==eventId||!modal?.classList.contains('open'))return false;
  for(const change of plan.changes){
    const control=eventRestoreControl(change.field);
    if(!control)return false;
    control.value=brvtalEventRestoreInputValue(change.field,change.to);
    control.dispatchEvent(new Event('input',{bubbles:true}));
    control.dispatchEvent(new Event('change',{bubbles:true}));
  }
  if(plan.changes.some(change=>change.field==='accent')){
    window.BRVTALAdminColorField?.sync?.($('#e_accent'));
  }
  window.BRVTALUnsavedChanges?.touch?.(modal);
  return true;
}
async function stageEventVersionRestore(item,eventId,restoreModule){
  const modal=$('#eventModal');
  if(Number(currentEvent?.id||0)!==eventId||!modal?.classList.contains('open'))return false;
  const plan=restoreModule.buildPlan(item,eventRestoreEditorSnapshot());
  if(!eventRestorePlanReady(plan)){
    msg('This version cannot be loaded safely into the current Event editor.',false,'eventNotice');
    return false;
  }
  const fields=plan.changes.map(change=>String(change.field).replaceAll('_',' ').toUpperCase()).join(' · ');
  const source=plan.source_created_at?(' from '+plan.source_created_at):'';
  if(!window.confirm(`Load this historical Event version${source} into the editor?\n\nFields: ${fields}\n\nNothing will be saved until you press SAVE EVENT.`))return false;
  if(Number(currentEvent?.id||0)!==eventId||!modal.classList.contains('open'))return false;
  if(!applyEventRestorePlan(plan,eventId)){
    msg('Event changed before the restore could be staged.',false,'eventNotice');
    return false;
  }
  msg('Historical version loaded into the editor. Review the changes before saving.',true,'eventNotice');
  return true;
}
async function openEventHistory(){
  const modal=$('#eventModal');
  const eventId=Number(currentEvent?.id||0);
  if(eventId<1||!modal?.classList.contains('open'))return false;
  try{
    const module=await brvtalLoadEventHistoryModule();
    if(Number(currentEvent?.id||0)!==eventId||!modal.classList.contains('open'))return false;
    let restoreModule=null;
    try{
      restoreModule=await brvtalLoadEventRestoreModule();
    }catch(error){
      msg('Version restore unavailable; history remains read-only.',false,'eventNotice');
    }
    if(Number(currentEvent?.id||0)!==eventId||!modal.classList.contains('open'))return false;
    const label=String(currentEvent?.title||currentEvent?.name||`Event #${eventId}`);
    await module.openHistory(
      'events',
      eventId,
      label,
      restoreModule?{onRestore:item=>stageEventVersionRestore(item,eventId,restoreModule)}:{}
    );
    return true;
  }catch(error){
    msg('Version history unavailable: '+(error?.message||error),false,'eventNotice');
    return false;
  }
}
async function initAuth(){const j=window.BRVTALAdminAuthBoundary?.auth?await window.BRVTALAdminAuthBoundary.auth():await api('/auth');if(!j.authenticated){location.href='/discadmin/';return}csrf=j.csrf||'';if(!csrf)throw new Error('CSRF token unavailable')}
async function loadEvents({required=false}={}){try{const j=await api('/events');events=j.data||j.events||[];renderEvents();return true}catch(e){msg('Could not load events: '+e.message,false);if(required)throw e;return false}}
function renderEvents(){const q=($('#eventSearch').value||'').toLowerCase();const a=events.filter(x=>JSON.stringify(x).toLowerCase().includes(q));$('#eventsTable').innerHTML='<div class="th"><div>EVENT</div><div>DATE</div><div>STATUS</div><div></div></div>'+(a.length?a.map(x=>`<div class="tr"><div><div class="title">${esc(x.title||x.name)}</div><div class="meta">${esc(x.city||'')} ${x.venue?'· '+esc(x.venue):''}</div></div><div>${esc(x.event_date||'—')}</div><div><span class="pill">${esc(x.status||'draft')}</span></div><div class="actions"><button class="icon" onclick="BRVTALContentCore.openEvent(${Number(x.id)})">EDIT</button></div></div>`).join(''):'<div class="empty">No events found.</div>')}
function fill(id,v){const el=$('#'+id);if(el)el.value=v??''}
const EVENT_PUBLIC_SCHEDULE_STATUSES=new Set(['published','upcoming','tickets_available','last_tickets','sold_out']);
function eventPublishScheduleEnabled(status=$('#e_status')?.value){
  return EVENT_PUBLIC_SCHEDULE_STATUSES.has(String(status||'').trim());
}
function eventPublishScheduleInputValue(event,nowMs=Date.now()){
  const raw=String(event?.published_at||'').trim();
  if(!raw)return '';
  const ms=Date.parse(raw.includes('T')?raw:raw.replace(' ','T'));
  if(!Number.isFinite(ms)||ms<=nowMs)return '';
  return raw.replace(' ','T').slice(0,16);
}
function syncEventPublishScheduleControl(){
  const input=$('#e_publish_at');
  const helper=$('#e_publish_at_help');
  const field=$('#e_publish_schedule_field');
  if(!input)return;
  const enabled=eventPublishScheduleEnabled();
  input.disabled=!enabled;
  input.setAttribute('aria-disabled',enabled?'false':'true');
  if(field)field.dataset.state=enabled?'available':'disabled';
  if(helper){
    helper.textContent=enabled
      ? 'The Event remains private until this date/time boundary. Visibility is evaluated on request; no cron is required.'
      : 'Choose published, upcoming or an active ticket lifecycle to schedule publication. Draft and historical states cannot be scheduled.';
  }
}
function openEvent(id=null){currentEvent=events.find(x=>Number(x.id)===Number(id))||null;$('#eventHeading').textContent=currentEvent?'EDIT EVENT':'NEW EVENT';fill('e_title',currentEvent?.title);fill('e_slug',currentEvent?.slug);fill('e_description',currentEvent?.description);fill('e_cover_image',currentEvent?.cover_image);fill('e_accent',currentEvent?.accent);window.BRVTALAdminColorField?.sync($('#e_accent'));fill('e_featured',currentEvent?.featured?'1':'0');fill('e_event_date',currentEvent?.event_date?String(currentEvent.event_date).replace(' ','T').slice(0,16):'');fill('e_city',currentEvent?.city);fill('e_venue',currentEvent?.venue);fill('e_archive_year',currentEvent?.archive_year);fill('e_status',currentEvent?.status||'draft');fill('e_publish_at',eventPublishScheduleInputValue(currentEvent));syncEventPublishScheduleControl();fill('e_ticket_instructions',currentEvent?.ticket_instructions);fill('e_ticket_qr',currentEvent?.ticket_qr);fill('e_ticket_url',currentEvent?.ticket_url);$('#tickets').innerHTML='';(currentEvent?.ticket_types||[]).forEach(addTicket);renderEventArtists();currentStep=1;setStep();syncEventHistoryControl();$('#eventModal').classList.add('open')}
function closeEvent(force=false){
  const modal=$('#eventModal');
  const close=()=>modal.classList.remove('open');
  if(window.BRVTALUnsavedChanges?.requestClose){
    return window.BRVTALUnsavedChanges.requestClose(modal,close,{force});
  }
  close();
  return true;
}
function eventWizardLastStep(){return $$('.step').reduce((last,node)=>Math.max(last,Number(node.dataset.step)||0),1)}
function step(dir){if(dir>0&&currentStep===1&&!$('#e_title').value.trim()){msg('Event name is required before continuing.',false,'eventNotice');return}const last=eventWizardLastStep();currentStep=Math.max(1,Math.min(last,currentStep+dir));setStep()}
function setStep(){const last=eventWizardLastStep();$$('.step').forEach(x=>x.classList.toggle('active',Number(x.dataset.step)===currentStep));$$('.step-content').forEach(x=>x.classList.toggle('active',Number(x.dataset.content)===currentStep));$('#prevBtn').style.visibility=currentStep===1?'hidden':'visible';$('#nextBtn').style.display=currentStep===last?'none':'inline-block';$('#cc-saveBtn').textContent=currentStep===last?'SAVE EVENT':'SAVE DRAFT'}
function addTicket(t={}){
  const d=document.createElement('div');
  const rowId='ticket_'+(++ticketRowSeq);
  d.className='ticket-row';
  if(t.id){d.dataset.id=String(t.id);}
  d.innerHTML=`<div class="ticket-row-head"><strong>TICKET TYPE</strong><button type="button" class="icon" aria-label="Remove ticket type" onclick="this.closest('.ticket-row').remove()">REMOVE</button></div>
  <div class="ticket-grid">
    <label class="ticket-field"><span>Name *</span><input data-k="name" aria-label="Ticket name" maxlength="120" placeholder="Preventa" value="${esc(t.name||'')}"></label>
    <label class="ticket-field"><span>Price</span><input data-k="price" aria-label="Ticket price" type="number" min="0" step="0.01" placeholder="20000" value="${esc(t.price??'')}"></label>
    <label class="ticket-field"><span>Currency *</span><input data-k="currency" aria-label="Ticket currency" maxlength="3" autocomplete="off" placeholder="COP" value="${esc(String(t.currency||'COP').toUpperCase())}"></label>
    <label class="ticket-field"><span>Status</span><select data-k="status" aria-label="Ticket status"><option value="draft" ${t.status==='draft'?'selected':''}>draft</option><option value="active" ${!t.status||t.status==='active'?'selected':''}>active</option><option value="inactive" ${t.status==='inactive'?'selected':''}>inactive</option><option value="sold_out" ${t.status==='sold_out'?'selected':''}>sold_out</option></select></label>
    <label class="ticket-field ticket-wide"><span>Description</span><textarea data-k="description" aria-label="Ticket description" maxlength="500" placeholder="What this ticket includes">${esc(t.description||'')}</textarea></label>
    <label class="ticket-field ticket-wide"><span>External purchase URL</span><input data-k="external_url" aria-label="Ticket external URL" maxlength="700" placeholder="https://…" value="${esc(t.external_url||'')}"></label>
    <label class="ticket-field"><span>Available from</span><input data-k="available_from" aria-label="Ticket available from" type="datetime-local" value="${esc(brvtalTicketDateInputValue(t.available_from))}"></label>
    <label class="ticket-field"><span>Available until</span><input data-k="available_until" aria-label="Ticket available until" type="datetime-local" value="${esc(brvtalTicketDateInputValue(t.available_until))}"></label>
    <label class="ticket-field ticket-wide"><span>Payment instructions</span><textarea data-k="payment_instructions" aria-label="Ticket payment instructions" maxlength="4000" placeholder="Nequi, transfer or pickup instructions">${esc(t.payment_instructions||'')}</textarea></label>
    <div class="ticket-field ticket-wide"><label for="${rowId}_qr">QR image</label><input id="${rowId}_qr" data-k="qr_image" data-media-picker="image" aria-label="Ticket QR image" maxlength="500" placeholder="/uploads/…" value="${esc(t.qr_image||'')}"></div>
  </div>`;
  $('#tickets').appendChild(d);
}
function validateEventPayload(payload){
  if(!payload.title){
    return 'Name is required.';
  }
  if(payload.accent&&!/^#[0-9a-f]{6}$/i.test(payload.accent)){
    return 'Accent must be a 6-digit HEX color.';
  }
  if(payload.status!=='draft'&&(!payload.event_date||!payload.city)){
    return 'Name, date and city are required before leaving draft.';
  }
  return '';
}
function ticketFieldError(index,field,message){
  currentStep=4;
  setStep();
  msg(`Ticket ${index+1} ${message}`,false,'eventNotice');
  field?.focus();
  return false;
}
function validateTicketRows(){
  const rows=$$('#tickets .ticket-row');
  for(let i=0;i<rows.length;i++){
    const validation=brvtalTicketRowValidation(rows[i]);
    if(validation)return ticketFieldError(i,validation.field,validation.message);
  }
  return true;
}

function timetableArtistOptions(selectedId){
  const selected=Number(selectedId||0);
  const options=['<option value="">EXTERNAL / LABEL ONLY</option>'];
  artists.slice().sort((a,b)=>String(a.name||'').localeCompare(String(b.name||''))).forEach(artist=>{
    const id=Number(artist.id||0);
    if(id<1)return;
    options.push('<option value="'+id+'" '+(id===selected?'selected':'')+'>'+esc(artist.name||('Artist #'+id))+'</option>');
  });
  return options.join('');
}
function addTimetableRow(item={}){
  const row=document.createElement('div');
  const artistId=Number(item.artist_id||0);
  const starts=String(item.starts_at||'').replace(' ','T').slice(0,16);
  const ends=String(item.ends_at||'').replace(' ','T').slice(0,16);
  const timezone=String(item.timezone||Intl.DateTimeFormat().resolvedOptions().timeZone||'UTC');
  row.className='ticket-row timetable-row';
  if(item.id)row.dataset.id=String(item.id);
  row.innerHTML='<div class="ticket-row-head"><strong>TIMETABLE SLOT</strong><button type="button" class="icon" aria-label="Remove timetable slot" onclick="this.closest(\'.timetable-row\').remove()">REMOVE</button></div>'
    +'<div class="ticket-grid">'
    +'<label class="ticket-field"><span>Artist</span><select data-k="artist_id" aria-label="Timetable artist">'+timetableArtistOptions(artistId)+'</select></label>'
    +'<label class="ticket-field"><span>External label</span><input data-k="label" aria-label="Timetable external label" maxlength="180" value="'+esc(artistId?'':(item.label||''))+'" placeholder="Opening / Guest"></label>'
    +'<label class="ticket-field"><span>Starts</span><input data-k="starts_at" aria-label="Timetable starts" type="datetime-local" value="'+esc(starts)+'"></label>'
    +'<label class="ticket-field"><span>Ends</span><input data-k="ends_at" aria-label="Timetable ends" type="datetime-local" value="'+esc(ends)+'"></label>'
    +'<label class="ticket-field"><span>Timezone</span><input data-k="timezone" aria-label="Timetable timezone" maxlength="64" value="'+esc(timezone)+'" placeholder="America/Bogota"></label>'
    +'<label class="ticket-field"><span>Status</span><select data-k="status" aria-label="Timetable status"><option value="draft" '+(item.status!=='approved'?'selected':'')+'>draft</option><option value="approved" '+(item.status==='approved'?'selected':'')+'>approved</option></select></label>'
    +'</div>';
  const artist=row.querySelector('[data-k="artist_id"]');
  const label=row.querySelector('[data-k="label"]');
  const syncIdentity=()=>{
    const linked=Number(artist?.value||0)>0;
    if(label){
      label.disabled=linked;
      if(linked)label.value='';
    }
  };
  artist?.addEventListener('change',syncIdentity);
  syncIdentity();
  const holder=$('#eventTimetable');
  if(!holder)return null;
  holder.appendChild(row);
  return row;
}
function renderTimetable(rows=[]){
  const holder=$('#eventTimetable');
  if(!holder)return;
  holder.innerHTML='';
  (Array.isArray(rows)?rows:[]).forEach(addTimetableRow);
}
async function saveEvent(){
  const rawDate=$('#e_event_date').value;
  const rawAccent=$('#e_accent').value.trim();
  const rawPublishAt=$('#e_publish_at')?.value||'';
  const accent=rawAccent?(window.BRVTALAdminColorField?.normalize(rawAccent)||rawAccent):'';
  const publishAt=eventPublishScheduleEnabled()&&rawPublishAt?rawPublishAt.replace('T',' '):null;
  const payload={title:$('#e_title').value.trim(),slug:$('#e_slug').value.trim(),description:$('#e_description').value,cover_image:$('#e_cover_image').value,accent,featured:Number($('#e_featured').value),event_date:rawDate?rawDate.replace('T',' '):null,city:$('#e_city').value.trim(),venue:$('#e_venue').value.trim(),archive_year:Number($('#e_archive_year').value)||null,status:$('#e_status').value,publish_at:publishAt,ticket_instructions:$('#e_ticket_instructions').value,ticket_qr:$('#e_ticket_qr').value,ticket_url:$('#e_ticket_url').value};
  const validationError=validateEventPayload(payload);
  if(validationError){msg(validationError,false,'eventNotice');return false}
  if(!validateTicketRows())return false;
  if(!csrf){msg('Security token unavailable. Reload the page.',false,'eventNotice');return false}
  let eventSaved=false;
  try{
    const path=currentEvent?'/events/'+currentEvent.id:'/events';
    const j=await api(path,{method:currentEvent?'PUT':'POST',body:JSON.stringify(payload),headers:{'X-CSRF-Token':csrf}});
    if(j.ok===false)throw new Error(j.error||'Save failed');
    const id=Number(j.id||j.data?.id||currentEvent?.id);
    if(!id)throw new Error('Event ID missing after save');
    currentEvent={...currentEvent,id,...payload};
    syncEventHistoryControl();
    eventSaved=true;
    await saveTickets(id);
    await loadEvents();
    const persisted=events.find(event=>Number(event.id)===id);
    if(persisted)currentEvent={...currentEvent,...persisted};
    fill('e_publish_at',eventPublishScheduleInputValue(currentEvent));
    syncEventPublishScheduleControl();
    msg('Event saved.');
    return true;
  }catch(e){
    msg((eventSaved?'Event saved, but tickets could not be saved: ':'Save failed: ')+e.message,false,'eventNotice');
    return false;
  }
}
async function saveTickets(eventId){const rows=$$('#tickets .ticket-row');const keep=new Set();for(let i=0;i<rows.length;i++){const row=rows[i],p=brvtalTicketPayload(row,eventId,i),existing=Number(row.dataset.id||0);let j;if(existing){keep.add(existing);j=await api('/ticket_types/'+existing,{method:'PUT',body:JSON.stringify(p),headers:{'X-CSRF-Token':csrf}})}else{j=await api('/ticket_types',{method:'POST',body:JSON.stringify(p),headers:{'X-CSRF-Token':csrf}});if(j.id)row.dataset.id=String(j.id)}if(j.ok===false)throw new Error(j.error||'Ticket save failed')}const old=(currentEvent?.ticket_types||[]).map(t=>Number(t.id)).filter(Boolean);for(const id of old){if(!keep.has(id)){const j=await api('/ticket_types/'+id,{method:'DELETE',headers:{'X-CSRF-Token':csrf}});if(j.ok===false)throw new Error(j.error||'Ticket delete failed')}}currentEvent=currentEvent||{id:eventId};currentEvent.ticket_types=rows.map((row,i)=>{const p=brvtalTicketPayload(row,eventId,i);return {...p,id:Number(row.dataset.id||0)}})}
function eventPreviewLineup(){
  const existing=new Map(
    (Array.isArray(currentEvent?.lineup)?currentEvent.lineup:[])
      .map((item,index)=>[Number(item.artist_id||item.id||0),{
        artist_id:Number(item.artist_id||item.id||0),
        role:String(item.role||''),
        sort_order:Number.isFinite(Number(item.lineup_order))
          ? Number(item.lineup_order)
          : index
      }])
  );
  let nextOrder=existing.size;
  return $$('#eventArtists [data-artist]:checked').map(checkbox=>{
    const artistId=Number(checkbox.dataset.artist||0);
    return existing.get(artistId)||{
      artist_id:artistId,
      role:'',
      sort_order:nextOrder++
    };
  }).filter(item=>item.artist_id>0);
}
function eventPreviewPayload(){
  const rawDate=$('#e_event_date').value;
  return {
    id:Number(currentEvent?.id||0),
    title:$('#e_title').value.trim(),
    slug:$('#e_slug').value.trim(),
    description:$('#e_description').value,
    cover_image:$('#e_cover_image').value,
    accent:$('#e_accent').value.trim(),
    status:$('#e_status').value||'draft',
    event_date:rawDate?rawDate.replace('T',' '):'',
    city:$('#e_city').value.trim(),
    venue:$('#e_venue').value.trim(),
    ticket_instructions:$('#e_ticket_instructions').value,
    ticket_url:$('#e_ticket_url').value,
    ticket_types:$$('#tickets .ticket-row').map((row,index)=>
      brvtalTicketPayload(row,Number(currentEvent?.id||0),index)
    ),
    lineup:eventPreviewLineup()
  };
}
async function previewEvent(){
  if(!window.BRVTALPublicPreview){
    msg('Public preview is unavailable. Reload DISCADMIN.',false,'eventNotice');
    return;
  }
  if($('#tickets').dataset.loadState==='loading'||$('#eventArtists').dataset.loadState==='loading'){
    msg('Wait for event relationships to finish loading before previewing.',false,'eventNotice');
    return;
  }
  const button=$('#cc-previewBtn');
  if(button){button.disabled=true;button.textContent='BUILDING PREVIEW…';}
  try{
    await window.BRVTALPublicPreview.open('events',eventPreviewPayload());
  }catch(error){
    msg('Preview failed: '+String(error?.message||'UNKNOWN_ERROR').replaceAll('_',' '),false,'eventNotice');
  }finally{
    if(button?.isConnected){button.disabled=false;button.textContent='PUBLIC PREVIEW';}
  }
}
async function loadArtists(){try{const j=await api('/artists');artists=j.data||j.artists||[];renderEventArtists()}catch(e){msg('Could not load artists: '+e.message,false)}}
function renderEventArtists(){const holder=$('#eventArtists');if(currentEvent?.id&&holder.dataset.loadState==='loading'){holder.innerHTML='<div class="empty">Loading event participation…</div>';return}if(!artists.length){holder.innerHTML='<div class="empty">Load artists to manage event participation.</div>';return}const relations=currentEvent?.lineup??currentEvent?.event_artists??currentEvent?.artists??[];const selected=new Set(relations.map(x=>Number(x.artist_id||x.id)));holder.innerHTML=artists.filter(a=>Number(a.is_collective_member||0)===1||selected.has(Number(a.id))).sort((a,b)=>Number(b.is_collective_member||0)-Number(a.is_collective_member||0)||Number(a.sort_order||0)-Number(b.sort_order||0)||String(a.name||'').localeCompare(String(b.name||''))).map(a=>{const member=Number(a.is_collective_member||0)===1;return `<div class="artist"><div class="ph">${a.photo?'IMG':'BRV'}</div><label class="grow" for="event_artist_${Number(a.id)}"><b>${esc(a.name)}</b><small>${member?'BRVTAL / MEMBER':'EXTERNAL / EVENT'}</small></label><input id="event_artist_${Number(a.id)}" type="checkbox" data-artist="${Number(a.id)}" aria-label="Include ${esc(a.name)} in event lineup" ${selected.has(Number(a.id))?'checked':''}></div>`}).join('')}
$('#eventSearch').oninput=renderEvents;
const ready = (async()=>{try{await initAuth();await loadEvents({required:true});await loadArtists();}catch(e){msg('Initialization failed: '+e.message,false);throw e}})();
let eventEditorReady=Promise.resolve(null);


(function(){
  const originalOpenEvent=openEvent;
  const originalCloseEvent=closeEvent;
  const originalSaveEvent=saveEvent;
  let ticketRequest=0,ticketState='ready',lineupRequest=0,lineupState='ready',timetableRequest=0,timetableState='ready',insightsRequest=0;
  const timetableEditor=()=>$('#eventTimetable');
  const canLoadLineup=()=>typeof window.BRVTALContentCoreLineup?.load==='function';
  const canSaveLineup=()=>typeof window.BRVTALContentCoreLineup?.save==='function';
  async function refreshEventInsights(event,request){
    const module=await brvtalLoadEventInsightsModule();
    if(request!==insightsRequest)return;
    module.mount(root);
    if(request!==insightsRequest)return;
    await module.load(event);
  }
  function markInsightsUnavailable(){
    const panel=$('[data-event-insights-panel]');
    const status=$('#eventInsightsStatus');
    if(panel)panel.dataset.state='unavailable';
    if(status){
      status.dataset.state='unavailable';
      status.textContent='UNAVAILABLE · Event Insights module could not be loaded.';
    }
    ['Users','Sessions','Views'].forEach(metric=>{
      const value=$('#eventInsights'+metric);
      const previous=$('#eventInsights'+metric+'Previous');
      if(value)value.textContent='—';
      if(previous)previous.textContent='PREVIOUS: —';
    });
  }
  async function refreshTickets(eventId,request){
    const response=await api('/ticket_types');
    if(response.ok===false)throw new Error(response.error||'Ticket types unavailable');
    const all=Array.isArray(response.data)?response.data:[];
    if(request!==ticketRequest||Number(currentEvent?.id)!==eventId)return;
    currentEvent.ticket_types=all.filter(ticket=>Number(ticket.event_id)===eventId).sort((a,b)=>Number(a.sort_order||0)-Number(b.sort_order||0));
    $('#tickets').innerHTML='';
    currentEvent.ticket_types.forEach(addTicket);
    ticketState='ready';
    $('#tickets').dataset.loadState='ready';
  }
  function normalizeLineup(lineup){
    return (Array.isArray(lineup)?lineup:[]).map((item,index)=>{
      const artistId=Number(item.artist_id||item.id||0);
      const order=Number(item.lineup_order);
      return {...item,artist_id:artistId,lineup_order:Number.isFinite(order)?order:index,role:String(item.role??'')};
    }).filter(item=>item.artist_id>0);
  }
  async function refreshLineup(eventId,request){
    if(!eventId||!canLoadLineup())return;
    const lineup=await window.BRVTALContentCoreLineup.load(eventId,csrf);
    if(request!==lineupRequest||Number(currentEvent?.id)!==eventId)return;
    currentEvent.lineup=normalizeLineup(lineup);
    lineupState='ready';
    $('#eventArtists').dataset.loadState='ready';
    renderEventArtists();
  }
  async function refreshTimetable(eventId,request){
    const holder=timetableEditor();
    if(!holder||!eventId)return;
    const response=await fetch('/api/event-workflow.php?id='+encodeURIComponent(eventId),{
      credentials:'same-origin',
      cache:'no-store'
    });
    let payload;
    try{payload=await response.json()}catch(_){throw new Error('Timetable workflow returned invalid JSON')}
    if(!response.ok||payload?.ok===false)throw new Error(payload?.error||('Timetable load failed ('+response.status+')'));
    if(request!==timetableRequest||Number(currentEvent?.id)!==eventId)return;
    currentEvent.timetable=Array.isArray(payload.data?.timetable)?payload.data.timetable:[];
    timetableState='ready';
    holder.dataset.loadState='ready';
    renderTimetable(currentEvent.timetable);
  }
  function lineupPayloadFromSelection(){
    const existing=normalizeLineup(currentEvent?.lineup||[]);
    const byArtist=new Map(existing.map(item=>[Number(item.artist_id),item]));
    let nextOrder=existing.reduce((max,item)=>Math.max(max,Number(item.lineup_order)||0),-1)+1;
    const payload=$$('#eventArtists [data-artist]:checked').map(el=>{
      const artistId=Number(el.dataset.artist);
      const saved=byArtist.get(artistId);
      if(saved)return {artist_id:artistId,lineup_order:saved.lineup_order,role:saved.role};
      return {artist_id:artistId,lineup_order:nextOrder++,role:''};
    });
    return payload.sort((a,b)=>a.lineup_order-b.lineup_order||a.artist_id-b.artist_id);
  }
  openEvent=function(id=null){
    const modal=$('#eventModal');
    modal.querySelector('[data-seo-editor="content-core"]')?.remove();
    modal.dataset.eventId=id?String(id):'new';
    const lineupRequestId=++lineupRequest;
    lineupState=id&&canLoadLineup()?'loading':'ready';
    $('#eventArtists').dataset.loadState=lineupState;
    const timetableRequestId=++timetableRequest;
    timetableState=id&&timetableEditor()?'loading':'ready';
    originalOpenEvent(id);
    const insightsRequestId=++insightsRequest;
    const request=++ticketRequest;
    ticketState=id?'loading':'ready';
    $('#tickets').dataset.loadState=ticketState;
    if(timetableEditor()){
      timetableEditor().dataset.loadState=timetableState;
      renderTimetable([]);
    }
    const loads=[];
    void refreshEventInsights(currentEvent,insightsRequestId).catch(()=>{
      if(insightsRequestId!==insightsRequest)return;
      markInsightsUnavailable();
    });
    if(id){
      $('#tickets').innerHTML='<div class="empty">Loading ticket types…</div>';
      loads.push(refreshTickets(Number(id),request).catch(e=>{
        if(request!==ticketRequest)return;
        ticketState='error';
        $('#tickets').dataset.loadState='error';
        $('#tickets').innerHTML='<div class="empty">Ticket types could not be loaded.</div>';
        msg('Could not load ticket types: '+e.message,false,'eventNotice');
      }));
      if(canLoadLineup()){
        loads.push(refreshLineup(Number(id),lineupRequestId).catch(e=>{
          if(lineupRequestId!==lineupRequest||Number(currentEvent?.id)!==Number(id))return;
          lineupState='error';
          $('#eventArtists').dataset.loadState='error';
          $('#eventArtists').innerHTML='<div class="empty">Event participation could not be loaded.</div>';
          msg('Could not load event roster: '+e.message,false,'eventNotice');
        }));
      }
      if(timetableEditor()){
        timetableEditor().innerHTML='<div class="empty">Loading timetable…</div>';
        loads.push(refreshTimetable(Number(id),timetableRequestId).catch(e=>{
          if(timetableRequestId!==timetableRequest||Number(currentEvent?.id)!==Number(id))return;
          timetableState='error';
          timetableEditor().dataset.loadState='error';
          timetableEditor().innerHTML='<div class="empty">Timetable could not be loaded.</div>';
          msg('Could not load timetable: '+e.message,false,'eventNotice');
        }));
      }
    }
    eventEditorReady=Promise.all(loads).then(()=>currentEvent);
    return eventEditorReady;
  };
  closeEvent=function(force=false){
    const modal=$('#eventModal');
    const wasOpen=modal.classList.contains('open');
    const result=originalCloseEvent(force);
    if(result===true&&wasOpen&&!modal.classList.contains('open')){
      insightsRequest+=1;
      window.BRVTALEventInsights?.reset?.();
    }
    return result;
  };
  saveEvent=async function(){
    if(currentEvent&&window.BRVTALSEOMetadata&&!$('#eventModal [data-seo-editor="content-core"]')){
      msg('Wait for SEO metadata to load before saving this event.',false,'eventNotice');
      return false;
    }
    if(ticketState!=='ready'){
      msg(ticketState==='loading'?'Wait for ticket types to load before saving.':'Reload ticket types before saving this event.',false,'eventNotice');
      return false;
    }
    if(currentEvent&&canLoadLineup()&&lineupState!=='ready'){
      msg(lineupState==='loading'?'Wait for event participation to load before saving.':'Reload event participation before saving this event.',false,'eventNotice');
      return false;
    }
    if(timetableEditor()){
      msg(
        timetableState==='loading'
          ? 'Wait for timetable to load before saving.'
          : 'Atomic Event workflow unavailable. Reload DISCADMIN.',
        false,
        'eventNotice'
      );
      return false;
    }
    const saved=await originalSaveEvent();
    if(!saved)return false;
    const insightsRequestId=++insightsRequest;
    void refreshEventInsights(currentEvent,insightsRequestId).catch(()=>{
      if(insightsRequestId!==insightsRequest)return;
      markInsightsUnavailable();
    });
    const eventId=Number(currentEvent?.id||0);
    if(!eventId||!canSaveLineup()){window.BRVTALUnsavedChanges?.markClean?.($('#eventModal'));return true;}
    const lineup=lineupPayloadFromSelection();
    try{
      await window.BRVTALContentCoreLineup.save(eventId,lineup,csrf);
      currentEvent.lineup=lineup;
      window.BRVTALUnsavedChanges?.markClean?.($('#eventModal'));
      msg('Event participation saved.');
      return true;
    }catch(e){msg('Event saved, but roster could not be saved: '+e.message,false,'eventNotice');return false;}
  };
})();

$('#e_status')?.addEventListener('change',syncEventPublishScheduleControl);
Object.assign(window.BRVTALContentCore, {openEvent,closeEvent,openEventHistory,syncEventPublishScheduleControl,loadEvents,loadArtists,addTicket,addTimetableRow,step,saveEvent,previewEvent,getCurrentEvent:()=>currentEvent,whenEventReady:()=>eventEditorReady,stageEventVersionRestore});
return ready;
}};