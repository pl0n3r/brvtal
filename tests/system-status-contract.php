<?php
declare(strict_types=1);

function system_status_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SYSTEM STATUS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$technical = (string)file_get_contents(__DIR__ . '/../discadmin/technical.php');
$script = (string)file_get_contents(__DIR__ . '/../discadmin/system-status-v2.js');
$styles = (string)file_get_contents(__DIR__ . '/../discadmin/system-status-v2.css');
$storageEndpoint = (string)file_get_contents(__DIR__ . '/../discadmin/storage-metrics.php');
$storageScript = (string)file_get_contents(__DIR__ . '/../discadmin/system-status-storage.js');
$shell = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');

system_status_expect(str_contains($technical, 'brvtal_admin_require();'), 'technical diagnostics must remain admin protected');
system_status_expect(str_contains($technical, "\$action === 'overview'"), 'overview action must exist');
system_status_expect(str_contains($technical, 'brvtal_deployment_short_sha()'), 'overview must expose resolved deployment identity');
system_status_expect(str_contains($technical, 'github_public_api'), 'overview must identify GitHub public metrics source');
system_status_expect(str_contains($technical, 'api.github.com/repos/pl0n3r/brvtal/commits'), 'commit count must come from GitHub');
system_status_expect(str_contains($technical, 'is%3Apr+is%3Amerged'), 'merged PR count must come from GitHub');
system_status_expect(str_contains($technical, 'is%3Aissue+is%3Aopen'), 'open Issue count must come from GitHub Search with PRs excluded');
system_status_expect(str_contains($technical, "'open_issues'"), 'GitHub payload must expose open Issue count');
system_status_expect(str_contains($technical, "'recent_issues'"), 'GitHub payload must expose a bounded recent Issue list');
system_status_expect(str_contains($technical, "'backlog_state'"), 'GitHub backlog freshness must be explicit');
system_status_expect(str_contains($technical, "array_slice(is_array(\$body['items'] ?? null) ? \$body['items'] : [], 0, 6)"), 'recent GitHub Issues must be bounded');
system_status_expect(str_contains($technical, "'https://github.com/pl0n3r/brvtal/issues/' . \$number"), 'Issue URLs must be reconstructed canonically from numeric IDs');
system_status_expect(str_contains($technical, 'array_key_exists(\'open_issues\''), 'old GitHub cache schema must not masquerade as a fresh backlog');
system_status_expect(!str_contains($technical, 'Authorization:'), 'System Status must not require or expose a GitHub authorization token');
system_status_expect(str_contains($technical, "'source_lines'"), 'source LOC must be calculated');
system_status_expect(str_contains($technical, "'source_files'"), 'source file count must be calculated');
system_status_expect(str_contains($technical, 'security.totp_encryption_key'), 'TOTP key readiness must be checked');
system_status_expect(!str_contains($technical, 'SELECT setting_value'), 'TOTP encryption value must never be selected into diagnostics');
system_status_expect(str_contains($technical, "'admin_activity_log'"), 'activity history readiness must be checked');
system_status_expect(str_contains($technical, "'issues' => \$issues"), 'overview must provide actionable platform issues');
system_status_expect(str_contains($technical, "'repository_diagnostics' => \$repositoryDiagnostics"), 'GitHub availability/cache notices must be emitted outside platform issues');
system_status_expect(str_contains($technical, "\$repositoryDiagnostics[] = ['severity'=>'info','title'=>'GITHUB METRICS'"), 'GitHub metrics outage must be repository diagnostics');
system_status_expect(str_contains($technical, "\$repositoryDiagnostics[] = ['severity'=>'info','title'=>'GITHUB CACHE'"), 'stale GitHub cache must be repository diagnostics');
system_status_expect(str_contains($script, "'/api/content-health.php'"), 'visual status must include Content Health');
system_status_expect(str_contains($script, "'/api/admin-activity.php?limit=5'"), 'visual status must include recent admin activity');
system_status_expect(str_contains($script, 'ssv2-storage-ring'), 'visual status must render storage utilization');
system_status_expect(str_contains($script, 'DATABASE CONTENT'), 'visual status must render database content chart');
system_status_expect(str_contains($script, 'COMMITS / MAIN'), 'visual status must render repository metrics');
system_status_expect(str_contains($script, 'OPEN ISSUES'), 'repository metrics must expose GitHub open Issues');
system_status_expect(str_contains($script, 'GITHUB BACKLOG'), 'Attention Required must expose GitHub backlog separately');
system_status_expect(str_contains($script, 'NO ACTIVE PLATFORM SIGNALS'), 'healthy operational copy must be scoped to platform signals');
system_status_expect(!str_contains($script, 'NO ACTIVE ISSUES'), 'System Status must not claim there are no issues when it only knows platform health');
system_status_expect(str_contains($script, 'platformIssues'), 'GitHub backlog must remain separate from platform issue scoring/presentation');
system_status_expect(str_contains($script, 'repository_diagnostics'), 'frontend must consume repository diagnostics separately');
system_status_expect(str_contains($script, 'repositoryDiagnosticList'), 'repository diagnostics must have their own renderer');
system_status_expect(str_contains($styles, 'conic-gradient'), 'visual status must use graphical ring indicators');
system_status_expect(str_contains($styles, 'ssv2-backlog-item'), 'GitHub backlog must have a distinct visual treatment');
system_status_expect(str_contains($shell, 'system-status-v2.css'), 'shell must load System Status stylesheet');
system_status_expect(str_contains($shell, 'system-status-v2.js'), 'shell must load System Status enhancement');

system_status_expect(str_contains($storageEndpoint, 'brvtal_admin_require();'), 'managed storage metrics must remain admin protected');
system_status_expect(str_contains($storageEndpoint, '25 * 1024 * 1024 * 1024'), 'managed storage must have the known 25 GB operational fallback');
system_status_expect(str_contains($storageEndpoint, "'/uploads'"), 'managed storage must scan uploads');
system_status_expect(str_contains($storageEndpoint, "'/storage'"), 'managed storage must scan private application storage');
system_status_expect(str_contains($storageEndpoint, "'diagnostic_only' => true"), 'host filesystem capacity must be explicitly diagnostic only');
system_status_expect(str_contains($storageScript, "'/discadmin/storage-metrics.php'"), 'visual storage block must use managed storage metrics');
system_status_expect(str_contains($storageScript, 'BRVTAL DATA'), 'visual storage block must identify BRVTAL-managed data');
system_status_expect(str_contains($shell, 'system-status-storage.js'), 'shell must load managed storage enhancement');

echo "BRVTAL System Status v2 contract tests passed.\n";
