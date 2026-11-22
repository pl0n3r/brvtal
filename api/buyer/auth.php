<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/buyer_auth_flows.php';
require_once __DIR__ . '/../../config/public_abuse_guard.php';

// The required bootstrap loads and validates this configuration.
/**
 * Whitelist internal diagnostic codes only; arbitrary exception messages can
 * contain user-controlled email, password, token or provider details.
 */
function brvtalBuyerAuthLogFailure(string $event, Throwable $error): void
{
    $message = $error->getMessage();
    $context = ['class' => $error::class];
    if (preg_match('/\A[A-Z][A-Z0-9_]{2,79}\z/D', $message) === 1) {
        $context['code'] = $message;
    }
    brvtal_log($event, 'Buyer authentication operation failed.', $context);
}

/** @var array<string, mixed> $config */


header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

try {
    brvtalBuyerSessionStart();
} catch (Throwable $error) {
    brvtalBuyerAuthLogFailure('BUYER_SESSION_FAILED', $error);
    json_response(['ok' => false, 'error' => 'BUYER_AUTH_UNAVAILABLE'], 503);
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    try {
        $challenge = [
            'register' => brvtal_public_abuse_challenge($config, 'register'),
            'request-reset' => brvtal_public_abuse_challenge($config, 'request-reset'),
            'resend-verification' => brvtal_public_abuse_challenge($config, 'resend-verification'),
        ];
    } catch (Throwable $error) {
        brvtalBuyerAuthLogFailure('BUYER_CHALLENGE_FAILED', $error);
        json_response(['ok' => false, 'error' => 'CAPTCHA_UNAVAILABLE'], 503);
    }
    json_response(['ok' => true, 'csrf' => $_SESSION['buyer_csrf'],
        'authenticated' => (int)($_SESSION['buyer_id'] ?? 0) > 0,
        'challenges' => $challenge]);
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
if (!in_array($action, ['login','register','verify','request-reset','confirm-reset',
    'resend-verification'], true)) {
    json_response(['ok' => false, 'error' => 'UNKNOWN_ACTION'], 400);
}
// Mandatory rate protection happens before opening a DB transaction.
$gate = brvtal_public_abuse_check($action, $body, $config);
if (!$gate['allowed']) {
    $headers = $gate['retry_after'] > 0
        ? ['Retry-After' => (string)$gate['retry_after']] : [];
    json_response(['ok' => false, 'error' => $gate['error']], $gate['status'], $headers);
}
try {
    $pdo = db();
    if (!brvtalBuyerSchemaReady($pdo)) {
        json_response(['ok' => false, 'error' => 'BUYER_SCHEMA_PENDING'], 503);
    }
    // #1877: irreversible PII anonymization requires explicit operator opt-in.
    // The test runner invokes the purge directly against disposable schemas only.
    if (getenv('BRVTAL_BUYER_RETENTION_PURGE_APPROVED') === '1') {
        brvtalBuyerPurgeUnverified($pdo);
    }
} catch (Throwable $error) {
    brvtalBuyerAuthLogFailure('BUYER_SCHEMA_FAILED', $error);
    json_response(['ok' => false, 'error' => 'BUYER_SCHEMA_PENDING'], 503);
}

if ($action === 'register') {
    try {
        brvtalBuyerAuthRegister($pdo, $body);
        json_response(['ok' => true], 202);
    } catch (InvalidArgumentException $error) {
        json_response(['ok' => false, 'error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        brvtalBuyerAuthLogFailure('BUYER_REGISTER_FAILED', $error);
        json_response(['ok' => false, 'error' => 'BUYER_MAIL_PENDING'], 503);
    }
}
if ($action === 'verify' || $action === 'confirm-reset') {
    try {
        $token = is_string($body['token'] ?? null) ? $body['token'] : '';
        brvtalBuyerAuthConsume($pdo, $token,
            $action === 'verify' ? 'verify_email' : 'reset_password',
            $action === 'confirm-reset' ? ($body['password'] ?? null) : null);
        json_response(['ok' => true]);
    } catch (DomainException) {
        json_response(['ok' => false, 'error' => 'TOKEN_INVALID'], 422);
    } catch (InvalidArgumentException $error) {
        json_response(['ok' => false, 'error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        brvtalBuyerAuthLogFailure('BUYER_TOKEN_ACTION_FAILED', $error);
        json_response(['ok' => false, 'error' => 'BUYER_AUTH_UNAVAILABLE'], 503);
    }
}
if ($action === 'request-reset' || $action === 'resend-verification') {
    try {
        brvtalBuyerAuthRequestForAction($pdo, $body['email'] ?? null, $action);
    } catch (Throwable $error) {
        // Preserve neutral public responses even for unknown addresses.
        brvtalBuyerAuthLogFailure('BUYER_MAIL_REQUEST_FAILED', $error);
    }
    json_response(['ok' => true], 202);
}

$email = brvtalBuyerEmail($body['email'] ?? null);
$password = $body['password'] ?? null;
if ($email === null || !is_string($password)) {
    json_response(['ok' => false, 'error' => 'INVALID_CREDENTIALS'], 401);
}
$limit = brvtalBuyerThrottle($email);
if ($limit['limited']) {
    json_response(['ok' => false, 'error' => 'RATE_LIMITED'], 429,
        ['Retry-After' => (string)max(1, (int)$limit['retry_after'])]);
}
try {
    $q = $pdo->prepare('SELECT id,password_hash,email_verified_at,status,security_epoch
        FROM ticket_buyers WHERE email=? LIMIT 1');
    $q->execute([$email]);
    $buyer = $q->fetch(PDO::FETCH_ASSOC);
    $dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $storedHash = is_array($buyer) && is_string($buyer['password_hash'] ?? null)
        ? $buyer['password_hash'] : $dummyHash;
    $passwordMatches = password_verify($password, $storedHash);
    $known = is_array($buyer) && $passwordMatches && $buyer['status'] === 'active';
    if (!$known) {
        brvtalBuyerThrottle($email, true);
        json_response(['ok' => false, 'error' => 'INVALID_CREDENTIALS'], 401);
    }
    if (empty($buyer['email_verified_at'])) {
        json_response(['ok' => false, 'error' => 'EMAIL_NOT_VERIFIED',
            'can_resend' => true], 403);
    }
    brvtalBuyerLoginSession((int)$buyer['id']);
    brvtal_password_rate_limit_reset('buyer|' . $email, __DIR__ . '/../../storage/buyer_rate_limits');
    json_response(['ok' => true, 'csrf' => $_SESSION['buyer_csrf']]);
} catch (Throwable $error) {
    brvtalBuyerAuthLogFailure('BUYER_LOGIN_FAILED', $error);
    json_response(['ok' => false, 'error' => 'BUYER_AUTH_UNAVAILABLE'], 503);
}
