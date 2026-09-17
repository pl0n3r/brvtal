<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';

if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') {
    header('Location: /discadmin/?module=releases');
    exit;
}

brvtal_admin_require();
?>
<section data-admin-module="releases" class="brvtal-releases">
  <div class="releases-hero">
    <div>
      <div class="eyebrow">LABEL / CATALOG</div>
      <h2>RELEASES</h2>
      <p>Singles, EPs, albums and compilations published by BRVTAL.</p>
    </div>
    <button class="btn red" id="release-new" type="button">+ NEW RELEASE</button>
  </div>

  <div class="releases-metrics" aria-label="Release summary">
    <div><span>TOTAL</span><b id="release-total">0</b></div>
    <div><span>PUBLISHED</span><b id="release-published">0</b></div>
    <div><span>DRAFTS</span><b id="release-drafts">0</b></div>
    <div><span>FEATURED</span><b id="release-featured">0</b></div>
  </div>

  <div class="toolbar releases-toolbar">
    <input class="search" id="release-search" aria-label="Search releases" placeholder="Search title, catalog or artist…" autocomplete="off">
    <select id="release-status-filter" aria-label="Filter releases by status">
      <option value="">ALL STATUS</option>
      <option value="published">PUBLISHED</option>
      <option value="draft">DRAFT</option>
      <option value="archived">ARCHIVED</option>
    </select>
  </div>

  <div id="release-status" class="releases-status" aria-live="polite"></div>
  <div id="release-grid" class="releases-grid"></div>
</section>
