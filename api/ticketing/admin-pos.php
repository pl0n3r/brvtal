<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/admin_auth.php';
require_once __DIR__ . '/../../config/ticketing_pos.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'POST']);
}
brvtal_admin_require_csrf();
if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')),'application/json')
    || (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>2048) {
    json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'],415);
}
try {
    $raw=file_get_contents('php://input',false,null,0,2049);
    if (!is_string($raw) || strlen($raw)>2048) {
        json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],413);
    }
    $data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if (!is_array($data) || array_is_list($data)) {
        json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);
    }
    $pdo=db();
    $actor=(int)($_SESSION['admin_id']??0);
    $role=$pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $role->execute([$actor]);
    // This server-only key is not the browser-visible CSRF token.
    $quoteKey=null;
    if (in_array($data['action']??null,['manual_quote','manual_order'],true)) {
        if (!is_string($_SESSION['pos_quote_key']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$_SESSION['pos_quote_key'])) {
            $_SESSION['pos_quote_key']=bin2hex(random_bytes(32));
        }
        $quoteKey=hex2bin($_SESSION['pos_quote_key']);
    }
    $receipt=brvtalPosRun($pdo,$actor,(string)($role->fetchColumn()?:''),$data,$quoteKey);
    json_response(['ok'=>true,'data'=>$receipt],200);
} catch (InvalidArgumentException | JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);
} catch (DomainException $error) {
    $code=$error->getMessage();
    $status=match($code) {
        'FORBIDDEN'=>403,'POS_SYNTHETIC_ONLY'=>423,
        'POS_EVENT_UNAVAILABLE','POS_TYPE_UNAVAILABLE'=>404,
        'POS_IDEMPOTENCY_CONFLICT','POS_SHIFT_ALREADY_OPEN','INVENTORY_SOLD_OUT',
        'POS_QUOTE_REQUIRED','POS_QUOTE_CHANGED'=>409,
        'POS_PROVIDER_PROOF_REQUIRED','POS_TRANSFER_RULES_REQUIRED'=>403,
        default=>409,
    };
    json_response(['ok'=>false,'error'=>$code],$status);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'POS_UNAVAILABLE'],503);
}
