<?php
declare(strict_types=1);

// The harness must fail closed if a future dependency calls exit(0) before
// running AC-01 (e.g. an unexpected admin bootstrap without config.php).
if (getenv('BRVTAL_INTEGRATION_TESTS') === '1') {
    $GLOBALS['brvtal_buyer_auth_suite_completed'] = false;
    register_shutdown_function(static function (): void {
        if (($GLOBALS['brvtal_buyer_auth_suite_completed'] ?? false) !== true) {
            fwrite(STDERR, "BUYER_AUTH_SUITE_EARLY_EXIT\n");
            exit(1);
        }
    });
}
require_once __DIR__ . '/../../config/buyer_auth_flows.php';

function buyerAuthAssert(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException('BUYER AUTH CONTRACT FAILED: ' . $reason);
}

/**
 * #1909: isolated real HTTP requests, disposable MariaDB and a throwaway log.
 * No production keys, external mail or persistent test data are involved.
 */
function buyerAuthMissingMailKeyHttpCheck(
    PDO $db, string $dbName, string $host, int $port, string $dbUser, string $dbPass,
    array $input, ?array $resetScenario = null
): void {
    buyerAuthAssert(function_exists('proc_open') && function_exists('stream_socket_server'),
        'HTTP integration requires a local PHP server');
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/brvtal-buyer-1909-' . bin2hex(random_bytes(6));
    buyerAuthAssert(mkdir($directory, 0700), 'create disposable HTTP fixture directory');
    $process = null;
    $pipes = [];
    try {
        $configPath = $directory . '/config.php';
        $prependPath = $directory . '/prepend.php';
        $loggerPath = $directory . '/brvtal.log';
        $serverLog = $directory . '/server.log';
        $fixture = [
            'app' => ['timezone' => 'America/Bogota', 'debug' => false],
            'db' => [
                'host' => $host, 'port' => $port, 'name' => $dbName,
                'user' => $dbUser, 'pass' => $dbPass, 'charset' => 'utf8mb4',
            ],
            'security' => [
                'csrf_key' => str_repeat('brvtal-1909-synthetic-csrf-', 3),
                // encryption_key deliberately absent.
            ],
            'ticketing' => ['mail' => [
                'testing' => '1', 'privacy_approved' => '1', 'mode' => 'disabled',
            ]],
        ];
        buyerAuthAssert(file_put_contents($configPath,
            '<?php return ' . var_export($fixture, true) . ';') !== false,
            'write temporary synthetic HTTP config');
        chmod($configPath, 0600);
        $prepend = '<?php define("BRVTAL_SENTRY_TESTING", true); '
            . '$GLOBALS["brvtalBootstrapConfigPath"] = ' . var_export($configPath, true) . '; '
            . '$GLOBALS["brvtalSentryTestLogFile"] = ' . var_export($loggerPath, true) . ';';
        buyerAuthAssert(file_put_contents($prependPath, $prepend) !== false,
            'write temporary logger redirect');
        chmod($prependPath, 0600);

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNo, $errorString);
        buyerAuthAssert($listener !== false, 'allocate local-only HTTP port');
        $bound = stream_socket_get_name($listener, false);
        fclose($listener);
        $listenPort = (int) substr(strrchr((string) $bound, ':'), 1);
        buyerAuthAssert($listenPort > 0, 'local HTTP port valid');

        $environment = (array) getenv();
        $environment['BRVTAL_TICKET_MAIL_TESTING'] = '1';
        $environment['BRVTAL_TICKET_MAIL_PRIVACY_APPROVED'] = '1';
        $environment['BRVTAL_TICKET_MAIL_MODE'] = 'disabled';
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=' . $prependPath,
            '-S', '127.0.0.1:' . $listenPort, '-t', $root];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $serverLog, 'a'],
            2 => ['file', $serverLog, 'a'],
        ], $pipes, $root, $environment);
        buyerAuthAssert(is_resource($process), 'start local-only PHP HTTP server');
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $url = 'http://127.0.0.1:' . $listenPort;
        $send = static function (string $path, string $method = 'GET',
            ?array $data = null, array $headers = []) use ($url): array {
            $requestHeaders = array_merge(['Connection: close'], $headers);
            $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 8];
            if ($data !== null) {
                $requestHeaders[] = 'Content-Type: application/json';
                $options['content'] = json_encode($data, JSON_THROW_ON_ERROR);
            }
            $options['header'] = implode("\r\n", $requestHeaders);
            $context = stream_context_create(['http' => $options]);
            $http_response_header = [];
            $response = @file_get_contents($url . $path, false, $context);
            $receivedHeaders = $http_response_header;
            preg_match('~^HTTP/\S+ ([0-9]{3})~', (string) ($receivedHeaders[0] ?? ''), $matches);
            return [
                'status' => (int) ($matches[1] ?? 0),
                'json' => is_string($response) ? json_decode($response, true) : null,
                'headers' => $receivedHeaders,
            ];
        };
        $session = null;
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            $response = $send('/api/buyer/auth.php');
            if ($response['status'] === 200) {
                $session = $response;
                break;
            }
            usleep(100_000);
        }
        buyerAuthAssert(is_array($session) && is_array($session['json'] ?? null),
            'session + CSRF HTTP handshake');
        $csrf = $session['json']['csrf'] ?? null;
        buyerAuthAssert(is_string($csrf) && strlen($csrf) > 20,
            'session contains a nontrivial CSRF token');
        $cookie = null;
        foreach ($session['headers'] as $header) {
            if (stripos($header, 'Set-Cookie: BRVTAL_BUYER=') === 0) {
                $cookie = explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
                break;
            }
        }
        buyerAuthAssert(is_string($cookie), 'buyer cookie retained');

        if ($resetScenario !== null) {
            $cookieFrom = static function (array $response): string {
                foreach ($response['headers'] as $header) {
                    if (stripos($header, 'Set-Cookie: BRVTAL_BUYER=') === 0) {
                        return explode(';', substr($header, strlen('Set-Cookie: ')), 2)[0];
                    }
                }
                throw new RuntimeException('expected rotated buyer cookie');
            };
            $login = $send('/api/buyer/auth.php', 'POST',
                ['action'=>'login','email'=>$input['email'],'password'=>$input['password']],
                ['Cookie: '.$cookie,'X-CSRF-Token: '.$csrf]);
            buyerAuthAssert($login['status'] === 200 && ($login['json']['ok'] ?? false),
                'verified password HTTP login');
            $authenticatedCookie = $cookieFrom($login);
            $authenticatedCsrf = $login['json']['csrf'];
            buyerAuthAssert($authenticatedCookie !== $cookie && $authenticatedCsrf !== $csrf,
                'login rotates cookie and CSRF');
            $beforeReset = $send('/api/buyer/auth.php', 'GET', null, ['Cookie: '.$authenticatedCookie]);
            buyerAuthAssert(($beforeReset['json']['authenticated'] ?? false) === true,
                'persisted cookie authenticates before reset');
            $reset = $send('/api/buyer/auth.php', 'POST',
                ['action'=>'confirm-reset','token'=>$resetScenario['token'],
                    'password'=>$resetScenario['password']],
                ['Cookie: '.$authenticatedCookie,'X-CSRF-Token: '.$authenticatedCsrf]);
            buyerAuthAssert($reset['status'] === 200 && ($reset['json']['ok'] ?? false),
                'actual HTTP reset consumes synthetic token');
            $afterReset = $send('/api/buyer/auth.php', 'GET', null, ['Cookie: '.$authenticatedCookie]);
            buyerAuthAssert($afterReset['status'] === 200
                && ($afterReset['json']['authenticated'] ?? null) === false,
                'old authenticated cookie rejected after reset');
            $freshCookie = $cookieFrom($afterReset);
            $freshCsrf = $afterReset['json']['csrf'];
            $oldLogin = $send('/api/buyer/auth.php', 'POST',
                ['action'=>'login','email'=>$input['email'],'password'=>$input['password']],
                ['Cookie: '.$freshCookie,'X-CSRF-Token: '.$freshCsrf]);
            buyerAuthAssert($oldLogin['status'] === 401, 'old password rejected after reset');
            $newLogin = $send('/api/buyer/auth.php', 'POST',
                ['action'=>'login','email'=>$input['email'],'password'=>$resetScenario['password']],
                ['Cookie: '.$freshCookie,'X-CSRF-Token: '.$freshCsrf]);
            buyerAuthAssert($newLogin['status'] === 200 && ($newLogin['json']['ok'] ?? false),
                'new password authenticates after reset');
            return;
        }

        // Challenge timestamp is already 3 seconds old; no artificial test delay.
        $challenge = brvtal_public_abuse_challenge($fixture, 'register', time() - 3);
        preg_match('/^(\d+) \+ (\d+) = \?$/', $challenge['question'], $answer);
        buyerAuthAssert(count($answer) === 3, 'synthetic challenge was generated');
        $payload = $input;
        $payload['action'] = 'register';
        $payload['website'] = '';
        $payload['privacy_accepted'] = true;
        $payload['captcha_token'] = $challenge['captcha_token'];
        $payload['captcha_answer'] = (int) $answer[1] + (int) $answer[2];
        $register = $send('/api/buyer/auth.php', 'POST', $payload, [
            'Cookie: ' . $cookie, 'X-CSRF-Token: ' . $csrf,
        ]);
        buyerAuthAssert($register['status'] === 503
            && $register['json'] === ['ok' => false, 'error' => 'BUYER_MAIL_PENDING'],
            'missing mail key keeps the exact public 503 contract');
        $logged = (string) @file_get_contents($loggerPath);
        buyerAuthAssert(str_contains($logged, '[BUYER_REGISTER_FAILED]')
            && str_contains($logged, '"class":"RuntimeException"')
            && str_contains($logged, '"code":"BUYER_MAIL_KEY_UNAVAILABLE"'),
            'internal code and exception class recorded in isolated log');
        buyerAuthAssert(!str_contains($logged, (string) $input['email'])
            && !str_contains($logged, (string) $input['password'])
            && !str_contains($logged, '"trace"')
            && !str_contains($logged, 'captcha_token'),
            'no account PII, password, CAPTCHA bearer or stack traces logged');
        buyerAuthAssert((int) $db->query('SELECT COUNT(*) FROM ticket_buyers')->fetchColumn() === 0
            && (int) $db->query('SELECT COUNT(*) FROM ticket_buyer_tokens')->fetchColumn() === 0
            && (int) $db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === 0,
            'missing key rolls back buyer, token and mail queue atomically');

        $health = $send('/api/health.php');
        buyerAuthAssert(in_array($health['status'], [200, 503], true)
            && is_array($health['json'])
            && ($health['json']['buyer_registration_ready'] ?? null) === false,
            'public health reports only aggregate buyer readiness on missing key');
        $publicPayload = json_encode($health['json'], JSON_THROW_ON_ERROR);
        buyerAuthAssert(!str_contains($publicPayload, 'encryption_key')
            && !str_contains($publicPayload, 'BUYER_MAIL_KEY_UNAVAILABLE')
            && !str_contains($publicPayload, (string) $input['email']),
            'health endpoint does not expose configuration or private reasons');
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) unlink($file);
        }
        @rmdir($directory);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL buyer-auth MariaDB integration skipped (synthetic-only).\n";
    return;
}
$prefix = (string)getenv('BRVTAL_TEST_DB_NAME');
buyerAuthAssert(preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', $prefix) === 1,
    'only disposable test DB names');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$dbName = $prefix . '_buyer_auth_' . bin2hex(random_bytes(4));
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false];
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $options);
$server->exec("CREATE DATABASE " . $dbName . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(static function () use ($server, $dbName): void {
    try { $server->exec("DROP DATABASE IF EXISTS " . $dbName); } catch (Throwable) {}
});
$db = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, $options);
$schema = (string)file_get_contents(__DIR__ . '/../../database/migration_ticketing_orders_01.sql');
$prefixSql = explode('-- Add a composite parent key', $schema, 2)[0];
$prefixSql = preg_replace('/^\s*--[^\n]*$/m', '', $prefixSql);
foreach (array_filter(array_map(trim(...), explode(';', (string)$prefixSql))) as $statement) {
    $db->exec($statement);
}
$db->exec((string)file_get_contents(__DIR__ . '/../../database/migration_buyer_auth_tokens_01.sql'));
$db->exec((string)file_get_contents(__DIR__ . '/../../database/migration_buyer_auth_tokens_01.sql'));
$db->exec((string)file_get_contents(__DIR__ . '/../../database/migration_ticketing_mail_outbox_01.sql'));
$originalConfig = $GLOBALS['config'] ?? null;
$oldMailApproval = getenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED');
$GLOBALS['config'] = [
    'security'=>['encryption_key'=>str_repeat('synthetic-test-key-', 3)],
    'ticketing'=>['mail'=>['testing'=>'0','privacy_approved'=>'0']],
];
putenv('BRVTAL_TICKET_MAIL_TESTING=0');
putenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED=0');
putenv('BRVTAL_TICKET_MAIL_MODE=disabled');
$rateDirectory = sys_get_temp_dir() . '/brvtal-buyer-rate-' . bin2hex(random_bytes(6));
mkdir($rateDirectory, 0700);
register_shutdown_function(static function () use ($rateDirectory): void {
    foreach (glob($rateDirectory . '/*') ?: [] as $file) @unlink($file);
    @rmdir($rateDirectory);
});
$input = ['name'=>'Persona Sintética', 'email'=>'buyer@example.test',
    'password'=>'SynthETIC-9345-safe-Long', 'terms_accepted'=>true,'consent_version'=>'0.1.277'];
buyerAuthMissingMailKeyHttpCheck($db, $dbName, $host, $port, $user, $pass, $input);
$deniedInput = $input;
$deniedInput['email'] = 'privacy-denied@example.test';
try {
    brvtalBuyerAuthRegister($db, $deniedInput);
    buyerAuthAssert(false, 'registration must abort when privacy is not approved');
} catch (RuntimeException $error) {
    buyerAuthAssert($error->getMessage() === 'MAIL_PRIVACY_NOT_APPROVED',
        'private privacy denial remains fail-closed');
}
buyerAuthAssert((int)$db->query('SELECT COUNT(*) FROM ticket_buyers')->fetchColumn() === 0
    && (int)$db->query('SELECT COUNT(*) FROM ticket_buyer_tokens')->fetchColumn() === 0
    && (int)$db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === 0,
    'privacy rejection atomically rolls back buyer, token and outbox');
$GLOBALS['config']['ticketing']['mail']['privacy_approved'] = '1';
$a = brvtalBuyerAuthRegister($db, $input);
// Use private synthetic mode for the rest of the multi-mail test suite.
$GLOBALS['config']['ticketing']['mail']['testing'] = '1';
$b = brvtalBuyerAuthRegister($db, $input);
buyerAuthAssert($a === $b && $a === ['ok'=>true], 'duplicate is neutral');
buyerAuthAssert((int)$db->query('SELECT COUNT(*) FROM ticket_buyers')->fetchColumn() === 1,
    'one buyer after duplicate');
$row = $db->query('SELECT id,full_name,password_hash,email_verified_at,security_epoch FROM ticket_buyers')->fetch();
// Match the 12..4096-character policy without silent bcrypt 72-byte truncation.
if (defined('PASSWORD_ARGON2ID')) {
    $longPassword = str_repeat('SYNTHETIC-secure-4829-', 7);
    $longHash = brvtalBuyerAuthPassword($longPassword);
    buyerAuthAssert(password_verify($longPassword, $longHash)
        && !password_verify(substr($longPassword, 0, 72), $longHash),
        'long password preserves all bytes with Argon2id');
}
buyerAuthAssert($row['full_name'] === $input['name']
    && $row['password_hash'] !== $input['password']
    && password_verify($input['password'], $row['password_hash'])
    && $row['email_verified_at'] === null, 'unverified hashed buyer and profile');
buyerAuthAssert($db->query('SELECT consent_version FROM ticket_buyers')->fetchColumn()
    === BRVTAL_APP_VERSION, 'recorded consent version is canonical server value');
buyerAuthAssert($db->query('SELECT consent_recorded_at FROM ticket_buyers')->fetchColumn() !== null,
    'consent timestamp is server generated');
buyerAuthAssert((int)$db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === 1,
    'one queued verification only');
$sealed = (string)$db->query('SELECT body_text FROM ticket_mail_outbox LIMIT 1')->fetchColumn();
buyerAuthAssert(str_starts_with($sealed, BRVTAL_BUYER_MAIL_SEAL_PREFIX)
    && !str_contains($sealed,'token='), 'no plaintext bearer in outbox');
$mail = brvtalBuyerMailUnseal($sealed);
preg_match('/token=([A-Za-z0-9_-]{43})/', (string)$mail, $matches);
$verifyToken = $matches[1] ?? '';
buyerAuthAssert($verifyToken !== '' && !str_contains($sealed, $verifyToken), 'secure 43-char token in memory');
// The worker unwraps only at the delivery boundary; ciphertext stays in DB.
putenv('BRVTAL_TICKET_MAIL_MODE=mock');
$captured = null;
$delivery = brvtal_ticket_resend_send([
    'recipient_email'=>'buyer@example.test','message_key'=>'buyer:1:verify_account:1',
    'subject'=>'Correo de verificación','body_text'=>$sealed,
], static function (array $payload, array $headers) use (&$captured): array {
    $captured = ['payload'=>$payload,'headers'=>$headers];
    return ['status'=>200,'body'=>'{"id":"buyer-auth-synthetic-1"}'];
});
buyerAuthAssert($delivery['state'] === 'accepted'
    && is_array($captured) && str_contains($captured['payload']['text'], $verifyToken)
    && $captured['headers'][0] === 'Authorization: Bearer ',
    'only synthetic provider receives decoded link in memory');
$corrupt = substr($sealed, 0, -4) . 'AAAA';
$invalid = brvtal_ticket_resend_send([
    'recipient_email'=>'buyer@example.test','message_key'=>'buyer:1:verify_account:1',
    'subject'=>'Correo','body_text'=>$corrupt,
], static fn(): array => ['status'=>200,'body'=>'{"id":"should-not-send"}']);
buyerAuthAssert($invalid['code'] === 'invalid_configuration',
    'tampered sealed message fails closed before provider');
putenv('BRVTAL_TICKET_MAIL_MODE=disabled');
buyerAuthAssert((string)$db->query('SELECT token_hash FROM ticket_buyer_tokens LIMIT 1')->fetchColumn()
    === hash('sha256', $verifyToken), 'only token digest persisted');
$db->prepare('UPDATE ticket_buyer_tokens SET expires_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND)
    WHERE token_hash=?')->execute([hash('sha256',$verifyToken)]);
try {
    brvtalBuyerAuthConsume($db, $verifyToken, 'verify_email');
    buyerAuthAssert(false, 'expired verification rejected');
} catch (DomainException) {}
buyerAuthAssert(brvtalBuyerAuthRequest($db, 'buyer@example.test', 'verify_email', $rateDirectory) === ['ok'=>true],
    'reissue unverified is neutral');
$next = (string)$db->query('SELECT body_text FROM ticket_mail_outbox ORDER BY id DESC LIMIT 1')->fetchColumn();
preg_match('/token=([A-Za-z0-9_-]{43})/', (string)brvtalBuyerMailUnseal($next), $vm);
$valid = $vm[1] ?? '';
buyerAuthAssert($valid !== '' && $valid !== $verifyToken, 'reissued token differs');
brvtalBuyerAuthConsume($db, $valid, 'verify_email');
try {
    brvtalBuyerAuthConsume($db, $valid, 'verify_email');
    buyerAuthAssert(false, 'verification replay rejected');
} catch (DomainException) {}
buyerAuthAssert($db->query('SELECT email_verified_at FROM ticket_buyers')->fetchColumn() !== null,
    'verification persisted');
$neutralKnown = brvtalBuyerAuthRequest($db,'buyer@example.test','reset_password',$rateDirectory);
$neutralUnknown = brvtalBuyerAuthRequest($db,'unknown@example.test','reset_password',$rateDirectory);
buyerAuthAssert($neutralKnown === $neutralUnknown && $neutralKnown === ['ok'=>true],
    'unknown and known reset responses identical');
$resetSealed = (string)$db->query('SELECT body_text FROM ticket_mail_outbox ORDER BY id DESC LIMIT 1')->fetchColumn();
preg_match('/token=([A-Za-z0-9_-]{43})/', (string)brvtalBuyerMailUnseal($resetSealed), $rm);
$resetToken = $rm[1] ?? '';
buyerAuthAssert($resetToken !== '' && !str_contains($resetSealed, $resetToken),
    'reset is sealed at rest');
$db->prepare('INSERT INTO ticket_buyer_sessions
    (buyer_id,session_hash,issued_epoch,expires_at_utc)
    VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR))')
    ->execute([(int)$row['id'],hash('sha256','synthetic-session-id'),(int)$row['security_epoch']]);
$newPassword = 'SynthETIC-new-3849-Strong';
$db->exec("CREATE TABLE ticket_orders (
    id BIGINT UNSIGNED PRIMARY KEY,buyer_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL) ENGINE=InnoDB");
// Connection A verifies the old credential. The HTTP server uses a distinct
// connection B to commit reset before A attempts to persist the stale proof.
$stale = $db->query('SELECT id,password_hash,security_epoch FROM ticket_buyers')->fetch();
buyerAuthAssert(password_verify($input['password'], $stale['password_hash']),
    'old password verified before reset on connection A');
buyerAuthMissingMailKeyHttpCheck($db, $dbName, $host, $port, $user, $pass, $input,
    ['token'=>$resetToken,'password'=>$newPassword]);
$staleHash = hash('sha256','stale-epoch-session');
try {
    brvtalBuyerPersistSession($db, (int)$stale['id'], $staleHash, (int)$stale['security_epoch']);
    buyerAuthAssert(false, 'stale credential must not create a new-epoch session');
} catch (RuntimeException $error) {
    buyerAuthAssert($error->getMessage() === 'BUYER_SESSION_NOT_ELIGIBLE',
        'stale epoch rejected after committed reset');
}
$lookupSession = $db->prepare('SELECT COUNT(*) FROM ticket_buyer_sessions WHERE session_hash=?');
$lookupSession->execute([$staleHash]);
buyerAuthAssert((int)$lookupSession->fetchColumn() === 0 && !$db->inTransaction(),
    'stale attempt rolled back with zero session rows');
$after = $db->query('SELECT password_hash,security_epoch FROM ticket_buyers')->fetch();
buyerAuthAssert(password_verify($newPassword, (string)$after['password_hash'])
    && !password_verify($input['password'], (string)$after['password_hash'])
    && (int)$after['security_epoch'] === (int)$row['security_epoch']+1,
    'reset rotates password and epoch');
buyerAuthAssert($db->query('SELECT revoked_at_utc FROM ticket_buyer_sessions')->fetchColumn() !== null,
    'all prior stored sessions revoked');
$currentEpoch = (int)$after['security_epoch'];
$currentId = (int)$stale['id'];
$sessionHash = hash('sha256','verified-current-session');
brvtalBuyerPersistSession($db, $currentId, $sessionHash, $currentEpoch);
try {
    brvtalBuyerPersistSession($db, $currentId, $sessionHash, $currentEpoch);
    buyerAuthAssert(false, 'duplicate session persistence must fail');
} catch (PDOException) {
    buyerAuthAssert(!$db->inTransaction(), 'persistence failure rolls back transaction');
}
$lookupSession->execute([$sessionHash]);
buyerAuthAssert((int)$lookupSession->fetchColumn() === 1, 'failed insert leaves original row intact');
$db->prepare("UPDATE ticket_buyers SET status='blocked' WHERE id=?")->execute([$currentId]);
try {
    brvtalBuyerPersistSession($db, $currentId, $staleHash, $currentEpoch);
    buyerAuthAssert(false, 'blocked account cannot start session');
} catch (RuntimeException $error) {
    buyerAuthAssert($error->getMessage() === 'BUYER_SESSION_NOT_ELIGIBLE',
        'blocked status rechecked under lock');
}
$db->prepare("UPDATE ticket_buyers SET status='active' WHERE id=?")->execute([$currentId]);
try {
    brvtalBuyerAuthConsume($db,$resetToken,'reset_password','SynthETIC-3494-Reused');
    buyerAuthAssert(false,'reset token replay rejected');
} catch (DomainException) {}
// Flood both a known and unknown mailbox; no response discloses existence.
for ($i=0;$i<8;$i++) {
    buyerAuthAssert(brvtalBuyerAuthRequest($db,'buyer@example.test','reset_password',$rateDirectory)
        === ['ok'=>true], 'rate limited response remains neutral');
}
buyerAuthAssert((int)$db->query("SELECT COUNT(*) FROM ticket_mail_outbox WHERE subject
    LIKE '%Restablece%'")->fetchColumn() <= 6, 'IP and email rate limit bounds mail volume');
buyerAuthAssert((int)$db->query("SELECT COUNT(*) FROM ticket_buyer_tokens WHERE token_hash
    LIKE '%SynthETIC%'")->fetchColumn() === 0, 'raw password never stored as token');

// #1985: guest purchase → one-use password token → verified account.
// Synthetic database and mail only; no external payments or messages.
$db->exec("CREATE TABLE ticket_issue_outbox (
    order_id BIGINT UNSIGNED PRIMARY KEY,state VARCHAR(16) NOT NULL) ENGINE=InnoDB");
$guestBuyer = $db->prepare("INSERT INTO ticket_buyers
    (public_id,email,full_name,status,consent_version,consent_recorded_at)
    VALUES (?,?,?,'guest','checkout-v1',UTC_TIMESTAMP(6))");
$guestBuyer->execute([random_bytes(16),'guest-1985@example.test','Comprador ficticio']);
$guestId=(int)$db->lastInsertId();
$guestBuyer->execute([random_bytes(16),'other-1985@example.test','Otra persona']);
$otherId=(int)$db->lastInsertId();
$db->prepare("INSERT INTO ticket_orders VALUES (198501,?,'paid'),(198502,?,'pending')")
    ->execute([$guestId,$otherId]);
$db->exec("INSERT INTO ticket_issue_outbox VALUES (198501,'done'),(198502,'done')");
try {
    brvtalBuyerAuthGuestPurchaseLink($db,198501);
    buyerAuthAssert(false,'guest purchase must be inside issuance transaction');
} catch (LogicException $ignored) {}
$db->beginTransaction();
buyerAuthAssert(brvtalBuyerAuthGuestPurchaseLink($db,198502)===null,
    'pending order cannot mint account-creation token');
$link=brvtalBuyerAuthGuestPurchaseLink($db,198501);
buyerAuthAssert(is_string($link)
    && str_contains($link,'accion=confirm-reset')
    && str_contains($link,'origen=compra'), 'paid guest gets purchase URL');
parse_str((string)parse_url($link,PHP_URL_QUERY),$guestParams);
$guestToken=(string)($guestParams['token']??'');
buyerAuthAssert(preg_match('/^[A-Za-z0-9_-]{43}$/D',$guestToken)===1,
    'guest token inherits reset strength');
$db->commit();
buyerAuthAssert(
    $db->query("SELECT COUNT(*) FROM ticket_buyer_tokens
        WHERE buyer_id=$guestId AND purpose='reset_password'")->fetchColumn()===1
    && !str_contains((string)$db->query("SELECT token_hash FROM ticket_buyer_tokens
        WHERE buyer_id=$guestId")->fetchColumn(),$guestToken),
    'one hashed reset token, never stored raw');
buyerAuthAssert(brvtalBuyerAuthRequest($db,'other-1985@example.test',
    'reset_password',$rateDirectory)===['ok'=>true], 'guest reset requests are neutral');
buyerAuthAssert((int)$db->query("SELECT COUNT(*) FROM ticket_buyer_tokens
    WHERE buyer_id=$otherId")->fetchColumn()===0,
    'unpaid guest may not request a creation token');
$newGuestPassword='Synthetic-Guest-1985-P@ssword';
brvtalBuyerAuthConsume($db,$guestToken,'reset_password',$newGuestPassword);
$guestRecord=$db->query("SELECT status,password_hash,email_verified_at
    FROM ticket_buyers WHERE id=$guestId")->fetch(PDO::FETCH_ASSOC);
buyerAuthAssert($guestRecord['status']==='active'
    && $guestRecord['email_verified_at']!==null
    && password_verify($newGuestPassword,(string)$guestRecord['password_hash']),
    'guest purchase link verifies mailbox and activates buyer account');
buyerAuthAssert($db->query("SELECT status FROM ticket_buyers WHERE id=$otherId")
    ->fetchColumn()==='guest','wrong buyer account remains guest');
try {
    brvtalBuyerAuthConsume($db,$guestToken,'reset_password','Another-Guest-1985-Password');
    buyerAuthAssert(false,'used purchase token cannot replay');
} catch (DomainException $ignored) {}
$db->beginTransaction();
$activatedLink=brvtalBuyerAuthGuestPurchaseLink($db,198501);
$db->commit();
buyerAuthAssert($activatedLink===null,
    'already activated account never receives another creation link');
$db->prepare("UPDATE ticket_buyers SET status='guest',password_hash=NULL,
    email_verified_at=NULL WHERE id=?")->execute([$guestId]);
$db->beginTransaction();
$expiredLink=brvtalBuyerAuthGuestPurchaseLink($db,198501);
$db->commit();
parse_str((string)parse_url((string)$expiredLink,PHP_URL_QUERY),$expiredParams);
$expiredToken=(string)($expiredParams['token']??'');
$db->prepare("UPDATE ticket_buyer_tokens
    SET expires_at_utc=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)
    WHERE token_hash=?")->execute([hash('sha256',$expiredToken)]);
try {
    brvtalBuyerAuthConsume($db,$expiredToken,'reset_password','Expired-Guest-1985-Password');
    buyerAuthAssert(false,'expired guest token must fail');
} catch (DomainException $ignored) {}
buyerAuthAssert($db->query("SELECT status FROM ticket_buyers WHERE id=$guestId")
    ->fetchColumn()==='guest','expired token cannot upgrade the guest');
buyerAuthAssert(brvtalBuyerAuthRequest($db,'guest-1985@example.test',
    'reset_password',$rateDirectory)===['ok'=>true],
    'paid guest may request a replacement link under existing throttle');

// #1877: 72h retention in a disposable DB, with production mutation opt-in.
foreach (['old-expiring@example.test' => 73, 'young-expiring@example.test' => 71] as $address => $age) {
    $profile = $input;
    $profile['email'] = $address;
    buyerAuthAssert(brvtalBuyerAuthRegister($db, $profile) === ['ok'=>true],
        'unverified synthetic retention fixture');
    $db->exec("UPDATE ticket_buyers SET created_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL "
        . $age . " HOUR) WHERE email=" . $db->quote($address));
}
putenv('BRVTAL_INTEGRATION_TESTS=0');
buyerAuthAssert(brvtalBuyerPurgeUnverified($db) === 0,
    'no destructive cleanup when production authorization is absent');
putenv('BRVTAL_INTEGRATION_TESTS=1');
buyerAuthAssert(brvtalBuyerPurgeUnverified($db) === 1,
    'expire only unverified buyers older than 72 hours');
$expired = $db->query("SELECT id,email,full_name,password_hash,status
    FROM ticket_buyers WHERE status='blocked' AND email IS NULL ORDER BY id DESC LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
buyerAuthAssert(is_array($expired)
    && $expired['email'] === null && $expired['full_name'] === null
    && $expired['password_hash'] === null, 'expired identity anonymized');
$id = (int)$expired['id'];
$tokenCheck = $db->prepare('SELECT COUNT(*) FROM ticket_buyer_tokens WHERE buyer_id=?
    AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL');
$tokenCheck->execute([$id]);
buyerAuthAssert((int)$tokenCheck->fetchColumn() === 0,
    'expired verification tokens revoked');
$mailCheck = $db->prepare("SELECT COUNT(*) FROM ticket_mail_outbox
    WHERE related_ref=? AND state='failed' AND last_error_code='consent_revoked'
    AND recipient_email='purged@invalid.example'");
$mailCheck->execute(['buyer:'.$id]);
buyerAuthAssert((int)$mailCheck->fetchColumn() >= 1, 'unsent recipient scrubbed');
buyerAuthAssert((int)$db->query("SELECT COUNT(*) FROM ticket_buyers
    WHERE email='young-expiring@example.test' AND status='active'
    AND email_verified_at IS NULL")->fetchColumn() === 1, 'under 72h preserved');
buyerAuthAssert(brvtalBuyerPurgeUnverified($db) === 0, 'second pass idempotent');

putenv('BRVTAL_TICKET_MAIL_TESTING');
putenv($oldMailApproval === false ? 'BRVTAL_TICKET_MAIL_PRIVACY_APPROVED'
    : 'BRVTAL_TICKET_MAIL_PRIVACY_APPROVED=' . $oldMailApproval);
$GLOBALS['config'] = $originalConfig;
buyerAuthAssert(brvtal_admin_password_error('password1234') === 'PASSWORD_COMMON',
    'shared admin/buyer password policy is loaded without admin bootstrap');
$GLOBALS['brvtal_buyer_auth_suite_completed'] = true;
echo "BRVTAL buyer-auth MariaDB integration passed.\n";
// #1877: all public form abuse bursts run from the existing buyer-auth CI entrypoint.
require __DIR__ . '/public-forms-abuse.php';
