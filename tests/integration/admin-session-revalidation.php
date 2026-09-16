<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/admin_session_revalidation.php';

function admin_session_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("ADMIN SESSION REVALIDATION INTEGRATION FAILED: {$message}");
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL admin session revalidation integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
admin_session_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $baseDb), 'test database name must start with brvtal_test');

$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$dbName = $baseDb . '_admin_session_' . bin2hex(random_bytes(4));
admin_session_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'scratch database name must stay inside test namespace');

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$cleanup = static function () use ($server, $dbName): void {
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    } catch (Throwable) {
    }
};
register_shutdown_function($cleanup);

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName),
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$pdo->exec(<<<'SQL'
CREATE TABLE admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT 'BRVTAL Admin',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;
SQL);

$insert = $pdo->prepare('INSERT INTO admins(email,password_hash,name,is_active) VALUES(?,?,?,1)');
$insert->execute(['session-ci@brvtal.test', password_hash('test-only', PASSWORD_DEFAULT), 'Session CI']);
$adminId = (int)$pdo->lastInsertId();
admin_session_assert($adminId > 0, 'fixture admin must be created');
admin_session_assert(brvtal_admin_account_is_active($pdo, $adminId), 'active admin must pass revalidation');
admin_session_assert(!brvtal_admin_account_is_active($pdo, 0), 'invalid admin id must fail revalidation');

$pdo->prepare('UPDATE admins SET is_active=0 WHERE id=?')->execute([$adminId]);
admin_session_assert(!brvtal_admin_account_is_active($pdo, $adminId), 'disabled admin must fail revalidation immediately');

$pdo->prepare('UPDATE admins SET is_active=1 WHERE id=?')->execute([$adminId]);
admin_session_assert(brvtal_admin_account_is_active($pdo, $adminId), 'reactivated admin must pass current-state revalidation');

$pdo->prepare('DELETE FROM admins WHERE id=?')->execute([$adminId]);
admin_session_assert(!brvtal_admin_account_is_active($pdo, $adminId), 'deleted admin must fail revalidation');

$cleanup();
echo "BRVTAL admin session revalidation integration passed.\n";
