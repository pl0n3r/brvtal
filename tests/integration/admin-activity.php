<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/admin_activity.php';
require_once __DIR__ . '/../../config/media_integrity.php';

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


$mediaSnapshot = brvtal_activity_snapshot('media', [
    'id' => 12,
    'type' => 'image',
    'title' => 'Hero',
    'file_path' => '/uploads/media/hero.webp',
    'mime_type' => 'image/webp',
    'file_size' => 1024,
    'content_hash' => 'must-not-be-stored',
    'alt_text' => 'Hero',
    'status' => 'published',
]);
activity_it_expect(is_array($mediaSnapshot), 'media snapshot must be supported');
activity_it_expect(($mediaSnapshot['title'] ?? null) === 'Hero', 'media metadata must remain attributable');
activity_it_expect(!array_key_exists('content_hash', $mediaSnapshot), 'media content hashes must not enter activity snapshots');

activity_it_expect(brvtalActivitySettingKeyAuditable('theme.active'), 'theme.active must be auditable');
activity_it_expect(brvtalActivitySettingKeyAuditable('theme.neon'), 'theme definitions must be auditable by key');
activity_it_expect(brvtalActivitySettingKeyAuditable('site.name'), 'safe public settings must be auditable');
foreach ([
    'security.totp_encryption_key',
    'theme.private_key',
    'site.api_token',
    'credential.rotation',
    'password.reset',
] as $sensitiveSetting) {
    activity_it_expect(
        !brvtalActivitySettingKeyAuditable($sensitiveSetting),
        'sensitive setting keys must never be audited: ' . $sensitiveSetting
    );
}

$settingSnapshot = brvtal_activity_snapshot('settings', [
    'setting_key' => 'theme.neon',
    'setting_value' => '{"secret":"must-not-be-stored"}',
    'is_json' => 1,
]);
activity_it_expect($settingSnapshot === ['setting_key' => 'theme.neon', 'is_json' => 1], 'settings snapshot must exclude setting_value');

$themeActivityId = brvtal_activity_record(
    $pdo,
    'setting_update',
    'settings',
    null,
    ['setting_key' => 'theme.neon', 'is_json' => 1, 'setting_value' => 'old-secret'],
    ['setting_key' => 'theme.neon', 'is_json' => 1, 'setting_value' => 'new-secret'],
    [
        'source' => 'theme_studio',
        'setting_key' => 'theme.neon',
        'value_changed' => true,
        'theme_mutation' => true,
        'api_token' => 'must-not-be-stored',
    ],
    'theme.neon'
);
activity_it_expect(is_int($themeActivityId) && $themeActivityId > 0, 'theme setting mutation must create activity');
$themeRow = $pdo->query('SELECT * FROM admin_activity_log WHERE id=' . (int)$themeActivityId)->fetch();
activity_it_expect(is_array($themeRow), 'theme activity row must persist');
activity_it_expect($themeRow['resource'] === 'settings' && $themeRow['action'] === 'setting_update', 'theme activity must be attributable');
activity_it_expect(!str_contains((string)$themeRow['before_json'], 'old-secret'), 'theme before snapshot must not contain setting value');
activity_it_expect(!str_contains((string)$themeRow['after_json'], 'new-secret'), 'theme after snapshot must not contain setting value');
activity_it_expect(!str_contains((string)$themeRow['meta_json'], 'must-not-be-stored'), 'theme metadata must sanitize tokens');


$themeDeleteActivityId = brvtal_activity_record(
    $pdo,
    'setting_delete',
    'settings',
    null,
    ['setting_key' => 'theme.neon', 'is_json' => 1, 'setting_value' => 'delete-secret'],
    null,
    [
        'source' => 'theme_studio',
        'setting_key' => 'theme.neon',
        'theme_mutation' => true,
    ],
    'theme.neon'
);
activity_it_expect(is_int($themeDeleteActivityId) && $themeDeleteActivityId > 0, 'theme delete must create activity');
$themeDeleteRow = $pdo->query('SELECT * FROM admin_activity_log WHERE id=' . (int)$themeDeleteActivityId)->fetch();
activity_it_expect(!str_contains((string)$themeDeleteRow['before_json'], 'delete-secret'), 'theme delete snapshot must not contain setting value');


// Executable rollback regression: a failed audit must roll back the Settings write.
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value LONGTEXT NULL,
    is_json TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$settingKey = 'site.audit_atomicity_ci';
$pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$settingKey]);
$pdo->prepare('INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,0)')
    ->execute([$settingKey, 'before']);
$pdo->exec('DROP TRIGGER IF EXISTS admin_activity_force_fail');
$pdo->exec(<<<'SQL_WRAP'
CREATE TRIGGER admin_activity_force_fail
BEFORE INSERT ON admin_activity_log
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced activity failure'
SQL_WRAP);
$settingsRolledBack = false;
try {
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT is_json FROM settings WHERE setting_key=? LIMIT 1 FOR UPDATE');
    $lock->execute([$settingKey]);
    $beforeSetting = $lock->fetch(PDO::FETCH_ASSOC) ?: ['setting_key'=>$settingKey,'is_json'=>0];
    $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute(['after', $settingKey]);
    brvtal_activity_record(
        $pdo,
        'setting_update',
        'settings',
        null,
        ['setting_key'=>$settingKey,'is_json'=>(int)($beforeSetting['is_json'] ?? 0)],
        ['setting_key'=>$settingKey,'is_json'=>(int)($beforeSetting['is_json'] ?? 0)],
        ['source'=>'integration'],
        $settingKey
    );
    $pdo->commit();
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $settingsRolledBack = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS admin_activity_force_fail');
activity_it_expect($settingsRolledBack, 'forced Settings audit failure must surface');
$settingValue = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
$settingValue->execute([$settingKey]);
activity_it_expect($settingValue->fetchColumn() === 'before', 'Settings mutation must roll back when audit insert fails');
$pdo->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$settingKey]);

// Executable filesystem rollback regressions for Media.
$tmpMediaRoot = sys_get_temp_dir() . '/brvtal-activity-media-' . bin2hex(random_bytes(5));
@mkdir($tmpMediaRoot, 0700, true);
$publicFile = $tmpMediaRoot . '/public-upload.bin';
file_put_contents($publicFile, 'fixture');
$recoveryRoot = $tmpMediaRoot . '/private-recovery';
$cleanup = brvtal_media_cleanup_failed_upload(
    $publicFile,
    $recoveryRoot,
    static fn(string $path): bool => false,
    static fn(string $source, string $target): bool => @rename($source, $target)
);
activity_it_expect(($cleanup['quarantined'] ?? false) === true, 'failed upload cleanup must quarantine when unlink fails');
activity_it_expect(!is_file($publicFile), 'quarantined failed upload must leave no public orphan');
activity_it_expect(is_file((string)($cleanup['recovery_path'] ?? '')), 'quarantined upload must retain a recoverable private path');

$restoreSource = $tmpMediaRoot . '/restore-target.bin';
$trashDir = $tmpMediaRoot . '/trash';
@mkdir($trashDir, 0700, true);
$restorePrivate = $trashDir . '/staged.bin';
file_put_contents($restorePrivate, 'staged');
$stage = [
    'ok'=>true,
    'staged'=>[[
        'label'=>'fixture',
        'source'=>$restoreSource,
        'private'=>$restorePrivate,
    ]],
    'failed'=>null,
    'trash_dir'=>$trashDir,
];
$failedRestore = brvtal_media_restore_staged_delete(
    $stage,
    static fn(string $source, string $target): bool => false
);
activity_it_expect(count($failedRestore['restore_failed'] ?? []) === 1, 'failed staged restore must be reported');
activity_it_expect(is_file($restorePrivate), 'failed staged restore must retain the private file for recovery');

$successfulRestore = brvtal_media_restore_staged_delete(
    $stage,
    static fn(string $source, string $target): bool => @rename($source, $target)
);
activity_it_expect(($successfulRestore['restore_failed'] ?? []) === [], 'successful staged restore must clear recovery debt');
activity_it_expect(is_file($restoreSource), 'successful staged restore must restore the public source path');

foreach (glob($recoveryRoot . '/*') ?: [] as $recoveryFile) {
    @unlink($recoveryFile);
}
@unlink($restoreSource);
@rmdir($recoveryRoot);
@rmdir($trashDir);
@rmdir($tmpMediaRoot);


// Cursor pagination must remain stable if newer audit rows arrive between pages.
$paginationAdminId = 900002;
$pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$paginationAdminId]);
$pdo->prepare('INSERT INTO admins(id,email,password_hash,name,is_active) VALUES(?,?,?,?,1)')->execute([
    $paginationAdminId,
    'pagination-ci@brvtal.test',
    password_hash('not-a-real-password', PASSWORD_DEFAULT),
    'Pagination CI Admin',
]);
$seed = $pdo->prepare(
    'INSERT INTO admin_activity_log(admin_id,admin_name,admin_email,action,resource,resource_id,resource_label,changed_fields,before_json,after_json,meta_json,request_id) '
    . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
);
for ($index = 1; $index <= 7; ++$index) {
    $seed->execute([
        $paginationAdminId,
        'Pagination CI Admin',
        'pagination-ci@brvtal.test',
        'update',
        'events',
        991,
        'Cursor fixture',
        '["description"]',
        json_encode(['description'=>'before-' . $index], JSON_THROW_ON_ERROR),
        json_encode(['description'=>'after-' . $index], JSON_THROW_ON_ERROR),
        '{"source":"pagination"}',
        str_pad(dechex($index), 32, '0', STR_PAD_LEFT),
    ]);
}
$firstPage = brvtal_activity_page(
    $pdo,
    ['resource'=>'events','resource_id'=>991,'action'=>'update','admin_id'=>$paginationAdminId],
    3,
    null,
    true
);
activity_it_expect($firstPage['total'] === 7, 'first cursor page must report filtered total without cursor');
activity_it_expect($firstPage['returned'] === 3 && $firstPage['has_more'] === true, 'first cursor page must return limit rows plus continuation');
activity_it_expect(is_int($firstPage['next_cursor']) && $firstPage['next_cursor'] > 0, 'first cursor page must expose next cursor');
$firstIds = array_map(static fn(array $row): int => (int)$row['id'], $firstPage['items']);
$sortedFirstIds = $firstIds;
rsort($sortedFirstIds, SORT_NUMERIC);
activity_it_expect($firstIds === $sortedFirstIds, 'cursor page must be strictly descending by id');
activity_it_expect(array_key_exists('before_json', $firstPage['items'][0]) && array_key_exists('after_json', $firstPage['items'][0]), 'history pages must preserve before/after snapshots');

$seed->execute([
    $paginationAdminId,
    'Pagination CI Admin',
    'pagination-ci@brvtal.test',
    'update',
    'events',
    991,
    'Inserted after page one',
    '["description"]',
    '{"description":"new-before"}',
    '{"description":"new-after"}',
    '{"source":"pagination"}',
    str_repeat('f', 32),
]);
$secondPage = brvtal_activity_page(
    $pdo,
    ['resource'=>'events','resource_id'=>991,'action'=>'update','admin_id'=>$paginationAdminId],
    3,
    $firstPage['next_cursor'],
    true
);
$secondIds = array_map(static fn(array $row): int => (int)$row['id'], $secondPage['items']);
activity_it_expect(array_intersect($firstIds, $secondIds) === [], 'cursor pages must never overlap');
activity_it_expect(max($secondIds) < min($firstIds), 'new rows inserted between pages must not shift the cursor window');
activity_it_expect($secondPage['total'] === 8, 'filtered total must remain independent from cursor position');
activity_it_expect(count($secondIds) === 3, 'second cursor page must continue through older records');
$pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$paginationAdminId]);

$pdo->exec('DROP TABLE admin_activity_log');

echo "BRVTAL Admin Activity MariaDB integration tests passed.\n";
