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
system_status_expect(str_contains($technical, "'source_lines'"), 'source LOC must be calculated');
system_status_expect(str_contains($technical, "'source_files'"), 'source file count must be calculated');
system_status_expect(str_contains($technical, 'security.totp_encryption_key'), 'TOTP key readiness must be checked');
system_status_expect(!str_contains($technical, 'SELECT setting_value'), 'TOTP encryption value must never be selected into diagnostics');
system_status_expect(str_contains($technical, "'admin_activity_log'"), 'activity history readiness must be checked');
system_status_expect(str_contains($technical, "'issues' => \$issues"), 'overview must provide actionable issues');
system_status_expect(str_contains($script, "'/api/content-health.php'"), 'visual status must include Content Health');
system_status_expect(str_contains($script, "'/api/admin-activity.php?limit=5'"), 'visual status must include recent admin activity');
system_status_expect(str_contains($script, 'ssv2-storage-ring'), 'visual status must render storage utilization');
system_status_expect(str_contains($script, 'DATABASE CONTENT'), 'visual status must render database content chart');
system_status_expect(str_contains($script, 'COMMITS / MAIN'), 'visual status must render repository metrics');
system_status_expect(str_contains($styles, 'conic-gradient'), 'visual status must use graphical ring indicators');
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
