#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}


// The CLI worker owns the database connection. Reuse it for encrypted secrets:
// the web bootstrap's db() is not otherwise available in a standalone process.
if (!function_exists('db')) {
    function db(): PDO
    {
        $pdo = $GLOBALS['brvtal_mail_worker_pdo'] ?? null;
        if (!$pdo instanceof PDO) throw new RuntimeException('MAIL_WORKER_DB_UNAVAILABLE');
        return $pdo;
    }
}

$root = dirname(__DIR__);
$configPath = $root . '/config/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "ticket_mail_config_unavailable\n");
    exit(2);
}
$config = require $configPath;
if (!is_array($config) || !is_array($config['db'] ?? null)) {
    fwrite(STDERR, "ticket_mail_config_invalid\n");
    exit(2);
}
$GLOBALS['config'] = $config;
require_once $root . '/config/integration_secrets_store.php';
if (brvtalIntegrationSecretMasterKey($config) === null) {
    fwrite(STDERR, "ticket_mail_secret_unavailable\n");
    exit(2);
}
$d = $config['db'];
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $d['host'] ?? '127.0.0.1', (int)($d['port'] ?? 3306),
            $d['name'] ?? '', $d['charset'] ?? 'utf8mb4'),
        (string)($d['user'] ?? ''), (string)($d['pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    $GLOBALS['brvtal_mail_worker_pdo'] = $pdo;
    require_once $root . '/config/secrets.php';
    $mode = brvtal_secret_value('BRVTAL_TICKET_MAIL_MODE', null, $config, $pdo);
    if (in_array($mode, ['live', 'mock'], true)) {
        // A missing or undecryptable stored sender is a configuration error,
        // never silently replaced with a blank provider credential.
        foreach (['BRVTAL_TICKET_MAIL_API_KEY', 'BRVTAL_TICKET_MAIL_DOMAIN', 'BRVTAL_TICKET_MAIL_FROM'] as $key) {
            if (brvtal_secret_value($key, null, $config, $pdo) === '') {
                fwrite(STDERR, "ticket_mail_secret_unavailable\n");
                exit(2);
            }
        }
    }
    require_once $root . '/config/ticketing_mail_outbox.php';
    // #1952: confirm one payment whose webhook was lost by asking Wompi directly.
    require_once $root . '/config/ticketing_wompi_reconcile.php';
    try {
        $reconcile = brvtal_wompi_reconcile_next($pdo);
    } catch (Throwable) {
        $reconcile = 'unavailable';
    }
    // #1945: issue one paid order first, so its QR email is delivered on this run.
    require_once $root . '/config/ticketing_issue_delivery.php';
    try {
        $issue = brvtal_ticket_issue_process_next($pdo);
    } catch (Throwable) {
        $issue = 'unavailable';
    }
    $result = brvtal_ticket_mail_deliver_one($pdo);
    // Deliberately print only a state, never recipient, body, key or token.
    $output = ['reconcile' => $reconcile, 'issue' => $issue, 'state' => $result];
    // #1956: the last issuance failure stays visible until an issuance succeeds.
    $last = function_exists('brvtal_ticket_issue_last_failure') ? brvtal_ticket_issue_last_failure() : null;
    if ($last !== null) $output['issue_last'] = $last['code'] . '@' . $last['at'];
    fwrite(STDOUT, json_encode($output, JSON_THROW_ON_ERROR) . "\n");
    exit(in_array($result, ['idle', 'accepted'], true) ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "ticket_mail_worker_unavailable\n");
    exit(2);
}
