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
$workerMode = (string)($argv[1] ?? '');
if (in_array($workerMode, ['--race', '--wall'], true)) {
    $raceDb = (string)($argv[2] ?? '');
    $orderId = (int)($argv[3] ?? 0);
    $startAt = (float)($argv[4] ?? 0);
    inventoryAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$raceDb),'race DB must be disposable');
    $contender = new PDO("mysql:host={$host};port={$port};dbname={$raceDb};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    if ($workerMode === '--race') {
        while (microtime(true) < $startAt) usleep(1000);
    } else {
        echo "READY\n";
        flush();
    }
    $contender->beginTransaction();
    try {
        brvtal_ticket_inventory_hold($contender,$orderId,10,1,'paid',600,
            $workerMode === '--race' ? $clock : null);
        $contender->commit();
        echo "HELD\n";
    } catch (DomainException $error) {
        $contender->rollBack();
        echo $error->getMessage() . "\n";
    }
    exit(0);
}
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
 status VARCHAR(24) NOT NULL,currency CHAR(3) NOT NULL,available_from DATETIME NULL,available_until DATETIME NULL,
 UNIQUE KEY uq_t03_ticket_type_event(id,event_id)) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,
 status VARCHAR(16) NOT NULL,UNIQUE KEY uq_ticket_orders_id_event(id,event_id)) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
 ticket_type_id INT UNSIGNED NOT NULL,quantity INT UNSIGNED NOT NULL,
 UNIQUE KEY uq_ticket_order_item_type(order_id,ticket_type_id)) ENGINE=InnoDB");
$inventorySql = (string)file_get_contents(__DIR__.'/../../database/migration_ticketing_reservations_01.sql');
foreach (array_filter(array_map(trim(...), explode(';', preg_replace('/^\\s*--[^\\n]*$/m','',$inventorySql)))) as $statement) {
    $db->exec($statement);
}

$db->exec("INSERT INTO events VALUES(1,'published','2026-12-04 21:00:00','2026-11-01 09:00:00')");
$db->exec("INSERT INTO event_ticket_types VALUES(10,1,'active','COP','2026-12-04 19:00:00','2026-12-04 20:30:00')");
$db->exec("INSERT INTO ticket_inventory_events VALUES(1,1)");
$db->exec("INSERT INTO ticket_inventory_types(ticket_type_id,event_id,capacity,courtesy_capacity) VALUES(10,1,1,0)");
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
// Two independent PHP transactions compete at the same instant for the last unit.
$db->exec("DELETE FROM ticket_inventory_holds");
$db->exec("INSERT INTO ticket_orders VALUES(102,1,'pending'),(103,1,'pending'),(104,1,'pending')");
$db->exec("INSERT INTO ticket_order_items(order_id,ticket_type_id,quantity) VALUES(102,10,1),(103,10,1),(104,10,1)");
inventoryAssert(function_exists('proc_open'),'separate PHP contenders supported in CI');
$barrier = microtime(true) + 0.7;
$workers = [];
foreach ([102,103] as $order) {
    $proc = proc_open([PHP_BINARY,__FILE__,'--race',$name,(string)$order,(string)$barrier],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
    inventoryAssert(is_resource($proc),'race worker launched');
    fclose($pipes[0]);
    $workers[] = [$proc,$pipes];
}
$results = [];
foreach ($workers as [$proc,$pipes]) {
    $stdout = trim((string)stream_get_contents($pipes[1]));
    $stderr = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($proc);
    inventoryAssert($code===0 && $stderr==='','worker completed: '.$stderr);
    $results[]=$stdout;
}
sort($results);
inventoryAssert($results===['HELD','INVENTORY_SOLD_OUT'],'two concurrent processes claim exactly one unit');
inventoryAssert((int)$db->query("SELECT COALESCE(SUM(quantity),0) FROM ticket_inventory_holds WHERE status='active'")->fetchColumn()===1,
    'race never produces two active holds or negative stock');

// Expiration is reconciled on the next transaction.
$db->beginTransaction();
$renewed = brvtal_ticket_inventory_hold($db,104,10,1,'paid',600,$clock->modify('+11 minutes'));
inventoryAssert($renewed['id']>0,'expired hold releases the seat');
$db->commit();
inventoryAssert((int)$db->query("SELECT COUNT(*) FROM ticket_inventory_holds WHERE status='expired'")->fetchColumn()===1,
    'raced winner expires');
inventoryAssert((int)$db->query("SELECT COUNT(*) FROM ticket_inventory_holds WHERE status='active'")->fetchColumn()===1,
    'exactly one subsequent reservation remains');
// A paid order can temporarily retain an active paid hold while payment
// confirmation and consumption are separate transactions. Never double count.
$db->exec("DELETE FROM ticket_inventory_holds");
$db->exec("UPDATE ticket_inventory_events SET capacity=2 WHERE event_id=1");
$db->exec("UPDATE ticket_inventory_types SET capacity=2 WHERE ticket_type_id=10");
$db->exec("INSERT INTO ticket_orders VALUES(200,1,'pending'),(201,1,'pending')");
$db->exec("INSERT INTO ticket_order_items(order_id,ticket_type_id,quantity) VALUES(200,10,1),(201,10,1)");
$db->beginTransaction();
brvtal_ticket_inventory_hold($db,200,10,1,'paid',600,$clock);
$db->commit();
$db->exec("UPDATE ticket_orders SET status='paid' WHERE id=200");
$db->beginTransaction();
$paidUsage = brvtal_ticket_inventory_usage($db,1,10,
    $clock->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'));
inventoryAssert($paidUsage['sold']+$paidUsage['held']===1,
    'paid order with active hold occupies exactly one seat');
brvtal_ticket_inventory_hold($db,201,10,1,'paid',600,$clock);
$db->commit();

// A real waiting transaction must use the wall clock AFTER the Event lock.
$db->exec("DELETE FROM ticket_inventory_holds");
$db->exec("UPDATE ticket_inventory_events SET capacity=1 WHERE event_id=1");
$db->exec("UPDATE ticket_inventory_types SET capacity=1 WHERE ticket_type_id=10");
$db->exec("INSERT INTO ticket_orders VALUES(202,1,'pending'),(203,1,'pending')");
$db->exec("INSERT INTO ticket_order_items(order_id,ticket_type_id,quantity) VALUES(202,10,1),(203,10,1)");
$wall = new DateTimeImmutable('now',new DateTimeZone('America/Bogota'));
$eventDate = $wall->modify('+3 days')->format('Y-m-d H:i:s');
$fromDate = $wall->modify('-1 hour')->format('Y-m-d H:i:s');
$untilDate = $wall->modify('+2 seconds')->format('Y-m-d H:i:s');
$eventUpdate=$db->prepare("UPDATE events SET event_date=?,published_at=? WHERE id=1");
// Public visibility also parses legacy timestamps in the server's timezone;
// publish well before the test window so the ONLY crossing boundary is
// available_until while the worker waits on the Event row lock.
$publishedDate=$wall->modify('-2 days')->format('Y-m-d H:i:s');
$eventUpdate->execute([$eventDate,$publishedDate]);
$windowUpdate=$db->prepare("UPDATE event_ticket_types SET available_from=?,available_until=? WHERE id=10");
$windowUpdate->execute([$fromDate,$untilDate]);
inventoryAssert(brvtal_ticket_inventory_saleable(
    ['status'=>'published','event_date'=>$eventDate,'published_at'=>$publishedDate],
    ['status'=>'active','currency'=>'COP','available_from'=>$fromDate,'available_until'=>$untilDate],
    $wall
), 'wall-clock fixture must be saleable before the Event lock wait');
$db->beginTransaction();
$db->query("SELECT id FROM events WHERE id=1 FOR UPDATE")->fetchColumn();
$proc=proc_open([PHP_BINARY,__FILE__,'--wall',$name,'202','0'],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
inventoryAssert(is_resource($proc),'closed-window worker launched');
fclose($pipes[0]);
inventoryAssert(trim((string)fgets($pipes[1]))==='READY','closed-window worker ready');
usleep(3100000);
$db->commit();
$closedOut=trim((string)stream_get_contents($pipes[1]));
$closedErr=trim((string)stream_get_contents($pipes[2]));
fclose($pipes[1]); fclose($pipes[2]);
$closedExit=proc_close($proc);
inventoryAssert($closedExit===0 && $closedErr==='' && $closedOut==='INVENTORY_NOT_SALEABLE',
    'Event lock wait crossing available_until denies the hold');

$futureWindow = (new DateTimeImmutable('now',new DateTimeZone('America/Bogota')))
    ->modify('+1 hour')->format('Y-m-d H:i:s');
$windowUpdate->execute([$fromDate,$futureWindow]);
$db->beginTransaction();
$db->query("SELECT id FROM events WHERE id=1 FOR UPDATE")->fetchColumn();
$proc=proc_open([PHP_BINARY,__FILE__,'--wall',$name,'203','0'],
    [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
inventoryAssert(is_resource($proc),'TTL worker launched');
fclose($pipes[0]);
inventoryAssert(trim((string)fgets($pipes[1]))==='READY','TTL worker ready');
usleep(2300000);
$releasedAt=microtime(true);
$db->commit();
$heldOut=trim((string)stream_get_contents($pipes[1]));
$heldErr=trim((string)stream_get_contents($pipes[2]));
fclose($pipes[1]); fclose($pipes[2]);
$heldExit=proc_close($proc);
inventoryAssert($heldExit===0 && $heldErr==='' && $heldOut==='HELD',
    'post-wait reservation holds an available unit');
$storedExpiry=(string)$db->query("SELECT expires_at_utc FROM ticket_inventory_holds WHERE order_id=203")->fetchColumn();
$expiryUtc=new DateTimeImmutable($storedExpiry,new DateTimeZone('UTC'));
$expirySeconds=(float)$expiryUtc->format('U.u');
inventoryAssert($expirySeconds >= $releasedAt+599.4 && $expirySeconds <= $releasedAt+603,
    'hold TTL begins after the Event lock wait, not before');
echo "BRVTAL T05 paid-state, cutoff contention and post-lock TTL passed.\n";
