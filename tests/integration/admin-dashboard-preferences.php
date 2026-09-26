<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/admin_dashboard.php';

function dashboardPrefItAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ADMIN DASHBOARD PREFERENCES INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL admin Dashboard preferences integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
dashboardPrefItAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database must be isolated');
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
        (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
        $dbName
    ),
    (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'),
    (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);
$pdo->exec('DROP TEMPORARY TABLE IF EXISTS settings');
$pdo->exec('CREATE TEMPORARY TABLE settings (setting_key VARCHAR(160) PRIMARY KEY, setting_value LONGTEXT NULL, is_json TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$upsert=$pdo->prepare('INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,1) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_json=1');
$a=brvtalAdminDashboardSettingKey(101);
$b=brvtalAdminDashboardSettingKey(202);
$upsert->execute([$a,json_encode(['modules'=>[['id'=>'activity','width'=>4,'height'=>1,'visible'=>true]]])]);
$upsert->execute([$b,json_encode(['modules'=>[['id'=>'operations','width'=>1,'height'=>2,'visible'=>true]]])]);
$read=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
$load=static function(string $key) use($read): array {$read->execute([$key]);$v=$read->fetchColumn();$d=is_string($v)?json_decode($v,true):null;return is_array($d)?$d:[];};
dashboardPrefItAssert($load($a)['modules'][0]['id']==='activity','admin A layout must persist independently');
dashboardPrefItAssert($load($b)['modules'][0]['id']==='operations','admin B layout must not inherit admin A');
echo "BRVTAL admin Dashboard preference persistence integration tests passed.\n";
