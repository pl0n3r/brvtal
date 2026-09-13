window.BRVTALContentCore = {mount(root) {

const API=['/api/index.php','/api/public'];let events=[],artists=[],currentEvent=null,currentStep=1,csrf='';
const $=s=>root.querySelector(s), $$=s=>[...root.querySelectorAll(s)];
async function api(path,opts={}){let last;for(const base of API){try{const {headers:optHeaders,...rest}=opts;const r=await fetch(base+path,{credentials:'same-origin',...rest,headers:{'Content-Type':'application/json',...(optHeaders||{})}});const j=await r.json();if(r.status===401){location.href='/discadmin/';return j}if(r.ok||j)return j;}catch(e){last=e}}throw last||new Error('API unavailable')}
function msg(text,ok=true,target='cc-notice'){const n=$('#'+target);n.textContent=text;n.className='notice show '+(ok?'ok':'err');setTimeout(()=>n.classList.remove('show'),5000)}
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
async function initAuth(){const j=await api('/auth');if(!j.authenticated){location.href='/discadmin/';return}csrf=j.csrf||'';if(!csrf)throw new Error('CSRF token unavailable')}
async function loadEvents(){try{const j=await api('/events');events=j.data||j.events||[];renderEvents()}catch(e){msg('Could not load events: '+e.message,false)}}
function renderEvents(){const q=($('#eventSearch').value||'').toLowerCase();const a=events.filter(x=>JSON.stringify(x).toLowerCase().includes(q));$('#eventsTable').innerHTML='<div class="th"><div>EVENT</div><div>DATE</div><div>STATUS</div><div></div></div>'+(a.length?a.map(x=>`<div class="tr"><div><div class="title">${esc(x.title||x.name)}</div><div class="meta">${esc(x.city||'')} ${x.venue?'· '+esc(x.venue):''}</div></div><div>${esc(x.event_date||'—')}</div><div><span class="pill">${esc(x.status||'draft')}</span></div><div class="actions"><button class="icon" onclick="BRVTALContentCore.openEvent(${Number(x.id)})">EDIT</button></div></div>`).join(''):'<div class="empty">No events found.</div>')}
function fill(id,v){const el=$('#'+id);if(el)el.value=v??''}
function openEvent(id=null){currentEvent=events.find(x=>Number(x.id)===Number(id))||null;$('#eventHeading').textContent=currentEvent?'EDIT EVENT':'NEW EVENT';fill('e_title',currentEvent?.title);fill('e_slug',currentEvent?.slug);fill('e_description',currentEvent?.description);fill('e_cover_image',currentEvent?.cover_image);fill('e_accent',currentEvent?.accent);fill('e_featured',currentEvent?.featured?'1':'0');fill('e_event_date',currentEvent?.event_date?String(currentEvent.event_date).replace(' ','T').slice(0,16):'');fill('e_city',currentEvent?.city);fill('e_venue',currentEvent?.venue);fill('e_archive_year',currentEvent?.archive_year);fill('e_status',currentEvent?.status||'draft');fill('e_ticket_instructions',currentEvent?.ticket_instructions);fill('e_ticket_qr',currentEvent?.ticket_qr);fill('e_ticket_url',currentEvent?.ticket_url);$('#tickets').innerHTML='';(currentEvent?.ticket_types||[]).forEach(addTicket);renderEventArtists();currentStep=1;setStep();$('#eventModal').classList.add('open')}
function closeEvent(){$('#eventModal').classList.remove('open')}
function step(dir){if(dir>0&&currentStep===1&&!$('#e_title').value.trim()){msg('Event name is required before continuing.',false,'eventNotice');return}currentStep=Math.max(1,Math.min(5,currentStep+dir));setStep()}
function setStep(){$$('.step').forEach(x=>x.classList.toggle('active',Number(x.dataset.step)===currentStep));$$('.step-content').forEach(x=>x.classList.toggle('active',Number(x.dataset.content)===currentStep));$('#prevBtn').style.visibility=currentStep===1?'hidden':'visible';$('#nextBtn').style.display=currentStep===5?'none':'inline-block';$('#cc-saveBtn').textContent=currentStep===5?'SAVE EVENT':'SAVE DRAFT'}
function addTicket(t={}){const d=document.createElement('div');d.className='ticket-row';if(t.id)d.dataset.id=String(t.id);d.innerHTML=`<input data-k="name" placeholder="Name" value="${esc(t.name||'')}"><input data-k="price" type="number" min="0" step="0.01" placeholder="Price" value="${esc(t.price??'')}"><select data-k="status"><option ${t.status==='draft'?'selected':''}>draft</option><option ${!t.status||t.status==='active'?'selected':''}>active</option><option ${t.status==='inactive'?'selected':''}>inactive</option><option ${t.status==='sold_out'?'selected':''}>sold_out</option></select><input data-k="external_url" placeholder="External ticket URL" value="${esc(t.external_url||'')}"><button type="button" class="icon" onclick="this.parentElement.remove()">×</button>`;$('#tickets').appendChild(d)}
function ticketPayload(row,eventId,i){const o={event_id:eventId,sort_order:i};row.querySelectorAll('[data-k]').forEach(el=>o[el.dataset.k]=el.value);return o}
function validateEventPayload(payload){if(!payload.title)return 'Name is required.';if(payload.status!=='draft'&&(!payload.event_date||!payload.city))return 'Name, date and city are required before leaving draft.';return ''}
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
  const payload={title:$('#e_title').value.trim(),slug:$('#e_slug').value.trim(),description:$('#e_description').value,cover_image:$('#e_cover_image').value,accent:$('#e_accent').value,featured:Number($('#e_featured').value),event_date:rawDate?rawDate.replace('T',' '):null,city:$('#e_city').value.trim(),venue:$('#e_venue').value.trim(),archive_year:Number($('#e_archive_year').value)||null,status:$('#e_status').value,ticket_instructions:$('#e_ticket_instructions').value,ticket_qr:$('#e_ticket_qr').value,ticket_url:$('#e_ticket_url').value};
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
async function saveTickets(eventId){const rows=$$('#tickets .ticket-row');const keep=new Set();for(let i=0;i<rows.length;i++){const row=rows[i],p=ticketPayload(row,eventId,i),existing=Number(row.dataset.id||0);let j;if(existing){keep.add(existing);j=await api('/ticket_types/'+existing,{method:'PUT',body:JSON.stringify(p),headers:{'X-CSRF-Token':csrf}})}else{j=await api('/ticket_types',{method:'POST',body:JSON.stringify(p),headers:{'X-CSRF-Token':csrf}});if(j.id)row.dataset.id=String(j.id)}if(j.ok===false)throw new Error(j.error||'Ticket save failed')}const old=(currentEvent?.ticket_types||[]).map(t=>Number(t.id)).filter(Boolean);for(const id of old){if(!keep.has(id)){const j=await api('/ticket_types/'+id,{method:'DELETE',headers:{'X-CSRF-Token':csrf}});if(j.ok===false)throw new Error(j.error||'Ticket delete failed')}}currentEvent=currentEvent||{id:eventId};currentEvent.ticket_types=rows.map((row,i)=>{const p=ticketPayload(row,eventId,i);return {...p,id:Number(row.dataset.id||0)}})}
async function loadArtists(){try{const j=await api('/artists');artists=j.data||j.artists||[];renderRoster();renderEventArtists()}catch(e){msg('Could not load artists: '+e.message,false)}}
function renderRoster(){const q=($('#artistSearch').value||'').toLowerCase();const a=artists.filter(x=>JSON.stringify(x).toLowerCase().includes(q));$('#rosterList').innerHTML=a.map(x=>`<div class="artist"><div class="ph">${x.photo?'IMG':'BRV'}</div><div class="grow"><b>${esc(x.name)}</b><small>${esc(x.collective_status||'none')} · order ${esc(x.collective_order??'—')}</small></div><button class="icon" onclick="BRVTALContentCore.editArtist(${Number(x.id)})">EDIT</button></div>`).join('')||'<div class="empty">No artists found.</div>'}
function editArtist(id){const a=artists.find(x=>Number(x.id)===Number(id));if(!a)return;$('#artistDetail').innerHTML=`<div class="field"><label>Artist</label><input value="${esc(a.name)}" disabled></div><div class="field"><label>Collective status</label><select id="a_status"><option ${a.collective_status==='none'?'selected':''}>none</option><option ${a.collective_status==='active'?'selected':''}>active</option><option ${a.collective_status==='alumni'?'selected':''}>alumni</option></select></div><div class="field"><label>Collective order</label><input id="a_order" type="number" value="${esc(a.collective_order??0)}"></div><div class="field"><label>Joined at</label><input id="a_joined" type="date" value="${esc((a.collective_joined_at||'').slice(0,10))}"></div><div class="field"><label>Left at</label><input id="a_left" type="date" value="${esc((a.collective_left_at||'').slice(0,10))}"></div><button class="btn red" onclick="BRVTALContentCore.saveArtist(${id})">SAVE ARTIST</button>`}
async function saveArtist(id){if(!csrf){msg('Security token unavailable. Reload the page.',false);return}try{const p={collective_status:$('#a_status').value,collective_order:Number($('#a_order').value)||0,collective_joined_at:$('#a_joined').value||null,collective_left_at:$('#a_left').value||null};const j=await api('/artists/'+id,{method:'PUT',body:JSON.stringify(p),headers:{'X-CSRF-Token':csrf}});if(j.ok===false)throw new Error(j.error||'Save failed');await loadArtists();msg('Artist lifecycle updated.')}catch(e){msg('Artist save failed: '+e.message,false)}}
function renderEventArtists(){if(!artists.length){$('#eventArtists').innerHTML='<div class="empty">Load artists to manage event participation.</div>';return}const selected=new Set((currentEvent?.artists||currentEvent?.event_artists||currentEvent?.lineup||[]).map(x=>Number(x.artist_id||x.id)));$('#eventArtists').innerHTML=artists.filter(a=>a.collective_status==='active'||selected.has(Number(a.id))).map(a=>`<div class="artist"><div class="ph">${a.photo?'IMG':'BRV'}</div><div class="grow"><b>${esc(a.name)}</b><small>${esc(a.collective_status||'none')}</small></div><input type="checkbox" data-artist="${Number(a.id)}" ${selected.has(Number(a.id))?'checked':''}></div>`).join('')}
$$('.tab').forEach(t=>t.onclick=()=>{$$('.tab').forEach(x=>x.classList.remove('active'));t.classList.add('active');const roster=t.dataset.tab==='roster';$('#eventsTab').style.display=roster?'none':'block';$('#rosterTab').style.display=roster?'block':'none';if(roster)loadArtists()});$('#eventSearch').oninput=renderEvents;$('#artistSearch').oninput=renderRoster;
(async()=>{try{await initAuth();await loadEvents();await loadArtists();}catch(e){msg('Initialization failed: '+e.message,false)}})();


(function(){
  const originalOpenEvent=openEvent;
  const originalSaveEvent=saveEvent;
  let ticketRequest=0,ticketState='ready';
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
  async function refreshLineup(eventId){
    if(!eventId||!window.BRVTALContentCoreLineup)return;
    const lineup=await window.BRVTALContentCoreLineup.load(eventId,csrf);
    if(currentEvent) currentEvent.lineup=lineup;
    renderEventArtists();
  }
  openEvent=function(id=null){
    const modal=$('#eventModal');
    modal.querySelector('[data-seo-editor="content-core"]')?.remove();
    modal.dataset.eventId=id?String(id):'new';
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
      refreshLineup(Number(id)).catch(e=>msg('Could not load event roster: '+e.message,false,'eventNotice'));
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
    const saved=await originalSaveEvent();
    if(!saved)return false;
    const eventId=Number(currentEvent?.id||0);
    if(!eventId||!window.BRVTALContentCoreLineup)return true;
    const lineup=$$('#eventArtists [data-artist]:checked').map((el,i)=>({artist_id:Number(el.dataset.artist),lineup_order:i,role:''}));
    try{
      await window.BRVTALContentCoreLineup.save(eventId,lineup,csrf);
      currentEvent.lineup=lineup;
      msg('Event participation saved.');
      return true;
    }catch(e){msg('Event saved, but roster could not be saved: '+e.message,false,'eventNotice');return false;}
  };
})();

Object.assign(window.BRVTALContentCore, {openEvent,closeEvent,loadEvents,loadArtists,addTicket,step,saveEvent,editArtist,saveArtist});
}};
