(function(){
  'use strict';

  const PUBLIC_EVENT_STATUSES=new Set([
    'published','upcoming','tickets_available','last_tickets','sold_out',
    'finished','archived','cancelled'
  ]);
  const RESPONSE_STATES=new Set(['FRESH','STALE','NOT CONFIGURED','UNAVAILABLE']);
  const METRICS=['users','sessions','views'];

  let root=null;
  let panel=null;
  let activeController=null;
  let loadSequence=0;

  function node(id){return root?.querySelector('#'+id)||null}

  function resetMetrics(){
    for(const metric of METRICS){
      const current=node('eventInsights'+metric[0].toUpperCase()+metric.slice(1));
      const previous=node('eventInsights'+metric[0].toUpperCase()+metric.slice(1)+'Previous');
      if(current)current.textContent='—';
      if(previous)previous.textContent='PREVIOUS: —';
    }
  }

  function setState(state,message){
    if(!panel)return;
    panel.dataset.state=state.toLowerCase().replaceAll(' ','_');
    const status=node('eventInsightsStatus');
    if(status){
      status.textContent=state+(message?' · '+message:'');
      status.dataset.state=panel.dataset.state;
    }
  }

  function metricValue(value){
    if(typeof value!=='number'||!Number.isFinite(value)||value<0||value>Number.MAX_SAFE_INTEGER)return null;
    return value;
  }

  function renderMetric(metric,current,previous){
    const currentNode=node('eventInsights'+metric[0].toUpperCase()+metric.slice(1));
    const previousNode=node('eventInsights'+metric[0].toUpperCase()+metric.slice(1)+'Previous');
    const currentValue=metricValue(current?.[metric]);
    const previousValue=metricValue(previous?.[metric]);
    if(currentNode)currentNode.textContent=currentValue===null?'—':String(currentValue);
    if(previousNode)previousNode.textContent='PREVIOUS: '+(previousValue===null?'—':String(previousValue));
  }

  function isPotentiallyPublic(event){
    return Boolean(
      event
      && Number(event.id)>0
      && PUBLIC_EVENT_STATUSES.has(String(event.status||'').trim().toLowerCase())
    );
  }

  function mount(scope){
    root=scope||root;
    panel=root?.querySelector('[data-event-insights-panel]')||null;
    if(!panel)return null;
    const windowSelect=node('eventInsightsWindow');
    if(windowSelect){
      windowSelect.onchange=()=>{
        const event=window.BRVTALContentCore?.getCurrentEvent?.()||null;
        void load(event);
      };
    }
    return panel;
  }

  function reset(state='NOT PUBLISHED',message='Insights are available only for public Events.'){
    loadSequence+=1;
    activeController?.abort();
    activeController=null;
    resetMetrics();
    setState(state,message);
  }

  async function load(event){
    mount(root);
    const sequence=++loadSequence;
    activeController?.abort();
    activeController=null;
    resetMetrics();

    if(!isPotentiallyPublic(event)){
      setState('NOT PUBLISHED','No analytics request was sent.');
      return {state:'NOT PUBLISHED'};
    }

    const eventId=Number(event.id);
    const windowSelect=node('eventInsightsWindow');
    const windowKey=String(windowSelect?.value||'7d');
    if(windowKey!=='7d'){
      setState('UNAVAILABLE','Unsupported analytics window.');
      return {state:'UNAVAILABLE'};
    }

    const controller=new AbortController();
    activeController=controller;
    setState('LOADING','Loading bounded aggregate metrics.');

    try{
      const response=await fetch(
        '/api/admin-event-analytics.php?id='+encodeURIComponent(eventId)+'&window='+encodeURIComponent(windowKey),
        {
          method:'GET',
          credentials:'same-origin',
          cache:'no-store',
          headers:{'Accept':'application/json'},
          signal:controller.signal
        }
      );
      let payload;
      try{payload=await response.json()}catch(_){throw new Error('INVALID_JSON')}
      if(sequence!==loadSequence)return {state:'STALE_REQUEST'};
      if(response.status===401){location.href='/discadmin/';return {state:'UNAVAILABLE'};}
      if(!response.ok||payload?.ok!==true||!payload.data||typeof payload.data!=='object'){
        throw new Error('INSIGHTS_UNAVAILABLE');
      }

      const data=payload.data;
      const state=String(data.state||'').toUpperCase();
      if(!RESPONSE_STATES.has(state))throw new Error('INVALID_STATE');

      if(state==='FRESH'||state==='STALE'){
        const metrics=data.metrics&&typeof data.metrics==='object'?data.metrics:{};
        const previous=data.previous&&typeof data.previous==='object'?data.previous:null;
        for(const metric of METRICS)renderMetric(metric,metrics,previous);
        setState(state,state==='STALE'?'Cached metrics; source is stale.':'Aggregate metrics are current.');
      }else{
        resetMetrics();
        setState(state,state==='NOT CONFIGURED'?'Analytics is not configured.':'Analytics is temporarily unavailable.');
      }
      return {state};
    }catch(error){
      if(error?.name==='AbortError'||sequence!==loadSequence)return {state:'STALE_REQUEST'};
      resetMetrics();
      setState('UNAVAILABLE','Analytics is temporarily unavailable.');
      return {state:'UNAVAILABLE'};
    }finally{
      if(sequence===loadSequence)activeController=null;
    }
  }

  window.BRVTALEventInsights={mount,load,reset,isPotentiallyPublic};
})();