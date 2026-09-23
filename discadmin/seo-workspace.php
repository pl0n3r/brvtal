<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';

if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') {
    header('Location: /discadmin/?module=seo', true, 302);
    exit;
}

brvtal_admin_require();
?>
<section data-admin-module="seo" class="brvtal-seo-workspace">
  <header class="seo-workspace-hero">
    <div>
      <div class="eyebrow">WEBSITE / SEARCH DISCOVERY</div>
      <h2>SEO</h2>
      <p>One inventory for every public destination. Automatic fallbacks stay live; manual overrides remain explicit and reversible.</p>
    </div>
  </header>

  <div class="seo-workspace-metrics" aria-label="SEO workspace summary">
    <article><span>TOTAL</span><b id="seo-workspace-total">0</b></article>
    <article><span>AUTO</span><b id="seo-workspace-auto">0</b></article>
    <article><span>MANUAL / MIXED</span><b id="seo-workspace-manual">0</b></article>
    <article><span>NEEDS ATTENTION</span><b id="seo-workspace-issues">0</b></article>
  </div>

  <div class="seo-workspace-toolbar">
    <label>
      <span class="admin-sr-only">Search SEO destinations</span>
      <input id="seo-workspace-search" class="search" type="search" placeholder="Search title, route or type…" autocomplete="off">
    </label>
    <select id="seo-workspace-type" aria-label="Filter by content type">
      <option value="">ALL TYPES</option>
      <option value="HOME">HOME</option>
      <option value="CONTACT">CONTACT</option>
      <option value="PAGE">PAGE</option>
      <option value="EVENT">EVENT</option>
      <option value="ARTIST">ARTIST</option>
      <option value="SET">SET</option>
      <option value="RELEASE">RELEASE</option>
      <option value="BLOG">BLOG</option>
    </select>
    <select id="seo-workspace-status-filter" aria-label="Filter by publication state">
      <option value="">ALL STATES</option>
      <option value="public">PUBLIC</option>
      <option value="private">DRAFT / PRIVATE</option>
    </select>
    <select id="seo-workspace-mode" aria-label="Filter by SEO mode">
      <option value="">ALL MODES</option>
      <option value="AUTO">AUTO</option>
      <option value="MANUAL">MANUAL</option>
      <option value="MIXED">MIXED</option>
    </select>
    <select id="seo-workspace-health" aria-label="Filter by SEO health">
      <option value="">ALL HEALTH</option>
      <option value="issues">NEEDS ATTENTION</option>
      <option value="ok">NO WARNINGS</option>
    </select>
  </div>

  <output id="seo-workspace-status" class="seo-workspace-status" aria-live="polite"></output>
  <div id="seo-workspace-inventory" class="seo-workspace-inventory" aria-live="polite"></div>

  <div id="seo-workspace-editor" class="seo-editor-overlay" role="dialog" aria-modal="true" aria-labelledby="seo-editor-title" hidden>
    <div class="seo-editor">
      <header class="seo-editor-head">
        <div><span id="seo-editor-kicker">SEO / DESTINATION</span><h3 id="seo-editor-title">SEARCH METADATA</h3></div>
        <button type="button" class="seo-editor-close" data-seo-editor-close aria-label="Close SEO editor">×</button>
      </header>
      <form id="seo-workspace-form">
        <div class="seo-editor-context">
          <div><span>CANONICAL</span><code id="seo-editor-canonical"></code></div>
          <div><span>STATE</span><strong id="seo-editor-state"></strong></div>
          <div><span>MODE</span><strong id="seo-editor-mode"></strong></div>
        </div>

        <label class="seo-editor-field">
          <span>SEO TITLE <small id="seo-editor-title-source"></small></span>
          <div class="seo-editor-input-row">
            <input id="seo-editor-title-input" maxlength="190" autocomplete="off">
            <button type="button" class="btn ghost" data-seo-reset="title">RESET TO AUTO</button>
          </div>
          <small id="seo-editor-title-count"></small>
        </label>

        <label class="seo-editor-field">
          <span>META DESCRIPTION <small id="seo-editor-description-source"></small></span>
          <textarea id="seo-editor-description-input" maxlength="320"></textarea>
          <div class="seo-editor-field-foot">
            <small id="seo-editor-description-count"></small>
            <button type="button" class="btn ghost" data-seo-reset="description">RESET TO AUTO</button>
          </div>
        </label>

        <label class="seo-editor-field" id="seo-editor-image-field" hidden>
          <span>DEFAULT SOCIAL IMAGE <small>AUTO uses the route default</small></span>
          <div class="seo-editor-input-row">
            <input id="seo-editor-image-input" maxlength="700" placeholder="/uploads/... or https://...">
            <button type="button" class="btn ghost" data-seo-reset="image">RESET TO AUTO</button>
          </div>
        </label>

        <section class="seo-editor-preview" aria-label="Effective search preview">
          <div class="seo-editor-preview-label">SERVER-RENDERED PREVIEW</div>
          <code id="seo-editor-preview-url"></code>
          <strong id="seo-editor-preview-title"></strong>
          <p id="seo-editor-preview-description"></p>
          <div class="seo-editor-preview-image"><img id="seo-editor-preview-image" alt="" hidden><span id="seo-editor-preview-image-label"></span></div>
        </section>

        <div id="seo-editor-warnings" class="seo-editor-warnings" aria-live="polite"></div>
        <p class="seo-editor-authority">Open Graph and Twitter/X inherit the same effective title, description and image from the server renderer. Canonical URLs are derived and are not editable here.</p>

        <div class="seo-editor-actions">
          <button type="button" class="btn ghost" data-seo-editor-close>CANCEL</button>
          <button type="submit" class="btn red" id="seo-editor-save">SAVE SEO</button>
        </div>
      </form>
    </div>
  </div>
</section>
