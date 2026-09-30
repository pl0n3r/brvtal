<?php
declare(strict_types=1);

require_once __DIR__ . '/backup_automation.php';

/** @return array<string,mixed> */
function brvtalAdminBackupSignals(
    ?callable $backupsResolver = null,
    ?callable $automationResolver = null,
    ?callable $sourceAtResolver = null
): array {
    $resolveBackups = $backupsResolver ?? static fn(): array => brvtal_backup_list();
    $resolveAutomation = $automationResolver ?? static fn(): array => brvtal_backup_automation_public_state(
        brvtal_backup_automation_read()
    );
    $resolveSourceAt = $sourceAtResolver ?? static fn(): string => gmdate('c');

    try {
        $backups = $resolveBackups();
        $automation = $resolveAutomation();
        $sourceAt = $resolveSourceAt();
        if (!is_array($backups) || !is_array($automation) || !is_string($sourceAt) || trim($sourceAt) === '') {
            throw new RuntimeException('BACKUP_SIGNAL_SOURCE_INVALID');
        }

        $latest = $backups[0] ?? null;
        $backupState = 'missing';
        $latestBackup = null;
        if ($latest !== null) {
            if (!is_array($latest)) {
                throw new RuntimeException('BACKUP_SIGNAL_LATEST_INVALID');
            }
            $rawStatus = strtolower(trim((string)($latest['status'] ?? '')));
            $backupState = in_array($rawStatus, ['ready', 'partial'], true)
                ? $rawStatus
                : 'unavailable';
            $latestBackup = [
                'id'=>mb_substr((string)($latest['id'] ?? ''), 0, 80),
                'status'=>$backupState,
                'scope'=>mb_substr((string)($latest['scope'] ?? ''), 0, 24),
                'trigger'=>mb_substr((string)($latest['trigger'] ?? ''), 0, 24),
                'created_at'=>is_string($latest['created_at'] ?? null)
                    ? (string)$latest['created_at']
                    : null,
                'artifacts_bytes'=>isset($latest['artifacts_bytes'])
                    ? max(0, (int)$latest['artifacts_bytes'])
                    : null,
            ];
        }

        $config = is_array($automation['config'] ?? null) ? $automation['config'] : [];
        $enabled = ($config['enabled'] ?? false) === true;
        $driveConfig = is_array($config['drive'] ?? null) ? $config['drive'] : [];
        $offsiteEnabled = ($driveConfig['enabled'] ?? false) === true;
        $lastResult = is_array($automation['last_result'] ?? null) ? $automation['last_result'] : [];
        $driveState = is_array($automation['drive'] ?? null) ? $automation['drive'] : [];

        $lastResultStatus = strtolower(trim((string)($lastResult['status'] ?? '')));
        if (!in_array($lastResultStatus, ['success', 'partial', 'failed'], true)) {
            $lastResultStatus = 'unknown';
        }

        $offsiteStatus = 'disabled';
        if ($offsiteEnabled) {
            $lastDrive = strtolower(trim((string)($lastResult['drive'] ?? '')));
            if ($lastDrive === 'failed' || !empty($driveState['last_error'])) {
                $offsiteStatus = 'failed';
            } elseif ($lastDrive === 'success' || !empty($driveState['last_success_at'])) {
                $offsiteStatus = 'success';
            } else {
                $offsiteStatus = 'pending';
            }
        }

        $lastError = null;
        if ($offsiteStatus === 'failed') {
            $candidate = strtoupper(trim((string)($driveState['last_error'] ?? 'OFFSITE_FAILED')));
            $lastError = preg_match('/^[A-Z0-9_:-]{1,80}$/', $candidate) === 1
                ? $candidate
                : 'OFFSITE_FAILED';
        }

        $degraded = (
            $backupState !== 'ready'
            || ($enabled && $lastResultStatus === 'failed')
            || ($offsiteEnabled && $offsiteStatus !== 'success')
        );

        return [
            'status'=>$degraded ? 'degraded' : 'available',
            'freshness'=>$degraded ? 'degraded' : 'fresh',
            'backup_state'=>$backupState,
            'latest_backup'=>$latestBackup,
            'automation'=>[
                'enabled'=>$enabled,
                'next_run_at'=>is_string($automation['next_run_at'] ?? null)
                    ? (string)$automation['next_run_at']
                    : null,
                'last_success_at'=>is_string($automation['last_success_at'] ?? null)
                    ? (string)$automation['last_success_at']
                    : null,
                'last_result_status'=>$lastResultStatus,
                'last_duration_ms'=>isset($automation['last_duration_ms'])
                    ? max(0, (int)$automation['last_duration_ms'])
                    : null,
            ],
            'offsite'=>[
                'enabled'=>$offsiteEnabled,
                'status'=>$offsiteStatus,
                'last_success_at'=>is_string($driveState['last_success_at'] ?? null)
                    ? (string)$driveState['last_success_at']
                    : null,
                'last_error'=>$lastError,
            ],
            'source_at'=>$sourceAt,
            'read_only'=>true,
        ];
    } catch (Throwable) {
        return [
            'status'=>'unavailable',
            'freshness'=>'unavailable',
            'backup_state'=>'unavailable',
            'latest_backup'=>null,
            'automation'=>[
                'enabled'=>false,
                'next_run_at'=>null,
                'last_success_at'=>null,
                'last_result_status'=>'unknown',
                'last_duration_ms'=>null,
            ],
            'offsite'=>[
                'enabled'=>false,
                'status'=>'disabled',
                'last_success_at'=>null,
                'last_error'=>null,
            ],
            'source_at'=>null,
            'read_only'=>true,
        ];
    }
}
