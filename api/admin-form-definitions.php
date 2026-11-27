<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/form_definition_store.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET','POST'], true)) json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
try {
    $pdo = db();
    $actor = (int)($_SESSION['admin_id'] ?? 0);
    brvtalFormDefinitionRequire($pdo, $actor);
    $settings = $GLOBALS['config']['forms'] ?? [];
    if (($settings['admin_definition_enabled'] ?? null) !== true || ($settings['privacy_approved'] ?? null) !== true) {
        json_response(['ok'=>false,'error'=>'FORMS_DEFINITIONS_DISABLED'],403);
    }
    if ($method === 'GET') json_response(['ok'=>true,'data'=>brvtalFormDefinitionRead($pdo,$actor,$_GET)]);
    brvtal_admin_require_csrf();
    if (!preg_match('~^application/json(?:\s*;|\s*$)~i', (string)($_SERVER['CONTENT_TYPE'] ?? ''))) {
        json_response(['ok'=>false,'error'=>'INVALID_CONTENT_TYPE'],415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (!is_string($raw) || strlen($raw) > 65536) json_response(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'],413);
    $input = json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_is_list($input)) brvtalFormSchemaFail();
    json_response(['ok'=>true,'data'=>brvtalFormDefinitionWrite($pdo,$actor,$input)]);
} catch (InvalidArgumentException | JsonException) {
    json_response(['ok'=>false,'error'=>'INVALID_PAYLOAD'],422);
} catch (DomainException $e) {
    $status = match ($e->getMessage()) {'FORBIDDEN'=>403,'FORM_NOT_FOUND'=>404,'FORM_CONFLICT'=>409,default=>422};
    json_response(['ok'=>false,'error'=>$status === 422 ? 'INVALID_PAYLOAD' : $e->getMessage()],$status);
} catch (Throwable) {
    // Never disclose draft contents, policy copy, SQL or exception text.
    json_response(['ok'=>false,'error'=>'FORMS_DEFINITIONS_UNAVAILABLE'],503);
}
