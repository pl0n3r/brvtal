<?php
declare(strict_types=1);

if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') {
    header('Location: /discadmin/?module=media&view=memories', true, 302);
    exit;
}
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/admin_auth.php';
brvtal_admin_require();
?>
<section data-admin-module="memories" class="memories-admin">
  <div class="memories-admin-head">
    <div>
      <div class="eyebrow">MEDIA / CURATION</div>
      <h2>MEMORIES</h2>
      <p>Curate the public archive from existing Media Library assets. Removing a Memory never deletes the source asset.</p>
    </div>
    <button type="button" class="btn red" data-memories-add>+ ADD MEMORY</button>
  </div>

  <div class="memories-admin-status" data-memories-status role="status" aria-live="polite"></div>
  <div class="memories-admin-grid" data-memories-grid aria-live="polite"></div>
  <div class="memories-admin-empty" data-memories-empty hidden>No curated Memories yet. Add one from Media Library.</div>
</section>
