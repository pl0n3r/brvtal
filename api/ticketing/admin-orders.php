<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_staff_permissions.php';
require_once __DIR__ . '/../../config/ticketing_admin_orders.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'GET']);
}
try {
    $filter = brvtalTicketAdminFilters($_GET);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    $role = (string)($stmt->fetchColumn() ?: '');
    $capability = $filter['kind'] === 'buyers' ? 'buyers' : 'orders';
    if (!brvtalTicketingCan($role, $capability)
        || ($filter['email'] !== '' && !brvtalTicketingCan($role, 'buyers'))) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'], 403);
    }
    if ($filter['format'] === 'csv' && !brvtalTicketingCan($role, 'export')) {
        json_response(['ok'=>false,'error'=>'EXPORT_FORBIDDEN'], 403);
    }
    $page = brvtalTicketAdminPage($pdo, $filter);
    if ($filter['order_id'] !== null) {
        $page['rows'] = array_values(array_filter($page['rows'], static fn(array $row): bool =>
            (int)$row['id'] === $filter['order_id']));
        if ($page['rows'] === []) {
            $single = $filter; $single['q'] = (string)$filter['order_id'];
            $single['cursor'] = null; $single['status'] = ''; $single['email'] = '';
            $single['from'] = ''; $single['to'] = ''; $single['event_id'] = null;
            $page = brvtalTicketAdminPage($pdo, $single);
        }
        $page['rows'] = array_values(array_filter($page['rows'], static fn(array $row): bool =>
            (int)$row['id'] === $filter['order_id']));
        if (!$page['rows']) json_response(['ok'=>false,'error'=>'ORDER_NOT_FOUND'], 404);
        $page['detail'] = brvtalTicketAdminDetail($pdo, $filter['order_id']);
    }
    if ($filter['format'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="brvtal-ticketing-page.csv"');
        $out = fopen('php://output', 'wb');
        fputcsv($out, $filter['kind'] === 'orders'
            ? ['id','buyer_id','event_id','status','currency','total_cop','units','approvals','last_payment_state','mail_state']
            : ['id','status','order_count','paid_total_cop'], escape: '\');
        foreach ($page['rows'] as $row) {
            $cols = $filter['kind'] === 'orders'
                ? ['id','buyer_id','event_id','status','currency','total_cop','units','approvals','last_payment_state','mail_state']
                : ['id','status','order_count','paid_total_cop'];
            fputcsv($out, array_map(static fn(string $key): string => (string)($row[$key] ?? ''), $cols),
                escape: '\');
        }
        fclose($out);
        exit;
    }
    json_response(['ok'=>true, 'data'=>$page, 'read_only'=>true, 'currency'=>'COP']);
} catch (InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'INVALID_FILTER'], 422);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'ORDERS_UNAVAILABLE'], 503);
}
