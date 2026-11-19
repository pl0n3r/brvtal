<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_staff_permissions.php';
require_once __DIR__ . '/../../config/ticketing_finance.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET']);
}
try {
    $stmt=db()->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    $role=(string)($stmt->fetchColumn() ?: '');
    if (!brvtalTicketingCan($role,'finance')) json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    foreach (array_keys($_GET) as $key) {
        if (!in_array($key,['event_id','from','to','channel','format'],true) || !is_string($_GET[$key])) {
            json_response(['ok'=>false,'error'=>'INVALID_FILTER'],422);
        }
    }
    $eventId=null;
    if (isset($_GET['event_id']) && $_GET['event_id']!=='') {
        $v=filter_var($_GET['event_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($v===false) json_response(['ok'=>false,'error'=>'INVALID_EVENT'],422);
        $eventId=(int)$v;
    }
    $utc=new DateTimeZone('UTC');
    $dates=[];
    foreach (['from','to'] as $name) {
        $value=$_GET[$name] ?? '';
        if ($value!=='' && (!preg_match('/^\d{4}-\d\d-\d\d$/D',$value)
            || !($date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$utc))
            || $date->format('Y-m-d')!==$value)) {
            json_response(['ok'=>false,'error'=>'INVALID_DATE'],422);
        }
        $dates[$name]=$value===''?null:$value;
    }
    if ($dates['from']!==null && $dates['to']!==null && $dates['from']>$dates['to']) {
        json_response(['ok'=>false,'error'=>'INVALID_RANGE'],422);
    }
    $channel=$_GET['channel'] ?? 'all';
    if (!in_array($channel,['all','wompi'],true)) json_response(['ok'=>false,'error'=>'INVALID_CHANNEL'],422);
    $format=$_GET['format'] ?? 'json';
    if (!in_array($format,['json','csv'],true)) json_response(['ok'=>false,'error'=>'INVALID_FORMAT'],422);
    if ($format==='csv' && !brvtalTicketingCan($role,'export')) {
        json_response(['ok'=>false,'error'=>'EXPORT_FORBIDDEN'],403);
    }
    $rows=brvtalFinanceReport(db(),$eventId,$dates['from'],$dates['to'],$channel);
    if ($format==='csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="brvtal-finanzas.csv"');
        $out=fopen('php://output','wb');
        fwrite($out,"\xEF\xBB\xBF");
        $columns=['event_id','gross_cop','discount_cop','approved_cop','paid_orders',
            'pending_orders','declined_orders','refunded_orders','unverified_orders',
            'tickets','fee_cop','fee_vat_cop','withheld_cop','payout_cop',
            'difference_cop','settlement_status'];
        fputcsv($out,$columns);
        foreach ($rows as $row) {
            fputcsv($out,array_map(static fn($key): string =>
                $row[$key]===null?'':(string)$row[$key],$columns));
        }
        fclose($out);
        exit;
    }
    json_response(['ok'=>true,'rows'=>$rows,'currency'=>'COP',
        'evidence'=>'provisional; sin comprobante de settlement ni libro de gastos'],200);
} catch (LengthException) {
    json_response(['ok'=>false,'error'=>'RANGE_TOO_LARGE'],422);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'FINANCES_UNAVAILABLE'],503);
}
