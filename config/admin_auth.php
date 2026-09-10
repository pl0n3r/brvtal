<?php
declare(strict_types=1);

/**
 * BRVTAL shared DISCADMIN authentication core.
 * Canonical session/CSRF helpers for protected admin endpoints.
 */
require_once __DIR__ . '/bootstrap.php';

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

    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Strict',
        'path' => '/',
    ]);

    session_start();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function brvtal_admin_session_regenerate(): void
{
    brvtal_admin_session_start();
    session_regenerate_id(true);
    $_SESSION['issued_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function brvtal_admin_login_session(int $adminId): void
{
    brvtal_admin_session_regenerate();
    $_SESSION['admin_id'] = $adminId;
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

    if (empty($_SESSION['admin_id'])) {
        return false;
    }

    $now = time();
    $last = (int)($_SESSION['last_activity'] ?? 0);
    $issued = (int)($_SESSION['issued_at'] ?? 0);

    if (($last && ($now - $last) > 28800) || ($issued && ($now - $issued) > 86400)) {
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
