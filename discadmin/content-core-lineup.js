/* BRVTAL Content Core — event participation bridge. Loaded by content-core.php when present. */
(function(){
  const get = window.fetch.bind(window);
  const endpoint = eventId => '/api/index.php/events/'+encodeURIComponent(eventId)+'/lineup';
  window.BRVTALContentCoreLineup = {
    async load(eventId, csrf){
      if(!eventId) return [];
      const r = await get(endpoint(eventId),{credentials:'same-origin',headers:{'X-CSRF-Token':csrf||''}});
      const j = await r.json().catch(()=>({}));
      if(!r.ok || j.ok===false) throw new Error(j.error||'Unable to load event participation');
      return Array.isArray(j.data)?j.data:[];
    },
    async save(eventId, lineup, csrf){
      if(!eventId) throw new Error('Event ID required');
      const r = await get(endpoint(eventId),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf||''},body:JSON.stringify({lineup:Array.isArray(lineup)?lineup:[]})});
      const j = await r.json().catch(()=>({}));
      if(!r.ok || j.ok===false) throw new Error(j.error||'Unable to save event participation');
      return j;
    }
  };
})();
