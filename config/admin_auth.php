<?php
declare(strict_types=1);

/**
 * BRVTAL shared DISCADMIN authentication.
 * Used by protected diagnostic/admin endpoints.
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
}

function brvtal_admin_require(): void
{
    brvtal_admin_session_start();

    $now = time();
    $last = (int)($_SESSION['last_activity'] ?? 0);
    $issued = (int)($_SESSION['issued_at'] ?? 0);

    if (
        empty($_SESSION['admin_id']) ||
        ($last && ($now - $last) > 28800) ||
        ($issued && ($now - $issued) > 86400)
    ) {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        echo json_encode([
            'ok' => false,
            'error' => 'AUTH_REQUIRED',
        ]);

        exit;
    }

    $_SESSION['last_activity'] = $now;
}
