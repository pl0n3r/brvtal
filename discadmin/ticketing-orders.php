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
    // A role only needs the capability of the requested workspace.
    $kind = ($_GET['kind'] ?? 'orders') === 'buyers' ? 'buyers' : 'orders';
    if (!brvtalTicketingCan($role, $kind)) {
        http_response_code(403); exit;
    }
} catch (Throwable) { http_response_code(503); exit; }
?>
<section data-ticketing-records data-ticketing-csrf="<?= htmlspecialchars(brvtal_admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <h2><?= $kind === 'buyers' ? 'Compradores' : 'Pedidos' ?></h2>
  <p>Consulta privada. El estado «pagado» solo procede de la verificación del servidor. No se reembolsan ni emiten boletas aquí.</p>
  <form data-ticketing-records-form class="ticketing-records-form tk-form">
    <input type="hidden" name="kind" value="<?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?>">
    <label class="tk-field">Buscar pedido o correo <input type="search" name="q" maxlength="120" autocomplete="off"></label>
    <label class="tk-field">Correo exacto <input type="email" name="email" maxlength="120" autocomplete="off"></label>
    <label class="tk-field">Evento <input type="number" min="1" name="event_id" placeholder="Todos"></label>
    <label class="tk-field">Estado <select name="status"><option value="">Todos</option>
      <option value="draft">Borrador</option><option value="pending">Pendiente</option>
      <option value="paid">Pagado</option><option value="failed">Fallido</option>
      <option value="cancelled">Cancelado</option><option value="expired">Expirado</option>
      <option value="refunded">Reembolsado</option>
    </select></label>
    <label class="tk-field">Desde (fecha) <input type="date" name="from"></label>
    <label class="tk-field">Hasta (fecha) <input type="date" name="to"></label>
    <button type="submit" class="tk-button is-primary">Consultar</button>
    <?php if (brvtalTicketingCan($role, 'export')): ?>
      <button type="button" class="tk-button" data-ticketing-export>Exportar CSV de esta página</button>
    <?php endif; ?>
  </form>
  <p class="tk-status" role="status" data-ticketing-records-status aria-live="polite">Consultando...</p>
  <div data-ticketing-records-results></div>
  <button type="button" class="tk-button" data-ticketing-records-more hidden>Cargar más</button>
  <div data-ticketing-records-detail aria-live="polite"></div>
</section>
