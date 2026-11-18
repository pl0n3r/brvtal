<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/buyer_auth.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

try {
    brvtalBuyerSessionStart();
} catch (Throwable) {
    json_response(['ok' => false, 'error' => 'BUYER_AUTH_UNAVAILABLE'], 503);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    json_response([
        'ok' => true, 'csrf' => $_SESSION['buyer_csrf'],
        'authenticated' => (int)($_SESSION['buyer_id'] ?? 0) > 0,
    ]);
}
if ($method !== 'POST') {
    json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405, ['Allow' => 'GET, POST']);
}

$body = input_json();
if (!brvtalBuyerCsrfValid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    json_response(['ok' => false, 'error' => 'CSRF_INVALID'], 403);
}
$action = is_string($body['action'] ?? null) ? $body['action'] : '';
if ($action === 'logout') {
    brvtalBuyerLogout();
    json_response(['ok' => true]);
}
if (!in_array($action, ['login', 'register', 'request-reset', 'confirm-reset', 'verify'], true)) {
    json_response(['ok' => false, 'error' => 'UNKNOWN_ACTION'], 400);
}

// Build-ahead only: fail closed until the separate T03 schema and mail
// transport are integrated. Never fake registration or reset success.
try {
    $pdo = db();
    if (!brvtalBuyerSchemaReady($pdo)) {
        json_response(['ok' => false, 'error' => 'BUYER_SCHEMA_PENDING'], 503);
    }
} catch (Throwable) {
    json_response(['ok' => false, 'error' => 'BUYER_SCHEMA_PENDING'], 503);
}
if ($action !== 'login') {
    json_response(['ok' => false, 'error' => 'BUYER_MAIL_PENDING'], 503);
}

$email = brvtalBuyerEmail($body['email'] ?? null);
$password = $body['password'] ?? null;
if ($email === null || !is_string($password)) {
    json_response(['ok' => false, 'error' => 'INVALID_CREDENTIALS'], 401);
}
$limit = brvtalBuyerThrottle($email);
if ($limit['limited']) {
    json_response(['ok' => false, 'error' => 'RATE_LIMITED'], 429, [
        'Retry-After' => (string)max(1, (int)$limit['retry_after']),
    ]);
}
try {
    $q = $pdo->prepare(
        'SELECT id, password_hash, email_verified_at FROM ticket_buyers WHERE email = ? LIMIT 1'
    );
    $q->execute([$email]);
    $buyer = $q->fetch(PDO::FETCH_ASSOC);
    $valid = is_array($buyer)
        && is_string($buyer['password_hash'] ?? null)
        && password_verify($password, $buyer['password_hash'])
        && !empty($buyer['email_verified_at']);
    if (!$valid) {
        brvtalBuyerThrottle($email, true);
        json_response(['ok' => false, 'error' => 'INVALID_CREDENTIALS'], 401);
    }
    brvtalBuyerLoginSession((int)$buyer['id']);
    brvtal_password_rate_limit_reset('buyer|' . $email, __DIR__ . '/../../storage/buyer_rate_limits');
    json_response(['ok' => true, 'csrf' => $_SESSION['buyer_csrf']]);
} catch (Throwable) {
    json_response(['ok' => false, 'error' => 'BUYER_AUTH_UNAVAILABLE'], 503);
}
