<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_courtesy.php';
require_once __DIR__.'/../../config/ticketing_checkin.php';
function t25(bool $ok,string $reason):void {if(!$ok) throw new RuntimeException('T25: '.$reason);}
$input=['action'=>'issue','event_id'=>1,'ticket_type_id'=>11,'recipient'=>'guest01@example.test',
    'category'=>'staff','reason'=>'Invitación de prueba','idempotency_key'=>str_repeat('a',24)];
t25(brvtalCourtesyValidate($input)['recipient']==='guest01','input validation');
t25(!brvtalTicketingCan('admin','courtesy')&&brvtalTicketingCan('superadmin','courtesy'),'RBAC');
foreach ([['action'=>'issue'],array_merge($input,['reason'=>'x']),array_merge($input,['recipient'=>'not-an-email']),array_merge($input,['extra'=>1])] as $bad) {
    try {brvtalCourtesyValidate($bad);throw new RuntimeException('invalid input accepted');}
    catch(InvalidArgumentException) {}
}
if(getenv('BRVTAL_INTEGRATION_TESTS')!=='1'){echo "T25 offline parser/RBAC passed, DB skipped.\n";exit;}
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
t25(preg_match('/^brvtal_test[A-Za-z0-9_]*$/D',$prefix)===1,'test-only DB prefix');
$dbname=$prefix.'_courtesy_'.bin2hex(random_bytes(4));
$dsn='mysql:host='.(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1')
    .';port='.(getenv('BRVTAL_TEST_DB_PORT')?:3306).';charset=utf8mb4';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$root=new PDO($dsn,(string)getenv('BRVTAL_TEST_DB_USER'),(string)getenv('BRVTAL_TEST_DB_PASS'),$options);
$root->exec('CREATE DATABASE '.$dbname.' CHARACTER SET utf8mb4');
try {
    $db=new PDO($dsn.';dbname='.$dbname,(string)getenv('BRVTAL_TEST_DB_USER'),
        (string)getenv('BRVTAL_TEST_DB_PASS'),$options);
    $schema=[
        'CREATE TABLE events(id INT UNSIGNED PRIMARY KEY,status VARCHAR(20)) ENGINE=InnoDB',
        'CREATE TABLE admins(id INT UNSIGNED PRIMARY KEY,staff_role VARCHAR(32),is_active TINYINT) ENGINE=InnoDB',
        'CREATE TABLE event_ticket_types(id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,
            status VARCHAR(20),UNIQUE KEY uq_t03_ticket_type_event(id,event_id)) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_events(event_id INT UNSIGNED PRIMARY KEY,capacity INT UNSIGNED) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_types(ticket_type_id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,
            capacity INT UNSIGNED,courtesy_capacity INT UNSIGNED) ENGINE=InnoDB',
        'CREATE TABLE ticket_buyers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id BINARY(16),email VARCHAR(190),status VARCHAR(20)) ENGINE=InnoDB',
        "CREATE TABLE ticket_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,public_id BINARY(16),
            buyer_id BIGINT UNSIGNED,event_id INT UNSIGNED,checkout_key_hash CHAR(64),
            status VARCHAR(20),currency CHAR(3),total_cop BIGINT UNSIGNED) ENGINE=InnoDB",
        'CREATE TABLE ticket_order_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED,event_id INT UNSIGNED,ticket_type_id INT UNSIGNED,
            ticket_name_snapshot VARCHAR(120),quantity INT UNSIGNED,unit_price_cop BIGINT UNSIGNED,
            subtotal_cop BIGINT UNSIGNED,currency CHAR(3)) ENGINE=InnoDB',
        'CREATE TABLE ticket_inventory_holds(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED,event_id INT UNSIGNED,ticket_type_id INT UNSIGNED,
            quantity INT UNSIGNED,kind VARCHAR(20),status VARCHAR(20),
            expires_at_utc DATETIME(6)) ENGINE=InnoDB',
        'CREATE TABLE ticket_issue_outbox(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,state VARCHAR(20)) ENGINE=InnoDB',
        'CREATE TABLE ticket_payment_attempts(id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,
            provider VARCHAR(24),status VARCHAR(20),provider_transaction_id VARCHAR(190)) ENGINE=InnoDB',
        'CREATE TABLE ticket_payment_webhook_events(id BIGINT UNSIGNED PRIMARY KEY,
            attempt_id BIGINT UNSIGNED,provider_transaction_id VARCHAR(190),signed_status VARCHAR(20)) ENGINE=InnoDB'
    ];
    foreach($schema as $statement) $db->exec($statement);
    foreach(['migration_ticketing_entries_01.sql','migration_ticketing_courtesy_01.sql','migration_ticketing_mail_outbox_01.sql'] as $file) {
        $sql=(string)file_get_contents(__DIR__.'/../../database/'.$file);
        $sql=(string)preg_replace('/^\s*--[^\r\n]*$/m','',$sql);
        foreach(array_filter(array_map(trim(...),explode(';',$sql))) as $statement) $db->exec($statement);
    }
    $db->exec("INSERT INTO events VALUES (1,'published')");
    $db->exec("INSERT INTO admins VALUES (7,'superadmin',1),(8,'admin',1)");
    $db->exec("INSERT INTO event_ticket_types VALUES (11,1,'active'),(12,1,'active')");
    $db->exec('INSERT INTO ticket_inventory_events VALUES (1,2)');
    $db->exec('INSERT INTO ticket_inventory_types VALUES (11,1,2,2),(12,1,2,2)');
    putenv('BRVTAL_TICKET_MAIL_TESTING=1');
    $GLOBALS['config']['security']['encryption_key']=str_repeat('T',64);
    t25(brvtalCourtesyEnabled($db),'only synthetic mode enabled');
    try {brvtalCourtesyIssue($db,8,'admin',$input);throw new RuntimeException('role bypass');}
    catch(DomainException $e){t25($e->getMessage()==='FORBIDDEN','least privilege');}
    // QR never leaves the encrypted transactional outbox; no plaintext in database.
    $getQr=static function(PDO $db,int $invite): string {
        $q=$db->prepare('SELECT body_text FROM ticket_mail_outbox
            WHERE related_ref=? AND state="queued" ORDER BY id DESC LIMIT 1');
        $q->execute(['courtesy:'.$invite]);
        $sealed=(string)$q->fetchColumn();
        t25(str_starts_with($sealed,BRVTAL_BUYER_MAIL_SEAL_PREFIX)
            && !str_contains($sealed,'BRVTAL:T1:'),'only encrypted bearer persisted');
        $text=brvtalBuyerMailUnseal($sealed);
        t25(is_string($text) && preg_match('/BRVTAL:T1:[a-f0-9]{32}:[A-Za-z0-9_-]{43}/',$text,$match)===1,
            'one-use bearer included in sealed mail');
        return $match[0];
    };
    $first=brvtalCourtesyIssue($db,7,'superadmin',$input);
    t25($first['queued']===true&&$first['qr']===null&&!$first['sent'],
        'queued encrypted email without plaintext or provider');
    $firstQr=$getQr($db,$first['invite_id']);
    $replay=brvtalCourtesyIssue($db,7,'superadmin',$input);
    t25($replay['replayed']&&$replay['qr']===null,'idempotent no second secret');
    foreach ([
        ['ticket_type_id'=>12], ['ticket_type_id'=>999], ['category'=>'press'],
        ['reason'=>'Motivo sintético diferente']
    ] as $changed) {
        try {
            brvtalCourtesyIssue($db,7,'superadmin',array_merge($input,$changed));
            throw new RuntimeException('changed replay accepted');
        } catch (DomainException $error) {
            t25($error->getMessage()==='IDEMPOTENCY_CONFLICT',
                'same key and recipient must reject changed payload');
        }
    }
    try {
        brvtalCourtesyIssue($db,7,'superadmin',array_merge($input,
            ['idempotency_key'=>str_repeat('z',24)]));
        throw new RuntimeException('duplicate recipient bypass');
    } catch (DomainException $error) {
        t25($error->getMessage()==='DUPLICATE_INVITATION','same guest different request denied');
    }
    $secondInput=array_merge($input,['recipient'=>'guest02@example.test','idempotency_key'=>str_repeat('b',24)]);
    $second=brvtalCourtesyIssue($db,7,'superadmin',$secondInput);
    t25(!$second['replayed'],'second recipient');
    try {brvtalCourtesyIssue($db,7,'superadmin',array_merge($input,
        ['recipient'=>'guest03@example.test','idempotency_key'=>str_repeat('c',24)]));
        throw new RuntimeException('capacity bypass');}
    catch(DomainException $e){t25($e->getMessage()==='COURTESY_CAPACITY','global and courtesy budget');}
    t25((int)$db->query('SELECT COUNT(*) FROM ticket_courtesy_invites')->fetchColumn()===2,
        'only two invitation rows');
    t25((int)$db->query("SELECT COUNT(*) FROM ticket_orders WHERE status='paid' OR total_cop<>0")->fetchColumn()===0,
        'never paid or Wompi revenue');
    t25((int)$db->query('SELECT COUNT(*) FROM ticket_payment_attempts')->fetchColumn()===0,
        'no payment attempts');
    $received=brvtalTicketCheckinConsume($db,1,7,'door-A',$firstQr);
    t25($received['ok']===true,'same T15 scanner accepts courtesy proof');
    t25(brvtalTicketCheckinConsume($db,1,7,'door-B',$firstQr)['reason']==='already_used',
        'replay denied');
    $secondOldQr=$getQr($db,$second['invite_id']);
    $resend=brvtalCourtesyAction($db,7,'superadmin','resend',$second['invite_id']);
    t25($resend['queued']===true && $resend['sent']===false,
        'resend queues new mail without calling provider');
    $secondNewQr=$getQr($db,$second['invite_id']);
    t25($secondOldQr!==$secondNewQr,'resend rotates credential');
    t25(brvtalTicketCheckinConsume($db,1,7,'door-B',$secondOldQr)['ok']===false,
        'old resend credential revoked');
    t25((int)$db->query("SELECT COUNT(*) FROM ticket_inventory_holds WHERE kind='courtesy'
        AND status='consumed'")->fetchColumn()===2, 'resend does not consume a second seat');
    brvtalCourtesyAction($db,7,'superadmin','revoke',$second['invite_id']);
    t25(brvtalTicketCheckinConsume($db,1,7,'door-B',$secondNewQr)['ok']===false,
        'revoked new QR denied');
    t25((int)$db->query("SELECT COUNT(*) FROM ticket_inventory_holds
        WHERE kind='courtesy' AND status='released'")->fetchColumn()===1,
        'revocation releases unconsumed seat');
    t25((int)$db->query('SELECT COUNT(*) FROM ticket_checkins WHERE outcome="accepted"')->fetchColumn()===1,
        'audit exactly one admission');
    $db=null;
} finally {$root->exec('DROP DATABASE IF EXISTS '.$dbname);}
echo "T25 real courtesy: sealed QR email, RBAC, budget, idempotency, resend, revoke, zero revenue passed.\n";
