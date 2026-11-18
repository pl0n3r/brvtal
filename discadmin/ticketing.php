<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/ticketing_staff_permissions.php';

header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') {
    http_response_code(404);
    exit;
}
brvtal_admin_require();
try {
    $statement = db()->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $statement->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    $role = (string)($statement->fetchColumn() ?: '');
} catch (Throwable $error) {
    http_response_code(503);
    exit('No se pudo verificar el acceso a Boletería.');
}
if (!brvtalTicketingCan($role, 'view')) {
    http_response_code(403);
    exit('Acceso a Boletería no autorizado.');
}
$tabs = [
    'dashboard'=>['Dashboard','view'], 'orders'=>['Pedidos','orders'],
    'entries'=>['Boletas','entries'], 'buyers'=>['Compradores','buyers'],
    'door'=>['Puerta','door'], 'finance'=>['Finanzas','finance'],
    'promos'=>['Promos','promos'], 'promoters'=>['Promotores','promoters'],
    'mail'=>['Correos','mail'], 'settings'=>['Ajustes','settings'],
];
$visible = array_filter($tabs, static fn(array $tab): bool => brvtalTicketingCan($role, $tab[1]));
$first = array_key_first($visible);
$providers = brvtalTicketingProviderStatus();
?>
<section class="ticketing" data-admin-module="ticketing" data-ticketing-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>"
 data-wompi-mode="<?= htmlspecialchars($providers['wompi'], ENT_QUOTES, 'UTF-8') ?>"
 data-resend-mode="<?= htmlspecialchars($providers['resend'], ENT_QUOTES, 'UTF-8') ?>">
  <p class="ticketing-notice" role="status">Preparación de Boletería. Sin ventas, emisión QR ni proveedores activos.</p>
  <div class="ticketing-tabs" role="tablist" aria-label="Secciones de Boletería">
    <?php foreach ($visible as $id => $tab): ?>
      <button type="button" id="ticketing-tab-<?= $id ?>" role="tab" data-ticketing-tab="<?= $id ?>"
       aria-controls="ticketing-panel" aria-selected="<?= $id === $first ? 'true' : 'false' ?>"
       tabindex="<?= $id === $first ? '0' : '-1' ?>"><?= htmlspecialchars($tab[0], ENT_QUOTES, 'UTF-8') ?></button>
    <?php endforeach; ?>
  </div>
  <div id="ticketing-panel" role="tabpanel" tabindex="0" aria-labelledby="ticketing-tab-<?= $first ?>"
   data-ticketing-panel aria-live="polite"></div>
</section>
