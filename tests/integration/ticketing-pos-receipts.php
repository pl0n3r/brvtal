<?php
declare(strict_types=1);
// @brvtal-ci database
require_once __DIR__.'/../../config/ticketing_pos_receipts.php';

function receiptsCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('POS receipt fixture: '.$message);
}
function receiptsReject(callable $call, string $code): void {
    try { $call(); } catch (LogicException $e) {
        receiptsCheck($e->getMessage()===$code, 'unexpected error'); return;
    }
    throw new RuntimeException('Expected '.$code);
}
function receiptsFixture(PDO $db): void {
    $db->exec('CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY,staff_role VARCHAR(32) NOT NULL,is_active TINYINT NOT NULL,credential_epoch INT NOT NULL DEFAULT 1) ENGINE=InnoDB');
    $db->exec("INSERT INTO admins (id,staff_role,is_active) VALUES (1,'owner',1),(2,'superadmin',1),(3,'owner',0)");
    $db->exec('CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('INSERT INTO ticket_orders VALUES (1),(2),(3),(4)');
}
function receiptsExercise(PDO $db): void {
    $a=brvtalPosDestinationCreate($db,1,'transfer','Cuenta ···1234');
    $b=brvtalPosDestinationCreate($db,1,'transfer','Cuenta ···5678');
    $c=brvtalPosDestinationCreate($db,1,'terminal','Datáfono barra');
    receiptsReject(fn()=>brvtalPosDestinationCreate($db,2,'transfer','Cuenta'), 'POS_DESTINATION_FORBIDDEN');
    receiptsReject(fn()=>brvtalPosDestinationDeactivate($db,3,$a), 'POS_DESTINATION_FORBIDDEN');
    receiptsReject(fn()=>brvtalPosDestinationCreate($db,1,'transfer','Cuenta 1234 5678'), 'POS_DESTINATION_ALIAS');
    receiptsReject(fn()=>brvtalPosDestinationCreate($db,1,'cash','Caja'), 'POS_RECEIPT_METHOD');
    $hash=str_repeat('a',64);
    receiptsReject(fn()=>brvtalPosReceiptRegister($db,1,'transfer',$a,'ref-01',$hash),'POS_RECEIPT_TRANSACTION');
    $db->beginTransaction();
    brvtalPosReceiptRegister($db,1,'transfer',$a,' ref-01  a ',$hash);
    brvtalPosReceiptRegister($db,2,'transfer',$b,'REF-01 A',$hash);
    brvtalPosReceiptRegister($db,3,'terminal',$c,'REF-01 A',$hash);
    $db->commit();
    $db->beginTransaction();
    try {
        brvtalPosReceiptRegister($db,4,'transfer',$a,'REF-01 A',$hash);
        throw new RuntimeException('Duplicate accepted');
    } catch (PDOException $e) { receiptsCheck((int)$e->errorInfo[1]===1062,'unique scope'); }
    finally { $db->rollBack(); }
    $db->beginTransaction();
    receiptsReject(fn()=>brvtalPosReceiptRegister($db,4,'terminal',$a,'REF-02',$hash),'POS_DESTINATION_UNAVAILABLE');
    brvtalPosReceiptRegister($db,4,'transfer',$a,'REF-01-A',$hash);
    $db->rollBack();
    receiptsCheck(brvtalPosReceiptFind($db,'transfer',$a,'REF-01-A')===null,'rollback');
    brvtalPosDestinationDeactivate($db,1,$a);
    receiptsCheck(count(brvtalPosDestinations($db,'transfer'))===1,'active only');
    receiptsCheck((int)brvtalPosReceiptFind($db,'transfer',$a,'ref-01 a')['order_id']===1,'inactive historical receipt');
    $db->beginTransaction();
    receiptsReject(fn()=>brvtalPosReceiptRegister($db,4,'transfer',$a,'REF-02',$hash),'POS_DESTINATION_UNAVAILABLE');
    $db->rollBack();
    receiptsCheck((int)$db->query('SELECT COUNT(*) FROM ticket_pos_payment_receipts')->fetchColumn()===3,'three confirmed receipts');
    $q=$db->prepare('SELECT deactivated_by_admin_id FROM ticket_pos_payment_destinations WHERE id=?');
    $q->execute([$a]);
    receiptsCheck((int)$q->fetchColumn()===1,'deactivation audited');
}

/** Actual localhost HTTP authentication, CSRF, catalog and historical receipt contract. */
function receiptsHttp(PDO $db,array $settings): void {
    $dir=sys_get_temp_dir().'/brvtal-destinations-'.bin2hex(random_bytes(8));
    receiptsCheck(mkdir($dir,0700),'fixture directory');
    $server=null;
    try {
        $root=realpath(__DIR__.'/../..');
        file_put_contents($dir.'/config.php','<?php return '.var_export([
            'app'=>['timezone'=>'America/Bogota'],'db'=>$settings,
            'security'=>['session_name'=>'BRVTAL_DEST_FIXTURE']
        ],true).';');
        chmod($dir.'/config.php',0600);
        $router='<?php define("BRVTAL_SENTRY_TESTING",true);'
            .'$GLOBALS["brvtalBootstrapConfigPath"]='.var_export($dir.'/config.php',true).';'
            .'ini_set("session.save_path",'.var_export($dir,true).');'
            .'$root='.var_export($root,true).';'
            . <<<'ROUTER'
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/fixture-session') {
    require $root.'/config/admin_auth.php';
    $actor=(int)($_GET['actor']??0);
    if (!in_array($actor,[1,2],true)) {http_response_code(404);exit;}
    brvtal_admin_login_session($actor);
    json_response(['csrf'=>brvtal_admin_csrf_token()]);
}
if ($path==='/api/ticketing/admin-pos-destinations.php') {require $root.$path;return true;}
http_response_code(404);
ROUTER;
        file_put_contents($dir.'/router.php',$router);
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);
        receiptsCheck(is_resource($socket),'fixture socket');
        $address=stream_socket_get_name($socket,false);fclose($socket);
        $server=proc_open([PHP_BINARY,'-S',$address,$dir.'/router.php'],
            [0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$root);
        receiptsCheck(is_resource($server),'fixture process');fclose($pipes[0]);
        $ready=false;$port=(int)substr(strrchr($address,':'),1);
        for($i=0;$i<40;$i++){
            $probe=@fsockopen('127.0.0.1',$port,$errno,$errstr,0.2);
            if(is_resource($probe)){fclose($probe);$ready=true;break;}
            usleep(50000);
        }
        receiptsCheck($ready,'HTTP fixture ready');
        $request=static function(string $path,array $headers=[],?array $payload=null)use($address):array{
            $options=['method'=>$payload===null?'GET':'POST','ignore_errors'=>true,
                'timeout'=>5,'header'=>implode("\r\n",$headers)];
            if($payload!==null)$options['content']=json_encode($payload,JSON_THROW_ON_ERROR);
            $body=file_get_contents('http://'.$address.$path,false,stream_context_create(['http'=>$options]));
            $lines=http_get_last_response_headers();
            receiptsCheck(is_string($body)&&is_array($lines)&&isset($lines[0]),'HTTP response');
            return [(int)substr($lines[0],9,3),json_decode($body,true,8,JSON_THROW_ON_ERROR),$lines];
        };
        $path='/api/ticketing/admin-pos-destinations.php';
        $create=['action'=>'create','method'=>'nequi','alias'=>'Billetera puerta'];
        receiptsCheck($request($path)[0]===401,'anonymous read');
        receiptsCheck($request($path,['Content-Type: application/json'],$create)[0]===401,'anonymous write');
        foreach([2,1] as $actor){
            [$status,$session,$headers]=$request('/fixture-session?actor='.$actor);
            receiptsCheck($status===200&&isset($session['csrf']),'session');
            $cookie='';
            foreach($headers as $header){
                if(str_starts_with($header,'Set-Cookie: BRVTAL_DEST_FIXTURE=')){
                    $cookie=explode(';',substr($header,12),2)[0];
                }
            }
            receiptsCheck($cookie!=='','session cookie');
            $base=['Content-Type: application/json','Cookie: '.$cookie];
            $authenticated=[...$base,'X-CSRF-Token: '.$session['csrf']];
            receiptsCheck($request($path,$base,$create)[0]===419,'CSRF required for create');
            receiptsCheck($request($path,$base,['action'=>'deactivate','id'=>2])[0]===419,'CSRF required for deactivate');
            if($actor===2){
                receiptsCheck($request($path,$base)[0]===403,'nonowner read');
                receiptsCheck($request($path,$authenticated,$create)[0]===403,'nonowner create');
                receiptsCheck($request($path,$authenticated,['action'=>'deactivate','id'=>2])[0]===403,'nonowner deactivate');
                continue;
            }
            [$status,$body]=$request($path,$base);
            receiptsCheck($status===200&&count($body['data']['destinations']['transfer'])===1,'list active only');
            receiptsCheck(array_keys($body['data']['destinations']['transfer'][0])===['id','method','alias'],'safe fields');
            foreach([['action'=>'create','method'=>'cash','alias'=>'Caja'],
                ['action'=>'create','method'=>'nequi','alias'=>'Cuenta 123456'],
                ['action'=>'create','method'=>[],'alias'=>'Cuenta'],
                $create+['owner'=>1],['action'=>'deactivate','id'=>'2'],
                ['action'=>'deactivate','id'=>-1]] as $invalid){
                receiptsCheck($request($path,$authenticated,$invalid)[0]===422,'invalid payload rejected');
            }
            [$status,$body]=$request($path,$authenticated,$create);
            receiptsCheck($status===201&&is_int($body['data']['id']),'owner creates');
            $new=$body['data']['id'];
            receiptsCheck($request($path,$authenticated,$create)[0]===409,'duplicate medium and alias');
            receiptsCheck($request($path,$authenticated,array_replace($create,['method'=>'terminal']))[0]===201,'same alias different medium');
            $historical=brvtalPosReceiptFind($db,'transfer',2,'REF-01 A');
            receiptsCheck($historical!==null,'historical before deactivate');
            receiptsCheck($request($path,$authenticated,['action'=>'deactivate','id'=>2])[0]===200,'owner deactivates');
            receiptsCheck($request($path,$authenticated,['action'=>'deactivate','id'=>2])[0]===409,'already inactive');
            receiptsCheck(brvtalPosReceiptFind($db,'transfer',2,'REF-01 A')===$historical,'receipt unchanged');
            receiptsCheck((int)$db->query('SELECT COUNT(*) FROM ticket_pos_payment_destinations WHERE id=2 AND is_active=0')->fetchColumn()===1,'not deleted');
            receiptsCheck($request($path,$authenticated,['action'=>'deactivate','id'=>$new])[0]===200,'new destination deactivated');
            receiptsCheck($request($path,$authenticated,$create)[0]===409,'historical alias cannot be reused');
            [$status,$body]=$request($path,$base);
            receiptsCheck($status===200&&$body['data']['destinations']['transfer']===[]&&$body['data']['destinations']['nequi']===[],'inactive no longer offered');
        }
    } finally {
        if(is_resource($server)){proc_terminate($server);proc_close($server);}
        foreach(glob($dir.'/*')?:[] as $file)unlink($file);
        rmdir($dir);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
receiptsCheck(brvtalPosReceiptNormalize(' ref-01  a ')==='REF-01 A','normalize');
receiptsCheck(brvtalPosReceiptNormalize('ref-01/a')!==brvtalPosReceiptNormalize('ref-01-a'),'separators');
receiptsCheck(brvtalPosReceiptNormalize('ref-001')!==brvtalPosReceiptNormalize('ref-01'),'leading zeros');
receiptsReject(fn()=>brvtalPosReceiptNormalize("REF\n001"),'POS_RECEIPT_REFERENCE');
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1' || getenv('BRVTAL_TEST_DB_HOST')!=='127.0.0.1') throw new RuntimeException('Disposable MariaDB required');
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
receiptsCheck(preg_match('/\Abrvtal_test[a-zA-Z0-9_]*\z/D',$prefix)===1,'test namespace');
$name=$prefix.'_receipts_'.bin2hex(random_bytes(4));
$dsn='mysql:host='.(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1').';port='.(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306).';charset=utf8mb4';
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root'); $pass=(string)getenv('BRVTAL_TEST_DB_PASS');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$user,$pass,$options); $server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try {
    $db=new PDO($dsn.';dbname='.$name,$user,$pass,$options); receiptsFixture($db);
    $db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_pos_receipts_01.sql'));
    receiptsExercise($db);
    receiptsHttp($db,['host'=>'127.0.0.1','port'=>(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306),
        'name'=>$name,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4']);
} finally { $server->exec('DROP DATABASE '.$name); }
echo "BRVTAL_MARKED_DATABASE_PASS: ticketing-pos-receipts.php\n";
