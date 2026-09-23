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
async function loadEvents(){try{const j=await api('/events');events=j.data||j.events||[];renderEvents()}catch(e){msg('Could not load events: '+e.message,false)}}
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
function step(dir){if(dir>0&&currentStep===1&&!$('#e_title').value.trim()){msg('Event name is required before continuing.',false,'eventNotice');return}currentStep=Math.max(1,Math.min(5,currentStep+dir));setStep()}
function setStep(){$$('.step').forEach(x=>x.classList.toggle('active',Number(x.dataset.step)===currentStep));$$('.step-content').forEach(x=>x.classList.toggle('active',Number(x.dataset.content)===currentStep));$('#prevBtn').style.visibility=currentStep===1?'hidden':'visible';$('#nextBtn').style.display=currentStep===5?'none':'inline-block';$('#cc-saveBtn').textContent=currentStep===5?'SAVE EVENT':'SAVE DRAFT'}
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
return (async()=>{try{await initAuth();await loadEvents();await loadArtists();}catch(e){msg('Initialization failed: '+e.message,false);throw e}})();


(function(){
  const originalOpenEvent=openEvent;
  const originalSaveEvent=saveEvent;
  let ticketRequest=0,ticketState='ready',lineupRequest=0,lineupState='ready';
  const canLoadLineup=()=>typeof window.BRVTALContentCoreLineup?.load==='function';
  const canSaveLineup=()=>typeof window.BRVTALContentCoreLineup?.save==='function';
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
    originalOpenEvent(id);
    const request=++ticketRequest;
    ticketState=id?'loading':'ready';
    $('#tickets').dataset.loadState=ticketState;
    if(id){
      $('#tickets').innerHTML='<div class="empty">Loading ticket types…</div>';
      refreshTickets(Number(id),request).catch(e=>{
        if(request!==ticketRequest)return;
        ticketState='error';
        $('#tickets').dataset.loadState='error';
        $('#tickets').innerHTML='<div class="empty">Ticket types could not be loaded.</div>';
        msg('Could not load ticket types: '+e.message,false,'eventNotice');
      });
      if(canLoadLineup()){
        refreshLineup(Number(id),lineupRequestId).catch(e=>{
          if(lineupRequestId!==lineupRequest||Number(currentEvent?.id)!==Number(id))return;
          lineupState='error';
          $('#eventArtists').dataset.loadState='error';
          $('#eventArtists').innerHTML='<div class="empty">Event participation could not be loaded.</div>';
          msg('Could not load event roster: '+e.message,false,'eventNotice');
        });
      }
    }
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
    const saved=await originalSaveEvent();
    if(!saved)return false;
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

Object.assign(window.BRVTALContentCore, {openEvent,closeEvent,loadEvents,loadArtists,addTicket,step,saveEvent,previewEvent});
}};