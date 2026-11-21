// T29: isolated read-only/synthetic marketing panel embedded by the canonical shell.
const root = document.querySelector('[data-recovery-root]');
if (root) {
  const status = root.querySelector('[data-recovery-status]');
  const rows = root.querySelector('[data-recovery-rows]');
  const form = root.querySelector('[data-recovery-form]');
  const confirm = root.querySelector('[data-recovery-confirm]');
  const yes = root.querySelector('[data-recovery-yes]');
  const no = root.querySelector('[data-recovery-no]');
  let prepared = null;
  const say = message => { status.textContent = message; };
  const reset = () => { prepared = null; confirm.hidden = true; yes.disabled = false; };
  async function summary() {
    try {
      const response = await fetch('/api/ticketing/admin-recovery.php',
        {credentials:'same-origin',cache:'no-store'});
      if (response.status === 423) { say('Sin habilitación para usar datos reales. Solo pruebas sintéticas.'); return; }
      if (!response.ok) throw new Error('NO_ACCESS');
      const payload = await response.json();
      rows.replaceChildren();
      for (const item of payload.data || []) {
        const tr = document.createElement('tr');
        for (const value of [item.event_id,item.campaign_key,item.stage,item.previews]) {
          const td = document.createElement('td');
          td.textContent = String(value);
          tr.appendChild(td);
        }
        rows.appendChild(tr);
      }
      say('Vistas previas elegibles: ' + (payload.data || []).length + ' grupos. Sin mensajes enviados.');
    } catch {
      say('Métricas no disponibles. Ningún mensaje fue enviado.');
    }
  }
  form.addEventListener('submit', e => {
    e.preventDefault();
    const formData = new FormData(form);
    const order = Number(formData.get('order_id'));
    const campaign = String(formData.get('campaign') || '');
    if (!Number.isSafeInteger(order) || order < 1 || !/^[a-z0-9_-]{1,32}$/.test(campaign)) {
      say('Pedido o campaña inválidos.'); return;
    }
    prepared = {order_id:order,campaign,idempotency_key:crypto.randomUUID()};
    confirm.hidden = false;
    say('Publicidad simulada. La preparación NO envía correos ni restaura reservas.');
    yes.focus();
  });
  no.addEventListener('click', () => { reset(); say('Cancelado sin envíos.'); form.querySelector('button').focus(); });
  yes.addEventListener('click', async () => {
    if (!prepared) return;
    yes.disabled = true;
    try {
      const response = await fetch('/api/ticketing/admin-recovery.php', {
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},
        body:JSON.stringify(prepared)
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error('BLOCKED');
      const notice = payload.data.state === 'planned'
        ? 'Vista previa registrada. Sin correo, pago ni reserva restaurada.'
        : 'Recuperación omitida por consentimiento, pago, vencimiento o frecuencia.';
      reset();
      await summary();
      say(notice);
    } catch {
      reset();
      say('Operación no disponible o no autorizada. No se envió correo.');
    }
  });
  summary();
}