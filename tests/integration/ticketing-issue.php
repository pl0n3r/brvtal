<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_issue.php';
require_once __DIR__ . '/../../config/ticketing_wompi_webhook.php';
require_once __DIR__ . '/../../config/migration_reconcile.php';

function t09(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('T09: '.$message);
}
function t09Reject(callable $fn, string $message): void {
    try { $fn(); } catch (DomainException $error) {
        t09($error->getMessage() === $message,'wrong fail-closed code for '.$message); return;
    }
    throw new RuntimeException('T09: did not reject '.$message);
}
// The only actual DB writes below target an ephemeral, synthetic MariaDB schema.
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "T09 database integration skipped without ephemeral CI fixture.\n";
    exit(0);
}
$prefix=(string)(getenv('BRVTAL_TEST_DB_NAME')?:'');
t09((bool)preg_match('/^brvtal_test[A-Za-z0-9_]*$/D',$prefix),'test namespace only');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');
$server=new PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$dbname=$prefix.'_issue_'.bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE '.$dbname.' CHARACTER SET utf8mb4');
register_shutdown_function(static function() use($server,$dbname):void {
    try {$server->exec('DROP DATABASE IF EXISTS '.$dbname);} catch(Throwable) {}
});
$db=new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",$user,$pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
     PDO::ATTR_EMULATE_PREPARES=>false]);
$fixture=<<<'SQL'
CREATE TABLE events(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE event_ticket_types(id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL) ENGINE=InnoDB;
CREATE TABLE admins(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE ticket_orders(id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL,
  buyer_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB;
CREATE TABLE ticket_order_items(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,ticket_type_id INT UNSIGNED NOT NULL,quantity INT UNSIGNED NOT NULL) ENGINE=InnoDB;
CREATE TABLE ticket_payment_attempts(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(24) NOT NULL,provider_transaction_id VARCHAR(190) NULL,
  status VARCHAR(20) NOT NULL) ENGINE=InnoDB;
SQL;
foreach (explode(';',$fixture) as $ddl) if(trim($ddl)!=='') $db->exec($ddl);
foreach (['entries','webhook'] as $name) {
    $sql=file_get_contents(__DIR__.'/../../database/migration_ticketing_'.$name.'_01.sql');
    t09(is_string($sql) && strlen($sql)>100,'T04/T08 migration present');
    $sql=(string)preg_replace('/^\h*--[^\r\n]*$/m','',$sql);
    foreach(explode(';',$sql) as $ddl) if(trim($ddl)!=='') $db->exec($ddl);
}
$db->exec('INSERT INTO events(id) VALUES (1)');
$db->exec('INSERT INTO admins(id) VALUES (7)');
$db->exec('INSERT INTO event_ticket_types(id,event_id) VALUES (11,1),(12,1)');
$db->exec("INSERT INTO ticket_orders(id,event_id,buyer_id,status)
    VALUES (100,1,8,'paid'),(101,1,8,'pending'),(102,1,8,'refunded'),(103,1,8,'paid')");
$db->exec('INSERT INTO ticket_order_items(id,order_id,event_id,ticket_type_id,quantity)
    VALUES (200,100,1,11,2),(201,100,1,12,1),(202,101,1,11,1),
           (203,102,1,11,1),(204,103,1,11,1)');
$db->exec("INSERT INTO ticket_payment_attempts(id,order_id,provider,provider_transaction_id,status)
    VALUES (1,100,'wompi','t09-approved','approved'),
           (2,101,'wompi','t09-pending','pending'),
           (3,102,'wompi','t09-refunded','refunded'),
           (4,103,'wompi',NULL,'approved')");
$journal=$db->prepare('INSERT INTO ticket_payment_webhook_events
    (attempt_id,provider_transaction_id,event_checksum,signed_status,event_timestamp)
    VALUES (?,?,?,?,?)');
foreach ([[1,'t09-approved','APPROVED'],[2,'t09-pending','PENDING'],
          [3,'t09-refunded','APPROVED']] as [$id,$tx,$state]) {
    $journal->execute([$id,$tx,hash('sha256','synthetic-'.$id),$state,time()]);
}
// T08's verified webhook does not issue tickets. Its unique durable outbox
// is replayed ten times to simulate ten authenticated notification retries.
for ($n=0;$n<10;$n++) {
    $db->beginTransaction();
    brvtal_wompi_hook_enqueue($db,100,1);
    $db->commit();
}
t09((int)$db->query('SELECT COUNT(*) FROM ticket_issue_outbox')->fetchColumn()===1,
    '10 duplicate webhooks -> 1 outbox row');
$jobId=(int)$db->query('SELECT id FROM ticket_issue_outbox WHERE order_id=100')->fetchColumn();
$issued=brvtal_ticket_issue_consume($db,$jobId);
t09($issued['state']==='issued' && count($issued['tickets'])===3,
    '2+1 units -> exactly three issued QR credentials');
$raws=array_column($issued['tickets'],'token');
$ids=array_column($issued['tickets'],'public_id');
t09(count(array_unique($raws))===3 && count(array_unique($ids))===3,
    'CSPRNG creates different public IDs and QR secrets');
t09((int)$db->query('SELECT COUNT(*) FROM ticket_entries')->fetchColumn()===3
    && (int)$db->query('SELECT COUNT(*) FROM ticket_entry_credentials')->fetchColumn()===3,
    'no missing or surplus tickets');
$read=$db->query('SELECT public_id FROM ticket_entries ORDER BY order_item_id,unit_number')
    ->fetchAll(PDO::FETCH_COLUMN);
t09(bin2hex($read[0])===$ids[0],'opaque identifier persisted exactly');
$stored=$db->query('SELECT token_hash FROM ticket_entry_credentials ORDER BY id')
    ->fetchAll(PDO::FETCH_COLUMN);
foreach ($raws as $n=>$value) {
    $secret=base64_decode(strtr($value,'-_','+/').'=',true);
    t09(strlen($secret)===32 && hash_equals($stored[$n],hash('sha256',$secret,true))
        && !hash_equals($stored[$n],$secret),'only binary digest stored');
}
for ($n=0;$n<10;$n++) {
    $again=brvtal_ticket_issue_consume($db,$jobId);
    t09($again['state']==='duplicate' && $again['tickets']===[],
        'retry cannot reveal/replace a previously issued credential');
}
t09((int)$db->query('SELECT COUNT(*) FROM ticket_entries')->fetchColumn()===3,
    'ten consumer retries never duplicate tickets');
t09($db->query("SELECT state FROM ticket_issue_outbox WHERE order_id=100")->fetchColumn()==='done',
    'atomic completion recorded in outbox');
$append=$db->prepare('INSERT INTO ticket_issue_outbox(order_id,payment_attempt_id) VALUES (?,?)');
foreach ([[101,2],[102,3],[103,4]] as [$order,$attempt]) $append->execute([$order,$attempt]);
foreach ([[101,'ISSUE_NOT_PAID'],[102,'ISSUE_NOT_PAID'],[103,'ISSUE_UNVERIFIED_PAYMENT']] as [$order,$error]) {
    $job=$db->query("SELECT id FROM ticket_issue_outbox WHERE order_id={$order}")->fetchColumn();
    t09Reject(fn()=>brvtal_ticket_issue_consume($db,(int)$job),$error);
}
t09((int)$db->query('SELECT COUNT(*) FROM ticket_entries')->fetchColumn()===3,
    'unpaid, refunded and unverified attempts never emit');
// Simulate an interrupted legacy issuance in a synthetic order. The T09
// consumer must refuse to mint any further secrets or mark the outbox done;
// ten retries cannot hide or multiply a partial persisted entry.
$db->exec("INSERT INTO ticket_orders(id,event_id,buyer_id,status) VALUES (104,1,8,'paid')");
$db->exec("INSERT INTO ticket_order_items(id,order_id,event_id,ticket_type_id,quantity)
    VALUES (205,104,1,11,2)");
$db->exec("INSERT INTO ticket_payment_attempts(id,order_id,provider,provider_transaction_id,status)
    VALUES (5,104,'wompi','t09-partial','approved')");
$journal->execute([5,'t09-partial',hash('sha256','synthetic-5'),'APPROVED',time()]);
$append->execute([104,5]);
$db->prepare("INSERT INTO ticket_entries
    (public_id,order_item_id,event_id,ticket_type_id,unit_number,issued_at_utc)
    VALUES (UNHEX(REPEAT('a',32)),205,1,11,1,UTC_TIMESTAMP(6))")->execute();
$partialJob=(int)$db->query("SELECT id FROM ticket_issue_outbox WHERE order_id=104")->fetchColumn();
for ($n=0;$n<10;$n++) {
    t09Reject(fn()=>brvtal_ticket_issue_consume($db,$partialJob),'ISSUE_PARTIAL');
}
t09((int)$db->query('SELECT COUNT(*) FROM ticket_entries')->fetchColumn()===4
    && (int)$db->query('SELECT COUNT(*) FROM ticket_entry_credentials')->fetchColumn()===3,
    'partial order never creates a second entry or orphan credential');
t09($db->query("SELECT state FROM ticket_issue_outbox WHERE order_id=104")->fetchColumn()==='pending',
    'partial outbox remains unreconciled and fail closed');
$future=new DateTimeImmutable('+1 day',new DateTimeZone('UTC'));
$past=new DateTimeImmutable('-1 day',new DateTimeZone('UTC'));
$token=$raws[0];$public=$ids[0];
$payload=brvtal_ticket_issue_qr_payload($db,8,$public,$token,$future);
t09($payload==='BRVTAL:T1:'.$public.':'.$token,'same credential can render again');
$db->exec("UPDATE ticket_issue_outbox SET state='pending' WHERE order_id=100");
t09Reject(fn()=>brvtal_ticket_issue_qr_payload($db,8,$public,$token,$future),'TICKET_UNAVAILABLE');
$db->exec("UPDATE ticket_issue_outbox SET state='done' WHERE order_id=100");
t09((bool)preg_match('/^BRVTAL:T1:[a-f0-9]{32}:[A-Za-z0-9_-]{43}$/D',$payload)
    && !str_contains($payload,'@'),'QR is opaque without buyer PII');
$download=brvtal_ticket_issue_download($db,8,$public,$token,$future);
t09($download['content']===$payload && $download['cache_control']==='private, no-store',
    'authorized download is private and regenerates the same token');
t09Reject(fn()=>brvtal_ticket_issue_qr_payload($db,9,$public,$token,$future),'TICKET_UNAVAILABLE');
t09Reject(fn()=>brvtal_ticket_issue_qr_payload($db,8,$public,'invalid',$future),'TICKET_UNAVAILABLE');
t09Reject(fn()=>brvtal_ticket_issue_qr_payload($db,8,$public,str_repeat('a',43),$future),'TICKET_UNAVAILABLE');
t09Reject(fn()=>brvtal_ticket_issue_qr_payload($db,8,$public,$token,$past),'TICKET_UNAVAILABLE');
$db->prepare("UPDATE ticket_entry_credentials SET status='revoked',revoked_at_utc=UTC_TIMESTAMP(6)
    WHERE token_hash=?")->execute([hash('sha256',base64_decode(strtr($token,'-_','+/').'=',true),true)]);
t09Reject(fn()=>brvtal_ticket_issue_qr_payload($db,8,$public,$token,$future),'TICKET_UNAVAILABLE');
$db->exec("UPDATE ticket_orders SET status='refunded' WHERE id=100");
t09Reject(fn()=>brvtal_ticket_issue_qr_payload($db,8,$ids[1],$raws[1],$future),'TICKET_UNAVAILABLE');

/** #1995: disposable MariaDB ensures the POS worker never mints Wompi evidence. */
$db->exec("ALTER TABLE ticket_orders
    ADD COLUMN total_cop BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'COP'");
$db->exec("ALTER TABLE ticket_order_items
    ADD COLUMN unit_price_cop BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN subtotal_cop BIGINT UNSIGNED NOT NULL DEFAULT 0");
$db->exec("CREATE TABLE ticket_pos_shifts (id BIGINT UNSIGNED PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,operator_admin_id INT UNSIGNED NOT NULL) ENGINE=InnoDB");
$db->exec("CREATE TABLE ticket_pos_sales (id BIGINT UNSIGNED PRIMARY KEY,
    shift_id BIGINT UNSIGNED NOT NULL,event_id INT UNSIGNED NOT NULL,
    operator_admin_id INT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NULL,
    ticket_type_id INT UNSIGNED NOT NULL,quantity INT UNSIGNED NOT NULL,
    unit_price_cop BIGINT UNSIGNED NOT NULL,amount_cop BIGINT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL,method VARCHAR(24) NOT NULL,
    completed_at_utc DATETIME(6) NULL) ENGINE=InnoDB");
$posProofBefore=brvtalMigrationSchemaProof($db,'migration_ticketing_pos_02.sql');
t09(brvtalMigrationProofIsFullyAbsent($posProofBefore),
    'all POS handoff proof checks must be absent before the compatibility ALTER');
$posDdl=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_pos_02.sql');
t09(str_contains($posDdl,'payment_attempt_id BIGINT UNSIGNED NULL'),
    'POS migration must preserve Wompi while allowing a NULL attempt');
$posDdl=(string)preg_replace('/^\h*--[^\r\n]*$/m','',$posDdl);
foreach(explode(';',$posDdl) as $statement) if(trim($statement)!=='') $db->exec($statement);
$posProofAfter=brvtalMigrationSchemaProof($db,'migration_ticketing_pos_02.sql');
t09(($posProofAfter['complete']??false)===true,
    'post-migration MariaDB must prove nullable unsigned FK and preserved POS method');
// A matching target/column with ON DELETE CASCADE is NOT our immutable ledger
// proof: deletion of a Wompi attempt must never cascade into issuance evidence.
$db->exec('ALTER TABLE ticket_issue_outbox
    DROP FOREIGN KEY fk_ticket_issue_outbox_pos_attempt,
    ADD CONSTRAINT fk_ticket_issue_outbox_pos_attempt FOREIGN KEY (payment_attempt_id)
    REFERENCES ticket_payment_attempts(id) ON DELETE CASCADE');
$cascadeProof=brvtalMigrationSchemaProof($db,'migration_ticketing_pos_02.sql');
$fkRule='fk-reference:ticket_issue_outbox.fk_ticket_issue_outbox_pos_attempt=payment_attempt_id:ticket_payment_attempts.id';
t09(($cascadeProof['complete']??true)===false
    && ($cascadeProof['checks'][$fkRule]??true)===false,
    'CASCADE may not be accepted as a valid POS Wompi evidence baseline');
$db->exec('ALTER TABLE ticket_issue_outbox
    DROP FOREIGN KEY fk_ticket_issue_outbox_pos_attempt,
    ADD CONSTRAINT fk_ticket_issue_outbox_pos_attempt FOREIGN KEY (payment_attempt_id)
    REFERENCES ticket_payment_attempts(id) ON DELETE RESTRICT');
t09((brvtalMigrationSchemaProof($db,'migration_ticketing_pos_02.sql')['complete']??false)===true,
    'restoring RESTRICT must restore the exact POS FK proof');
$db->exec("INSERT INTO ticket_orders (id,event_id,buyer_id,status,total_cop,currency)
    VALUES (105,1,8,'paid',5000,'COP'),(106,1,8,'paid',5000,'COP')");
$db->exec("INSERT INTO ticket_order_items
    (id,order_id,event_id,ticket_type_id,quantity,unit_price_cop,subtotal_cop)
    VALUES (206,105,1,11,1,5000,5000),(207,106,1,11,1,5000,5000)");
$db->exec("INSERT INTO ticket_pos_shifts(id,event_id,operator_admin_id) VALUES(1,1,7)");
$db->exec("INSERT INTO ticket_pos_sales
    (id,shift_id,event_id,operator_admin_id,order_id,ticket_type_id,quantity,
     unit_price_cop,amount_cop,status,method,completed_at_utc)
    VALUES (1,1,1,7,105,11,1,5000,5000,'paid','cash',UTC_TIMESTAMP(6))");
$db->beginTransaction();
brvtal_ticket_pos_enqueue($db,105);
brvtal_ticket_pos_enqueue($db,105);
$db->commit();
t09((int)$db->query('SELECT COUNT(*) FROM ticket_issue_outbox WHERE order_id=105')
    ->fetchColumn()===1,'POS enqueued exactly once');
t09($db->query('SELECT payment_attempt_id FROM ticket_issue_outbox WHERE order_id=105')
    ->fetchColumn()===null,'POS outbox never fabricates a Wompi attempt');
$job=(int)$db->query('SELECT id FROM ticket_issue_outbox WHERE order_id=105')
    ->fetchColumn();
// Delivery is part of the same transaction. Missing email or a rejected
// privacy gate must roll back minted secrets and preserve the pending POS job.
foreach (['ISSUE_DELIVERY_RECIPIENT','MAIL_PRIVACY_NOT_APPROVED'] as $deliveryError) {
    $rejectDelivery = static function (PDO $connection, int $orderId, array $tickets) use ($deliveryError): void {
        throw new DomainException($deliveryError);
    };
    t09Reject(fn()=>brvtal_ticket_issue_consume($db,$job,$rejectDelivery), $deliveryError);
    t09((int)$db->query('SELECT COUNT(*) FROM ticket_entries e
        JOIN ticket_order_items i ON i.id=e.order_item_id
        WHERE i.order_id=105')->fetchColumn()===0,
        'delivery failure rolls back QR entries and hashes');
    t09($db->query('SELECT state FROM ticket_issue_outbox WHERE order_id=105')->fetchColumn()==='pending',
        'delivery failure leaves trusted POS work pending');
}
$result=brvtal_ticket_issue_consume($db,$job);
t09($result['state']==='issued' && count($result['tickets'])===1,
    'trusted POS payment issues exactly one ticket with the same worker');
$ticket=$result['tickets'][0];
t09(brvtal_ticket_issue_consume($db,$job)['state']==='duplicate',
    'POS worker replay never duplicates a credential');
t09(brvtal_ticket_issue_qr_payload($db,8,$ticket['public_id'],$ticket['token'],$future)
    ==='BRVTAL:T1:'.$ticket['public_id'].':'.$ticket['token'],
    'POS QR follows existing private buyer authentication');
$db->beginTransaction();
t09Reject(fn()=>brvtal_ticket_pos_enqueue($db,106),'POS_ISSUE_UNVERIFIED');
$db->rollBack();
t09((int)$db->query('SELECT COUNT(*) FROM ticket_issue_outbox WHERE order_id=106')
    ->fetchColumn()===0,'unverified POS may not enqueue');
$db->exec("INSERT INTO ticket_issue_outbox(order_id,payment_attempt_id) VALUES(106,NULL)");
$fake=(int)$db->query('SELECT id FROM ticket_issue_outbox WHERE order_id=106')
    ->fetchColumn();
t09Reject(fn()=>brvtal_ticket_issue_consume($db,$fake),'ISSUE_UNVERIFIED_PAYMENT');
$db->exec("UPDATE ticket_orders SET status='refunded' WHERE id=105");
t09Reject(fn()=>brvtal_ticket_issue_qr_payload($db,8,$ticket['public_id'],
    $ticket['token'],$future),'TICKET_UNAVAILABLE');
echo "T09 + #1995 POS worker, QR, idempotency and forged evidence rejected.\n";

