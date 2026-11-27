<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/forms_admin.php';

function formsAdminCheck(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('Forms admin fixture: '.$message);
}
function formsAdminHttp(string $url,int $expected,string $cookie='',string $method='GET'): array {
    $ctx=stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>5,
        'header'=>$cookie===''?'':"Cookie: ".$cookie."\r\n"]]);
    $raw=file_get_contents($url,false,$ctx);
    $headers=http_get_last_response_headers();
    formsAdminCheck(preg_match('/^HTTP\/\S+ '.$expected.' /',$headers[0] ?? '')===1,'HTTP status '.$expected);
    formsAdminCheck(stripos(implode("\n",$headers),'Cache-Control: no-store')!==false,'no-store');
    return json_decode((string)$raw,true,flags:JSON_THROW_ON_ERROR);
}
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1') throw new RuntimeException('Disposable MariaDB required');
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
formsAdminCheck(preg_match('/\Abrvtal_test[a-zA-Z0-9_]*\z/',$prefix)===1,'test namespace');
$host=(string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user=(string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass=(string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$name=$prefix.'_forms_read_'.bin2hex(random_bytes(4));
$dsn="mysql:host=$host;port=$port;charset=utf8mb4";
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$user,$pass,$options);
$server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root=sys_get_temp_dir().'/brvtal-forms-read-'.bin2hex(random_bytes(8));
$process=null;
try {
    $pdo=new PDO($dsn.';dbname='.$name,$user,$pass,$options);
    $sql=(string)file_get_contents(__DIR__.'/../../database/migration_forms_entries_01.sql');
    foreach (preg_split('/;\s*(?=CREATE TABLE|$)/i',$sql) ?: [] as $part) if(trim($part)!=='') $pdo->exec($part);
    $pdo->exec("CREATE TABLE admins (id INT PRIMARY KEY,staff_role VARCHAR(32) NOT NULL,is_active TINYINT NOT NULL,credential_epoch BIGINT NOT NULL)");
    $pdo->exec("INSERT INTO admins VALUES (1,'owner',1,1),(2,'superadmin',1,1),(3,'admin',1,1),(4,'door',1,1),(5,'buyer',1,1),(6,'owner',1,1)");
    $versions=[];
    $insert=$pdo->prepare('INSERT INTO form_definition_versions VALUES (?,?,?,?,?)');
    foreach ([['contact',1,'Mensaje antiguo'],['contact',2,'Mensaje nuevo'],['feedback',1,'Comentario'],['empty_form',1,'Vacío']] as [$form,$version,$label]) {
        $definition=json_encode(['fields'=>[['id'=>'message','type'=>'textarea','label'=>$label]]],JSON_THROW_ON_ERROR);
        $digest=hash('sha256',$definition,true);
        $insert->execute([$form,$version,$digest,$definition,'2026-11-01 00:00:00']);
        $versions[$form.$version]=$digest;
    }
    $entry=$pdo->prepare('INSERT INTO form_entries (entry_id,form_key,version_number,definition_sha256,idempotency_digest,payload_json,created_at_utc,purpose_key,notice_version,retention_policy_key,retention_until_utc,state,redacted_at_utc) VALUES (UNHEX(?),?,?,?,?,?,?,?,?,?,?,?,?)');
    $intent=$pdo->prepare("INSERT INTO form_notification_intents VALUES (UNHEX(?),'contact_notify',?,?)");
    for($i=1;$i<=64;$i++){
        $form=$i<=60?'contact':'feedback'; $version=$i<=30?1:($form==='contact'?2:1);
        $id=sprintf('%032x',$i); $date=$i===1?'2026-11-10 04:59:59.999999':($i===2?'2026-11-11 05:00:00':'2026-11-10 05:00:00');
        $state=$i===3?'redacted':($i%2===0?'reviewed':'received');
        $payload=$state==='redacted'?null:json_encode(['message'=>'<script>synthetic fixture</script>','token'=>'not-for-disclosure'],JSON_THROW_ON_ERROR);
        $entry->execute([$id,$form,$version,$versions[$form.$version],hash('sha256',$id,true),$payload,$date,
            'fixture','fixture-v1','fixture-only','2026-12-01 00:00:00',$state,$state==='redacted'?'2026-11-12 00:00:00':null]);
        $intent->execute([$id,$i===3?'suppressed':($i%2===0?'enqueued':'pending'),$date]);
    }
    $snapshot=static fn()=>[
        $pdo->query('SELECT * FROM form_entries ORDER BY entry_id')->fetchAll(PDO::FETCH_ASSOC),
        $pdo->query('SELECT * FROM form_notification_intents ORDER BY entry_id,action_key')->fetchAll(PDO::FETCH_ASSOC),
    ];
    $before=$snapshot();
    formsAdminCheck(!brvtalFormsAdminEnabled([])&&!brvtalFormsAdminEnabled(['forms'=>['admin_read_enabled'=>true]]),'privacy default closed');
    // Use canonical PHP session login/revalidation in an isolated loopback root, never production credentials.
    formsAdminCheck(mkdir($root,0700),'create fixture root');
    foreach (['api','config','sessions'] as $dir) mkdir($root.'/'.$dir,0700);
    foreach (['api/admin-forms.php','config/forms_admin.php','config/admin_auth.php','config/admin_session_revalidation.php',
        'config/bootstrap.php','config/public_health.php','config/db_connect_recovery.php'] as $file) {
        formsAdminCheck(copy(dirname(__DIR__,2).'/'.$file,$root.'/'.$file),'copy fixture file');
    }
    $config=['db'=>['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass],
        'forms'=>['admin_read_enabled'=>true,'privacy_approved'=>true]];
    $configFile=$root.'/config/config.php';
    file_put_contents($configFile,'<?php return '.var_export($config,true).';',LOCK_EX);
    chmod($configFile,0600);
    $login=$root.'/fixture-login.php';
    file_put_contents($login, '<?php require __DIR__."/config/admin_auth.php"; brvtal_admin_login_session((int)$argv[1]); echo session_name()."=".session_id();');
    $cookies=[];
    for($i=1;$i<=6;$i++){
        $p=proc_open([PHP_BINARY,'-d','session.save_path='.$root.'/sessions',$login,(string)$i],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        formsAdminCheck(is_resource($p),'session fixture process');
        fclose($pipes[0]); $cookies[$i]=stream_get_contents($pipes[1]); fclose($pipes[1]);
        fclose($pipes[2]); formsAdminCheck(proc_close($p)===0 && preg_match('/^BRVTAL_ADMIN=[a-zA-Z0-9,-]+$/D',$cookies[$i])===1,'canonical session');
    }
    unlink($login);
    $pdo->exec('UPDATE admins SET is_active=0 WHERE id=6');
    $socket=stream_socket_server('tcp://127.0.0.1:0');
    formsAdminCheck(is_resource($socket),'loopback socket');
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$root.'/sessions','-d','sendmail_path=/bin/false','-d','session.cache_limiter=private','-S',$address,'-t',$root],
        [0=>['pipe','r'],1=>['file',$root.'/http.log','a'],2=>['file',$root.'/http.log','a']],$pipes);
    formsAdminCheck(is_resource($process),'HTTP fixture');
    fclose($pipes[0]);
    for($i=0;$i<50;$i++){
        $probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.2);
        if(is_resource($probe)){fclose($probe);break;} usleep(100000);
    }
    formsAdminCheck($i<50,'loopback ready');
    $url='http://'.$address.'/api/admin-forms.php';
    $get=static fn(array $q,int $status=200,int $actor=1)=>formsAdminHttp($url.'?'.http_build_query($q),$status,$actor===0?'':$cookies[$actor]);
    $id=static fn(int $i):string=>sprintf('%032x',$i);
    $detail=['view'=>'detail','form_key'=>'contact','entry_id'=>$id(1)];
    $get($detail,401,0); $get($detail,401,6);
    foreach([3,4,5] as $role) $get($detail,403,$role);
    $catalog=$get([],200,2)['data']['items'];
    formsAdminCheck(count($catalog)===3 && (int)$catalog[0]['total']===60 && (int)$catalog[0]['new_count']===29
        && (int)$catalog[0]['latest_version']===2 && (int)$catalog[1]['total']===0 && (int)$catalog[2]['total']===4,'actual catalog counts and empty form');
    $query=['view'=>'list','form_key'=>'contact','limit'=>'7'];
    $seen=[];
    do {
        $page=$get($query)['data'];
        formsAdminCheck($page['total']===60,'scope total');
        formsAdminCheck(count($page['items'])<=7 && (!$page['has_more'] || count($page['items'])===7),'requested page size');
        foreach($page['items'] as $item){
            formsAdminCheck($item['form_key']==='contact'&&!array_key_exists('payload_json',$item)&&!array_key_exists('fields',$item)&&!isset($seen[$item['entry_id']]),'minimal unique list');
            $seen[$item['entry_id']]=true;
        }
        $query['cursor']=$page['next_cursor'];
    } while($page['has_more']);
    formsAdminCheck(count($seen)===60,'stable cursor with tied timestamps');
    $received=['view'=>'list','form_key'=>'contact','state'=>'received','limit'=>'2'];
    $first=$get($received)['data'];
    $anchor=$first['next_cursor'];
    formsAdminCheck(is_string($anchor),'received cursor');
    $change=$pdo->prepare('UPDATE form_entries SET state=? WHERE entry_id=UNHEX(?)');
    $change->execute(['reviewed',$anchor]);
    try {
        $next=$get($received+['cursor'=>$anchor])['data'];
        formsAdminCheck(count($next['items'])===2 && $next['total']===28,'cursor survives reviewed anchor');
    } finally {
        $change->execute(['received',$anchor]);
    }
    $filtered=$get(['view'=>'list','form_key'=>'contact','from'=>'2026-11-10','to'=>'2026-11-10','state'=>'reviewed'])['data'];
    formsAdminCheck($filtered['total']===29,'Bogota half-open UTC range plus state');
    $result=$get($detail)['data'];
    formsAdminCheck($result['version_number']==1 && $result['fields'][0]['label']==='Mensaje antiguo'
        && $result['fields'][0]['value']==='<script>synthetic fixture</script>' && count($result['fields'])===1
        && $result['notifications'][0]['state']==='pending' && !array_key_exists('payload_json',$result)&&!array_key_exists('definition_json',$result),'historical projected detail and durable intent');
    $result=$get(array_replace($detail,['entry_id'=>$id(4)]))['data'];
    formsAdminCheck($result['notifications'][0]['state']==='enqueued','persisted synthetic enqueued intent; handoff belongs to #2066');
    $result=$get(array_replace($detail,['entry_id'=>$id(3)]))['data'];
    formsAdminCheck($result['fields'][0]['value']===null && $result['notifications'][0]['state']==='suppressed','redacted detail');
    $get(array_replace($detail,['form_key'=>'feedback']),404);
    $get(array_replace($detail,['entry_id'=>$id(999)]),404);
    foreach([['cursor'=>$id(61)],['cursor'=>'bad'],['limit'=>'101'],['state'=>'unknown'],['from'=>'2026-02-30'],['from'=>'2026-11-12','to'=>'2026-11-10'],['query'=>'fixture'],['form_key'=>['contact']]] as $bad) {
        $get(array_replace(['view'=>'list','form_key'=>'contact'],$bad),422);
    }
    formsAdminHttp($url,405,$cookies[1],'POST');
    $pdo->exec("UPDATE admins SET staff_role='admin' WHERE id=1");
    $get($detail,403);
    $pdo->exec("UPDATE admins SET staff_role='owner' WHERE id=1");
    $config['forms']['privacy_approved']=false;
    file_put_contents($configFile,'<?php return '.var_export($config,true).';',LOCK_EX);
    $get($detail,503);
    formsAdminCheck($snapshot()===$before,'all stored rows unchanged by API reads');
    formsAdminCheck((int)$pdo->query('SELECT COUNT(*) FROM form_entries')->fetchColumn()===64
        && (int)$pdo->query('SELECT COUNT(*) FROM form_notification_intents')->fetchColumn()===64,'read-only fixture');
    echo "BRVTAL forms admin synthetic MariaDB and canonical HTTP auth passed.\n";
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    if(is_dir($root)){
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $file){if($file->isDir()) rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($root);
    }
    $server->exec('DROP DATABASE '.$name);
}
