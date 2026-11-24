<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_stages.php';
function stageAssert(bool $yes,string $why):void {
    if (!$yes) throw new RuntimeException('T22: '.$why);
}
$stages=[
 ['code'=>'early_bird','price_cop'=>15000,'cumulative_capacity'=>2,
  'opens_at_local'=>'2026-12-01 09:00:00','closes_at_local'=>'2026-12-04 20:00:00'],
 ['code'=>'preventa','price_cop'=>20000,'cumulative_capacity'=>5,
  'opens_at_local'=>'2026-12-02 09:00:00','closes_at_local'=>'2026-12-04 21:00:00'],
 ['code'=>'general','price_cop'=>30000,'cumulative_capacity'=>10,
  'opens_at_local'=>'2026-12-04 21:00:00','closes_at_local'=>'2026-12-04 23:59:59']
];
$now=static fn(string $s):DateTimeImmutable=>new DateTimeImmutable($s,new DateTimeZone('America/Bogota'));
stageAssert(brvtalTicketStageCurrent($stages,0,$now('2026-11-30 21:00:00'))===null,'before all windows');
stageAssert(brvtalTicketStageCurrent($stages,0,$now('2026-12-01 09:00:00'))['code']==='early_bird','inclusive opening');
stageAssert(brvtalTicketStageCurrent($stages,2,$now('2026-12-01 10:00:00'))===null,'no oversell before next stage');
stageAssert(brvtalTicketStageCurrent($stages,2,$now('2026-12-02 09:00:00'))['code']==='preventa','exhaustion transition');
stageAssert(brvtalTicketStageCurrent($stages,0,$now('2026-12-02 10:00:00'))['code']==='preventa','time transition');
stageAssert(brvtalTicketStageCurrent($stages,5,$now('2026-12-03 10:00:00'))===null,'quota fully consumed');
stageAssert(brvtalTicketStageCurrent($stages,5,$now('2026-12-04 21:00:00'))['code']==='general','new stage');
stageAssert(brvtalTicketStageCurrent($stages,10,$now('2026-12-04 21:00:00'))===null,'zero availability');
stageAssert(brvtalTicketStageCurrent($stages,0,$now('2026-12-05 00:00:00'))===null,'after last close');
foreach ([
  [array_replace($stages[0],['price_cop'=>-1]),$stages[1]],
  [array_replace($stages[0],['opens_at_local'=>'2026-02-30 09:00:00'])],
  [array_replace($stages[0],['cumulative_capacity'=>5]),array_replace($stages[1],['cumulative_capacity'=>2])],
  [$stages[0],$stages[0]]
] as $invalid) {
    try { brvtalTicketStageInput($invalid); throw new RuntimeException('invalid stage accepted'); }
    catch (InvalidArgumentException) {}
}
$quote = brvtalTicketStageQuote($stages,0,2,4,$now('2026-12-01 09:00:00'));
stageAssert($quote['code']==='early_bird' && $quote['unit_price_cop']===15000
    && $quote['subtotal_cop']===30000 && $quote['available']===2,
    'quote uses exact COP and stage allocation');
$quote = brvtalTicketStageQuote($stages,2,2,3,$now('2026-12-02 09:00:00'));
stageAssert($quote['code']==='preventa' && $quote['subtotal_cop']===40000,
    'stage rollover uses server price and cumulative capacity');
foreach ([
    [$stages,0,3,5,$now('2026-12-01 09:00:00')],
    [$stages,2,1,0,$now('2026-12-02 09:00:00')],
    [$stages,0,1,10,$now('2026-11-30 09:00:00')],
    [[array_replace($stages[0],['price_cop'=>10000000])],0,2,2,$now('2026-12-01 09:00:00')],
] as $notSaleable) {
    try {
        brvtalTicketStageQuote(...$notSaleable);
        throw new RuntimeException('stage quote accepted unavailable purchase');
    } catch (DomainException) {}
}
try {
    brvtalTicketStageQuote($stages,-1,1,2,$now('2026-12-01 09:00:00'));
    throw new RuntimeException('negative inventory accepted');
} catch (InvalidArgumentException) {}
$read=(string)file_get_contents(__DIR__.'/../../api/ticketing/admin-stages.php');
stageAssert(str_contains($read,'brvtal_admin_require_csrf'),'admin POST CSRF required');
stageAssert(str_contains($read,"'settings'"),'admin settings permission required');
stageAssert(!str_contains((string)file_get_contents(__DIR__.'/../../config/ticketing_stages.php'),
    'BRVTAL_INTEGRATION_TESTS'),'stage writes do not require a synthetic flag');
stageAssert(!str_contains($read,'ticket_order_items'),'API must not rewrite order snapshots');
$checkoutSource = (string)file_get_contents(__DIR__.'/../../config/public_ticket_checkout.php');
$stageQuoteSource=substr($checkoutSource,
    strpos($checkoutSource,'function brvtal_public_ticket_checkout_stage_quote('),
    strpos($checkoutSource,'function brvtal_public_ticket_checkout_cart_lines(')
      - strpos($checkoutSource,'function brvtal_public_ticket_checkout_stage_quote('));
stageAssert(!str_contains($stageQuoteSource,'BRVTAL_INTEGRATION_TESTS')
    && str_contains($stageQuoteSource,'STAGE_TRANSACTION_REQUIRED')
    && str_contains($stageQuoteSource,'FOR UPDATE'),
    'stage checkout quote works without a test flag under the event mutex');
stageAssert(str_contains($checkoutSource, "brvtal_public_ticket_checkout_stage_quote("),
    'server-side quote is invoked in checkout before order insertion');
stageAssert(str_contains($checkoutSource, "unit_price_cop,subtotal_cop"),
    'idempotent checkout reads the immutable item price snapshot');
stageAssert(str_contains($checkoutSource, 'Display the same stage price as checkout')
    && str_contains($checkoutSource, "\$price = \$quote['unit_price_cop'];"),
    'public offers show active stage price, not stale editorial price');
$core = (string)file_get_contents(__DIR__.'/../../discadmin/content-core.js');
stageAssert((bool)preg_match("~import\\('/discadmin/ticketing-stages\\.js'\\s*\\+~", $core),
    'stage editor mounted through cache-versioned dynamic import');
stageAssert(str_contains($core,'Number(currentEvent?.id)'),
    'stage editor only for persisted event with valid identity');

if (getenv('BRVTAL_INTEGRATION_TESTS') === '1') {
    require_once __DIR__.'/../../config/public_ticket_checkout.php';
    $prefix = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
    stageAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$prefix),
        'MariaDB tests require disposable database namespace');
    $host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
    $port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
    $user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
    $pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
    $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,$options);
    $name = $prefix.'_stages_'.bin2hex(random_bytes(3));
    $server->exec("CREATE DATABASE `{$name}`");
    register_shutdown_function(static function () use ($server,$name): void {
        try { $server->exec("DROP DATABASE IF EXISTS `{$name}`"); } catch (Throwable) {}
    });
    $db = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,$options);
    foreach ([
        'CREATE TABLE events (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL, UNIQUE KEY uq_stage_type_event (id,event_id)) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_events (event_id INT UNSIGNED PRIMARY KEY,
            capacity INT UNSIGNED NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_types (ticket_type_id INT UNSIGNED PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL, capacity INT UNSIGNED NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_order_items (order_id BIGINT UNSIGNED NOT NULL,
            ticket_type_id INT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL,
            unit_price_cop INT UNSIGNED NOT NULL, subtotal_cop INT UNSIGNED NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_holds (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL, event_id INT UNSIGNED NOT NULL,
            ticket_type_id INT UNSIGNED NOT NULL, quantity INT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL,
            expires_at_utc DATETIME(6) NOT NULL) ENGINE=InnoDB'
    ] as $ddl) $db->exec($ddl);
    $migration = (string)file_get_contents(__DIR__.'/../../database/migration_ticketing_stages_01.sql');
    $sql = (string)preg_replace('/^\s*--[^\n]*$/m','',$migration);
    foreach (array_filter(array_map(trim(...),explode(';',$sql))) as $statement) $db->exec($statement);
    $db->exec("INSERT INTO events VALUES(1)");
    $db->exec("INSERT INTO event_ticket_types VALUES(10,1)");
    $db->exec("INSERT INTO ticket_inventory_events VALUES(1,5)");
    $db->exec("INSERT INTO ticket_inventory_types VALUES(10,1,5)");
    // The env flag is needed only to provision an isolated MariaDB database.
    // Business behavior must now work without it, as it does in production.
    putenv('BRVTAL_INTEGRATION_TESTS=0');
    brvtalTicketStageSave($db,1,10,$stages);
    $quoted = $db->beginTransaction();
    $quoted = brvtal_public_ticket_checkout_stage_quote($db,1,10,2,$now('2026-12-01 09:01:00'));
    stageAssert($quoted['unit_price_cop']===15000 && $quoted['subtotal_cop']===30000,
        'quoted price under Event lock and synthetic schema');
    $db->rollBack();
    $db->beginTransaction();
    try {
        brvtal_public_ticket_checkout_stage_quote($db,1,10,3,$now('2026-12-01 09:01:00'));
        throw new RuntimeException('stage max sold without quota');
    } catch (DomainException $e) {
        stageAssert($e->getMessage()==='STAGE_SOLD_OUT','server refuses excess quantity');
    }
    $db->rollBack();
    $db->beginTransaction();
    try {
        brvtal_public_ticket_checkout_stage_quote($db,1,10,1,$now('2026-11-30 09:01:00'));
        throw new RuntimeException('stage sold before window');
    } catch (DomainException $e) {
        stageAssert($e->getMessage()==='STAGE_NOT_SALEABLE','server refuses closed window');
    }
    $db->rollBack();
    // Two independent connections attempt the final Early Bird allocation.
    // The second checkout must observe the first hold after the Event mutex.
    $db->beginTransaction();
    $first=brvtal_public_ticket_checkout_stage_quote(
        $db,1,10,2,$now('2026-12-01 09:01:00'));
    stageAssert($first['code']==='early_bird','first checkout receives active stage');
    $worker=tempnam(sys_get_temp_dir(),'brvtal_t22_');
    stageAssert(is_string($worker),'temporary concurrency worker');
    $code=<<<'WORKER_WRAP'
    <?php
    declare(strict_types=1);
    require_once $argv[1];
    $db=new PDO('mysql:host='.getenv('BRVTAL_TEST_DB_HOST').';port='.
        getenv('BRVTAL_TEST_DB_PORT').';dbname='.getenv('BRVTAL_WORKER_DB').';charset=utf8mb4',
        getenv('BRVTAL_TEST_DB_USER'),getenv('BRVTAL_TEST_DB_PASS'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec('SET SESSION innodb_lock_wait_timeout=5');
    $db->beginTransaction();
    echo "READY\n";flush();
    try {
        $quote=brvtal_public_ticket_checkout_stage_quote($db,1,10,1,
          new DateTimeImmutable('2026-12-01 09:01:00',new DateTimeZone('America/Bogota')));
        echo $quote['code']."\n";
    } catch (DomainException $e) {
        echo $e->getMessage()."\n";
    }
    $db->rollBack();
    WORKER_WRAP;
    file_put_contents($worker,$code);
    $env=array_merge($_ENV,[
      'BRVTAL_INTEGRATION_TESTS'=>'0',
      'BRVTAL_TEST_DB_HOST'=>$host,
      'BRVTAL_TEST_DB_PORT'=>(string)$port,
      'BRVTAL_TEST_DB_USER'=>$user,
      'BRVTAL_TEST_DB_PASS'=>$pass,
      'BRVTAL_WORKER_DB'=>$name
    ]);
    $proc=proc_open([PHP_BINARY,$worker,__DIR__.'/../../config/public_ticket_checkout.php'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
    stageAssert(is_resource($proc),'second checkout process started');
    fclose($pipes[0]);
    stageAssert(trim((string)fgets($pipes[1]))==='READY',
        'second checkout connected before first committed');
    usleep(150000);
    $db->exec("INSERT INTO ticket_inventory_holds
        (order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
        VALUES(101,1,10,2,'paid','active','2099-01-01 00:00:00')");
    $db->commit();
    $second=trim((string)stream_get_contents($pipes[1]));
    $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($proc);
    @unlink($worker);
    stageAssert($exit===0 && $second==='STAGE_NOT_SALEABLE',
      'concurrent checkout must not exceed Early Bird cap: '.$second.' '.$errors);
    $db->beginTransaction();
    $rollover=brvtal_public_ticket_checkout_stage_quote(
        $db,1,10,1,$now('2026-12-02 09:01:00'));
    stageAssert($rollover['code']==='preventa' && $rollover['unit_price_cop']===20000,
      'sold-out stage rolls into next stage at opening time');
    $db->rollBack();
    $db->exec('DELETE FROM ticket_inventory_holds WHERE order_id=101');
    $db->exec("INSERT INTO ticket_orders VALUES(100,1,'paid')");
    $db->exec("INSERT INTO ticket_order_items VALUES(100,10,1,15000,15000)");
    brvtalTicketStageSave($db,1,10,[array_replace($stages[0],['price_cop'=>16000])]);
    stageAssert((int)$db->query('SELECT unit_price_cop FROM ticket_order_items WHERE order_id=100')->fetchColumn()===15000,
        'saved stage does not rewrite paid order snapshot');
    $db->exec("INSERT INTO ticket_inventory_holds
        (order_id,event_id,ticket_type_id,quantity,kind,status,expires_at_utc)
        VALUES(101,1,10,1,'paid','active','2099-01-01 00:00:00')");
    try {
        brvtalTicketStageSave($db,1,10,[array_replace($stages[0],['price_cop'=>17000])]);
        throw new RuntimeException('stage update allowed active hold');
    } catch (DomainException $e) {
        stageAssert($e->getMessage()==='ACTIVE_RESERVATIONS',
            'live holds block retroactive stage configuration');
    }
}

echo "T22 live stage pricing, Event-lock concurrency, snapshots and inputs OK (disposable DB).".PHP_EOL;
