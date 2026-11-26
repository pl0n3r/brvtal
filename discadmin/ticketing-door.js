export function mount(root) {
  if (!root || root.dataset.doorMounted === 'true') return () => {};
  root.dataset.doorMounted = 'true';
  const event = root.querySelector('[data-door-event]');
  const form = root.querySelector('[data-door-form]');
  const qr = root.querySelector('[name="qr"]');
  const result = root.querySelector('[data-door-result]');
  const summary = root.querySelector('[data-door-summary]');
  const audit = root.querySelector('[data-door-audit]');
  const cameraButton = root.querySelector('[data-door-camera]');
  const stopButton = root.querySelector('[data-door-stop]');
  const cameraStatus = root.querySelector('[data-door-camera-status]');
  const video = root.querySelector('[data-door-video]');
  const deviceKey = 'brvtal-door-device';
  const attemptKey = 'brvtal-door-attempt';
  const submitButton = root.querySelector('[data-door-submit]');
  const receiptButton = document.createElement('button');
  receiptButton.type = 'button';
  receiptButton.dataset.doorReceipt = '';
  receiptButton.textContent = 'Consultar intento anterior';
  receiptButton.hidden = true;
  cameraButton.insertAdjacentElement('afterend', receiptButton);
  let attempt = null, storageFault = false;
  let device = '';
  try {
    device = sessionStorage.getItem(deviceKey) || '';
    if (!/^door-[a-f0-9-]{36}$/.test(device)) {
      device = 'door-' + crypto.randomUUID();
      sessionStorage.setItem(deviceKey, device);
    }
  } catch { storageFault = true; }
  let stream = null, frameId = 0, pending = false, generation = 0;
  let eventRevision = 0, cameraRevision = 0, cameraStarting = false, closed = false;
  const messages = {
    already_used: 'Entrada ya utilizada. No permitir acceso.',
    entry_revoked: 'Entrada anulada o reembolsada. Acceso denegado.',
    credential_revoked: 'Código anterior o revocado. Acceso denegado.',
    wrong_event: 'Entrada de otro evento. Acceso denegado.',
    malformed_qr: 'Código QR inválido. Acceso denegado.',
    unknown_credential: 'Código no reconocido. Acceso denegado.',
    event_closed: 'Evento cerrado. Acceso denegado.',
    payment_unverified: 'Pago sin verificar. Acceso denegado.'
  };
  function feedback(state, text) {
    result.dataset.state = state;
    result.textContent = text;
  }
  function syncControls() {
    const blocked = pending || attempt !== null || storageFault;
    qr.disabled = blocked;
    submitButton.disabled = blocked;
    cameraButton.disabled = blocked;
    submitButton.hidden = attempt !== null;
    cameraButton.hidden = attempt !== null || stream !== null || cameraStarting;
    receiptButton.hidden = attempt === null;
    receiptButton.disabled = pending || storageFault;
  }
  function unknown(message = 'Estado desconocido: NO permitir acceso.') {
    feedback('deny', message + (attempt ? ' Evento del intento: ' + attempt.event_id
      + '. Consulta el recibo; si no se resuelve, informa al responsable de puerta.' : ''));
  }
  function restoreAttempt() {
    try {
      const stored = sessionStorage.getItem(attemptKey);
      if (stored !== null) {
        const saved = JSON.parse(stored);
        if (!saved || Object.keys(saved).sort().join(',') !== 'device_id,event_id,request_id'
            || !Number.isSafeInteger(saved.event_id) || saved.event_id < 1
            || !/^door-[a-f0-9-]{36}$/.test(saved.device_id)
            || !/^[a-f0-9]{32}$/.test(saved.request_id) || /^0{32}$/.test(saved.request_id)) {
          throw new Error('invalid attempt');
        }
        attempt = saved;
        if ([...event.options].some(option => option.value === String(saved.event_id))) {
          event.value = String(saved.event_id);
        }
        unknown();
      }
    } catch { storageFault = true; }
    if (storageFault) unknown('No se puede recuperar el estado local. NO permitir acceso. Informa al responsable de puerta.');
    syncControls();
  }
  function saveAttempt(eventId) {
    const requestId = Array.from(crypto.getRandomValues(new Uint8Array(16)),
      byte => byte.toString(16).padStart(2, '0')).join('');
    if (/^0{32}$/.test(requestId) || sessionStorage.getItem(attemptKey) !== null) throw new Error('attempt');
    const current = {event_id: Number(eventId), device_id: device, request_id: requestId};
    sessionStorage.setItem(attemptKey, JSON.stringify(current));
    attempt = current;
    return current;
  }
  function clearAttempt(current) {
    // A stale response from an unmounted scanner must never clear a newer intent.
    const stored = JSON.parse(sessionStorage.getItem(attemptKey));
    if (!stored || stored.request_id !== current.request_id || stored.device_id !== current.device_id
        || stored.event_id !== current.event_id) throw new Error('attempt changed');
    sessionStorage.removeItem(attemptKey);
    attempt = null;
  }
  async function post(body) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch('/api/ticketing/checkin.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf},
        body: JSON.stringify(body)
      });
      return {status: response.status, data: await response.json()};
    } finally { clearTimeout(timer); }
  }
  function finishRequest() {
    pending = false;
    if (closed) return;
    syncControls();
    void refresh();
    if (attempt && !receiptButton.disabled) receiptButton.focus();
    else if (!qr.disabled) (event.value ? qr : event).focus();
  }
  function stopCamera() {
    cameraRevision++;
    cameraStarting = false;
    cancelAnimationFrame(frameId);
    if (stream) stream.getTracks().forEach(track => track.stop());
    stream = null;
    video.pause();
    video.srcObject = null;
    video.hidden = true;
    stopButton.hidden = true;
    cameraButton.hidden = false;
  }
  async function refresh() {
    if (closed) return;
    const id = event.value;
    const request = ++generation;
    audit.replaceChildren();
    if (!id) {
      summary.textContent = 'Elige un evento para consultar la asistencia.';
      return;
    }
    summary.textContent = 'Consultando asistencia…';
    try {
      const response = await fetch('/discadmin/ticketing-door.php?summary=1&event_id=' + encodeURIComponent(id), {
        credentials: 'same-origin', cache: 'no-store'
      });
      if (!response.ok) throw new Error('summary');
      const data = await response.json();
      if (closed || !data.ok || request !== generation || event.value !== id) return;
      summary.textContent = 'Ingresos confirmados: ' + Number(data.summary.accepted || 0)
        + ' · Rechazos: ' + Number(data.summary.rejected || 0);
      for (const item of data.recent || []) {
        const p = document.createElement('p');
        p.textContent = String(item.at) + ' · Operador ' + Number(item.operator)
          + ' · Dispositivo ' + String(item.device) + ' · '
          + (item.outcome === 'accepted' ? 'Ingreso confirmado' : 'Rechazado: ' + String(item.reason));
        audit.appendChild(p);
      }
    } catch {
      if (!closed && request === generation) summary.textContent = 'Asistencia no disponible. No asumir conteos actuales.';
    }
  }
  async function submit(token) {
    if (pending || closed || attempt || storageFault) return;
    if (!Number.isSafeInteger(Number(event.value)) || Number(event.value) < 1 || !token || token.length > 256) {
      feedback('deny', 'Selecciona un evento y un código QR válido.');
      return;
    }
    pending = true;
    const selectedEvent = event.value;
    const selectedRevision = eventRevision;
    stopCamera();
    qr.value = '';
    try {
      const current = saveAttempt(selectedEvent);
      syncControls();
      feedback('pending', 'Validando con el servidor. Aún no se permite el acceso.');
      const {status, data} = await post({...current, qr: token});
      if (closed) return;
      const accepted = status === 200 && data?.ok === true && data.reason === 'accepted';
      const rejected = status === 409 && data?.ok === false && Object.hasOwn(messages, data.reason);
      if (!accepted && !rejected) {
        unknown(status === 403 ? 'Sin permiso para confirmar el intento. NO permitir acceso.'
          : 'Estado desconocido: NO permitir acceso.');
        return;
      }
      clearAttempt(current);
      if (eventRevision !== selectedRevision || event.value !== selectedEvent) {
        feedback('deny', 'Respuesta de un intento anterior (evento ' + current.event_id
          + '). NO permitir acceso.');
      } else if (accepted) {
        feedback('allow', 'ENTRADA VÁLIDA · Acceso confirmado por el servidor.');
      } else {
        feedback('deny', messages[data.reason]);
      }
    } catch {
      if (!closed) {
        if (!attempt) storageFault = true;
        unknown('Error de red, servidor o almacenamiento. Estado desconocido: NO permitir acceso.');
      }
    } finally { finishRequest(); }
  }
  async function lookupReceipt() {
    if (pending || closed || storageFault || !attempt) return;
    pending = true;
    const current = attempt;
    syncControls();
    feedback('pending', 'Consultando recibo histórico. NO permitir acceso.');
    try {
      const {status, data} = await post({...current, action: 'receipt'});
      if (closed) return;
      const history = data?.receipt;
      if (status === 200 && data?.ok === false && history
          && ((history.outcome === 'accepted' && history.reason === 'accepted')
            || (history.outcome === 'rejected' && Object.hasOwn(messages, history.reason)))) {
        clearAttempt(current);
        feedback('deny', 'Recibo histórico · Evento ' + current.event_id + ': '
          + (history.outcome === 'accepted' ? 'ingreso registrado anteriormente. NO permitir un nuevo acceso.'
            : messages[history.reason]));
      } else {
        unknown(status === 403 ? 'Sin permiso para consultar el recibo. NO permitir acceso.'
          : status === 404 ? 'No se encontró un recibo confirmado. Estado desconocido: NO permitir acceso.'
          : 'No se pudo confirmar el recibo. Estado desconocido: NO permitir acceso.');
      }
    } catch {
      if (!closed) unknown();
    } finally { finishRequest(); }
  }
  receiptButton.addEventListener('click', () => { void lookupReceipt(); });
  form.addEventListener('submit', e => {
    e.preventDefault();
    void submit(qr.value.trim());
  });
  event.addEventListener('change', () => {
    if (closed) return;
    eventRevision++;
    stopCamera();
    if (attempt || storageFault) unknown();
    else feedback('idle', 'Esperando confirmación del servidor.');
    syncControls();
    void refresh();
  });
  cameraButton.addEventListener('click', async () => {
    if (closed || cameraStarting || stream || pending || attempt || storageFault) return;
    if (!event.value) { feedback('deny', 'Selecciona primero un evento.'); return; }
    if (!navigator.mediaDevices?.getUserMedia || !window.BarcodeDetector) {
      cameraStatus.textContent = 'Cámara no compatible. Usa la entrada manual.';
      qr.focus(); return;
    }
    const session = ++cameraRevision;
    cameraStarting = true;
    cameraButton.hidden = true;
    stopButton.hidden = false;
    let acquired = null;
    try {
      const detector = new BarcodeDetector({formats: ['qr_code']});
      acquired = await navigator.mediaDevices.getUserMedia({audio: false, video: {facingMode: {ideal: 'environment'}}});
      if (closed || session !== cameraRevision) return;
      stream = acquired;
      acquired = null;
      video.srcObject = stream;
      video.hidden = false;
      await video.play();
      if (closed || session !== cameraRevision || !stream) return;
      cameraStarting = false;
      cameraStatus.textContent = 'Cámara activa. Apunta al QR o detén para escribirlo.';
      const scan = async () => {
        if (closed || session !== cameraRevision || !stream || pending) return;
        try {
          const codes = await detector.detect(video);
          if (closed || session !== cameraRevision || !stream || pending) return;
          if (codes.length && typeof codes[0].rawValue === 'string') {
            const value = codes[0].rawValue;
            stopCamera();
            await submit(value);
            return;
          }
        } catch {
          if (!closed && session === cameraRevision) {
            cameraStatus.textContent = 'No se pudo leer. Puedes usar el código manual.';
          }
        }
        if (!closed && session === cameraRevision && stream && !pending) frameId = requestAnimationFrame(scan);
      };
      frameId = requestAnimationFrame(scan);
    } catch {
      if (!closed && session === cameraRevision) {
        stopCamera();
        cameraStatus.textContent = 'Permiso de cámara denegado o cámara no disponible. Usa la entrada manual.';
        qr.focus();
      }
    } finally {
      if (acquired) acquired.getTracks().forEach(track => track.stop());
      if (session === cameraRevision) cameraStarting = false;
    }
  });
  stopButton.addEventListener('click', stopCamera);
  function onPageHide() {
    eventRevision++;
    stopCamera();
    if (attempt) unknown();
    else feedback('idle', 'Esperando confirmación del servidor.');
    syncControls();
  }
  window.addEventListener('pagehide', onPageHide);
  restoreAttempt();
  if (attempt) void refresh();
  return () => {
    if (closed) return;
    closed = true;
    eventRevision++;
    generation++;
    stopCamera();
    window.removeEventListener('pagehide', onPageHide);
    receiptButton.remove();
    delete root.dataset.doorMounted;
  };
}
