<?php
declare(strict_types=1);
// @brvtal-ci database
require_once __DIR__.'/../../config/ticketing_promoters.php';
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
promoAssert(!str_contains($checkoutCode,"'provider_activation'=>false")
    && !str_contains($checkoutCode,"'environment'=>'mock'"),
    'coupon checkout cannot return an unsigned simulated quote');
promoAssert(str_contains($checkoutCode,'$checkout = brvtal_ticket_wompi_prepare('),
    'coupon path uses the same provider preparation after ledger verification');
promoAssert(str_contains($checkoutCode,'if ($withPromo) brvtalPromoSynthetic($db)'),
    'D-063 guard is preserved until approved production activation');
$guardAt=strpos($checkoutCode,'brvtalPromoRejectUncodedReplay($db,$orderId)');
$providerAt=strpos($checkoutCode,'$checkout = brvtal_ticket_wompi_prepare(',
    $guardAt === false ? 0 : $guardAt);
promoAssert($guardAt!==false && $providerAt!==false && $guardAt<$providerAt,
    'the persisted coupon guard runs before the payment provider');
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
    // DATETIME(6) precise boundaries: start inclusive, expiration exclusive.
    brvtalPromoCreate($db,array_replace($rule,[
        'code'=>'BOUNDARY2026','starts_at_utc'=>'2026-11-21 12:00:00',
        'ends_at_utc'=>'2026-11-21 12:00:01','max_uses'=>8,'buyer_limit'=>2]));
    $db->beginTransaction();
    $boundary=brvtalPromoQuote($db,1,10,3,1,15000,'BOUNDARY2026',[],$now);
    promoAssert($boundary['total_cop']===10000,'opening second included');
    try {
        brvtalPromoQuote($db,1,10,3,1,15000,'BOUNDARY2026',[],
            new DateTimeImmutable('2026-11-21 12:00:01 UTC'));
        throw new RuntimeException('expiration second incorrectly accepted');
    } catch (DomainException $e) {
        promoAssert($e->getMessage()==='PROMO_UNAVAILABLE','expiration second excluded');
    }
    $db->rollBack();
    // 3x15000 COP bundle priced at 45000 COP still has a coupon ledger.
    $db->exec("INSERT INTO ticket_orders VALUES (102,3,1,'draft',45000)");
    brvtalPromoCreate($db,array_replace($rule,[
        'code'=>'GROUP3ZERO26','kind'=>'group3','value_cop'=>45000,
        'max_uses'=>8,'buyer_limit'=>2]));
    $db->beginTransaction();
    $zero=brvtalPromoReserve($db,102,1,10,3,3,15000,'GROUP3ZERO26',$now);
    promoAssert($zero['subtotal_cop']===45000 && $zero['total_cop']===45000
        && $zero['discount_cop']===0,'zero-discount bundle persisted');
    try {
        brvtalPromoRejectUncodedReplay($db,102);
        throw new RuntimeException('coupon omission accepted');
    } catch (DomainException $e) {
        promoAssert($e->getMessage()==='CHECKOUT_RETRY_CONFLICT',
            'zero-discount replay rejects omitted code');
    }
    brvtalPromoRejectUncodedReplay($db,999);
    $db->commit();
    $db->exec("UPDATE ticket_orders SET status='paid' WHERE id=100");
    $frozen=(int)$db->query('SELECT total_cop FROM ticket_promo_redemptions WHERE order_id=100')
        ->fetchColumn();
    brvtalPromoDeactivate($db,$id);
    promoAssert((int)$db->query('SELECT total_cop FROM ticket_promo_redemptions WHERE order_id=100')
        ->fetchColumn()===$frozen,'paid snapshot never edited');
    promoAssert((int)$db->query('SELECT total_cop FROM ticket_orders WHERE id=100')
        ->fetchColumn()===25000,'paid order immutable');
    // No zero-COP checkout is valid for hosted Wompi, regardless of rule kind.
    foreach ([
        ['code'=>'FREEFIXED26','kind'=>'fixed','value_cop'=>15000],
        ['code'=>'FREEPERCENT26','kind'=>'percent','value_cop'=>100],
    ] as $freeRule) {
        brvtalPromoCreate($db,array_replace($rule,$freeRule));
        $db->beginTransaction();
        try {
            brvtalPromoQuote($db,1,10,3,1,15000,$freeRule['code'],[],$now);
            throw new RuntimeException('T23: zero-COP promotion accepted');
        } catch (DomainException $e) {
            promoAssert($e->getMessage()==='PROMO_ZERO_TOTAL',
                'free coupon denied before attempting a provider charge');
        } finally {
            $db->rollBack();
        }
    }
    // Paused rule with spare quota and no redemptions, independent of exhausted rule.
    $pausedId=brvtalPromoCreate($db,array_replace($rule,[
        'code'=>'PAUSED2026','max_uses'=>8,'buyer_limit'=>2]));
    brvtalPromoDeactivate($db,$pausedId);
    $db->beginTransaction();
    try {
        brvtalPromoQuote($db,1,10,3,1,15000,'PAUSED2026',[],$now);
        throw new RuntimeException('paused rule accepted');
    } catch (DomainException $e) {
        promoAssert($e->getMessage()==='PROMO_UNAVAILABLE','paused rule denied');
    } finally { $db->rollBack(); }
    promoAssert((int)$db->query('SELECT uses_count FROM ticket_promo_rules WHERE id=' . $pausedId)->fetchColumn()===0,
        'paused rule consumes no quota');
    $beforeRules=(int)$db->query('SELECT COUNT(*) FROM ticket_promo_rules')->fetchColumn();
    $beforeRedemptions=(int)$db->query('SELECT COUNT(*) FROM ticket_promo_redemptions')->fetchColumn();
    $beforeUses=(int)$db->query('SELECT SUM(uses_count) FROM ticket_promo_rules')->fetchColumn();
    putenv('BRVTAL_INTEGRATION_TESTS');
    try {
        foreach ([
            fn()=>brvtalPromoCreate($db,array_replace($rule,['code'=>'GUARDOFF26'])),
            fn()=>brvtalPromoQuote($db,1,10,3,1,15000,'PERCENT2026',[],$now),
            fn()=>brvtalPromoDeactivate($db,$pausedId)
        ] as $reject) {
            try { $reject(); throw new RuntimeException('non-synthetic promo accepted'); }
            catch (DomainException $e) {
                promoAssert($e->getMessage()==='PROMOS_NOT_ENABLED','non-synthetic operation denied');
            }
        }
    } finally { putenv('BRVTAL_INTEGRATION_TESTS=1'); }
    promoAssert((int)$db->query('SELECT COUNT(*) FROM ticket_promo_rules')->fetchColumn()===$beforeRules &&
        (int)$db->query('SELECT COUNT(*) FROM ticket_promo_redemptions')->fetchColumn()===$beforeRedemptions &&
        (int)$db->query('SELECT SUM(uses_count) FROM ticket_promo_rules')->fetchColumn()===$beforeUses,
        'non-synthetic rejection has no persistent side effects');

    // T26: use the existing disposable T23 namespace; no real identities/providers.
    $db->exec('ALTER TABLE ticket_orders ADD COLUMN currency CHAR(3) NOT NULL DEFAULT "COP"');
    $db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_promoters_01.sql'));
    foreach ([
        'CREATE TABLE ticket_payment_attempts (id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,provider VARCHAR(24),status VARCHAR(20),gross_amount_cop BIGINT UNSIGNED,provider_reference VARCHAR(190),provider_transaction_id VARCHAR(190))',
        'CREATE TABLE ticket_payment_webhook_events (attempt_id BIGINT UNSIGNED,provider_transaction_id VARCHAR(190))',
        'CREATE TABLE ticket_pos_sales (id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,method VARCHAR(24))',
        'CREATE TABLE ticket_pos_shifts (id BIGINT UNSIGNED PRIMARY KEY)',
        'CREATE TABLE ticket_courtesy_invites (order_id BIGINT UNSIGNED)',
        'CREATE TABLE ticket_refunds (id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,event_id INT UNSIGNED,status VARCHAR(20),amount_cop BIGINT UNSIGNED)',
        'CREATE TABLE ticket_refund_adjustments (refund_id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,event_id INT UNSIGNED,amount_cop BIGINT UNSIGNED)'
    ] as $ddl) $db->exec($ddl);
    $p1=brvtalPromoterCreate($db,'SYNTHETIC-A');
    $p2=brvtalPromoterCreate($db,'SYNTHETIC-B');
    $codeA=brvtalPromoterCreateCode($db,$p1,'REFCODE-A');
    $codeB=brvtalPromoterCreateCode($db,$p2,'REFCODE-B');
    $paused=brvtalPromoterCreateCode($db,$p1,'REFCODE-X');
    brvtalPromoterDeactivateCode($db,$p1,$paused);
    $db->exec("INSERT INTO ticket_orders(id,buyer_id,event_id,status,total_cop) VALUES
        (200,1,1,'draft',20000),(201,2,1,'draft',30000),(202,3,1,'draft',10000),
        (203,1,1,'draft',5000),(204,2,1,'draft',7000),(205,3,1,'draft',9000),
        (206,1,1,'draft',8000),(207,2,1,'draft',1000)");
    $attribute=static function(int $id,array $codes) use ($db):?int {
        $db->beginTransaction();
        try { $result=brvtalPromoterAttribute($db,$id,$codes); $db->commit(); return $result; }
        catch(Throwable $e) { $db->rollBack(); throw $e; }
    };
    promoAssert($attribute(200,['REFCODE-B',' refcode-a ','REFCODE-X','bad'])===$codeA,'last valid explicit code');
    promoAssert($attribute(200,['REFCODE-X'])===$codeA,'invalid new code cannot erase valid previous');
    promoAssert($attribute(201,['REFCODE-A','REFCODE-B'])===$codeB,'latest valid across sources');
    promoAssert($attribute(207,['bad','REFCODE-X'])===null,'invalid/disabled not attributed');
    foreach ([202,203,204,205,206] as $id) $attribute($id,['REFCODE-A']);
    $db->exec("UPDATE ticket_orders SET status='paid' WHERE id IN (200,201,202,203,204,206)");
    $db->exec("UPDATE ticket_orders SET status='cancelled' WHERE id=205");
    $db->exec("UPDATE ticket_orders SET status='refunded' WHERE id=204");
    $q=$db->prepare("INSERT INTO ticket_payment_attempts VALUES (?,?,'wompi','approved',?, ?, ?)");
    $event=$db->prepare('INSERT INTO ticket_payment_webhook_events VALUES (?,?)');
    foreach ([[1,200,20000],[2,201,30000],[3,202,10000],[4,203,5000],[5,203,5000],
        [6,204,7000],[7,205,9000],[8,206,8000]] as [$attempt,$order,$amount]) {
        $tx='FIXTURE-'.$attempt;
        $q->execute([$attempt,$order,$amount,'REF-'.$attempt,$tx]);
        $event->execute([$attempt,$tx]);
        if ($attempt===1) $event->execute([$attempt,$tx]); // callback replay is not a sale
    }
    $db->exec("INSERT INTO ticket_refunds VALUES (1,202,1,'refunded',3000),(2,206,1,'refunded',8000)");
    $db->exec('INSERT INTO ticket_refund_adjustments VALUES (1,202,1,3000),(2,206,1,8000)');
    brvtalPromoterDeactivate($db,$p1);
    try { $attribute(200,['REFCODE-B']); throw new RuntimeException('paid attribution changed'); }
    catch (DomainException $e) { promoAssert($e->getMessage()==='ATTRIBUTION_FROZEN','paid snapshot immutable'); }
    $q->execute([9,207,1000,'REF-9','FIXTURE-9']);
    try { $attribute(207,['REFCODE-B']); throw new RuntimeException('payment attempt attribution changed'); }
    catch (DomainException $e) { promoAssert($e->getMessage()==='ATTRIBUTION_FROZEN','payment attempt freezes attribution'); }
    promoAssert(brvtalPromoterSales($db,$p1)===[
        ['code'=>'REFCODE-A','paid_orders'=>2,'gross_cop'=>30000,'net_cop'=>27000],
        ['code'=>'REFCODE-X','paid_orders'=>0,'gross_cop'=>0,'net_cop'=>0]
    ],'historical aggregate: partial refund, full refund, duplicate approvals, replay, cancellation and no buyer data');
    promoAssert(brvtalPromoterSales($db,$p2)===[
        ['code'=>'REFCODE-B','paid_orders'=>1,'gross_cop'=>30000,'net_cop'=>30000]
    ],'scope cannot include other promoter');
    try { brvtalPromoterCreateCode($db,$p1,'REFCODE-Y'); throw new RuntimeException('disabled promoter code created'); }
    catch (DomainException $e) { promoAssert($e->getMessage()==='PROMOTER_UNAVAILABLE','disabled promoter'); }
    $db->exec('DELETE FROM ticket_refund_adjustments WHERE refund_id=1');
    try { brvtalPromoterSales($db,$p1); throw new RuntimeException('missing refund treated as zero'); }
    catch (DomainException $e) { promoAssert($e->getMessage()==='ATTRIBUTION_EVIDENCE_UNAVAILABLE','missing evidence fails closed'); }
    putenv('BRVTAL_INTEGRATION_TESTS');
    try {
        brvtalPromoterSales($db,$p1); throw new RuntimeException('non-synthetic report accepted');
    } catch (DomainException $e) { promoAssert($e->getMessage()==='PROMOS_NOT_ENABLED','synthetic guard'); }
    finally { putenv('BRVTAL_INTEGRATION_TESTS=1'); }
}

echo "T23 promo windows, quotas, lock, snapshots and integer COP OK (synthetic).".PHP_EOL;
// Run coupon Wompi ledger regression under the same isolated T23 MariaDB matrix.
require __DIR__.'/ticketing-wompi.php';
if (getenv('BRVTAL_INTEGRATION_TESTS')==='1') echo "BRVTAL_MARKED_DATABASE_PASS: ticketing-promos.php\n";
