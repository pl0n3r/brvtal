<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/ticketing_staff_permissions.php';
header('Cache-Control: no-store, private');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('X-Robots-Tag: noindex, nofollow');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); exit; }
brvtal_admin_require();
try {
    $stmt=db()->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1');
    $stmt->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    $role=(string)($stmt->fetchColumn() ?: '');
    if (!brvtalTicketingCan($role,'mail') || !brvtalTicketingCan($role,'buyers')) {
        http_response_code(403); exit;
    }
} catch (Throwable) { http_response_code(503); exit; }
?>
<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recuperación · Boletería</title></head>
<body>
<main data-recovery-root data-csrf="<?= htmlspecialchars(brvtal_admin_csrf_token(),ENT_QUOTES,'UTF-8') ?>">
  <h2>Recuperación de selección</h2>
  <p>Panel de simulación de marketing. No envía correos ni cobra. Consentimiento promocional separado del transaccional.</p>
  <form data-recovery-form>
    <label>Pedido sintético <input type="number" name="order_id" min="1" required></label>
    <label>Campaña <input name="campaign" value="checkout" maxlength="32" pattern="[a-z0-9_-]+" required></label>
    <button type="submit">Preparar simulación</button>
  </form>
  <div data-recovery-confirm hidden><p>Sin envíos, sin reservas ni pagos. ¿Registrar vista previa?</p>
    <button type="button" data-recovery-yes>Confirmar simulación</button>
    <button type="button" data-recovery-no>Cancelar</button>
  </div>
  <output data-recovery-status role="status" aria-live="polite">Consultando métricas sintéticas…</output>
  <h3>Resumen por evento y campaña</h3>
  <table><thead><tr><th>Evento</th><th>Campaña</th><th>Etapa</th><th>Vistas previas elegibles</th></tr></thead>
  <tbody data-recovery-rows></tbody></table>
</main>
<script type="module" src="/discadmin/ticketing-recovery.js"></script>
</body></html>
