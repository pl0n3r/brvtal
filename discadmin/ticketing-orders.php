<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/ticketing_staff_permissions.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') { http_response_code(404); exit; }
brvtal_admin_require();
try {
    $stmt = db()->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    $role = (string)($stmt->fetchColumn() ?: '');
    if (!brvtalTicketingCan($role, 'orders') || !brvtalTicketingCan($role, 'buyers')) {
        http_response_code(403); exit;
    }
} catch (Throwable) { http_response_code(503); exit; }
?>
<section data-ticketing-records data-ticketing-csrf="<?= htmlspecialchars(brvtal_admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <h2>Pedidos y compradores</h2>
  <p>Consulta privada. El estado «pagado» solo procede de la verificación del servidor. No se reembolsan ni emiten boletas aquí.</p>
  <form data-ticketing-records-form class="ticketing-records-form">
    <label>Vista <select name="kind">
      <option value="orders">Pedidos</option><option value="buyers">Compradores</option>
    </select></label>
    <label>Buscar pedido o correo <input type="search" name="q" maxlength="120" autocomplete="off"></label>
    <label>Correo exacto <input type="email" name="email" maxlength="120" autocomplete="off"></label>
    <label>Evento <input type="number" min="1" name="event_id" placeholder="Todos"></label>
    <label>Estado <select name="status"><option value="">Todos</option>
      <option value="draft">Borrador</option><option value="pending">Pendiente</option>
      <option value="paid">Pagado</option><option value="failed">Fallido</option>
      <option value="cancelled">Cancelado</option><option value="expired">Expirado</option>
      <option value="refunded">Reembolsado</option>
    </select></label>
    <label>Desde UTC <input type="date" name="from"></label>
    <label>Hasta UTC <input type="date" name="to"></label>
    <button type="submit">Consultar</button>
    <?php if (brvtalTicketingCan($role, 'export')): ?>
      <button type="button" data-ticketing-export>Exportar CSV de esta página</button>
    <?php endif; ?>
  </form>
  <p role="status" data-ticketing-records-status aria-live="polite">Consultando...</p>
  <div data-ticketing-records-results></div>
  <button type="button" data-ticketing-records-more hidden>Cargar más</button>
  <div data-ticketing-records-detail aria-live="polite"></div>
</section>
