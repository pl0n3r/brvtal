<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_checkin.php';
require_once __DIR__ . '/../../config/ticketing_staff_permissions.php';
function t15(bool $ok,string $why):void {if (!$ok) throw new RuntimeException('T15: '.$why);}
function t15Qr(string $id,string $secret):string {
    return 'BRVTAL:T1:'.$id.':'.rtrim(strtr(base64_encode($secret),'+/','-_'),'=');
}
function t15Conn(string $db):PDO {
    return new PDO('mysql:host='.getenv('BRVTAL_TEST_DB_HOST').';port='.(getenv('BRVTAL_TEST_DB_PORT')?:3306)
        .';dbname='.$db.';charset=utf8mb4',(string)getenv('BRVTAL_TEST_DB_USER'),
        (string)getenv('BRVTAL_TEST_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function t15Entry(PDO $pdo,int $id,int $unit):array {
    $opaque=random_bytes(16); $secret=random_bytes(32);
    $pdo->prepare('INSERT INTO ticket_entries (id,public_id,order_item_id,event_id,ticket_type_id,unit_number,issued_at_utc)
        VALUES (?,?,1,1,11,?,UTC_TIMESTAMP(6))')->execute([$id,$opaque,$unit]);
    $pdo->prepare('INSERT INTO ticket_entry_credentials (entry_id,version,token_hash,created_at_utc)
        VALUES (?,1,?,UTC_TIMESTAMP(6))')->execute([$id,hash('sha256',$secret,true)]);
    return [bin2hex($opaque),t15Qr(bin2hex($opaque),$secret)];
}
// In fast CI: parser, role, CSRF usage, canonical encoding and rate budget.
t15(brvtalTicketingCan('door','door')&&!brvtalTicketingCan('door','orders')
    &&!brvtalTicketingCan('unknown','door'),'door least privilege');
$api=(string)file_get_contents(__DIR__.'/../../api/ticketing/checkin.php');
t15(str_contains($api,'brvtal_admin_require_csrf()')&&str_contains($api,'brvtalTicketingCan')
    &&str_contains($api,'brvtal_admin_require()'),'HTTP auth and CSRF');
t15(brvtalTicketCheckinQr(t15Qr(bin2hex(random_bytes(16)),random_bytes(32)))!==null,'canonical QR');
t15(brvtalTicketCheckinQr('BRVTAL:T1:'.str_repeat('a',32).':'.str_repeat('x',44))===null,'malformed QR');
t15(brvtalTicketCheckinInput(['qr'=>'bad','device_id'=>'door-1','event_id'=>1])['event_id']===1,'field order');
foreach ([['event_id'=>0,'device_id'=>'d','qr'=>'x'],['event_id'=>1,'device_id'=>'../../etc','qr'=>'x'],
    ['event_id'=>1,'device_id'=>'d','qr'=>[]]] as $bad) {
    try {brvtalTicketCheckinInput($bad);throw new RuntimeException('invalid accepted');}
    catch (InvalidArgumentException) {}
}
$dir=sys_get_temp_dir().'/brvtal_t15_'.bin2hex(random_bytes(8));
try {
    for ($i=0;$i<30;$i++) brvtalTicketCheckinRate(7,'127.0.0.1',10000,$dir);
    try {brvtalTicketCheckinRate(7,'127.0.0.1',10000,$dir);throw new RuntimeException('rate bypass');}
    catch (DomainException $e) {t15($e->getMessage()==='CHECKIN_RATE_LIMITED','rate limit');}
    brvtalTicketCheckinRate(7,'127.0.0.1',10061,$dir);
} finally {foreach (glob($dir.'/*')?:[] as $f) unlink($f);@rmdir($dir);}
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {echo "T15 parser/RBAC/rate checks OK, DB skipped.\n";exit;}
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
t15(preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$prefix)===1,'disposable namespace');
$db=$prefix.'_checkin_'.bin2hex(random_bytes(4));
$root=t15Conn($prefix);
$root->exec('CREATE DATABASE '.$db.' CHARACTER SET utf8mb4');
try {
    $pdo=t15Conn($db);
    foreach ([
        'CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,status VARCHAR(20)) ENGINE=InnoDB',
        'CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED) ENGINE=InnoDB',
        'CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,status VARCHAR(20)) ENGINE=InnoDB',
        'CREATE TABLE ticket_order_items (id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,event_id INT UNSIGNED,ticket_type_id INT UNSIGNED) ENGINE=InnoDB',
        'CREATE TABLE ticket_issue_outbox (id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,state VARCHAR(20)) ENGINE=InnoDB',
        'CREATE TABLE ticket_payment_attempts (id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,provider VARCHAR(20),status VARCHAR(20),provider_transaction_id VARCHAR(100)) ENGINE=InnoDB',
        'CREATE TABLE ticket_payment_webhook_events (id BIGINT UNSIGNED PRIMARY KEY,attempt_id BIGINT UNSIGNED,provider_transaction_id VARCHAR(100),signed_status VARCHAR(16) NOT NULL) ENGINE=InnoDB',
    ] as $sql) $pdo->exec($sql);
    $migration=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_entries_01.sql');
    foreach (array_filter(array_map(trim(...),explode(';',preg_replace('/^\s*--.*$/m','',$migration)))) as $sql) $pdo->exec($sql);
    foreach (["INSERT INTO events VALUES (1,'active'),(2,'active')","INSERT INTO admins VALUES (7)",
        'INSERT INTO event_ticket_types VALUES (11,1)',"INSERT INTO ticket_orders VALUES (100,1,'paid')",
        'INSERT INTO ticket_order_items VALUES (1,100,1,11)',
        "INSERT INTO ticket_issue_outbox VALUES (1,100,'done')",
        "INSERT INTO ticket_payment_attempts VALUES (1,100,'wompi','approved','txn-synth')",
        "INSERT INTO ticket_payment_webhook_events VALUES (1,1,'txn-synth','APPROVED')"] as $sql) $pdo->exec($sql);
    [$id1,$qr1]=t15Entry($pdo,1,1);
    // A provider-approved attempt alone is insufficient without an APPROVED signed webhook.
    $pdo->exec("UPDATE ticket_payment_webhook_events SET signed_status='PENDING' WHERE id=1");
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr1)['reason']==='payment_unverified',
        'PENDING webhook denies check-in');
    $pdo->exec("UPDATE ticket_payment_webhook_events SET signed_status='DECLINED' WHERE id=1");
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr1)['reason']==='payment_unverified',
        'DECLINED webhook denies check-in');
    t15($pdo->query("SELECT status FROM ticket_entries WHERE id=1")->fetchColumn()==='issued',
        'unapproved webhooks cannot use ticket');
    $pdo->exec("UPDATE ticket_payment_webhook_events SET signed_status='APPROVED' WHERE id=1");
    brvtalTicketCheckinAuditRateLimited($pdo,1,7,'scanner-A');
    $limited=$pdo->query("SELECT outcome,reason_code,device_id,entry_id FROM ticket_checkins
        WHERE reason_code='rate_limited'")->fetch(PDO::FETCH_ASSOC);
    t15(is_array($limited) && $limited['outcome']==='rejected'
        && $limited['entry_id']===null && str_starts_with($limited['device_id'],'rate-')
        && $limited['device_id']!=='scanner-A','throttled audit is redacted');
    t15($pdo->query("SELECT status FROM ticket_entries WHERE id=1")->fetchColumn()==='issued',
        'throttled audit cannot consume ticket');
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A','garbage')['reason']==='malformed_qr','malformed audited');
    t15(brvtalTicketCheckinConsume($pdo,2,7,'scanner-A',$qr1)['reason']==='wrong_event','wrong event');
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr1)['ok'],'scan accepted');
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-B',$qr1)['reason']==='already_used','replay');
    [$id2,$qr2]=t15Entry($pdo,2,2);
    $pdo->exec("UPDATE ticket_entry_credentials SET status='revoked',revoked_at_utc=UTC_TIMESTAMP(6) WHERE entry_id=2");
    $newSecret=random_bytes(32);
    $pdo->prepare('INSERT INTO ticket_entry_credentials (entry_id,version,token_hash,created_at_utc)
        VALUES (2,2,?,UTC_TIMESTAMP(6))')->execute([hash('sha256',$newSecret,true)]);
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr2)['reason']==='credential_revoked','old QR');
    $qrNew=t15Qr($id2,$newSecret);
    $pdo->exec("UPDATE ticket_orders SET status='refunded' WHERE id=100");
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qrNew)['reason']==='payment_unverified','refunded');
    $pdo->exec("UPDATE ticket_orders SET status='paid' WHERE id=100");
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qrNew)['ok'],'reissued token');
    [$id3,$qr3]=t15Entry($pdo,3,3);
    $pdo->beginTransaction();
    $pdo->prepare('SELECT id FROM ticket_entries WHERE public_id=UNHEX(?) FOR UPDATE')->execute([$id3]);
    // Two independent processes are held at the same row, then released together.
    $workers=[];
    foreach (['scanner-C','scanner-D'] as $device) {
        $cmd=[PHP_BINARY,'-r','require '.var_export(__DIR__.'/../../config/ticketing_checkin.php',true).';'
            // Test-only PDO statement hook: READY is emitted directly before
            // the real event FOR UPDATE query, not merely after connecting.
            .'class T15ReadyStmt extends PDOStatement {protected function __construct(){}'
            .'public function execute(?array $params=null):bool {'
            .'if(str_contains($this->queryString,"SELECT status FROM events WHERE id=? FOR UPDATE"))'
            .'{echo "READY".PHP_EOL;flush();}'
            .'return parent::execute($params);}}'
            .'$pdo=new PDO(getenv("T15_DSN"),getenv("BRVTAL_TEST_DB_USER"),getenv("BRVTAL_TEST_DB_PASS"),'
            .'[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);'
            .'$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[T15ReadyStmt::class,[]]);'
            .'echo json_encode(brvtalTicketCheckinConsume($pdo,1,7,getenv("T15_DEVICE"),getenv("T15_QR")));'];
        $env=['T15_DSN'=>'mysql:host='.getenv('BRVTAL_TEST_DB_HOST').';port='.(getenv('BRVTAL_TEST_DB_PORT')?:3306).';dbname='.$db,
            'T15_DEVICE'=>$device,'T15_QR'=>$qr3,'BRVTAL_TEST_DB_USER'=>(string)getenv('BRVTAL_TEST_DB_USER'),
            'BRVTAL_TEST_DB_PASS'=>(string)getenv('BRVTAL_TEST_DB_PASS')];
        $p=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        t15(is_resource($p),'worker launched');$workers[]=[$p,$pipes];
    }
    // Both workers signal just before executing the real FOR UPDATE statement.
    $barrierPassed=false;
    try {
        $connections=[];
        foreach ($workers as [$proc,$pipes]) stream_set_blocking($pipes[1],false);
        $deadline=microtime(true)+10;
        while (count($connections)<2 && microtime(true)<$deadline) {
            $ready=[];
            foreach ($workers as [$proc,$pipes]) $ready[]=$pipes[1];
            $write=[];$except=[];
            t15(stream_select($ready,$write,$except,0,100000)!==false,'worker ready select');
            foreach ($ready as $stream) {
                $line=fgets($stream);
                if (is_string($line) && trim($line)==='READY') {
                    $connections[(int)$stream]=true;
                }
            }
        }
        t15(count($connections)===2,'both scanners reached event row-lock query');
        $pdo->commit();
        $barrierPassed=true;
    } finally {
        // A failing readiness check must not hold the entry row while cleanup
        // tries to drop the disposable database and workers still wait on it.
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$barrierPassed) {
            foreach ($workers as [$proc,$pipes]) {
                if (is_resource($proc)) proc_terminate($proc);
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (is_resource($proc)) proc_close($proc);
            }
        }
    }
    $reasons=[];
    foreach ($workers as [$proc,$pipes]) {
        stream_set_blocking($pipes[1],true);
        $output=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        t15(proc_close($proc)===0,'worker: '.$stderr);
        $reasons[]=json_decode($output,true,512,JSON_THROW_ON_ERROR)['reason'];
    }
    sort($reasons);
    t15($reasons===['accepted','already_used'],'exactly one of two scanners');
    t15((int)$pdo->query("SELECT COUNT(*) FROM ticket_checkins")->fetchColumn()===12,'audit every attempt');
    t15((int)$pdo->query("SELECT COUNT(*) FROM ticket_checkins WHERE outcome='accepted'")->fetchColumn()===3,
        'exactly three admissions');
    [$id4,$qr4]=t15Entry($pdo,4,4);
    $pdo->exec("UPDATE events SET status='cancelled' WHERE id=1");
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr4)['reason']==='event_closed','cancelled denied');
    $pdo->exec("UPDATE events SET status='active' WHERE id=1");
    $pdo->exec('DROP TABLE ticket_payment_webhook_events');
    try {brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr4);throw new RuntimeException('DB outage accepted');}
    catch (RuntimeException $e) {t15($e->getMessage()==='CHECKIN_STORAGE_UNAVAILABLE','DB failure fail-closed');}
    t15($pdo->query("SELECT status FROM ticket_entries WHERE id=4")->fetchColumn()==='issued',
        'DB outage never admits');
} finally {$root->exec('DROP DATABASE IF EXISTS '.$db);}
echo "T15 atomic scans, revocation, refund, audit and DB failure passed.\n";
// T25 runs within the same disposable MariaDB CI, never as a live migration.
require __DIR__ . '/ticketing-courtesy.php';
