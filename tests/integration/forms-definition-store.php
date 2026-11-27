<?php
declare(strict_types=1);
// @brvtal-ci database

function formDefinitionsCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('Form definitions fixture: '.$message);
}
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
formDefinitionsCheck(getenv('BRVTAL_INTEGRATION_TESTS')==='1' && getenv('BRVTAL_TEST_DB_HOST')==='127.0.0.1'
    && preg_match('/\Abrvtal_test[a-zA-Z0-9_]*\z/D',$prefix)===1 && strlen($prefix)<=40,'disposable loopback DB required');
$port=filter_var(getenv('BRVTAL_TEST_DB_PORT') ?: '3306',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
formDefinitionsCheck($port!==false,'valid DB port');
$user=(string)getenv('BRVTAL_TEST_DB_USER'); $pass=(string)getenv('BRVTAL_TEST_DB_PASS');
$dsn="mysql:host=127.0.0.1;port=$port;charset=utf8mb4";
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$user,$pass,$options);
$name=$prefix.'_defs_'.bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root=sys_get_temp_dir().'/brvtal-defs-'.bin2hex(random_bytes(8)); $process=null;
try {
    $db=new PDO($dsn.';dbname='.$name,$user,$pass,$options);
    $db->exec("CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY,name VARCHAR(120),email VARCHAR(190),staff_role VARCHAR(32),is_active TINYINT NOT NULL,credential_epoch BIGINT NOT NULL) ENGINE=InnoDB");
    $db->exec("INSERT INTO admins VALUES (1,'Fixture','owner@example.invalid','owner',1,1),(2,'Fixture','admin@example.invalid','admin',1,1),(3,'Fixture','super@example.invalid','superadmin',1,1),(4,'Fixture','disabled@example.invalid','owner',1,1)");
    foreach (['migration_forms_entries_01.sql','migration_forms_registry_01.sql','migration_admin_activity_01.sql'] as $file) {
        $sql=preg_replace('/^\h*--[^\r\n]*$/m','',(string)file_get_contents(__DIR__.'/../../database/'.$file));
        foreach (explode(';',(string)$sql) as $statement) if(trim($statement)!=='') $db->exec($statement);
    }
    formDefinitionsCheck(mkdir($root,0700),'fixture root');
    foreach (['api','config','sessions'] as $dir) mkdir($root.'/'.$dir,0700);
    foreach (['api/admin-form-definitions.php','config/form_definition_store.php','config/form_schema.php','config/admin_activity.php',
        'config/admin_auth.php','config/admin_session_revalidation.php','config/bootstrap.php','config/public_health.php',
        'config/db_connect_recovery.php','config/artist_collective_lifecycle.php','config/artist_collective_membership.php'] as $file) {
        formDefinitionsCheck(copy(dirname(__DIR__,2).'/'.$file,$root.'/'.$file),'copy canonical fixture dependency');
    }
    $config=['db'=>['host'=>'127.0.0.1','port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass],
        'forms'=>['admin_definition_enabled'=>true,'privacy_approved'=>false]];
    $writeConfig=static function() use ($root,&$config): void {
        file_put_contents($root.'/config/config.php','<?php return '.var_export($config,true).';',LOCK_EX);
        chmod($root.'/config/config.php',0600);
    };
    $writeConfig();
    $login=$root.'/fixture-login.php';
    file_put_contents($login,'<?php require __DIR__."/config/admin_auth.php"; brvtal_admin_login_session((int)$argv[1]); echo json_encode([session_name()."=".session_id(),brvtal_admin_csrf_token()]);');
    $sessions=[];
    foreach ([1,2,3,4] as $actor) {
        $p=proc_open([PHP_BINARY,'-d','session.save_path='.$root.'/sessions',$login,(string)$actor],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        formDefinitionsCheck(is_resource($p),'session process');
        fclose($pipes[0]); $session=json_decode(stream_get_contents($pipes[1]),true,flags:JSON_THROW_ON_ERROR);
        fclose($pipes[1]); fclose($pipes[2]);
        formDefinitionsCheck(proc_close($p)===0 && count($session)===2 && preg_match('/^BRVTAL_ADMIN=[a-zA-Z0-9,-]+$/D',$session[0])===1,'canonical session');
        $sessions[$actor]=$session;
    }
    unlink($login); $db->exec('UPDATE admins SET is_active=0 WHERE id=4');
    $socket=stream_socket_server('tcp://127.0.0.1:0');
    formDefinitionsCheck(is_resource($socket),'loopback socket');
    $address=stream_socket_get_name($socket,false); fclose($socket);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$root.'/sessions','-d','sendmail_path=/bin/false','-S',$address,'-t',$root],
        [0=>['pipe','r'],1=>['file',$root.'/http.log','a'],2=>['file',$root.'/http.log','a']],$pipes);
    formDefinitionsCheck(is_resource($process),'HTTP fixture'); fclose($pipes[0]);
    for($i=0;$i<50;$i++){
        $probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.2);
        if(is_resource($probe)){fclose($probe);break;} usleep(100000);
    }
    formDefinitionsCheck($i<50,'loopback ready');
    $url='http://'.$address.'/api/admin-form-definitions.php';
    $call=static function(?array $body,int $status=200,int $actor=1,bool $csrf=true,array $query=[]) use($url,$sessions): array {
        $headers=['Content-Type: application/json'];
        if($actor!==0){$headers[]='Cookie: '.$sessions[$actor][0]; if($csrf)$headers[]='X-CSRF-Token: '.$sessions[$actor][1];}
        $http=['method'=>$body===null?'GET':'POST','ignore_errors'=>true,'timeout'=>5,'header'=>implode("\r\n",$headers)];
        if($body!==null)$http['content']=json_encode($body,JSON_THROW_ON_ERROR);
        $raw=file_get_contents($url.($query?'?'.http_build_query($query):''),false,stream_context_create(['http'=>$http]));
        $responseHeaders=http_get_last_response_headers();
        formDefinitionsCheck(preg_match('/^HTTP\/\S+ '.$status.' /',$responseHeaders[0]??'')===1,'HTTP status '.$status);
        formDefinitionsCheck(stripos(implode("\n",$responseHeaders),'Cache-Control: no-store')!==false,'no-store');
        return json_decode((string)$raw,true,flags:JSON_THROW_ON_ERROR);
    };
    $draft=['title'=>'Fixture definition','fields'=>[],'privacy'=>null,'delivery'=>['actions'=>[]]];
    $create=['action'=>'create','slug'=>'fixture-form','draft'=>$draft];
    $call($create,401,0); $call($create,403,2); $call($create,401,4); $call($create,403);
    $config['forms']['privacy_approved']=true; $writeConfig();
    $call($create,419,1,false);
    $row=$call($create)['data']; $key=$row['form_key']; $id=$row['public_id'];
    $call($create,409);
    $call(['action'=>'publish','form_key'=>$key,'revision'=>1],422);
    $draft['fields']=[['id'=>'message','order'=>0,'label'=>'Fixture message','type'=>'text','required'=>true,'max_length'=>100],
        ['id'=>'accept_notice','order'=>1,'label'=>'Fixture consent','type'=>'consent','required'=>true,'purpose'=>'privacy','notice_version'=>'fixture-v1']];
    $draft['privacy']=['notice_version'=>'fixture-v1','notice_text'=>'Fixture privacy notice','purpose_key'=>'fixture','retention_policy_key'=>'fixture-only'];
    $draft['delivery']=['actions'=>['form_entry_received']];
    $save=['action'=>'save','form_key'=>$key,'revision'=>1,'slug'=>'fixture-form','draft'=>$draft];
    $bad=$save; $bad['draft']['delivery']['recipient']='external@example.invalid'; $call($bad,422);
    $row=$call($save)['data']; formDefinitionsCheck($row['revision']===2 && $row['published_version_number']===null,'draft saved');
    $publish=['action'=>'publish','form_key'=>$key,'revision'=>2];
    $row=$call($publish)['data']; formDefinitionsCheck($row['published_version_number']===1 && $row['state']==='active','v1 published');
    $snapshot=static fn()=>[$db->query('SELECT * FROM form_definition_versions ORDER BY version_number')->fetchAll(PDO::FETCH_ASSOC),
        $db->query('SELECT * FROM form_publication_policies ORDER BY version_number')->fetchAll(PDO::FETCH_ASSOC)];
    $v1=$snapshot(); $digest=$v1[0][0]['definition_sha256'];
    $db->prepare("INSERT INTO form_entries (entry_id,form_key,version_number,definition_sha256,idempotency_digest,payload_json,created_at_utc,purpose_key,notice_version,retention_policy_key,retention_until_utc,state) VALUES (?,?,1,?,?,?,UTC_TIMESTAMP(6),'fixture','fixture-v1','fixture-only',DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 DAY),'received')")
        ->execute([random_bytes(16),$key,$digest,random_bytes(32),'{"message":"synthetic","accept_notice":true}']);
    $entry=$db->query('SELECT * FROM form_entries')->fetch(PDO::FETCH_ASSOC);
    $read=['form_key'=>$key]; $first=$call(null,200,1,true,$read)['data']; $second=$call(null,200,3,true,$read)['data'];
    $draft['title']='Fixture revision two'; $draft['fields'][0]['label']='Updated fixture';
    $save=['action'=>'save','form_key'=>$key,'revision'=>$first['revision'],'slug'=>'renamed-fixture','draft'=>$draft];
    $row=$call($save)['data']; $call(array_replace($save,['revision'=>$second['revision']]),409,3);
    formDefinitionsCheck($row['public_id']===$id && $row['slug']==='renamed-fixture' && $row['published_version_number']===1 && $snapshot()===$v1,'stable identity and immutable live v1');
    $publish['revision']=$row['revision']; $row=$call($publish)['data'];
    $v2=$snapshot(); formDefinitionsCheck($row['published_version_number']===2 && count($v2[0])===2 && count($v2[1])===2
        && $v2[0][0]===$v1[0][0] && $v2[1][0]===$v1[1][0] && $db->query('SELECT * FROM form_entries')->fetch(PDO::FETCH_ASSOC)===$entry,'v2 and historical entry preserved');
    $row=$call(['action'=>'disable','form_key'=>$key,'revision'=>$row['revision']])['data'];
    formDefinitionsCheck($row['state']==='disabled' && $row['published_version_number']===2,'disable preserves history');
    $copy=$call(['action'=>'duplicate','form_key'=>$key,'revision'=>$row['revision'],'slug'=>'fixture-copy'])['data'];
    formDefinitionsCheck($copy['form_key']!==$key && $copy['public_id']!==$id && $copy['state']==='draft'
        && $copy['published_version_number']===null && $copy['draft']===$row['draft'] && $snapshot()===$v2
        && (int)$db->query('SELECT COUNT(*) FROM form_entries')->fetchColumn()===1,'duplicate only definition');
    $beforeAudit=(int)$db->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn();
    $db->exec("CREATE TRIGGER fail_fixture_audit BEFORE INSERT ON admin_activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture'");
    $call(['action'=>'publish','form_key'=>$key,'revision'=>$row['revision']],503);
    $db->exec('DROP TRIGGER fail_fixture_audit');
    formDefinitionsCheck($call(null,200,1,true,$read)['data']===$row && $snapshot()===$v2
        && (int)$db->query('SELECT COUNT(*) FROM admin_activity_log')->fetchColumn()===$beforeAudit,'audit failure rolls back version/policy/pointer');
    formDefinitionsCheck($beforeAudit===7 && (int)$db->query("SELECT COUNT(*) FROM admin_activity_log WHERE before_json IS NULL AND after_json IS NULL AND meta_json NOT LIKE '%Fixture%'")->fetchColumn()===7,'minimal audit without definition copy');
    echo "BRVTAL_MARKED_DATABASE_PASS: forms-definition-store.php\n";
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    if(is_dir($root)){
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($iterator as $file){if($file->isDir()) rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($root);
    }
    $server->exec('DROP DATABASE '.$name);
}
