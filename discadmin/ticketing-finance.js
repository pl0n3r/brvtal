/* T20: finance panel inside the existing ticketing workspace. */
export async function mount(panel) {
  const live = () => panel.isConnected &&
    panel.closest('[data-admin-module="ticketing"]')?.querySelector('[data-ticketing-tab="finance"]')?.getAttribute('aria-selected') === 'true';
  const notice = (msg) => { if (live()) panel.replaceChildren(document.createTextNode(msg)); };
  try {
    const response = await fetch('/discadmin/ticketing-finance.php', {
      credentials: 'same-origin', cache:'no-store', headers:{'X-BRVTAL-ADMIN-FRAGMENT':'1'}
    });
    if (!response.ok) throw Error('FRAGMENT');
    const fragment = new DOMParser().parseFromString(await response.text(),'text/html')
      .querySelector('[data-finance-module]');
    if (!fragment || !live()) return;
    panel.replaceChildren(document.importNode(fragment,true));
    const form = panel.querySelector('[data-finance-form]');
    const status = panel.querySelector('[data-finance-status]');
    const results = panel.querySelector('[data-finance-results]');
    const params = () => new URLSearchParams(new FormData(form));
    const fmt = (v) => v === null ? 'Sin evidencia' : Number(v).toLocaleString('es-CO') + ' COP';
    const cell = (tr,value) => { const td=document.createElement('td'); td.textContent=String(value); tr.appendChild(td); };
    async function refresh() {
      status.textContent='Consultando…'; results.replaceChildren();
      try {
        const res=await fetch('/api/ticketing/admin-finances.php?' + params(),{credentials:'same-origin',cache:'no-store'});
        if (!res.ok) throw Error(res.status===403?'Sin permiso':'Consulta no disponible');
        const data=await res.json();
        if (!live()) return;
        status.textContent='Cifras preliminares. Comisiones no acreditadas y gastos no registrados aparecen sin evidencia.';
        if (!data.rows.length) { results.textContent='No hay pedidos en ese rango.'; return; }
        const table=document.createElement('table');
        const header=table.createTHead().insertRow();
        ['Evento','Bruto','Descuentos','Aprobado','Pagos','Pendientes','Rechazados','Reembolsados',
         'Entradas','Cupos','Checkins','Comisión','IVA','Retenciones','Neto reportado','Diferencia teórica'].forEach(h => {
          const th=document.createElement('th'); th.scope='col'; th.textContent=h; header.appendChild(th);
        });
        const body=table.createTBody();
        for (const row of data.rows) {
          const tr=body.insertRow();
          [row.event_id,fmt(row.gross_cop),fmt(row.discount_cop),fmt(row.approved_cop),
           row.paid_orders,row.pending_orders,row.declined_orders,row.refunded_orders,row.tickets,
           row.capacity===null?'Sin evidencia':row.capacity, row.checkins===null?'Sin evidencia':row.checkins,
           fmt(row.fee_cop),fmt(row.fee_vat_cop),fmt(row.withheld_cop),
           fmt(row.payout_cop),fmt(row.difference_cop)].forEach(value => cell(tr,value));
          const details=body.insertRow().insertCell();
          details.colSpan=16;
          details.textContent='Tipos: ' + (row.types.map(t =>
            'Tipo '+t.ticket_type_id+': '+t.quantity+' boletas / '+fmt(t.gross_cop)).join(' | ') || 'sin ventas') +
            '. Transacciones: ' + (row.transactions.map(t => String(t.transaction_id || 'sin referencia') +
            ' (pedido ' + t.order_id + ', settlement sin verificar)').join(' | ') || 'sin evidencia');
        }
        results.appendChild(table);
      } catch (error) {
        if (live()) status.textContent=error.message==='Sin permiso' ? error.message : 'No fue posible consultar finanzas.';
      }
    }
    form.addEventListener('submit',e => { e.preventDefault(); refresh(); });
    form.querySelector('[data-finance-export]').addEventListener('click',async () => {
      status.textContent='Preparando CSV…';
      try {
        const p=params(); p.set('format','csv');
        const res=await fetch('/api/ticketing/admin-finances.php?'+p,{credentials:'same-origin',cache:'no-store'});
        if (!res.ok) throw Error('EXPORT');
        const url=URL.createObjectURL(await res.blob()), a=document.createElement('a');
        a.href=url; a.download='brvtal-finanzas.csv'; a.click();
        setTimeout(()=>URL.revokeObjectURL(url),1000);
        status.textContent='Archivo generado. Datos provisionales.';
      } catch { if(live()) status.textContent='No fue posible exportar.'; }
    });
    refresh();
  } catch { notice('No fue posible cargar el módulo financiero.'); }
}
