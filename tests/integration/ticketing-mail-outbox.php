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
echo "BRVTAL ticket-mail outbox integration OK.\n";
// #1874: isolate fixture locals from T11 history's PDO and globals.
(static function (): void {
    require __DIR__ . '/ticketing-mail-worker-secrets.php';
})();


// T11: run signed webhook and durable history tests in the existing canonical mail integration suite.
require __DIR__ . '/ticketing-mail-history.php';

// T34: run the buyer-auth synthetic MariaDB contracts in the canonical database gate.
require __DIR__ . '/buyer-auth-flows.php';
