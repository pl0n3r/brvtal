<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/seo_persistence.php';

function seo_persistence_it_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SEO PERSISTENCE INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL SEO persistence integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
seo_persistence_it_expect(
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
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);

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
$pdo->exec((string)file_get_contents(__DIR__ . '/../../database/migration_admin_activity_01.sql'));

$pdo->exec("CREATE TEMPORARY TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    seo_title VARCHAR(190) NULL,
    seo_description VARCHAR(320) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->prepare('INSERT INTO events(title,description,seo_title,seo_description) VALUES(?,?,NULL,NULL)')
    ->execute(['Dynamic Event','Dynamic event description']);
$eventId = (int)$pdo->lastInsertId();

$adminId = 900214;
$pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$adminId]);
$pdo->prepare('INSERT INTO admins(id,email,password_hash,name,is_active) VALUES(?,?,?,?,1)')->execute([
    $adminId,
    'seo-persistence-ci@brvtal.test',
    password_hash('not-a-real-password', PASSWORD_DEFAULT),
    'SEO Persistence CI',
]);
brvtal_admin_session_start();
$_SESSION['admin_id'] = $adminId;

$definition = ['table'=>'events','title'=>'title','description'=>'description'];

$automatic = brvtalSeoPersistOverrides($pdo,'events',$eventId,$definition,[
    'seo_title'=>'',
    'seo_description'=>'',
]);
seo_persistence_it_expect($automatic['seo_title'] === null, 'blank title must remain NULL');
seo_persistence_it_expect($automatic['seo_description'] === null, 'blank description must remain NULL');
seo_persistence_it_expect(
    (int)$pdo->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn() === 0,
    'saving an already-automatic SEO state must not create audit noise'
);

$manual = brvtalSeoPersistOverrides($pdo,'events',$eventId,$definition,[
    'seo_title'=>'Manual search title',
    'seo_description'=>'Manual search description',
]);
seo_persistence_it_expect($manual['seo_title'] === 'Manual search title', 'manual title must persist');
seo_persistence_it_expect($manual['seo_description'] === 'Manual search description', 'manual description must persist');
$row = $pdo->query('SELECT seo_title,seo_description FROM events WHERE id=' . $eventId)->fetch();
seo_persistence_it_expect($row['seo_title'] === 'Manual search title', 'stored manual title must match');
seo_persistence_it_expect($row['seo_description'] === 'Manual search description', 'stored manual description must match');

$cleared = brvtalSeoPersistOverrides($pdo,'events',$eventId,$definition,[
    'seo_title'=>'   ',
    'seo_description'=>'',
]);
seo_persistence_it_expect($cleared['seo_title'] === null, 'clearing title must restore NULL');
seo_persistence_it_expect($cleared['seo_description'] === null, 'clearing description must restore NULL');
$row = $pdo->query('SELECT seo_title,seo_description FROM events WHERE id=' . $eventId)->fetch();
seo_persistence_it_expect($row['seo_title'] === null, 'database title must be NULL after clear');
seo_persistence_it_expect($row['seo_description'] === null, 'database description must be NULL after clear');

$activity = $pdo->query(
    "SELECT action,resource,resource_id,changed_fields,before_json,after_json,meta_json
     FROM admin_activity_log ORDER BY id ASC"
)->fetchAll();
seo_persistence_it_expect(count($activity) === 2, 'manual set and clear must each create one activity record');
seo_persistence_it_expect(
    $activity[0]['action'] === 'seo_update'
    && $activity[0]['resource'] === 'events'
    && (int)$activity[0]['resource_id'] === $eventId,
    'manual set activity must identify the Event'
);
seo_persistence_it_expect(
    json_decode((string)$activity[1]['changed_fields'], true) === ['seo_description','seo_title'],
    'clear activity must record both changed SEO fields'
);
$beforeClear = json_decode((string)$activity[1]['before_json'], true);
$afterClear = json_decode((string)$activity[1]['after_json'], true);
seo_persistence_it_expect(
    ($beforeClear['seo_title'] ?? null) === 'Manual search title'
    && ($beforeClear['seo_description'] ?? null) === 'Manual search description',
    'clear activity must preserve the explicit previous overrides'
);
seo_persistence_it_expect(
    array_key_exists('seo_title',$afterClear)
    && $afterClear['seo_title'] === null
    && array_key_exists('seo_description',$afterClear)
    && $afterClear['seo_description'] === null,
    'clear activity must record NULL automatic state instead of a fallback value'
);
seo_persistence_it_expect(
    str_contains((string)$activity[1]['meta_json'],'"source":"seo_metadata"'),
    'SEO activity must retain its source marker'
);

$pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$adminId]);
$pdo->exec('DROP TABLE admin_activity_log');

echo "BRVTAL SEO persistence MariaDB integration passed.\n";
