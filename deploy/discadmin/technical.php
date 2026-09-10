<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

function admin_session(): void {
    global $config;
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name($config['security']['session_name'] ?? 'BRVTAL_ADMIN');
    session_start(['cookie_httponly'=>true,'cookie_secure'=>!empty($_SERVER['HTTPS']),'cookie_samesite'=>'Lax']);
}
admin_session();
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok'=>false,'error'=>'AUTH_REQUIRED']); exit;
}
header('Content-Type: application/json; charset=utf-8');
$action = (string)($_GET['action'] ?? 'system');
$root = dirname(__DIR__);
$bytes = static function($n): string {
    if ($n === false) return 'N/A';
    $u=['B','KB','MB','GB','TB']; $i=0; $n=(float)$n;
    while($n>=1024 && $i<count($u)-1){$n/=1024;$i++;}
    return number_format($n,2).' '.$u[$i];
};
try {
    if ($action === 'health') {
        db()->query('SELECT 1');
        echo json_encode(['ok'=>true,'api'=>'online','database'=>'connected','time'=>date(DATE_ATOM)]); exit;
    }
    if ($action === 'system') {
        $pdo=db(); $pdo->query('SELECT 1');
        echo json_encode(['ok'=>true,'php'=>PHP_VERSION,'sapi'=>PHP_SAPI,'session'=>session_status()===PHP_SESSION_ACTIVE?'active':'inactive','database'=>'connected','driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'server'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'storage_free'=>$bytes(@disk_free_space($root)),'storage_total'=>$bytes(@disk_total_space($root)),'uploads_writable'=>is_writable($root.'/uploads'),'logs_writable'=>is_writable($root.'/storage/logs'),'time'=>date(DATE_ATOM)]); exit;
    }
    if ($action === 'database') {
        $pdo=db();
        $tables=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $counts=[];
        foreach(['events','artists','sets_media','media','pages','settings','analytics_events'] as $t){if(in_array($t,$tables,true))$counts[$t]=(int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();}
        echo json_encode(['ok'=>true,'driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'server'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'database'=>$config['db']['name']??'[configured]','tables'=>$tables,'counts'=>$counts]); exit;
    }
    if ($action === 'storage') {
        echo json_encode(['ok'=>true,'root_free'=>$bytes(@disk_free_space($root)),'root_total'=>$bytes(@disk_total_space($root)),'uploads_exists'=>is_dir($root.'/uploads'),'uploads_writable'=>is_writable($root.'/uploads'),'logs_exists'=>is_dir($root.'/storage/logs'),'logs_writable'=>is_writable($root.'/storage/logs'),'uploads_items'=>is_dir($root.'/uploads')?count(array_diff(scandir($root.'/uploads'),['.','..'])):0]); exit;
    }
    if ($action === 'php') {
        $ext=['PDO','pdo_mysql','mbstring','json','curl','fileinfo','openssl','iconv']; $extensions=[]; foreach($ext as $e)$extensions[$e]=extension_loaded($e);
        echo json_encode(['ok'=>true,'version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'memory_limit'=>ini_get('memory_limit'),'upload_max_filesize'=>ini_get('upload_max_filesize'),'post_max_size'=>ini_get('post_max_size'),'max_execution_time'=>ini_get('max_execution_time'),'extensions'=>$extensions]); exit;
    }
    if ($action === 'logs') {
        $file=$root.'/storage/logs/brvtal.log'; $lines=is_file($file)?file($file,FILE_IGNORE_NEW_LINES):[]; $lines=array_slice($lines,-300);
        echo json_encode(['ok'=>true,'file'=>'storage/logs/brvtal.log','lines'=>count($lines),'content'=>implode("\n",$lines)]); exit;
    }
    echo json_encode(['ok'=>false,'error'=>'UNKNOWN_ACTION'], JSON_UNESCAPED_UNICODE); 
} catch(Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'TECH_ERROR','message'=>$e->getMessage()]);
}
