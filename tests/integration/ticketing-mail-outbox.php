<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/ticketing_mail_outbox.php';

function mail_assert(bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('TICKET MAIL TEST FAILED: ' . $message);
    }
}
putenv('BRVTAL_TICKET_MAIL_MODE=disabled');
$fixture = ['recipient_email' => 'buyer@example.test', 'message_key' => 'ticket:synthetic:1',
    'subject' => 'Tu entrada', 'body_text' => 'Entrada de prueba'];
mail_assert(brvtal_ticket_resend_send($fixture)['code'] === 'transport_disabled',
    'missing credentials must fail closed');

putenv('BRVTAL_TICKET_MAIL_MODE=mock');
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
putenv('BRVTAL_TICKET_MAIL_API_KEY=synthetic-test-key-must-not-leak');
mail_assert(brvtal_ticket_resend_send($fixture)['state'] === 'accepted', 'mock accepted');
$seen = [];
$transport = static function ($payload, $headers) use (&$seen): array {
    $seen[] = [$payload, $headers];
    return ['status' => count($seen) === 1 ? 429 : (count($seen) === 2 ? 503 : 200),
        'body' => count($seen) === 3 ? '{"id":"synthetic-resend-1"}' : '{}', 'retry_after' => 1];
};
mail_assert(brvtal_ticket_resend_send($fixture, $transport)['state'] === 'retry', '429 retries');
mail_assert(brvtal_ticket_resend_send($fixture, $transport)['code'] === 'provider_5xx', '503 retries');
mail_assert(brvtal_ticket_resend_send($fixture, $transport)['state'] === 'accepted', '200 accepted');
mail_assert(count($seen) === 3, 'mock transport calls');
mail_assert($seen[0][1][2] === 'Idempotency-Key: ticket:synthetic:1', 'stable provider key');
mail_assert($seen[0][1][0] === 'Authorization: Bearer ', 'mock must not expose API key');
putenv('BRVTAL_TICKET_MAIL_API_KEY');

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL ticket-mail transport mock OK; MariaDB skipped.\n";
    exit(0);
}

$base = (string)getenv('BRVTAL_TEST_DB_NAME');
mail_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', $base), 'test namespace');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$dbName = $base . '_mail_' . bin2hex(random_bytes(4));
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false];
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $opts);
$server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(static function () use ($server, $dbName): void {
    try { $server->exec("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (Throwable) {}
});
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, $opts);
$pdo->exec((string)file_get_contents(__DIR__ . '/../../database/migration_ticketing_mail_outbox_01.sql'));

// #1884: gate matrix uses synthetic config and rollback-only outbox probes.
$hadPrivateConfig = array_key_exists('config', $GLOBALS);
$oldPrivateConfig = $GLOBALS['config'] ?? null;
$oldTesting = getenv('BRVTAL_TICKET_MAIL_TESTING');
$oldApproval = getenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED');
$probe = static function (string $case) use ($pdo): int {
    $pdo->beginTransaction();
    try {
        $id = brvtal_ticket_mail_enqueue($pdo, 'ticket:privacy:' . $case,
            'entry:privacy', 'privacy-' . $case . '@example.test',
            'Correo sintético', 'Contenido sintético seguro');
        $pdo->rollBack();
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
};
$expectPrivacyDeny = static function (string $case) use ($probe): void {
    try {
        $probe($case);
        mail_assert(false, 'privacy check unexpectedly accepted ' . $case);
    } catch (RuntimeException $error) {
        mail_assert($error->getMessage() === 'MAIL_PRIVACY_NOT_APPROVED',
            'privacy check rejects ' . $case . ' without leaking private values');
    }
};
try {
    putenv('BRVTAL_TICKET_MAIL_TESTING');
    putenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED');
    $GLOBALS['config'] = ['ticketing'=>['mail'=>[
        'testing'=>'0','privacy_approved'=>'0',
    ]]];
    $expectPrivacyDeny('no-approval');
    $GLOBALS['config']['ticketing']['mail']['privacy_approved'] = '1';
    mail_assert($probe('private-approved') > 0,
        'private approval permits enqueue without env');
    putenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED=1');
    $GLOBALS['config']['ticketing']['mail']['privacy_approved'] = '0';
    $expectPrivacyDeny('private-deny');
    putenv('BRVTAL_TICKET_MAIL_TESTING=1');
    $expectPrivacyDeny('private-testing-deny');
    putenv('BRVTAL_TICKET_MAIL_TESTING');
    $GLOBALS['config']['ticketing']['mail']['testing'] = '1';
    mail_assert($probe('private-testing') > 0,
        'private testing flag permits synthetic enqueue');
    $GLOBALS['config']['ticketing']['mail'] = [];
    mail_assert($probe('env-fallback') > 0,
        'environment approval is fallback when private config is absent');
    mail_assert((int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === 0,
        'all privacy probes rolled back with no persisted personal data');
} finally {
    if ($hadPrivateConfig) $GLOBALS['config'] = $oldPrivateConfig;
    else unset($GLOBALS['config']);
    putenv($oldTesting === false ? 'BRVTAL_TICKET_MAIL_TESTING'
        : 'BRVTAL_TICKET_MAIL_TESTING=' . $oldTesting);
    putenv($oldApproval === false ? 'BRVTAL_TICKET_MAIL_PRIVACY_APPROVED'
        : 'BRVTAL_TICKET_MAIL_PRIVACY_APPROVED=' . $oldApproval);
}
$pdo->exec("CREATE TABLE synthetic_paid_ticket (id INT PRIMARY KEY,status VARCHAR(20) NOT NULL)");
$pdo->exec("INSERT INTO synthetic_paid_ticket VALUES (1,'paid')");

putenv('BRVTAL_TICKET_MAIL_TESTING=0');
try {
    brvtal_ticket_mail_enqueue($pdo, 'ticket:disabled:1', 'entry:1',
        'buyer@example.test', 'Entrada', 'Contenido');
    mail_assert(false, 'privacy gate must reject enqueue');
} catch (RuntimeException $e) {
    mail_assert($e->getMessage() === 'MAIL_PRIVACY_NOT_APPROVED', 'privacy gate reason');
}
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
try {
    brvtal_ticket_mail_enqueue($pdo, 'ticket:outside:1', 'entry:1',
        'buyer@example.test', 'Entrada', 'Contenido');
    mail_assert(false, 'enqueue outside transaction should fail closed');
} catch (RuntimeException $e) {
    mail_assert($e->getMessage() === 'MAIL_TRANSACTION_REQUIRED', 'transaction reason');
}
mail_assert((int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === 0,
    'blocked privacy write remains empty');

// Enqueue must remain part of the payment's transaction, not commit on its own.
$pdo->beginTransaction();
brvtal_ticket_mail_enqueue($pdo, 'ticket:rollback:1', 'entry:1', 'buyer@example.test', 'Entrada', 'Contenido');
$pdo->rollBack();
mail_assert((int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === 0,
    'rolled-back ticket never enqueues mail');

$pdo->beginTransaction();
$id = brvtal_ticket_mail_enqueue($pdo, 'ticket:synthetic:1', 'entry:1',
    'buyer@example.test', 'Entrada', 'Contenido');
$pdo->commit();
$pdo->beginTransaction();
mail_assert($id === brvtal_ticket_mail_enqueue($pdo, 'ticket:synthetic:1', 'entry:1',
    'buyer@example.test', 'Entrada', 'Contenido'), 'unique logical message');
try {
    brvtal_ticket_mail_enqueue($pdo, 'ticket:synthetic:1', 'entry:1',
        'buyer@example.test', 'Otro', 'Contenido');
    mail_assert(false, 'different payload must be rejected');
} catch (DomainException $e) {
    mail_assert($e->getMessage() === 'MAIL_KEY_CONFLICT', 'key conflict reason');
}
$pdo->commit();
mail_assert((int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn() === 1,
    'one email row after duplicate webhook');

// Two temporary failures followed by one acceptance; never mutate paid tickets.
$count = 0;
$sender = static function ($payload, $headers) use (&$count): array {
    $count++;
    return match ($count) {
        1 => ['status' => 429, 'body' => '{}', 'retry_after' => 1],
        2 => ['status' => 503, 'body' => '{}'],
        default => ['status' => 200, 'body' => '{"id":"sent-fixture-1"}'],
    };
};
ob_start();
mail_assert(brvtal_ticket_mail_deliver_one($pdo, $sender) === 'retry', '429 backoff');
$pdo->exec("UPDATE ticket_mail_outbox SET next_attempt_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)");
mail_assert(brvtal_ticket_mail_deliver_one($pdo, $sender) === 'retry', '5xx backoff');
$pdo->exec("UPDATE ticket_mail_outbox SET next_attempt_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)");
mail_assert(brvtal_ticket_mail_deliver_one($pdo, $sender) === 'accepted', 'accepted after retries');
mail_assert(brvtal_ticket_mail_deliver_one($pdo, $sender) === 'idle', 'accepted is terminal');
mail_assert(ob_get_clean() === '', 'no recipient or secret on stdout');
mail_assert($count === 3, 'no duplicate delivery after acceptance');
mail_assert((string)$pdo->query('SELECT status FROM synthetic_paid_ticket')->fetchColumn() === 'paid',
    'paid ticket unchanged by mail failure');

$pdo->beginTransaction();
$id2 = brvtal_ticket_mail_enqueue($pdo, 'ticket:synthetic:2', 'entry:2',
    'buyer2@example.test', 'Entrada', 'Contenido');
$pdo->commit();
putenv('BRVTAL_TICKET_MAIL_MODE=disabled');
mail_assert(brvtal_ticket_mail_deliver_one($pdo) === 'failed', 'disabled transport terminal');
mail_assert(brvtal_ticket_mail_redrive($pdo, $id2), 'safe manual redrive');
putenv('BRVTAL_TICKET_MAIL_MODE=mock');
mail_assert(brvtal_ticket_mail_deliver_one($pdo) === 'accepted', 'redrive accepted');

$pdo->beginTransaction();
$id3 = brvtal_ticket_mail_enqueue($pdo, 'ticket:synthetic:3', 'entry:3',
    'buyer3@example.test', 'Entrada', 'Contenido');
$pdo->commit();
$pdo->prepare('UPDATE ticket_mail_outbox SET attempts=4 WHERE id=?')->execute([$id3]);
mail_assert(brvtal_ticket_mail_deliver_one($pdo, static fn() => ['status' => 503]) === 'uncertain',
    'max retries fail closed to reconciliation');
mail_assert(!brvtal_ticket_mail_redrive($pdo, $id3), 'uncertain cannot redrive blindly');
mail_assert(brvtal_ticket_mail_deliver_one($pdo) === 'idle', 'uncertain not automatically replayed');

// #2063: orphaned leases and exhausted windows are reconciled without transport.
// These assertions intentionally precede the implementation change (red-first).
$enqueueForReconciliation = static function (string $key) use ($pdo): int {
    $pdo->beginTransaction();
    try {
        $id = brvtal_ticket_mail_enqueue($pdo, 'ticket:reconcile:' . $key, 'entry:reconcile',
            $key . '@example.test', 'Synthetic', 'No real delivery');
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
};
$expired = $enqueueForReconciliation('expired');
$exhausted = $enqueueForReconciliation('exhausted');
$window = $enqueueForReconciliation('window');
$orphan = $enqueueForReconciliation('orphan');
$active = $enqueueForReconciliation('active');
$activeRetry = $enqueueForReconciliation('active-retry');
$normal = $enqueueForReconciliation('normal');
$receipt = $enqueueForReconciliation('receipt');
$failed = $enqueueForReconciliation('failed');
$change = $pdo->prepare("UPDATE ticket_mail_outbox SET state=?,attempts=?,first_attempt_at=?,
    locked_until=?,next_attempt_at=?,provider_message_id=?,accepted_at=?,last_error_code=? WHERE id=?");
$older = gmdate('Y-m-d H:i:s', time() - 24 * 3600);
$recent = gmdate('Y-m-d H:i:s', time() - 3600);
$past = gmdate('Y-m-d H:i:s', time() - 180);
$future = gmdate('Y-m-d H:i:s', time() + 3600);
$set = static function (int $id, string $state, int $attempts, ?string $first,
    ?string $locked, ?string $next, ?string $provider = null,
    ?string $accepted = null, ?string $error = null) use ($change): void {
    $change->execute([$state,$attempts,$first,$locked,$next,$provider,$accepted,$error,$id]);
};
$set($expired, 'sending', 5, $recent, $past, $past);
$set($exhausted, 'retry', 5, $recent, null, $future, null, null, 'provider_5xx');
$set($window, 'retry', 2, $older, null, $future);
$set($orphan, 'sending', 1, $recent, $past, $past);
$set($active, 'sending', 5, $recent, $future, $past);
$set($activeRetry, 'retry', 5, $recent, $future, $past);
$set($normal, 'retry', 2, $recent, null, $future);
$set($receipt, 'sending', 5, $recent, $past, $past, 'provider-real-1', $recent);
$set($failed, 'failed', 5, $recent, null, $past, null, null, 'provider_rejected');
$before = $pdo->query("SELECT id,message_key,payload_hash,attempts,first_attempt_at,
    next_attempt_at,provider_message_id,accepted_at,last_error_code
    FROM ticket_mail_outbox WHERE id IN ($expired,$exhausted,$window,$orphan,$active,$activeRetry,$normal,$receipt,$failed)
    ORDER BY id")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
$transportCalls = 0;
mail_assert(brvtal_ticket_mail_reconcile_stale($pdo, 2) === 2, 'bounded first batch');
$second = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, $opts);
mail_assert(brvtal_ticket_mail_reconcile_stale($second, 2) === 2, 'second worker handles remaining batch');
mail_assert(brvtal_ticket_mail_reconcile_stale($pdo, 2) === 0, 'reconciliation is idempotent');
$states = $pdo->query("SELECT id,state,locked_until FROM ticket_mail_outbox
    WHERE id IN ($expired,$exhausted,$window,$orphan,$active,$activeRetry,$normal,$receipt,$failed)
    ORDER BY id")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
foreach ([$expired,$exhausted,$window,$orphan] as $id) {
    mail_assert($states[$id]['state'] === 'uncertain' && $states[$id]['locked_until'] === null,
        'stale job is fenced without redrive');
    mail_assert(!brvtal_ticket_mail_redrive($pdo, $id), 'uncertain is not redrivable');
}
foreach ([$active,$activeRetry,$normal,$receipt,$failed] as $id) {
    mail_assert($states[$id]['state'] === ($id === $normal || $id === $activeRetry ? 'retry'
        : ($id === $failed ? 'failed' : 'sending')), 'active/terminal/receipt unchanged');
}
$after = $pdo->query("SELECT id,message_key,payload_hash,attempts,first_attempt_at,
    next_attempt_at,provider_message_id,accepted_at,last_error_code
    FROM ticket_mail_outbox WHERE id IN ($expired,$exhausted,$window,$orphan,$active,$activeRetry,$normal,$receipt,$failed)
    ORDER BY id")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
foreach ($before as $rowId => $row) {
    foreach ($row as $field => $value) {
        mail_assert($after[$rowId][$field] === $value
            || ($field === 'last_error_code' && $value === null
                && in_array((int)$rowId, [$expired,$window,$orphan], true)
                && $after[$rowId][$field] === 'receipt_unknown'),
            'preserve evidence ' . $field);
    }
}
mail_assert(brvtal_ticket_mail_claim($pdo) === null, 'uncertain cannot be claimed');
mail_assert(brvtal_ticket_mail_deliver_one($pdo,
    static function (array $payload, array $headers) use (&$transportCalls): array {
        $transportCalls++;
        return ['status' => 200, 'body' => '{"id":"must-not-send"}'];
    }) === 'idle', 'worker cannot replay reconciled jobs');
mail_assert($transportCalls === 0, 'uncertain never invokes the injected transport');
$lockCase = $enqueueForReconciliation('lock-case');
$set($lockCase, 'sending', 2, $recent, $past, $past);
$pdo->exec('SET innodb_lock_wait_timeout=1');
$second->exec('SET innodb_lock_wait_timeout=1');
$pdo->beginTransaction();
$pdo->query("SELECT id FROM ticket_mail_outbox WHERE id=$lockCase FOR UPDATE")->fetchColumn();
try {
    brvtal_ticket_mail_reconcile_stale($second, 1);
    mail_assert(false, 'competing worker must not bypass lock');
} catch (PDOException $error) {
    mail_assert(in_array((string)$error->getCode(), ['HY000','40001'], true),
        'competing worker is fenced by DB row lock');
}
$pdo->rollBack();
mail_assert(brvtal_ticket_mail_reconcile_stale($second, 1) === 1, 'lock released permits safe reconcile');
mail_assert((string)$pdo->query("SELECT state FROM ticket_mail_outbox WHERE id=$lockCase")
    ->fetchColumn() === 'uncertain', 'late worker cannot send orphan');
try {
    brvtal_ticket_mail_reconcile_stale($pdo, 0);
    mail_assert(false, 'zero limit must fail');
} catch (InvalidArgumentException $error) {
    mail_assert($error->getMessage() === 'MAIL_RECONCILE_INVALID_LIMIT', 'invalid limit reason');
}
mail_assert((string)$pdo->query('SELECT status FROM synthetic_paid_ticket')->fetchColumn() === 'paid',
    'reconciliation does not change paid tickets');


// #2134: an ambiguous legacy reminder on an outbox-only schema fails closed,
// and a stale claimed generation cannot write a suppression or authorize delivery.
$pdo->beginTransaction();
$legacy = brvtal_ticket_mail_enqueue($pdo, 'order:2134:reminder', 'order:2134',
    'legacy@example.test', 'Synthetic', 'Unknown purpose');
$pdo->commit();
$legacyJob = brvtal_ticket_mail_claim($pdo);
mail_assert($legacyJob !== null && (int)$legacyJob['id'] === $legacy, 'legacy claimed');
$pdo->exec("UPDATE ticket_mail_outbox SET locked_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=$legacy");
mail_assert(brvtal_ticket_mail_prepare_dispatch($pdo, $legacyJob)==='lease_lost', 'expired lease cannot suppress or dispatch');
mail_assert(brvtal_ticket_mail_reconcile_stale($second, 2)===1, 'other worker reconciles stale legacy');
mail_assert(!brvtal_ticket_mail_redrive($pdo,$legacy), 'stale legacy cannot redrive');

// #2126: exercise legacy, tampered and context-swapped jobs against the worker.
$pdo->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticket_mail_html_01.sql'));
$oldConfig = $GLOBALS['config'] ?? null;
$GLOBALS['config']['security']['encryption_key'] = str_repeat('mail-fixture-', 4);
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
putenv('BRVTAL_TICKET_MAIL_MODE=mock');
$pdo->beginTransaction();
$sealedId = brvtal_ticket_waitlist_mail_enqueue($pdo, 2126, 'offer',
    'sealed@example.test','Synthetic','Synthetic offer');
$pdo->commit();
$sealedJob = $pdo->query("SELECT * FROM ticket_mail_outbox WHERE id=$sealedId")->fetch(PDO::FETCH_ASSOC);
mail_assert(brvtal_ticket_waitlist_mail_open($sealedJob)!==null,'waitlist envelope opens');
$renamed = BRVTAL_BUYER_MAIL_SEAL_PREFIX.substr($sealedJob['body_text'], strlen(BRVTAL_WAITLIST_MAIL_PREFIX));
mail_assert(brvtalBuyerMailUnseal($renamed)===null,'buyer-auth cannot open re-prefixed waitlist ciphertext');
$bytes=base64_decode(substr($sealedJob['body_text'],strlen(BRVTAL_WAITLIST_MAIL_PREFIX)),true);
if (!is_string($bytes) || strlen($bytes) <= 28) throw new RuntimeException('fixture envelope missing');
$bytes[28]=chr(ord($bytes[28]) ^ 1);
mail_assert(brvtal_ticket_waitlist_mail_open(array_replace($sealedJob,
    ['body_text'=>BRVTAL_WAITLIST_MAIL_PREFIX.base64_encode($bytes)]))===null,
    'modified ciphertext rejected');
foreach (['related_ref'=>'waitlist:2127','recipient_email'=>'other@example.test',
    'subject'=>'Other','template_key'=>'waitlist_join','template_version'=>1,
    'message_key'=>'waitlist:offer:2126:0000000000',
    'body_html'=>$sealedJob['body_text']] as $field=>$value) {
    mail_assert(brvtal_ticket_waitlist_mail_open(array_replace($sealedJob,[$field=>$value]))===null,
        'envelope rejects altered context or part');
}
$pdo->exec("INSERT INTO ticket_mail_outbox
    (message_key,payload_hash,related_ref,recipient_email,subject,body_text)
    VALUES ('waitlist:join:2127:0000000000',REPEAT('a',64),'waitlist:2127',
    'legacy@example.test','Synthetic','Historical cleartext')");
$clearId=(int)$pdo->lastInsertId();
$pdo->prepare("UPDATE ticket_mail_outbox SET body_html=? WHERE id=?")
    ->execute([$sealedJob['body_text'],$sealedId]);
$forbiddenCalls=0;
$forbiddenTransport=static function () use (&$forbiddenCalls): array {
    $forbiddenCalls++;
    return ['status'=>200,'body'=>'{"id":"unexpected"}'];
};
mail_assert(brvtal_ticket_mail_deliver_one($pdo,$forbiddenTransport)==='failed','swapped envelope rejected');
mail_assert(brvtal_ticket_mail_deliver_one($pdo,$forbiddenTransport)==='failed','legacy cleartext rejected');
mail_assert($forbiddenCalls===0,'no transport on invalid or legacy waitlist jobs');
mail_assert(!brvtal_ticket_mail_redrive($pdo,$clearId)
    && !brvtal_ticket_mail_redrive($pdo,$sealedId),'invalid waitlist cannot redrive');
mail_assert($pdo->query("SELECT body_text FROM ticket_mail_outbox WHERE id=$clearId")->fetchColumn()
    ==='Historical cleartext','historical body is not rewritten');
$pdo->prepare("UPDATE ticket_mail_outbox SET state='failed',attempts=2,last_error_code='provider_rejected'
    WHERE id=?")->execute([$sealedId]);
mail_assert(!brvtal_ticket_mail_redrive($pdo,$sealedId)
    && $pdo->query("SELECT state FROM ticket_mail_outbox WHERE id=$sealedId")->fetchColumn()==='uncertain',
    'later waitlist rejection cannot erase an earlier ambiguous attempt');
if ($oldConfig === null) unset($GLOBALS['config']); else $GLOBALS['config']=$oldConfig;

echo "BRVTAL ticket-mail outbox integration OK.\n";

// T11: run signed webhook and durable history tests in the existing canonical mail integration suite.
require __DIR__ . '/ticketing-mail-history.php';

// #2063: provider evidence can arrive after a local lease became uncertain.
// The provider audit is separate from the local work state; do not rewrite the outbox.
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
$pdo->exec("INSERT INTO ticket_mail_outbox
    (message_key,payload_hash,related_ref,recipient_email,subject,body_text,state,provider_message_id)
    VALUES ('test:late:2063',REPEAT('d',64),'entry:late','late@example.test',
    'Synthetic','No real delivery','uncertain','late-provider-2063')");
$lateId = (int)$pdo->lastInsertId();
$lateHistory = brvtal_mail_history_record($pdo, $lateId, ['order_id' => 2063]);
brvtal_mail_history_event($pdo, 'late-event-2063', 'late-provider-2063', 'delivered',
    new DateTimeImmutable('now', new DateTimeZone('UTC')), hash('sha256', 'synthetic-late'));
$lateRead = $pdo->prepare('SELECT delivery_state FROM ticket_mail_history WHERE id=?');
$lateRead->execute([$lateHistory]);
mail_assert($lateRead->fetchColumn() === 'delivered', 'late authentic provider event survives');
mail_assert((string)$pdo->query("SELECT state FROM ticket_mail_outbox WHERE id=$lateId")
    ->fetchColumn() === 'uncertain', 'provider status never fabricates a local send');
mail_assert(brvtal_ticket_mail_reconcile_stale($pdo, 2) === 0,
    'reconciler cannot rewrite a late delivered event');
putenv('BRVTAL_TICKET_MAIL_TESTING=0');

// T28: run the isolated worker cycle fixture in the canonical database gate.
(static function (): void {
    require __DIR__ . '/ticketing-mail-worker.php';
})();

// T34: run the buyer-auth synthetic MariaDB contracts in the canonical database gate.
require __DIR__ . '/buyer-auth-flows.php';
