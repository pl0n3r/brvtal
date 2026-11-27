<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/admin_auth.php';
require_once __DIR__.'/../../config/ticketing_pos_receipts.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
}
if ($method === 'POST') brvtal_admin_require_csrf();

try {
    $pdo = db();
    $actor = (int)($_SESSION['admin_id'] ?? 0);
    $role = $pdo->prepare("SELECT id FROM admins WHERE id=? AND is_active=1 AND staff_role='owner'");
    $role->execute([$actor]);
    if (!$role->fetchColumn()) json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);

    if ($method === 'GET') {
        $destinations = [];
        foreach (['transfer','nequi','daviplata','terminal'] as $medium) {
            $destinations[$medium] = brvtalPosDestinations($pdo,$medium);
        }
        json_response(['ok'=>true,'data'=>['destinations'=>$destinations]]);
    }

    $mime = strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE'] ?? ''),2)[0]));
    if ($mime !== 'application/json') json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'],415);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>2048) json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],413);
    $raw = file_get_contents('php://input',false,null,0,2049);
    if (!is_string($raw) || strlen($raw)>2048) json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],413);
    $input = json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('INVALID_PAYLOAD');

    if (($input['action'] ?? null)==='create' && count($input)===3
        && is_string($input['method'] ?? null) && is_string($input['alias'] ?? null)) {
        $medium = brvtalPosReceiptMethod($input['method']);
        $alias = brvtalPosDestinationAlias($input['alias']);
        // Existing schema has no unique alias key. Serialize this sole administrative
        // creation path per database/method, including aliases in historical rows.
        $lock = hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn().':pos-destination:'.$medium);
        $acquire = $pdo->prepare('SELECT GET_LOCK(?,2)');
        $acquire->execute([$lock]);
        if ((int)$acquire->fetchColumn()!==1) throw new RuntimeException('POS_DESTINATION_BUSY');
        try {
            $duplicate = $pdo->prepare('SELECT id FROM ticket_pos_payment_destinations WHERE method=? AND alias=? LIMIT 1');
            $duplicate->execute([$medium,$alias]);
            if ($duplicate->fetchColumn()) throw new DomainException('POS_DESTINATION_DUPLICATE');
            $id = brvtalPosDestinationCreate($pdo,$actor,$medium,$alias);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        }
        json_response(['ok'=>true,'data'=>['id'=>$id]],201);
    }

    if (($input['action'] ?? null)==='deactivate' && count($input)===2
        && is_int($input['id'] ?? null) && $input['id']>0) {
        brvtalPosDestinationDeactivate($pdo,$actor,$input['id']);
        json_response(['ok'=>true,'data'=>['id'=>$input['id']]]);
    }
    throw new InvalidArgumentException('INVALID_PAYLOAD');
} catch (InvalidArgumentException | JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);
} catch (DomainException $error) {
    $code = $error->getMessage();
    if ($code==='POS_DESTINATION_FORBIDDEN') json_response(['ok'=>false,'error'=>'FORBIDDEN'],403);
    if (in_array($code,['POS_DESTINATION_DUPLICATE','POS_DESTINATION_UNAVAILABLE'],true)) {
        json_response(['ok'=>false,'error'=>$code],409);
    }
    json_response(['ok'=>false,'error'=>'POS_UNAVAILABLE'],503);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'POS_UNAVAILABLE'],503);
}
