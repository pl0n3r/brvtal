<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/deployment.php';
$started=microtime(true);
try {
    $pdo=db(); $pdo->query('SELECT 1');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    json_response(['ok'=>true,'app'=>'BRVTAL','status'=>'healthy','database'=>'connected','driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'server'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'php'=>PHP_VERSION,'deployment'=>['commit'=>brvtal_deployment_sha(),'short_commit'=>brvtal_deployment_short_sha(),'source'=>brvtal_deployment_source()],'time'=>date(DATE_ATOM),'latency_ms'=>round((microtime(true)-$started)*1000,2)]);
} catch(Throwable $e) {
    brvtal_log('HEALTH_ERROR','Standalone health check failed',['message'=>$e->getMessage()]);
    json_response(['ok'=>false,'app'=>'BRVTAL','status'=>'degraded','database'=>'error','php'=>PHP_VERSION,'time'=>date(DATE_ATOM)],503);
}
