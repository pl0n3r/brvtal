<?php
declare(strict_types=1);

/** #1945: paid order -> tickets + sealed QR email in one transaction (disposable MariaDB). */
require_once __DIR__ . '/../../config/ticketing_issue_delivery.php';
require_once __DIR__ . '/../../config/ticketing_wompi_webhook.php';

function deliveryAssert(bool $ok, string $what): void
{
    if (!$ok) throw new RuntimeException('ISSUE_DELIVERY: ' . $what);
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL #1945 issuance delivery needs BRVTAL_INTEGRATION_TESTS=1 and a disposable MariaDB.\n";
    exit(0);
}
$base = (string)getenv('BRVTAL_TEST_DB_NAME');
deliveryAssert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $base), 'disposable DB namespace');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$opt = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, $opt);
$name = $base . '_delivery_' . bin2hex(random_bytes(3));
$server->exec("CREATE DATABASE $name CHARACTER SET utf8mb4");
register_shutdown_function(static function () use ($server, $name): void {
    try { $server->exec("DROP DATABASE IF EXISTS $name"); } catch (Throwable) {}
});
$db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, $opt);

// Synthetic fixture only: no real buyer, key or provider.
$GLOBALS['config'] = ['security'=>['encryption_key'=>str_repeat('k', 48)],
    'ticketing'=>['mail'=>['testing'=>'1']]];
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
foreach ([
    "CREATE TABLE events(id INT UNSIGNED PRIMARY KEY,title VARCHAR(180) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE event_ticket_types(id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE admins(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
    "CREATE TABLE ticket_buyers(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190) NULL) ENGINE=InnoDB",
    "CREATE TABLE ticket_orders(id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,
        buyer_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE ticket_order_items(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
        event_id INT UNSIGNED NOT NULL,ticket_type_id INT UNSIGNED NOT NULL,quantity INT UNSIGNED NOT NULL,
        ticket_name_snapshot VARCHAR(120) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE ticket_payment_attempts(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(24) NOT NULL,provider_transaction_id VARCHAR(190) NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB",
] as $ddl) {
    $db->exec($ddl);
}
foreach (['ticketing_entries_01','ticketing_webhook_01','ticketing_mail_outbox_01',
          'ticket_mail_html_01','ticketing_mail_history_01'] as $migration) {
    $sql = file_get_contents(__DIR__ . '/../../database/migration_' . $migration . '.sql');
    deliveryAssert(is_string($sql) && strlen($sql) > 50, $migration . ' present');
    $sql = (string)preg_replace('/^\h*--[^\r\n]*$/m', '', $sql);
    foreach (explode(';', $sql) as $ddl) if (trim($ddl) !== '') $db->exec($ddl);
}
$db->exec("INSERT INTO events(id,title) VALUES (1,'GENESIS')");
$db->exec('INSERT INTO admins(id) VALUES (7)');
$db->exec('INSERT INTO event_ticket_types(id,event_id) VALUES (11,1),(12,1)');
$db->exec("INSERT INTO ticket_buyers(id,email) VALUES (8,'ana@example.test'),(9,'beto@example.test')");
$db->exec("INSERT INTO ticket_orders(id,event_id,buyer_id,status) VALUES (100,1,8,'paid'),(103,1,9,'paid')");
$db->exec("INSERT INTO ticket_order_items(id,order_id,event_id,ticket_type_id,quantity,ticket_name_snapshot)
    VALUES (200,100,1,11,2,'Preventa'),(201,100,1,12,1,'VIP'),(204,103,1,11,1,'Preventa')");
$db->exec("INSERT INTO ticket_payment_attempts(id,order_id,provider,provider_transaction_id,status)
    VALUES (1,100,'wompi','tx-100','approved'),(4,103,'wompi','tx-103','approved')");
$journal = $db->prepare('INSERT INTO ticket_payment_webhook_events
    (attempt_id,provider_transaction_id,event_checksum,signed_status,event_timestamp) VALUES (?,?,?,?,?)');
foreach ([[1,'tx-100'],[4,'tx-103']] as [$attempt,$tx]) {
    $journal->execute([$attempt,$tx,hash('sha256','synthetic-'.$attempt),'APPROVED',time()]);
}
foreach ([[100,1],[103,4]] as [$order,$attempt]) {
    $db->beginTransaction();
    brvtal_wompi_hook_enqueue($db, $order, $attempt);
    $db->commit();
}
$count = static fn(string $sql): int => (int)$db->query($sql)->fetchColumn();
$origin = 'https://brvtal.invalid';

// AC-01 (a): the oldest paid order is issued and its sealed QR email queued.
deliveryAssert(brvtal_ticket_issue_process_next($db, $origin) === 'issued', 'first job issued');
deliveryAssert($count('SELECT COUNT(*) FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id WHERE i.order_id=100') === 3,
    'one entry per unit');
deliveryAssert($count("SELECT COUNT(*) FROM ticket_mail_outbox WHERE message_key='order:100:payment_approved'") === 1,
    'exactly one paid-order email');
$mail = $db->query("SELECT recipient_email,body_text,body_html FROM ticket_mail_outbox
    WHERE message_key='order:100:payment_approved'")->fetch();
deliveryAssert($mail['recipient_email'] === 'ana@example.test', 'mail goes to the buyer');
// AC-02: no bearer token stored in clear text.
foreach (['body_text','body_html'] as $column) {
    deliveryAssert(str_starts_with((string)$mail[$column], BRVTAL_BUYER_MAIL_SEAL_PREFIX), $column . ' sealed');
    deliveryAssert(!str_contains((string)$mail[$column], 'BRVTAL:T1:'), $column . ' has no clear QR');
}
$text = (string)brvtalBuyerMailUnseal((string)$mail['body_text']);
$html = (string)brvtalBuyerMailUnseal((string)$mail['body_html']);
preg_match_all('~https://brvtal\.invalid/mi-cuenta/boleta#(BRVTAL:T1:([a-f0-9]{32}):([A-Za-z0-9_-]{43}))~', $text, $links, PREG_SET_ORDER);
deliveryAssert(count($links) === 3, 'one link per ticket in the text body');
deliveryAssert(str_contains($text, 'Pedido: #100') && str_contains($html, '<strong>Pedido:</strong> #100'),
    'email shows the order number (#1950)');
deliveryAssert(str_contains($text, 'Boleta 3 · VIP') && str_contains($html, 'Boleta 1 · Preventa'), 'tickets are labelled');
deliveryAssert(str_contains($html, htmlspecialchars($links[0][0], ENT_QUOTES)), 'html carries the same links');
$future = new DateTimeImmutable('+1 day', new DateTimeZone('UTC'));
foreach ($links as [, $payload, $publicId, $token]) {
    // Same payload the door scanner accepts: the credential digest matches.
    deliveryAssert(brvtal_ticket_issue_qr_payload($db, 8, $publicId, $token, $future) === $payload,
        'link payload validates for ' . $publicId);
}

// AC-01 (b): a repeat never re-issues nor re-sends.
$doneJob = $count('SELECT id FROM ticket_issue_outbox WHERE order_id=100');
deliveryAssert(brvtal_ticket_issue_consume($db, $doneJob, brvtal_ticket_issue_mail_deliverer($origin))['state'] === 'duplicate',
    'replay is a duplicate');
deliveryAssert($count('SELECT COUNT(*) FROM ticket_mail_outbox') === 1, 'replay sends nothing');

// AC-01 (c): a failing deliverer rolls back the whole issuance.
$otherJob = $count('SELECT id FROM ticket_issue_outbox WHERE order_id=103');
try {
    brvtal_ticket_issue_consume($db, $otherJob, static function (): void {
        throw new DomainException('SYNTHETIC_DELIVERY_DOWN');
    });
    deliveryAssert(false, 'failing deliverer must throw');
} catch (DomainException $error) {
    deliveryAssert($error->getMessage() === 'SYNTHETIC_DELIVERY_DOWN', 'deliverer error surfaces');
}
deliveryAssert($count('SELECT COUNT(*) FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id WHERE i.order_id=103') === 0,
    'no ticket survives a failed delivery');
deliveryAssert($db->query('SELECT state FROM ticket_issue_outbox WHERE order_id=103')->fetchColumn() === 'pending',
    'job stays pending after rollback');

// AC-01 (d): without the mail privacy approval nothing is issued and no attempt is spent.
$GLOBALS['config']['ticketing']['mail'] = ['testing'=>'0', 'privacy_approved'=>'0'];
deliveryAssert(brvtal_ticket_issue_process_next($db, $origin) === 'waiting', 'privacy gate waits');
$job = $db->query('SELECT state,attempts FROM ticket_issue_outbox WHERE order_id=103')->fetch();
deliveryAssert($job['state'] === 'pending' && (int)$job['attempts'] === 0, 'waiting keeps the job intact');
deliveryAssert($count('SELECT COUNT(*) FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id WHERE i.order_id=103') === 0,
    'privacy gate issues nothing');
deliveryAssert(brvtal_ticket_issue_process_next($db, $origin) === 'idle', 'backoff hides the waiting job');

// Once approved (and the backoff elapsed) the waiting order is delivered.
$GLOBALS['config']['ticketing']['mail'] = ['testing'=>'1'];
$db->exec('UPDATE ticket_issue_outbox SET next_attempt_at_utc=NULL WHERE order_id=103');
deliveryAssert(brvtal_ticket_issue_process_next($db, $origin) === 'issued', 'approved gate resumes issuance');
deliveryAssert($count("SELECT COUNT(*) FROM ticket_mail_outbox WHERE recipient_email='beto@example.test'") === 1,
    'second buyer gets one email');
deliveryAssert(brvtal_ticket_issue_process_next($db, $origin) === 'idle', 'queue drained');

// #1954: a failure reports a controlled code and keeps retrying with growing backoff.
$db->exec("INSERT INTO ticket_buyers(id,email) VALUES (10,NULL)");
$db->exec("INSERT INTO ticket_orders(id,event_id,buyer_id,status) VALUES (105,1,10,'paid')");
$db->exec("INSERT INTO ticket_order_items(id,order_id,event_id,ticket_type_id,quantity,ticket_name_snapshot)
    VALUES (205,105,1,11,1,'Preventa')");
$db->exec("INSERT INTO ticket_payment_attempts(id,order_id,provider,provider_transaction_id,status)
    VALUES (5,105,'wompi','tx-105','approved')");
$journal->execute([5,'tx-105',hash('sha256','synthetic-5'),'APPROVED',time()]);
$db->beginTransaction();
brvtal_wompi_hook_enqueue($db, 105, 5);
$db->commit();
deliveryAssert(brvtal_ticket_issue_process_next($db, $origin) === 'failed:ISSUE_DELIVERY_RECIPIENT',
    'failure reports its controlled code');
$failed = $db->query("SELECT state,attempts,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(6),next_attempt_at_utc) AS wait
    FROM ticket_issue_outbox WHERE order_id=105")->fetch();
deliveryAssert($failed['state'] === 'failed' && (int)$failed['attempts'] === 1 && (int)$failed['wait'] >= 55,
    'first retry in about a minute');
deliveryAssert($count('SELECT COUNT(*) FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id WHERE i.order_id=105') === 0,
    'a failed issuance leaves no tickets');
$db->exec('UPDATE ticket_issue_outbox SET attempts=6,next_attempt_at_utc=NULL WHERE order_id=105');
deliveryAssert(brvtal_ticket_issue_process_next($db, $origin) === 'failed:ISSUE_DELIVERY_RECIPIENT',
    'no fixed cap: retried after 6 failures');
$failed = $db->query("SELECT attempts,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(6),next_attempt_at_utc) AS wait
    FROM ticket_issue_outbox WHERE order_id=105")->fetch();
deliveryAssert((int)$failed['attempts'] === 7 && (int)$failed['wait'] >= 3500 && (int)$failed['wait'] <= 3600,
    'backoff capped at one hour');
// Fixed cause: the next due run issues the tickets.
$db->exec("UPDATE ticket_buyers SET email='carla@example.test' WHERE id=10");
$db->exec('UPDATE ticket_issue_outbox SET next_attempt_at_utc=NULL WHERE order_id=105');
deliveryAssert(brvtal_ticket_issue_process_next($db, $origin) === 'issued', 'issued once the cause is fixed');
deliveryAssert(brvtal_ticket_issue_failure_code("SQLSTATE[42S02]: Base table 'x' doesn't exist") === 'ISSUE_FAILED'
    && brvtal_ticket_issue_failure_code('ana@example.test') === 'ISSUE_FAILED', 'free text never leaves the worker');

// Public origins only.
try {
    brvtal_ticket_issue_process_next($db, 'https://evil.example');
    deliveryAssert(false, 'foreign origin accepted');
} catch (InvalidArgumentException $error) {
    deliveryAssert($error->getMessage() === 'ISSUE_DELIVERY_ORIGIN', 'foreign origin rejected');
}

echo "BRVTAL #1945 issuance + sealed QR email passed against disposable MariaDB.\n";
