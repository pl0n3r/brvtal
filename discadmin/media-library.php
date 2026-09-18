<?php
declare(strict_types=1);

if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') {
    header('Location: /discadmin/?module=media', true, 302);
    exit;
}
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/admin_auth.php';
brvtal_admin_require();
?>
<section data-admin-module="media" class="brvtal-media-library">
  <div class="media-toolbar">
    <div class="media-toolbar-main">
      <label class="admin-sr-only" for="media-search">Search media library</label>
      <input id="media-search" class="search" type="search" placeholder="Search media…" autocomplete="off">
      <select id="media-type-filter" class="media-select" aria-label="Filter media type">
        <option value="">ALL TYPES</option>
        <option value="image">IMAGES</option>
        <option value="video">VIDEO</option>
        <option value="audio">AUDIO</option>
        <option value="document">DOCUMENTS</option>
      </select>
      <select id="media-month-filter" class="media-select" aria-label="Filter upload month">
        <option value="">ALL DATES</option>
      </select>
    </div>
    <div class="media-toolbar-actions">
      <button id="media-register" class="btn ghost" type="button">REGISTER EXTERNAL</button>
      <button id="media-upload" class="btn red" type="button">+ UPLOAD MEDIA</button>
      <label class="admin-sr-only" for="media-file">Choose media file to upload</label>
      <input id="media-file" type="file" hidden accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,audio/mpeg,audio/wav,application/pdf">
    </div>
  </div>

  <button id="media-dropzone" class="media-dropzone" type="button">
    <strong>DROP FILES HERE</strong>
    <span>Originals are preserved. Image variants are generated when GD/WebP is available.</span>
  </button>

  <output id="media-status" class="media-status" aria-live="polite"></output>

  <div class="media-layout">
    <div>
      <div id="media-summary" class="media-summary"></div>
      <div id="media-grid" class="media-grid" aria-live="polite"></div>
    </div>
    <aside id="media-inspector" class="media-inspector" aria-label="Media details">
      <div class="media-inspector-empty">SELECT AN ASSET</div>
    </aside>
  </div>
</section>
