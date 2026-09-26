<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/backup_automation.php';

function backup_auto_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "BACKUP AUTOMATION CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$config = brvtal_backup_automation_normalize_config([
    'enabled'=>true,
    'cadence'=>['type'=>'daily','interval'=>1,'time'=>'03:00'],
    'scope'=>'database',
    'include_media_archive'=>true,
    'retention_local'=>3,
    'drive'=>['enabled'=>true,'folder'=>'BRVTAL'],
    'refresh_token'=>'must-not-survive',
]);
backup_auto_expect($config['timezone'] === 'America/Bogota', 'timezone must be fixed to America/Bogota');
backup_auto_expect($config['scope'] === 'database', 'database scope must normalize');
backup_auto_expect($config['include_media_archive'] === false, 'database-only schedule cannot request media archive');
backup_auto_expect(!array_key_exists('refresh_token', $config), 'unknown secret-like keys must not persist');

$next = brvtal_backup_automation_next_run($config, new DateTimeImmutable('2026-09-26T07:59:00Z'));
backup_auto_expect($next === '2026-09-26T08:00:00+00:00', 'daily 03:00 Bogota schedule must honor local time');
$after = brvtal_backup_automation_next_run($config, new DateTimeImmutable('2026-09-26T08:01:00Z'));
backup_auto_expect($after === '2026-09-27T08:00:00+00:00', 'daily schedule must advance after boundary');

$hourly = brvtal_backup_automation_normalize_config([
    'enabled'=>true,
    'cadence'=>['type'=>'hours','interval'=>6,'time'=>'03:00'],
    'scope'=>'full',
    'retention_local'=>5,
]);
backup_auto_expect(brvtal_backup_automation_next_run($hourly, new DateTimeImmutable('2026-09-26T12:00:00Z')) === '2026-09-26T18:00:00+00:00', 'hour cadence must advance');

$missedHourly = brvtal_backup_automation_normalize_config([
    'enabled'=>true,
    'cadence'=>['type'=>'hours','interval'=>1,'time'=>'03:00'],
    'scope'=>'database',
    'retention_local'=>3,
]);
$collapsed = brvtal_backup_automation_next_after_now(
    $missedHourly,
    new DateTimeImmutable('2026-09-23T12:00:00Z'),
    new DateTimeImmutable('2026-09-26T12:00:00Z')
);
backup_auto_expect(
    $collapsed === '2026-09-26T13:00:00+00:00',
    'missed hourly occurrences must collapse into one future run'
);

$invalid = false;
try {
    brvtal_backup_automation_normalize_config(['cadence'=>['type'=>'hours','interval'=>0,'time'=>'03:00']]);
} catch (InvalidArgumentException) {
    $invalid = true;
}
backup_auto_expect($invalid, 'zero interval must fail closed');

$tmp = sys_get_temp_dir() . '/brvtal-backup-auto-' . bin2hex(random_bytes(4));
brvtal_backup_ensure_private_dir($tmp);
try {
    $state = brvtal_backup_automation_save_config([
        'enabled'=>true,
        'cadence'=>['type'=>'days','interval'=>2,'time'=>'04:30'],
        'scope'=>'media',
        'retention_local'=>2,
        'drive'=>['enabled'=>false],
    ], $tmp, new DateTimeImmutable('2026-09-26T10:00:00Z'));
    $encoded = (string)file_get_contents(brvtal_backup_automation_dir($tmp) . '/state.json');
    backup_auto_expect(!str_contains($encoded, 'access_token'), 'state must not contain access-token fields');
    backup_auto_expect(($state['config']['scope'] ?? null) === 'media', 'saved config must preserve scope');

    $lockPath = brvtal_backup_automation_dir($tmp) . '/scheduler.lock';
    $first = fopen($lockPath, 'c+');
    backup_auto_expect(is_resource($first) && flock($first, LOCK_EX | LOCK_NB), 'test must acquire scheduler lock');
    $busy = brvtal_backup_automation_with_lock(static fn(): array => ['status'=>'unexpected'], $tmp);
    backup_auto_expect(($busy['status'] ?? null) === 'busy', 'overlapping scheduler invocation must return busy');
    flock($first, LOCK_UN);
    fclose($first);

    $drive = brvtal_backup_drive_deliver(
        ['id'=>'brvtal-20260926T100000Z-abcdef12'],
        ['enabled'=>true,'folder'=>'BRVTAL'],
        static fn(array $manifest, array $options): array => ['remote_id'=>'fake-' . $manifest['id']]
    );
    backup_auto_expect(($drive['status'] ?? null) === 'success', 'fake Drive transport must be injectable');

    $ids = [
        'brvtal-20260926T100000Z-aaaabbbb',
        'brvtal-20260926T090000Z-ccccdddd',
        'brvtal-20260926T080000Z-eeeeffff',
        'brvtal-20260926T070000Z-11112222',
    ];
    foreach ($ids as $index => $id) {
        brvtal_backup_write_json($tmp . '/' . $id . '.manifest.json', [
            'version'=>1,'id'=>$id,'status'=>'ready','scope'=>'full',
            'trigger'=>$index === 3 ? 'manual' : 'scheduled',
            'created_at'=>sprintf('2026-09-26T%02d:00:00+00:00', 10 - $index),
            'components'=>[
                'database'=>['status'=>'not_requested'],
                'media_manifest'=>['status'=>'not_requested'],
                'media_archive'=>['status'=>'not_requested'],
            ],
            'artifacts_bytes'=>0,'artifacts_size'=>'0 B','restore_supported'=>false,
        ]);
    }
    $removed = brvtal_backup_automation_prune(2, $tmp);
    backup_auto_expect(count($removed) === 1 && $removed[0] === $ids[2], 'retention must prune only old scheduled backups');
    backup_auto_expect(is_file($tmp . '/' . $ids[3] . '.manifest.json'), 'manual backup must survive retention');
} finally {
    $remove = static function(string $path) use (&$remove): void {
        if (!file_exists($path)) return;
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                $remove($path . DIRECTORY_SEPARATOR . $name);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    };
    $remove($tmp);
}

echo "BRVTAL backup automation contract passed.\n";
