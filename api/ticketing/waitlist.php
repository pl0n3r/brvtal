<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/buyer_auth.php';
require_once __DIR__ . '/../../config/ticketing_waitlist.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

// GET only displays a confirmation form; mail-link prefetchers MUST NOT unsubscribe.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    // Public, read-only catalog. Never expose buyers, token hashes or waitlist
    // status; anonymous reads do not create a subscription, hold or offer.
    if (array_key_exists('catalog', $_GET)) {
        $slug = $_GET['catalog'];
        if (!is_string($slug) || preg_match('/^[a-z0-9-]{1,100}$/D', $slug) !== 1
            || count($_GET) !== 1) {
            json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
        }
        if (getenv('BRVTAL_WAITLIST_TESTING') !== '1'
            && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
            json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'],403);
        }
        try {
            if (!brvtal_waitlist_enabled()) {
                json_response(['ok'=>true,'types'=>[],'checkout'=>[]],200);
            }
            $db = db();
            $q = $db->prepare("SELECT id FROM events WHERE slug=? AND
                status IN ('published','sold_out','tickets_available','last_tickets')
                LIMIT 1");
            $q->execute([$slug]);
            $eventId = (int)$q->fetchColumn();
            if ($eventId < 1) {
                json_response(['ok'=>true,'types'=>[],'checkout'=>[]],200);
            }
            $types = brvtal_waitlist_public_types($db, $eventId);
            // The checkout adapter is separately disabled unless the Wompi
            // mode and offer verification gate are both configured safely.
            $checkout = [];
            if ($types !== [] && brvtal_waitlist_offers_enabled()) {
                require_once __DIR__.'/../../config/public_ticket_checkout.php';
                $checkout = brvtal_public_ticket_checkout_offers($db,$eventId);
            }
            json_response(['ok'=>true,'event_id'=>$eventId,
                'types'=>$types,'checkout'=>$checkout],200);
        } catch (Throwable) {
            json_response(['ok'=>true,'types'=>[],'checkout'=>[]],200);
        }
    }
    $opaque=(string)($_GET['token'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$opaque)) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    header("Content-Security-Policy: default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Frame-Options: DENY');
    header('Content-Type: text/html; charset=utf-8');
    $secret=htmlspecialchars($opaque,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Salir de lista de espera · BRVTAL</title></head><body>'
        . '<main><h1>Salir de lista de espera</h1>'
        . '<p>Confirma la baja. Esto cancelará cualquier invitación pendiente y no generará cobros.</p>'
        . '<form method="post" action="/api/ticketing/waitlist.php">'
        . '<input type="hidden" name="action" value="unsubscribe">'
        . '<input type="hidden" name="token" value="'.$secret.'">'
        . '<button type="submit">Confirmar baja</button></form></main></body></html>';
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405,['Allow'=>'GET, POST']);
}
if (getenv('BRVTAL_WAITLIST_TESTING') !== '1'
    && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) {
    json_response(['ok'=>false,'error'=>'HTTPS_REQUIRED'],403);
}
try {
    $isForm=str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')),
        'application/x-www-form-urlencoded');
    $body=$isForm ? $_POST : input_json();
    if (!is_array($body) || !is_string($body['action'] ?? null)) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    if ($body['action'] === 'unsubscribe' && count($body) === 2) {
        // Opaque link token: unsubscribe only, no account, seat or payment authority.
        brvtal_waitlist_unsubscribe(db(),(string)($body['token'] ?? ''));
        if ($isForm) {
            header('Content-Type: text/html; charset=utf-8');
            header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
            echo '<!doctype html><html lang="es"><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>Baja tramitada · BRVTAL</title><main><h1>Baja tramitada</h1>'
                . '<p>Si la solicitud corresponde a una inscripción, esta fue cancelada.</p></main>';
            exit;
        }
        json_response(['ok'=>true,'status'=>'unsubscribe_processed'],200);
    }
    if ($body['action'] !== 'subscribe' || count($body) !== 5) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    brvtalBuyerSessionStart();
    $buyerId=(int)($_SESSION['buyer_id'] ?? 0);
    if ($buyerId<1) json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'],401);
    if (!brvtalBuyerCsrfValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        json_response(['ok'=>false,'error'=>'CSRF'],403);
    }
    if (!is_int($body['event_id'] ?? null) || !is_int($body['ticket_type_id'] ?? null)
        || !is_string($body['consent'] ?? null) || !is_string($body['website'] ?? null)) {
        json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
    }
    $state=brvtal_waitlist_subscribe(db(),$body['event_id'],$body['ticket_type_id'],
        $buyerId,$body['consent'],$body['website']);
    json_response(['ok'=>true,'status'=>$state,'payment_created'=>false,'ticket_created'=>false],200);
} catch (InvalidArgumentException) {
    json_response(['ok'=>false,'error'=>'INVALID_REQUEST'],422);
} catch (DomainException $e) {
    $code=$e->getMessage();
    json_response(['ok'=>false,'error'=>in_array($code,
        ['WAITLIST_DISABLED','WAITLIST_RATE_LIMIT','WAITLIST_VERIFIED_BUYER_REQUIRED'],true)
        ? $code : 'WAITLIST_UNAVAILABLE'],409);
} catch (Throwable) {
    json_response(['ok'=>false,'error'=>'WAITLIST_UNAVAILABLE'],503);
}
