window.BRVTALContentCore = {mount(root) {

const API='/api/index.php';let events=[],artists=[],currentEvent=null,currentStep=1,csrf='';
const $=s=>root.querySelector(s), $$=s=>[...root.querySelectorAll(s)];
async function api(path,opts={}){const {headers:optHeaders,...rest}=opts;const r=await fetch(API+path,{credentials:'same-origin',...rest,headers:{'Content-Type':'application/json',...(optHeaders||{})}});let j;try{j=await r.json()}catch(_){throw new Error(`API ${r.status} returned invalid JSON`)}if(r.status===401){location.href='/discadmin/';throw new Error(j?.error||'Authentication required')}if(!r.ok||j?.ok===false)throw new Error(j?.error||`API request failed (${r.status})`);return j}
function msg(text,ok=true,target='cc-notice'){const n=$('#'+target);n.textContent=text;n.className='notice show '+(ok?'ok':'err');setTimeout(()=>n.classList.remove('show'),5000)}
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
async function initAuth(){const j=await api('/auth');if(!j.authenticated){location.href='/discadmin/';return}csrf=j.csrf||'';if(!csrf)throw new Error('CSRF token unavailable')}
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
function addTicket(t={}){const d=document.createElement('div');d.className='ticket-row';if(t.id){d.dataset.id=String(t.id);}d.innerHTML=`<input data-k="name" aria-label="Ticket name" placeholder="Name" value="${esc(t.name||'')}"><input data-k="price" aria-label="Ticket price" type="number" min="0" step="0.01" placeholder="Price" value="${esc(t.price??'')}"><select data-k="status" aria-label="Ticket status"><option ${t.status==='draft'?'selected':''}>draft</option><option ${!t.status||t.status==='active'?'selected':''}>active</option><option ${t.status==='inactive'?'selected':''}>inactive</option><option ${t.status==='sold_out'?'selected':''}>sold_out</option></select><input data-k="external_url" aria-label="Ticket external URL" placeholder="External ticket URL" value="${esc(t.external_url||'')}"><button type="button" class="icon" aria-label="Remove ticket type" onclick="this.parentElement.remove()">×</button>`;$('#tickets').appendChild(d)}
function ticketPayload(row,eventId,i){const o={event_id:eventId,sort_order:i};row.querySelectorAll('[data-k]').forEach(el=>o[el.dataset.k]=el.value);return o}
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
    const name=rows[i].querySelector('[data-k="name"]');
    if(!name?.value.trim())return ticketFieldError(i,name,'needs a name before saving.');
    const price=rows[i].querySelector('[data-k="price"]');
    if(price?.value && (!Number.isFinite(Number(price.value)) || Number(price.value)<0))return ticketFieldError(i,price,'needs a valid non-negative price.');
    const url=rows[i].querySelector('[data-k="external_url"]');
    if(url?.value.trim()){
      try{
        const parsed=new URL(url.value.trim());
        if(!['http:','https:'].includes(parsed.protocol) || url.value.trim().length>700)throw new Error('INVALID_URL');
      }catch(_){return ticketFieldError(i,url,'needs a valid http(s) purchase URL.');}
    }
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
async function saveTickets(eventId){const rows=$('#tickets .ticket-row');const keep=new Set();for(let i=0;i<rows.length;i++){const row=rows[i],p=ticketPayload(row,eventId,i),existing=Number(row.dataset.id||0);let j;if(existing){keep.add(existing);j=await api('/ticket_types/'+existing,{method:'PUT',body:JSON.stringify(p),headers:{'X-CSRF-Token':csrf}})}else{j=await api('/ticket_types',{method:'POST',body:JSON.stringify(p),headers:{'X-CSRF-Token':csrf}});if(j.id)row.dataset.id=String(j.id)}if(j.ok===false)throw new Error(j.error||'Ticket save failed')}const old=(currentEvent?.ticket_types||[]).map(t=>Number(t.id)).filter(Boolean);for(const id of old){if(!keep.has(id)){const j=await api('/ticket_types/'+id,{method:'DELETE',headers:{'X-CSRF-Token':csrf}});if(j.ok===false)throw new Error(j.error||'Ticket delete failed')}}currentEvent=currentEvent||{id:eventId};currentEvent.ticket_types=rows.map((row,i)=>{const p=ticketPayload(row,eventId,i);return {...p,id:Number(row.dataset.id||0)}})}
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
  return $('#eventArtists [data-artist]:checked').map(checkbox=>{
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
    ticket_types:$('#tickets .ticket-row').map((row,index)=>
      ticketPayload(row,Number(currentEvent?.id||0),index)
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
async function loadArtists(){try{const j=await api('/artists');artists=j.data||j.artists||[];renderRoster();renderEventArtists()}catch(e){msg('Could not load artists: '+e.message,false)}}
function renderRoster(){const q=($('#artistSearch').value||'').toLowerCase();const a=artists.filter(x=>JSON.stringify(x).toLowerCase().includes(q));$('#rosterList').innerHTML=a.map(x=>`<div class="artist"><div class="ph">${x.photo?'IMG':'BRV'}</div><div class="grow"><b>${esc(x.name)}</b><small>${esc(x.collective_status||'none')} · order ${esc(x.collective_order??'—')}</small></div><button class="icon" onclick="BRVTALContentCore.editArtist(${Number(x.id)})">EDIT</button></div>`).join('')||'<div class="empty">No artists found.</div>'}
function editArtist(id){const a=artists.find(x=>Number(x.id)===Number(id));if(!a)return;$('#artistDetail').innerHTML=`<div class="field"><label for="a_name">Artist</label><input id="a_name" value="${esc(a.name)}" disabled></div><div class="field"><label for="a_status">Collective status</label><select id="a_status"><option ${a.collective_status==='none'?'selected':''}>none</option><option ${a.collective_status==='active'?'selected':''}>active</option><option ${a.collective_status==='alumni'?'selected':''}>alumni</option></select></div><div class="field"><label for="a_order">Collective order</label><input id="a_order" type="number" value="${esc(a.collective_order??0)}"></div><div class="field"><label for="a_joined">Joined at</label><input id="a_joined" type="date" value="${esc((a.collective_joined_at||'').slice(0,10))}"></div><div class="field"><label for="a_left">Left at</label><input id="a_left" type="date" value="${esc((a.collective_left_at||'').slice(0,10))}"></div><button class="btn red" onclick="BRVTALContentCore.saveArtist(${id})">SAVE ARTIST</button>`}
async function saveArtist(id){if(!csrf){msg('Security token unavailable. Reload the page.',false);return}try{const p={collective_status:$('#a_status').value,collective_order:Number($('#a_order').value)||0,collective_joined_at:$('#a_joined').value||null,collective_left_at:$('#a_left').value||null};const j=await api('/artists/'+id,{method:'PUT',body:JSON.stringify(p),headers:{'X-CSRF-Token':csrf}});if(j.ok===false)throw new Error(j.error||'Save failed');await loadArtists();msg('Artist lifecycle updated.')}catch(e){msg('Artist save failed: '+e.message,false)}}
function renderEventArtists(){const holder=$('#eventArtists');if(currentEvent?.id&&holder.dataset.loadState==='loading'){holder.innerHTML='<div class="empty">Loading event participation…</div>';return}if(!artists.length){holder.innerHTML='<div class="empty">Load artists to manage event participation.</div>';return}const relations=currentEvent?.lineup??currentEvent?.event_artists??currentEvent?.artists??[];const selected=new Set(relations.map(x=>Number(x.artist_id||x.id)));holder.innerHTML=artists.filter(a=>a.collective_status==='active'||selected.has(Number(a.id))).map(a=>`<div class="artist"><div class="ph">${a.photo?'IMG':'BRV'}</div><label class="grow" for="event_artist_${Number(a.id)}"><b>${esc(a.name)}</b><small>${esc(a.collective_status||'none')}</small></label><input id="event_artist_${Number(a.id)}" type="checkbox" data-artist="${Number(a.id)}" aria-label="Include ${esc(a.name)} in event lineup" ${selected.has(Number(a.id))?'checked':''}></div>`).join('')}
$$('.tab').forEach(t=>t.onclick=()=>{$$('.tab').forEach(x=>x.classList.remove('active'));t.classList.add('active');const roster=t.dataset.tab==='roster';$('#eventsTab').style.display=roster?'none':'block';$('#rosterTab').style.display=roster?'block':'none';if(roster)loadArtists()});$('#eventSearch').oninput=renderEvents;$('#artistSearch').oninput=renderRoster;
(async()=>{try{await initAuth();await loadEvents();await loadArtists();}catch(e){msg('Initialization failed: '+e.message,false)}})();


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

Object.assign(window.BRVTALContentCore, {openEvent,closeEvent,loadEvents,loadArtists,addTicket,step,saveEvent,previewEvent,editArtist,saveArtist});
}};