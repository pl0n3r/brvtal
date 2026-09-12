<?php
declare(strict_types=1);

function deployment_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "DEPLOYMENT TRACEABILITY CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$resolver = (string)file_get_contents(__DIR__ . '/../config/deployment.php');
$endpoint = (string)file_get_contents(__DIR__ . '/../api/deployment.php');
$health = (string)file_get_contents(__DIR__ . '/../api/health.php');
$admin = (string)file_get_contents(__DIR__ . '/../discadmin/index-core.php');
$adminShell = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');

deployment_expect(str_contains($resolver, "getenv('BRVTAL_DEPLOY_COMMIT')"), 'resolver must support an explicit deployment SHA');
deployment_expect(str_contains($resolver, "'/HEAD'"), 'resolver must inspect the deployed Git checkout');
deployment_expect(str_contains($resolver, 'BRVTAL_APP_BUILD'), 'resolver must retain intentional release metadata as fallback');
deployment_expect(str_contains($endpoint, "'short_commit'"), 'public deployment endpoint must expose the deployed short SHA');
deployment_expect(str_contains($health, "'deployment'=>"), 'health response must identify its deployed source');
deployment_expect(str_contains($admin, 'DEPLOY <?= htmlspecialchars(brvtal_deployment_short_sha()'), 'DISCADMIN must display the resolved deployed SHA');
deployment_expect(str_contains($adminShell, 'brvtal_deployment_short_sha()'), 'DISCADMIN enhancement assets must be versioned by the deployed SHA');
deployment_expect(str_contains($adminShell, "seo-editorial-defaults.js' . \$suffix"), 'SEO defaults enhancement must receive the deployment cache key');
deployment_expect(str_contains($adminShell, "content-core-nav.js' . \$suffix"), 'Content Core direct navigation must load as a deployment-versioned enhancement');

echo "BRVTAL deployment traceability contract tests passed.\n";
