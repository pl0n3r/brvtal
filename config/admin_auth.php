<?php
declare(strict_types=1);

/**
 * BRVTAL shared DISCADMIN authentication core.
 * Canonical session/CSRF helpers for protected admin endpoints.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/admin_session_revalidation.php';

const BRVTAL_ADMIN_IDLE_TIMEOUT = 604800; // 7 days without activity.
const BRVTAL_ADMIN_ABSOLUTE_TIMEOUT = 2592000; // 30 days from login.
const BRVTAL_ADMIN_COOKIE_LIFETIME = 2592000; // 30 days.
const BRVTAL_ADMIN_COOKIE_REFRESH_INTERVAL = 3600; // Refresh at most once per hour.

function brvtal_admin_cookie_secure(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function brvtal_admin_session_start(): void
{
    global $config;

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $name = preg_replace(
        '/[^A-Za-z0-9_-]/',
        '',
        (string)($config['security']['session_name'] ?? 'BRVTAL_ADMIN')
    ) ?: 'BRVTAL_ADMIN';

    session_name($name);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string)BRVTAL_ADMIN_COOKIE_LIFETIME);

    session_set_cookie_params([
        'lifetime' => BRVTAL_ADMIN_COOKIE_LIFETIME,
        'httponly' => true,
        'secure' => brvtal_admin_cookie_secure(),
        'samesite' => 'Strict',
        'path' => '/',
    ]);

    session_start();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function brvtal_admin_release_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

function brvtal_admin_refresh_cookie(bool $force = false): void
{
    brvtal_admin_session_start();

    $now = time();
    $lastRefresh = (int)($_SESSION['cookie_refreshed_at'] ?? 0);
    if (!$force && $lastRefresh && ($now - $lastRefresh) < BRVTAL_ADMIN_COOKIE_REFRESH_INTERVAL) {
        return;
    }

    if (!headers_sent() && session_id() !== '') {
        setcookie(session_name(), session_id(), [
            'expires' => $now + BRVTAL_ADMIN_COOKIE_LIFETIME,
            'path' => '/',
            'secure' => brvtal_admin_cookie_secure(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_SESSION['cookie_refreshed_at'] = $now;
    }
}

function brvtal_admin_session_regenerate(): void
{
    brvtal_admin_session_start();
    session_regenerate_id(true);
    $_SESSION['issued_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    brvtal_admin_refresh_cookie(true);
}

function brvtal_admin_login_session(int $adminId): void
{
    $state = brvtal_admin_account_session_state(db(), $adminId);
    if ($state === null || !$state['is_active']) {
        throw new RuntimeException('ADMIN_SESSION_STATE_UNAVAILABLE');
    }

    brvtal_admin_session_regenerate();
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['credential_epoch'] = $state['credential_epoch'];
    $_SESSION['authenticated_at'] = time();
}

function brvtal_admin_logout(): void
{
    brvtal_admin_session_start();
    $_SESSION = [];

    $params = session_get_cookie_params();
    if (ini_get('session.use_cookies')) {
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => (string)($params['path'] ?? '/'),
                'domain' => (string)($params['domain'] ?? ''),
                'secure' => (bool)($params['secure'] ?? false),
                'httponly' => (bool)($params['httponly'] ?? true),
                'samesite' => (string)($params['samesite'] ?? 'Strict'),
            ]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function brvtal_admin_is_authenticated(): bool
{
    brvtal_admin_session_start();

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    if ($adminId < 1) {
        return false;
    }

    $now = time();
    $last = (int)($_SESSION['last_activity'] ?? 0);
    $issued = (int)($_SESSION['issued_at'] ?? 0);

    if (($last && ($now - $last) > BRVTAL_ADMIN_IDLE_TIMEOUT)
        || ($issued && ($now - $issued) > BRVTAL_ADMIN_ABSOLUTE_TIMEOUT)) {
        brvtal_admin_logout();
        return false;
    }

    try {
        $state = brvtal_admin_account_session_state(db(), $adminId);
    } catch (Throwable $e) {
        brvtal_log('AUTH_REVALIDATION_ERROR', 'Admin session could not be revalidated', [
            'admin_id' => $adminId,
            'class' => get_class($e),
        ]);
        return false;
    }

    $sessionEpoch = (int)($_SESSION['credential_epoch'] ?? 0);
    if ($state === null || !$state['is_active'] || $sessionEpoch < 1
        || !hash_equals((string)$state['credential_epoch'], (string)$sessionEpoch)) {
        brvtal_log('SECURITY', 'Admin session revoked because account or credential epoch changed', [
            'admin_id' => $adminId,
        ]);
        brvtal_admin_logout();
        return false;
    }

    return true;
}

function brvtal_admin_require(): void
{
    if (!brvtal_admin_is_authenticated()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        echo json_encode([
            'ok' => false,
            'error' => 'AUTH_REQUIRED',
        ]);
        exit;
    }

    $_SESSION['last_activity'] = time();
    brvtal_admin_refresh_cookie();

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET') {
        brvtal_admin_release_session();
    }
}

function brvtal_admin_csrf_token(): string
{
    brvtal_admin_session_start();
    return (string)$_SESSION['csrf'];
}

function brvtal_admin_require_csrf(?string $token = null): void
{
    brvtal_admin_session_start();

    $token ??= (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
    $expected = (string)($_SESSION['csrf'] ?? '');

    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        echo json_encode([
            'ok' => false,
            'error' => 'CSRF',
        ]);
        exit;
    }
}
