<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/deployment.php';

$started = microtime(true);
try {
    $pdo = db();
    $pdo->query('SELECT 1');
    $exact = brvtalDeploymentIsExact();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    json_response([
        'ok' => true,
        'app' => 'BRVTAL',
        'status' => 'healthy',
        'database' => 'connected',
        'deployment' => [
            'commit' => $exact ? brvtal_deployment_sha() : null,
            'short_commit' => $exact ? brvtal_deployment_short_sha() : null,
            'source' => brvtal_deployment_source(),
            'exact' => $exact,
            'version' => BRVTAL_APP_VERSION,
            'release_identity' => brvtalReleaseIdentity(),
            'cache_key' => brvtalDeploymentCacheKey(),
            'environment' => BRVTAL_APP_ENV,
        ],
        'time' => date(DATE_ATOM),
        'latency_ms' => round((microtime(true) - $started) * 1000, 2),
    ]);
} catch (Throwable $e) {
    brvtal_log('HEALTH_ERROR', 'Standalone health check failed', ['message' => $e->getMessage()]);
    json_response([
        'ok' => false,
        'app' => 'BRVTAL',
        'status' => 'degraded',
        'database' => 'error',
        'time' => date(DATE_ATOM),
    ], 503);
}
