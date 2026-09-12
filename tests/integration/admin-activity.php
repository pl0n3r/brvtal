<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/admin_activity.php';

function activity_it_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ADMIN ACTIVITY INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL Admin Activity integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
activity_it_expect((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database name must start with brvtal_test');

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    $dbName
);
$pdo = new PDO($dsn, (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'), (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('DROP TABLE IF EXISTS admin_activity_log');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT 'BRVTAL Admin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$migration = (string)file_get_contents(__DIR__ . '/../../database/migration_admin_activity_01.sql');
activity_it_expect($migration !== '', 'activity migration must be readable');
$pdo->exec($migration);
$pdo->exec($migration);
activity_it_expect(brvtal_activity_schema_ready($pdo), 'migration must be idempotent and create the activity table');

$adminId = 900001;
$pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$adminId]);
$pdo->prepare('INSERT INTO admins(id,email,password_hash,name,is_active) VALUES(?,?,?,?,1)')->execute([
    $adminId,
    'activity-ci@brvtal.test',
    password_hash('not-a-real-password', PASSWORD_DEFAULT),
    'Activity CI Admin',
]);

brvtal_admin_session_start();
$_SESSION['admin_id'] = $adminId;

$before = [
    'id'=>77,
    'title'=>'GENESIS',
    'description'=>'Old copy',
    'status'=>'draft',
    'password'=>'must-not-be-stored',
];
$after = [
    'id'=>77,
    'title'=>'GENESIS',
    'description'=>'New copy',
    'status'=>'published',
    'password'=>'must-not-be-stored',
];

$activityId = brvtal_activity_record($pdo, 'update', 'events', 77, $before, $after, [
    'source'=>'integration',
    'api_token'=>'must-not-be-stored',
    'nested'=>['secret'=>'must-not-be-stored','safe'=>'kept'],
]);
activity_it_expect(is_int($activityId) && $activityId > 0, 'activity insert must return an id');

$row = $pdo->query('SELECT * FROM admin_activity_log WHERE id=' . (int)$activityId)->fetch();
activity_it_expect(is_array($row), 'activity row must persist');
activity_it_expect((int)$row['admin_id'] === $adminId, 'activity row must store the authenticated admin id');
activity_it_expect($row['admin_name'] === 'Activity CI Admin', 'activity row must snapshot admin name');
activity_it_expect($row['admin_email'] === 'activity-ci@brvtal.test', 'activity row must snapshot admin email');
activity_it_expect($row['resource'] === 'events' && (int)$row['resource_id'] === 77, 'activity row must identify the changed resource');
activity_it_expect($row['resource_label'] === 'GENESIS', 'activity row must preserve a readable resource label');

$changed = json_decode((string)$row['changed_fields'], true);
activity_it_expect($changed === ['description','status'], 'changed fields must be deterministic and exclude id');
$beforeJson = (string)$row['before_json'];
$afterJson = (string)$row['after_json'];
$metaJson = (string)$row['meta_json'];
activity_it_expect(!str_contains($beforeJson, 'must-not-be-stored') && !str_contains($afterJson, 'must-not-be-stored'), 'snapshot allowlists must exclude password data');
activity_it_expect(!str_contains($metaJson, 'must-not-be-stored'), 'metadata sanitizer must remove tokens and secrets recursively');
activity_it_expect(str_contains($metaJson, '"safe":"kept"'), 'non-sensitive metadata must remain available');
activity_it_expect((bool)preg_match('/^[a-f0-9]{32}$/', (string)$row['request_id']), 'request id must be a 32-character hex correlation id');

$noop = brvtal_activity_record($pdo, 'update', 'events', 77, $after, $after, ['source'=>'integration']);
activity_it_expect($noop === null, 'no-op updates must not create audit noise');
$count = (int)$pdo->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn();
activity_it_expect($count === 1, 'no-op update must not add a second row');

$pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$adminId]);
$preserved = $pdo->query('SELECT admin_id,admin_name,admin_email FROM admin_activity_log WHERE id=' . (int)$activityId)->fetch();
activity_it_expect($preserved['admin_id'] === null, 'deleting an admin must null the FK instead of deleting history');
activity_it_expect($preserved['admin_name'] === 'Activity CI Admin' && $preserved['admin_email'] === 'activity-ci@brvtal.test', 'actor snapshot must survive admin deletion');

$pdo->exec('DROP TABLE admin_activity_log');

echo "BRVTAL Admin Activity MariaDB integration tests passed.\n";
