<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/deployment.php';
require_once __DIR__ . '/../config/health.php';

$started = microtime(true);
$exact = brvtalDeploymentIsExact();
$resolvedSha = $exact ? brvtal_deployment_sha() : null;
$deployment = [
    'commit' => $resolvedSha,
    'short_commit' => $exact ? brvtal_deployment_short_sha() : null,
    'source' => brvtal_deployment_source(),
    'exact' => $exact,
    'version' => BRVTAL_APP_VERSION,
    'release_identity' => brvtalReleaseIdentity(),
    'cache_key' => brvtalDeploymentCacheKey(),
    'environment' => BRVTAL_APP_ENV,
];

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $pdo = db();
    $pdo->query('SELECT 1');
    $migrationStatus = brvtal_migration_status($pdo, dirname(__DIR__) . '/database');
    $factoryHealth = brvtalFactoryHealthState($exact, $resolvedSha, $migrationStatus);
    $statusCode = $factoryHealth['ready'] ? 200 : 503;

    json_response([
        'ok' => $factoryHealth['ready'],
        'app' => 'BRVTAL',
        'status' => $factoryHealth['status'],
        'health_status' => $factoryHealth['health_status'],
        'database' => 'connected',
        'version' => BRVTAL_APP_VERSION,
        'release_sha' => $factoryHealth['release_sha'],
        'schema_up_to_date' => $factoryHealth['schema_up_to_date'],
        'schema' => brvtalMigrationHealthSummary($migrationStatus),
        'deployment' => $deployment,
        'time' => date(DATE_ATOM),
        'latency_ms' => round((microtime(true) - $started) * 1000, 2),
    ], $statusCode);
} catch (Throwable $e) {
    brvtal_log('HEALTH_ERROR', 'Standalone health check failed', ['message' => $e->getMessage()]);
    $factoryHealth = brvtalFactoryHealthState($exact, $resolvedSha, [
        'registry_exists' => false,
        'migrations' => [],
        'orphaned_records' => [],
    ]);

    json_response([
        'ok' => false,
        'app' => 'BRVTAL',
        'status' => 'degraded',
        'health_status' => 'degraded',
        'database' => 'error',
        'version' => BRVTAL_APP_VERSION,
        'release_sha' => $factoryHealth['release_sha'],
        'schema_up_to_date' => false,
        'deployment' => $deployment,
        'time' => date(DATE_ATOM),
        'latency_ms' => round((microtime(true) - $started) * 1000, 2),
    ], 503);
}
