<?php
declare(strict_types=1);
// @brvtal-ci database
require_once __DIR__.'/../../config/form_entries.php';
require_once __DIR__.'/../../config/public_abuse_guard.php';
function submitCheck(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
submitCheck(getenv('BRVTAL_INTEGRATION_TESTS')==='1' && getenv('BRVTAL_TEST_DB_HOST')==='127.0.0.1'
    && preg_match('/\Abrvtal_test[a-zA-Z0-9_]*\z/D',$prefix) && strlen($prefix)<=40,'isolated DB');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user=(string)getenv('BRVTAL_TEST_DB_USER'); $pass=(string)getenv('BRVTAL_TEST_DB_PASS');
$dsn="mysql:host=127.0.0.1;port=$port;charset=utf8mb4";
$server=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name=$prefix.'_submit_'.bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
$root=sys_get_temp_dir().'/brvtal-submit-'.bin2hex(random_bytes(8)); $proc=null;
try {
    $db=new PDO($dsn.';dbname='.$name,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    foreach (['migration_forms_entries_01.sql','migration_forms_registry_01.sql'] as $file) {
        $sql=preg_replace('/^\h*--[^\r\n]*$/m','',(string)file_get_contents(__DIR__.'/../../database/'.$file));
        foreach(explode(';',$sql) as $stmt) if(trim($stmt)!=='') $db->exec($stmt);
    }
    $id=str_repeat('a',32); $other=str_repeat('b',32); $intent=str_repeat('c',32);
    $fields=[['id'=>'message','order'=>0,'label'=>'Mensaje','type'=>'text','required'=>true,'max_length'=>100],
        ['id'=>'consent_ok','order'=>1,'label'=>'Consentimiento','type'=>'consent','required'=>true,'purpose'=>'privacy','notice_version'=>'fixture-v1']];
    $policy=['notice_version'=>'fixture-v1','notice_text'=>'Aviso sintético','purpose_key'=>'fixture','retention_policy_key'=>'fixture','form_title'=>'Fixture'];
    foreach (['fixture'=>$id,'other'=>$other] as $form=>$public) {
        foreach ([1,2] as $version) {
            $json=json_encode(['form_key'=>$form,'version_number'=>$version,'fields'=>$fields],JSON_THROW_ON_ERROR);
            $digest=hash('sha256',$json,true);
            $db->prepare('INSERT INTO form_definition_versions VALUES (?,?,?,?,UTC_TIMESTAMP(6))')->execute([$form,$version,$digest,$json]);
            $db->prepare('INSERT INTO form_publication_policies VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))')
                ->execute([$form,$version,$digest,'fixture-v1',json_encode($policy), '{"actions":["form_entry_received"]}']);
        }
        $db->prepare("INSERT INTO form_definition_registry(form_key,public_id,slug,draft_json,state,published_version_number,created_at_utc,updated_at_utc)
            VALUES (?,UNHEX(?),?,'{}','active',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")->execute([$form,$public,$form]);
    }
    $approved=['version_number'=>1,'notice_version'=>'fixture-v1','purpose_key'=>'fixture','retention_policy_key'=>'fixture','retention_days'=>3];
    $config=['app'=>['base_url'=>'https://forms.example.invalid'],'security'=>['csrf_key'=>str_repeat('fixture-hmac-',4)],
        'db'=>['host'=>'127.0.0.1','port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass],
        'forms'=>['privacy_approved'=>true,'public_submit_enabled'=>true,'public_submit_policies'=>[$id=>$approved,$other=>$approved]]];
    mkdir($root,0700); foreach (['api','config'] as $dir) mkdir($root.'/'.$dir,0700);
    foreach (['api/forms.php','config/bootstrap.php','config/public_health.php','config/db_connect_recovery.php',
        'config/form_entries.php','config/form_schema.php','config/public_abuse_guard.php','config/public_contact.php',
        'config/password_rate_limit.php','config/rate_limit_store.php'] as $file) submitCheck(copy(dirname(__DIR__,2).'/'.$file,$root.'/'.$file),'fixture copy');
    file_put_contents($root.'/config/config.php','<?php return '.var_export($config,true).';',LOCK_EX); chmod($root.'/config/config.php',0600);
    file_put_contents($root.'/router.php','<?php $_SERVER["HTTPS"]="on"; require __DIR__."/api/forms.php";');
    $socket=stream_socket_server('tcp://127.0.0.1:0'); submitCheck(is_resource($socket),'socket');
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $proc=proc_open([PHP_BINARY,'-d','sendmail_path=/bin/false','-S',$address,$root.'/router.php'],
        [0=>['pipe','r'],1=>['file',$root.'/http.log','a'],2=>['file',$root.'/http.log','a']],$pipes);
    submitCheck(is_resource($proc),'HTTP fixture'); fclose($pipes[0]);
    for($i=0;$i<50;$i++){ $probe=@stream_socket_client('tcp://'.$address,$eno,$err,0.2); if(is_resource($probe)){fclose($probe);break;} usleep(100000); }
    submitCheck($i<50,'HTTP ready');
    $http=static function(string $method,string $public,int $version,string $key,int $status,string $raw='',array $extra=[])use($address):array{
        $headers=array_replace(['Host'=>'forms.example.invalid','Origin'=>'https://forms.example.invalid','Content-Type'=>'application/json','Idempotency-Key'=>$key],$extra);
        $lines=[];foreach($headers as $h=>$value)$lines[]=$h.': '.$value;
        $body=file_get_contents('http://'.$address.'/api/forms.php?public_id='.$public.'&version='.$version,false,
            stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$lines),'content'=>$raw,'ignore_errors'=>true,'timeout'=>10]]));
        $response=http_get_last_response_headers();
        submitCheck(preg_match('/^HTTP\/\S+ '.$status.' /',$response[0]??'')===1,'HTTP '.$status);
        if($status===429)submitCheck((bool)preg_grep('/^Retry-After: [1-9]/i',$response),'retry-after');
        return json_decode((string)$body,true,flags:JSON_THROW_ON_ERROR);
    };
    $payload='{"message":"Sintético","consent_ok":true}';
    $challenge=static function(string $key,int $at)use($config,$id):array{
        $c=brvtal_public_abuse_challenge($config,'forms',$at,$id.'|1|'.$key);
        preg_match('/^(\d+) \+ (\d+) = \?$/',$c['question'],$m);
        return ['X-Form-Captcha-Token'=>$c['captcha_token'],'X-Form-Captcha-Answer'=>(string)((int)$m[1]+(int)$m[2])];
    };
    $issued=$http('GET',$id,1,$intent,200)['data'];
    submitCheck(isset($issued['captcha_token'])&&!isset($issued['form_key']),'public challenge only');
    $h=$challenge($intent,time()-3);
    $http('POST',$id,1,$intent,403,$payload,$h+['Origin'=>'https://other.invalid']);
    $http('POST',$id,1,$intent,422,'{"message":"a","message":"b","consent_ok":true}',$h);
    foreach (['<script>alert(1)</script>','https://example.invalid','javascript:alert(1)'] as $bad) $http('POST',$id,1,$intent,422,json_encode(['message'=>$bad,'consent_ok'=>true],JSON_THROW_ON_ERROR),$h);
    $http('POST',$other,1,$intent,422,$payload,$h);
    $http('POST',$id,1,str_repeat('d',32),422,$payload,$h);
    $http('POST',$id,1,$intent,422,$payload,$challenge($intent,time()-601));
    $http('POST',$id,1,$intent,422,$payload,$h+['X-Form-Honeypot'=>'robot']);
    $http('POST',$id,1,$intent,413,str_repeat('a',65537),$h);
    submitCheck($http('POST',$id,1,$intent,200,$payload,$h)===['ok'=>true,'message'=>'FORM_RECEIVED'],'success no ID');
    $http('POST',$id,1,$intent,200,$payload,$h);
    $http('POST',$id,1,$intent,409,'{"message":"Otro","consent_ok":true}',$h);
    $worker= 'require $argv[1]; $c=require $argv[2]; $d=$c["db"]; $p=new PDO("mysql:host=127.0.0.1;port=".$d["port"].";dbname=".$d["name"],$d["user"],$d["pass"],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); brvtalFormsSubmit($p,$c,$argv[3],1,$argv[4],$argv[5]);';
    $workers=[]; for($i=0;$i<2;$i++){$p=proc_open([PHP_BINARY,'-r',$worker,$root.'/config/form_entries.php',$root.'/config/config.php',$id,str_repeat('e',32),$payload],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);submitCheck(is_resource($p),'worker');$workers[]=$p;}
    foreach($workers as $p)submitCheck(proc_close($p)===0,'concurrent same intent');
    $db->exec("CREATE TRIGGER submit_fail BEFORE INSERT ON form_notification_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
    $fail=str_repeat('f',32); $http('POST',$id,1,$fail,503,$payload,$challenge($fail,time()-3));
    $db->exec('DROP TRIGGER submit_fail');
    submitCheck((int)$db->query('SELECT COUNT(*) FROM form_entries')->fetchColumn()===2
        && (int)$db->query('SELECT COUNT(*) FROM form_notification_intents')->fetchColumn()===2,'atomic unique entry and intent');
    $db->exec("UPDATE form_definition_registry SET published_version_number=2 WHERE form_key='fixture'");
    $http('POST',$id,1,$intent,409,$payload,$h); $http('GET',$id,2,$intent,503);
    $db->exec("UPDATE form_definition_registry SET state='disabled' WHERE form_key='fixture'");
    $http('POST',$id,1,$intent,409,$payload,$h);
    $limited=false;for($i=0;$i<40;$i++){ $rate=brvtalFormsRate($config,$id,$root.'/burst.json',1800000000);if(!$rate['allowed']){$limited=true;submitCheck($rate['retry_after']>0,'rate retry');break;} }
    submitCheck($limited&&!brvtalFormsCaptureEnabled([]),'burst and default off');
    echo "BRVTAL_MARKED_DATABASE_PASS: forms-public-submit.php\n";
} finally {
    if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}
    $server->exec('DROP DATABASE '.$name);
    if(is_dir($root))exec('rm -rf -- '.escapeshellarg($root));
}
