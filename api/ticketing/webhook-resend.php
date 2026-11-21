<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_mail_events.php';
require_once __DIR__ . '/../../config/secrets.php';
/** Standard Svix headers; whsec_ is a base64 key, MAC covers the raw request. */
function brvtal_mail_webhook_verify(string $raw,array $headers,string $secret,?int $now=null): bool {
    $id=(string)($headers['svix-id'] ?? '');
    $stamp=(string)($headers['svix-timestamp'] ?? '');
    $signatures=(string)($headers['svix-signature'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id)
        || !preg_match('/^[0-9]{10,11}$/D',$stamp)
        || abs(($now ?? time())-(int)$stamp)>300
        || !str_starts_with($secret,'whsec_') || strlen($secret)>256) return false;
    $key=base64_decode(substr($secret,6),true);
    if (!is_string($key) || strlen($key)<16) return false;
    $expected=base64_encode(hash_hmac('sha256',$id.'.'.$stamp.'.'.$raw,$key,true));
    return array_any(preg_split('/\s+/',trim($signatures)) ?: [],
        fn($sig) => str_starts_with($sig,'v1,') && hash_equals($expected,substr($sig,3)));
}
/** Fail closed; no sensitive request/exception body ever returned. */
function brvtal_mail_webhook_process(PDO $pdo,string $raw,array $headers,string $secret,?int $now=null): int {
    if (brvtal_secret_value('BRVTAL_TICKET_MAIL_WEBHOOK_ENABLED')!=='1' || !brvtal_mail_history_allowed()) return 503;
    if (strlen($raw)>65536) return 413;
    if (!brvtal_mail_webhook_verify($raw,$headers,$secret,$now)) return 401;
    $e=json_decode($raw,true);
    if (!is_array($e) || !is_array($e['data'] ?? null)) return 400;
    $state=match($e['type'] ?? null) {
        'email.sent'=>'accepted','email.delivered'=>'delivered',
        'email.delivery_delayed'=>'deferred','email.bounced'=>'bounced',
        'email.failed'=>'failed',default=>null,
    };
    $provider=$e['data']['email_id'] ?? null;$created=$e['created_at'] ?? null;
    if ($state===null || !is_string($provider) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$provider)
        || !is_string($created) || strlen($created)>40) return 400;
    try {
        $when=new DateTimeImmutable($created);
        if ($when->getTimestamp()>($now ?? time())+300) return 400;
        brvtal_mail_history_event($pdo,(string)$headers['svix-id'],$provider,$state,$when,hash('sha256',$raw));
        return 200;
    } catch (DomainException) { return 409; }
    catch (Throwable) { return 503; }
}
// GET is DISCADMIN-only, POST is signed Resend-only; no public mail history.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__) {
    require_once __DIR__ . '/../../config/bootstrap.php';
    header('Cache-Control: no-store');
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
    if ($method==='GET') {
        require_once __DIR__ . '/../../config/admin_auth.php';
        brvtal_admin_require();
        try {
            json_response(['ok'=>true,'items'=>brvtal_mail_history_list(db(),$_GET,(int)($_SESSION['admin_id'] ?? 0))]);
        } catch (InvalidArgumentException) { json_response(['ok'=>false,'error'=>'INVALID_QUERY'],422); }
        catch (Throwable) { json_response(['ok'=>false,'error'=>'UNAVAILABLE'],503); }
    }
    if ($method!=='POST') json_response(['ok'=>false],405);
    $raw=file_get_contents('php://input');
    $headers=['svix-id'=>(string)($_SERVER['HTTP_SVIX_ID'] ?? ''),
        'svix-timestamp'=>(string)($_SERVER['HTTP_SVIX_TIMESTAMP'] ?? ''),
        'svix-signature'=>(string)($_SERVER['HTTP_SVIX_SIGNATURE'] ?? '')];
    $secret=(string)brvtal_secret_value('BRVTAL_TICKET_MAIL_WEBHOOK_SECRET');
    if (!is_string($raw)) $raw='';
    $status=brvtal_mail_webhook_verify($raw,$headers,$secret)
        ? brvtal_mail_webhook_process(db(),$raw,$headers,$secret) : 401;
    json_response(['ok'=>$status===200],$status);
}
