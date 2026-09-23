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
<section data-admin-module="content-core">

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
        <button id="cc-top-saveBtn" type="button" class="btn red" onclick="BRVTALContentCore.saveEvent()">SAVE</button>
        <button class="icon" type="button" onclick="BRVTALContentCore.closeEvent()">CLOSE</button>
      </div>
    </div>
    <div id="eventNotice" class="notice"></div>
    <div class="wizard">
      <div class="steps">
        <div class="step active" data-step="1">01 · IDENTITY</div>
        <div class="step" data-step="2">02 · DATE & PLACE</div>
        <div class="step" data-step="3">03 · LIFECYCLE</div>
        <div class="step" data-step="4">04 · TICKETS</div>
        <div class="step" data-step="5">05 · ROSTER</div>
      </div>
      <div class="wizard-main">
<form id="eventForm" onsubmit="return false">
<div class="step-content active" data-content="1">
  <div class="section">
    <div class="sectionhead">
      <strong>EVENT IDENTITY</strong>
      <span class="helper">Save incomplete content at any time.</span>
    </div>
    <div class="form">
      <div class="field"><label for="e_title">Name *</label><input id="e_title"></div>
      <div class="field"><label for="e_slug">Slug</label><input id="e_slug" placeholder="Generated automatically"></div>
      <div class="field full">
        <label for="e_description">Description</label>
        <textarea id="e_description"></textarea>
      </div>
      <div class="field">
        <label for="e_cover_image">Cover image</label>
        <input id="e_cover_image" placeholder="Media path / URL">
      </div>
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
      <div class="field">
        <label for="e_featured">Featured</label>
        <select id="e_featured"><option value="0">No</option><option value="1">Yes</option></select>
      </div>
    </div>
  </div>
</div>
<div class="step-content" data-content="2">
  <div class="section">
    <div class="sectionhead"><strong>DATE & PLACE</strong></div>
    <div class="form">
      <div class="field">
        <label for="e_event_date">Date & time *</label>
        <input id="e_event_date" type="datetime-local">
      </div>
      <div class="field"><label for="e_city">City *</label><input id="e_city"></div>
      <div class="field"><label for="e_venue">Venue</label><input id="e_venue"></div>
      <div class="field">
        <label for="e_archive_year">Archive year</label>
        <input id="e_archive_year" type="number" min="2000" max="2200">
      </div>
    </div>
  </div>
</div>
<div class="step-content" data-content="3">
  <div class="section">
    <div class="sectionhead"><strong>LIFECYCLE</strong></div>
    <div class="form">
      <div class="field">
        <label for="e_status">Status</label>
        <select id="e_status">
          <option>draft</option><option>published</option><option>upcoming</option>
          <option>tickets_available</option><option>last_tickets</option><option>sold_out</option>
          <option>cancelled</option><option>finished</option><option>archived</option>
        </select>
      </div>
      <div class="field">
        <label for="e_ticket_instructions">Ticket instructions</label>
        <textarea id="e_ticket_instructions"></textarea>
      </div>
      <div class="field"><label for="e_ticket_qr">Ticket QR</label><input id="e_ticket_qr"></div>
      <div class="field">
        <label for="e_ticket_url">External ticket URL</label>
        <input id="e_ticket_url">
      </div>
    </div>
  </div>
</div>
<div class="step-content" data-content="4">
  <div class="section">
    <div class="sectionhead">
      <strong>TICKET TYPES</strong>
      <button type="button" class="btn" onclick="BRVTALContentCore.addTicket()">+ ADD TICKET</button>
    </div>
    <div class="helper" style="margin-bottom:12px">
      No purchaser or attendee data is stored. Manage price/currency, lifecycle, availability,
      payment details, QR and external destination for each type.
    </div>
    <div id="tickets"></div>
  </div>
</div>
<div class="step-content" data-content="5"><div class="section"><div class="sectionhead"><strong>ARTISTS / EVENT PARTICIPATION</strong></div><div class="helper" style="margin-bottom:12px">Current BRVTAL members are listed first. Existing external participants stay available so event history is never lost.</div><div id="eventArtists" class="artist-list"></div></div></div>
</form><div class="foot"><button class="btn" onclick="BRVTALContentCore.closeEvent()">CANCEL</button><div class="foot-right"><button id="prevBtn" class="btn" onclick="BRVTALContentCore.step(-1)">← BACK</button><button id="nextBtn" class="btn" onclick="BRVTALContentCore.step(1)">NEXT →</button><button id="cc-saveBtn" class="btn red" onclick="BRVTALContentCore.saveEvent()">SAVE DRAFT</button></div></div>
</div></div></div></div>




</section>
