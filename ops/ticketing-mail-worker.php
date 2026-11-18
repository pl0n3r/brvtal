#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
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
    require_once $root . '/config/ticketing_mail_outbox.php';
    $result = brvtal_ticket_mail_deliver_one($pdo);
    // Deliberately print only a state, never recipient, body, key or token.
    fwrite(STDOUT, json_encode(['state' => $result], JSON_THROW_ON_ERROR) . "\n");
    exit(in_array($result, ['idle', 'accepted'], true) ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "ticket_mail_worker_unavailable\n");
    exit(2);
}
