<?php
declare(strict_types=1);

// The script exports only the cycle contract when required from this fixture.
require_once __DIR__ . '/../../ops/ticketing-mail-worker.php';

function worker_test_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('T28 worker: ' . $message);
}

// CI's database job always supplies these values and a disposable MariaDB.
// Outside CI no production or externally configured database may be touched.
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "T28 worker contract requires synthetic CI MariaDB; skipped outside fixture.\n";
    exit(0);
}
$prefix = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 0);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: '');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
worker_test_assert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $prefix)
    && in_array($host, ['127.0.0.1', 'localhost'], true) && $port > 0 && $port <= 65535,
    'disposable loopback-only MariaDB preflight');
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$dbName = $prefix . '_mail_worker_' . bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE ' . chr(96) . $dbName . chr(96) . ' CHARACTER SET utf8mb4');
register_shutdown_function(static function () use ($server, $dbName): void {
    try { $server->exec('DROP DATABASE IF EXISTS ' . chr(96) . $dbName . chr(96)); } catch (Throwable) {}
});
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('CREATE TABLE worker_stage_log (id INT AUTO_INCREMENT PRIMARY KEY, stage VARCHAR(16) NOT NULL)
    ENGINE=InnoDB');

$gateCases = [
    ['mode' => '', 'expect' => ['mail'=>'disabled','issue'=>'disabled']],
    ['mode' => 'invalid', 'expect' => ['mail'=>'not_ready','issue'=>'not_ready']],
    ['mode' => 'mock', 'expect' => ['mail'=>'not_ready','issue'=>'not_ready']],
    ['mode' => 'mock', 'testing'=>'1', 'expect' => ['mail'=>'not_ready','issue'=>'not_ready']],
    ['mode' => 'mock', 'testing'=>'1', 'keys'=>true, 'expect' => ['mail'=>'ready','issue'=>'ready']],
    ['mode' => 'test', 'expect' => ['mail'=>'not_ready','issue'=>'not_ready']],
    ['mode' => 'test', 'keys'=>true, 'expect' => ['mail'=>'not_ready','issue'=>'not_ready']],
    ['mode' => 'test', 'keys'=>true, 'allowlist'=>true,
        'expect' => ['mail'=>'ready','issue'=>'not_ready']],
    ['mode' => 'test', 'keys'=>true, 'invalid_allowlist'=>true,
        'expect' => ['mail'=>'not_ready','issue'=>'not_ready']],
    ['mode' => 'live', 'keys'=>true, 'flags'=>true, 'invalid_sender'=>true,
        'expect' => ['mail'=>'not_ready','issue'=>'not_ready']],
    ['mode' => 'live', 'expect' => ['mail'=>'not_ready','issue'=>'not_ready']],
    ['mode' => 'live', 'keys'=>true, 'expect' => ['mail'=>'not_ready','issue'=>'not_ready']],
    ['mode' => 'live', 'keys'=>true, 'flags'=>true,
        'expect' => ['mail'=>'ready','issue'=>'ready']],
];
foreach ($gateCases as $case) {
    $values = ['BRVTAL_TICKET_MAIL_MODE' => $case['mode']];
    if (isset($case['testing'])) $values['BRVTAL_TICKET_MAIL_TESTING'] = '1';
    if ($case['keys'] ?? false) {
        $values['BRVTAL_TICKET_MAIL_API_KEY'] = 'synthetic';
        $values['BRVTAL_TICKET_MAIL_DOMAIN'] = 'test.invalid';
        $values['BRVTAL_TICKET_MAIL_FROM'] = 'noreply@test.invalid';
    }
    if ($case['allowlist'] ?? false) {
        $values['BRVTAL_TICKET_MAIL_TEST_RECIPIENTS'] = 'test@test.invalid';
    }
    if ($case['invalid_allowlist'] ?? false) {
        $values['BRVTAL_TICKET_MAIL_TEST_RECIPIENTS'] = '*';
    }
    if ($case['invalid_sender'] ?? false) {
        $values['BRVTAL_TICKET_MAIL_REPLY_TO'] = 'invalid-reply';
    }
    if ($case['flags'] ?? false) {
        foreach (['ALLOW_LIVE', 'PRIVACY_APPROVED', 'DNS_VERIFIED'] as $key) {
            $values['BRVTAL_TICKET_MAIL_' . $key] = '1';
        }
    }
    $result = brvtal_ticket_mail_worker_gate(static fn(string $key): string => $values[$key] ?? '');
    worker_test_assert($result === $case['expect'], 'provider gate is fail-closed');
}

/** Test callbacks record stage execution in synthetic MariaDB, never transport. */
$record = static function (string $stage, string $answer) use ($pdo): callable {
    return static function (PDO $db) use ($pdo, $stage, $answer): string {
        worker_test_assert($db === $pdo, 'one trusted disposable PDO passed to each stage');
        $insert = $db->prepare('INSERT INTO worker_stage_log(stage) VALUES (?)');
        $insert->execute([$stage]);
        return $answer;
    };
};
$readLog = static fn(): array => $pdo->query('SELECT stage FROM worker_stage_log ORDER BY id')
    ->fetchAll(PDO::FETCH_COLUMN);
$run = static function (string $name, array $gate, array $answers,
    array $expected, int $expectedExit, array $expectedCalls) use ($pdo, $record, $readLog): void {
    $pdo->exec('DELETE FROM worker_stage_log');
    $preflight = static fn(): array => ['pdo' => $pdo, 'gate' => $gate];
    $result = brvtal_ticket_mail_worker_cycle($preflight,
        $record('reconcile', $answers[0]), $record('issue', $answers[1]),
        $record('state', $answers[2]));
    worker_test_assert($result['output'] === $expected, $name . ': exact bounded states');
    worker_test_assert($result['exit_code'] === $expectedExit, $name . ': meaningful exit');
    worker_test_assert($readLog() === $expectedCalls, $name . ': stage isolation and single attempt');
    $encoded = json_encode($result['output'], JSON_THROW_ON_ERROR);
    worker_test_assert(!str_contains($encoded, '@') && !str_contains($encoded, 'synthetic_secret'),
        $name . ': no secret or PII in JSON');
};
$ready = ['mail'=>'ready','issue'=>'ready'];
$blocked = ['mail'=>'not_ready','issue'=>'not_ready'];
$disabled = ['mail'=>'disabled','issue'=>'disabled'];
$run('mail missing but payment reconciliation allowed', $blocked,
    ['approved','issued','accepted'], ['reconcile'=>'approved','issue'=>'not_ready','state'=>'not_ready'],
    1, ['reconcile']);
$unreadableMail = brvtal_ticket_mail_worker_gate(static function (string $key): string {
    throw new RuntimeException('synthetic_secret');
});
worker_test_assert($unreadableMail === $blocked, 'mail secret read failure is stage-specific');
$run('mail secret read failure does not block reconciliation', $unreadableMail,
    ['approved','issued','accepted'], ['reconcile'=>'approved','issue'=>'not_ready','state'=>'not_ready'],
    1, ['reconcile']);
$run('mail intentionally disabled is not idle', $disabled,
    ['idle','issued','accepted'], ['reconcile'=>'idle','issue'=>'disabled','state'=>'disabled'],
    1, ['reconcile']);
$run('reconciliation failure with idle mail', $ready,
    ['unavailable','idle','idle'], ['reconcile'=>'unavailable','issue'=>'idle','state'=>'idle'],
    1, ['reconcile','issue','state']);
$run('all genuine idle is success', $ready,
    ['idle','idle','idle'], ['reconcile'=>'idle','issue'=>'idle','state'=>'idle'],
    0, ['reconcile','issue','state']);
$run('approved transaction, issued QR and accepted mail', $ready,
    ['approved','issued','accepted'], ['reconcile'=>'approved','issue'=>'issued','state'=>'accepted'],
    0, ['reconcile','issue','state']);
$run('incomplete fulfillment cannot be success', $ready,
    ['approved_unfulfilled','issued','accepted'],
    ['reconcile'=>'approved_unfulfilled','issue'=>'issued','state'=>'accepted'],
    1, ['reconcile','issue','state']);
$run('reconcile disabled is not idle', $ready,
    ['skipped','idle','idle'], ['reconcile'=>'disabled','issue'=>'idle','state'=>'idle'],
    1, ['reconcile','issue','state']);
$run('issuance waiting for mail or privacy approval', $ready,
    ['idle','waiting','idle'], ['reconcile'=>'idle','issue'=>'waiting','state'=>'idle'],
    1, ['reconcile','issue','state']);
$run('issuance failure stays diagnostic and nonzero', $ready,
    ['idle','failed:ISSUE_FAILED','idle'],
    ['reconcile'=>'idle','issue'=>'failed:ISSUE_FAILED','state'=>'idle'],
    1, ['reconcile','issue','state']);
$run('uncertain mail receipt is not success', $ready,
    ['idle','idle','uncertain'], ['reconcile'=>'idle','issue'=>'idle','state'=>'uncertain'],
    1, ['reconcile','issue','state']);
$run('arbitrary provider messages sanitized', $ready,
    ['private@example.com','ORDER_FAILED:private@example.com','synthetic_secret'],
    ['reconcile'=>'unavailable','issue'=>'unavailable','state'=>'unavailable'],
    1, ['reconcile','issue','state']);

$pdo->exec('DELETE FROM worker_stage_log');
$called = 0;
$noStages = static function (PDO $db) use (&$called): string { ++$called; return 'idle'; };
foreach (['master key missing','database config unavailable','invalid preflight context'] as $case) {
    $preflight = static function () use ($case, $pdo): array {
        if ($case === 'master key missing') throw new RuntimeException('WORKER_MASTER_KEY_UNAVAILABLE');
        if ($case === 'database config unavailable') throw new RuntimeException('WORKER_DB_UNAVAILABLE');
        return ['pdo'=>$pdo,'gate'=>['mail'=>'ready','issue'=>'invalid']];
    };
    $result = brvtal_ticket_mail_worker_cycle($preflight, $noStages, $noStages, $noStages);
    worker_test_assert($result === ['output'=>['reconcile'=>'not_run', 'issue'=>'not_run',
        'state'=>'not_run'], 'exit_code'=>2], $case . ': global fail closed');
}
worker_test_assert($called === 0 && $readLog() === [], 'failed global preflight runs zero stages');

$exception = static function (PDO $db): string {
    throw new RuntimeException('private@example.com/synthetic_secret');
};
$result = brvtal_ticket_mail_worker_cycle(static fn(): array => ['pdo'=>$pdo,'gate'=>$ready],
    $exception, $record('issue','idle'), $record('state','idle'));
worker_test_assert($result['output'] === ['reconcile'=>'unavailable','issue'=>'idle','state'=>'idle']
    && $result['exit_code'] === 1, 'stage exception sanitized; subsequent independent work runs');

echo "T28_WORKER_STAGE_ISOLATION_PASS: synthetic MariaDB, distinct idle/disabled/not_ready, master-key fail closed, no network; all stages bounded\n";
