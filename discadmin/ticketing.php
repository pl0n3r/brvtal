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
} catch (Throwable) {
    http_response_code(503);
    exit('No se pudo verificar el acceso a Boletería.');
}
if (!brvtalTicketingCan($role, 'view')) {
    http_response_code(403);
    exit('Acceso a Boletería no autorizado.');
}
// #1964 (A): sections grouped by what the operator is doing; role filtering unchanged.
$groups = ['sale'=>'Venta', 'live'=>'En vivo', 'money'=>'Dinero', 'marketing'=>'Marketing'];
$tabs = [
    'dashboard'=>['Resumen','view','sale'], 'orders'=>['Pedidos','orders','sale'],
    'entries'=>['Boletas','entries','sale'], 'buyers'=>['Compradores','buyers','sale'],
    'door'=>['Puerta','door','live'], 'finance'=>['Finanzas','finance','money'],
    'promos'=>['Cupones','promos','marketing'], 'promoters'=>['Promotores','promoters','marketing'],
    'recovery'=>['Recuperación','mail','marketing'], 'mail'=>['Correos','mail','marketing'],
];
$visible = array_filter($tabs, static fn(array $tab, string $id): bool => brvtalTicketingCan($role, $tab[1])
    && ($id !== 'recovery' || brvtalTicketingCan($role, 'buyers')), ARRAY_FILTER_USE_BOTH);
$first = array_key_first($visible);
$visibleGroups = array_filter($groups, static fn(string $label, string $id): bool =>
    array_filter($visible, static fn(array $tab): bool => $tab[2] === $id) !== [], ARRAY_FILTER_USE_BOTH);
// Real provider state, never a hard-coded "inactive" banner.
$saleText = 'Venta en línea: apagada';
$mailText = 'Correo: apagado';
try {
    require_once __DIR__ . '/../config/ticketing_wompi_client.php';
    require_once __DIR__ . '/../config/ticketing_mail_mode.php';
    $wompi = brvtal_ticket_wompi_status();
    $saleText = $wompi['ready'] ? ($wompi['mode'] === 'production' ? 'Venta en línea: cobros reales' : 'Venta en línea: pruebas')
        : ($wompi['mode'] === 'off' ? 'Venta en línea: apagada' : 'Venta en línea: incompleta');
    $mail = brvtal_ticket_mail_status();
    $mailText = $mail['ready'] ? ($mail['mode'] === 'live' ? 'Correo: envío real' : 'Correo: pruebas')
        : ($mail['mode'] === 'disabled' ? 'Correo: apagado' : 'Correo: incompleto');
} catch (Throwable) {
    $saleText = 'Venta en línea: sin estado';
    $mailText = 'Correo: sin estado';
}
$e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<section class="ticketing" data-admin-module="ticketing" data-ticketing-role="<?= $e($role) ?>">
  <p class="ticketing-status">
    <span data-ticketing-sale-status><?= $e($saleText) ?></span>
    <span data-ticketing-mail-status><?= $e($mailText) ?></span>
    <?php if (brvtalTicketingCan($role, 'settings')): ?>
    <button type="button" class="ticketing-link" data-ticketing-configure>Configurar en Integraciones</button>
    <?php endif; ?>
  </p>
  <nav class="ticketing-groups" aria-label="Grupos de Boletería">
    <?php foreach ($visibleGroups as $id => $label): ?>
      <button type="button" data-ticketing-group="<?= $id ?>"
       aria-pressed="<?= $visible[$first][2] === $id ? 'true' : 'false' ?>"><?= $e($label) ?></button>
    <?php endforeach; ?>
  </nav>
  <div class="ticketing-tabs" role="tablist" aria-label="Secciones de Boletería">
    <?php foreach ($visible as $id => $tab): ?>
      <button type="button" id="ticketing-tab-<?= $id ?>" role="tab" data-ticketing-tab="<?= $id ?>"
       data-ticketing-tab-group="<?= $tab[2] ?>"
       aria-controls="ticketing-panel" aria-selected="<?= $id === $first ? 'true' : 'false' ?>"
       tabindex="<?= $id === $first ? '0' : '-1' ?>"<?= $tab[2] === $visible[$first][2] ? '' : ' hidden' ?>><?= $e($tab[0]) ?></button>
    <?php endforeach; ?>
  </div>
  <div id="ticketing-panel" role="tabpanel" tabindex="0" aria-labelledby="ticketing-tab-<?= $first ?>"
   data-ticketing-panel aria-live="polite"></div>
</section>
