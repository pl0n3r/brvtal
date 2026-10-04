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

window.BRVTALContentCore = {mount(root) {

const API='/api/index.php';let events=[],artists=[],currentEvent=null,currentStep=1,csrf='',ticketRowSeq=0;
const $=s=>root.querySelector(s), $$=s=>[...root.querySelectorAll(s)];
async function api(path,opts={}){const {headers:optHeaders,...rest}=opts;const r=await fetch(API+path,{credentials:'same-origin',...rest,headers:{'Content-Type':'application/json',...(optHeaders||{})}});let j;try{j=await r.json()}catch(_){throw new Error(`API ${r.status} returned invalid JSON`)}if(r.status===401){location.href='/discadmin/';throw new Error(j?.error||'Authentication required')}if(!r.ok||j?.ok===false)throw new Error(j?.error||`API request failed (${r.status})`);return j}
function msg(text,ok=true,target='cc-notice'){const n=$('#'+target);n.textContent=text;n.className='notice show '+(ok?'ok':'err');setTimeout(()=>n.classList.remove('show'),5000)}
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
async function initAuth(){const j=window.BRVTALAdminAuthBoundary?.auth?await window.BRVTALAdminAuthBoundary.auth():await api('/auth');if(!j.authenticated){location.href='/discadmin/';return}csrf=j.csrf||'';if(!csrf)throw new Error('CSRF token unavailable')}
async function loadEvents({required=false}={}){try{const j=await api('/events');events=j.data||j.events||[];renderEvents();return true}catch(e){msg('Could not load events: '+e.message,false);if(required)throw e;return false}}
function renderEvents(){const q=($('#eventSearch').value||'').toLowerCase();const a=events.filter(x=>JSON.stringify(x).toLowerCase().includes(q));$('#eventsTable').innerHTML='<div class="th"><div>EVENT</div><div>DATE</div><div>STATUS</div><div></div></div>'+(a.length?a.map(x=>`<div class="tr"><div><div class="title">${esc(x.title||x.name)}</div><div class="meta">${esc(x.city||'')} ${x.venue?'· '+esc(x.venue):''}</div></div><div>${esc(x.event_date||'—')}</div><div><span class="pill">${esc(x.status||'draft')}</span></div><div class="actions"><button class="icon" onclick="BRVTALContentCore.openEvent(${Number(x.id)})">EDIT</button></div></div>`).join(''):'<div class="empty">No events found.</div>')}
function fill(id,v){const el=$('#'+id);if(el)el.value=v??''}
function openEvent(id=null){currentEvent=events.find(x=>Number(x.id)===Number(id))||null;$('#eventHeading').textContent=currentEvent?'EDIT EVENT':'NEW EVENT';fill('e_title',currentEvent?.title);fill('e_slug',currentEvent?.slug);fill('e_description',currentEvent?.description);fill('e_cover_image',currentEvent?.cover_image);fill('e_accent',currentEvent?.accent);window.BRVTALAdminColorField?.sync($('#e_accent'));fill('e_featured',currentEvent?.featured?'1':'0');fill('e_event_date',currentEvent?.event_date?String(currentEvent.event_date).replace(' ','T').slice(0,16):'');fill('e_city',currentEvent?.city);fill('e_venue',currentEvent?.venue);fill('e_archive_year',currentEvent?.archive_year);fill('e_status',currentEvent?.status||'draft');fill('e_ticket_instructions',currentEvent?.ticket_instructions);fill('e_ticket_qr',currentEvent?.ticket_qr);fill('e_ticket_url',currentEvent?.ticket_url);$('#tickets').innerHTML='';(currentEvent?.ticket_types||[]).forEach(addTicket);renderEventArtists();currentStep=1;setStep();$('#eventModal').classList.add('open')}
function closeEvent(force=false){
  const modal=$('#eventModal');
  const close=()=>modal.classList.remove('open');
  if(window.BRVTALUnsavedChanges?.requestClose){
    return window.BRVTALUnsavedChanges.requestClose(modal,close,{force});
  }
  close();
  return true;
}
function step(dir){if(dir>0&&currentStep===1&&!$('#e_title').value.trim()){msg('Event name is required before continuing.',false,'eventNotice');return}currentStep=Math.max(1,Math.min(6,currentStep+dir));setStep()}
function setStep(){$$('.step').forEach(x=>x.classList.toggle('active',Number(x.dataset.step)===currentStep));$$('.step-content').forEach(x=>x.classList.toggle('active',Number(x.dataset.content)===currentStep));$('#prevBtn').style.visibility=currentStep===1?'hidden':'visible';$('#nextBtn').style.display=currentStep===6?'none':'inline-block';$('#cc-saveBtn').textContent=currentStep===6?'SAVE EVENT':'SAVE DRAFT'}
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
  $('#eventTimetable').appendChild(row);
}
function renderTimetable(rows=[]){
  const holder=$('#eventTimetable');
  holder.innerHTML='';
  (Array.isArray(rows)?rows:[]).forEach(addTimetableRow);
}
function timetableFieldError(index,field,message){
  currentStep=6;
  setStep();
  msg('Timetable slot '+(index+1)+' '+message,false,'eventNotice');
  field?.focus();
  return false;
}
function validateTimetableRows(){
  const rows=$$('#eventTimetable .timetable-row');
  for(let i=0;i<rows.length;i++){
    const row=rows[i];
    const artist=row.querySelector('[data-k="artist_id"]');
    const label=row.querySelector('[data-k="label"]');
    const starts=row.querySelector('[data-k="starts_at"]');
    const ends=row.querySelector('[data-k="ends_at"]');
    const timezone=row.querySelector('[data-k="timezone"]');
    if(Number(artist?.value||0)<1&&!label?.value.trim())return timetableFieldError(i,label,'needs an Artist or external label.');
    if(!starts?.value)return timetableFieldError(i,starts,'needs a start time.');
    if(!ends?.value)return timetableFieldError(i,ends,'needs an end time.');
    if(ends.value<=starts.value)return timetableFieldError(i,ends,'must end after it starts.');
    if(!timezone?.value.trim())return timetableFieldError(i,timezone,'needs an IANA timezone.');
  }
  return true;
}
function timetablePayloadFromRows(){
  return $$('#eventTimetable .timetable-row').map((row,index)=>{
    const artistId=Number(row.querySelector('[data-k="artist_id"]')?.value||0);
    const item={
      artist_id:artistId>0?artistId:null,
      label:artistId>0?null:(row.querySelector('[data-k="label"]')?.value.trim()||''),
      starts_at:(row.querySelector('[data-k="starts_at"]')?.value||'').replace('T',' '),
      ends_at:(row.querySelector('[data-k="ends_at"]')?.value||'').replace('T',' '),
      timezone:row.querySelector('[data-k="timezone"]')?.value.trim()||'',
      status:row.querySelector('[data-k="status"]')?.value||'draft',
      sort_order:index
    };
    const id=Number(row.dataset.id||0);
    if(id>0)item.id=id;
    return item;
  });
}
async function saveEvent(){
  const rawDate=$('#e_event_date').value;
  const rawAccent=$('#e_accent').value.trim();
  const accent=rawAccent?(window.BRVTALAdminColorField?.normalize(rawAccent)||rawAccent):'';
  const payload={title:$('#e_title').value.trim(),slug:$('#e_slug').value.trim(),description:$('#e_description').value,cover_image:$('#e_cover_image').value,accent,featured:Number($('#e_featured').value),event_date:rawDate?rawDate.replace('T',' '):null,city:$('#e_city').value.trim(),venue:$('#e_venue').value.trim(),archive_year:Number($('#e_archive_year').value)||null,status:$('#e_status').value,ticket_instructions:$('#e_ticket_instructions').value,ticket_qr:$('#e_ticket_qr').value,ticket_url:$('#e_ticket_url').value};
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
    currentEvent={...(currentEvent||{}),id,...payload};
    eventSaved=true;
    await saveTickets(id);
    await loadEvents();
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


(function(){
  const originalOpenEvent=openEvent;
  let workflowRequest=0,workflowState='ready';

  async function eventWorkflowApi(query='',opts={}){
    const {headers:optHeaders,...rest}=opts;
    const response=await fetch('/api/event-workflow.php'+query,{
      credentials:'same-origin',
      ...rest,
      headers:{'Content-Type':'application/json',...(optHeaders||{})}
    });
    let payload;
    try{payload=await response.json()}catch(_){throw new Error('Event workflow returned invalid JSON')}
    if(response.status===401){location.href='/discadmin/';throw new Error(payload?.error||'Authentication required')}
    if(!response.ok||payload?.ok===false)throw new Error(payload?.error||('Event workflow failed ('+response.status+')'));
    return payload;
  }

  function renderWorkflowRelations(data){
    currentEvent={
      ...(currentEvent||{}),
      ...(data.event||{}),
      ticket_types:Array.isArray(data.ticket_types)?data.ticket_types:[],
      lineup:Array.isArray(data.lineup)?data.lineup:[],
      timetable:Array.isArray(data.timetable)?data.timetable:[]
    };
    $('#tickets').innerHTML='';
    currentEvent.ticket_types.forEach(addTicket);
    renderEventArtists();
    renderTimetable(currentEvent.timetable);
    $('#tickets').dataset.loadState='ready';
    $('#eventArtists').dataset.loadState='ready';
    $('#eventTimetable').dataset.loadState='ready';
  }

  async function refreshWorkflow(eventId,request){
    const response=await eventWorkflowApi('?id='+encodeURIComponent(eventId),{method:'GET'});
    if(request!==workflowRequest||Number(currentEvent?.id)!==eventId)return;
    renderWorkflowRelations(response.data||{});
    workflowState='ready';
  }

  function lineupPayloadFromSelection(){
    const existing=(Array.isArray(currentEvent?.lineup)?currentEvent.lineup:[]).map((item,index)=>({
      artist_id:Number(item.artist_id||item.id||0),
      lineup_order:Number.isFinite(Number(item.lineup_order))?Number(item.lineup_order):index,
      role:String(item.role??'')
    })).filter(item=>item.artist_id>0);
    const byArtist=new Map(existing.map(item=>[item.artist_id,item]));
    let nextOrder=existing.reduce((max,item)=>Math.max(max,item.lineup_order),-1)+1;
    return $$('#eventArtists [data-artist]:checked').map(element=>{
      const artistId=Number(element.dataset.artist);
      const saved=byArtist.get(artistId);
      return saved||{artist_id:artistId,lineup_order:nextOrder++,role:''};
    }).sort((a,b)=>a.lineup_order-b.lineup_order||a.artist_id-b.artist_id);
  }

  function eventWorkflowEventPayload(){
    const rawDate=$('#e_event_date').value;
    const rawAccent=$('#e_accent').value.trim();
    const accent=rawAccent?(window.BRVTALAdminColorField?.normalize(rawAccent)||rawAccent):'';
    const payload={
      title:$('#e_title').value.trim(),
      slug:$('#e_slug').value.trim(),
      description:$('#e_description').value,
      cover_image:$('#e_cover_image').value,
      accent,
      featured:Number($('#e_featured').value),
      event_date:rawDate?rawDate.replace('T',' '):null,
      city:$('#e_city').value.trim(),
      venue:$('#e_venue').value.trim(),
      archive_year:Number($('#e_archive_year').value)||null,
      status:$('#e_status').value,
      ticket_instructions:$('#e_ticket_instructions').value,
      ticket_qr:$('#e_ticket_qr').value,
      ticket_url:$('#e_ticket_url').value
    };
    const eventId=Number(currentEvent?.id||0);
    if(eventId>0)payload.id=eventId;
    return payload;
  }

  function ticketWorkflowPayloads(){
    return $$('#tickets .ticket-row').map((row,index)=>{
      const payload=brvtalTicketPayload(row,Number(currentEvent?.id||0),index);
      delete payload.event_id;
      const id=Number(row.dataset.id||0);
      if(id>0)payload.id=id;
      return payload;
    });
  }

  let eventEditorReady=Promise.resolve(null);
  openEvent=function(id=null){
    const modal=$('#eventModal');
    modal.querySelector('[data-seo-editor="content-core"]')?.remove();
    modal.dataset.eventId=id?String(id):'new';
    originalOpenEvent(id);
    const request=++workflowRequest;
    workflowState=id?'loading':'ready';
    $('#tickets').dataset.loadState=workflowState;
    $('#eventArtists').dataset.loadState=workflowState;
    $('#eventTimetable').dataset.loadState=workflowState;
    renderTimetable([]);
    if(!id){
      eventEditorReady=Promise.resolve(currentEvent);
      return eventEditorReady;
    }
    $('#tickets').innerHTML='<div class="empty">Loading event workflow…</div>';
    $('#eventArtists').innerHTML='<div class="empty">Loading event participation…</div>';
    $('#eventTimetable').innerHTML='<div class="empty">Loading timetable…</div>';
    eventEditorReady=refreshWorkflow(Number(id),request).catch(error=>{
      if(request!==workflowRequest)return currentEvent;
      workflowState='error';
      $('#tickets').dataset.loadState='error';
      $('#eventArtists').dataset.loadState='error';
      $('#eventTimetable').dataset.loadState='error';
      msg('Could not load the atomic event workflow: '+error.message,false,'eventNotice');
      return currentEvent;
    });
    return eventEditorReady;
  };

  saveEvent=async function(){
    if(currentEvent&&window.BRVTALSEOMetadata&&!$('#eventModal [data-seo-editor="content-core"]')){
      msg('Wait for SEO metadata to load before saving this event.',false,'eventNotice');
      return false;
    }
    if(workflowState!=='ready'){
      msg(workflowState==='loading'?'Wait for the event workflow to load before saving.':'Reload the event workflow before saving this event.',false,'eventNotice');
      return false;
    }
    const eventPayload=eventWorkflowEventPayload();
    const validationError=validateEventPayload(eventPayload);
    if(validationError){msg(validationError,false,'eventNotice');return false}
    if(!validateTicketRows()||!validateTimetableRows())return false;
    if(!csrf){msg('Security token unavailable. Reload the page.',false,'eventNotice');return false}

    const body={
      event:eventPayload,
      ticket_types:ticketWorkflowPayloads(),
      lineup:lineupPayloadFromSelection(),
      timetable:timetablePayloadFromRows()
    };
    workflowState='saving';
    try{
      const response=await eventWorkflowApi('',{
        method:'POST',
        body:JSON.stringify(body),
        headers:{'X-CSRF-Token':csrf}
      });
      renderWorkflowRelations(response.data||{});
      workflowState='ready';
      await loadEvents();
      window.BRVTALUnsavedChanges?.markClean?.($('#eventModal'));
      msg('Event, tickets, roster and timetable saved atomically.');
      return true;
    }catch(error){
      workflowState='ready';
      msg('Atomic event save failed: '+error.message,false,'eventNotice');
      return false;
    }
  };
})();

Object.assign(window.BRVTALContentCore, {openEvent,closeEvent,loadEvents,loadArtists,addTicket,addTimetableRow,step,saveEvent,previewEvent,getCurrentEvent:()=>currentEvent,whenEventReady:()=>eventEditorReady});
return ready;
}};