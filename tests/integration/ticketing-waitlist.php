<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_waitlist.php';

function wlAssert(bool $ok,string $what): void {
    if (!$ok) throw new RuntimeException('T28 '.$what);
}
putenv('BRVTAL_WAITLIST_TESTING=0');
putenv('BRVTAL_WAITLIST_ENABLED=0');
wlAssert(!brvtal_waitlist_enabled(),'closed without operator gate');
putenv('BRVTAL_WAITLIST_OFFERS_ENABLED');
putenv('BRVTAL_WAITLIST_REDEMPTION_VERIFIED');
putenv('BRVTAL_WAITLIST_ENABLED=1');
putenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED=1');
wlAssert(brvtal_waitlist_enabled(),'synthetic signup gate enabled');
wlAssert(!brvtal_waitlist_offers_enabled(),
    'privacy/signup flags cannot activate offers without redemption verification');
putenv('BRVTAL_WAITLIST_ENABLED=0');
putenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED=0');
wlAssert(strlen(brvtal_waitlist_token())===43,'unpredictable token length');
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {
    echo "T28 fail-closed policy passed; MariaDB fixture skipped.\n";
    exit(0);
}
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
wlAssert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D',$base),'disposable DB namespace');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$opt);
$name=$base.'_waitlist_'.bin2hex(random_bytes(3));
$server->exec("CREATE DATABASE $name");
register_shutdown_function(static function () use($server,$name): void {
    try{$server->exec("DROP DATABASE IF EXISTS $name");}catch(Throwable){}
});
$db=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,$opt);
foreach ([
    "CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,status VARCHAR(32),event_date DATETIME,published_at DATETIME)",
    "CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,
        name VARCHAR(120) NOT NULL DEFAULT 'Test pass',price INT UNSIGNED NOT NULL DEFAULT 25000,
        sort_order INT NOT NULL DEFAULT 0,status VARCHAR(32),currency VARCHAR(3),
        available_from DATETIME,available_until DATETIME,
        UNIQUE KEY uq_type_event(id,event_id))",
    "CREATE TABLE ticket_buyers (id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190),
        email_verified_at DATETIME,status VARCHAR(20))",
    "CREATE TABLE ticket_inventory_events (event_id INT UNSIGNED PRIMARY KEY,capacity INT UNSIGNED)",
    "CREATE TABLE ticket_inventory_types (ticket_type_id INT UNSIGNED PRIMARY KEY,
        event_id INT UNSIGNED,capacity INT UNSIGNED,courtesy_capacity INT UNSIGNED DEFAULT 0)",
    "CREATE TABLE ticket_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_id INT UNSIGNED,
        buyer_id BIGINT UNSIGNED,status VARCHAR(20),public_id BINARY(16),checkout_key_hash CHAR(64) UNIQUE,
        currency CHAR(3),total_cop BIGINT UNSIGNED,expires_at_utc DATETIME(6),
        updated_at_utc DATETIME(6))",
    "CREATE TABLE ticket_order_items (order_id BIGINT UNSIGNED,ticket_type_id INT UNSIGNED,
        quantity INT,event_id INT UNSIGNED,ticket_name_snapshot VARCHAR(120),
        unit_price_cop BIGINT UNSIGNED,subtotal_cop BIGINT UNSIGNED,currency CHAR(3))",
    "CREATE TABLE ticket_inventory_holds (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED,event_id INT UNSIGNED,
        ticket_type_id INT UNSIGNED,quantity INT,kind VARCHAR(20),status VARCHAR(20),
        expires_at_utc DATETIME(6),UNIQUE KEY uq_hold_order_type(order_id,ticket_type_id))",
    "CREATE TABLE ticket_payment_attempts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED,provider VARCHAR(24),provider_reference VARCHAR(190),
        idempotency_key_hash CHAR(64),gross_amount_cop BIGINT UNSIGNED,status VARCHAR(20))"
] as $ddl) $db->exec($ddl);
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_waitlist_01.sql'));
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_mail_outbox_01.sql'));
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticket_mail_html_01.sql'));
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_mail_history_01.sql'));
$GLOBALS['config']['security']['encryption_key'] = str_repeat('t28-synthetic-', 4);
$readMail = static function (string $sql) use ($db): string {
    $job = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
    wlAssert(is_array($job), 'fixture mail exists');
    $plain = brvtal_ticket_waitlist_mail_open($job);
    wlAssert($plain !== null, 'valid contextual text and HTML envelope');
    wlAssert(str_starts_with($job['body_text'], BRVTAL_WAITLIST_MAIL_PREFIX)
        && str_starts_with($job['body_html'], BRVTAL_WAITLIST_MAIL_PREFIX), 'both stored parts sealed');
    return $plain['body_text'];
};
$now=new DateTimeImmutable('now',new DateTimeZone('America/Bogota'));
$publishedAt=$now->modify('-1 day')->format('Y-m-d H:i:s');
$db->prepare("INSERT INTO events VALUES (1,'published',?,?)")
    ->execute([$now->modify('+1 day')->format('Y-m-d H:i:s'),$publishedAt]);
$db->prepare("INSERT INTO event_ticket_types
    (id,event_id,status,currency,available_from,available_until) VALUES
    (10,1,'active','COP',?,?)")->execute([
        $now->modify('-1 hour')->format('Y-m-d H:i:s'),$now->modify('+2 hours')->format('Y-m-d H:i:s')]);
$db->exec("INSERT INTO ticket_inventory_events VALUES (1,1)");
$db->exec("INSERT INTO ticket_inventory_types VALUES (10,1,1,0)");
$db->exec("INSERT INTO ticket_buyers VALUES
    (1,'first@example.test','2026-11-01 00:00:00','active'),
    (2,'second@example.test','2026-11-01 00:00:00','active'),
    (3,'unverified@example.test',NULL,'active')");
putenv('BRVTAL_WAITLIST_TESTING=1');
try {
    brvtal_waitlist_assert_test_storage($server);
    wlAssert(false,'test flag must never permit a database outside brvtal_test namespace');
} catch (DomainException) {}
brvtal_waitlist_assert_test_storage($db);
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
putenv('BRVTAL_TICKET_MAIL_MODE=mock');
// A future publication is not a public event or a valid opt-in target.
$db->prepare("UPDATE events SET published_at=? WHERE id=1")
    ->execute([$now->modify('+2 days')->format('Y-m-d H:i:s')]);
wlAssert(brvtal_waitlist_public_types($db,1)===[],
    'pre-publication catalog cannot leak ticket types');
try {
    brvtal_waitlist_subscribe($db,1,10,1,'waitlist-v1','',$now);
    wlAssert(false,'pre-publication consent must not create an entry');
} catch (DomainException $e) {
    wlAssert($e->getMessage()==='WAITLIST_EVENT_CLOSED','unpublished gate');
}
wlAssert((int)$db->query('SELECT COUNT(*) FROM ticket_waitlist_entries')->fetchColumn()===0,
    'unpublished signup must roll back');
$db->prepare("UPDATE events SET published_at=? WHERE id=1")->execute([$publishedAt]);
$fixtureCipher = $GLOBALS['config']['security']['encryption_key'];
unset($GLOBALS['config']['security']['encryption_key']);
try {
    brvtal_waitlist_subscribe($db,1,10,1,'waitlist-v1','',$now);
    wlAssert(false,'missing key must abort signup');
} catch (RuntimeException $error) {
    wlAssert($error->getMessage()==='BUYER_MAIL_KEY_UNAVAILABLE','missing cipher key');
}
wlAssert((int)$db->query('SELECT COUNT(*) FROM ticket_waitlist_entries')->fetchColumn()===0
    && (int)$db->query('SELECT COUNT(*) FROM ticket_mail_outbox')->fetchColumn()===0,
    'signup and enqueue roll back together');
$GLOBALS['config']['security']['encryption_key']=$fixtureCipher;
wlAssert(brvtal_waitlist_subscribe($db,1,10,1,'waitlist-v1','',$now)==='waiting',
    'verified opt-in');
wlAssert(brvtal_waitlist_subscribe($db,1,10,1,'waitlist-v1','',$now)==='already_registered',
    'duplicate is idempotent');
wlAssert(brvtal_waitlist_subscribe($db,1,10,2,'waitlist-v1','',$now)==='waiting',
    'second interest');
wlAssert((int)$db->query('SELECT COUNT(*) FROM ticket_waitlist_entries')->fetchColumn()===2,
    'no double signup');
// AUTO_INCREMENT advances even when the failed enrollment transaction rolls back.
$firstEntry=(int)$db->query("SELECT id FROM ticket_waitlist_entries WHERE event_id=1 AND buyer_id=1")->fetchColumn();
$secondEntry=(int)$db->query("SELECT id FROM ticket_waitlist_entries WHERE event_id=1 AND buyer_id=2")->fetchColumn();
wlAssert($firstEntry>0 && $secondEntry>$firstEntry,'fixture preserves FIFO identity');
// T10 Resend transient retry is tested end-to-end through T28's own outbox message.
$attempts=0;
$sendSynthetic=static function (array $payload, array $headers) use (&$attempts): array {
    $attempts++;
    wlAssert($headers[0]==='Authorization: Bearer ', 'mock cannot expose API credentials');
    wlAssert($payload['to']===['first@example.test'], 'only the consented recipient');
    wlAssert(str_contains($payload['text'],'/api/ticketing/waitlist.php?token=')
        && str_contains($payload['html'],'href="https://www.brvtal.com.co/api/ticketing/waitlist.php?token='),
        'transport receives actionable text and HTML only after authenticated opening');
    return match ($attempts) {
        1 => ['status'=>429,'body'=>'{}','retry_after'=>1],
        2 => ['status'=>503,'body'=>'{}'],
        default => ['status'=>200,'body'=>'{"id":"synthetic-waitlist-accepted"}'],
    };
};
wlAssert(brvtal_ticket_mail_deliver_one($db,$sendSynthetic)==='retry',
    '429 must enter backoff instead of losing notification');
$db->exec("UPDATE ticket_mail_outbox SET next_attempt_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)
    WHERE recipient_email='first@example.test'");
wlAssert(brvtal_ticket_mail_deliver_one($db,$sendSynthetic)==='retry',
    '503 must remain retryable');
$db->exec("UPDATE ticket_mail_outbox SET next_attempt_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)
    WHERE recipient_email='first@example.test'");
wlAssert(brvtal_ticket_mail_deliver_one($db,$sendSynthetic)==='accepted',
    'Resend recovery must accept exactly one message');
wlAssert($attempts===3 && (int)$db->query("SELECT COUNT(*) FROM ticket_mail_outbox
    WHERE recipient_email='first@example.test' AND state='accepted'")->fetchColumn()===1,
    'retry cannot duplicate accepted notifications');
foreach ([[3,'waitlist-v1',''],[1,'no',''],[1,'waitlist-v1','bot']] as [$buyer,$consent,$honey]) {
    try { brvtal_waitlist_subscribe($db,1,10,$buyer,$consent,$honey,$now);
        wlAssert(false,'invalid signup denied');
    } catch (InvalidArgumentException|DomainException) {}
}
unset($GLOBALS['config']['security']['encryption_key']);
try {
    brvtal_waitlist_offer($db,1,10,$now);
    wlAssert(false,'missing key must abort offer');
} catch (RuntimeException $error) {
    wlAssert($error->getMessage()==='BUYER_MAIL_KEY_UNAVAILABLE','offer cipher failure');
}
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_waitlist_entries WHERE status='offered'")->fetchColumn()===0
    && (int)$db->query("SELECT COUNT(*) FROM ticket_mail_outbox")->fetchColumn()===2,
    'offer and queue rollback together');
$GLOBALS['config']['security']['encryption_key']=$fixtureCipher;
wlAssert(brvtal_waitlist_offer($db,1,10,$now)['entry_id']===$firstEntry,'FIFO invitation');
wlAssert(brvtal_waitlist_offer($db,1,10,$now)===null,'single capacity cannot over-offer');
$clock=brvtal_waitlist_clock($now)->format('Y-m-d H:i:s.u');
$usage=brvtal_ticket_inventory_usage($db,1,10,$clock);
wlAssert($usage['held']===1,'checkout counts offered capacity');
wlAssert((int)$db->query('SELECT COUNT(*) FROM ticket_orders')->fetchColumn()===0,
    'no order, charge or ticket from signup/offer');
$body=$readMail("SELECT * FROM ticket_mail_outbox
    WHERE related_ref='waitlist:{$firstEntry}' ORDER BY id LIMIT 1");
wlAssert(str_contains($body,'/api/ticketing/waitlist.php?token='),
    'email has actionable unsubscribe confirmation link');
wlAssert((bool)preg_match('/[A-Za-z0-9_-]{43}$/D',$body,$parts),
    'unsubscribe delivered by outbox');
$storedJoin=$db->query("SELECT * FROM ticket_mail_outbox WHERE related_ref='waitlist:{$firstEntry}' ORDER BY id LIMIT 1")->fetch();
foreach (['body_text','body_html','subject','message_key','related_ref','payload_hash'] as $field) {
    wlAssert(!str_contains((string)$storedJoin[$field],$parts[0]),'no persisted unsubscribe bearer');
}
wlAssert(brvtal_waitlist_unsubscribe($db,$parts[0]),'unsubscribe');
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_mail_outbox
    WHERE related_ref='waitlist:{$firstEntry}' AND state IN ('queued','retry','sending')")->fetchColumn()===0,
    'unsubscribe atomically cancels all pending/retryable notifications');
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_mail_outbox
    WHERE related_ref='waitlist:{$firstEntry}' AND state='suppressed'
    AND last_error_code='consent_revoked'")->fetchColumn()>=1,
    'revoked consent is not automatically retried');
wlAssert(!brvtal_waitlist_unsubscribe($db,$parts[0]),'unsubscribe replay');
wlAssert(brvtal_ticket_inventory_usage($db,1,10,$clock)['held']===0,
    'unsubscribe releases offer');
wlAssert(brvtal_waitlist_offer($db,1,10,$now)['entry_id']===$secondEntry,'next eligible entry');
wlAssert(brvtal_waitlist_offer($db,1,10,$now->modify('+11 minutes'))!==null,
    'expired offers return to inventory');
// A checkout may redeem a consented one-time invitation for exactly one
// inventory hold in its OWN transaction; never a payment or issued ticket.
$db->exec("INSERT INTO ticket_orders(id,event_id,buyer_id,status)
    VALUES (42,1,2,'draft'),(43,1,1,'draft')");
$db->exec("INSERT INTO ticket_order_items (order_id,ticket_type_id,quantity)
    VALUES (42,10,1),(43,10,1)");
$offerBody=$readMail("SELECT * FROM ticket_mail_outbox
    WHERE related_ref='waitlist:{$secondEntry}' AND subject='Cupo liberado en BRVTAL'
    ORDER BY id DESC LIMIT 1");
wlAssert(preg_match('/Código: ([A-Za-z0-9_-]{43})[.]/',$offerBody,$offerCode)===1,
    'offer token issued only to opted-in buyer');
$storedOffer=$db->query("SELECT * FROM ticket_mail_outbox WHERE related_ref='waitlist:{$secondEntry}'
    AND template_key='waitlist_offer' ORDER BY id DESC LIMIT 1")->fetch();
foreach (['body_text','body_html','subject','message_key','related_ref','payload_hash'] as $field) {
    wlAssert(!str_contains((string)$storedOffer[$field],$offerCode[1]),'no persisted invitation bearer');
}
// Select exact fixture jobs without introducing sleeps or changing worker order.
$readyMail=static function (int $id) use ($db): void {
    $db->exec("UPDATE ticket_mail_outbox SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY)
        WHERE state IN ('queued','retry')");
    $db->prepare('UPDATE ticket_mail_outbox SET next_attempt_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
};
$forbiddenCalls=0;
$noTransport=static function () use (&$forbiddenCalls): array {
    $forbiddenCalls++;
    return ['status'=>200,'body'=>'{"id":"unexpected"}'];
};
$staleOffer=(int)$db->query("SELECT id FROM ticket_mail_outbox WHERE related_ref='waitlist:{$secondEntry}'
    AND template_key='waitlist_offer' ORDER BY id ASC LIMIT 1")->fetchColumn();
$readyMail($staleOffer);
wlAssert(brvtal_ticket_mail_deliver_one($db,$noTransport)==='suppressed' && $forbiddenCalls===0,
    'replaced invitation never reaches transport while a different offer is live');
$redeemAt=$now->modify('+12 minutes');
// An expired but still-stored token must never redeem even before cleanup.
$db->beginTransaction();
try {
    brvtal_waitlist_redeem_into_hold($db,$offerCode[1],42,2,10,
        $now->modify('+22 minutes'));
    wlAssert(false,'expired offer cannot redeem');
} catch (DomainException) {
    $db->rollBack();
}
// Even a valid offer must survive failed checkout stock validation after
// the caller rolls its transaction back.
$db->beginTransaction();
$db->exec('UPDATE ticket_inventory_types SET capacity=0 WHERE ticket_type_id=10');
try {
    brvtal_waitlist_redeem_into_hold($db,$offerCode[1],42,2,10,$redeemAt);
    wlAssert(false,'no stock checkout must abort conversion');
} catch (DomainException) {
    $db->rollBack();
}
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_waitlist_entries
    WHERE buyer_id=2 AND status='offered'")->fetchColumn()===1,
    'failed hold rollback preserves original offered unit');
$db->beginTransaction();
try {
    brvtal_waitlist_redeem_into_hold($db,$offerCode[1],43,1,10,$redeemAt);
    wlAssert(false,'wrong buyer cannot redeem another buyer invitation');
} catch (DomainException) {
    $db->rollBack();
}
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_waitlist_entries
    WHERE buyer_id=2 AND status='offered'")->fetchColumn()===1,
    'wrong buyer leaves invitation intact');
$db->beginTransaction();
$claimed=brvtal_waitlist_redeem_into_hold($db,$offerCode[1],42,2,10,$redeemAt);
$db->commit();
wlAssert(($claimed['status']??null)==='active' && (int)$claimed['quantity']===1,
    'single-use invitation converts to paid T05 hold');
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_waitlist_entries
    WHERE buyer_id=2 AND status='redeemed' AND offer_hash IS NULL")->fetchColumn()===1,
    'redeemed offer is terminal');
wlAssert(brvtal_ticket_inventory_usage($db,1,10,
    brvtal_waitlist_clock($redeemAt)->format('Y-m-d H:i:s.u'))['held']===1,
    'held inventory counts exactly once after conversion');
$db->beginTransaction();
try {
    brvtal_waitlist_redeem_into_hold($db,$offerCode[1],42,2,10,$redeemAt);
    wlAssert(false,'redeemed token replay denied');
} catch (DomainException) {
    $db->rollBack();
}
$db->beginTransaction();
try {
    brvtal_ticket_inventory_hold($db,43,10,1,'paid',600,$redeemAt);
    wlAssert(false,'non-invited checkout cannot oversell');
} catch (DomainException) {
    $db->rollBack();
}
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_orders
    WHERE status='paid'")->fetchColumn()===0,
    'redemption did not charge, pay or issue a ticket');
$db->exec("UPDATE events SET status='cancelled' WHERE id=1");
try { brvtal_waitlist_offer($db,1,10,$now); wlAssert(false,'cancelled offer'); }
catch (DomainException) {}
try { brvtal_waitlist_subscribe($db,1,10,2,'waitlist-v1','',$now);
    wlAssert(false,'cancelled event enrollment'); }
catch (DomainException) {}

// Checkout T22 is now merged: validate the real adapter with a *live-clock*
// disposable MariaDB fixture and Wompi mock. No production or provider access.
require_once __DIR__.'/../../config/public_ticket_checkout.php';
putenv('BRVTAL_TICKET_WOMPI_MODE=mock');
putenv('BRVTAL_TICKET_WOMPI_TESTING=1');
putenv('BRVTAL_TICKET_WOMPI_PUBLIC_KEY=pub_test_waitlist_fixture');
putenv('BRVTAL_TICKET_WOMPI_PRIVATE_KEY=prv_test_waitlist_fixture');
putenv('BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET=test_integrity_waitlist_fixture');
putenv('BRVTAL_TICKET_WOMPI_ENABLED_METHODS=CARD');
putenv('BRVTAL_TICKET_WOMPI_RETURN_URL=https://example.test/return');
$clockNow=new DateTimeImmutable('now', new DateTimeZone('UTC'));
$eventAt=$clockNow->modify('+3 days')->setTimezone(
    new DateTimeZone('America/Bogota'))->format('Y-m-d H:i:s');
$windowStart=$clockNow->modify('-1 day')->format('Y-m-d H:i:s');
$windowEnd=$clockNow->modify('+1 day')->format('Y-m-d H:i:s');
$db->prepare("INSERT INTO events(id,status,event_date,published_at)
    VALUES (2,'published',?,?)")->execute([$eventAt,$windowStart]);
$db->prepare("INSERT INTO event_ticket_types
    (id,event_id,status,currency,available_from,available_until)
    VALUES (20,2,'active','COP',?,?)")->execute([$windowStart,$windowEnd]);
$db->exec('INSERT INTO ticket_inventory_events VALUES (2,1)');
$db->exec('INSERT INTO ticket_inventory_types VALUES (20,2,1,0)');
wlAssert(brvtal_waitlist_subscribe($db,2,20,2,'waitlist-v1')==='waiting',
    'buyer opts in before checkout');
$thirdEntry=(int)$db->query("SELECT id FROM ticket_waitlist_entries WHERE event_id=2 AND buyer_id=2")->fetchColumn();
wlAssert($thirdEntry>0 && brvtal_waitlist_offer($db,2,20)['entry_id']===$thirdEntry,
    'exclusive invitation offered to the correct entry');
$mail=$readMail("SELECT * FROM ticket_mail_outbox
    WHERE related_ref='waitlist:{$thirdEntry}' AND subject='Cupo liberado en BRVTAL'
    ORDER BY id DESC LIMIT 1");
wlAssert(preg_match('/Código: ([A-Za-z0-9_-]{43})[.]/', $mail, $offerCode2)===1,
    'checkout invitation token comes from mock outbox');
$normal=['ticket_type_id'=>20,'quantity'=>1,'idempotency_key'=>str_repeat('n',20),'method'=>'CARD'];
try {
    brvtal_public_ticket_checkout_prepare($db,2,1,$normal);
    wlAssert(false,'ordinary checkout must not oversell an offered unit');
} catch (DomainException $error) {
    wlAssert($error->getMessage()==='INVENTORY_SOLD_OUT',
        'ordinary checkout sees waitlist seat as held');
}
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_orders WHERE event_id=2")->fetchColumn()===0,
    'failed non-invited checkout rolls back its order');
$requested=$normal;
$requested['idempotency_key']=str_repeat('w',20);
$requested['waitlist_token']=$offerCode2[1];
$alien=$requested;
$alien['idempotency_key']=str_repeat('a',20);
try {
    brvtal_public_ticket_checkout_prepare($db,2,1,$alien);
    wlAssert(false,'checkout cannot redeem another buyer token');
} catch (DomainException $error) {
    wlAssert($error->getMessage()==='WAITLIST_OFFER_NOT_ELIGIBLE',
        'buyer mismatch rejects redemption');
}
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_waitlist_entries
    WHERE event_id=2 AND status='offered'")->fetchColumn()===1,
    'alien checkout rolls back the invitation');
$prepared=brvtal_public_ticket_checkout_prepare($db,2,2,$requested);
wlAssert((int)$prepared['order_id']>0
    && ($prepared['checkout']['state']??'')==='awaiting_provider',
    'verified invite creates only Wompi mock checkout preparation');
$retried=brvtal_public_ticket_checkout_prepare($db,2,2,$requested);
wlAssert((int)$retried['order_id']===(int)$prepared['order_id'],
    'repeated idempotency key reuses paid hold, not invitation');
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_waitlist_entries
    WHERE event_id=2 AND status='redeemed' AND offer_hash IS NULL")->fetchColumn()===1,
    'checkout consumes token exactly once');
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_inventory_holds
    WHERE event_id=2 AND status='active'")->fetchColumn()===1,
    'one hold only after checkout and idempotent replay');
wlAssert((int)$db->query("SELECT COUNT(*) FROM ticket_orders
    WHERE event_id=2 AND status='paid'")->fetchColumn()===0,
    'mock checkout cannot cause a paid state or issue tickets');
$redeemedMail=(int)$db->query("SELECT id FROM ticket_mail_outbox WHERE related_ref='waitlist:{$thirdEntry}'
    AND template_key='waitlist_offer' ORDER BY id DESC LIMIT 1")->fetchColumn();
$readyMail($redeemedMail);
wlAssert(brvtal_ticket_mail_deliver_one($db,$noTransport)==='suppressed' && $forbiddenCalls===0,
    'already redeemed invitation never reaches transport');

// Independent live-clock event for dispatch negatives and two-connection fencing.
$db->prepare("INSERT INTO events(id,status,event_date,published_at) VALUES (3,'published',?,?)")
    ->execute([$eventAt,$windowStart]);
$db->prepare("INSERT INTO event_ticket_types
    (id,event_id,status,currency,available_from,available_until) VALUES (30,3,'active','COP',?,?)")
    ->execute([$windowStart,$windowEnd]);
$db->exec('INSERT INTO ticket_inventory_events VALUES (3,1)');
$db->exec('INSERT INTO ticket_inventory_types VALUES (30,3,1,0)');
$db->exec("UPDATE ticket_buyers SET email_verified_at=UTC_TIMESTAMP() WHERE id=3");
wlAssert(brvtal_waitlist_subscribe($db,3,30,3,'waitlist-v1')==='waiting','dispatch fixture signup');
$dispatchEntry=(int)$db->query("SELECT id FROM ticket_waitlist_entries WHERE event_id=3 AND buyer_id=3")->fetchColumn();
$newOffer=static function () use ($db,$dispatchEntry): int {
    $db->prepare("UPDATE ticket_waitlist_entries SET offer_expires_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND)
        WHERE id=? AND status='offered'")->execute([$dispatchEntry]);
    wlAssert(brvtal_waitlist_offer($db,3,30)!==null,'fresh dispatch fixture offer');
    return (int)$db->query("SELECT id FROM ticket_mail_outbox WHERE related_ref='waitlist:{$dispatchEntry}'
        AND template_key='waitlist_offer' ORDER BY id DESC LIMIT 1")->fetchColumn();
};
$joinToken=static function () use ($readMail,$dispatchEntry): string {
    $body=$readMail("SELECT * FROM ticket_mail_outbox WHERE related_ref='waitlist:{$dispatchEntry}'
        AND template_key='waitlist_join' ORDER BY id DESC LIMIT 1");
    wlAssert(preg_match('/[A-Za-z0-9_-]{43}$/D',$body,$match)===1,'current consent token');
    return $match[0];
};
$stateOf=static fn(int $id): array => $db->query("SELECT state,last_error_code,provider_message_id
    FROM ticket_mail_outbox WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
$historyOf=static fn(int $id): string => (string)$db->query("SELECT delivery_state
    FROM ticket_mail_history WHERE outbox_id=$id")->fetchColumn();
$offerId=$newOffer();
brvtal_mail_history_record($db,$offerId,['template_key'=>'waitlist_offer','event_id'=>3,'buyer_id'=>3]);
$db->prepare("UPDATE ticket_waitlist_entries SET offer_expires_at_utc=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND)
    WHERE id=?")->execute([$dispatchEntry]);
$readyMail($offerId);
wlAssert(brvtal_ticket_mail_deliver_one($db,$noTransport)==='suppressed'
    && $stateOf($offerId)['last_error_code']==='waitlist_expired'
    && $historyOf($offerId)==='suppressed','delayed expired invitation suppressed in outbox and history');
$offerId=$newOffer();
$db->exec("UPDATE events SET status='cancelled' WHERE id=3");
$readyMail($offerId);
wlAssert(brvtal_ticket_mail_deliver_one($db,$noTransport)==='suppressed'
    && $stateOf($offerId)['last_error_code']==='waitlist_event_closed','cancelled event suppressed');
$db->exec("UPDATE events SET status='published' WHERE id=3");
$offerId=$newOffer();
$db->exec("UPDATE ticket_buyers SET email='changed@example.test' WHERE id=3");
$readyMail($offerId);
wlAssert(brvtal_ticket_mail_deliver_one($db,$noTransport)==='suppressed'
    && $stateOf($offerId)['last_error_code']==='waitlist_buyer_ineligible','changed recipient suppressed');
$db->exec("UPDATE ticket_buyers SET email='unverified@example.test' WHERE id=3");
$offerId=$newOffer();
putenv('BRVTAL_WAITLIST_TESTING=0');
$readyMail($offerId);
wlAssert(brvtal_ticket_mail_deliver_one($db,$noTransport)==='suppressed'
    && $stateOf($offerId)['last_error_code']==='waitlist_disabled','closed activation gate suppresses');
putenv('BRVTAL_WAITLIST_TESTING=1');

$second=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,$opt);
$offerId=$newOffer();
$readyMail($offerId);
$claimedJob=brvtal_ticket_mail_claim($db);
wlAssert((int)$claimedJob['id']===$offerId,'claim exact invitation');
$second->prepare('UPDATE ticket_mail_outbox SET locked_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)
    WHERE id=?')->execute([$offerId]);
wlAssert(brvtal_ticket_mail_prepare_dispatch($db,$claimedJob)==='lease_lost','expired generation cannot dispatch');
wlAssert(brvtal_ticket_mail_reconcile_stale($second)>=1
    && $stateOf($offerId)['state']==='uncertain','stale generation stays uncertain');

$offerId=$newOffer();
$readyMail($offerId);
$claimedJob=brvtal_ticket_mail_claim($db);
wlAssert((int)$claimedJob['id']===$offerId && brvtal_waitlist_unsubscribe($second,$joinToken()),
    'other connection revokes after claim and before boundary');
wlAssert(brvtal_ticket_mail_prepare_dispatch($db,$claimedJob)==='suppressed'
    && $stateOf($offerId)['state']==='suppressed','revocation before boundary is proven local');
wlAssert(!brvtal_ticket_mail_redrive($db,$offerId) && $forbiddenCalls===0,'no replay or transport');

wlAssert(brvtal_waitlist_subscribe($db,3,30,3,'waitlist-v1')==='waiting','new explicit consent');
$offerId=$newOffer();
brvtal_mail_history_record($db,$offerId,['template_key'=>'waitlist_offer','event_id'=>3,'buyer_id'=>3]);
$token=$joinToken();
$readyMail($offerId);
$sent=0;
$afterBoundary=static function (array $payload,array $headers) use ($second,$token,&$sent): array {
    $sent++;
    wlAssert($payload['to']===['unverified@example.test'] && str_contains($payload['text'],'Código: ')
        && str_starts_with($headers[2],'Idempotency-Key: waitlist:offer:'),'valid sealed invitation reaches fixture');
    wlAssert(brvtal_waitlist_unsubscribe($second,$token),'revocation after transport boundary');
    return ['status'=>200,'body'=>'{"id":"synthetic-waitlist-boundary"}'];
};
wlAssert(brvtal_ticket_mail_deliver_one($db,$afterBoundary)==='accepted' && $sent===1
    && $stateOf($offerId)['provider_message_id']==='synthetic-waitlist-boundary'
    && $historyOf($offerId)==='accepted','in-flight acceptance is not falsely cancelled or overwritten');

wlAssert(brvtal_waitlist_subscribe($db,3,30,3,'waitlist-v1')==='waiting','consent after prior offer');
$offerId=$newOffer();
$readyMail($offerId);
wlAssert(brvtal_ticket_mail_deliver_one($db,static fn()=>['status'=>503,'body'=>'{}'])==='retry',
    'provider may have accepted earlier ambiguous attempt');
wlAssert(brvtal_waitlist_unsubscribe($second,$joinToken()) && $stateOf($offerId)['state']==='uncertain'
    && !brvtal_ticket_mail_redrive($db,$offerId),'revocation preserves uncertainty instead of replay');
wlAssert($forbiddenCalls===0,'all stale and ineligible jobs made zero provider requests');

echo "T28 MariaDB: opt-in, unsubscribe, FIFO, reservation, checkout redemption, replay, dispatch fencing and safety passed.\n";

