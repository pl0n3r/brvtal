<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_mail_events.php';
require_once __DIR__ . '/../../api/ticketing/webhook-resend.php';
function mail_t11_assert(bool $ok,string $case): void {
    if (!$ok) throw new RuntimeException('T11 FAILED: '.$case);
}
putenv('BRVTAL_TICKET_MAIL_TESTING=1');putenv('BRVTAL_TICKET_MAIL_WEBHOOK_ENABLED=1');
$secret='whsec_'.base64_encode(str_repeat('s',32));$now=time();
$sign=static function(string $id,string $raw,int $stamp): array {
    $key=str_repeat('s',32);
    return ['svix-id'=>$id,'svix-timestamp'=>(string)$stamp,
        'svix-signature'=>'v1,'.base64_encode(hash_hmac('sha256',$id.'.'.$stamp.'.'.$raw,$key,true))];
};
$raw=json_encode(['type'=>'email.delivered','created_at'=>gmdate('c',$now-10),
    'data'=>['email_id'=>'synthetic-email-1','to'=>['buyer@example.test']]],JSON_THROW_ON_ERROR);
$headers=$sign('msg_synthetic_1',$raw,$now);
mail_t11_assert(brvtal_mail_webhook_verify($raw,$headers,$secret,$now),'HMAC');
mail_t11_assert(!brvtal_mail_webhook_verify($raw.' ',$headers,$secret,$now),'tampered');
mail_t11_assert(!brvtal_mail_webhook_verify($raw,$headers,'',$now),'missing secret');
mail_t11_assert(!brvtal_mail_webhook_verify($raw,$sign('msg_old',$raw,$now-600),$secret,$now),'replay');
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {
    echo "BRVTAL ticket-mail-history signature fixtures OK; MariaDB skipped.\n";exit(0);
}
$base=(string)getenv('BRVTAL_TEST_DB_NAME');
mail_t11_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$base),'DB namespace');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306);$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS')?:'');$dbName=$base.'_t11_'.bin2hex(random_bytes(4));
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO('mysql:host='.$host.';port='.$port.';charset=utf8mb4',$user,$pass,$opts);
$server->exec('CREATE DATABASE '.$dbName.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
register_shutdown_function(static function () use($server,$dbName): void {
    try {$server->exec('DROP DATABASE IF EXISTS '.$dbName);} catch (Throwable) {}
});
$pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$dbName.';charset=utf8mb4',$user,$pass,$opts);
$pdo->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_mail_outbox_01.sql'));
$schema=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_mail_history_01.sql');
foreach (preg_split('/;\s*(?=CREATE|$)/i',$schema) ?: [] as $statement) {
    if (trim($statement)!=='') $pdo->exec($statement);
}

/* Small, synthetic staff fixture. No production staff records are used. */
$pdo->exec('CREATE TABLE admins (
    id INT PRIMARY KEY, staff_role VARCHAR(32) NOT NULL,
    is_active TINYINT NOT NULL, credential_epoch INT NOT NULL DEFAULT 1
)');
$pdo->exec("INSERT INTO admins (id,staff_role,is_active,credential_epoch) VALUES
    (1,'admin',1,1),(2,'owner',1,1),(3,'door',1,1),
    (4,'admin',0,1),(5,'unexpected_role',1,1)");

$pdo->exec("INSERT INTO ticket_mail_outbox (message_key,payload_hash,related_ref,recipient_email,subject,body_text,provider_message_id,state)
 VALUES ('test:mail:1',REPEAT('a',64),'entry:1','buyer@example.test','Test','Synthetic','synthetic-email-1','accepted')");
$id=brvtal_mail_history_record($pdo,1,['event_id'=>5,'buyer_id'=>6,'order_id'=>7,'ticket_id'=>8]);
mail_t11_assert($id===brvtal_mail_history_record($pdo,1),'idempotent');
foreach (['event_id'=>5,'buyer_id'=>6,'order_id'=>7,'ticket_id'=>8] as $key=>$val) {
    mail_t11_assert(count(brvtal_mail_history_list($pdo,[$key=>$val],1))===1,$key);
    mail_t11_assert(count(brvtal_mail_history_list($pdo,[$key=>$val],2))===1,'owner '.$key);
}
foreach ([3,4,5,999] as $unauthorized) {
    try {
        brvtal_mail_history_list($pdo,['event_id'=>5],$unauthorized);
        mail_t11_assert(false,'no mail grant '.$unauthorized);
    } catch (DomainException $e) {
        mail_t11_assert($e->getMessage()==='MAIL_FORBIDDEN','mail permission '.$unauthorized);
    }
}
$pdo->prepare("UPDATE admins SET is_active=0 WHERE id=1")->execute();
try {brvtal_mail_history_list($pdo,['event_id'=>5],1);mail_t11_assert(false,'revoked admin');}
catch (DomainException $e) {mail_t11_assert($e->getMessage()==='MAIL_FORBIDDEN','revoked access');}
$pdo->prepare("UPDATE admins SET is_active=1 WHERE id=1")->execute();
try {brvtal_mail_history_list($pdo,['event_id'=>5],0);mail_t11_assert(false,'public leak');}
catch(RuntimeException $e){mail_t11_assert($e->getMessage()==='MAIL_ADMIN_REQUIRED','admin gate');}
mail_t11_assert((string)$pdo->query('SELECT delivery_state FROM ticket_mail_history')->fetchColumn()==='accepted','accepted != delivered');
mail_t11_assert(brvtal_mail_webhook_process($pdo,$raw,$headers,$secret,$now)===200,'delivered');
mail_t11_assert(brvtal_mail_webhook_process($pdo,$raw,$headers,$secret,$now)===200,'duplicate');
mail_t11_assert((int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_provider_events')->fetchColumn()===1,'dedup');
$old=json_encode(['type'=>'email.sent','created_at'=>gmdate('c',$now-60),
    'data'=>['email_id'=>'synthetic-email-1']],JSON_THROW_ON_ERROR);
mail_t11_assert(brvtal_mail_webhook_process($pdo,$old,$sign('msg_old_sent',$old,$now),$secret,$now)===200,'out of order');
mail_t11_assert((string)$pdo->query('SELECT delivery_state FROM ticket_mail_history')->fetchColumn()==='delivered','no downgrade');

$later=json_encode(['type'=>'email.sent','created_at'=>gmdate('c',$now+10),
    'data'=>['email_id'=>'synthetic-email-1']],JSON_THROW_ON_ERROR);
mail_t11_assert(brvtal_mail_webhook_process($pdo,$later,$sign('msg_later_sent',$later,$now),$secret,$now)===200,
    'later sent event');
mail_t11_assert((string)$pdo->query('SELECT delivery_state FROM ticket_mail_history')->fetchColumn()==='delivered',
    'later sent must not downgrade delivered');

$orphan=json_encode(['type'=>'email.bounced','created_at'=>gmdate('c',$now),
    'data'=>['email_id'=>'synthetic-email-2']],JSON_THROW_ON_ERROR);
mail_t11_assert(brvtal_mail_webhook_process($pdo,$orphan,$sign('msg_orphan',$orphan,$now),$secret,$now)===200,'orphan');
$pdo->exec("INSERT INTO ticket_mail_outbox (message_key,payload_hash,related_ref,recipient_email,subject,body_text,provider_message_id,state)
 VALUES ('test:mail:2',REPEAT('b',64),'entry:2','other@example.test','Test','Synthetic','synthetic-email-2','accepted')");
$id2=brvtal_mail_history_record($pdo,2);
$q=$pdo->prepare('SELECT delivery_state FROM ticket_mail_history WHERE id=?');$q->execute([$id2]);
mail_t11_assert((string)$q->fetchColumn()==='bounced','orphan reconciled');
$pdo->prepare('UPDATE ticket_mail_history SET retention_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=?')->execute([$id]);
mail_t11_assert(brvtal_mail_history_redact_expired($pdo)===1,'retention');
mail_t11_assert(count(brvtal_mail_history_list($pdo,['event_id'=>5],1))===1,'non-PII history preserved');
// A queued record must bind the provider ID when Resend accepts it later.
$pdo->exec("INSERT INTO ticket_mail_outbox (message_key,payload_hash,related_ref,recipient_email,subject,body_text,state)
 VALUES ('test:mail:3',REPEAT('c',64),'entry:3','third@example.test','Test','Synthetic','queued')");
$id3=brvtal_mail_history_record($pdo,3,['order_id'=>11]);
$pdo->exec("UPDATE ticket_mail_outbox SET provider_message_id='synthetic-email-3',state='accepted'
 WHERE message_key='test:mail:3'");
mail_t11_assert($id3===brvtal_mail_history_record($pdo,3,['order_id'=>11]),'backfill stable');
$bind=$pdo->prepare('SELECT resend_email_id,delivery_state FROM ticket_mail_history WHERE id=?');
$bind->execute([$id3]);$bound=$bind->fetch(PDO::FETCH_ASSOC);
mail_t11_assert($bound['resend_email_id']==='synthetic-email-3'
    && $bound['delivery_state']==='accepted','queued upgraded to accepted');
$delivered=json_encode(['type'=>'email.delivered','created_at'=>gmdate('c',$now),
    'data'=>['email_id'=>'synthetic-email-3']],JSON_THROW_ON_ERROR);
mail_t11_assert(brvtal_mail_webhook_process($pdo,$delivered,$sign('msg_upgraded',$delivered,$now),$secret,$now)===200,
    'upgraded delivered');
$bind->execute([$id3]);$bound=$bind->fetch(PDO::FETCH_ASSOC);
mail_t11_assert($bound['delivery_state']==='delivered','upgrade receipt persisted');
// #1998: the authenticated whsec_ signature + privacy gate suffice;
// the legacy operational toggle is no longer a separate veto.
putenv('BRVTAL_TICKET_MAIL_WEBHOOK_ENABLED=0');
mail_t11_assert(brvtal_mail_webhook_process($pdo,$raw,$headers,$secret,$now)===200,
    'signed webhook accepted without undocumented flag');
mail_t11_assert((int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_provider_events')->fetchColumn()===5,
    'legacy flag off does not duplicate the signed provider receipt');
putenv('BRVTAL_TICKET_MAIL_TESTING=0');
putenv('BRVTAL_TICKET_MAIL_PRIVACY_APPROVED=0');
mail_t11_assert(brvtal_mail_webhook_process($pdo,$raw,$headers,$secret,$now)===503,
    'privacy gate still denies signed webhook without approval');
try {brvtal_mail_history_record($pdo,1);mail_t11_assert(false,'privacy denied');}
catch (RuntimeException $e) {mail_t11_assert($e->getMessage()==='MAIL_PRIVACY_NOT_APPROVED','privacy gate');}


// #2107: real HTTP boundary in an isolated localhost PHP server + disposable MariaDB.
// It runs within the existing T11 database gate, without any provider or production account.
putenv('BRVTAL_TICKET_MAIL_TESTING=1');
putenv('BRVTAL_TICKET_MAIL_WEBHOOK_SECRET='.$secret);
$httpDir=sys_get_temp_dir().'/brvtal-mail-history-http-'.bin2hex(random_bytes(6));
mail_t11_assert(mkdir($httpDir,0700),'create isolated HTTP fixture');
$fixtureConfig=$httpDir.'/config.php';
$routerFile=$httpDir.'/router.php';
$serverLog=$httpDir.'/php.log';
$root=realpath(__DIR__.'/../..');
mail_t11_assert(is_string($root),'locate web root');
$configData=[
    'app'=>['timezone'=>'UTC','base_url'=>'http://127.0.0.1'],
    'db'=>['host'=>$host,'port'=>$port,'name'=>$dbName,
        'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4'],
    'security'=>['session_name'=>'BRVTAL_T11_HTTP','csrf_key'=>'synthetic-only'],
];
file_put_contents($fixtureConfig,'<?php return '.var_export($configData,true).';');
$router=<<<'T11_ROUTER'
<?php
declare(strict_types=1);
define('BRVTAL_SENTRY_TESTING',true);
$GLOBALS['brvtalBootstrapConfigPath']=__T11_CONFIG__;
if (parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)==='/__t11_ready') {
    echo 'ready';
    return;
}
if (parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)==='/__t11_login') {
    require_once __T11_AUTH__;
    brvtal_admin_session_start();
    $_SESSION['admin_id']=(int)($_GET['id']??0);
    $_SESSION['credential_epoch']=1;
    $_SESSION['issued_at']=time();
    $_SESSION['last_activity']=time();
    header('Cache-Control: no-store');
    echo 'ready';
    return;
}
$_SERVER['SCRIPT_FILENAME']=__T11_ENDPOINT__;
require __T11_ENDPOINT__;
T11_ROUTER;
$router=str_replace(['__T11_CONFIG__','__T11_AUTH__','__T11_ENDPOINT__'],
    [var_export($fixtureConfig,true),
     var_export($root.'/config/admin_auth.php',true),
     var_export($root.'/api/ticketing/webhook-resend.php',true)],$router);
file_put_contents($routerFile,$router);
$listener=stream_socket_server('tcp://127.0.0.1:0',$portError,$portMessage);
mail_t11_assert($listener!==false,'reserve loopback port');
$listenAddress=stream_socket_get_name($listener,false);
$httpPort=(int)substr((string)strrchr($listenAddress,':'),1);
fclose($listener);
mail_t11_assert($httpPort>0,'loopback port');
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$httpPort,'-t',$root,$routerFile],
    [['pipe','r'],['file',$serverLog,'a'],['file',$serverLog,'a']],$serverPipes);
mail_t11_assert(is_resource($server),'start isolated server');
fclose($serverPipes[0]);
try {
    $origin='http://127.0.0.1:'.$httpPort;
    $ready=false;
    for ($attempt=0;$attempt<40;$attempt++) {
        if (@file_get_contents($origin.'/__t11_ready')==='ready') {$ready=true;break;}
        usleep(100000);
    }
    mail_t11_assert($ready,'local HTTP server ready');
    $request=static function (string $method,string $url,array $headers=[],string $body=''): array {
        $opts=['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>8,
            'header'=>implode("\r\n",$headers),'content'=>$body]];
        $response=file_get_contents($url,false,stream_context_create($opts));
        $responseHeaders=$http_response_header??[];
        mail_t11_assert($response!==false && count($responseHeaders)>0,'HTTP transport');
        preg_match('/^HTTP\/[0-9.]+ ([0-9]{3})/',(string)$responseHeaders[0],$match);
        return [(int)($match[1]??0),(string)$response,$responseHeaders];
    };
    $historyUrl=$origin.'/api/ticketing/webhook-resend.php?event_id=5';
    [$status,$body]=$request('GET',$historyUrl);
    mail_t11_assert($status===401 && !str_contains($body,'"items"'),'HTTP anonymous denied');
    foreach ([3=>403,4=>401,5=>403] as $roleId=>$expectedStatus) {
        [$loginStatus,$loginBody,$loginHeaders]=$request('GET',$origin.'/__t11_login?id='.$roleId);
        mail_t11_assert($loginStatus===200 && $loginBody==='ready','HTTP login '.$roleId);
        $cookie='';
        foreach ($loginHeaders as $header) {
            if (preg_match('/^Set-Cookie:\s*([^;]+)/i',$header,$cookieMatch)) {
                $cookie=$cookieMatch[1];
            }
        }
        mail_t11_assert($cookie!=='','session cookie '.$roleId);
        [$status,$body]=$request('GET',$historyUrl,['Cookie: '.$cookie]);
        mail_t11_assert($status===$expectedStatus && !str_contains($body,'"items"'),
            'HTTP staff denial '.$roleId);
    }
    foreach ([1,2] as $authorizedId) {
        [$loginStatus,$loginBody,$loginHeaders]=$request('GET',$origin.'/__t11_login?id='.$authorizedId);
        mail_t11_assert($loginStatus===200,'HTTP authorized login '.$authorizedId);
        $cookie='';
        foreach ($loginHeaders as $header) {
            if (preg_match('/^Set-Cookie:\s*([^;]+)/i',$header,$cookieMatch)) $cookie=$cookieMatch[1];
        }
        mail_t11_assert($cookie!=='','authorized cookie');
        [$status,$body,$headers]=$request('GET',$historyUrl,['Cookie: '.$cookie]);
        $json=json_decode($body,true);
        mail_t11_assert($status===200 && is_array($json) && ($json['ok']??false)
            && count($json['items']??[])===1,'HTTP allowed mail '.$authorizedId);
        mail_t11_assert(!array_key_exists('recipient_digest',$json['items'][0])
            && !array_key_exists('body_text',$json['items'][0]),'HTTP projected fields');
        mail_t11_assert((bool)array_filter($headers,
            static fn($h)=>stripos($h,'Cache-Control: no-store')===0),'HTTP no-store');
        [$invalidStatus]=$request('GET',$origin.'/api/ticketing/webhook-resend.php?event_id=invalid',
            ['Cookie: '.$cookie]);
        mail_t11_assert($invalidStatus===422,'HTTP filter check');
    }
    $httpRaw=json_encode(['type'=>'email.delivered','created_at'=>gmdate('c',$now),
        'data'=>['email_id'=>'synthetic-http-2107','to'=>['safe@example.test']]],
        JSON_THROW_ON_ERROR);
    $beforeEvents=(int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_provider_events')->fetchColumn();
    $httpHeaders=$sign('msg_http_2107',$httpRaw,$now);
    $headLines=['Content-Type: application/json'];
    foreach ($httpHeaders as $key=>$value) $headLines[]=$key.': '.$value;
    [$postStatus]=$request('POST',$origin.'/api/ticketing/webhook-resend.php',$headLines,$httpRaw);
    mail_t11_assert($postStatus===200,'signed POST without staff session');
    $afterEvents=(int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_provider_events')->fetchColumn();
    mail_t11_assert($afterEvents===$beforeEvents+1,'POST created one synthetic event');
    [$postStatus,$body]=$request('POST',$origin.'/api/ticketing/webhook-resend.php',
        $headLines,$httpRaw.' ');
    mail_t11_assert($postStatus===401,'tampered POST rejected');
    mail_t11_assert((int)$pdo->query('SELECT COUNT(*) FROM ticket_mail_provider_events')->fetchColumn()
        ===$afterEvents,'invalid POST did not mutate');
} finally {
    proc_terminate($server);
    proc_close($server);
    foreach ([$fixtureConfig,$routerFile,$serverLog] as $path) if (is_file($path)) unlink($path);
    rmdir($httpDir);
}

// #1874: exercise the encrypted-secret CLI worker in the existing MariaDB T11 gate.
// Keep local fixture state isolated from the webhook and subsequent buyer-auth tests.
(static function (): void {
    require __DIR__ . '/ticketing-mail-worker-secrets.php';
})();
echo "BRVTAL ticket-mail-history integration OK.\n";

// #1889 separate synthetic MariaDB delivery snapshots.
require __DIR__ . '/mail-templates-delivery.php';
