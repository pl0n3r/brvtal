<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/ticketing_staff_permissions.php';
header('Cache-Control: no-store, private');
if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') { http_response_code(404); exit; }
brvtal_admin_require();
try {
    $q=db()->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    if (!brvtalTicketingCan((string)($q->fetchColumn() ?: ''),'finance')) {
        http_response_code(403); exit;
    }
} catch (Throwable) { http_response_code(503); exit; }
?>
<section data-finance-module>
  <h2>Finanzas de boletería</h2>
  <p>Solo consulta. Sin conciliación bancaria acreditada, las cifras de abono y gastos son provisionales.</p>
  <form data-finance-form>
    <label>Evento <input type="number" min="1" name="event_id" placeholder="Todos"></label>
    <label>Desde (UTC) <input type="date" name="from"></label>
    <label>Hasta (UTC) <input type="date" name="to"></label>
    <label>Canal <select name="channel"><option value="all">Todos</option><option value="wompi">Wompi</option></select></label>
    <button type="submit">Consultar</button>
    <button type="button" data-finance-export>Exportar CSV</button>
  </form>
  <p role="status" data-finance-status aria-live="polite"></p>
  <div data-finance-results></div>
</section>
