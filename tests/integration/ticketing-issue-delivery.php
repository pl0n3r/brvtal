<?php
declare(strict_types=1);

/** #1945: paid order -> tickets + sealed QR email in one transaction (disposable MariaDB). */
require_once __DIR__ . '/../../config/ticketing_issue_delivery.php';
require_once __DIR__ . '/../../config/ticketing_wompi_webhook.php';
require_once __DIR__ . '/../../config/buyer_account.php';
require_once __DIR__ . '/../../config/migration_reconcile.php';

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
// #1956: keep the last-failure diagnostic inside the test's own temp file.
$lastFailureFile = sys_get_temp_dir() . '/brvtal-issue-last-' . bin2hex(random_bytes(4)) . '.json';
putenv('BRVTAL_TICKET_ISSUE_LAST_FAILURE_FILE=' . $lastFailureFile);
register_shutdown_function(static fn() => @unlink($lastFailureFile));
foreach ([
    "CREATE TABLE events(id INT UNSIGNED PRIMARY KEY,title VARCHAR(180) NOT NULL,
        event_date DATE NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE event_ticket_types(id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE admins(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB",
    "CREATE TABLE ticket_buyers(id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190) NULL,
        status ENUM('guest','active') NOT NULL DEFAULT 'guest',
        password_hash VARCHAR(255) NULL,email_verified_at DATETIME NULL) ENGINE=InnoDB",
    "CREATE TABLE ticket_orders(id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,
        buyer_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL,
        public_id BINARY(16) NULL,total_cop BIGINT NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT 'COP',
        created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
    "CREATE TABLE ticket_order_items(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
        event_id INT UNSIGNED NOT NULL,ticket_type_id INT UNSIGNED NOT NULL,quantity INT UNSIGNED NOT NULL,
        ticket_name_snapshot VARCHAR(120) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE ticket_payment_attempts(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(24) NOT NULL,provider_transaction_id VARCHAR(190) NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB",
    "CREATE TABLE ticket_buyer_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        buyer_id BIGINT UNSIGNED NOT NULL,purpose ENUM('verify_email','reset_password') NOT NULL,
        token_hash CHAR(64) NOT NULL,expires_at_utc DATETIME(6) NOT NULL,
        consumed_at_utc DATETIME(6) NULL,revoked_at_utc DATETIME(6) NULL) ENGINE=InnoDB",
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
// New credentials get recoverable ciphertext, historical ones remain nullable.
$entrySecretMigration = file_get_contents(__DIR__ . '/../../database/migration_ticket_entry_secret_01.sql');
deliveryAssert(is_string($entrySecretMigration), 'entry-secret migration present');
foreach (explode(';', preg_replace('/^\\h*--[^\\r\\n]*$/m', '', $entrySecretMigration)) as $ddl) {
    if (trim($ddl) !== '') $db->exec($ddl);
}
$db->exec('CREATE TABLE qr_nullable_probe(nullable VARBINARY(96) NULL,
    required VARBINARY(96) NOT NULL) ENGINE=InnoDB');
deliveryAssert(brvtalMigrationProbeRequirement($db,
    'column-nullable:qr_nullable_probe.nullable'), 'nullable schema column verified');
deliveryAssert(!brvtalMigrationProbeRequirement($db,
    'column-nullable:qr_nullable_probe.required'), 'NOT NULL schema column rejected');
deliveryAssert(!brvtalMigrationProbeRequirement($db,
    'column-nullable:qr_nullable_probe.missing'), 'missing schema column rejected');
$db->exec("INSERT INTO events(id,title,event_date)
    VALUES (1,'GENESIS',DATE_ADD(CURDATE(),INTERVAL 30 DAY))");
$db->exec('INSERT INTO admins(id) VALUES (7)');
$db->exec('INSERT INTO event_ticket_types(id,event_id) VALUES (11,1),(12,1)');
$db->exec("INSERT INTO ticket_buyers(id,email,status,email_verified_at)
    VALUES (8,'ana@example.test','active',UTC_TIMESTAMP()),
           (9,'beto@example.test','active',UTC_TIMESTAMP())");
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
// Regression: loading logger.php for the first time from a callback must
// still initialize the GLOBAL path used by exception/shutdown handlers.
(static function (): void {
    require_once __DIR__ . '/../../config/logger.php';
})();
deliveryAssert(is_string($GLOBALS['BRVTAL_LOG_FILE'] ?? null)
    && $GLOBALS['BRVTAL_LOG_FILE'] !== '',
    'logger path survives initialization inside the paid delivery callback');
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
deliveryAssert(!str_contains($text,'Crea tu contraseña')
    && !str_contains($html,'Crea tu contraseña'),
    'registered buyer receives no guest-only password link');
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


// #1978: ciphertext is never equal to or a substring of the token, is scoped
// to the buyer and cannot be read with another buyer's authenticated scope.
$issuedCredentials = $db->query("SELECT t.public_id,c.token_encrypted,c.token_hash
    FROM ticket_entries t JOIN ticket_order_items i ON i.id=t.order_item_id
    JOIN ticket_entry_credentials c ON c.entry_id=t.id
    WHERE i.order_id=100 ORDER BY t.id")->fetchAll(PDO::FETCH_ASSOC);
deliveryAssert(count($issuedCredentials)===3, 'three encrypted ticket credentials');
foreach ($issuedCredentials as $record) {
    deliveryAssert(is_string($record['token_encrypted']) && strlen($record['token_encrypted'])===61,
        'AES-GCM v1 nonce, tag and ciphertext persisted');
    $plain = brvtal_ticket_issue_open_token($record['token_encrypted'], $record['public_id']);
    deliveryAssert(hash_equals($record['token_hash'],hash('sha256',$plain,true)),
        'authenticated decrypt matches stored digest');
    deliveryAssert(!str_contains($record['token_encrypted'],$plain),
        'plaintext not persisted in ciphertext');
    $id = bin2hex($record['public_id']);
    $stored = brvtalBuyerAccountStoredQr($db,8,$id);
    deliveryAssert(str_starts_with($stored['content'],'BRVTAL:T1:'.$id.':'),
        'owner can recover the same valid QR');
    try { brvtalBuyerAccountStoredQr($db,9,$id); deliveryAssert(false,'foreign buyer got QR'); }
    catch (DomainException $e) { deliveryAssert($e->getMessage()==='TICKET_UNAVAILABLE','foreign buyer denied'); }
}
// Guests with checkout proof may access only that paid order, never other
// orders sharing the buyer email. Removing the order scope must fail closed.
$db->exec("INSERT INTO ticket_orders(id,event_id,buyer_id,status)
    VALUES (107,1,8,'paid')");
$db->exec("UPDATE ticket_buyers SET status='guest',email_verified_at=NULL WHERE id=8");
$_SESSION = ['buyer_id'=>8,'buyer_checkout_new_order'=>100];
$guestId = bin2hex($issuedCredentials[0]['public_id']);
deliveryAssert(str_starts_with(brvtalBuyerAccountStoredQr($db,8,$guestId)['content'],'BRVTAL:T1:'),
    'guest can recover only the checkout-paid ticket');
$guestOrders = brvtalBuyerAccountOrders($db,8,brvtalBuyerAccountFilters(['page'=>'0']));
deliveryAssert(count($guestOrders['orders']) === 1
    && $guestOrders['orders'][0]['order_number'] === 100,
    'guest account list never exposes another order with same email');
$_SESSION['buyer_checkout_new_order'] = 107;
try { brvtalBuyerAccountStoredQr($db,8,$guestId); deliveryAssert(false,'cross-order guest QR leaked'); }
catch (DomainException $e) { deliveryAssert($e->getMessage()==='TICKET_UNAVAILABLE','guest order scope denied'); }
unset($_SESSION['buyer_checkout_new_order']);
try { brvtalBuyerAccountStoredQr($db,8,$guestId); deliveryAssert(false,'guest without scope leaked QR'); }
catch (DomainException $e) { deliveryAssert($e->getMessage()==='TICKET_UNAVAILABLE','guest without order denied'); }
$db->exec("UPDATE ticket_buyers SET status='active',email_verified_at=UTC_TIMESTAMP() WHERE id=8");
$_SESSION = [];

$first = $issuedCredentials[0];
$badSeal = $first['token_encrypted'];
$badSeal[40] = chr(ord($badSeal[40]) ^ 1);
try { brvtal_ticket_issue_open_token($badSeal,$first['public_id']); deliveryAssert(false,'tampered seal accepted'); }
catch (DomainException $e) { deliveryAssert($e->getMessage()==='TICKET_UNAVAILABLE','tampered seal rejected'); }

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
$last = brvtal_ticket_issue_last_failure();
deliveryAssert($last !== null && $last['code'] === 'ISSUE_DELIVERY_RECIPIENT'
    && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $last['at']) === 1, 'last failure remembered (#1956)');
deliveryAssert(!str_contains((string)file_get_contents($lastFailureFile), '@example'), 'diagnostic holds no buyer data');
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
// #1976: the job waits an hour; the admin retry brings it forward without issuing itself.
deliveryAssert(brvtal_ticket_issue_process_next($db, $origin) === 'idle', 'hour-long backoff hides the job');
deliveryAssert(brvtal_ticket_issue_retry_now($db, 105) === true, 'admin retry accepted for a failed job');
deliveryAssert($count('SELECT COUNT(*) FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id WHERE i.order_id=105') === 0,
    'the retry itself issues nothing');
deliveryAssert(brvtal_ticket_issue_retry_now($db, 105) === true, 'retrying an already due job is harmless');
deliveryAssert(brvtal_ticket_issue_process_next($db, $origin) === 'issued', 'issued once the cause is fixed');
deliveryAssert(brvtal_ticket_issue_retry_now($db, 105) === false, 'an issued order has nothing to retry');
deliveryAssert(brvtal_ticket_issue_retry_now($db, 999) === false, 'an unknown order has nothing to retry');
try {
    brvtal_ticket_issue_retry_now($db, 0);
    deliveryAssert(false, 'invalid order id accepted');
} catch (InvalidArgumentException) {
}
deliveryAssert(brvtal_ticket_issue_last_failure() === null, 'a successful issuance clears the last failure');
file_put_contents($lastFailureFile, json_encode(['code'=>'ana@example.test','at'=>'2026-11-24T00:00:00Z']));
deliveryAssert(brvtal_ticket_issue_last_failure() === null, 'a tampered diagnostic is never printed');
deliveryAssert(brvtal_ticket_issue_failure_code("SQLSTATE[42S02]: Base table 'x' doesn't exist") === 'ISSUE_FAILED'
    && brvtal_ticket_issue_failure_code('ana@example.test') === 'ISSUE_FAILED', 'free text never leaves the worker');

// #1966: a verified payment's tickets never hit the public per-recipient limit; account mail still does.
$limitFile = __DIR__ . '/../../storage/rate_limits/public-mail-hourly.json';
$limitBackup = is_file($limitFile) ? file_get_contents($limitFile) : null;
register_shutdown_function(static function () use ($limitFile, $limitBackup): void {
    if ($limitBackup === null) @unlink($limitFile); else file_put_contents($limitFile, $limitBackup);
});
$GLOBALS['config']['ticketing']['mail'] = ['testing'=>'0', 'privacy_approved'=>'1', 'lookup_secret'=>str_repeat('a', 64)];
putenv('BRVTAL_TICKET_MAIL_TESTING=0');
$rateRecipient = 'rate-' . bin2hex(random_bytes(3)) . '@example.test';
for ($i = 0; $i < 6; $i++) brvtal_public_abuse_mail_limit($rateRecipient);
deliveryAssert(brvtal_public_abuse_mail_limit($rateRecipient)['allowed'] === false, 'recipient limit exhausted');
$db->exec("INSERT INTO ticket_buyers(id,email) VALUES (11,'$rateRecipient')");
$db->exec("INSERT INTO ticket_orders(id,event_id,buyer_id,status) VALUES (106,1,11,'paid')");
$db->exec("INSERT INTO ticket_order_items(id,order_id,event_id,ticket_type_id,quantity,ticket_name_snapshot)
    VALUES (206,106,1,11,1,'Preventa')");
$db->exec("INSERT INTO ticket_payment_attempts(id,order_id,provider,provider_transaction_id,status)
    VALUES (6,106,'wompi','tx-106','approved')");
$journal->execute([6,'tx-106',hash('sha256','synthetic-6'),'APPROVED',time()]);
$db->beginTransaction();
brvtal_wompi_hook_enqueue($db, 106, 6);
$db->commit();
deliveryAssert(brvtal_ticket_issue_process_next($db, 'https://www.brvtal.com.co') === 'issued',
    'paid tickets are issued despite an exhausted recipient limit');
deliveryAssert($count("SELECT COUNT(*) FROM ticket_mail_outbox WHERE message_key='order:106:payment_approved'") === 1,
    'paid tickets email queued');
$db->beginTransaction();
try {
    brvtal_ticket_mail_enqueue($db, 'buyer:11:reset_password:1', 'buyer:11', $rateRecipient, 'Restablece', 'Texto');
    deliveryAssert(false, 'account mail must stay rate limited');
} catch (DomainException $error) {
    deliveryAssert($error->getMessage() === 'MAIL_RATE_LIMITED', 'account mail keeps the public limit');
} finally {
    $db->rollBack();
}
$GLOBALS['config']['ticketing']['mail'] = ['testing'=>'1'];
putenv('BRVTAL_TICKET_MAIL_TESTING=1');


// #1985: the REAL issuance path includes a sealed, one-use setup URL in the
// same QR purchase email for a guest, never for a registered buyer.
$db->exec("INSERT INTO ticket_buyers(id,email,status,email_verified_at,password_hash)
    VALUES (12,'guest-1985@example.test','guest',NULL,NULL)");
$db->exec("INSERT INTO ticket_orders(id,event_id,buyer_id,status)
    VALUES (108,1,12,'paid')");
$db->exec("INSERT INTO ticket_order_items
    (id,order_id,event_id,ticket_type_id,quantity,ticket_name_snapshot)
    VALUES (208,108,1,11,1,'Preventa')");
$db->exec("INSERT INTO ticket_payment_attempts
    (id,order_id,provider,provider_transaction_id,status)
    VALUES (8,108,'wompi','tx-108','approved')");
$journal->execute([8,'tx-108',hash('sha256','synthetic-8'),'APPROVED',time()]);
$db->beginTransaction();
brvtal_wompi_hook_enqueue($db,108,8);
$db->commit();
deliveryAssert(brvtal_ticket_issue_process_next($db,$origin)==='issued',
    'guest paid order uses the same verified ticket issuance');
$guestMail=$db->query("SELECT recipient_email,body_text,body_html
    FROM ticket_mail_outbox WHERE message_key='order:108:payment_approved'")->fetch(PDO::FETCH_ASSOC);
deliveryAssert(is_array($guestMail)
    && $guestMail['recipient_email']==='guest-1985@example.test'
    && str_starts_with((string)$guestMail['body_text'],BRVTAL_BUYER_MAIL_SEAL_PREFIX)
    && str_starts_with((string)$guestMail['body_html'],BRVTAL_BUYER_MAIL_SEAL_PREFIX),
    'one guest purchase email is sealed at rest');
$guestText=(string)brvtalBuyerMailUnseal((string)$guestMail['body_text']);
$guestHtml=(string)brvtalBuyerMailUnseal((string)$guestMail['body_html']);
deliveryAssert(str_contains($guestText,'Crea tu contraseña')
    && str_contains($guestHtml,'Crea tu contraseña')
    && str_contains($guestText,'Boleta 1 · Preventa'),
    'the single purchase email contains both QR and setup CTA');
deliveryAssert(preg_match(
    '~https://www\.brvtal\.com\.co/mi-cuenta/restablecer\?accion=confirm-reset&token=([A-Za-z0-9_-]{43})&origen=compra~',
    $guestText,$passwordLink)===1, 'guest link has canonical reset route');
$guestToken=$passwordLink[1];
$tokenDigest=$db->query("SELECT token_hash FROM ticket_buyer_tokens
    WHERE buyer_id=12 AND purpose='reset_password'")->fetchColumn();
deliveryAssert(is_string($tokenDigest)
    && hash_equals($tokenDigest,hash('sha256',$guestToken))
    && !str_contains((string)$guestMail['body_text'],$guestToken),
    'one-use guest token persisted as hash; no plaintext in DB');
deliveryAssert(str_contains($guestHtml,htmlspecialchars($passwordLink[0],ENT_QUOTES)),
    'the HTML CTA uses the same token as text');
deliveryAssert($count("SELECT COUNT(*) FROM ticket_mail_outbox
    WHERE message_key='order:108:payment_approved'")===1
    && brvtal_ticket_issue_process_next($db,$origin)==='idle',
    'worker replay cannot duplicate the guest mail or setup token');
try {
    brvtal_mail_delivery_render($db,'payment_approved',[
        'event'=>'GENESIS','link'=>'https://brvtal.invalid/buyer/',
        'guest_password_link'=>'https://evil.invalid/?token='.str_repeat('A',43),
    ]);
    deliveryAssert(false,'untrusted account creation link rendered');
} catch (InvalidArgumentException $e) {
    deliveryAssert($e->getMessage()==='MAIL_GUEST_PASSWORD_LINK_INVALID',
        'renderer rejects externally supplied account-creation URL');
}

// Public origins only.
try {
    brvtal_ticket_issue_process_next($db, 'https://evil.example');
    deliveryAssert(false, 'foreign origin accepted');
} catch (InvalidArgumentException $error) {
    deliveryAssert($error->getMessage() === 'ISSUE_DELIVERY_ORIGIN', 'foreign origin rejected');
}

echo "BRVTAL #1945 issuance + sealed QR email passed against disposable MariaDB.\n";
