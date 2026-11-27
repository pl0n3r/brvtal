<?php
declare(strict_types=1);
// @brvtal-ci database
require_once __DIR__.'/../../config/ticketing_event_notice_audience.php';
function noticeAssert(bool $ok,string $why): void {if (!$ok) throw new RuntimeException('T2090: '.$why);}
foreach ([[],['event_id'=>'0'],['event_id'=>'01'],['event_id'=>['1']],['event_id'=>'1 OR 1=1'],
    ['event_id'=>'1','recipient_id'=>'9'],['event_id'=>'4294967296']] as $bad) {
    try {brvtalNoticeEventId($bad); throw new RuntimeException('accepted invalid filter');} catch (InvalidArgumentException) {}
}
noticeAssert(brvtalNoticeEventId(['event_id'=>'7'])===7,'typed scope');
noticeAssert(!brvtalTicketingCan('admin','refund')&&!brvtalTicketingCan('door','refund'),'least privilege');
noticeAssert(brvtalNoticeCounts([],7,'cancelled')['counts']['candidate_recipients']===0,'known zero');
noticeAssert(brvtalNoticeCounts([],7,null)['counts']===null,'unknown incident');
$sample=['id'=>1,'buyer_id'=>1,'event_id'=>7,'email'=>'a@example.test','buyer_status'=>'guest','consistent'=>true,
    'status'=>'paid','terminal_entries'=>0,'channel'=>'wompi','verified'=>true,'issued'=>1,'used'=>0,'units'=>1,'pending_evidence'=>false];
$other=['id'=>2,'email'=>' A@EXAMPLE.TEST ','channel'=>'pos']+$sample;
noticeAssert(brvtalNoticeCounts([$sample,$other],7,'cancelled')['counts']['candidate_recipients']===1,'dedupe normalized identity across channels');
$other['buyer_id']=2;
noticeAssert(brvtalNoticeCounts([$sample,$other],7,'cancelled')['counts']['groups']['ambiguous_review']===1,'no inferred account merge');
$other=['id'=>2,'buyer_status'=>'blocked']+$sample;
noticeAssert(brvtalNoticeCounts([$sample,$other],7,'cancelled')['counts']['groups']['blocked_review']===1,'blocked identity takes precedence');
noticeAssert(brvtalNoticeCounts([['units'=>2]+$sample],7,'cancelled')['counts']['groups']['mixed_review']===1,'partial issuance needs review');
noticeAssert(brvtalNoticeCounts([$sample,$sample],7,'cancelled')['counts']===null,'duplicate order snapshot unknown');
noticeAssert(brvtalNoticeCounts([['event_id'=>8]+$sample],7,'cancelled')['counts']===null,'foreign event snapshot unknown');
noticeAssert(getenv('BRVTAL_INTEGRATION_TESTS')==='1' && getenv('BRVTAL_TEST_DB_HOST')==='127.0.0.1','disposable loopback integration required');
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
noticeAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$base),'disposable DB only');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1'); $port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root'); $pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$name=$base.'_notice_'.bin2hex(random_bytes(3));
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$root=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,$opts);
$root->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try {
    $db=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,$opts);
    // Minimal canonical column projection, intentionally permitting malformed relationships for negative tests.
    $ddl=[
        'events'=>'id INT PRIMARY KEY',
        'ticket_refund_event_decisions'=>'event_id INT PRIMARY KEY,cause VARCHAR(20)',
        'ticket_buyers'=>'id BIGINT PRIMARY KEY,email VARCHAR(190),status VARCHAR(20)',
        'event_ticket_types'=>'id INT PRIMARY KEY,event_id INT',
        'ticket_orders'=>'id BIGINT PRIMARY KEY,event_id INT,buyer_id BIGINT,status VARCHAR(20),currency CHAR(3),total_cop BIGINT',
        'ticket_order_items'=>'id BIGINT PRIMARY KEY,order_id BIGINT,event_id INT,ticket_type_id INT,quantity INT,currency CHAR(3),subtotal_cop BIGINT,unit_price_cop BIGINT',
        'ticket_entries'=>'id BIGINT PRIMARY KEY,order_item_id BIGINT,event_id INT,ticket_type_id INT,unit_number INT,status VARCHAR(20)',
        'ticket_payment_attempts'=>'id BIGINT PRIMARY KEY,order_id BIGINT,status VARCHAR(20),provider VARCHAR(20),gross_amount_cop BIGINT,provider_reference VARCHAR(190),provider_transaction_id VARCHAR(190)',
        'ticket_payment_webhook_events'=>'attempt_id BIGINT,provider_transaction_id VARCHAR(190),signed_status VARCHAR(20)',
        'ticket_courtesy_invites'=>'id BIGINT PRIMARY KEY,order_id BIGINT,event_id INT,origin VARCHAR(20),recipient_digest CHAR(64),entry_id BIGINT,ticket_type_id INT,status VARCHAR(20)',
        'ticket_inventory_holds'=>'id BIGINT PRIMARY KEY,order_id BIGINT,event_id INT,ticket_type_id INT,kind VARCHAR(20),status VARCHAR(20),quantity INT,expires_at_utc DATETIME',
        'ticket_pos_sales'=>'id BIGINT PRIMARY KEY,order_id BIGINT,shift_id BIGINT,event_id INT,operator_admin_id INT,ticket_type_id INT,quantity INT,unit_price_cop BIGINT,amount_cop BIGINT,method VARCHAR(20),status VARCHAR(20),completed_at_utc DATETIME',
        'ticket_pos_shifts'=>'id BIGINT PRIMARY KEY,event_id INT,operator_admin_id INT',
        'ticket_order_notes'=>'id BIGINT PRIMARY KEY,order_id BIGINT,actor_admin_id INT,note_text VARCHAR(255)',
        'ticket_promo_redemptions'=>'order_id BIGINT,buyer_id BIGINT,event_id INT,ticket_type_id INT,quantity INT,subtotal_cop BIGINT,discount_cop BIGINT,total_cop BIGINT',
    ];
    foreach ($ddl as $table=>$cols) $db->exec("CREATE TABLE $table ($cols) ENGINE=InnoDB");
    $db->exec("INSERT INTO events VALUES(7),(8),(9)");
    $db->exec("INSERT INTO ticket_refund_event_decisions VALUES(7,'cancelled'),(8,'postponed'),(9,'cancelled')");
    $db->exec("INSERT INTO event_ticket_types VALUES(1,7),(2,8),(3,9)");
    for ($b=1;$b<=12;$b++) $db->prepare('INSERT INTO ticket_buyers VALUES (?,?,?)')
        ->execute([$b,$b===11?null:"synthetic$b@example.test",$b===6?'blocked':(in_array($b,[1,3,12],true)?'guest':'active')]);
    // id, buyer, event, order state, amount, entry state, payment evidence
    $fixtures=[
        [1,1,7,'paid',100,'issued','wompi'],[2,1,7,'paid',100,'used','wompi'],[3,2,7,'paid',100,'issued','pos'],
        [4,3,7,'draft',0,'issued','courtesy'],[5,4,7,'paid',100,null,'wompi'],[6,5,7,'pending',100,null,'pending'],
        [7,6,7,'paid',100,'issued','wompi'],[8,7,7,'refunded',100,'refunded','wompi'],[9,8,7,'cancelled',100,null,'none'],
        [10,9,7,'paid',100,'used','wompi'],[11,10,7,'paid',100,'issued','invalid'],[12,11,7,'paid',100,'issued','wompi'],
        [13,12,7,'draft',0,'issued','bad_courtesy'],[14,2,8,'paid',100,'issued','wompi'],
    ];
    $db->exec('INSERT INTO ticket_pos_shifts VALUES(1,7,42)');
    foreach ($fixtures as [$id,$buyer,$event,$status,$amount,$entry,$proof]) {
        $type=$event===7?1:2;
        $db->prepare("INSERT INTO ticket_orders VALUES(?,?,?,?,'COP',?)")->execute([$id,$event,$buyer,$status,$amount]);
        $db->prepare("INSERT INTO ticket_order_items VALUES(?,?,?,?,1,'COP',?,?)")->execute([$id,$id,$event,$type,$amount,$amount]);
        if ($entry!==null) $db->prepare('INSERT INTO ticket_entries VALUES(?,?,?,?,1,?)')
            ->execute([$id,$id,$id===10?8:$event,$id===10?2:$type,$entry]);
        if (in_array($proof,['wompi','invalid','pending'],true)) {
            $db->prepare("INSERT INTO ticket_payment_attempts VALUES(?,?,?,'wompi',?,?,?)")
                ->execute([$id,$id,$proof==='pending'?'pending':'approved',$amount,"ref-$id","tx-$id"]);
            $db->prepare('INSERT INTO ticket_payment_webhook_events VALUES(?,?,?)')
                ->execute([$id,"tx-$id",$proof==='wompi'?'APPROVED':'PENDING']);
        } elseif ($proof==='pos') {
            $db->prepare("INSERT INTO ticket_pos_sales VALUES(?,?,1,?,42,?,1,?,?,'cash','paid',UTC_TIMESTAMP())")
                ->execute([$id,$id,$event,$type,$amount,$amount]);
        } elseif (in_array($proof,['courtesy','bad_courtesy'],true)) {
            $db->prepare("INSERT INTO ticket_courtesy_invites VALUES(?,?,?,'courtesy',?,?,?,'issued')")
                ->execute([$id,$id,$event,hash('sha256',$proof==='courtesy'?"synthetic$buyer@example.test":'invalid'),$id,$type]);
            $db->prepare("INSERT INTO ticket_inventory_holds VALUES(?,?,?,?,'courtesy','consumed',1,UTC_TIMESTAMP())")
                ->execute([$id,$id,$event,$type]);
        }
    }
    $fingerprint=static function () use($db,$ddl):string {
        $data=[];foreach (array_keys($ddl) as $table) $data[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();
        return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
    };
    $before=$fingerprint(); $p=brvtalNoticePreview($db,7,'superadmin'); $c=$p['counts']; $g=$c['groups']??[];
    noticeAssert($p['state']==='known'&&$p['cause']==='cancelled'&&$c['orders_examined']===13,'authoritative incident and scope');
    noticeAssert($c['candidate_recipients']===4&&$c['review_recipients']===7&&$c['orders_without_identity']===1,'deduplicated candidate/review/exclusion counts');
    noticeAssert($g['issued']===2&&$g['used']===1&&$g['courtesy']===1&&$g['approved_without_issuance']===1,'verified Wompi/POS, draft courtesy and no issuance');
    noticeAssert($g['pending_review']===1&&$g['blocked_review']===1&&$g['terminal_review']===2&&$g['ambiguous_review']===1&&$g['unverified_review']===2,'negative proof/status groups');
    noticeAssert($p['send_authorized']===false&&!str_contains(json_encode($p),'@')&&$p===brvtalNoticePreview($db,7,'owner'),'repeatable aggregate only');
    noticeAssert(brvtalNoticePreview($db,8,'owner')['counts']['candidate_recipients']===1,'other event independent');
    noticeAssert(brvtalNoticePreview($db,9,'owner')['counts']['candidate_recipients']===0,'known empty scope');
    noticeAssert(brvtalNoticePreview($db,99,'owner')['counts']===null,'absent incident unknown');
    foreach (['admin','door',''] as $role) {
        try {brvtalNoticePreview($db,7,$role); throw new RuntimeException('unauthorized preview');}
        catch (DomainException $e) {noticeAssert($e->getMessage()==='FORBIDDEN','role gate');}
    }
    putenv('BRVTAL_INTEGRATION_TESTS=0');
    try {brvtalNoticePreview($db,7,'owner');throw new RuntimeException('sandbox bypass');}
    catch (DomainException $e) {noticeAssert($e->getMessage()==='NOTICE_SANDBOX_ONLY','production disabled');}
    finally {putenv('BRVTAL_INTEGRATION_TESTS=1');}
    noticeAssert($fingerprint()===$before&&!$db->inTransaction(),'no persisted change or transaction leak');
    // #2131: a discounted Wompi order has a gross line and a lower collected total.
    $db->exec('UPDATE ticket_order_items SET subtotal_cop=120,unit_price_cop=120 WHERE order_id=14');
    noticeAssert(brvtalNoticePreview($db,8,'owner')['counts']['groups']['ambiguous_review']===1,'discount without redemption');
    $db->exec('INSERT INTO ticket_promo_redemptions VALUES(14,2,8,2,1,120,20,100)');
    noticeAssert(brvtalNoticePreview($db,8,'owner')['counts']['candidate_recipients']===1,'verified discounted Wompi order');
    // A second unresolved Wompi attempt can still settle after a verified charge.
    $db->exec("INSERT INTO ticket_payment_attempts VALUES(15,14,'pending','wompi',120,'pending-14','tx-pending-14')");
    $db->exec("INSERT INTO ticket_payment_webhook_events VALUES(15,'tx-pending-14','PENDING')");
    $mixedPayment=brvtalNoticePreview($db,8,'owner');
    noticeAssert($mixedPayment['counts']['candidate_recipients']===0
        && $mixedPayment['counts']['groups']['ambiguous_review']===1,
        'pending mismatched attempt alongside verified approval requires review');
    $db->exec('DELETE FROM ticket_payment_webhook_events WHERE attempt_id=15');
    $db->exec('DELETE FROM ticket_payment_attempts WHERE id=15');
    $db->exec('UPDATE ticket_promo_redemptions SET buyer_id=1');
    noticeAssert(brvtalNoticePreview($db,8,'owner')['counts']['groups']['ambiguous_review']===1,'foreign redemption');
    $db->exec('UPDATE ticket_promo_redemptions SET buyer_id=2,discount_cop=19');
    noticeAssert(brvtalNoticePreview($db,8,'owner')['counts']['groups']['ambiguous_review']===1,'inconsistent redemption amount');
    $db->exec('UPDATE ticket_promo_redemptions SET discount_cop=20');
    $db->exec('INSERT INTO ticket_promo_redemptions VALUES(14,2,8,2,1,120,20,100)');
    noticeAssert(brvtalNoticePreview($db,8,'owner')['counts']['groups']['ambiguous_review']===1,'duplicate redemption');
    $db->exec('DELETE FROM ticket_promo_redemptions');
    for ($id=1000;$id<=1200;$id++) $db->exec("INSERT INTO ticket_orders VALUES($id,9,1,'pending','COP',100)");
    noticeAssert(brvtalNoticePreview($db,9,'owner')['counts']===null,'over-limit unknown, never truncated');
    $db->exec("INSERT INTO ticket_payment_webhook_events VALUES(1,'tx-1','VOIDED')");
    noticeAssert(brvtalNoticePreview($db,7,'owner')['counts']['groups']['mixed_review']===1,'contradictory signed status needs review across same identity');
    $db->exec('RENAME TABLE ticket_promo_redemptions TO promo_unavailable');
    noticeAssert(brvtalNoticePreview($db,8,'owner')['counts']===null,'missing promo ledger unknown');
    $db->exec('RENAME TABLE promo_unavailable TO ticket_promo_redemptions');
    $db->exec('DROP TABLE ticket_courtesy_invites');
    noticeAssert(brvtalNoticePreview($db,7,'owner')['counts']===null,'missing schema unknown');
} finally {$root->exec('DROP DATABASE IF EXISTS '.$name);}
echo "BRVTAL_MARKED_DATABASE_PASS: ticketing-event-notice-audience.php\n";
