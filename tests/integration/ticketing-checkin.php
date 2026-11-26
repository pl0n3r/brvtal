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
function t15Entry(PDO $pdo,int $id,int $unit,int $event=1,int $item=1,int $type=11):array {
    $opaque=random_bytes(16); $secret=random_bytes(32);
    $pdo->prepare('INSERT INTO ticket_entries (id,public_id,order_item_id,event_id,ticket_type_id,unit_number,issued_at_utc)
        VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))')->execute([$id,$opaque,$item,$event,$type,$unit]);
    $pdo->prepare('INSERT INTO ticket_entry_credentials (entry_id,version,token_hash,created_at_utc)
        VALUES (?,1,?,UTC_TIMESTAMP(6))')->execute([$id,hash('sha256',$secret,true)]);
    return [bin2hex($opaque),t15Qr(bin2hex($opaque),$secret)];
}

// Isolated CI real-stack only: never copy live configuration or credentials.
function t15DoorHttp(string $db,string $qr):void {
    $source=dirname(__DIR__,2); $directory=sys_get_temp_dir().'/brvtal-t15-http-'.bin2hex(random_bytes(8));
    t15(mkdir($directory,0700),'isolated fixture directory');
    $server=null;
    try {
        exec('git -C '.escapeshellarg($source).' ls-files -- config',$paths,$exit);
        t15($exit===0,'tracked fixture sources');
        foreach (array_merge($paths,['api/ticketing/checkin.php','discadmin/ticketing-door.php',
            'discadmin/ticketing-door.js','discadmin/ticketing-door.css']) as $path) {
            if ($path==='config/config.php') continue;
            $input=$source.'/'.$path; $target=$directory.'/'.$path;
            t15(is_file($input)&&!is_link($input),'fixture regular source');
            if (!is_dir(dirname($target))) t15(mkdir(dirname($target),0700,true),'fixture subtree');
            t15(copy($input,$target),'fixture source copy');
        }
        $config=['app'=>['timezone'=>'America/Bogota','debug'=>false],'db'=>[
            'host'=>getenv('BRVTAL_TEST_DB_HOST'),'port'=>(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306),
            'name'=>$db,'user'=>getenv('BRVTAL_TEST_DB_USER'),'pass'=>getenv('BRVTAL_TEST_DB_PASS')]];
        t15(file_put_contents($directory.'/config/config.php','<?php return '.var_export($config,true).';')!==false,
            'private fixture config');
        chmod($directory.'/config/config.php',0600); mkdir($directory.'/sessions',0700);
        $router=<<<'PHP_WRAP'
        <?php
        // Loopback synthetic sessions model HTTPS termination; never trust a client header.
        $_SERVER['HTTPS']='on';
        $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
        if ($path==='/fixture') {
            require __DIR__.'/config/admin_auth.php';
            if (!brvtal_admin_is_authenticated()) brvtal_admin_login_session(7);
            $_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT']='1';
            ob_start();
            echo '<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">';
            echo '<link rel="stylesheet" href="/discadmin/ticketing-door.css">';
            require __DIR__.'/discadmin/ticketing-door.php';
            echo '<script type="module">import {mount} from "/discadmin/ticketing-door.js";mount(document.querySelector("[data-door]"));</script>';
            ob_end_flush(); exit;
        }
        if (in_array($path,['/api/ticketing/checkin.php','/discadmin/ticketing-door.php'],true)) {
            require __DIR__.$path;
            exit;
        }
        if (in_array($path,['/discadmin/ticketing-door.js','/discadmin/ticketing-door.css'],true)) return false;
        http_response_code(404);
        PHP_WRAP;
        t15(file_put_contents($directory.'/router.php',$router)!==false,'fixture router');
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
        t15(is_resource($socket),'fixture loopback socket'); $address=stream_socket_get_name($socket,false); fclose($socket);
        $server=proc_open([PHP_BINARY,'-d','session.save_path='.$directory.'/sessions',
            '-d','allow_url_fopen=0',
            '-d','disable_functions=curl_init,curl_exec,curl_multi_init,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
            '-S',$address,'-t',$directory,$directory.'/router.php'],
            [0=>['pipe','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes,$directory);
        t15(is_resource($server),'fixture HTTP server'); fclose($pipes[0]);
        $ready=false; for ($i=0;$i<40;$i++) {
            $connection=@stream_socket_client('tcp://'.$address,$errno,$error,0.2);
            if (is_resource($connection)) {fclose($connection);$ready=true;break;}
            usleep(100000);
        }
        t15($ready,'fixture listening');
        $env=array_merge(getenv(),['BRVTAL_DOOR_REAL_URL'=>'http://'.$address,'BRVTAL_DOOR_TEST_QR'=>$qr]);
        $browser=proc_open(['node','node_modules/@playwright/test/cli.js','test','tests/e2e/ticketing-door.spec.mjs',
            '--project=chromium','--grep','T16 real HTTP','--reporter=json'],
            [0=>['pipe','r'],1=>['file',$directory.'/browser.json','w'],2=>['file','/dev/null','a']],$pipes,$source,$env);
        t15(is_resource($browser),'fixture browser'); fclose($pipes[0]);
        $exit=proc_close($browser); if ($exit!==0 && preg_match_all('/ticketing-door\.spec\.mjs:\d+:\d+/',(string)file_get_contents($directory.'/browser.json'),$locations)) echo implode(' ',array_unique($locations[0])).PHP_EOL;
        t15($exit===0,'real HTTP browser recovery');
    } finally {
        if (is_resource($server)) {proc_terminate($server);proc_close($server);}
        $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isDir()&&!$entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($directory);
    }
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
// Explicit receipt request has no QR; existing scanner bodies remain valid.
$attempt15=bin2hex(random_bytes(16));
$receipt15=['action'=>'receipt','event_id'=>1,'device_id'=>'door-1','request_id'=>$attempt15];
t15(brvtalTicketCheckinInput($receipt15)===$receipt15,'receipt parser');
t15(brvtalTicketCheckinInput(['event_id'=>1,'device_id'=>'door-1','qr'=>'bad',
    'request_id'=>$attempt15])['request_id']===$attempt15,'correlated scan parser');
foreach ([$receipt15+['qr'=>'x'],array_replace($receipt15,['request_id'=>null]),
    array_replace($receipt15,['request_id'=>str_repeat('A',32)]),
    array_replace($receipt15,['request_id'=>str_repeat('0',32)]),
    array_replace($receipt15,['action'=>'scan'])] as $invalidReceipt) {
    try {brvtalTicketCheckinInput($invalidReceipt);throw new RuntimeException('invalid receipt accepted');}
    catch (InvalidArgumentException) {}
}
t15(strpos($api,'brvtalTicketingCan')<strpos($api,'brvtalTicketCheckinReceipt')
    && strpos($api,'brvtalTicketCheckinRate')<strpos($api,'brvtalTicketCheckinReceipt')
    && strpos($api,'brvtal_admin_require_csrf()')<strpos($api,'brvtalTicketCheckinReceipt')
    && strpos($api,'HTTPS_REQUIRED')<strpos($api,'brvtalTicketCheckinReceipt')
    && str_contains($api,'RECEIPT_NOT_FOUND'),'receipt uses live HTTP authorization and neutral absence');
// Fast-CI contract: canonical states and Bogota admission boundaries.
$tz15=new DateTimeZone('America/Bogota');
$start15=new DateTimeImmutable('2026-11-28 21:00:00', $tz15);
$event15=['status'=>'tickets_available','event_date'=>'2026-11-28 21:00:00',
    'published_at'=>'2026-11-27 09:00:00'];
foreach (brvtal_public_event_statuses()['active'] as $status15) {
    $event15['status']=$status15;
    t15(brvtalTicketCheckinEventEligible($event15, $start15),'canonical admission '.$status15);
}
foreach (['active','draft','finished','archived','cancelled'] as $status15) {
    $event15['status']=$status15;
    t15(!brvtalTicketCheckinEventEligible($event15, $start15),'closed lifecycle '.$status15);
}
$event15['status']='sold_out';
t15(!brvtalTicketCheckinEventEligible($event15, $start15->modify('-1 second')),'door not open');
t15(brvtalTicketCheckinEventEligible($event15,
    $start15->modify('+1 day')->modify('-1 second')),'overnight last second');
t15(!brvtalTicketCheckinEventEligible($event15, $start15->modify('+1 day')),'door cutoff exclusive');
t15(!brvtalTicketCheckinEventEligible($event15, $start15->modify('+1 day +1 second')),'door expired');
foreach (['', '2026-02-30 20:00:00', 'bogus'] as $invalid15) {
    $event15['event_date']=$invalid15;
    t15(!brvtalTicketCheckinEventEligible($event15, $start15),'invalid event date closed');
}
$event15['event_date']='2026-11-28 21:00:00';
$event15['published_at']='2026-11-29 09:00:00';
t15(!brvtalTicketCheckinEventEligible($event15, $start15),'future publication denied');
$event15['published_at']='malformed';
t15(!brvtalTicketCheckinEventEligible($event15, $start15),'invalid publication denied');

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
        'CREATE TABLE events (id INT UNSIGNED PRIMARY KEY,status VARCHAR(20),event_date DATETIME NULL,published_at DATETIME NULL) ENGINE=InnoDB',
        'CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB',
        'CREATE TABLE ticket_refund_event_decisions (event_id INT UNSIGNED PRIMARY KEY,cause VARCHAR(20) NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED) ENGINE=InnoDB',
        'CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,status VARCHAR(20)) ENGINE=InnoDB',
        'CREATE TABLE ticket_order_items (id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,event_id INT UNSIGNED,ticket_type_id INT UNSIGNED) ENGINE=InnoDB',
        'CREATE TABLE ticket_issue_outbox (id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,state VARCHAR(20)) ENGINE=InnoDB',
        'CREATE TABLE ticket_payment_attempts (id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,provider VARCHAR(20),status VARCHAR(20),provider_transaction_id VARCHAR(100)) ENGINE=InnoDB',
        'CREATE TABLE ticket_payment_webhook_events (id BIGINT UNSIGNED PRIMARY KEY,attempt_id BIGINT UNSIGNED,provider_transaction_id VARCHAR(100),signed_status VARCHAR(16) NOT NULL) ENGINE=InnoDB',
    ] as $sql) $pdo->exec($sql);
    $migration=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_entries_01.sql');
    foreach (array_filter(array_map(trim(...),explode(';',preg_replace('/^\s*--.*$/m','',$migration)))) as $sql) $pdo->exec($sql);
    // Synthetic Bogota Event began twelve hours ago: safely inside the door window.
    $openAt15=new DateTimeImmutable('now', new DateTimeZone('America/Bogota'))
        ->modify('-12 hours')->format('Y-m-d H:i:s');
    $publicAt15=new DateTimeImmutable('now', new DateTimeZone('America/Bogota'))
        ->modify('-2 days')->format('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO events(id,status,event_date,published_at)
        VALUES (1,'published',?,?),(2,'published',?,?)")
        ->execute([$openAt15,$publicAt15,$openAt15,$publicAt15]);
    foreach (["INSERT INTO admins VALUES (7)",
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
            .'if(str_contains($this->queryString,"SELECT status,event_date,published_at FROM events WHERE id=? FOR UPDATE"))'
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
    $pdo->exec("UPDATE events SET status='published' WHERE id=1");
    // MariaDB: QR admission in canonical stock statuses, irrespective of sold-out inventory.
    foreach (['tickets_available','last_tickets','sold_out'] as $j=>$status15) {
        $pdo->prepare('UPDATE events SET status=? WHERE id=1')->execute([$status15]);
        [, $qrValid15]=t15Entry($pdo,5+$j,5+$j);
        t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qrValid15)['ok'],
            'MariaDB admits legitimate QR in '.$status15);
    }
    // A previously downloaded QR must not grant early/late entry.
    $pdo->prepare("UPDATE events SET status='sold_out',event_date=?,published_at=? WHERE id=1")
        ->execute([$start15->format('Y-m-d H:i:s'),'2026-11-27 09:00:00']);
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr4,
        $start15->modify('-1 second'))['reason']==='event_closed','MariaDB early denied');
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A','garbage',$start15)
        ['reason']==='malformed_qr','MariaDB opening inclusive');
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A','garbage',
        $start15->modify('+1 day')->modify('-1 second'))['reason']==='malformed_qr',
        'MariaDB overnight near end');
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr4,
        $start15->modify('+1 day'))['reason']==='event_closed','MariaDB cutoff exclusive');
    t15($pdo->query('SELECT status FROM ticket_entries WHERE id=4')->fetchColumn()==='issued',
        'window denial preserves downloaded QR');

    $pdo->exec("INSERT INTO ticket_refund_event_decisions VALUES (1,'postponed')");
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr4,$start15)
        ['reason']==='event_closed','owner postponement blocks sold_out admission');
    $pdo->exec('DELETE FROM ticket_refund_event_decisions WHERE event_id=1');
    $pdo->prepare('UPDATE events SET event_date=? WHERE id=1')
        ->execute([$start15->modify('+7 days')->format('Y-m-d H:i:s')]);
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr4,$start15)
        ['reason']==='event_closed','rescheduled start controls old QR');
    foreach (['cancelled','archived','finished','draft','active'] as $status15) {
        $pdo->prepare('UPDATE events SET status=? WHERE id=1')->execute([$status15]);
        t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr4,
            $start15->modify('+7 days'))['reason']==='event_closed',
            'MariaDB rejects lifecycle '.$status15);
    }
    $pdo->prepare("UPDATE events SET status='published',event_date=?,published_at=? WHERE id=1")
        ->execute([$openAt15,$publicAt15]);
    // #2121: separate event/order/credential and staff identities.
    foreach (["INSERT INTO admins VALUES (8)",
        'INSERT INTO event_ticket_types VALUES (12,2)',"INSERT INTO ticket_orders VALUES (101,2,'paid')",
        'INSERT INTO ticket_order_items VALUES (2,101,2,12)',
        "INSERT INTO ticket_issue_outbox VALUES (2,101,'done')",
        "INSERT INTO ticket_payment_attempts VALUES (2,101,'wompi','approved','txn-synth-2')",
        "INSERT INTO ticket_payment_webhook_events VALUES (2,2,'txn-synth-2','APPROVED')"] as $sql) $pdo->exec($sql);
    [, $qr30]=t15Entry($pdo,30,30);
    [, $qr31]=t15Entry($pdo,31,31);
    $id30=bin2hex(random_bytes(16));
    // Discard the response after commit, then recover the historical result.
    brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr30,null,$id30);
    $history=brvtalTicketCheckinReceipt($pdo,1,7,'scanner-A',$id30);
    t15($history===['ok'=>false,'receipt'=>['outcome'=>'accepted','reason'=>'accepted']],
        'lost response recovered without authorizing entry');
    foreach ([[2,7,'scanner-A',$id30],[1,8,'scanner-A',$id30],
        [1,7,'scanner-B',$id30],[1,7,'scanner-A',bin2hex(random_bytes(16))]] as $scope) {
        t15(brvtalTicketCheckinReceipt($pdo,...$scope)===null,'foreign/missing receipt is indistinguishable');
    }
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr31,null,$id30)
        ===['ok'=>false,'reason'=>'attempt_conflict'],'same ID different QR conflicts');
    t15($pdo->query("SELECT status FROM ticket_entries WHERE id=31")->fetchColumn()==='issued',
        'duplicate ID does not consume a second ticket');
    $otherId=bin2hex(random_bytes(16));
    t15(brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr30,null,$otherId)['reason']==='already_used',
        'same QR new ID remains denied');
    t15(brvtalTicketCheckinReceipt($pdo,1,7,'scanner-A',$otherId)
        ===['ok'=>false,'receipt'=>['outcome'=>'rejected','reason'=>'already_used']],'historical denial');
    t15((int)$pdo->query("SELECT COUNT(*) FROM ticket_checkins WHERE request_id=UNHEX('$id30')")->fetchColumn()===1,
        'one durable audit per attempt');
    [, $qr32]=t15Entry($pdo,32,1,2,2,12);
    $collision=bin2hex(random_bytes(16)); $workers2121=[];
    try {
        foreach ([[1,7,$qr31],[2,8,$qr32]] as [$ev,$staff,$code]) {
            // Both workers stop immediately before the actual UNIQUE insert,
            // after their independent event/entry mutations, while locks are held.
            $program='require '.var_export(__DIR__.'/../../config/ticketing_checkin.php',true).';'
                .'class T15CollisionStmt extends PDOStatement {protected function __construct(){}'
                .'public function execute(?array $params=null):bool {'
                .'if(str_contains($this->queryString,"INSERT INTO ticket_checkins")){'
                .'echo "READY".PHP_EOL;flush();if(trim((string)fgets(STDIN))!=="GO")throw new RuntimeException("barrier");}'
                .'return parent::execute($params);}}'
                .'$db=new PDO(getenv("T15_DSN"),getenv("BRVTAL_TEST_DB_USER"),getenv("BRVTAL_TEST_DB_PASS"),'
                .'[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);'
                .'$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[T15CollisionStmt::class,[]]);'
                .'echo json_encode(brvtalTicketCheckinConsume($db,(int)getenv("T15_EVENT"),'
                .'(int)getenv("T15_STAFF"),"scanner-race",getenv("T15_QR"),null,getenv("T15_ATTEMPT")));';
            $env=['T15_DSN'=>'mysql:host='.getenv('BRVTAL_TEST_DB_HOST').';port='.(getenv('BRVTAL_TEST_DB_PORT')?:3306).';dbname='.$db,
                'T15_EVENT'=>(string)$ev,'T15_STAFF'=>(string)$staff,'T15_QR'=>$code,'T15_ATTEMPT'=>$collision,
                'BRVTAL_TEST_DB_USER'=>(string)getenv('BRVTAL_TEST_DB_USER'),'BRVTAL_TEST_DB_PASS'=>(string)getenv('BRVTAL_TEST_DB_PASS')];
            $proc=proc_open([PHP_BINARY,'-r',$program],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
            t15(is_resource($proc),'collision worker launched');$workers2121[]=[$proc,$pipes];
        }
        $ready2121=[];$deadline2121=microtime(true)+10;
        foreach ($workers2121 as [$proc,$pipes]) stream_set_blocking($pipes[1],false);
        while (count($ready2121)<2 && microtime(true)<$deadline2121) {
            $read=[];
            foreach ($workers2121 as [$proc,$pipes]) {
                if (!isset($ready2121[(int)$pipes[1]])) $read[]=$pipes[1];
            }
            $write=[];$except=[];
            t15(stream_select($read,$write,$except,0,100000)!==false,'collision ready select');
            foreach ($read as $stream) {
                if (trim((string)fgets($stream))==='READY') $ready2121[(int)$stream]=true;
            }
        }
        t15(count($ready2121)===2,'both workers reached audit insert');
        foreach ($workers2121 as [$proc,$pipes]) {fwrite($pipes[0],"GO\n");fflush($pipes[0]);}
        $results2121=[];
        foreach ($workers2121 as [$proc,$pipes]) {
            stream_set_blocking($pipes[1],true);
            $results2121[]=json_decode((string)stream_get_contents($pipes[1]),true,512,JSON_THROW_ON_ERROR)['reason'];
        }
        sort($results2121);
        t15($results2121===['accepted','attempt_conflict'],'one cross-event UNIQUE winner');
        t15((int)$pdo->query("SELECT COUNT(*) FROM ticket_entries WHERE id IN (31,32) AND status='used'")->fetchColumn()===1,
            'collision rolls back losing entry mutation');
        t15((int)$pdo->query("SELECT COUNT(*) FROM ticket_checkins WHERE request_id=UNHEX('$collision')")->fetchColumn()===1,
            'only winning audit committed');
    } finally {
        foreach ($workers2121 as [$proc,$pipes]) {
            if (is_resource($proc) && proc_get_status($proc)['running']) proc_terminate($proc);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($proc)) proc_close($proc);
        }
    }
    echo "T15 #2121 durable receipt and cross-event rollback passed.\n";
    // #2098: disposable POS evidence rows; T24 also exercises the actual
    // brvtalPosConfirm/manual-order issuance paths against MariaDB.
    foreach ([
        "ALTER TABLE ticket_orders ADD currency CHAR(3) DEFAULT 'COP',
            ADD total_cop BIGINT UNSIGNED DEFAULT 25000, ADD checkout_key_hash CHAR(64)",
        'ALTER TABLE ticket_order_items ADD quantity INT UNSIGNED DEFAULT 1,
            ADD unit_price_cop BIGINT UNSIGNED DEFAULT 25000, ADD subtotal_cop BIGINT UNSIGNED DEFAULT 25000',
        'ALTER TABLE ticket_issue_outbox ADD payment_attempt_id BIGINT UNSIGNED NULL',
        'CREATE TABLE ticket_pos_shifts (id BIGINT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED,
            operator_admin_id INT UNSIGNED) ENGINE=InnoDB',
        'CREATE TABLE ticket_pos_sales (id BIGINT UNSIGNED PRIMARY KEY,shift_id BIGINT UNSIGNED,
            event_id INT UNSIGNED,operator_admin_id INT UNSIGNED,ticket_type_id INT UNSIGNED,
            order_id BIGINT UNSIGNED UNIQUE,quantity INT UNSIGNED,unit_price_cop BIGINT UNSIGNED,
            amount_cop BIGINT UNSIGNED,method VARCHAR(20),status VARCHAR(20),
            completed_at_utc DATETIME NULL,request_hash CHAR(64)) ENGINE=InnoDB',
        'CREATE TABLE ticket_order_notes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED,actor_admin_id INT UNSIGNED,note_text VARCHAR(280)) ENGINE=InnoDB',
        'INSERT INTO ticket_pos_shifts VALUES (1,1,7),(2,2,7),(3,1,8)',
    ] as $sql) $pdo->exec($sql);
    $posFixture=static function(int $id,string $method,bool $direct) use ($pdo):string {
        $digest=hash('sha256','manual-fixture:'.$id);
        $checkout=$direct?hash('sha256','pos:'.$id):$digest;
        $pdo->prepare("INSERT INTO ticket_orders(id,event_id,status,checkout_key_hash)
            VALUES (?,1,'paid',?)")->execute([$id,$checkout]);
        $pdo->prepare('INSERT INTO ticket_order_items(id,order_id,event_id,ticket_type_id)
            VALUES (?,?,1,11)')->execute([$id,$id]);
        $pdo->prepare("INSERT INTO ticket_pos_sales VALUES (?,1,1,7,11,?,1,25000,25000,
            ?,'paid',UTC_TIMESTAMP(),?)")->execute([$id,$id,$method,$digest]);
        if (!$direct) {
            $pdo->prepare("INSERT INTO ticket_issue_outbox(id,order_id,state)
                VALUES (?,?,'done')")->execute([$id,$id]);
        }
        if ($method==='transfer') {
            $pdo->prepare('INSERT INTO ticket_order_notes(order_id,actor_admin_id,note_text)
                VALUES (?,7,?)')->execute([$id,'manual-payment|transfer|REF-2098|'.$digest]);
        }
        $opaque=random_bytes(16); $secret=random_bytes(32);
        $pdo->prepare('INSERT INTO ticket_entries(id,public_id,order_item_id,event_id,ticket_type_id,
            unit_number,issued_at_utc) VALUES (?,?,?,1,11,1,UTC_TIMESTAMP(6))')->execute([$id,$opaque,$id]);
        $pdo->prepare('INSERT INTO ticket_entry_credentials(entry_id,version,token_hash,created_at_utc)
            VALUES (?,1,?,UTC_TIMESTAMP(6))')->execute([$id,hash('sha256',$secret,true)]);
        return t15Qr(bin2hex($opaque),$secret);
    };
    foreach ([[200,'cash',true],[201,'cash',false],[202,'terminal',false],[203,'transfer',false]]
        as [$id,$method,$direct]) {
        $qr=$posFixture($id,$method,$direct);
        t15(brvtalTicketCheckinConsume($pdo,1,7,'pos-reader',$qr)['ok'],'valid POS '.$id);
    }
    $invalid=[
        210=>"UPDATE ticket_pos_sales SET amount_cop=1 WHERE id=210",
        211=>"UPDATE ticket_pos_sales SET event_id=2 WHERE id=211",
        212=>"UPDATE ticket_pos_sales SET quantity=2 WHERE id=212",
        213=>"UPDATE ticket_pos_sales SET shift_id=2 WHERE id=213",
        214=>"UPDATE ticket_pos_sales SET shift_id=3 WHERE id=214",
        215=>"UPDATE ticket_pos_sales SET completed_at_utc=NULL WHERE id=215",
        216=>"UPDATE ticket_pos_sales SET status='prepared' WHERE id=216",
        217=>"UPDATE ticket_issue_outbox SET state='pending' WHERE id=217",
        218=>"UPDATE ticket_issue_outbox SET payment_attempt_id=1 WHERE id=218",
        219=>"DELETE FROM ticket_issue_outbox WHERE id=219",
        220=>"UPDATE ticket_order_items SET unit_price_cop=1 WHERE id=220",
        221=>"UPDATE ticket_orders SET currency='USD' WHERE id=221",
        222=>"UPDATE ticket_entries SET unit_number=2 WHERE id=222",
        223=>"INSERT INTO ticket_payment_attempts VALUES (223,223,'wompi','approved','mixed')",
        224=>"DELETE FROM ticket_order_notes WHERE order_id=224",
    ];
    foreach ($invalid as $id=>$mutation) {
        $qr=$posFixture($id,$id===224?'transfer':'cash',false);
        $pdo->exec($mutation);
        if ($id===223) {
            $pdo->exec("INSERT INTO ticket_payment_webhook_events VALUES (223,223,'mixed','APPROVED')");
        }
        t15(brvtalTicketCheckinConsume($pdo,1,7,'pos-reader',$qr)['reason']==='payment_unverified',
            'inconsistent POS rejected '.$id);
        $status=$pdo->prepare('SELECT status FROM ticket_entries WHERE id=?');
        $status->execute([$id]);
        t15($status->fetchColumn()==='issued','invalid POS preserves QR '.$id);
    }
    $qr=$posFixture(225,'cash',true);
    $pdo->exec("UPDATE ticket_orders SET checkout_key_hash=SHA2('wrong',256) WHERE id=225");
    t15(brvtalTicketCheckinConsume($pdo,1,7,'pos-reader',$qr)['reason']==='payment_unverified',
        'direct receipt provenance required');
    echo "T15 #2098 POS direct/manual financial proof and negative cases passed.\n";
    if (getenv('BRVTAL_REAL_STACK_FORCE')==='1') {
        $pdo->exec("ALTER TABLE admins ADD is_active TINYINT NOT NULL DEFAULT 1,
            ADD credential_epoch INT NOT NULL DEFAULT 1, ADD staff_role VARCHAR(20) NOT NULL DEFAULT 'door'");
        $pdo->exec("ALTER TABLE events ADD title VARCHAR(100) NOT NULL DEFAULT 'Evento sintético'");
        t15DoorHttp($db,$posFixture(226,'cash',true));
        t15($pdo->query("SELECT status FROM ticket_entries WHERE id=226")->fetchColumn()==='used',
            'real HTTP lost response committed');
        t15((int)$pdo->query("SELECT COUNT(*) FROM ticket_checkins WHERE entry_id=226 AND outcome='accepted'")
            ->fetchColumn()===1,'real HTTP one accepted consumption');
        echo "T15 #2110 real HTTP scanner recovery passed.\n";
    }
    $pdo->exec('DROP TABLE ticket_payment_webhook_events');
    try {brvtalTicketCheckinConsume($pdo,1,7,'scanner-A',$qr4);throw new RuntimeException('DB outage accepted');}
    catch (RuntimeException $e) {t15($e->getMessage()==='CHECKIN_STORAGE_UNAVAILABLE','DB failure fail-closed');}
    t15($pdo->query("SELECT status FROM ticket_entries WHERE id=4")->fetchColumn()==='issued',
        'DB outage never admits');
} finally {$root->exec('DROP DATABASE IF EXISTS '.$db);}
echo "T15 atomic scans, revocation, refund, audit and DB failure passed.\n";
// T25 runs within the same disposable MariaDB CI, never as a live migration.
require __DIR__ . '/ticketing-courtesy.php';
