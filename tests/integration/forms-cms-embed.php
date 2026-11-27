<?php
declare(strict_types=1);
// @brvtal-ci database
function cmsCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('CMS fixture: '.$message, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,1)[0]['line']);
}
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
cmsCheck(getenv('BRVTAL_INTEGRATION_TESTS')==='1' && getenv('BRVTAL_TEST_DB_HOST')==='127.0.0.1'
    && preg_match('/\Abrvtal_test[a-zA-Z0-9_]*\z/D',$prefix)===1 && strlen($prefix)<=40,'disposable loopback required');
$port=(int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$user=(string)getenv('BRVTAL_TEST_DB_USER'); $pass=(string)getenv('BRVTAL_TEST_DB_PASS');
$dsn="mysql:host=127.0.0.1;port=$port;charset=utf8mb4";
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$user,$pass,$options);
$name=$prefix.'_cms_'.bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root=sys_get_temp_dir().'/brvtal-cms-'.bin2hex(random_bytes(8)); $process=null;
try {
    $db=new PDO($dsn.';dbname='.$name,$user,$pass,$options);
    foreach (['schema.sql','migration_admin_activity_01.sql','migration_totp_foundation.sql','migration_forms_entries_01.sql','migration_forms_registry_01.sql'] as $file) {
        $sql=(string)file_get_contents(__DIR__.'/../../database/'.$file);
        if ($file==='schema.sql') {
            $sql=preg_replace('/\ACREATE DATABASE IF NOT EXISTS brvtal[^;]*;\s*USE brvtal;\s*/','',$sql,1,$removed);
            cmsCheck($removed===1,'strip canonical DB selector');
        }
        $sql=preg_replace('/^\h*--[^\r\n]*$/m','',(string)$sql);
        foreach(explode(';',(string)$sql) as $statement) if(trim($statement)!=='') $db->exec($statement);
    }
    $db->exec("INSERT INTO settings(setting_key,setting_value,is_json) VALUES ('indexnow','{\"enabled\":false}',1)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_json=1");
    $db->prepare("INSERT INTO admins(email,password_hash,name,staff_role) VALUES (?,?,?,'owner')")
        ->execute(['cms@example.invalid',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),'Synthetic owner']);
    $actor=(int)$db->lastInsertId();
    $id=str_repeat('a',32); $draftId=str_repeat('b',32); $badId=str_repeat('c',32);
    $fields=[['id'=>'message','order'=>0,'label'=>'Mensaje sintético','type'=>'text','required'=>true,'max_length'=>100],
        ['id'=>'privacy_ok','order'=>1,'label'=>'Consentimiento sintético','type'=>'consent','required'=>true,'purpose'=>'privacy','notice_version'=>'fixture-v1']];
    $policy=['notice_version'=>'fixture-v1','notice_text'=>'Aviso sintético','purpose_key'=>'fixture','retention_policy_key'=>'fixture','form_title'=>'Formulario sintético'];
    foreach ([['cms_fixture',$id,$fields],['cms_bad',$badId,array_replace($fields,[0=>array_replace($fields[0],['label'=>'<img src=x onerror=alert(1)>'])])]] as [$key,$publicId,$copy]) {
        $json=json_encode(['form_key'=>$key,'version_number'=>1,'fields'=>$copy],JSON_THROW_ON_ERROR);
        $digest=hash('sha256',$json,true);
        $db->prepare('INSERT INTO form_definition_versions VALUES (?,?,?,?,UTC_TIMESTAMP(6))')->execute([$key,1,$digest,$json]);
        $db->prepare('INSERT INTO form_publication_policies VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))')
            ->execute([$key,1,$digest,'fixture-v1',json_encode($policy,JSON_THROW_ON_ERROR),'{"actions":[]}']);
        $db->prepare("INSERT INTO form_definition_registry(form_key,public_id,slug,draft_json,state,published_version_number,created_at_utc,updated_at_utc)
            VALUES (?,UNHEX(?),?,'{}','active',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")->execute([$key,$publicId,str_replace('_','-',$key)]);
    }
    $db->prepare("INSERT INTO form_definition_registry(form_key,public_id,slug,draft_json,created_at_utc,updated_at_utc)
        VALUES ('cms_draft',UNHEX(?),'cms-draft','{}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")->execute([$draftId]);
    cmsCheck(mkdir($root,0700),'fixture root');
    exec('git -C '.escapeshellarg(dirname(__DIR__,2)).' archive --format=tar --output='.escapeshellarg($root.'/source.tar').' HEAD config api index.php',$out,$code);
    cmsCheck($code===0,'exact checkout source archive');
    exec('tar -xf '.escapeshellarg($root.'/source.tar').' -C '.escapeshellarg($root),$out,$code);
    cmsCheck($code===0&&!is_file($root.'/config/config.php'),'isolated source');
    foreach(['sessions','storage/logs','storage/rate_limits'] as $dir) mkdir($root.'/'.$dir,0700,true);
    $socket=stream_socket_server('tcp://127.0.0.1:0'); cmsCheck(is_resource($socket),'loopback socket');
    $address=stream_socket_get_name($socket,false); fclose($socket); $origin='http://'.$address;
    $config=['app'=>['base_url'=>$origin],'db'=>['host'=>'127.0.0.1','port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass],
        'forms'=>['privacy_approved'=>false,'admin_definition_enabled'=>false,'admin_read_enabled'=>false,'admin_manage_enabled'=>false]];
    file_put_contents($root.'/config/config.php','<?php return '.var_export($config,true).';',LOCK_EX);
    chmod($root.'/config/config.php',0600);
    $login=$root.'/fixture-login.php';
    file_put_contents($login,'<?php require __DIR__."/config/admin_auth.php"; brvtal_admin_login_session((int)$argv[1]); echo json_encode([session_name()."=".session_id(),brvtal_admin_csrf_token()]);');
    $p=proc_open([PHP_BINARY,'-d','session.save_path='.$root.'/sessions',$login,(string)$actor],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    cmsCheck(is_resource($p),'fixture session'); fclose($pipes[0]); $session=json_decode(stream_get_contents($pipes[1]),true,flags:JSON_THROW_ON_ERROR);
    fclose($pipes[1]); fclose($pipes[2]); cmsCheck(proc_close($p)===0&&count($session)===2,'canonical session'); unlink($login);
    $process=proc_open([PHP_BINARY,'-d','session.save_path='.$root.'/sessions','-d','sendmail_path=/bin/false','-S',$address,'-t',$root],
        [0=>['pipe','r'],1=>['file',$root.'/http.log','a'],2=>['file',$root.'/http.log','a']],$pipes);
    cmsCheck(is_resource($process),'HTTP fixture'); fclose($pipes[0]);
    for($i=0;$i<50;$i++){ $probe=@stream_socket_client('tcp://'.$address,$errno,$errstr,0.2); if(is_resource($probe)){fclose($probe);break;} usleep(100000); }
    cmsCheck($i<50,'HTTP ready');
    $http=static function(string $path,int $status,string $method='GET',?array $data=null,bool $auth=true)use($origin,$session):string{
        $headers=$auth?['Cookie: '.$session[0],'X-CSRF-Token: '.$session[1]]:[];
        if($data!==null)$headers[]='Content-Type: application/json';
        $raw=file_get_contents($origin.$path,false,stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),
            'content'=>$data===null?'':json_encode($data,JSON_THROW_ON_ERROR),'ignore_errors'=>true,'timeout'=>10,'follow_location'=>0]]));
        cmsCheck(preg_match('/^HTTP\/\S+ '.$status.' /',http_get_last_response_headers()[0]??'')===1,'HTTP '.$status.' '.$path);
        return (string)$raw;
    };
    $make=static fn(string $slug,string $ref,string $status='published'):array=>['title'=>'Fixture '.$slug,'slug'=>$slug,'locale'=>'es','status'=>$status,
        'content_json'=>json_encode(['blocks'=>[['type'=>'heading','content'=>'Before'],['type'=>'form_ref','public_id'=>$ref],
            ['type'=>'text','content'=>'After <img src=x onerror=alert(1)>']]],JSON_THROW_ON_ERROR)];
    $api='/api/index.php/pages';
    $http($api,401,'POST',$make('cms-denied',$id),false);
    foreach([$draftId,$badId,str_repeat('d',32)] as $invalid) {
        $raw=$http($api,422,'POST',$make('cms-invalid',$invalid));
        cmsCheck(json_decode($raw,true)['error']==='PAGE_FORM_REF_UNAVAILABLE','publication fails closed');
    }
    $http($api,422,'POST',array_replace($make('cms-wrong-locale',$id),['locale'=>'en'])); $http($api,201,'POST',$make('cms-valid',$id));
    $draft=json_decode($http($api,201,'POST',$make('cms-unpublished',$draftId,'draft')),true,flags:JSON_THROW_ON_ERROR);
    $draftPage=(int)$draft['id'];
    $http($api.'/'.$draftPage,422,'PUT',['status'=>'published']);
    $http('/api/bulk-actions.php',422,'POST',['resource'=>'pages','ids'=>[$draftPage],'status'=>'published']);
    cmsCheck($db->query("SELECT status FROM pages WHERE id=".$draftPage)->fetchColumn()==='draft','invalid batch and edit rollback');
    $legacy=['title'=>'Legacy fixture','slug'=>'cms-legacy','locale'=>'en','status'=>'published','content_json'=>'{"text":"Legacy text"}'];
    $http($api,201,'POST',$legacy); $db->exec("UPDATE pages SET locale='es' WHERE slug='cms-legacy'"); // historical public fixture
    cmsCheck(str_contains($http('/index.php?type=pages&slug=cms-legacy',200,'GET',null,false),'Legacy text'),'legacy public HTTP');
    $url='/index.php?type=pages&slug=cms-valid'; $html=$http($url,200,'GET',null,false);
    cmsCheck(str_contains($html,'data-form-ref="preview"') && str_contains($html,'Formulario sintético') && str_contains($html,'Mensaje sintético')
        && !str_contains($html,$id) && !str_contains($html,'cms_fixture') && !str_contains($html,'<img src=x onerror=alert(1)>'),'public copy only; XSS escaped');
    cmsCheck(strpos($html,'Before') < strpos($html,'data-form-ref="preview"') && strpos($html,'data-form-ref="preview"') < strrpos($html,'After'),'public block order');
    $db->exec("UPDATE form_definition_registry SET state='disabled' WHERE form_key='cms_fixture'");
    $html=$http($url,200,'GET',null,false);
    cmsCheck(str_contains($html,'data-form-ref="unavailable"')&&!str_contains($html,'Formulario sintético'),'revocation immediate');
    cmsCheck((int)$db->query('SELECT COUNT(*) FROM form_entries')->fetchColumn()===0,'no capture');
    echo "BRVTAL_MARKED_DATABASE_PASS: forms-cms-embed.php\n";
} catch (Throwable $error) {
    $failedLine = $error->getFile() === __FILE__ ? ($error->getCode() ?: $error->getLine()) : 200;
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    $server->exec('DROP DATABASE '.$name);
    if(is_dir($root))exec('rm -rf -- '.escapeshellarg($root));
}
if(isset($failedLine)) exit(max(1,min(254,(int)$failedLine)));
