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
            $id = $latest['id'] ?? null;
            $scope = $latest['scope'] ?? null;
            $trigger = $latest['trigger'] ?? null;
            $createdAt = $latest['created_at'] ?? null;
            $artifactsBytes = $latest['artifacts_bytes'] ?? null;
            if (
                !is_string($id)
                || !brvtal_backup_valid_id($id)
                || !in_array($rawStatus, ['ready', 'partial'], true)
                || !is_string($scope)
                || !in_array($scope, ['full', 'database', 'media'], true)
                || !is_string($trigger)
                || !in_array($trigger, ['manual', 'scheduled'], true)
                || !is_string($createdAt)
                || trim($createdAt) === ''
                || !is_int($artifactsBytes)
                || $artifactsBytes < 0
            ) {
                throw new RuntimeException('BACKUP_SIGNAL_LATEST_INVALID');
            }
            $backupState = $rawStatus;
            $latestBackup = [
                'id'=>$id,
                'status'=>$backupState,
                'scope'=>$scope,
                'trigger'=>$trigger,
                'created_at'=>$createdAt,
                'artifacts_bytes'=>$artifactsBytes,
            ];
        }

        $config = $automation['config'] ?? null;
        if (
            !is_array($config)
            || !array_key_exists('enabled', $config)
            || !is_bool($config['enabled'])
            || !is_array($config['drive'] ?? null)
            || !array_key_exists('enabled', $config['drive'])
            || !is_bool($config['drive']['enabled'])
        ) {
            throw new RuntimeException('BACKUP_AUTOMATION_STATE_INVALID');
        }
        $enabled = $config['enabled'];
        $driveConfig = $config['drive'];
        $offsiteEnabled = $driveConfig['enabled'];

        $lastResultRaw = $automation['last_result'] ?? null;
        if ($lastResultRaw !== null && !is_array($lastResultRaw)) {
            throw new RuntimeException('BACKUP_AUTOMATION_STATE_INVALID');
        }
        $lastResult = is_array($lastResultRaw) ? $lastResultRaw : [];

        $driveState = $automation['drive'] ?? null;
        if (!is_array($driveState)) {
            throw new RuntimeException('BACKUP_AUTOMATION_STATE_INVALID');
        }

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
            $lastError = preg_match('/^[A-Z0-9_]{1,80}$/', $candidate) === 1
                ? $candidate
                : 'OFFSITE_FAILED';
        }

        $nextRunAt = is_string($automation['next_run_at'] ?? null)
            && trim((string)$automation['next_run_at']) !== ''
                ? (string)$automation['next_run_at']
                : null;
        $lastDuration = $automation['last_duration_ms'] ?? null;
        if ($lastDuration !== null && (!is_int($lastDuration) || $lastDuration < 0)) {
            throw new RuntimeException('BACKUP_AUTOMATION_STATE_INVALID');
        }

        $degraded = (
            $backupState !== 'ready'
            || ($enabled && $nextRunAt === null)
            || ($enabled && in_array($lastResultStatus, ['partial', 'failed'], true))
            || ($offsiteEnabled && $offsiteStatus !== 'success')
        );

        return [
            'status'=>$degraded ? 'degraded' : 'available',
            'freshness'=>$degraded ? 'degraded' : 'fresh',
            'backup_state'=>$backupState,
            'latest_backup'=>$latestBackup,
            'automation'=>[
                'enabled'=>$enabled,
                'next_run_at'=>$nextRunAt,
                'last_success_at'=>is_string($automation['last_success_at'] ?? null)
                    ? (string)$automation['last_success_at']
                    : null,
                'last_result_status'=>$lastResultStatus,
                'last_duration_ms'=>$lastDuration,
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
