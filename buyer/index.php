<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer"><title>Mis compras · BRVTAL</title>
<style>
:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#090909;color:#f7f7f7}body{margin:0}
main{max-width:66rem;margin:auto;padding:clamp(1rem,4vw,3rem)}h1{font-size:clamp(2.2rem,7vw,4.5rem);text-transform:uppercase;letter-spacing:-.06em}
header{border-bottom:2px solid #d32626;margin-bottom:2rem}section,article{border:1px solid #555;padding:1.1rem;margin:1rem 0}
form{display:flex;flex-wrap:wrap;gap:.8rem;align-items:end}label{display:grid;gap:.35rem;flex:1 1 9rem}
input,button,textarea{font:inherit;padding:.7rem;color:inherit;background:#1b1b1b;border:1px solid #bbb;border-radius:.15rem}
button{cursor:pointer;background:#e9e9e9;color:#111;font-weight:700}button:disabled{opacity:.5;cursor:wait}
a{color:#ff6b6b}:focus-visible{outline:3px solid #ff5252;outline-offset:3px}
small,.muted{color:#bcbcbc}.ticket{border-left:3px solid #e13838;padding:.4rem .8rem;margin:.5rem 0}
#qr svg{display:block;max-width:100%;width:min(75vw,18rem);height:auto;background:#fff;margin:1rem auto}
[hidden]{display:none!important}#feedback{min-height:1.5em}
</style></head><body><main><header><h1>Mis compras</h1><p>Entradas y pedidos de tu cuenta. Sin datos del área administrativa.</p></header>
<p><a href="/buyer/login.php">Acceso de comprador</a> · La vinculación de invitados requiere correo verificado; aún no está habilitada.</p>
<form id="filters" aria-label="Filtrar compras"><label>Evento (ID)<input name="event_id" inputmode="numeric" pattern="[0-9]*"></label>
<label>Desde (UTC)<input name="from" type="date"></label><label>Hasta (UTC)<input name="to" type="date"></label>
<button>Filtrar</button></form>
<p id="feedback" role="status" aria-live="polite"></p><section id="orders" aria-label="Pedidos"><p>Cargando compras…</p></section>
<button id="more" hidden type="button">Ver más pedidos</button>
<section id="refund-list" aria-label="Solicitudes de devolución"><h2>Devoluciones</h2><div id="refund-items" aria-live="polite">Cargando solicitudes…</div></section>
<section id="qr" hidden aria-label="Entrada QR"><h2>Entrada protegida</h2>
<p class="muted">Usa la credencial original recibida al emitir tu entrada. BRVTAL no conserva el secreto en claro.</p>
<form id="qr-form"><label>Credencial original<input id="token" type="password" required autocomplete="off" spellcheck="false" minlength="43" maxlength="43"></label>
<button>Mostrar QR</button></form><div id="qr-output" role="img" aria-label="Código QR de entrada" hidden></div>
<button id="download" type="button" hidden>Descargar QR SVG</button><p id="qr-feedback" role="status" aria-live="polite"></p></section>
</main><script>
(() => {
  'use strict';
  const $ = id => document.getElementById(id), api='/api/buyer/account.php';
  const filters=$('filters'),orders=$('orders'),feedback=$('feedback'),more=$('more'),qr=$('qr');
  let csrf='',page=0,ticket='',payload='';
  const el=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);return n;};
  const statuses={draft:'Borrador',pending:'Pago pendiente',paid:'Aprobado',failed:'Rechazado',declined:'Rechazado',error:'Error',created:'Iniciado',cancelled:'Cancelado',expired:'Expirado',refunded:'Reembolsado',issued:'Disponible',used:'Usada',revoked:'Revocada'};

  const refundsApi='/api/buyer/refunds.php';
  const refundStates={requested:'Solicitada',approved:'Aprobada',processing:'En proceso',manual_pending:'Pendiente manual',
    refunded:'Reembolsada',rejected:'Rechazada',failed:'Fallida'};
  async function loadRefunds(){
    const target=$('refund-items');
    try{
      const response=await fetch(refundsApi,{credentials:'same-origin',cache:'no-store'});
      if(!response.ok)throw Error('UNAVAILABLE');
      const payload=await response.json();
      if(!Array.isArray(payload.data))throw Error('UNAVAILABLE');
      target.replaceChildren();
      if(!payload.data.length)target.append(el('p','Sin solicitudes de devolución.'));
      for(const r of payload.data){
        const text='Pedido '+r.order_id+' · '+(refundStates[r.status]||'Estado pendiente de verificación')+' · '+r.amount_cop+' COP';
        target.append(el('p',text));
      }
    }catch(_){target.textContent='Las devoluciones no están disponibles en este entorno.';}
  }
  function refundForm(order){
    const eligible=(order.items||[]).filter(item=>item.status==='issued'&&item.ticket_id);
    if(order.status!=='paid'||!eligible.length||!/^[a-f0-9]{32}$/.test(order.public_id||''))return null;
    const form=el('form');form.setAttribute('aria-label','Solicitar devolución');
    const caption=el('h3','Solicitar devolución');form.append(caption);
    eligible.forEach((item,i)=>{
      const label=el('label');const checkbox=el('input');checkbox.type='checkbox';checkbox.name='refund_entry';
      checkbox.value=item.ticket_id;
      label.append(checkbox,document.createTextNode(' '+(item.name||'Entrada')+' #'+(i+1)));form.append(label);
    });
    const reasonLabel=el('label','Motivo de la devolución');
    const reason=el('textarea');reason.name='reason';reason.required=true;reason.minLength=4;reason.maxLength=220;
    reasonLabel.append(reason);form.append(reasonLabel);
    const button=el('button','Enviar solicitud');button.type='submit';
    const status=el('p');status.setAttribute('role','status');form.append(button,status);
    form.addEventListener('input',()=>delete form.dataset.idempotencyKey);
    form.addEventListener('submit',async event=>{
      event.preventDefault();
      const selected=Array.from(form.querySelectorAll('input[name="refund_entry"]:checked'),input=>input.value);
      if(!selected.length){status.textContent='Selecciona al menos una entrada disponible.';return;}
      if(!csrf){status.textContent='Sesión no disponible.';return;}
      button.disabled=true;status.textContent='Enviando solicitud…';
      form.dataset.idempotencyKey||=crypto.randomUUID();
      try{
        const response=await fetch(refundsApi,{method:'POST',credentials:'same-origin',cache:'no-store',
          headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
          body:JSON.stringify({order_id:order.public_id,entry_ids:selected,reason:reason.value,
            idempotency_key:form.dataset.idempotencyKey})});
        if(!response.ok)throw Error('UNAVAILABLE');
        status.textContent='Solicitud registrada; pendiente de confirmación.';
        await loadRefunds();
      }catch(_){status.textContent='No se confirmó la solicitud. Puedes reintentar sin duplicarla.';}
      finally{button.disabled=false;}
    });
    return form;
  }
  function resetQr(){ticket='';payload='';qr.hidden=true;$('token').value='';$('qr-output').replaceChildren();$('qr-output').hidden=true;$('download').hidden=true;$('qr-feedback').textContent='';}
  function showQr(id){resetQr();ticket=id;qr.hidden=false;$('token').focus();}
  function card(order){
    const box=el('article'),title=el('h2',order.event_title||'Evento');box.append(title,
      el('p','Pedido '+(statuses[order.status]||order.status)+' · Pago: '+(statuses[order.payment_status]||order.payment_status||'Sin intento')),
      el('small','Fecha UTC: '+(order.created_at_utc||'—')+' · '+order.total_cop+' '+(order.currency||'COP')));
    for(const item of order.items||[]){
      const line=el('div');line.className='ticket';line.append(el('strong',item.name),el('p','Cantidad: '+item.quantity+' · Entrada: '+(statuses[item.status]||item.status)));
      if(order.status==='paid'&&item.status==='issued'&&item.credential_active!==false&&item.qr_requires_original_token&&item.ticket_id){
        const button=el('button','Ver QR');button.type='button';button.addEventListener('click',()=>showQr(item.ticket_id));line.append(button);
      }box.append(line);
    }
    const refund=refundForm(order);if(refund)box.append(refund);
    return box;
  }
  async function load(append=false){
    more.disabled=true;feedback.textContent='Cargando compras…';if(!append){orders.replaceChildren();resetQr();}
    const query=new URLSearchParams({page:String(page)});for(const name of ['event_id','from','to']){
      const value=String(filters.elements.namedItem(name).value||'');if(value)query.set(name,value);
    }
    try{const r=await fetch(api+'?'+query,{credentials:'same-origin',cache:'no-store'});if(r.status===401){location.href='/buyer/login.php';return;}
      if(!r.ok)throw Error('API');const result=(await r.json()).data;if(!result||!Array.isArray(result.orders))throw Error('API');
      if(!result.orders.length&&!append)orders.append(el('p','Todavía no hay compras para estos filtros.'));
      for(const order of result.orders)orders.append(card(order));more.hidden=!result.has_more;feedback.textContent=result.orders.length?'Compras cargadas.':'No hay más pedidos.';
    }catch(_){feedback.textContent='No pudimos cargar tus compras. Intenta nuevamente.';more.hidden=true;}
    finally{more.disabled=false;}
  }
  filters.addEventListener('submit',e=>{e.preventDefault();page=0;load();});
  more.addEventListener('click',()=>{page++;load(true);});
  // Fixed QR version 5-L, byte mode, mask 0. Offline encoding: never send credentials to a third party.
  function qrSvg(value){
    const bytes=Array.from(new TextEncoder().encode(value));if(bytes.length>106)throw Error('QR_LENGTH');
    const bits=[];const add=(n,len)=>{for(let i=len-1;i>=0;i--)bits.push((n>>>i)&1);};
    add(4,4);add(bytes.length,8);bytes.forEach(n=>add(n,8));for(let i=0;i<4&&bits.length<864;i++)bits.push(0);
    while(bits.length%8)bits.push(0);let data=[];for(let i=0;i<bits.length;i+=8)data.push(parseInt(bits.slice(i,i+8).join(''),2));
    while(data.length<108)data.push(data.length%2===0?0xec:0x11);
    const mul=(x,y)=>{let z=0;for(let i=7;i>=0;i--){z=((z<<1)^((z>>>7)*0x11d))&255;z^=((y>>>i)&1)*x;}return z;};
    const div=Array(26).fill(0);div[25]=1;let root=1;for(let i=0;i<26;i++){for(let j=0;j<26;j++){div[j]=mul(div[j],root);if(j<25)div[j]^=div[j+1];}root=mul(root,2);}
    const rem=Array(26).fill(0);for(const byte of data){let factor=byte^rem.shift();rem.push(0);for(let j=0;j<26;j++)rem[j]^=mul(div[j],factor);}
    const code=data.concat(rem),size=37,m=Array.from({length:size},()=>Array(size).fill(false)),locked=m.map(row=>row.slice());
    const put=(x,y,v)=>{if(x>=0&&y>=0&&x<size&&y<size){m[y][x]=!!v;locked[y][x]=true;}};
    for(const [fx,fy] of [[0,0],[30,0],[0,30]])for(let y=-1;y<=7;y++)for(let x=-1;x<=7;x++){
      const v=x>=0&&x<=6&&y>=0&&y<=6&&(x===0||x===6||y===0||y===6||(x>=2&&x<=4&&y>=2&&y<=4));put(fx+x,fy+y,v);
    }
    for(let i=8;i<size-8;i++){put(6,i,i%2===0);put(i,6,i%2===0);}
    for(let y=-2;y<=2;y++)for(let x=-2;x<=2;x++)put(30+x,30+y,Math.max(Math.abs(x),Math.abs(y))!==1);
    let format=8;for(let i=0;i<10;i++)format=(format<<1)^(((format>>>9)&1)*0x537);format=((8<<10)|format)^0x5412;
    const f=i=>(format>>>i)&1;
    for(let i=0;i<=5;i++)put(8,i,f(i));put(8,7,f(6));put(8,8,f(7));put(7,8,f(8));
    for(let i=9;i<15;i++)put(14-i,8,f(i));for(let i=0;i<8;i++)put(size-1-i,8,f(i));
    for(let i=8;i<15;i++)put(8,size-15+i,f(i));put(8,size-8,true);
    let k=0;for(let right=size-1;right>=1;right-=2){if(right===6)right=5;for(let vert=0;vert<size;vert++){
      const y=((right+1)&2)===0?size-1-vert:vert;for(let j=0;j<2;j++){const x=right-j;if(!locked[y][x]){
        const bit=k<code.length*8?(code[k>>>3]>>>(7-(k&7)))&1:0;m[y][x]=!!(bit^((x+y)%2===0?1:0));k++;
      }}
    }}
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 45 45');
    svg.setAttribute('aria-label','QR local de la entrada');const back=document.createElementNS(svg.namespaceURI,'rect');
    back.setAttribute('width','45');back.setAttribute('height','45');back.setAttribute('fill','#fff');svg.append(back);
    for(let y=0;y<size;y++)for(let x=0;x<size;x++)if(m[y][x]){const r=document.createElementNS(svg.namespaceURI,'rect');
      r.setAttribute('x',String(x+4));r.setAttribute('y',String(y+4));r.setAttribute('width','1');r.setAttribute('height','1');r.setAttribute('fill','#000');svg.append(r);}
    return svg;
  }
  $('qr-form').addEventListener('submit',async e=>{e.preventDefault();const button=e.currentTarget.querySelector('button');button.disabled=true;
    $('qr-feedback').textContent='Verificando entrada…';payload='';$('qr-output').hidden=true;$('download').hidden=true;
    try{if(!csrf)throw Error('UNAVAILABLE');const r=await fetch(api,{method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'qr',ticket_id:ticket,token:$('token').value})});
      if(!r.ok)throw Error('UNAVAILABLE');const data=await r.json();if(typeof data.qr!=='string'||!data.qr.startsWith('BRVTAL:T1:'))throw Error('UNAVAILABLE');
      const svg=qrSvg(data.qr);$('qr-output').replaceChildren(svg);$('qr-output').hidden=false;$('download').hidden=false;payload=data.qr;
      $('qr-feedback').textContent='QR autorizado. No compartas esta entrada.';$('token').value='';
    }catch(_){$('qr-feedback').textContent='No se pudo autorizar esta entrada. Revisa la credencial y su vigencia.';}
    finally{button.disabled=false;}
  });
  $('download').addEventListener('click',()=>{if(!payload)return;const svg=$('qr-output').querySelector('svg');if(!svg)return;
    const blob=new Blob([new XMLSerializer().serializeToString(svg)],{type:'image/svg+xml'}),url=URL.createObjectURL(blob);
    const a=el('a');a.href=url;a.download='entrada-brvtal.svg';document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),0);
  });
  (async()=>{try{const r=await fetch('/api/buyer/auth.php',{credentials:'same-origin',cache:'no-store'});const d=await r.json();
    if(!r.ok||!d.authenticated){location.href='/buyer/login.php';return;}csrf=String(d.csrf||'');if(!csrf)throw Error('AUTH');await load();await loadRefunds();
  }catch(_){feedback.textContent='No está disponible tu cuenta. Reintenta más tarde.';orders.replaceChildren();}})();
})();
</script></body></html>
