<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/deployment.php';
require_once __DIR__ . '/deploy_readiness.php';

/** @return array<string,mixed> */
function brvtalAdminDeploySignals(
    ?callable $deploymentResolver = null,
    ?callable $migrationResolver = null
): array {
    $resolveDeployment = $deploymentResolver ?? static fn(): array => brvtalDeploymentPublicData();
    $resolveMigrations = $migrationResolver ?? static function (): array {
        $pdo = db();
        $pdo->query('SELECT 1');

        return brvtal_migration_status($pdo, dirname(__DIR__) . '/database');
    };

    try {
        $deployment = $resolveDeployment();
        if (!is_array($deployment)) {
            throw new RuntimeException('DEPLOYMENT_IDENTITY_UNAVAILABLE');
        }

        $version = $deployment['version'] ?? null;
        $source = $deployment['source'] ?? null;
        $exact = ($deployment['exact'] ?? false) === true;
        $commit = $deployment['commit'] ?? null;
        if (!is_string($version) || trim($version) === '' || !is_string($source)) {
            throw new RuntimeException('DEPLOYMENT_IDENTITY_INVALID');
        }
        if ($exact && (!is_string($commit) || preg_match('/^[a-f0-9]{40}$/i', $commit) !== 1)) {
            throw new RuntimeException('DEPLOYMENT_IDENTITY_INVALID');
        }

        try {
            $migrationStatus = $resolveMigrations();
            if (!is_array($migrationStatus)) {
                throw new RuntimeException('MIGRATION_STATUS_INVALID');
            }
            $readiness = brvtalFactoryReadinessState(
                $exact,
                $exact ? strtolower((string)$commit) : null,
                $migrationStatus
            );
            $schema = brvtalMigrationReadinessSummary($migrationStatus);

            return [
                'status'=>'available',
                'freshness'=>'fresh',
                'version'=>$version,
                'release_identity'=>(string)($deployment['release_identity'] ?? ('v' . $version)),
                'exact'=>$exact,
                'release_sha'=>$readiness['release_sha'],
                'short_sha'=>$readiness['release_sha'] !== null
                    ? substr((string)$readiness['release_sha'], 0, 7)
                    : null,
                'source'=>$source,
                'environment'=>(string)($deployment['environment'] ?? ''),
                'release_date'=>(string)($deployment['release_date'] ?? ''),
                'readiness'=>$readiness['readiness_status'],
                'schema_up_to_date'=>$readiness['schema_up_to_date'],
                'schema'=>$schema,
                'source_at'=>gmdate('c'),
                'read_only'=>true,
            ];
        } catch (Throwable) {
            return [
                'status'=>'degraded',
                'freshness'=>'fresh',
                'version'=>$version,
                'release_identity'=>(string)($deployment['release_identity'] ?? ('v' . $version)),
                'exact'=>$exact,
                'release_sha'=>$exact ? strtolower((string)$commit) : null,
                'short_sha'=>$exact ? substr(strtolower((string)$commit), 0, 7) : null,
                'source'=>$source,
                'environment'=>(string)($deployment['environment'] ?? ''),
                'release_date'=>(string)($deployment['release_date'] ?? ''),
                'readiness'=>'degraded',
                'schema_up_to_date'=>false,
                'schema'=>null,
                'source_at'=>gmdate('c'),
                'read_only'=>true,
            ];
        }
    } catch (Throwable) {
        return [
            'status'=>'unavailable',
            'freshness'=>'unavailable',
            'version'=>null,
            'release_identity'=>null,
            'exact'=>false,
            'release_sha'=>null,
            'short_sha'=>null,
            'source'=>null,
            'environment'=>null,
            'release_date'=>null,
            'readiness'=>'degraded',
            'schema_up_to_date'=>false,
            'schema'=>null,
            'source_at'=>null,
            'read_only'=>true,
        ];
    }
}
