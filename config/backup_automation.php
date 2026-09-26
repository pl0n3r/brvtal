<?php
declare(strict_types=1);

require_once __DIR__ . '/backups.php';

function brvtal_backup_automation_dir(?string $baseDir = null): string
{
    return brvtal_backup_base_dir($baseDir) . '/.automation';
}

function brvtal_backup_automation_defaults(): array
{
    return [
        'version' => 1,
        'config' => [
            'enabled' => false,
            'timezone' => 'America/Bogota',
            'cadence' => ['type'=>'daily', 'interval'=>1, 'time'=>'03:00'],
            'scope' => 'full',
            'include_media_archive' => false,
            'retention_local' => 7,
            'drive' => ['enabled'=>false, 'folder'=>null],
        ],
        'next_run_at' => null,
        'last_run_at' => null,
        'last_success_at' => null,
        'last_result' => null,
        'last_duration_ms' => null,
        'in_progress' => null,
        'drive' => ['connected'=>false, 'last_success_at'=>null, 'last_error'=>null],
        'updated_at' => null,
    ];
}

function brvtal_backup_automation_normalize_config(array $input): array
{
    $defaults = brvtal_backup_automation_defaults()['config'];
    $cadenceInput = is_array($input['cadence'] ?? null) ? $input['cadence'] : [];
    $type = strtolower(trim((string)($cadenceInput['type'] ?? $defaults['cadence']['type'])));
    if (!in_array($type, ['hours', 'days', 'daily'], true)) throw new InvalidArgumentException('BACKUP_SCHEDULE_TYPE_INVALID');

    $interval = (int)($cadenceInput['interval'] ?? 1);
    $maxInterval = $type === 'hours' ? 168 : 30;
    if ($type === 'daily') $interval = 1;
    if ($interval < 1 || $interval > $maxInterval) throw new InvalidArgumentException('BACKUP_SCHEDULE_INTERVAL_INVALID');

    $time = trim((string)($cadenceInput['time'] ?? $defaults['cadence']['time']));
    if (!preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $time)) throw new InvalidArgumentException('BACKUP_SCHEDULE_TIME_INVALID');

    $retention = (int)($input['retention_local'] ?? $defaults['retention_local']);
    if ($retention < 1 || $retention > 90) throw new InvalidArgumentException('BACKUP_RETENTION_INVALID');

    $scope = brvtal_backup_normalize_scope($input['scope'] ?? $defaults['scope']);
    $driveInput = is_array($input['drive'] ?? null) ? $input['drive'] : [];
    $folder = trim((string)($driveInput['folder'] ?? ''));
    if (mb_strlen($folder) > 160) throw new InvalidArgumentException('BACKUP_DRIVE_FOLDER_INVALID');

    return [
        'enabled' => (bool)($input['enabled'] ?? $defaults['enabled']),
        'timezone' => 'America/Bogota',
        'cadence' => ['type'=>$type, 'interval'=>$interval, 'time'=>$time],
        'scope' => $scope,
        'include_media_archive' => $scope !== 'database' && (bool)($input['include_media_archive'] ?? false),
        'retention_local' => $retention,
        'drive' => ['enabled'=>(bool)($driveInput['enabled'] ?? false), 'folder'=>$folder !== '' ? $folder : null],
    ];
}

function brvtal_backup_automation_next_run(array $config, ?DateTimeImmutable $after = null): string
{
    $config = brvtal_backup_automation_normalize_config($config);
    $utc = new DateTimeZone('UTC');
    $timezone = new DateTimeZone('America/Bogota');
    $after = ($after ?? new DateTimeImmutable('now', $utc))->setTimezone($timezone);
    $cadence = $config['cadence'];

    if ($cadence['type'] === 'hours') {
        return $after->modify('+' . (int)$cadence['interval'] . ' hours')->setTimezone($utc)->format(DATE_ATOM);
    }

    [$hour, $minute] = array_map(intval(...), explode(':', (string)$cadence['time']));
    $candidate = $after->setTime($hour, $minute, 0);
    if ($cadence['type'] === 'daily') {
        if ($candidate <= $after) $candidate = $candidate->modify('+1 day');
    } elseif ($candidate <= $after) {
        $candidate = $candidate->modify('+' . (int)$cadence['interval'] . ' days');
    }
    return $candidate->setTimezone($utc)->format(DATE_ATOM);
}

function brvtal_backup_automation_read(?string $baseDir = null): array
{
    $defaults = brvtal_backup_automation_defaults();
    $file = brvtal_backup_automation_dir($baseDir) . '/state.json';
    if (!is_file($file)) return $defaults;
    $decoded = json_decode((string)@file_get_contents($file), true);
    if (!is_array($decoded)) return $defaults;
    $state = array_replace_recursive($defaults, $decoded);
    try {
        $state['config'] = brvtal_backup_automation_normalize_config(is_array($state['config'] ?? null) ? $state['config'] : []);
    } catch (Throwable) {
        $state['config'] = $defaults['config'];
    }
    return $state;
}

function brvtal_backup_automation_write(array $state, ?string $baseDir = null): array
{
    $dir = brvtal_backup_automation_dir($baseDir);
    brvtal_backup_ensure_private_dir($dir);
    $state['version'] = 1;
    $state['updated_at'] = gmdate(DATE_ATOM);
    $state['config'] = brvtal_backup_automation_normalize_config(is_array($state['config'] ?? null) ? $state['config'] : []);
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) throw new RuntimeException('BACKUP_AUTOMATION_JSON_FAILED');

    $target = $dir . '/state.json';
    $tmp = $target . '.part-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('BACKUP_AUTOMATION_WRITE_FAILED');
    @chmod($tmp, 0600);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('BACKUP_AUTOMATION_FINALIZE_FAILED');
    }
    @chmod($target, 0600);
    return $state;
}

function brvtal_backup_automation_save_config_locked(
    array $input,
    ?string $baseDir = null,
    ?DateTimeImmutable $now = null
): array {
    $state = brvtal_backup_automation_read($baseDir);
    $state['config'] = brvtal_backup_automation_normalize_config($input);
    $state['next_run_at'] = $state['config']['enabled']
        ? brvtal_backup_automation_next_run($state['config'], $now)
        : null;
    $state['in_progress'] = null;
    return brvtal_backup_automation_write($state, $baseDir);
}

function brvtal_backup_automation_save_config(
    array $input,
    ?string $baseDir = null,
    ?DateTimeImmutable $now = null
): array {
    $result = brvtal_backup_automation_with_lock(
        static fn(): array => brvtal_backup_automation_save_config_locked($input, $baseDir, $now),
        $baseDir
    );
    if (($result['status'] ?? null) === 'busy') {
        throw new RuntimeException('BACKUP_SCHEDULER_BUSY');
    }
    return $result;
}

function brvtal_backup_automation_with_lock(callable $callback, ?string $baseDir = null): array
{
    $dir = brvtal_backup_automation_dir($baseDir);
    brvtal_backup_ensure_private_dir($dir);
    $path = $dir . '/scheduler.lock';
    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) throw new RuntimeException('BACKUP_SCHEDULER_LOCK_OPEN_FAILED');
    @chmod($path, 0600);
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return ['status'=>'busy'];
    }
    try {
        return $callback();
    } finally {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function brvtal_backup_automation_next_after_now(
    array $config,
    DateTimeImmutable $due,
    DateTimeImmutable $now
): string {
    $next = brvtal_backup_automation_next_run($config, $due);
    $nextDate = new DateTimeImmutable($next)->setTimezone(new DateTimeZone('UTC'));
    if ($nextDate <= $now) {
        return brvtal_backup_automation_next_run($config, $now);
    }
    return $next;
}

function brvtal_backup_automation_existing_occurrence(string $scheduledFor, ?string $baseDir = null): ?array
{
    foreach (brvtal_backup_list($baseDir) as $manifest) {
        if (($manifest['trigger'] ?? null) === 'scheduled' && ($manifest['scheduled_for'] ?? null) === $scheduledFor) return $manifest;
    }
    return null;
}

function brvtal_backup_automation_prune(int $keep, ?string $baseDir = null): array
{
    $keep = max(1, min(90, $keep));
    $scheduled = array_values(array_filter(brvtal_backup_list($baseDir), static fn(array $manifest): bool => ($manifest['trigger'] ?? null) === 'scheduled'));
    $removed = [];
    foreach (array_slice($scheduled, $keep) as $manifest) {
        $id = (string)($manifest['id'] ?? '');
        if (!brvtal_backup_valid_id($id)) continue;
        brvtal_backup_cleanup($manifest, $baseDir);
        $removed[] = $id;
    }
    return $removed;
}

function brvtal_backup_drive_deliver(array $manifest, array $driveConfig, ?callable $transport = null): array
{
    if (empty($driveConfig['enabled'])) return ['status'=>'disabled'];
    if ($transport === null) return ['status'=>'failed', 'error'=>'DRIVE_NOT_CONNECTED'];
    try {
        $result = $transport($manifest, ['folder'=>$driveConfig['folder'] ?? null]);
        return ['status'=>'success','remote_id'=>is_array($result) && isset($result['remote_id']) ? (string)$result['remote_id'] : null];
    } catch (Throwable) {
        return ['status'=>'failed', 'error'=>'DRIVE_UPLOAD_FAILED'];
    }
}

function brvtal_backup_automation_public_state(array $state): array
{
    return [
        'config' => brvtal_backup_automation_normalize_config(is_array($state['config'] ?? null) ? $state['config'] : []),
        'next_run_at' => $state['next_run_at'] ?? null,
        'last_run_at' => $state['last_run_at'] ?? null,
        'last_success_at' => $state['last_success_at'] ?? null,
        'last_result' => $state['last_result'] ?? null,
        'last_duration_ms' => isset($state['last_duration_ms']) ? (int)$state['last_duration_ms'] : null,
        'drive' => ['connected'=>false,'last_success_at'=>$state['drive']['last_success_at'] ?? null,'last_error'=>$state['drive']['last_error'] ?? null],
    ];
}

function brvtal_backup_automation_run(PDO $pdo, array $options = []): array
{
    $baseDir = isset($options['base_dir']) ? (string)$options['base_dir'] : null;
    $uploadsDir = isset($options['uploads_dir']) ? (string)$options['uploads_dir'] : null;
    $driveTransport = is_callable($options['drive_transport'] ?? null) ? $options['drive_transport'] : null;
    $now = $options['now'] ?? null;
    $now = $now instanceof DateTimeImmutable ? $now->setTimezone(new DateTimeZone('UTC')) : new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return brvtal_backup_automation_with_lock(function () use ($pdo, $baseDir, $uploadsDir, $driveTransport, $now): array {
        $state = brvtal_backup_automation_read($baseDir);
        $config = brvtal_backup_automation_normalize_config($state['config'] ?? []);
        if (!$config['enabled']) return ['status'=>'disabled'];

        if (!is_string($state['next_run_at'] ?? null) || trim((string)$state['next_run_at']) === '') {
            $state['next_run_at'] = brvtal_backup_automation_next_run($config, $now);
            brvtal_backup_automation_write($state, $baseDir);
            return ['status'=>'not_due','next_run_at'=>$state['next_run_at']];
        }

        try {
            $due = new DateTimeImmutable((string)$state['next_run_at'])->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            $due = $now;
        }
        if ($now < $due) return ['status'=>'not_due','next_run_at'=>$due->format(DATE_ATOM)];

        $occurrence = $due->format(DATE_ATOM);
        $existing = brvtal_backup_automation_existing_occurrence($occurrence, $baseDir);
        if ($existing !== null) {
            $state['last_run_at'] = $now->format(DATE_ATOM);
            $state['last_success_at'] = $now->format(DATE_ATOM);
            $state['last_result'] = ['status'=>'success','backup_id'=>$existing['id'] ?? null,'local'=>'success','drive'=>'unknown','recovered'=>true];
            $state['last_duration_ms'] = 0;
            $state['next_run_at'] = brvtal_backup_automation_next_after_now($config, $due, $now);
            $state['in_progress'] = null;
            brvtal_backup_automation_prune((int)$config['retention_local'], $baseDir);
            brvtal_backup_automation_write($state, $baseDir);
            return ['status'=>'success','backup'=>$existing,'recovered'=>true,'next_run_at'=>$state['next_run_at']];
        }

        $started = microtime(true);
        $state['in_progress'] = ['scheduled_for'=>$occurrence,'started_at'=>$now->format(DATE_ATOM)];
        brvtal_backup_automation_write($state, $baseDir);

        try {
            $createOptions = [
                'base_dir'=>$baseDir,
                'scope'=>$config['scope'],
                'include_media_archive'=>$config['include_media_archive'],
                'trigger'=>'scheduled',
                'scheduled_for'=>$occurrence,
                'created_by'=>['id'=>null,'name'=>'BRVTAL Scheduler'],
            ];
            if ($uploadsDir !== null && trim($uploadsDir) !== '') $createOptions['uploads_dir'] = $uploadsDir;
            $manifest = brvtal_backup_create($pdo, $createOptions);
            $drive = brvtal_backup_drive_deliver($manifest, $config['drive'], $driveTransport);
            $finished = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $localStatus = ($manifest['status'] ?? null) === 'ready' ? 'success' : 'partial';
            $state['last_run_at'] = $finished->format(DATE_ATOM);
            if ($localStatus === 'success') $state['last_success_at'] = $finished->format(DATE_ATOM);
            $state['last_result'] = ['status'=>$localStatus,'backup_id'=>$manifest['id'] ?? null,'local'=>$localStatus,'drive'=>$drive['status']];
            if ($drive['status'] === 'success') {
                $state['drive']['last_success_at'] = $finished->format(DATE_ATOM);
                $state['drive']['last_error'] = null;
            } elseif ($drive['status'] === 'failed') {
                $state['drive']['last_error'] = $drive['error'] ?? 'DRIVE_UPLOAD_FAILED';
            }
            $state['last_duration_ms'] = (int)round((microtime(true) - $started) * 1000);
            $state['next_run_at'] = brvtal_backup_automation_next_after_now($config, $due, $now);
            $state['in_progress'] = null;
            $removed = brvtal_backup_automation_prune((int)$config['retention_local'], $baseDir);
            brvtal_backup_automation_write($state, $baseDir);
            return ['status'=>$localStatus,'backup'=>$manifest,'drive'=>$drive,'retention_removed'=>$removed,'next_run_at'=>$state['next_run_at']];
        } catch (Throwable) {
            $finished = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $state['last_run_at'] = $finished->format(DATE_ATOM);
            $state['last_result'] = ['status'=>'failed','local'=>'failed','drive'=>'skipped','error'=>'BACKUP_CREATE_FAILED'];
            $state['last_duration_ms'] = (int)round((microtime(true) - $started) * 1000);
            $state['next_run_at'] = brvtal_backup_automation_next_after_now($config, $due, $now);
            $state['in_progress'] = null;
            brvtal_backup_automation_write($state, $baseDir);
            return ['status'=>'failed','error'=>'BACKUP_CREATE_FAILED','next_run_at'=>$state['next_run_at']];
        }
    }, $baseDir);
}
