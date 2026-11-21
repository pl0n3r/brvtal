<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/secrets.php';

brvtal_admin_require();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    json_response(['ok'=>false, 'error'=>'METHOD_NOT_ALLOWED'], 405);
}

try {
    $snapshot = brvtal_provisioning_snapshot();
    // The runbook configures cron output to these private, append-only logs.
    // A timestamp means a log write was observed, not that delivery succeeded.
    $files = [
        'ticketing-mail' => dirname(__DIR__) . '/storage/logs/ticketing-mail-worker.log',
        'admin-password-mail' => dirname(__DIR__) . '/storage/logs/admin-password-mail-worker.log',
    ];
    foreach ($snapshot['workers'] as &$worker) {
        $path = $files[$worker['id']] ?? '';
        $modified = $path !== '' && is_file($path) ? @filemtime($path) : false;
        $worker['last_execution'] = is_int($modified) && $modified > 0
            ? gmdate(DATE_ATOM, $modified) : null;
    }
    unset($worker);
    json_response(['ok'=>true] + $snapshot);
} catch (Throwable) {
    // Never serialize exception messages: config values may appear in exceptions.
    json_response(['ok'=>false, 'error'=>'STATUS_UNAVAILABLE'], 503);
}
