<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/ticketing_staff_permissions.php';
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT']??'')!=='1') {http_response_code(404);exit;}
brvtal_admin_require();
try {
    $pdo=db();
    $stmt=$pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1');
    $stmt->execute([(int)($_SESSION['admin_id']??0)]);
    $role=(string)($stmt->fetchColumn()?:'');
    if (!brvtalTicketingCan($role,'entries')) {http_response_code(403);exit;}
} catch (Throwable) {http_response_code(503);exit;}
?>
<section data-ticketing-entries data-ticketing-csrf="<?= htmlspecialchars(brvtal_admin_csrf_token(),ENT_QUOTES,'UTF-8') ?>"
 data-ticketing-can-reissue="<?= brvtalTicketingCan($role,'reissue')?'1':'0' ?>"
 data-ticketing-can-void="<?= brvtalTicketingCan($role,'void')?'1':'0' ?>">
 <h2>Boletas individuales</h2>
 <p>Los QR originales no se recuperan desde un hash. Para volver a mostrar uno, presenta el QR existente.
 Reemitir invalida el anterior, sin crear otra venta ni modificar el pago Wompi.</p>
 <p role="status" data-entry-status aria-live="polite">Consultando boletas…</p>
 <div data-entry-list></div>
 <button type="button" data-entry-more hidden>Más boletas</button>
 <div data-entry-detail></div>
 <div data-entry-qr hidden>
   <p>QR visible solo durante esta sesión de trabajo. No se guarda en el navegador.</p>
   <div data-entry-qr-image></div>
   <button type="button" data-entry-download>Descargar imagen</button>
   <button type="button" data-entry-close>Cerrar QR</button>
 </div>
</section>
