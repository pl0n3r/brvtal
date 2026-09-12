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
<div class="tabs"><button class="tab active" data-tab="events">EVENT EDITOR</button><button class="tab" data-tab="roster">COLLECTIVE ROSTER</button></div>
<section id="eventsTab" class="panel"><div class="toolbar"><input id="eventSearch" class="search" placeholder="Search events…"><div class="head-actions"><button class="btn" onclick="BRVTALContentCore.loadEvents()">REFRESH</button><button class="btn green" onclick="BRVTALContentCore.openEvent()">+ NEW EVENT</button></div></div><div id="eventsTable" class="table"></div></section>
<section id="rosterTab" class="panel" style="display:none"><div class="toolbar"><input id="artistSearch" class="search" placeholder="Search artists…"><button class="btn" onclick="BRVTALContentCore.loadArtists()">REFRESH</button></div><div class="roster"><div class="box"><h3>COLLECTIVE ROSTER</h3><div class="helper">Manage active BRVTAL DJs and alumni without changing the artist identity itself.</div><div id="rosterList" class="artist-list" style="margin-top:12px"></div></div><div class="box"><h3>ARTIST LIFECYCLE</h3><div id="artistDetail" class="helper">Select an artist to edit collective status and order.</div></div></div></section>
</div>
<div id="eventModal" class="modal"><div class="modalbox"><div class="modalhead"><div><div class="ey">CONTENT CORE / EVENT</div><h2 id="eventHeading">NEW EVENT</h2></div><div class="modal-actions" style="display:flex;gap:8px;align-items:center"><button id="cc-top-saveBtn" type="button" class="btn red" onclick="BRVTALContentCore.saveEvent()">SAVE</button><button class="icon" type="button" onclick="BRVTALContentCore.closeEvent()">CLOSE</button></div></div><div id="eventNotice" class="notice"></div><div class="wizard"><div class="steps"><div role="button" tabindex="0" class="step active" data-step="1">01 · IDENTITY</div><div role="button" tabindex="0" class="step" data-step="2">02 · DATE & PLACE</div><div role="button" tabindex="0" class="step" data-step="3">03 · LIFECYCLE</div><div role="button" tabindex="0" class="step" data-step="4">04 · TICKETS</div><div role="button" tabindex="0" class="step" data-step="5">05 · ROSTER</div></div><div class="wizard-main">
<form id="eventForm" onsubmit="return false">
<div class="step-content active" data-content="1"><div class="section"><div class="sectionhead"><strong>EVENT IDENTITY</strong><span class="helper">Save incomplete content at any time.</span></div><div class="form"><div class="field"><label>Name *</label><input id="e_title"></div><div class="field"><label>Slug</label><input id="e_slug" placeholder="Generated automatically"></div><div class="field full"><label>Description</label><textarea id="e_description"></textarea></div><div class="field"><label>Cover image</label><input id="e_cover_image" placeholder="Media path / URL"></div><div class="field"><label>Accent</label><input id="e_accent" placeholder="#ff2038"></div><div class="field"><label>Featured</label><select id="e_featured"><option value="0">No</option><option value="1">Yes</option></select></div></div></div></div>
<div class="step-content" data-content="2"><div class="section"><div class="sectionhead"><strong>DATE & PLACE</strong></div><div class="form"><div class="field"><label>Date & time *</label><input id="e_event_date" type="datetime-local"></div><div class="field"><label>City *</label><input id="e_city"></div><div class="field"><label>Venue</label><input id="e_venue"></div><div class="field"><label>Archive year</label><input id="e_archive_year" type="number" min="2000" max="2200"></div></div></div></div>
<div class="step-content" data-content="3"><div class="section"><div class="sectionhead"><strong>LIFECYCLE</strong></div><div class="form"><div class="field"><label>Status</label><select id="e_status"><option>draft</option><option>published</option><option>upcoming</option><option>tickets_available</option><option>last_tickets</option><option>sold_out</option><option>cancelled</option><option>finished</option><option>archived</option></select></div><div class="field"><label>Ticket instructions</label><textarea id="e_ticket_instructions"></textarea></div><div class="field"><label>Ticket QR</label><input id="e_ticket_qr"></div><div class="field"><label>External ticket URL</label><input id="e_ticket_url"></div></div></div></div>
<div class="step-content" data-content="4"><div class="section"><div class="sectionhead"><strong>TICKET TYPES</strong><button type="button" class="btn" onclick="BRVTALContentCore.addTicket()">+ ADD TICKET</button></div><div class="helper" style="margin-bottom:12px">No purchaser or attendee data is stored. Each type can point to an external payment/ticket destination.</div><div id="tickets"></div></div></div>
<div class="step-content" data-content="5"><div class="section"><div class="sectionhead"><strong>BRVTAL COLLECTIVE / EVENT PARTICIPATION</strong></div><div class="helper" style="margin-bottom:12px">This is the event roster. It does not redefine the artist's collective history.</div><div id="eventArtists" class="artist-list"></div></div></div>
</form><div class="foot"><button class="btn" onclick="BRVTALContentCore.closeEvent()">CANCEL</button><div class="foot-right"><button id="prevBtn" class="btn" onclick="BRVTALContentCore.step(-1)">← BACK</button><button id="nextBtn" class="btn" onclick="BRVTALContentCore.step(1)">NEXT →</button><button id="cc-saveBtn" class="btn red" onclick="BRVTALContentCore.saveEvent()">SAVE DRAFT</button></div></div>
</div></div></div></div>




</section>
