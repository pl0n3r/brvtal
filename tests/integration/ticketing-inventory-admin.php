<?php
declare(strict_types=1);

/** #1931: DISCADMIN capacity for online sale, against disposable MariaDB. */
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    require_once __DIR__ . '/../../config/ticketing_inventory_admin.php';
    foreach ([null, [], ['capacity'=>-1,'types'=>[]], ['capacity'=>100001,'types'=>[]], ['capacity'=>10],
        ['capacity'=>10,'types'=>[['ticket_type_id'=>1,'capacity'=>'5']]],
        ['capacity'=>10,'types'=>[['ticket_type_id'=>1,'capacity'=>5],['ticket_type_id'=>1,'capacity'=>6]]],
        ['capacity'=>10,'types'=>[['ticket_type_id'=>1,'capacity'=>5,'price'=>1]]],
        ...array_map(static fn($v) => ['capacity'=>10,'types'=>[['ticket_type_id'=>1,'capacity'=>5,'courtesy_capacity'=>$v]]],
            [-1, 100001, null, '1', 1.5, true]),
        ['capacity'=>10,'types'=>[],'extra'=>1]] as $bad) {
        try {
            brvtal_ticket_inventory_admin_input($bad);
            throw new RuntimeException('INVENTORY_ADMIN: accepted ' . json_encode($bad));
        } catch (InvalidArgumentException) {
        }
    }
    echo "Ticket inventory admin input validation passed; MariaDB checks need BRVTAL_INTEGRATION_TESTS=1.\n";
    exit(0);
}
function invAssert(bool $ok, string $what): void
{
    if (!$ok) throw new RuntimeException('INVENTORY_ADMIN: ' . $what);
}
function invRejects(callable $callback, string $code, string $what): void
{
    try {
        $callback();
    } catch (DomainException | InvalidArgumentException $error) {
        invAssert($error->getMessage() === $code, $what . ' (got ' . $error->getMessage() . ')');
        return;
    }
    invAssert(false, $what . ' was accepted');
}
$namespace = (string)getenv('BRVTAL_TEST_DB_NAME');
invAssert((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D', $namespace), 'disposable DB namespace');
$dbSettings = [
    'host'=>(string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    'port'=>(int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    'user'=>(string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'),
    'pass'=>(string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''),
];
$dbOptions = [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
];
$connect = static function (?string $schema = null) use ($dbSettings, $dbOptions): PDO {
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbSettings['host'], $dbSettings['port']);
    if ($schema !== null) {
        $dsn .= ';dbname=' . $schema;
    }
    return new PDO($dsn, $dbSettings['user'], $dbSettings['pass'], $dbOptions);
};
$server = $connect();
$name = $namespace . '_inventory_admin_' . bin2hex(random_bytes(3));
$server->exec('CREATE DATABASE ' . $name);
register_shutdown_function(static function () use ($server, $name): void {
    try { $server->exec('DROP DATABASE IF EXISTS ' . $name); } catch (Throwable) {}
});
$db = $connect($name);
foreach ([
    "CREATE TABLE admins (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,name VARCHAR(120) NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,
        credential_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1) ENGINE=InnoDB",
    "CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,status VARCHAR(32),event_date DATETIME,published_at DATETIME) ENGINE=InnoDB",
    "CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,name VARCHAR(120) NOT NULL,
        price INT UNSIGNED NOT NULL,sort_order INT NOT NULL DEFAULT 0,status VARCHAR(32),currency VARCHAR(3),
        available_from DATETIME,available_until DATETIME,UNIQUE KEY uq_type_event(id,event_id)) ENGINE=InnoDB",
    "CREATE TABLE ticket_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_id INT UNSIGNED,buyer_id BIGINT UNSIGNED,
        status VARCHAR(20),public_id BINARY(16),checkout_key_hash CHAR(64) UNIQUE,currency CHAR(3),total_cop BIGINT UNSIGNED,
        expires_at_utc DATETIME(6),updated_at_utc DATETIME(6),UNIQUE KEY uq_order_event(id,event_id)) ENGINE=InnoDB",
    "CREATE TABLE ticket_buyers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id BINARY(16),
        email VARCHAR(190) UNIQUE, status VARCHAR(20)) ENGINE=InnoDB",
    "CREATE TABLE ticket_order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED,ticket_type_id INT UNSIGNED,quantity INT,event_id INT UNSIGNED,
        ticket_name_snapshot VARCHAR(120),unit_price_cop BIGINT UNSIGNED,subtotal_cop BIGINT UNSIGNED,currency CHAR(3)) ENGINE=InnoDB",
] as $ddl) $db->exec($ddl);
$db->exec((string)file_get_contents(__DIR__ . '/../../database/migration_admin_activity_01.sql'));
$db->exec((string)file_get_contents(__DIR__ . '/../../database/migration_ticketing_reservations_01.sql'));
$db->exec("INSERT INTO admins(email,password_hash,name) VALUES('owner@synthetic.invalid','synthetic','Owner')");

$cfg = ['app'=>['timezone'=>'America/Bogota'],
    'db'=>['host'=>$dbSettings['host'],'port'=>$dbSettings['port'],'name'=>$name,
        'user'=>$dbSettings['user'],'pass'=>$dbSettings['pass'],'charset'=>'utf8mb4'],
    'security'=>['session_name'=>'BRVTAL_INVENTORY_ADMIN_TEST','encryption_key'=>str_repeat('T',64)]];
$tmp = tempnam(sys_get_temp_dir(), 'brvtal_inventory_admin_');
file_put_contents($tmp, '<?php return ' . var_export($cfg, true) . ';');
register_shutdown_function(static fn() => @unlink($tmp));
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = $tmp;
require_once __DIR__ . '/../../config/admin_activity.php';
require_once __DIR__ . '/../../config/ticketing_inventory_admin.php';
require_once __DIR__ . '/../../config/public_ticket_checkout.php';
brvtal_admin_session_start();
$_SESSION['admin_id'] = 1;

foreach (['BRVTAL_TICKET_WOMPI_MODE=mock','BRVTAL_TICKET_WOMPI_TESTING=1',
    'BRVTAL_TICKET_WOMPI_PUBLIC_KEY=pub_test_inventory_fixture','BRVTAL_TICKET_WOMPI_PRIVATE_KEY=prv_test_inventory_fixture',
    'BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET=test_integrity_inventory_fixture','BRVTAL_TICKET_WOMPI_ENABLED_METHODS=CARD',
    'BRVTAL_TICKET_WOMPI_RETURN_URL=https://example.test/return'] as $setting) putenv($setting);

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$eventAt = $now->modify('+5 days')->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d H:i:s');
$from = $now->modify('-1 day')->format('Y-m-d H:i:s');
$db->prepare("INSERT INTO events VALUES (1,'published',?,?),(2,'published',?,?)")->execute([$eventAt,$from,$eventAt,$from]);
$db->prepare("INSERT INTO event_ticket_types(id,event_id,name,price,status,currency,available_from) VALUES
    (10,1,'Preventa',25000,'active','COP',?),(11,1,'VIP',60000,'active','COP',?),(20,2,'Otro',10000,'active','COP',?)")
    ->execute([$from,$from,$from]);

// Before any capacity: nothing is offered online and the editor reads empty capacity.
invAssert(brvtal_public_ticket_checkout_offers($db, 1) === [], 'no capacity means no online offer');
$read = brvtal_ticket_inventory_admin_read($db, 1);
invAssert($read['capacity'] === null && count($read['types']) === 2 && $read['types'][0]['capacity'] === null,
    'empty capacity before configuration');
// #1939: the editor explains why nothing sells yet.
invAssert($read['sale']['ready'] === false && in_array('EVENT_CAPACITY_MISSING', $read['sale']['reasons'], true),
    'readiness reports the missing event capacity');
invAssert(in_array('TYPE_CAPACITY_MISSING', $read['sale']['types'][0]['reasons'], true), 'readiness reports missing type capacity');

$saved = brvtal_ticket_inventory_admin_save($db, 1, ['capacity'=>300,
    'types'=>[['ticket_type_id'=>10,'capacity'=>200],['ticket_type_id'=>11,'capacity'=>20]]]);
invAssert($saved['capacity'] === 300, 'event capacity saved');
invAssert($saved['types'][0]['capacity'] === 200 && $saved['types'][1]['capacity'] === 20, 'type capacity saved');
$offers = brvtal_public_ticket_checkout_offers($db, 1);
invAssert(count($offers['offers'] ?? []) === 2 && $offers['offers'][0]['available'] === 20,
    'online checkout offers the types after saving (available capped at 20 per order)');
$audit = $db->query("SELECT action,resource,resource_id,meta_json FROM admin_activity_log")->fetchAll();
invAssert(count($audit) === 1 && $audit[0]['action'] === 'inventory_update' && (int)$audit[0]['resource_id'] === 1,
    'save is audited');

// Usage: a paid order of 3 VIP and an active hold of 2 Preventa.
$db->exec("INSERT INTO ticket_orders(id,event_id,buyer_id,status,currency,total_cop) VALUES (1,1,7,'paid','COP',180000),(2,1,8,'pending','COP',50000)");
$db->exec("INSERT INTO ticket_order_items(order_id,ticket_type_id,quantity,event_id,unit_price_cop,subtotal_cop,currency) VALUES (1,11,3,1,60000,180000,'COP'),(2,10,2,1,25000,50000,'COP')");
$db->exec("INSERT INTO ticket_inventory_holds(order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
    VALUES (2,1,10,2,'paid','active',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))");
$usage = brvtal_ticket_inventory_admin_read($db, 1);
invAssert($usage['types'][1]['sold'] === 3 && $usage['types'][0]['held'] === 2 && $usage['used'] === 5, 'usage is reported');

$auditBefore = (int)$db->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn();
invRejects(fn() => brvtal_ticket_inventory_admin_save($db, 1, ['capacity'=>300,
    'types'=>[['ticket_type_id'=>10,'capacity'=>150],['ticket_type_id'=>11,'capacity'=>2]]]),
    'INVENTORY_BELOW_USAGE', 'VIP below sold units');
invRejects(fn() => brvtal_ticket_inventory_admin_save($db, 1, ['capacity'=>4, 'types'=>[]]),
    'INVENTORY_BELOW_USAGE', 'event below total usage');
invRejects(fn() => brvtal_ticket_inventory_admin_save($db, 1, ['capacity'=>300,
    'types'=>[['ticket_type_id'=>20,'capacity'=>5]]]), 'INVENTORY_TYPE_NOT_IN_EVENT', 'type from another event');
invRejects(fn() => brvtal_ticket_inventory_admin_save($db, 99, ['capacity'=>1,'types'=>[]]),
    'INVENTORY_EVENT_NOT_FOUND', 'unknown event');
$db->exec('UPDATE ticket_inventory_types SET courtesy_capacity=10 WHERE ticket_type_id=10');
invRejects(fn() => brvtal_ticket_inventory_admin_save($db, 1, ['capacity'=>300,
    'types'=>[['ticket_type_id'=>10,'capacity'=>9]]]), 'INVENTORY_BELOW_COURTESY', 'below courtesy allocation');
$state = brvtal_ticket_inventory_admin_read($db, 1);
invAssert($state['capacity'] === 300 && $state['types'][0]['capacity'] === 200 && $state['types'][1]['capacity'] === 20,
    'rejected saves change nothing');
invAssert((int)$db->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn() === $auditBefore,
    'rejected saves are not audited');

// Exactly at usage is allowed; zero closes online sale for a type with no usage.
$db->exec("INSERT INTO event_ticket_types(id,event_id,name,price,status,currency) VALUES (12,1,'Mesa',150000,'active','COP')");
$ok = brvtal_ticket_inventory_admin_save($db, 1, ['capacity'=>5,
    'types'=>[['ticket_type_id'=>11,'capacity'=>3],['ticket_type_id'=>12,'capacity'=>0]]]);
invAssert($ok['capacity'] === 5 && $ok['types'][1]['capacity'] === 3 && $ok['types'][2]['capacity'] === 0,
    'capacity equal to usage and zero capacity are accepted');
$offerIds = array_column(brvtal_public_ticket_checkout_offers($db, 1)['offers'] ?? [], 'id');
invAssert(!in_array(12, $offerIds, true), 'zero capacity type is not offered online');
// #1939: readiness agrees with the checkout and names each blocking condition.
$full = brvtal_ticket_inventory_admin_read($db, 1)['sale'];
invAssert($full['ready'] === false && in_array('EVENT_FULL', $full['reasons'], true), 'a full event (5 of 5 used) is named');
brvtal_ticket_inventory_admin_save($db, 1, ['capacity'=>50, 'types'=>[['ticket_type_id'=>11,'capacity'=>20]]]);
$sale = brvtal_ticket_inventory_admin_read($db, 1)['sale'];
invAssert($sale['ready'] === true && $sale['reasons'] === [], 'ready when the checkout offers a type');
$byType = array_column($sale['types'], null, 'ticket_type_id');
invAssert($byType[11]['offered'] === true, 'VIP is offered');
invAssert($byType[12]['offered'] === false && in_array('TYPE_CAPACITY_MISSING', $byType[12]['reasons'], true), 'zero capacity is named');
$future = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'))->modify('+2 days')->format('Y-m-d H:i:s');
$db->prepare("INSERT INTO event_ticket_types(id,event_id,name,price,status,currency,available_from) VALUES
    (13,1,'Dólar',10,'active','USD',NULL),(14,1,'Tardía',30000,'active','COP',?)")->execute([$future]);
$db->exec('INSERT INTO ticket_inventory_types(ticket_type_id,event_id,capacity) VALUES (13,1,5),(14,1,5)');
$byType = array_column(brvtal_ticket_inventory_admin_read($db, 1)['sale']['types'], null, 'ticket_type_id');
invAssert(in_array('TYPE_CURRENCY', $byType[13]['reasons'], true), 'non-COP currency is named');
invAssert(in_array('TYPE_NOT_STARTED', $byType[14]['reasons'], true), 'future sale window is named');
putenv('BRVTAL_TICKET_WOMPI_MODE=off');
$off = brvtal_ticket_inventory_admin_read($db, 1)['sale'];
invAssert($off['ready'] === false && in_array('WOMPI_DISABLED', $off['reasons'], true), 'Wompi off is named and blocks the sale');
putenv('BRVTAL_TICKET_WOMPI_MODE=mock');
// #2221: the same admin service configures the budget consumed by actual synthetic issuance.
require_once __DIR__ . '/../../config/ticketing_courtesy.php';
foreach (['migration_ticketing_entries_01.sql','migration_ticket_entry_secret_01.sql',
    'migration_ticketing_courtesy_01.sql','migration_ticketing_mail_outbox_01.sql'] as $file) {
    $sql = (string)file_get_contents(__DIR__ . '/../../database/' . $file);
    $sql = (string)preg_replace('/^\s*--[^\r\n]*$/m', '', $sql);
    foreach (array_filter(array_map(trim(...), explode(';', $sql))) as $statement) $db->exec($statement);
}
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
$GLOBALS['config']['security']['encryption_key'] = str_repeat('T',64);
$set = static fn(array $types) => brvtal_ticket_inventory_admin_save($db, 1, ['capacity'=>50,'types'=>$types]);
$vip = static fn(int $budget) => ['ticket_type_id'=>11,'capacity'=>20,'courtesy_capacity'=>$budget];
$changed = $set([$vip(1)]);
invAssert($changed['types'][1]['courtesy_capacity'] === 1 && $changed['types'][1]['courtesy_used'] === 0,
    'explicit courtesy budget saved');
$meta = json_decode((string)$db->query('SELECT meta_json FROM admin_activity_log ORDER BY id DESC LIMIT 1')->fetchColumn(), true);
invAssert($meta['before']['courtesy_capacity'][11] === 0 && $meta['after']['courtesy_capacity'][11] === 1,
    'courtesy budget before and after audited');
$omitted = $set([['ticket_type_id'=>11,'capacity'=>20]]);
invAssert($omitted['types'][1]['courtesy_capacity'] === 1 && $omitted['types'][2]['courtesy_capacity'] === 0,
    'legacy omission preserves budget and new type defaults to zero');
$invitation = ['action'=>'issue','event_id'=>1,'ticket_type_id'=>11,'recipient'=>'guest@example.test',
    'category'=>'staff','reason'=>'Invitación sintética','idempotency_key'=>str_repeat('a',24)];
$result = brvtalCourtesyIssue($db, 1, 'superadmin', brvtalCourtesyValidate($invitation));
invAssert($result['queued'] === true && $result['sent'] === false, 'positive budget permits synthetic issue only');
invRejects(fn() => brvtalCourtesyIssue($db, 1, 'superadmin', brvtalCourtesyValidate(array_replace($invitation,
    ['recipient'=>'second@example.test','idempotency_key'=>str_repeat('b',24)]))), 'COURTESY_CAPACITY', 'budget exhausted');
$before = brvtal_ticket_inventory_admin_read($db, 1);
$audits = (int)$db->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn();
invAssert($before['types'][1]['courtesy_used'] === 1 && $before['types'][1]['courtesy_capacity'] === 1, 'usage counted');
invRejects(fn() => $set([$vip(21)]), 'INVENTORY_BELOW_COURTESY', 'budget exceeds capacity');
invRejects(fn() => $set([$vip(0)]), 'INVENTORY_COURTESY_BELOW_USAGE', 'budget below consumed');
invRejects(fn() => $set([$vip(-1)]), 'INVENTORY_INVALID_INPUT', 'negative budget');
invRejects(fn() => $set([$vip(2),['ticket_type_id'=>20,'capacity'=>5,'courtesy_capacity'=>1]]),
    'INVENTORY_TYPE_NOT_IN_EVENT', 'other event cannot alter budget');
$after = brvtal_ticket_inventory_admin_read($db, 1);
invAssert($after === $before && (int)$db->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn() === $audits,
    'failed budget saves are atomic and not audited');
invAssert((int)$db->query("SELECT COUNT(*) FROM ticket_mail_outbox WHERE state='queued'")->fetchColumn() === 1,
    'only one synthetic mail queued; no transport');

$db->exec("UPDATE events SET event_date=DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id=1");
$past = brvtal_ticket_inventory_admin_read($db, 1)['sale'];
invAssert($past['ready'] === false && in_array('EVENT_DATE_PAST', $past['reasons'], true), 'past event date is named');

echo "BRVTAL #1931 ticket inventory admin: save, usage floors, courtesy floor, rollback, audit and checkout passed.\n";
