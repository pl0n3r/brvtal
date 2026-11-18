<?php
declare(strict_types=1);

function admin_password_security_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("ADMIN PASSWORD SECURITY INTEGRATION FAILED: {$message}");
    }
}

function admin_password_security_expect(callable $call, string $expected): void
{
    try {
        $call();
    } catch (Throwable $error) {
        admin_password_security_assert(
            $error->getMessage() === $expected,
            "expected {$expected}, got {$error->getMessage()}"
        );
        return;
    }
    throw new RuntimeException("ADMIN PASSWORD SECURITY INTEGRATION FAILED: expected {$expected}");
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL admin password security integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
admin_password_security_assert(
    (bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $baseDb),
    'test database name must start with brvtal_test'
);

$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$dbName = $baseDb . '_admin_password_' . bin2hex(random_bytes(4));
admin_password_security_assert(
    (bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName),
    'scratch database name must stay inside test namespace'
);

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

$configPath = sys_get_temp_dir() . '/brvtal-password-security-' . bin2hex(random_bytes(6)) . '.php';
$config = [
    'app' => ['timezone' => 'America/Bogota'],
    'db' => [
        'host' => $host,
        'port' => $port,
        'name' => $dbName,
        'user' => $user,
        'pass' => $pass,
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'csrf_key' => str_repeat('c', 40),
        'encryption_key' => str_repeat('e', 40),
    ],
];
file_put_contents(
    $configPath,
    "<?php\nreturn " . var_export($config, true) . ";\n"
);

define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = $configPath;
require_once __DIR__ . '/../../config/admin_password_security.php';

$cleanup = static function () use ($server, $dbName, $configPath): void {
    @unlink($configPath);
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
  credential_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1,
  name VARCHAR(120) NOT NULL DEFAULT 'BRVTAL Admin',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  totp_secret_enc TEXT NULL
) ENGINE=InnoDB;

CREATE TABLE admin_password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_password_reset_hash (token_hash),
  KEY idx_admin_password_reset_admin_active (admin_id, consumed_at, revoked_at, expires_at),
  CONSTRAINT fk_admin_password_reset_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE admin_recovery_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  used_at DATETIME NULL,
  CONSTRAINT fk_admin_recovery_code_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;
SQL);

$currentPassword = 'Current-Test-Password-2026!';
$insert = $pdo->prepare(
    'INSERT INTO admins(email,password_hash,name,is_active,totp_enabled,totp_secret_enc) VALUES(?,?,?,?,?,?)'
);
$insert->execute([
    'password-security@brvtal.test',
    password_hash($currentPassword, PASSWORD_DEFAULT),
    'Password Security CI',
    1,
    0,
    null,
]);
$adminId = (int)$pdo->lastInsertId();
admin_password_security_assert($adminId > 0, 'fixture admin must be created');

$_SERVER['REMOTE_ADDR'] = '203.0.113.71';

$resetLimiter = static function (string $scope, string $subject): void {
    $ip = (string)$_SERVER['REMOTE_ADDR'];
    brvtal_password_rate_limit_reset('__' . $scope . '_ip__', null, $ip);
    brvtal_password_rate_limit_reset(
        '__' . $scope . '_subject__' . strtolower(trim($subject)),
        null,
        '__account__'
    );
};

$resetLimiter('password_change', (string)$adminId);
for ($attempt = 0; $attempt < BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES; ++$attempt) {
    admin_password_security_expect(
        static fn() => brvtal_admin_change_password(
            $pdo,
            $adminId,
            'wrong-current-password',
            'Replacement-Test-Password-2026!'
        ),
        'INVALID_CREDENTIALS'
    );
}
admin_password_security_expect(
    static fn() => brvtal_admin_change_password(
        $pdo,
        $adminId,
        'wrong-current-password',
        'Replacement-Test-Password-2026!'
    ),
    'RATE_LIMITED'
);

$invalidToken = 'integration-invalid-reset-token';
$invalidTokenHash = brvtal_password_reset_token_hash($invalidToken);
$resetLimiter('password_reset', $invalidTokenHash);
for ($attempt = 0; $attempt < BRVTAL_PASSWORD_RATE_LIMIT_MAX_FAILURES; ++$attempt) {
    admin_password_security_expect(
        static fn() => brvtal_password_reset_consume(
            $pdo,
            $invalidToken,
            'Reset-Test-Password-2026!'
        ),
        'RESET_TOKEN_INVALID'
    );
}
admin_password_security_expect(
    static fn() => brvtal_password_reset_consume(
        $pdo,
        $invalidToken,
        'Reset-Test-Password-2026!'
    ),
    'RATE_LIMITED'
);

$resetLimiter('password_reset', brvtal_password_reset_token_hash('placeholder'));
$pdo->prepare('UPDATE admins SET totp_enabled=1,totp_secret_enc=NULL WHERE id=?')->execute([$adminId]);
$totpIssue = brvtal_password_reset_issue($pdo, $adminId);
$resetLimiter('password_reset', brvtal_password_reset_token_hash($totpIssue['token']));
admin_password_security_expect(
    static fn() => brvtal_password_reset_consume(
        $pdo,
        $totpIssue['token'],
        'Totp-Gated-Password-2026!'
    ),
    'SECOND_FACTOR_REQUIRED'
);

$pdo->prepare('UPDATE admins SET totp_enabled=0 WHERE id=?')->execute([$adminId]);
$first = brvtal_password_reset_issue($pdo, $adminId);
$second = brvtal_password_reset_issue($pdo, $adminId);
$firstHash = brvtal_password_reset_token_hash($first['token']);
$secondHash = brvtal_password_reset_token_hash($second['token']);

$lookup = $pdo->prepare(
    'SELECT token_hash,revoked_at,consumed_at FROM admin_password_reset_tokens WHERE token_hash IN (?,?)'
);
$lookup->execute([$firstHash, $secondHash]);
$tokens = [];
foreach ($lookup->fetchAll() as $row) {
    $tokens[(string)$row['token_hash']] = $row;
}
admin_password_security_assert(
    isset($tokens[$firstHash]) && $tokens[$firstHash]['revoked_at'] !== null,
    'reissue must revoke the previous token'
);
admin_password_security_assert(
    isset($tokens[$secondHash])
    && $tokens[$secondHash]['revoked_at'] === null
    && $tokens[$secondHash]['consumed_at'] === null,
    'reissue must leave only the newest token active'
);

$resetLimiter('password_change', (string)$adminId);
$resetLimiter('password_reset', $invalidTokenHash);
$resetLimiter('password_reset', brvtal_password_reset_token_hash($totpIssue['token']));

$cleanup();
echo "BRVTAL admin password security integration passed.\n";
