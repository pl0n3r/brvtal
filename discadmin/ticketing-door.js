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
  let device = '';
  try {
    device = sessionStorage.getItem(deviceKey) || '';
    if (!/^door-[a-f0-9-]{36}$/.test(device)) {
      device = 'door-' + crypto.randomUUID();
      sessionStorage.setItem(deviceKey, device);
    }
  } catch { device = 'door-' + crypto.randomUUID(); }
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
    if (pending || closed) return;
    if (!event.value || !token || token.length > 256) {
      feedback('deny', 'Selecciona un evento y un código QR válido.');
      return;
    }
    pending = true;
    const selectedEvent = event.value;
    const selectedRevision = eventRevision;
    stopCamera();
    feedback('pending', 'Validando con el servidor. Aún no se permite el acceso.');
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch('/api/ticketing/checkin.php', {
        method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf},
        body: JSON.stringify({event_id: Number(selectedEvent), device_id: device, qr: token})
      });
      const data = await response.json();
      if (closed || eventRevision !== selectedRevision || event.value !== selectedEvent) {
        feedback('deny', 'Se cambió el evento durante la validación. NO permitir acceso.');
      } else if (response.status === 200 && data.ok === true && data.reason === 'accepted') {
        feedback('allow', 'ENTRADA VÁLIDA · Acceso confirmado por el servidor.');
      } else {
        feedback('deny', messages[data.reason] || 'Acceso no autorizado. Verifica la entrada.');
      }
    } catch {
      feedback('deny', 'Error de red o servidor. Estado desconocido: NO permitir acceso.');
    } finally {
      clearTimeout(timer);
      qr.value = '';
      pending = false;
      await refresh();
      qr.focus();
    }
  }
  form.addEventListener('submit', e => {
    e.preventDefault();
    void submit(qr.value.trim());
  });
  event.addEventListener('change', () => {
    eventRevision++;
    stopCamera();
    feedback('idle', 'Esperando confirmación del servidor.');
    void refresh();
  });
  cameraButton.addEventListener('click', async () => {
    if (closed || cameraStarting || stream || pending) return;
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
  window.addEventListener('pagehide', stopCamera);
  return () => {
    if (closed) return;
    closed = true;
    eventRevision++;
    generation++;
    stopCamera();
    window.removeEventListener('pagehide', stopCamera);
    delete root.dataset.doorMounted;
  };
}
