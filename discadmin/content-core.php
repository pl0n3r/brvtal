<?php
declare(strict_types=1);
if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') {
    header('Location: /discadmin/?module=content-core', true, 302);
    exit;
}
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/admin_auth.php';
brvtal_admin_require();
?>
<section data-admin-module="content-core"
  data-datetime-picker-script="/discadmin/admin-datetime-picker.js"
  data-datetime-picker-style="/discadmin/admin-datetime-picker.css">

<div class="wrap">

<div id="cc-notice" class="notice"></div>
<section id="eventsTab" class="panel">
  <div class="toolbar">
    <label class="admin-sr-only" for="eventSearch">Search events</label>
    <input id="eventSearch" class="search" placeholder="Search events…">
    <div class="head-actions">
      <button class="btn" onclick="BRVTALContentCore.loadEvents()">REFRESH</button>
      <button class="btn green" onclick="BRVTALContentCore.openEvent()">+ NEW EVENT</button>
    </div>
  </div>
  <div id="eventsTable" class="table"></div>
</section>
</div>
<div id="eventModal" class="modal">
  <div class="modalbox">
    <div class="modalhead">
      <div>
        <div class="ey">CONTENT CORE / EVENT</div>
        <h2 id="eventHeading">NEW EVENT</h2>
      </div>
      <div class="modal-actions" style="display:flex;gap:8px;align-items:center">
        <button
          id="cc-previewBtn"
          type="button"
          class="btn"
          onclick="BRVTALContentCore.previewEvent()"
        >PUBLIC PREVIEW</button>
        <button
          id="cc-historyBtn"
          type="button"
          class="btn"
          hidden
          disabled
          onclick="BRVTALContentCore.openEventHistory()"
        >HISTORY</button>
        <button id="cc-top-saveBtn" type="button" class="btn red" onclick="BRVTALContentCore.saveEvent()">SAVE</button>
        <button class="icon" type="button" onclick="BRVTALContentCore.closeEvent()">CLOSE</button>
      </div>
    </div>
    <div id="eventNotice" class="notice"></div>
    <div class="wizard event-sales-wizard">
      <div class="steps" aria-label="Pasos del editor">
        <button type="button" class="step active" data-step="1" onclick="BRVTALContentCore.jumpStep(1)">01 · Evento</button>
        <button type="button" class="step" data-step="2" onclick="BRVTALContentCore.jumpStep(2)">02 · Boletas</button>
        <button type="button" class="step" data-step="3" onclick="BRVTALContentCore.jumpStep(3)">03 · Lineup y horarios</button>
        <button type="button" class="step" data-step="4" onclick="BRVTALContentCore.jumpStep(4)">04 · Promoción</button>
        <button type="button" class="step" data-step="5" onclick="BRVTALContentCore.jumpStep(5)">05 · Publicar</button>
      </div>
      <div class="wizard-main">
<form id="eventForm" onsubmit="return false">
<div class="step-content active" data-content="1">
  <div class="section">
    <div class="sectionhead"><strong>Tu evento</strong><span class="helper">Primero lo esencial para que la gente llegue.</span></div>
    <div class="form">
      <div class="field"><label for="e_title">Nombre *</label><input id="e_title" maxlength="180"></div>
      <div class="field"><label for="e_event_date">Fecha y hora *</label><input id="e_event_date" type="datetime-local"></div>
      <div class="field"><label for="e_city">Ciudad *</label><input id="e_city"></div>
      <div class="field"><label for="e_venue">Lugar</label><input id="e_venue"></div>
      <div class="field"><label for="e_venue_address">Dirección para el mapa (opcional)</label><input id="e_venue_address" maxlength="300" placeholder="Calle, número, ciudad" aria-describedby="e_venue_address_help"><small id="e_venue_address_help">El mapa solo se carga si la persona pulsa Ver mapa.</small><a id="e_venue_map_preview" href="#" target="_blank" rel="noopener noreferrer" hidden>Ver en Google Maps ↗</a></div>
      <div class="field"><label for="e_cover_image">Afiche principal</label><input id="e_cover_image" placeholder="/uploads/…"></div>
    </div>
    <details class="event-advanced"><summary>Opciones avanzadas</summary><div class="form">
      <div class="field"><label for="e_slug">Slug (URL)</label><input id="e_slug" placeholder="Se genera automáticamente"></div>
      <div class="field" data-admin-color-field data-color-default="#ff2038">
        <label for="e_accent">Accent</label>
        <div class="admin-color-field__controls">
          <input
            id="e_accent"
            data-color-hex
            placeholder="#ff2038"
            maxlength="7"
            pattern="#[0-9A-Fa-f]{6}"
            inputmode="text"
            autocomplete="off"
            spellcheck="false"
            aria-describedby="e_accent_help"
            aria-errormessage="e_accent_error"
          >
          <input
            id="e_accent_picker"
            class="admin-color-field__picker"
            data-color-picker
            type="color"
            value="#ff2038"
            aria-label="Choose Event Accent visually"
          >
        </div>
        <div class="admin-color-field__meta">
          <span class="admin-color-field__swatch" data-color-swatch aria-hidden="true"></span>
          <output class="admin-color-field__value" data-color-value for="e_accent e_accent_picker">No accent</output>
        </div>
        <div id="e_accent_help" class="helper">Choose visually or enter an exact HEX value (#RRGGBB).</div>
        <div
          id="e_accent_error"
          class="admin-color-field__error"
          data-color-error
          hidden
        >Use a 6-digit HEX value such as #ff2038.</div>
      </div>

      <div class="field"><label for="e_archive_year">Año de archivo</label><input id="e_archive_year" type="number" min="2000" max="2200"></div>
    </div></details>
  </div>
</div>
<div class="step-content" data-content="2">
  <div class="section">
    <div class="sectionhead"><strong>Boletas</strong><button type="button" class="btn" onclick="BRVTALContentCore.addTicket()">+ AÑADIR BOLETA</button></div>
    <p class="helper">Precio, estado, compra, QR y ventanas de venta para cada boleta. No almacenamos información de compradores aquí.</p>
    <div id="tickets"></div>
    <div id="ticketInventory" class="ticket-inventory-host"></div>
    <details class="event-legacy-tickets"><summary>Compra general y datos heredados (si no hay tipos)</summary><div class="form">
      <div class="field"><label for="e_ticket_url">Enlace general de compra</label><input id="e_ticket_url" placeholder="https://…"></div>
      <div class="field"><label for="e_ticket_instructions">Instrucciones de compra</label><textarea id="e_ticket_instructions"></textarea></div>
      <div class="field"><label for="e_ticket_qr">Código QR de compra</label><input id="e_ticket_qr"></div>
    </div></details>
    <div class="event-derived"><span>Venta (calculada)</span><output id="e_sales_state" aria-live="polite">Sin boletas configuradas</output></div>
  </div>
</div>
<div class="step-content" data-content="3">
  <div class="section">
    <div class="sectionhead"><strong>Lineup</strong></div>
    <p class="helper">Artistas del catálogo, invitados y participación existente.</p>
    <div id="eventArtists" class="artist-list"></div>
  </div>
  <div class="section">
    <div class="sectionhead"><strong>Horarios de la noche</strong><button type="button" class="btn" onclick="BRVTALContentCore.addTimetableRow()">+ AÑADIR FRANJA</button></div>
    <p class="helper">Solo horas (por ejemplo, 9:00 pm). La fecha sale del evento; cruzar medianoche se calcula automáticamente en Bogotá.</p>
    <div id="eventTimetable"></div>
  </div>
</div>
<div class="step-content" data-content="4">
  <div class="section">
    <div class="sectionhead"><strong>Promoción y compartir</strong></div>
    <div class="form">
      <div class="field full"><label for="e_description">Descripción para vender el evento</label><textarea id="e_description" maxlength="65535" aria-describedby="e_description_count e_description_help"></textarea><small id="e_description_help">Cuenta por qué vale la pena asistir, sin inventar precios ni disponibilidad.</small><output id="e_description_count" aria-live="polite">0 caracteres</output></div>
      <div class="field"><label for="e_share_image">Imagen para compartir</label><input id="e_share_image" maxlength="500" placeholder="Se usa el afiche principal"><small>Sin nuevo campo de datos: editarla también actualiza el afiche principal, y se guarda en el evento.</small></div>
      <div class="field"><label for="e_featured">Destacar en el inicio</label><select id="e_featured"><option value="0">No</option><option value="1">Sí</option></select></div>
    </div>
    <div id="e_seo_slot" aria-label="SEO y vista previa de buscadores"></div>
    <div class="event-share-preview" aria-live="polite">
      <strong>Vista previa para compartir</strong>
      <div class="event-share-card"><img id="e_share_preview_image" alt="" hidden><div><b id="e_share_preview_title">Tu evento</b><p id="e_share_preview_description">La descripción aparecerá aquí.</p><small id="e_share_preview_url"></small></div></div>
      <div class="event-share-links">
        <button type="button" id="e_share_copy">Copiar enlace público</button>
        <a id="e_share_whatsapp" target="_blank" rel="noopener noreferrer">WhatsApp</a>
        <a id="e_share_instagram" target="_blank" rel="noopener noreferrer">Instagram (enlace)</a>
        <a id="e_share_qr" target="_blank" rel="noopener noreferrer">QR (enlace)</a>
      </div>
      <output id="e_share_feedback" role="status" aria-live="polite"></output>
    </div>
  </div>
  <details class="event-insights-details"><summary>Métricas del evento (solo lectura)</summary><div class="section" data-event-insights-panel data-state="idle">
    <div class="sectionhead">
      <strong>EVENT INSIGHTS</strong>
      <span class="pill">READ ONLY</span>
    </div>
    <div class="helper" style="margin-bottom:12px">
      Aggregate Event metrics only. Draft/private Events never trigger analytics requests.
    </div>
    <div class="form">
      <div class="field">
        <label for="eventInsightsWindow">Window</label>
        <select id="eventInsightsWindow">
          <option value="7d">LAST 7 DAYS</option>
        </select>
      </div>
      <div class="field">
        <span class="helper">STATUS</span>
        <output
          id="eventInsightsStatus"
          aria-live="polite"
          aria-label="Event Insights status"
          data-state="idle"
        >NOT PUBLISHED · No analytics request was sent.</output>
      </div>
      <div class="field">
        <span class="helper">USERS</span>
        <output id="eventInsightsUsers" aria-label="Event Insights users">—</output>
        <div id="eventInsightsUsersPrevious" class="helper">PREVIOUS: —</div>
      </div>
      <div class="field">
        <span class="helper">SESSIONS</span>
        <output id="eventInsightsSessions" aria-label="Event Insights sessions">—</output>
        <div id="eventInsightsSessionsPrevious" class="helper">PREVIOUS: —</div>
      </div>
      <div class="field">
        <span class="helper">VIEWS</span>
        <output id="eventInsightsViews" aria-label="Event Insights views">—</output>
        <div id="eventInsightsViewsPrevious" class="helper">PREVIOUS: —</div>
      </div>
    </div>
  </div>
</details>
</div>
<div class="step-content" data-content="5">
  <div class="section">
    <div class="sectionhead"><strong>Publicar el evento</strong></div>
    <div class="event-publish-checklist" id="e_publish_checklist" role="status" aria-live="polite"></div>
    <div class="form">
      <div class="field"><label for="e_status">Publicación</label><select id="e_status" aria-describedby="e_status_help"><option value="draft">Borrador</option><option value="published">Publicado</option><option value="scheduled">Programado</option><option value="cancelled">Cancelado</option></select><small id="e_status_help">El estado de venta y la finalización se calculan por fecha y boletas.</small></div>
      <div class="field full">
        <label for="e_visibility">Visibilidad</label>
        <select id="e_visibility" aria-describedby="e_visibility_help">
          <option value="public">Público</option>
          <option value="unlisted">Privado (solo con enlace)</option>
        </select>
        <small id="e_visibility_help">Privado: no aparece en el listado público ni en buscadores.
          Cualquiera con el enlace puede verlo cuando esté publicado.</small>
      </div>
      <div class="field full" id="e_private_share" hidden>
        <label for="e_private_url">Enlace del evento</label>
        <input id="e_private_url" type="url" readonly aria-describedby="e_private_feedback">
        <button id="e_private_copy" type="button" class="btn" disabled>Copiar enlace</button>
        <output id="e_private_feedback" aria-live="polite">
          Guarda el evento privado para obtener su enlace.
        </output>
      </div>
      <div class="field" id="e_publish_schedule_field"><label for="e_publish_at">Publicar a partir de</label><input id="e_publish_at" type="datetime-local" aria-describedby="e_publish_at_help" disabled><small id="e_publish_at_help" role="status" aria-live="polite">Elige Programado para configurar la fecha.</small></div>
      <div class="event-derived"><span>Fecha (calculada)</span><output id="e_date_state" aria-live="polite">Por definir</output></div>
    </div>
  </div>
</div>
</form>
<div class="foot">
  <button class="btn" onclick="BRVTALContentCore.closeEvent()">CANCEL</button>
  <div class="foot-right">
    <button id="prevBtn" class="btn" onclick="BRVTALContentCore.step(-1)">← BACK</button>
    <button id="nextBtn" class="btn" onclick="BRVTALContentCore.step(1)">NEXT →</button>
    <button id="cc-saveBtn" class="btn red" onclick="BRVTALContentCore.saveEvent()">SAVE DRAFT</button>
  </div>
</div>
</div></div></div></div>




</section>
