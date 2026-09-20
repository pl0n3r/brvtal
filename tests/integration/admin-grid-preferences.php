<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_grid.php';

function gridPrefItAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ADMIN GRID PREFERENCES INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL admin-grid preferences integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
gridPrefItAssert(
    (bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName),
    'test database name must start with brvtal_test'
);

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    $dbName
);
$pdo = new PDO(
    $dsn,
    (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'),
    (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$pdo->exec('DROP TEMPORARY TABLE IF EXISTS settings');
$pdo->exec(
    'CREATE TEMPORARY TABLE settings ('
    . 'setting_key VARCHAR(120) PRIMARY KEY,'
    . 'setting_value LONGTEXT NULL,'
    . 'is_json TINYINT(1) NOT NULL DEFAULT 0'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$keys = [
    'admin_a_events' => brvtalAdminGridSettingKey(101, 'events'),
    'admin_b_events' => brvtalAdminGridSettingKey(202, 'events'),
    'admin_a_blog' => brvtalAdminGridSettingKey(101, 'blog'),
];

$upsert = $pdo->prepare(
    'INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,1) '
    . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_json=1'
);
$upsert->execute([$keys['admin_a_events'], json_encode(['columns'=>['primary','status']])]);
$upsert->execute([$keys['admin_b_events'], json_encode(['columns'=>['primary','date','location']])]);
$upsert->execute([$keys['admin_a_blog'], json_encode(['columns'=>['primary','published']])]);

$read = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
$load = static function (string $key) use ($read): array {
    $read->execute([$key]);
    $raw = $read->fetchColumn();
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
};

gridPrefItAssert(
    $load($keys['admin_a_events'])['columns'] === ['primary','status'],
    'admin A Events preferences must persist independently'
);
gridPrefItAssert(
    $load($keys['admin_b_events'])['columns'] === ['primary','date','location'],
    'admin B Events preferences must not inherit admin A preferences'
);
gridPrefItAssert(
    $load($keys['admin_a_blog'])['columns'] === ['primary','published'],
    'the same administrator must have module-isolated preferences'
);

$upsert->execute([$keys['admin_a_events'], json_encode(['columns'=>['primary','date']])]);
gridPrefItAssert(
    $load($keys['admin_a_events'])['columns'] === ['primary','date'],
    'upsert must update exactly one admin/module preference'
);
gridPrefItAssert(
    $load($keys['admin_b_events'])['columns'] === ['primary','date','location'],
    'updating admin A must preserve admin B'
);
gridPrefItAssert(
    $load($keys['admin_a_blog'])['columns'] === ['primary','published'],
    'updating Events must preserve Blog preferences'
);

echo "BRVTAL admin-grid preference persistence integration tests passed.\n";
