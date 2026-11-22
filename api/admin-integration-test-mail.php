<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/ticketing_staff_permissions.php';
require_once __DIR__ . '/../config/ticketing_resend.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
brvtal_admin_require();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'POST']);
}

try {
    $pdo = db();
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $query = $pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $query->execute([$adminId]);
    if ($adminId < 1 || !brvtalTicketingCan((string)($query->fetchColumn() ?: ''), 'settings')) {
        json_response(['ok'=>false,'error'=>'FORBIDDEN'], 403);
    }

    $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = brvtal_admin_csrf_token();
    if ($csrf === '' || $expected === '' || !hash_equals($expected, $csrf)) {
        json_response(['ok'=>false,'error'=>'CSRF'], 403);
    }
    brvtal_admin_require_csrf($csrf);

    // El destino lo escribe el administrador (como en Condor); si lo deja vacío se
    // usa el primer destinatario permitido. No depende del modo live/test (#1904).
    $input = json_decode((string)file_get_contents('php://input', false, null, 0, 2048), true);
    $requested = is_array($input) ? strtolower(trim((string)($input['email'] ?? ''))) : '';
    if ($requested !== '') {
        if (strlen($requested) > 254 || filter_var($requested, FILTER_VALIDATE_EMAIL) === false) {
            json_response(['ok'=>false,'error'=>'TEST_RECIPIENT_INVALID'], 422);
        }
        $recipient = $requested;
    } else {
        $allowed = brvtal_ticket_test_recipients();
        if ($allowed === []) {
            json_response(['ok'=>false,'error'=>'TEST_RECIPIENT_REQUIRED'], 422);
        }
        $recipient = $allowed[0];
    }
    if (trim((string)brvtal_secret_value('BRVTAL_TICKET_MAIL_API_KEY')) === ''
        || trim((string)brvtal_secret_value('BRVTAL_TICKET_MAIL_FROM')) === ''
        || trim((string)brvtal_secret_value('BRVTAL_TICKET_MAIL_DOMAIN')) === '') {
        json_response(['ok'=>false,'error'=>'MAIL_NOT_CONFIGURED'], 409);
    }

    // Session-local anti-spam fence, acquired before the provider call.
    $now = time();
    $last = (int)($_SESSION['brvtal_test_mail_sent_at'] ?? 0);
    if ($last > 0 && $now - $last < 60) {
        json_response(['ok'=>false,'error'=>'MAIL_TEST_RATE_LIMITED'], 429);
    }
    $_SESSION['brvtal_test_mail_sent_at'] = $now;
    brvtal_admin_release_session();

    // The endpoint never accepts an arbitrary recipient from client input.
    $job = [
        'recipient_email' => $recipient,
        'admin_test' => true,
        'message_key' => 'admin:test:' . bin2hex(random_bytes(12)),
        'subject' => 'Correo de prueba BRVTAL',
        'body_text' => 'Este es un mensaje de prueba controlado de BRVTAL. No corresponde a una compra.',
    ];
    $outcome = brvtal_ticket_resend_send($job);
    $state = (string)($outcome['state'] ?? 'uncertain');
    $accepted = $state === 'accepted';
    $safeStates = ['accepted','retry','failed','uncertain','suppressed_test'];
    if (!in_array($state, $safeStates, true)) {
        $state = 'uncertain';
    }
    // No address, secret, provider response or idempotency key in JSON/logs.
    json_response(['ok'=>$accepted,'state'=>$state,
        'error'=>$accepted ? null : 'MAIL_TEST_NOT_ACCEPTED'], $accepted ? 200 : 503);
} catch (Throwable $e) {
    brvtal_log('MAIL_TEST_ERROR', 'Controlled mail test unavailable', ['class'=>$e::class]);
    json_response(['ok'=>false,'error'=>'MAIL_TEST_UNAVAILABLE'], 503);
}
