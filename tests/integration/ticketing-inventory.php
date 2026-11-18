<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/ticketing_inventory.php';

function inventoryAssert(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('T05 inventory: '.$message);
}
$zone = new DateTimeZone('America/Bogota');
$clock = new DateTimeImmutable('2026-12-04 20:00:00', $zone);
$event = ['status'=>'published','event_date'=>'2026-12-04 21:00:00',
    'published_at'=>'2026-11-01 09:00:00'];
$type = ['status'=>'active','currency'=>'COP',
    'available_from'=>'2026-12-04 19:00:00','available_until'=>'2026-12-04 20:30:00'];
inventoryAssert(brvtal_ticket_inventory_saleable($event,$type,$clock),'valid night/window');
inventoryAssert(!brvtal_ticket_inventory_saleable(
    $event,$type,$clock->modify('+61 minutes')),'past event hour blocked');
inventoryAssert(!brvtal_ticket_inventory_saleable(
    array_replace($event,['status'=>'cancelled']),$type,$clock),'cancelled event blocked');
inventoryAssert(!brvtal_ticket_inventory_saleable(
    array_replace($event,['published_at'=>'2026-12-05 00:00:00']),$type,$clock),'scheduled event blocked');
inventoryAssert(!brvtal_ticket_inventory_saleable(
    $event,array_replace($type,['available_from'=>'2026-12-04 20:01:00']),$clock),'pre-window blocked');
inventoryAssert(!brvtal_ticket_inventory_saleable(
    $event,array_replace($type,['available_until'=>'2026-12-04 19:59:59']),$clock),'post-window blocked');
inventoryAssert(!brvtal_ticket_inventory_saleable(
    $event,array_replace($type,['status'=>'sold_out']),$clock),'sold out blocked');

$src = (string)file_get_contents(__DIR__.'/../../config/ticketing_inventory.php');
inventoryAssert(str_contains($src,'FOR UPDATE'),'exclusive DB lock required');
inventoryAssert(!str_contains($src,'ticket_entries'),'inventory cannot emit tickets');
inventoryAssert(str_contains($src,'INVENTORY_NOT_CONFIGURED'),'unknown capacity must fail closed');

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL T05 deterministic inventory policy passed; MariaDB integration requires disposable DB.\n";
    exit(0);
}
$prefix = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
inventoryAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$prefix),'disposable MariaDB only');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$password = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name=$prefix.'_inventory_'.bin2hex(random_bytes(3));
$server->exec("CREATE DATABASE `{$name}`");
register_shutdown_function(static function () use ($server,$name): void {
    try{$server->exec("DROP DATABASE IF EXISTS `{$name}`");}catch(Throwable){}
});
$db=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$password,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$db->exec("CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,status VARCHAR(32) NOT NULL,
 event_date DATETIME NOT NULL,published_at DATETIME NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,
 status VARCHAR(24) NOT NULL,currency CHAR(3) NOT NULL,available_from DATETIME NULL,available_until DATETIME NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,
 status VARCHAR(16) NOT NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
 ticket_type_id INT UNSIGNED NOT NULL,quantity INT UNSIGNED NOT NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_inventory_events (event_id INT UNSIGNED PRIMARY KEY,capacity INT UNSIGNED NOT NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_inventory_types (ticket_type_id INT UNSIGNED PRIMARY KEY,capacity INT UNSIGNED NOT NULL,
 courtesy_capacity INT UNSIGNED NOT NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_inventory_holds (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,event_id INT UNSIGNED NOT NULL,ticket_type_id INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL,kind VARCHAR(16) NOT NULL,status VARCHAR(16) NOT NULL,
 expires_at_utc DATETIME(6) NOT NULL,UNIQUE KEY uq_inventory_hold_order_type(order_id,ticket_type_id)) ENGINE=InnoDB");
$db->exec("INSERT INTO events VALUES(1,'published','2026-12-04 21:00:00','2026-11-01 09:00:00')");
$db->exec("INSERT INTO event_ticket_types VALUES(10,1,'active','COP','2026-12-04 19:00:00','2026-12-04 20:30:00')");
$db->exec("INSERT INTO ticket_inventory_events VALUES(1,1)");
$db->exec("INSERT INTO ticket_inventory_types VALUES(10,1,0)");
$db->exec("INSERT INTO ticket_orders VALUES(100,1,'pending'),(101,1,'pending')");
$db->exec("INSERT INTO ticket_order_items(order_id,ticket_type_id,quantity) VALUES(100,10,1),(101,10,1)");
$db->beginTransaction();
$hold=brvtal_ticket_inventory_hold($db,100,10,1,'paid',600,$clock);
$retry=brvtal_ticket_inventory_hold($db,100,10,1,'paid',600,$clock);
inventoryAssert($hold['id']===$retry['id'] && $retry['idempotent'],'retry does not duplicate');
$db->commit();
$db->beginTransaction();
try {
    brvtal_ticket_inventory_hold($db,101,10,1,'paid',600,$clock);
    throw new RuntimeException('Last unit oversold');
} catch (DomainException $e) {
    inventoryAssert($e->getMessage()==='INVENTORY_SOLD_OUT','last unit only one hold');
}
$db->rollBack();
inventoryAssert((int)$db->query("SELECT COUNT(*) FROM ticket_inventory_holds")->fetchColumn()===1,'no negative inventory');
$db->beginTransaction();
inventoryAssert(brvtal_ticket_inventory_transition($db,100,10,'released'),'release');
$db->commit();
$db->beginTransaction();
$second=brvtal_ticket_inventory_hold($db,101,10,1,'paid',600,$clock);
inventoryAssert($second['id']!==$hold['id'],'released stock reusable by another order');
$db->commit();
echo "BRVTAL T05 disposable MariaDB last-unit, retry and release passed.\n";
