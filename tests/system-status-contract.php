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

echo "BRVTAL System Status v2 contract tests passed.\n";
