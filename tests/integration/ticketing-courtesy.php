<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_courtesy.php';
require_once __DIR__.'/../../config/ticketing_checkin.php';
function t25(bool $ok,string $reason):void {if(!$ok) throw new RuntimeException('T25: '.$reason);}
$input=['action'=>'issue','event_id'=>1,'ticket_type_id'=>11,'recipient'=>'guest01@example.test',
    'category'=>'staff','reason'=>'Invitación de prueba','idempotency_key'=>str_repeat('a',24)];
t25(brvtalCourtesyValidate($input)['recipient']==='guest01@example.test','input validation');
t25(!brvtalTicketingCan('admin','courtesy')&&brvtalTicketingCan('superadmin','courtesy'),'RBAC');
foreach ([['action'=>'issue'],array_merge($input,['reason'=>'x']),array_merge($input,['recipient'=>'not-an-email']),array_merge($input,['extra'=>1])] as $bad) {
    try {brvtalCourtesyValidate($bad);throw new RuntimeException('invalid input accepted');}
    catch(InvalidArgumentException) {}
}
// Deterministic offline lifecycle and timezone tests.
$clock=new DateTimeImmutable('2026-11-26 15:00:00',new DateTimeZone('America/Bogota'));
$dates=['event_date'=>'2026-11-27 21:00:00','published_at'=>'2026-11-25 10:00:00'];
foreach (['published','upcoming','tickets_available','last_tickets','sold_out'] as $status)
    t25(brvtalCourtesyEventEligible(['status'=>$status]+$dates,$clock),'eligible '.$status);
foreach (['active','draft','cancelled','finished','archived'] as $status)
    t25(!brvtalCourtesyEventEligible(['status'=>$status]+$dates,$clock),'rejected '.$status);
foreach ([
    ['event_date'=>'2026-11-25 21:00:00'],
    ['event_date'=>'2026-11-26 14:59:59'],
    ['event_date'=>''],['event_date'=>'invalid-date'],
    ['published_at'=>'2026-11-27 10:00:00'],
    ['published_at'=>'invalid-date']
] as $boundary)
    t25(!brvtalCourtesyEventEligible(['status'=>'published']+array_replace($dates,$boundary),$clock),
        'date or publication must fail closed');
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
        'CREATE TABLE events(id INT UNSIGNED PRIMARY KEY,status VARCHAR(20),
            event_date DATETIME NULL,published_at DATETIME NULL) ENGINE=InnoDB',
        'CREATE TABLE admins(id INT UNSIGNED PRIMARY KEY,staff_role VARCHAR(32),is_active TINYINT) ENGINE=InnoDB',
        'CREATE TABLE ticket_refund_event_decisions(event_id INT UNSIGNED PRIMARY KEY,cause VARCHAR(20) NOT NULL) ENGINE=InnoDB',
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
    foreach(['migration_ticketing_entries_01.sql','migration_ticket_entry_secret_01.sql','migration_ticketing_courtesy_01.sql','migration_ticketing_mail_outbox_01.sql'] as $file) {
        $sql=(string)file_get_contents(__DIR__.'/../../database/'.$file);
        $sql=(string)preg_replace('/^\s*--[^\r\n]*$/m','',$sql);
        foreach(array_filter(array_map(trim(...),explode(';',$sql))) as $statement) $db->exec($statement);
    }
    $now=new DateTimeImmutable('now',new DateTimeZone('America/Bogota'));
    $future=$now->modify('+2 days')->format('Y-m-d H:i:s');
    $doorClock=new DateTimeImmutable($future,new DateTimeZone('America/Bogota'));
    $published=$now->modify('-1 day')->format('Y-m-d H:i:s');
    $addEvent=$db->prepare('INSERT INTO events VALUES (?,?,?,?)');
    $addEvent->execute([1,'published',$future,$published]);
    $db->exec("INSERT INTO admins VALUES (7,'superadmin',1),(8,'admin',1)");
    $db->exec("INSERT INTO event_ticket_types VALUES (11,1,'active'),(12,1,'active')");
    $db->exec('INSERT INTO ticket_inventory_events VALUES (1,2)');
    $db->exec('INSERT INTO ticket_inventory_types VALUES (11,1,2,2),(12,1,2,2)');
    putenv('BRVTAL_TICKET_MAIL_TESTING=1');
    $GLOBALS['config']['security']['encryption_key']=str_repeat('T',64);
    t25(brvtalCourtesyEnabled($db),'only synthetic mode enabled');
    try {brvtalCourtesyIssue($db,8,'admin',$input);throw new RuntimeException('role bypass');}
    catch(DomainException $e){t25($e->getMessage()==='FORBIDDEN','least privilege');}
    // The transactional outbox and stored credential must both remain encrypted.
    $getQr=static function(PDO $db,int $invite): string {
        $q=$db->prepare('SELECT body_text FROM ticket_mail_outbox
            WHERE related_ref=? AND state="queued" ORDER BY id DESC LIMIT 1');
        $q->execute(['courtesy:'.$invite]);
        $sealed=(string)$q->fetchColumn();
        t25(str_starts_with($sealed,BRVTAL_BUYER_MAIL_SEAL_PREFIX)
            && !str_contains($sealed,'BRVTAL:T1:'),'only encrypted bearer persisted');
        $text=brvtalBuyerMailUnseal($sealed);
        $match=[];
        t25(is_string($text) && preg_match('/BRVTAL:T1:[a-f0-9]{32}:[A-Za-z0-9_-]{43}/',$text,$match)===1,
            'one-use bearer included in sealed mail');
        return (string)($match[0] ?? '');
    };
    $activeCredential=static function(PDO $db,int $invite): array {
        $q=$db->prepare("SELECT e.public_id,c.entry_id,c.version,c.token_hash,c.token_encrypted
            FROM ticket_courtesy_invites i JOIN ticket_entries e ON e.id=i.entry_id
            JOIN ticket_entry_credentials c ON c.entry_id=e.id AND c.status='active' WHERE i.id=?");
        $q->execute([$invite]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        t25(is_array($row),'active courtesy credential exists');
        return $row;
    };
    $assertStored=static function(array $row,string $qr): void {
        $sealed=$row['token_encrypted'];
        t25(is_string($sealed) && strlen($sealed)===61,'authenticated ciphertext stored');
        $secret=brvtal_ticket_issue_open_token($sealed,$row['public_id']);
        t25(!str_contains($sealed,$secret) && hash_equals($row['token_hash'],hash('sha256',$secret,true)),
            'ciphertext recovers exactly the hashed bearer without storing plaintext');
        t25($qr==='BRVTAL:T1:'.bin2hex($row['public_id']).':'.rtrim(strtr(base64_encode($secret),'+/','-_'),'='),
            'stored credential matches the original mail QR');
    };
    $first=brvtalCourtesyIssue($db,7,'superadmin',$input);
    t25($first['queued']===true&&$first['qr']===null&&!$first['sent'],
        'queued encrypted email without plaintext or provider');
    $firstQr=$getQr($db,$first['invite_id']);
    $assertStored($activeCredential($db,$first['invite_id']),$firstQr);
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
    $received=brvtalTicketCheckinConsume($db,1,7,'door-A',$firstQr,$doorClock);
    t25($received['ok']===true,'same T15 scanner accepts courtesy proof');
    t25(brvtalTicketCheckinConsume($db,1,7,'door-B',$firstQr,$doorClock)['reason']==='already_used',
        'replay denied');
    $secondOldQr=$getQr($db,$second['invite_id']);
    $oldCredential=$activeCredential($db,$second['invite_id']);
    $assertStored($oldCredential,$secondOldQr);
    // Simulate a historical hash-only credential: NULL is the existing non-recoverable marker.
    $db->prepare('UPDATE ticket_entry_credentials SET token_encrypted=NULL WHERE entry_id=? AND version=?')
        ->execute([$oldCredential['entry_id'],$oldCredential['version']]);
    t25($activeCredential($db,$second['invite_id'])['token_encrypted']===null,
        'historical courtesy remains non-recoverable without inventing a secret');
    $resend=brvtalCourtesyAction($db,7,'superadmin','resend',$second['invite_id']);
    t25($resend['queued']===true && $resend['sent']===false,
        'resend queues new mail without calling provider');
    $secondNewQr=$getQr($db,$second['invite_id']);
    t25($secondOldQr!==$secondNewQr,'resend rotates credential');
    $newCredential=$activeCredential($db,$second['invite_id']);
    $assertStored($newCredential,$secondNewQr);
    t25((int)$newCredential['version']===(int)$oldCredential['version']+1
        && !hash_equals($oldCredential['token_hash'],$newCredential['token_hash']),
        'rotation preserves audit version and replaces hash');
    $q=$db->prepare('SELECT status,token_encrypted FROM ticket_entry_credentials WHERE entry_id=? AND version=?');
    $q->execute([$oldCredential['entry_id'],$oldCredential['version']]);
    $legacy=$q->fetch(PDO::FETCH_ASSOC);
    t25(is_array($legacy) && $legacy['status']==='revoked' && $legacy['token_encrypted']===null,
        'legacy revoked row stays hash-only; no fabricated recovery');
    t25(brvtalTicketCheckinConsume($db,1,7,'door-B',$secondOldQr,$doorClock)['ok']===false,
        'old resend credential revoked');
    t25((int)$db->query("SELECT COUNT(*) FROM ticket_inventory_holds WHERE kind='courtesy'
        AND status='consumed'")->fetchColumn()===2, 'resend does not consume a second seat');
    brvtalCourtesyAction($db,7,'superadmin','revoke',$second['invite_id']);
    t25(brvtalTicketCheckinConsume($db,1,7,'door-B',$secondNewQr,$doorClock)['ok']===false,
        'revoked new QR denied');
    t25((int)$db->query("SELECT COUNT(*) FROM ticket_inventory_holds
        WHERE kind='courtesy' AND status='released'")->fetchColumn()===1,
        'revocation releases unconsumed seat');
    t25((int)$db->query('SELECT COUNT(*) FROM ticket_checkins WHERE outcome="accepted"')->fetchColumn()===1,
        'audit exactly one admission');
    // Dedicated MariaDB fixtures for all canonical active Event states.
    $addType=$db->prepare("INSERT INTO event_ticket_types VALUES (?,?,'active')");
    $addOverall=$db->prepare('INSERT INTO ticket_inventory_events VALUES (?,?)');
    $addTypeCap=$db->prepare('INSERT INTO ticket_inventory_types VALUES (?,?,?,?)');
    $seed=static function(int $id,string $status,?string $date,string $pub,
        int $overall,int $type,int $courtesy) use ($addEvent,$addType,$addOverall,$addTypeCap): void {
        $addEvent->execute([$id,$status,$date,$pub]);
        $addType->execute([$id+20,$id]);
        $addOverall->execute([$id,$overall]);
        $addTypeCap->execute([$id+20,$id,$type,$courtesy]);
    };
    $make=static fn(int $id,int $n=0):array=>[
        'action'=>'issue','event_id'=>$id,'ticket_type_id'=>$id+20,
        'recipient'=>"guest-{$id}-{$n}@example.test",'category'=>'staff',
        'reason'=>'Invitacion sintetica de control',
        'idempotency_key'=>str_repeat(chr(65+$id+$n),24)
    ];
    foreach ([
        [2,'tickets_available',2,2,1], // courtesy cap
        [3,'last_tickets',2,1,2],      // ticket type cap
        [4,'sold_out',1,2,2],          // physical Event cap
        [5,'published',2,2,2],
        [6,'upcoming',2,2,2]
    ] as [$id,$status,$overall,$type,$courtesy]) {
        $seed($id,$status,$future,$published,$overall,$type,$courtesy);
        $inputCase=$make($id);
        $accepted=brvtalCourtesyIssue($db,7,'superadmin',$inputCase);
        t25($accepted['queued'] && !$accepted['replayed'],'MariaDB Event '.$status);
        $again=brvtalCourtesyIssue($db,7,'superadmin',$inputCase);
        t25($again['replayed']&&$again['invite_id']===$accepted['invite_id'],'idempotent retry');
        if ($id<=4) {
            try {
                brvtalCourtesyIssue($db,7,'superadmin',$make($id,1));
                throw new RuntimeException('quota bypass');
            } catch (DomainException $e) {
                t25($e->getMessage()==='COURTESY_CAPACITY','all quotas enforced');
            }
            $check=$db->prepare('SELECT COUNT(*) FROM ticket_courtesy_invites WHERE event_id=?');
            $check->execute([$id]);
            t25((int)$check->fetchColumn()===1,'no second invitation on overbooking');
        }
    }
    foreach ([
        [7,'cancelled',$future,$published],
        [8,'archived',$future,$published],
        [9,'finished',$future,$published],
        [10,'active',$future,$published],
        [11,'draft',$future,$published],
        [12,'published',$now->modify('-1 day')->format('Y-m-d H:i:s'),$published],
        [13,'published',$now->modify('-1 minute')->format('Y-m-d H:i:s'),$published],
        [14,'published',null,$published],
        [15,'published',$future,$now->modify('+1 day')->format('Y-m-d H:i:s')]
    ] as [$id,$status,$date,$publication]) {
        $seed($id,$status,$date,$publication,2,2,2);
        try {
            brvtalCourtesyIssue($db,7,'superadmin',$make($id));
            throw new RuntimeException('invalid Event accepted');
        } catch (DomainException $e) {
            t25($e->getMessage()==='EVENT_CLOSED','past or inactive Event denied');
        }
    }
    t25((int)$db->query('SELECT COUNT(*) FROM ticket_courtesy_invites WHERE event_id BETWEEN 7 AND 15')
        ->fetchColumn()===0,'rejected Event never emits courtesy');
    // A second PDO connection cannot reserve during the held Event mutex.
    $seed(20,'tickets_available',$future,$published,1,1,1);
    $db2=new PDO($dsn.';dbname='.$dbname,(string)getenv('BRVTAL_TEST_DB_USER'),
        (string)getenv('BRVTAL_TEST_DB_PASS'),$options);
    $db2->exec('SET SESSION innodb_lock_wait_timeout=1');
    $db->beginTransaction();
    try {
        $lock=$db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        $lock->execute([20]);
        try {
            brvtalCourtesyIssue($db2,7,'superadmin',$make(20));
            throw new RuntimeException('mutex bypass');
        } catch (RuntimeException $e) {
            t25($e->getMessage()==='COURTESY_STORAGE_UNAVAILABLE','second connection blocked');
        }
        t25((int)$db->query('SELECT COUNT(*) FROM ticket_courtesy_invites WHERE event_id=20')
            ->fetchColumn()===0,'no write on lock timeout');
    } finally { if ($db->inTransaction()) $db->commit(); }
    t25(brvtalCourtesyIssue($db2,7,'superadmin',$make(20))['queued'],'retry after mutex release');
    try {
        brvtalCourtesyIssue($db,7,'superadmin',$make(20,1));
        throw new RuntimeException('capacity bypass after mutex');
    } catch (DomainException $e) {
        t25($e->getMessage()==='COURTESY_CAPACITY','committed seat is counted');
    }
    $db2=null;
    $db=null;
} finally {$root->exec('DROP DATABASE IF EXISTS '.$dbname);}
echo "T25 real courtesy: sealed QR email, RBAC, budget, idempotency, resend, revoke, zero revenue passed.\n";
