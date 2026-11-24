/* #1970: financial overview and separate staff tools within the canonical shell. */
const peso = new Intl.NumberFormat('es-CO', {style:'currency',currency:'COP',maximumFractionDigits:0});
const cash = value => value == null ? 'Sin dato' : peso.format(Number(value));
const quantity = value => value == null ? 'Sin dato' : String(value);
const make = (tag, text) => { const el=document.createElement(tag); if(text!==undefined) el.textContent=String(text); return el; };

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
    let catalog=[], reportEvents=[], lastRequest=0;
    const params=()=>new URLSearchParams(new FormData(form));

    function eventLabel(id) {
      return catalog.find(row=>String(row.id)===String(id))?.title || 'Evento ' + id;
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
      for (const name of ['Evento','Aprobado','Pagos','Neto informado','Detalle']) {
        const th=make('th',name);th.scope='col';header.append(th);
      }
      const body=table.createTBody();
      for (const row of rows) {
        const tr=body.insertRow();
        const fields=[
          ['Evento',eventLabel(row.event_id)],
          ['Aprobado',cash(row.approved_cop)],
          ['Pagos',quantity(row.paid_orders)],
          ['Neto informado',cash(row.payout_cop)],
        ];
        for (const [label,value] of fields) {
          const td=make('td',value);td.dataset.label=label;tr.append(td);
        }
        const detailCell=make('td');detailCell.dataset.label='Detalle';
        const details=make('details'),summary=make('summary','Ver desglose');
        const list=make('ul');
        for (const [label,value] of [
          ['Bruto',cash(row.gross_cop)],['Descuentos',cash(row.discount_cop)],
          ['Pendientes',quantity(row.pending_orders)],['Rechazados',quantity(row.declined_orders)],
          ['Reembolsados',quantity(row.refunded_orders)],['Boletas',quantity(row.tickets)],
          ['Cupos',quantity(row.capacity)],['Ingresos a puerta',quantity(row.checkins)],
          ['Caja presencial',cash(row.pos_cop)],['Ventas de caja',quantity(row.pos_orders)],
          ['Comisión Wompi',cash(row.fee_cop)],['IVA',cash(row.fee_vat_cop)],
          ['Retenciones',cash(row.withheld_cop)],['Diferencia estimada',cash(row.difference_cop)]
        ]) list.append(make('li',label+': '+value));
        const types=Array.isArray(row.types)?row.types:[];
        const transactions=Array.isArray(row.transactions)?row.transactions:[];
        list.append(make('li','Boletas vendidas por tipo: '+(types.length
          ? types.map(x=>eventLabel(row.event_id)+' · '+x.quantity+' boletas · '+cash(x.gross_cop)).join('; ')
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
        status.textContent='Cifras provisionales. El reporte no acredita abonos ni gastos.';
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
