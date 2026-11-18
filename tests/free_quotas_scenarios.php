<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/free_quotas.php';

$case = $argv[1] ?? 'normal';
$now = gmmktime(17, 0, 0, 10, 30, 2026);
$counts = ['normal' => 35, 'warning' => 85, 'exhausted' => 100, 'precision' => 1599, 'unknown' => null];
if (!array_key_exists($case, $counts)) throw new RuntimeException('Invalid case');
$values = ['BRVTAL_FREE_QUOTA_WARNING_PERCENT' => '80'];
if ($counts[$case] !== null) {
    $values += [
        'BRVTAL_FREE_MAIL_USED' => (string)$counts[$case],
        'BRVTAL_FREE_MAIL_LIMIT' => $case === 'precision' ? '2000' : '100',
        'BRVTAL_FREE_MAIL_MEASURED_AT' => '2026-10-30T16:58:00Z',
    ];
}
$snapshot = brvtalFreeQuotaSnapshot($values, $now);
echo json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
