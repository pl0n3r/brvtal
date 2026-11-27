/* #1970: financial overview and separate staff tools within the canonical shell. */
const peso = new Intl.NumberFormat('es-CO', {style:'currency',currency:'COP',maximumFractionDigits:0});
const cash = value => value == null ? 'Sin dato' : peso.format(Number(value));
const quantity = value => value == null ? 'Sin dato' : String(value);
function payoutLabel(row) {
  if (row.approved_cop == null) return 'Sin dato';
  const hasWompi=Array.isArray(row.transactions)&&row.transactions.some(x=>x.provider==='wompi');
  if (Number(row.approved_cop)>0 || hasWompi) return cash(row.payout_cop);
  return Number(row.unverified_orders)>0?'Sin dato':'No aplica';
}
const make = (tag, text) => { const el=document.createElement(tag); if(text!==undefined) el.textContent=String(text); return el; };

const categoryLabels={artistas:'Artistas',lugar:'Lugar',produccion:'Producción',publicidad:'Publicidad',logistica:'Logística',otros:'Otros'};
const ledgerStatus={committed:'Comprometido',paid:'Pagado',active:'Vigente',void:'Anulado'};
const ledgerEndpoint='/api/ticketing/admin-finances.php';

function mountLedger(root, live) {
  const event=root.querySelector('#tk-ledger-event'), refresh=root.querySelector('[data-ledger-refresh]');
  const status=root.querySelector('[data-ledger-status]'), results=root.querySelector('[data-ledger-results]');
  root.append(root.querySelector('template').content.cloneNode(true));
  const form=root.querySelector('form'), fields=form.querySelector('fieldset'), f=form.elements;
  const voidButton=form.querySelector('[data-ledger-void]'), heading=form.querySelector('[data-ledger-heading]');
  let previous=null, busy=false, loaded=false, hasCatalog=false;
  function sync() {
    const expense=f.kind.value==='expense';
    form.querySelectorAll('[data-expense-only]').forEach(el=>{el.hidden=!expense;});
    f.concept.required=expense; f.amount_cop.min=expense?'1':'0';
    f.kind.disabled=previous!==null; f.category.disabled=previous!==null&&!expense;
    voidButton.hidden=previous===null;
    heading.textContent=previous===null?'Nuevo registro':'Ajustar registro #'+previous;
  }
  function reset() { previous=null;form.reset();sync(); }
  function setBusy(value) {
    busy=value;event.disabled=value;refresh.disabled=value;
    fields.disabled=value||!loaded;results.inert=value;
  }
  async function request(query, body) {
    const options={credentials:'same-origin',cache:'no-store'};
    if (body) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.ledgerCsrf},body:JSON.stringify(body)});
    const res=await fetch(ledgerEndpoint+query,options);
    if (!res.ok) throw new Error(res.status===403?'Sin permiso para gestionar el libro.':
      res.status===419?'La sesión venció. Vuelve a entrar.':res.status===409?
      'El registro cambió o el presupuesto ya existe. Revisa el libro actualizado.':'No se pudo completar la operación.');
    const data=await res.json();
    if (!data.ok) throw new Error('No se pudo completar la operación.');
    return data;
  }
  function edit(kind,row) {
    previous=row.id;f.kind.value=kind;f.category.value=row.category;
    f.amount_cop.value=row.amount_cop;f.entry_date.value=row.entry_date;
    f.concept.value=row.concept??'';f.status.value=kind==='expense'?row.status:'committed';
    f.reason.value='';sync();f.reason.focus();
  }
  function render(kind,rows) {
    const expense=kind==='expense', title=expense?'Gastos':'Presupuestos';
    results.append(make('h4',title));
    if (!rows.length) { results.append(make('p','No hay '+title.toLowerCase()+' registrados.'));return; }
    const table=make('table');table.className='tk-table';
    const names=expense?['Concepto','Categoría','Importe','Fecha','Estado','Acción']:['Categoría','Importe','Fecha','Estado','Acción'];
    const head=table.createTHead().insertRow();
    names.forEach(label=>{const th=make('th',label);th.scope='col';head.append(th);});
    const body=table.createTBody();
    for (const row of rows) {
      const tr=body.insertRow();tr.dataset.ledgerRow=row.id;
      const values=[...(expense?[row.concept]:[]),categoryLabels[row.category],peso.format(BigInt(row.amount_cop)),row.entry_date,ledgerStatus[row.status]];
      values.forEach((value,i)=>{const td=tr.insertCell();td.dataset.label=names[i];td.textContent=value;});
      const cell=tr.insertCell();cell.dataset.label='Acción';
      if (row.status!=='void') {
        const button=make('button','Editar');button.type='button';button.className='tk-button';
        button.addEventListener('click',()=>edit(kind,row));cell.append(button);
      } else cell.textContent='Historial conservado';
    }
    const wrap=make('div');wrap.className='tk-table-wrap';wrap.append(table);results.append(wrap);
  }
  async function load() {
    if (busy||!live()) return;
    loaded=false;reset();setBusy(true);results.replaceChildren();
    status.textContent='Consultando libro…';
    try {
      if (!hasCatalog) {
        const data=await request('?ledger=1');
        if (!live()) return;
        for (const item of data.events) {const option=make('option',item.title);option.value=String(item.id);event.append(option);}
        hasCatalog=true;
      }
      if (!event.value) {status.textContent='Elige un evento para consultar o registrar gastos.';return;}
      const data=await request('?ledger=1&event_id='+encodeURIComponent(event.value));
      if (!live()) return;
      render('expense',data.expenses);render('budget',data.budgets);
      loaded=true;status.textContent='Libro actualizado. No equivale a un cierre contable.';
    } catch (error) {if (live()) status.textContent=error.message;}
    finally {setBusy(false);}
  }
  async function save(action) {
    if (busy||!loaded||!live()) return;
    if (!f.reason.reportValidity()) return;
    const expense=f.kind.value==='expense',amount=Number(f.amount_cop.value);
    if (action!=='void'&&(!Number.isSafeInteger(amount)||!form.reportValidity())) return;
    const values=action==='void'?{}:{category:f.category.value,amount_cop:amount,entry_date:f.entry_date.value,
      status:expense?f.status.value:'active',...(expense?{concept:f.concept.value}:{})};
    const payload={kind:f.kind.value,action,event_id:Number(event.value),previous_id:previous===null?null:Number(previous),values,reason:f.reason.value};
    if (!Number.isSafeInteger(payload.event_id)||(previous!==null&&!Number.isSafeInteger(payload.previous_id))) return;
    setBusy(true);status.textContent='Guardando registro…';
    let message='Registro guardado.';
    try {await request('',payload);}
    catch (error) {message=error.message+' Verifica el resultado antes de repetir la operación.';}
    finally {setBusy(false);}
    if (!live()) return;
    await load();
    if (live()&&loaded) status.textContent=message;
  }
  f.kind.addEventListener('change',sync);
  event.addEventListener('change',()=>void load());refresh.addEventListener('click',()=>void load());
  form.addEventListener('submit',e=>{e.preventDefault();void save(previous===null?'create':'adjust');});
  form.querySelector('[data-ledger-new]').addEventListener('click',reset);
  voidButton.addEventListener('click',()=>void save('void'));
  return load;
}

export async function mount(panel) {
  const live = () => panel.isConnected &&
    panel.closest('[data-admin-module="ticketing"]')?.querySelector('[data-ticketing-tab="finance"]')?.getAttribute('aria-selected') === 'true';
  const notice = msg => { if (live()) panel.replaceChildren(make('p',msg)); };
  try {
    const response=await fetch('/discadmin/ticketing-finance.php',{
      credentials:'same-origin',cache:'no-store',headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}
    });
    if (!response.ok) throw new Error('FRAGMENT');
    const fragment=new DOMParser().parseFromString(await response.text(),'text/html')
      .querySelector('[data-finance-module]');
    if (!fragment || !live()) return;
    panel.replaceChildren(document.importNode(fragment,true));

    const form=panel.querySelector('[data-finance-form]');
    const status=panel.querySelector('[data-finance-status]');
    const results=panel.querySelector('[data-finance-results]');
    const event=form.elements.namedItem('event_id');
    const outlet=panel.querySelector('[data-finance-tool-panel]');
    const posButton=panel.querySelector('[data-finance-open-pos]');
    const ledger=panel.querySelector('#tk-finance-ledger'), summary=panel.querySelector('#tk-finance-summary');
    const ledgerButton=panel.querySelector('[data-finance-expenses]'), summaryButton=panel.querySelector('[data-finance-summary]');
    if (ledger&&summary&&ledgerButton&&summaryButton) {
      let loadLedger;
      function selectLedger(open) {
        ledger.hidden=!open;summary.hidden=open;
        ledgerButton.setAttribute('aria-pressed',String(open));summaryButton.setAttribute('aria-pressed',String(!open));
        if (open&&!loadLedger) {loadLedger=mountLedger(ledger,()=>live()&&ledger.isConnected);void loadLedger();}
      }
      ledgerButton.addEventListener('click',()=>selectLedger(true));
      summaryButton.addEventListener('click',()=>selectLedger(false));
    }
    let catalog=[], reportEvents=[], lastRequest=0;
    const params=()=>new URLSearchParams(new FormData(form));

    function eventLabel(id) {
      return catalog.find(row=>String(row.id)===String(id))?.title || 'Evento ' + id;
    }
    function typeLabel(type) {
      const names=Array.isArray(type.ticket_names)?type.ticket_names.filter(name=>typeof name==='string'&&name.trim()):[];
      const fallback='Tipo #'+String(type.ticket_type_id)+' (sin nombre histórico)';
      if (!names.length) return fallback;
      const label=names.join(' / ')+(names.length>1?' (nombres históricos)':'');
      return Number(type.unnamed_quantity)>0?label+' / '+fallback:label;
    }
    function fillEventOptions() {
      const selected=event.value;
      const options=new Map();
      for (const row of catalog) options.set(String(row.id),String(row.title));
      for (const id of reportEvents) if (!options.has(String(id))) options.set(String(id),'Evento '+id);
      event.replaceChildren(make('option','Todos los eventos'));
      event.firstElementChild.value='';
      for (const [id,title] of options) {
        const option=make('option',title); option.value=id; event.append(option);
      }
      if (options.has(selected)) event.value=selected;
    }
    function renderRows(rows) {
      results.replaceChildren();
      if (!rows.length) { results.append(make('p','No hay movimientos para los filtros seleccionados.')); return; }
      const table=make('table');table.className='tk-table';
      const header=table.createTHead().insertRow();
      for (const name of ['Evento','Ingresos verificados','Pagos','Devuelto','Neto tras devolución','Abono Wompi informado','Detalle']) {
        const th=make('th',name);th.scope='col';header.append(th);
      }
      const body=table.createTBody();
      for (const row of rows) {
        const tr=body.insertRow();
        const fields=[
          ['Evento',eventLabel(row.event_id)],
          ['Ingresos verificados','Wompi: '+cash(row.approved_cop)+' · POS: '+cash(row.pos_cop)],
          ['Pagos',quantity(row.paid_orders)],
          ['Devuelto',cash(row.refund_cop)],
          ['Neto tras devolución',cash(row.net_after_refunds_cop)],
          ['Abono Wompi informado',payoutLabel(row)],
        ];
        for (const [label,value] of fields) {
          const td=make('td',value);td.dataset.label=label;tr.append(td);
        }
        const detailCell=make('td');detailCell.dataset.label='Detalle';
        const details=make('details'),summary=make('summary','Ver desglose');
        const list=make('ul');
        for (const [label,value] of [
          ['Bruto histórico',cash(row.gross_cop)],['Descuentos',cash(row.discount_cop)],
          ['Devoluciones confirmadas del corte',cash(row.refund_in_period_cop)],
          ['Pendientes',quantity(row.pending_orders)],['Rechazados',quantity(row.declined_orders)],
          ['Reembolsados',quantity(row.refunded_orders)],['Boletas',quantity(row.tickets)],
          ['Cupos',quantity(row.capacity)],['Ingresos a puerta',quantity(row.checkins)],
          ['Caja presencial (POS)',cash(row.pos_cop)],['Ventas de caja',quantity(row.pos_orders)],
          ['Pedidos sin verificar',quantity(row.unverified_orders)],
          ['Comisión Wompi',cash(row.fee_cop)],['IVA',cash(row.fee_vat_cop)],
          ['Retenciones',cash(row.withheld_cop)],['Diferencia estimada',cash(row.difference_cop)]
        ]) list.append(make('li',label+': '+value));
        const types=Array.isArray(row.types)?row.types:[];
        const transactions=Array.isArray(row.transactions)?row.transactions:[];
        list.append(make('li','Boletas vendidas por tipo: '+(types.length
          ? types.map(x=>typeLabel(x)+' · '+quantity(x.quantity)+' boletas · '+cash(x.gross_cop)).join('; ')
          : 'Sin ventas registradas')));
        list.append(make('li','Referencias de transacciones: '+(transactions.length
          ? transactions.map(x=>String(x.transaction_id||'Sin referencia')).join('; ')
          : 'Sin registros')));
        details.append(summary,list);detailCell.append(details);tr.append(detailCell);
      }
      results.append(table);
    }

    async function refresh() {
      const revision=++lastRequest;
      status.textContent='Consultando finanzas…';results.replaceChildren();
      try {
        const res=await fetch('/api/ticketing/admin-finances.php?'+params(),{
          credentials:'same-origin',cache:'no-store'});
        if (!res.ok) throw new Error(res.status===403?'Sin permiso':'Consulta no disponible');
        const data=await res.json();
        if (!live()||revision!==lastRequest) return;
        if (!data.ok || !Array.isArray(data.rows)) throw new Error('Consulta no disponible');
        reportEvents=[...new Set(data.rows.map(row=>row.event_id).filter(id=>Number(id)>0))];
        fillEventOptions();
        status.textContent='Corte por fecha de venta; devoluciones confirmadas hasta «Hasta» y dentro del corte se distinguen. Los abonos y gastos siguen sin conciliación.';
        renderRows(data.rows);
      } catch (error) {
        if (live()&&revision===lastRequest) status.textContent=error.message==='Sin permiso'
          ? 'No tienes permiso para consultar finanzas.' : 'No fue posible consultar finanzas.';
      }
    }
    form.addEventListener('submit',e=>{e.preventDefault();void refresh();});
    form.querySelector('[data-finance-export]').addEventListener('click',async()=>{
      status.textContent='Preparando CSV…';
      try {
        const p=params();p.set('format','csv');
        const res=await fetch('/api/ticketing/admin-finances.php?'+p,{
          credentials:'same-origin',cache:'no-store'});
        if (!res.ok) throw new Error('EXPORT');
        const url=URL.createObjectURL(await res.blob()),anchor=make('a');
        anchor.href=url;anchor.download='brvtal-finanzas.csv';anchor.click();
        setTimeout(()=>URL.revokeObjectURL(url),1000);
        if(live()) status.textContent='Archivo generado. Datos provisionales.';
      } catch { if(live()) status.textContent='No fue posible exportar.'; }
    });
    posButton.addEventListener('click',async()=>{
      if (!live()) return;
      const refundButton=panel.querySelector('[data-finance-open-refunds]');
      posButton.disabled=true;outlet.replaceChildren();
      try {
        const module=await import('/discadmin/ticketing-pos.js' + new URL(import.meta.url).search);
        if (!live()) return;
        module.mount(outlet);
        posButton.setAttribute('aria-pressed','true');
        refundButton?.setAttribute('aria-pressed','false');
      } catch {
        if (live()) outlet.append(make('p','No fue posible abrir Caja.'));
      } finally { posButton.disabled=false; }
    });
    // A finance grant is sufficient for the report; the promo catalog is only
    // a best-effort enhancement. Never bypass its independent server permission.
    try {
      const embedded=panel.querySelector('[data-finance-catalog]')?.textContent;
      if (embedded) {
        const payload=JSON.parse(embedded);
        if (payload.ok && Array.isArray(payload.events)) catalog=payload.events;
      }
    } catch { /* A malformed fragment never grants new permissions. */ }
    if (!catalog.length) {
      try {
        const catalogRes=await fetch('/api/ticketing/admin-promos.php?catalog=1',{
          credentials:'same-origin',cache:'no-store'});
        if (catalogRes.ok) {
          const payload=await catalogRes.json();
          if (payload.ok && Array.isArray(payload.events)) catalog=payload.events;
        }
      } catch { /* Leave the authenticated finance report usable. */ }
    }
    if (!live()) return;
    fillEventOptions();
    await refresh();
  } catch { notice('No fue posible cargar el módulo financiero.'); }
}
