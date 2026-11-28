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
    array $input, ?array $resetScenario = null, ?array $activation = null
): void {
    buyerAuthAssert(function_exists('proc_open') && function_exists('stream_socket_server'),
        'HTTP integration requires a local PHP server');
    $sourceRoot = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/brvtal-buyer-1909-' . bin2hex(random_bytes(6));
    buyerAuthAssert(mkdir($directory, 0700), 'create disposable HTTP fixture directory');
    $process = null;
    $pipes = [];
    try {
        // Run the actual tracked HTTP code in a fresh document root. The
        // database suite invokes this fixture twice in one checkout; sharing
        // storage would exhaust the real public-login IP/subject buckets.
        $root = $directory . '/app';
        buyerAuthAssert(mkdir($root, 0700), 'create isolated HTTP document root');
        $listing = proc_open(['git','ls-files','-z','--','config','database','templates',
            'api/buyer/auth.php','api/health.php'], [
            0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w'],
        ], $listingPipes, $sourceRoot);
        buyerAuthAssert(is_resource($listing), 'enumerate tracked fixture source files');
        fclose($listingPipes[0]);
        $tracked = stream_get_contents($listingPipes[1]);
        fclose($listingPipes[1]);
        fclose($listingPipes[2]);
        buyerAuthAssert(proc_close($listing) === 0 && is_string($tracked),
            'tracked source enumeration succeeds');
        foreach (explode("\0", $tracked) as $relative) {
            if ($relative === '' || $relative === 'config/config.php') continue;
            $source = $sourceRoot . '/' . $relative;
            $target = $root . '/' . $relative;
            buyerAuthAssert(is_file($source) && !is_link($source), 'fixture source is a regular file');
            buyerAuthAssert(is_dir(dirname($target)) || mkdir(dirname($target),0700,true),
                'create fixture source directory');
            buyerAuthAssert(copy($source,$target), 'copy tracked source to isolated fixture');
        }
        buyerAuthAssert(mkdir($root . '/storage',0700), 'isolated rate-limit storage');
        buyerAuthAssert(mkdir($directory . '/sessions',0700), 'isolated PHP sessions');
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
            '-d', 'session.save_path=' . $directory . '/sessions',
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

        if ($activation !== null) {
            $headers = ['Cookie: '.$cookie,'X-CSRF-Token: '.$csrf];
            $urlPath = '/api/buyer/auth.php';
            $payload = ['action'=>'verify','token'=>$activation['token']];
            $invalid = $send($urlPath, 'POST',
                ['action'=>'verify','token'=>str_repeat('X',43),'password'=>$activation['password']], $headers);
            buyerAuthAssert($invalid['status'] === 422, 'unknown activation token rejected');
            $missing = $send($urlPath, 'POST', $payload, $headers);
            buyerAuthAssert($missing['status'] === 422, 'link alone cannot activate');
            $malformed = $send($urlPath, 'POST', $payload + ['password'=>[]], $headers);
            buyerAuthAssert($malformed['status'] === 422, 'malformed password fails closed');
            $denied = $send($urlPath, 'POST', $payload + ['password'=>$activation['password']],
                ['Cookie: '.$cookie]);
            buyerAuthAssert($denied['status'] === 403, 'activation requires CSRF');
            $pending = $db->query('SELECT email_verified_at FROM ticket_buyers')->fetchColumn();
            buyerAuthAssert($pending === null, 'rejected attempts never verify email');
            $ok = $send($urlPath, 'POST', $payload + ['password'=>$activation['password']], $headers);
            buyerAuthAssert($ok['status'] === 200 && ($ok['json']['ok'] ?? false),
                'real HTTP activation with mailbox-chosen password');
            $replay = $send($urlPath, 'POST', $payload + ['password'=>$activation['password']], $headers);
            buyerAuthAssert($replay['status'] === 422, 'activation replay rejected');
            $old = $send($urlPath, 'POST',
                ['action'=>'login','email'=>$input['email'],'password'=>$input['password']], $headers);
            buyerAuthAssert($old['status'] === 401, 'registrant credential rejected by HTTP login');
            $new = $send($urlPath, 'POST',
                ['action'=>'login','email'=>$input['email'],'password'=>$activation['password']], $headers);
            buyerAuthAssert($new['status'] === 200, 'mailbox owner can log in');
            $logs = (string)@file_get_contents($loggerPath) . (string)@file_get_contents($serverLog);
            buyerAuthAssert(!str_contains($logs, $activation['token'])
                && !str_contains($logs, $activation['password']), 'no raw activation secrets in logs');
            return;
        }

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
                'new password authenticates after reset (HTTP ' . $newLogin['status'] . ')');
            return;
        }

        // Challenge timestamp is already 3 seconds old; no artificial test delay.
        $challenge = brvtal_public_abuse_challenge($fixture, 'register', time() - 3);
        preg_match('/^(\d+) \+ (\d+) = \?$/', $challenge['question'], $answer);
        buyerAuthAssert(count($answer) === 3, 'synthetic challenge was generated');
        $payload = array_diff_key($input, ['password'=>true]);
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
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($directory);
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
$deniedInput = array_diff_key($input, ['password'=>true]);
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
// #2205: independent marketing ledger; no mail, provider or real addresses.
require_once __DIR__ . '/../../config/marketing_consent.php';
$db->exec((string)file_get_contents(__DIR__ . '/../../database/migration_marketing_consent_01.sql'));
$marketingEmail = 'buyer@example.test';
buyerAuthAssert(!brvtalMarketingConsentContactable($db,$marketingEmail), 'no opt-in by default');
try {
    brvtalMarketingConsentGrant($db,$marketingEmail,'v1','registration',false);
    buyerAuthAssert(false,'unchecked consent rejected');
} catch (InvalidArgumentException $e) {
    buyerAuthAssert($e->getMessage() === 'MARKETING_CONSENT_INVALID','explicit consent required');
}
$marketingToken = brvtalMarketingConsentGrant($db,' BUYER@EXAMPLE.TEST ','v1','registration',true);
$marketingRow = $db->query('SELECT * FROM marketing_consents')->fetch();
buyerAuthAssert($marketingRow['email'] === $marketingEmail && $marketingRow['consent_version'] === 'v1'
    && $marketingRow['origin'] === 'registration' && $marketingRow['granted_at_utc'] !== null
    && $marketingRow['unsubscribe_hash'] === hash('sha256',$marketingToken)
    && $marketingRow['unsubscribe_hash'] !== $marketingToken, 'normalized, versioned, hash-only evidence');
buyerAuthAssert(brvtalMarketingConsentContactable($db,$marketingEmail), 'explicit consent contactable');
$marketingPeer = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$pass,$options);
try {
    brvtalMarketingConsentGrant($marketingPeer,$marketingEmail,'v2','account',true);
    buyerAuthAssert(false,'second connection cannot create active duplicate');
} catch (PDOException $e) {
    buyerAuthAssert($e->getCode() === '23000','duplicate constrained by database');
}
buyerAuthAssert(!brvtalMarketingConsentRevoke($db,'invalid')
    && !brvtalMarketingConsentRevoke($db,str_repeat('0',64)), 'invalid and unknown tokens rejected');
buyerAuthAssert(brvtalMarketingConsentRevoke($marketingPeer,$marketingToken)
    && !brvtalMarketingConsentContactable($db,$marketingEmail), 'revocation visible immediately across connections');
$marketingNew = brvtalMarketingConsentGrant($db,$marketingEmail,'v2','account',true);
buyerAuthAssert($marketingNew !== $marketingToken && brvtalMarketingConsentRevoke($db,$marketingToken)
    && brvtalMarketingConsentContactable($marketingPeer,$marketingEmail), 'old token cannot revoke new explicit grant');
buyerAuthAssert(brvtalMarketingConsentRevoke($db,$marketingNew)
    && !brvtalMarketingConsentContactable($db,$marketingEmail), 'new grant independently revoked');
buyerAuthAssert((int)$db->query("SELECT COUNT(*) FROM marketing_consents WHERE revoked_at_utc IS NOT NULL")->fetchColumn() === 2
    && (int)$db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === 0, 'historical evidence retained and no mail generated');
putenv('BRVTAL_INTEGRATION_TESTS=0');
buyerAuthAssert(!brvtalMarketingConsentContactable($db,$marketingEmail)
    && !brvtalMarketingConsentRevoke($db,$marketingNew), 'activation remains disabled');
try {
    brvtalMarketingConsentGrant($db,$marketingEmail,'v3','account',true);
    buyerAuthAssert(false,'disabled grant rejected');
} catch (DomainException $e) {
    buyerAuthAssert($e->getMessage() === 'MARKETING_CONSENT_DISABLED','disabled grant fails closed');
}
putenv('BRVTAL_INTEGRATION_TESTS=1');
// Existing registration, verification and transactional outbox tests below use
// the same opted-out address and remain independent of marketing.
$a = brvtalBuyerAuthRegister($db, array_diff_key($input, ['password'=>true]));
// Use private synthetic mode for the rest of the multi-mail test suite.
$GLOBALS['config']['ticketing']['mail']['testing'] = '1';
$b = brvtalBuyerAuthRegister($db, $input);
buyerAuthAssert($a === $b && $a === ['ok'=>true], 'duplicate is neutral');
buyerAuthAssert((int)$db->query('SELECT COUNT(*) FROM ticket_buyers')->fetchColumn() === 1,
    'one buyer after duplicate');
$row = $db->query('SELECT id,full_name,password_hash,email_verified_at,security_epoch,status FROM ticket_buyers')->fetch();
// Match the 12..4096-character policy without silent bcrypt 72-byte truncation.
if (defined('PASSWORD_ARGON2ID')) {
    $longPassword = str_repeat('SYNTHETIC-secure-4829-', 7);
    $longHash = brvtalBuyerAuthPassword($longPassword);
    buyerAuthAssert(password_verify($longPassword, $longHash)
        && !password_verify(substr($longPassword, 0, 72), $longHash),
        'long password preserves all bytes with Argon2id');
}
buyerAuthAssert($row['full_name'] === $input['name']
    && $row['password_hash'] === null && $row['status'] === 'active'
    && $row['email_verified_at'] === null, 'unverified buyer has no credential and is not guest');
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
buyerAuthAssert(str_contains((string)$mail, '?accion=verify&token='),
    'verification link retains the protected mail-renderer contract');
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
    brvtalBuyerAuthConsume($db, $verifyToken, 'verify_email', 'Synthetic-expired-2105-pass');
    buyerAuthAssert(false, 'expired verification rejected');
} catch (DomainException) {}
buyerAuthAssert(brvtalBuyerAuthRequest($db, 'buyer@example.test', 'verify_email', $rateDirectory) === ['ok'=>true],
    'reissue unverified is neutral');
$next = (string)$db->query('SELECT body_text FROM ticket_mail_outbox ORDER BY id DESC LIMIT 1')->fetchColumn();
preg_match('/token=([A-Za-z0-9_-]{43})/', (string)brvtalBuyerMailUnseal($next), $vm);
$valid = $vm[1] ?? '';
buyerAuthAssert($valid !== '' && $valid !== $verifyToken, 'reissued token differs');
$mailboxPassword = 'Synthetic-mailbox-2105-owner';
$db->prepare('INSERT INTO ticket_buyer_sessions
    (buyer_id,session_hash,issued_epoch,expires_at_utc)
    VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 1 HOUR))')
    ->execute([(int)$row['id'],hash('sha256','synthetic-pending-session'),(int)$row['security_epoch']]);
buyerAuthMissingMailKeyHttpCheck($db, $dbName, $host, $port, $user, $pass, $input,
    null, ['token'=>$valid,'password'=>$mailboxPassword]);
$activated = $db->query('SELECT password_hash,security_epoch FROM ticket_buyers')->fetch();
buyerAuthAssert(password_verify($mailboxPassword, $activated['password_hash'])
    && !password_verify($input['password'], $activated['password_hash'])
    && (int)$activated['security_epoch'] === (int)$row['security_epoch']+1,
    'activation replaces untrusted credential and advances epoch');
buyerAuthAssert((int)$db->query("SELECT COUNT(*) FROM ticket_buyer_sessions
    WHERE issued_epoch=".(int)$row['security_epoch']." AND revoked_at_utc IS NULL")->fetchColumn() === 0,
    'activation revokes earlier sessions');
$activeToken = brvtalBuyerAuthToken($db, (int)$row['id'], 'verify_email');
try {
    brvtalBuyerAuthConsume($db, $activeToken['raw'], 'verify_email', 'Synthetic-active-2105-reject');
    buyerAuthAssert(false, 'already active account cannot be reactivated');
} catch (DomainException) {}
$input['password'] = $mailboxPassword;
$row['security_epoch'] = $activated['security_epoch'];
try {
    brvtalBuyerAuthConsume($db, $valid, 'verify_email', $mailboxPassword);
    buyerAuthAssert(false, 'verification replay rejected');
} catch (DomainException) {}
buyerAuthAssert($db->query('SELECT email_verified_at FROM ticket_buyers')->fetchColumn() !== null,
    'verification persisted');
$activeBefore = $db->query('SELECT * FROM ticket_buyers')->fetch();
$activeTokens = $db->query('SELECT COUNT(*) FROM ticket_buyer_tokens')->fetchColumn();
$activeMail = $db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn();
buyerAuthAssert(brvtalBuyerAuthRegister($db, array_replace($input, [
    'name'=>'Duplicate attempt', 'password'=>['ignored'],
])) === ['ok'=>true]
    && $db->query('SELECT * FROM ticket_buyers')->fetch() === $activeBefore
    && $db->query('SELECT COUNT(*) FROM ticket_buyer_tokens')->fetchColumn() === $activeTokens
    && $db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === $activeMail,
    'duplicate active account remains neutral without changing credentials or consent');
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
$prior = $db->prepare('SELECT COUNT(*) FROM ticket_buyer_sessions
    WHERE buyer_id=? AND issued_epoch=? AND revoked_at_utc IS NULL');
$prior->execute([(int)$stale['id'], (int)$stale['security_epoch']]);
buyerAuthAssert((int)$prior->fetchColumn() === 0,
    'all prior epoch sessions revoked, independently of newly issued sessions');
$oldSession = $db->prepare('SELECT revoked_at_utc FROM ticket_buyer_sessions
    WHERE session_hash=?');
$oldSession->execute([hash('sha256','synthetic-session-id')]);
$revokedAt = $oldSession->fetchColumn();
buyerAuthAssert(is_string($revokedAt) && $revokedAt !== '',
    'original synthetic session retained and revoked');
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
$guestBefore = $db->query("SELECT * FROM ticket_buyers WHERE id=$guestId")->fetch();
$guestMail = $db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn();
$guestTokens = $db->query('SELECT COUNT(*) FROM ticket_buyer_tokens')->fetchColumn();
buyerAuthAssert(brvtalBuyerAuthRegister($db, array_replace($input, [
    'email'=>'guest-1985@example.test', 'name'=>'Duplicate attempt',
])) === ['ok'=>true]
    && $db->query("SELECT * FROM ticket_buyers WHERE id=$guestId")->fetch() === $guestBefore
    && (int)$db->query("SELECT COUNT(*) FROM ticket_orders WHERE buyer_id=$guestId AND status='paid'")
        ->fetchColumn() === 1
    && $db->query('SELECT COUNT(*) FROM ticket_buyer_tokens')->fetchColumn() === $guestTokens
    && $db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === $guestMail,
    'duplicate paid guest preserves original identity, consent and order');
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

// #2118: old clients may still send a password, but it must never be stored.
$legacy = array_replace($input, ['email'=>'legacy-2118@example.test','password'=>['ignored']]);
buyerAuthAssert(brvtalBuyerAuthRegister($db, $legacy) === ['ok'=>true], 'legacy payload is accepted');
$legacyRow = $db->query("SELECT id,status,password_hash,email_verified_at FROM ticket_buyers
    WHERE email='legacy-2118@example.test'")->fetch();
buyerAuthAssert(is_array($legacyRow) && $legacyRow['password_hash'] === null && $legacyRow['status'] === 'active'
    && $legacyRow['email_verified_at'] === null, 'legacy password ignored for new account');
// Simulate a pending record created before #2118 without altering its consent or token.
$historicalPassword = 'Synthetic-old-pending-2118';
$db->prepare('UPDATE ticket_buyers SET password_hash=? WHERE id=?')
    ->execute([brvtalBuyerAuthPassword($historicalPassword), (int)$legacyRow['id']]);
$legacyMail = (string)$db->query('SELECT body_text FROM ticket_mail_outbox ORDER BY id DESC LIMIT 1')->fetchColumn();
preg_match('/token=([A-Za-z0-9_-]{43})/', (string)brvtalBuyerMailUnseal($legacyMail), $legacyMatches);
$legacyToken = $legacyMatches[1] ?? '';
$chosen = 'Synthetic-verified-owner-2118';
brvtalBuyerAuthConsume($db, $legacyToken, 'verify_email', $chosen);
$legacyAfter = $db->query("SELECT password_hash,email_verified_at FROM ticket_buyers
    WHERE id=".(int)$legacyRow['id'])->fetch();
buyerAuthAssert($legacyAfter['email_verified_at'] !== null
    && password_verify($chosen, $legacyAfter['password_hash'])
    && !password_verify($historicalPassword, $legacyAfter['password_hash']),
    'historical pending hash never becomes a verified credential');

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
