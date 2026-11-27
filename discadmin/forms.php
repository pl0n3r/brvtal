<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_auth.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') {
    header('Location: /discadmin/?module=forms');
    exit;
}
brvtal_admin_require();
// Data and owner/superadmin authorization remain exclusively in admin-forms.php.
?>
<section class="forms-workspace" data-admin-module="forms" aria-label="Formularios">
  <p>Consulta de respuestas registradas. Solo lectura.</p>
  <output data-forms-status aria-live="polite"></output>
  <div data-forms-content></div>
</section>
