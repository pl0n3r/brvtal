<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/free_quotas.php';

$case = $argv[1] ?? 'normal';
$now = gmmktime(17, 0, 0, 10, 30, 2026);
$path = tempnam(sys_get_temp_dir(), 'brvtal-quota-');
if ($path === false) throw new RuntimeException('Fixture unavailable');
register_shutdown_function(static function () use ($path): void { @unlink($path); });
$charCounts = ['normal' => 35, 'warning' => 85, 'exhausted' => 100, 'unknown' => -1];
if (!array_key_exists($case, $charCounts)) throw new RuntimeException('Invalid case');
if ($case !== 'unknown') {
    file_put_contents($path, json_encode(['month' => '2026-10', 'chars' => $charCounts[$case]], JSON_THROW_ON_ERROR));
    touch($path, $now - 120);
} else {
    file_put_contents($path, '{invalid json');
}
$values = [
    'BRVTAL_DEEPL_MONTHLY_CHARS' => '100',
    'BRVTAL_FREE_QUOTA_WARNING_PERCENT' => '80',
];
if ($case === 'warning') {
    $values += [
        'BRVTAL_FREE_MAIL_USED' => '40',
        'BRVTAL_FREE_MAIL_LIMIT' => '100',
        'BRVTAL_FREE_MAIL_MEASURED_AT' => '2026-10-30T16:58:00Z',
    ];
}
$snapshot = brvtalFreeQuotaSnapshot($values, $path, $now);
echo json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
