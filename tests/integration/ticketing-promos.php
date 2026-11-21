<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_promos.php';
function promoAssert(bool $yes,string $message): void {
    if (!$yes) throw new RuntimeException('T23: '.$message);
}
$rule=['code'=>'NOCHE2026A','event_id'=>1,'ticket_type_id'=>10,
    'kind'=>'fixed','value_cop'=>5000,
    'starts_at_utc'=>'2026-01-01 00:00:00','ends_at_utc'=>'2027-01-01 00:00:00',
    'max_uses'=>2,'buyer_limit'=>1,'campaign_key'=>'noche'];
promoAssert(strlen(brvtalPromoDigest($rule['code']))===64,'hash-only code');
promoAssert(brvtalPromoDigest('noche2026a')===brvtalPromoDigest($rule['code']),
    'canonical code normalization');
foreach ([array_replace($rule,['value_cop'=>-1]),
    array_replace($rule,['kind'=>'percent','value_cop'=>101]),
    array_replace($rule,['ends_at_utc'=>'2025-01-01 00:00:00']),
    array_replace($rule,['code'=>'BAD']),
    array_replace($rule,['campaign_key'=>'<script>'])] as $invalid) {
    try { brvtalPromoInput($invalid); throw new RuntimeException('bad rule accepted'); }
    catch (InvalidArgumentException) {}
}
$source=(string)file_get_contents(__DIR__.'/../../api/ticketing/admin-promos.php');
promoAssert(str_contains($source,'brvtal_admin_require_csrf'),'CSRF mandatory');
promoAssert(str_contains($source,"'settings'"),'RBAC mandatory');
promoAssert(!str_contains($source,'code_digest,kind'),'list must not disclose digest');
promoAssert(str_contains((string)file_get_contents(__DIR__.'/../../config/ticketing_promos.php'),
    'BRVTAL_INTEGRATION_TESTS'),'live writes blocked');
$checkoutCode=(string)file_get_contents(__DIR__.'/../../config/public_ticket_checkout.php');
promoAssert(str_contains($checkoutCode,"'promo_code'"),'coupon is strictly whitelisted');
promoAssert(str_contains($checkoutCode,'brvtalPromoQuote('),'quote before order');
promoAssert(str_contains($checkoutCode,'brvtalPromoReserve('),'ledger reservation in checkout tx');
promoAssert(str_contains($checkoutCode,"'promo_breakdown'"),'server returns immutable COP breakdown');
promoAssert(str_contains($checkoutCode,"'environment'=>'mock'"),'T23 never creates real provider attempts');
if (getenv('BRVTAL_INTEGRATION_TESTS')==='1') {
    $prefix=(string)(getenv('BRVTAL_TEST_DB_NAME')?:'');
    promoAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$prefix),
        'isolated database namespace required');
    $host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
    $port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
    $user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
    $pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,$opts);
    $name=$prefix.'_promo_'.bin2hex(random_bytes(3));
    $server->exec("CREATE DATABASE `{$name}`");
    register_shutdown_function(static function()use($server,$name):void{
        try { $server->exec("DROP DATABASE IF EXISTS `{$name}`"); } catch(Throwable){}
    });
    $dsn="mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $db=new PDO($dsn,$user,$pass,$opts);
    foreach ([
        'CREATE TABLE events (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,
          event_id INT UNSIGNED NOT NULL, UNIQUE KEY uq_type_event (id,event_id)) ENGINE=InnoDB',
        'CREATE TABLE ticket_buyers (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,
          buyer_id BIGINT UNSIGNED NOT NULL,event_id INT UNSIGNED NOT NULL,
          status VARCHAR(20) NOT NULL,total_cop BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_order_items (order_id BIGINT UNSIGNED NOT NULL,
          ticket_type_id INT UNSIGNED NOT NULL,quantity INT UNSIGNED NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_events (event_id INT UNSIGNED PRIMARY KEY,
          capacity INT UNSIGNED NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_types (ticket_type_id INT UNSIGNED PRIMARY KEY,
          event_id INT UNSIGNED NOT NULL,capacity INT UNSIGNED NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_holds (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          order_id BIGINT UNSIGNED NOT NULL,event_id INT UNSIGNED NOT NULL,
          ticket_type_id INT UNSIGNED NOT NULL,quantity INT UNSIGNED NOT NULL,
          kind VARCHAR(20) NOT NULL,status VARCHAR(20) NOT NULL,
          expires_at_utc DATETIME(6) NOT NULL) ENGINE=InnoDB'
    ] as $ddl) $db->exec($ddl);
    $sql=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_promos_01.sql');
    $sql=(string)preg_replace('/^\s*--[^\n]*$/m','',$sql);
    foreach (array_filter(array_map(trim(...),explode(';',$sql))) as $statement) {
        $db->exec($statement);
    }
    $db->exec('INSERT INTO events VALUES (1),(2)');
    $db->exec('INSERT INTO event_ticket_types VALUES (10,1),(20,2)');
    $db->exec('INSERT INTO ticket_buyers VALUES (1),(2),(3)');
    $db->exec('INSERT INTO ticket_inventory_events VALUES (1,5),(2,5)');
    $db->exec('INSERT INTO ticket_inventory_types VALUES (10,1,5),(20,2,5)');
    $db->exec("INSERT INTO ticket_orders VALUES
      (100,1,1,'draft',25000),(101,2,1,'draft',25000)");
    $id=brvtalPromoCreate($db,$rule);
    promoAssert($id>0,'rule persisted');
    try { brvtalPromoCreate($db,$rule); throw new RuntimeException('duplicate accepted'); }
    catch (DomainException $e) { promoAssert($e->getMessage()==='PROMO_ALREADY_EXISTS','duplicate denied'); }
    $now=new DateTimeImmutable('2026-11-21 12:00:00',new DateTimeZone('UTC'));
    $db->beginTransaction();
    $q=brvtalPromoQuote($db,1,10,1,2,15000,'noche2026a',[],$now);
    promoAssert($q['subtotal_cop']===30000 && $q['discount_cop']===5000 &&
        $q['total_cop']===25000,'integer COP and breakdown');
    // A competing checkout cannot bypass the Event mutex while first tx owns it.
    $other=new PDO($dsn,$user,$pass,$opts);
    $other->exec('SET innodb_lock_wait_timeout=1');
    $other->beginTransaction();
    try {
        brvtalPromoQuote($other,1,10,2,2,15000,$rule['code'],[],$now);
        throw new RuntimeException('parallel checkout bypassed event lock');
    } catch (PDOException) {
        promoAssert(true,'second checkout failed closed on the occupied mutex');
    } finally { if ($other->inTransaction()) $other->rollBack(); }
    $first=brvtalPromoReserve($db,100,1,10,1,2,15000,$rule['code'],$now);
    promoAssert($first['total_cop']===25000,'exact order total');
    promoAssert(brvtalPromoReserve($db,100,1,10,1,2,15000,$rule['code'],$now)
        ['total_cop']===25000,'idempotent frozen retry');
    $db->commit();
    $db->beginTransaction();
    try {
        brvtalPromoQuote($db,1,10,1,1,15000,$rule['code'],[],$now);
        throw new RuntimeException('buyer limit bypassed');
    } catch (DomainException $e) {
        promoAssert($e->getMessage()==='PROMO_BUYER_LIMIT','buyer limit');
    }
    $db->rollBack();
    $db->beginTransaction();
    brvtalPromoReserve($db,101,1,10,2,2,15000,$rule['code'],$now);
    $db->commit();
    $db->beginTransaction();
    try {
        brvtalPromoQuote($db,1,10,3,1,15000,$rule['code'],[],$now);
        throw new RuntimeException('global quota bypassed');
    } catch (DomainException $e) {
        promoAssert($e->getMessage()==='PROMO_UNAVAILABLE','max uses');
    }
    $db->rollBack();
    foreach ([
        ['code'=>'PERCENT2026','kind'=>'percent','value_cop'=>10],
        ['code'=>'GROUP32026','kind'=>'group3','value_cop'=>40000],
        ['code'=>'GROUP52026','kind'=>'group5','value_cop'=>70000]
    ] as $variant) brvtalPromoCreate($db,array_replace($rule,$variant,['max_uses'=>8,'buyer_limit'=>2]));
    $db->beginTransaction();
    $pct=brvtalPromoQuote($db,1,10,3,1,10001,'PERCENT2026',[],$now);
    promoAssert($pct['discount_cop']===1000 && $pct['total_cop']===9001,'percent floors exact COP');
    $combo=brvtalPromoQuote($db,1,10,3,3,15000,'GROUP32026',[],$now);
    promoAssert($combo['total_cop']===40000,'three-ticket bundle');
    foreach ([
      fn()=>brvtalPromoQuote($db,2,20,3,1,15000,'PERCENT2026',[],$now),
      fn()=>brvtalPromoQuote($db,1,10,3,3,15000,'GROUP32026',['old'], $now),
      fn()=>brvtalPromoQuote($db,1,10,3,2,15000,'GROUP32026',[],$now),
      fn()=>brvtalPromoQuote($db,1,10,3,6,15000,'GROUP52026',[],$now),
      fn()=>brvtalPromoQuote($db,1,10,3,1,15000,'PERCENT2026',[],
          new DateTimeImmutable('2100-01-01 UTC'))
    ] as $reject) {
        try { $reject(); throw new RuntimeException('invalid promo accepted'); }
        catch (DomainException) {}
    }
    $db->rollBack();
    $db->exec("UPDATE ticket_orders SET status='paid' WHERE id=100");
    $frozen=(int)$db->query('SELECT total_cop FROM ticket_promo_redemptions WHERE order_id=100')
        ->fetchColumn();
    brvtalPromoDeactivate($db,$id);
    promoAssert((int)$db->query('SELECT total_cop FROM ticket_promo_redemptions WHERE order_id=100')
        ->fetchColumn()===$frozen,'paid snapshot never edited');
    promoAssert((int)$db->query('SELECT total_cop FROM ticket_orders WHERE id=100')
        ->fetchColumn()===25000,'paid order immutable');
}
echo "T23 promo windows, quotas, lock, snapshots and integer COP OK (synthetic).".PHP_EOL;
