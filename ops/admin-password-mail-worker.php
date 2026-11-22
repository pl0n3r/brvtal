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
    fwrite(STDERR, "BRVTAL config unavailable\n");
    exit(2);
}
$config = require $configPath;
$GLOBALS['config'] = $config;
$db = $config['db'] ?? [];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    (string)($db['host'] ?? '127.0.0.1'),
    (int)($db['port'] ?? 3306),
    (string)($db['name'] ?? ''),
    (string)($db['charset'] ?? 'utf8mb4')
);
$pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['brvtal_mail_worker_pdo'] = $pdo;
require_once $root . '/config/admin_password_security.php';

$baseUrl = (string)($config['app']['base_url'] ?? 'https://www.brvtal.com.co');
$result = brvtal_password_recovery_deliver_one($pdo, $baseUrl);
fwrite(STDOUT, json_encode(['status' => $result], JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($result === 'retry' ? 1 : 0);
