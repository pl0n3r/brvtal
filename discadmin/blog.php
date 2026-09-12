<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';

if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') {
    header('Location: /discadmin/?module=blog');
    exit;
}

brvtal_admin_require();
?>
<section data-admin-module="blog" class="brvtal-blog">
  <div class="blog-hero">
    <div>
      <div class="eyebrow">EDITORIAL / JOURNAL</div>
      <h2>BLOG</h2>
      <p>Stories, announcements and editorial content from BRVTAL.</p>
    </div>
    <button class="btn red" id="blog-new" type="button">+ NEW POST</button>
  </div>

  <div class="blog-metrics" aria-label="Blog summary">
    <div><span>TOTAL</span><b id="blog-total">0</b></div>
    <div><span>PUBLISHED</span><b id="blog-published">0</b></div>
    <div><span>DRAFTS</span><b id="blog-drafts">0</b></div>
    <div><span>FEATURED</span><b id="blog-featured">0</b></div>
  </div>

  <div class="toolbar blog-toolbar">
    <input class="search" id="blog-search" placeholder="Search title, excerpt or slug…" autocomplete="off">
    <select id="blog-status-filter" aria-label="Filter posts by status">
      <option value="">ALL STATUS</option>
      <option value="published">PUBLISHED</option>
      <option value="draft">DRAFT</option>
      <option value="archived">ARCHIVED</option>
    </select>
  </div>

  <div id="blog-status" class="blog-status" aria-live="polite"></div>
  <div id="blog-grid" class="blog-grid"></div>
</section>
