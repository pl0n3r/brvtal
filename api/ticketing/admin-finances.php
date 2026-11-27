<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_staff_permissions.php';
require_once __DIR__ . '/../../config/ticketing_finance.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
$method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method,['GET','POST'],true)) {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
}
if ($method==='POST') brvtal_admin_require_csrf();

function brvtalFinanceLedgerRead(PDO $db): array
{
    if (array_diff(array_keys($_GET),['ledger','event_id']) || $_GET['ledger']!=='1') {
        throw new InvalidArgumentException('INVALID_FILTER');
    }
    if (!isset($_GET['event_id'])) {
        $events=$db->query('SELECT id,title FROM events ORDER BY event_date DESC,id DESC LIMIT 5001')->fetchAll(PDO::FETCH_ASSOC);
        if (count($events)>5000) throw new LengthException('RANGE_TOO_LARGE');
        return ['events'=>$events];
    }
    $id=filter_var($_GET['event_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($id===false) throw new InvalidArgumentException('INVALID_EVENT');
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT id FROM events WHERE id=?');
        $q->execute([$id]);
        if (!$q->fetchColumn()) throw new DomainException('LEDGER_EVENT_NOT_FOUND');
        $result=[];
        foreach (['expenses'=>'event_expenses','budgets'=>'event_budgets'] as $key=>$table) {
            $concept=$key==='expenses'?', e.concept':'';
            $q=$db->prepare("SELECT CAST(e.id AS CHAR) AS id,e.category,CAST(e.amount_cop AS CHAR) AS amount_cop,
                e.entry_date,e.status$concept FROM $table e WHERE e.event_id=?
                AND NOT EXISTS (SELECT 1 FROM $table n WHERE n.supersedes_id=e.id)
                ORDER BY e.entry_date DESC,e.id DESC LIMIT 1001");
            $q->execute([$id]);
            $result[$key]=$q->fetchAll(PDO::FETCH_ASSOC);
            if (count($result[$key])>1000) throw new LengthException('RANGE_TOO_LARGE');
        }
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function brvtalFinanceLedgerPost(PDO $db, int $actor): int
{
    if ($_GET!==[] || strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json'
        || (int)($_SERVER['CONTENT_LENGTH']??0)>4096) {
        throw new InvalidArgumentException('INVALID_PAYLOAD');
    }
    $raw=file_get_contents('php://input',false,null,0,4097);
    if (!is_string($raw) || strlen($raw)>4096) throw new InvalidArgumentException('INVALID_PAYLOAD');
    $data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_diff(array_keys($data),['kind','action','event_id','previous_id','values','reason'])
        || !is_int($data['event_id']??null) || !is_string($data['kind']??null) || !is_string($data['action']??null)
        || !is_array($data['values']??null) || !is_string($data['reason']??null)
        || !array_key_exists('previous_id',$data) || ($data['previous_id']!==null && !is_int($data['previous_id']))) {
        throw new InvalidArgumentException('INVALID_PAYLOAD');
    }
    $values=$data['values'];
    if (array_diff(array_keys($values),['category','amount_cop','entry_date','status','concept'])
        || ($data['kind']==='budget' && array_key_exists('concept',$values))) {
        throw new InvalidArgumentException('INVALID_PAYLOAD');
    }
    return brvtalFinanceLedgerWrite($db,$data['kind'],$data['event_id'],$actor,$data['action'],
        $data['previous_id'],$values,$data['reason']);
}
try {
    $stmt=db()->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    $role=(string)($stmt->fetchColumn() ?: '');
    if (!brvtalTicketingCan($role,'finance')) json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    if ($method==='POST') {
        $id=brvtalFinanceLedgerPost(db(),(int)($_SESSION['admin_id']??0));
        json_response(['ok'=>true,'id'=>(string)$id],200);
    }
    if (array_key_exists('ledger',$_GET)) {
        json_response(['ok'=>true]+brvtalFinanceLedgerRead(db()),200);
    }
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
    if (!in_array($channel,['all','wompi','pos'],true)) json_response(['ok'=>false,'error'=>'INVALID_CHANNEL'],422);
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
        $columns=['event_id','gross_cop','discount_cop','approved_cop','pos_cop','pos_orders','paid_orders',
            'pending_orders','declined_orders','refunded_orders','unverified_orders',
            'tickets','capacity','checkins','refund_cop','refund_in_period_cop',
            'net_after_refunds_cop','fee_cop','fee_vat_cop','withheld_cop','payout_cop',
            'difference_cop','settlement_status','net_after_fees_cop','expenses_cop','committed_expenses_cop',
            'budget_cop','budget_variance_cop','profit_cop','profit_margin_pct','break_even_tickets',
            'profit_evidence','break_even_assumption'];
        $categories=['artistas','lugar','produccion','publicidad','logistica','otros'];
        $categoryFields=['budget_cop','paid_cop','committed_cop','variance_cop'];
        foreach ($categories as $category) foreach ($categoryFields as $field) $columns[]=$category.'_'.$field;
        fputcsv($out,$columns, escape: '\\');
        foreach ($rows as $row) {
            foreach ($categories as $category) foreach ($categoryFields as $field) {
                $row[$category.'_'.$field]=$row['budget_vs_actual'][$category][$field]??null;
            }
            fputcsv($out,array_map(static fn($key): string =>
                brvtalFinanceCsvCell($row[$key]??null),$columns),
            escape: '\\');
        }
        fclose($out);
        exit;
    }
    json_response(['ok'=>true,'rows'=>$rows,'currency'=>'COP',
        'evidence'=>'provisional; sin settlement acreditado; utilidad de evento completo según gastos pagados registrados; punto de equilibrio es un escenario'],200);
} catch (InvalidArgumentException | JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);
} catch (DomainException $e) {
    $error=$e->getMessage();
    $status=match($error) {
        'LEDGER_FORBIDDEN'=>403,'LEDGER_EVENT_NOT_FOUND'=>404,
        'LEDGER_STALE_REVISION','LEDGER_BUDGET_EXISTS','LEDGER_BUDGET_CATEGORY_IMMUTABLE'=>409,
        default=>422,
    };
    json_response(['ok'=>false,'error'=>$status===422?'INVALID_PAYLOAD':$error],$status);
} catch (LengthException) {
    json_response(['ok'=>false,'error'=>'RANGE_TOO_LARGE'],422);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'FINANCES_UNAVAILABLE'],503);
}
