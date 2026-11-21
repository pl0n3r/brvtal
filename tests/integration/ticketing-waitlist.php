<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_waitlist.php';

function wlAssert(bool $ok,string $what): void {
    if (!$ok) throw new RuntimeException('T28 '.$what);
}
putenv('BRVTAL_WAITLIST_TESTING=0');
putenv('BRVTAL_WAITLIST_ENABLED=0');
wlAssert(!brvtal_waitlist_enabled(),'closed without operator gate');
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
        status VARCHAR(32),currency VARCHAR(3),available_from DATETIME,available_until DATETIME,
        UNIQUE KEY uq_type_event(id,event_id))",
    "CREATE TABLE ticket_buyers (id BIGINT UNSIGNED PRIMARY KEY,email VARCHAR(190),
        email_verified_at DATETIME,status VARCHAR(20))",
    "CREATE TABLE ticket_inventory_events (event_id INT UNSIGNED PRIMARY KEY,capacity INT UNSIGNED)",
    "CREATE TABLE ticket_inventory_types (ticket_type_id INT UNSIGNED PRIMARY KEY,
        event_id INT UNSIGNED,capacity INT UNSIGNED,courtesy_capacity INT UNSIGNED DEFAULT 0)",
    "CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,status VARCHAR(20))",
    "CREATE TABLE ticket_order_items (order_id BIGINT UNSIGNED,ticket_type_id INT UNSIGNED,quantity INT)",
    "CREATE TABLE ticket_inventory_holds (order_id BIGINT UNSIGNED,event_id INT UNSIGNED,
        ticket_type_id INT UNSIGNED,quantity INT,kind VARCHAR(20),status VARCHAR(20),
        expires_at_utc DATETIME(6))"
] as $ddl) $db->exec($ddl);
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_waitlist_01.sql'));
$db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_mail_outbox_01.sql'));
$db->exec("INSERT INTO events VALUES (1,'published','2026-12-05 21:00:00','2026-11-01 09:00:00')");
$db->exec("INSERT INTO event_ticket_types VALUES
    (10,1,'active','COP','2026-12-04 19:00:00','2026-12-04 22:00:00')");
$db->exec("INSERT INTO ticket_inventory_events VALUES (1,1)");
$db->exec("INSERT INTO ticket_inventory_types VALUES (10,1,1,0)");
$db->exec("INSERT INTO ticket_buyers VALUES
    (1,'first@example.test','2026-11-01 00:00:00','active'),
    (2,'second@example.test','2026-11-01 00:00:00','active'),
    (3,'unverified@example.test',NULL,'active')");
putenv('BRVTAL_WAITLIST_TESTING=1');
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
putenv('BRVTAL_TICKET_MAIL_MODE=mock');
$now=new DateTimeImmutable('2026-12-04 20:00:00',new DateTimeZone('America/Bogota'));
wlAssert(brvtal_waitlist_subscribe($db,1,10,1,'waitlist-v1','',$now)==='waiting',
    'verified opt-in');
wlAssert(brvtal_waitlist_subscribe($db,1,10,1,'waitlist-v1','',$now)==='already_registered',
    'duplicate is idempotent');
wlAssert(brvtal_waitlist_subscribe($db,1,10,2,'waitlist-v1','',$now)==='waiting',
    'second interest');
wlAssert((int)$db->query('SELECT COUNT(*) FROM ticket_waitlist_entries')->fetchColumn()===2,
    'no double signup');
foreach ([[3,'waitlist-v1',''],[1,'no',''],[1,'waitlist-v1','bot']] as [$buyer,$consent,$honey]) {
    try { brvtal_waitlist_subscribe($db,1,10,$buyer,$consent,$honey,$now);
        wlAssert(false,'invalid signup denied');
    } catch (InvalidArgumentException|DomainException) {}
}
wlAssert(brvtal_waitlist_offer($db,1,10,$now)['entry_id']===1,'FIFO invitation');
wlAssert(brvtal_waitlist_offer($db,1,10,$now)===null,'single capacity cannot over-offer');
$clock=brvtal_waitlist_clock($now)->format('Y-m-d H:i:s.u');
$usage=brvtal_ticket_inventory_usage($db,1,10,$clock);
wlAssert($usage['held']===1,'checkout counts offered capacity');
wlAssert((int)$db->query('SELECT COUNT(*) FROM ticket_orders')->fetchColumn()===0,
    'no order, charge or ticket from signup/offer');
$body=(string)$db->query("SELECT body_text FROM ticket_mail_outbox
    WHERE related_ref='waitlist:1' ORDER BY id LIMIT 1")->fetchColumn();
wlAssert((bool)preg_match('/[A-Za-z0-9_-]{43}$/D',$body,$parts),'unsubscribe delivered by outbox');
wlAssert(brvtal_waitlist_unsubscribe($db,$parts[0]),'unsubscribe');
wlAssert(!brvtal_waitlist_unsubscribe($db,$parts[0]),'unsubscribe replay');
wlAssert(brvtal_ticket_inventory_usage($db,1,10,$clock)['held']===0,
    'unsubscribe releases offer');
wlAssert(brvtal_waitlist_offer($db,1,10,$now)['entry_id']===2,'next eligible entry');
wlAssert(brvtal_waitlist_offer($db,1,10,$now->modify('+11 minutes'))!==null,
    'expired offers return to inventory');
$db->exec("UPDATE events SET status='cancelled' WHERE id=1");
try { brvtal_waitlist_offer($db,1,10,$now); wlAssert(false,'cancelled offer'); }
catch (DomainException) {}
echo "T28 MariaDB: opt-in, duplicate, unsubscribe, limits, FIFO, expiry, closed event passed.\n";
