// #1983: real recovery panel, inside the existing DISCADMIN shell.
const root = document.querySelector('[data-recovery-root]');
if (root) {
  const status = root.querySelector('[data-recovery-status]');
  const rows = root.querySelector('[data-recovery-rows]');
  const form = root.querySelector('[data-recovery-form]');
  const confirm = root.querySelector('[data-recovery-confirm]');
  const yes = root.querySelector('[data-recovery-yes]');
  const no = root.querySelector('[data-recovery-no]');
  const mainDescription = root.querySelector('h2 + p');
  const formButton = form.querySelector('button[type="submit"]');
  const headers = root.querySelectorAll('.tk-table thead th');
  if (mainDescription) mainDescription.textContent =
    'Envía un único recordatorio a compras pendientes con consentimiento vigente. No modifica pagos ni reservas.';
  if (formButton) formButton.textContent = 'Preparar recordatorio';
  const confirmText = confirm.querySelector('p');
  if (confirmText) confirmText.textContent =
    'Se pondrá un recordatorio en la cola de correo, solo si el pedido es elegible. No se cobrará ni se reservará nada.';
  yes.textContent = 'Programar recordatorio';
  ['Evento', 'En cola', 'Aceptados', 'Fallidos'].forEach((text, i) => {
    if (headers[i]) headers[i].textContent = text;
  });
  let prepared = null;
  let enabled = false;
  const say = message => { status.textContent = message; };
  const reset = () => { prepared = null; confirm.hidden = true; yes.disabled = false; };
  const reasons = {
    order_not_pending:'El pedido no está pendiente.',
    consent_unavailable:'No hay consentimiento de marketing vigente o correo verificado.',
    payment_confirmed:'El pago ya está confirmado.',
    checkout_expired:'El pedido venció.',
    outside_window:'El pedido está fuera de la ventana de recordatorios.',
    already_reminded:'Este pedido ya recibió su único recordatorio.',
    mail_unavailable:'El correo no está habilitado.',
    test_recipient_blocked:'En pruebas, solo se permiten direcciones autorizadas.',
    window_unavailable:'La ventana de recordatorios no está configurada.'
  };
  async function summary() {
    try {
      const response = await fetch('/api/ticketing/admin-recovery.php',
        {credentials:'same-origin',cache:'no-store'});
      if (!response.ok) throw new Error('NO_ACCESS');
      const payload = await response.json();
      rows.replaceChildren();
      for (const item of payload.data || []) {
        const tr = document.createElement('tr');
        const values=['Evento '+item.event_id,item.queued || 0,item.accepted || 0,item.failed || 0];
        const names=['Evento','En cola','Aceptados','Fallidos'];
        for (const [index,value] of values.entries()) {
          const td = document.createElement('td');
          td.dataset.label = names[index];
          td.textContent = String(value);
          tr.appendChild(td);
        }
        rows.appendChild(tr);
      }
      enabled = payload.mail_ready === true &&
        (payload.mode === 'live' || payload.mode === 'test');
      formButton.disabled = !enabled;
      const descriptor = payload.mode === 'live' ? 'envío real'
        : payload.mode === 'test' ? 'solo correos de prueba autorizados'
        : 'correo deshabilitado';
      say('Eventos con recordatorios: ' + (payload.data || []).length +
        '. Modo: ' + descriptor + (enabled ? '.' : '. No se programarán mensajes.'));
    } catch {
      enabled = false;
      formButton.disabled = true;
      say('Resultados no disponibles. Comprueba tu acceso y vuelve a intentar.');
    }
  }
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (!enabled) { say('El correo debe estar habilitado antes de programar recordatorios.'); return; }
    const data = new FormData(form);
    const order = Number(data.get('order_id'));
    const campaign = String(data.get('campaign') || '');
    if (!Number.isSafeInteger(order) || order < 1 || !/^[a-z0-9_-]{1,32}$/.test(campaign)) {
      say('Revisa el número del pedido y el nombre de la campaña.'); return;
    }
    prepared = {order_id:order,campaign,idempotency_key:crypto.randomUUID()};
    confirm.hidden = false;
    say('Confirma para comprobar el consentimiento y programar un único recordatorio.');
    yes.focus();
  });
  no.addEventListener('click', () => {
    reset(); say('Operación cancelada. No se programó ningún mensaje.'); formButton.focus();
  });
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
      const data = payload.data || {};
      const message = data.state === 'queued'
        ? 'Recordatorio programado una sola vez. El servicio de correo gestiona la entrega.'
        : reasons[data.reason] || 'No se programó un recordatorio para este pedido.';
      reset();
      await summary();
      say(message);
    } catch {
      reset();
      say('No se pudo programar el recordatorio. Comprueba el estado y vuelve a intentar.');
    }
  });
  await summary();
}
