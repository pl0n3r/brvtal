<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
brvtal_admin_require();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__);
$defaultQuota = 25 * 1024 * 1024 * 1024;
$configuredQuota = (int)($config['hosting']['storage_quota_bytes'] ?? 0);
$envQuota = (int)(getenv('BRVTAL_STORAGE_QUOTA_BYTES') ?: 0);
$quota = $configuredQuota > 0 ? $configuredQuota : ($envQuota > 0 ? $envQuota : $defaultQuota);
$quotaSource = $configuredQuota > 0 ? 'config' : ($envQuota > 0 ? 'environment' : 'hostinger_plan_fallback');

$formatBytes = static function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = max(0, (float)$bytes);
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return number_format($value, $i === 0 ? 0 : 2) . ' ' . $units[$i];
};

$scanDirectory = static function (string $path): array {
    if (!is_dir($path)) return ['bytes'=>0, 'files'=>0];
    $bytes = 0;
    $files = 0;
    try {
        $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile() || $item->isLink()) continue;
            $bytes += max(0, (int)$item->getSize());
            $files++;
        }
    } catch (Throwable) {
        return ['bytes'=>$bytes, 'files'=>$files];
    }
    return ['bytes'=>$bytes, 'files'=>$files];
};

$cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'brvtal-managed-storage.json';
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$cacheTtl = 30;
$cached = null;
if (!$forceRefresh && is_file($cachePath) && (time() - (int)@filemtime($cachePath)) < $cacheTtl) {
    $decoded = json_decode((string)@file_get_contents($cachePath), true);
    if (is_array($decoded)) $cached = $decoded;
}

if (is_array($cached)) {
    $cached['cache'] = 'fresh';
    echo json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$uploads = $scanDirectory($root . '/uploads');
$storage = $scanDirectory($root . '/storage');
$used = (int)$uploads['bytes'] + (int)$storage['bytes'];
$free = max(0, $quota - $used);
$usedPercent = $quota > 0 ? round(min(100, ($used / $quota) * 100), 2) : 0.0;

$hostTotal = @disk_total_space($root);
$hostFree = @disk_free_space($root);

$payload = [
    'ok' => true,
    'scope' => 'brvtal_managed_data',
    'quota_source' => $quotaSource,
    'quota_bytes' => $quota,
    'quota' => $formatBytes($quota),
    'used_bytes' => $used,
    'used' => $formatBytes($used),
    'free_bytes' => $free,
    'free' => $formatBytes($free),
    'used_percent' => $usedPercent,
    'managed_files' => (int)$uploads['files'] + (int)$storage['files'],
    'directories' => [
        'uploads' => ['bytes'=>(int)$uploads['bytes'], 'size'=>$formatBytes((int)$uploads['bytes']), 'files'=>(int)$uploads['files']],
        'storage' => ['bytes'=>(int)$storage['bytes'], 'size'=>$formatBytes((int)$storage['bytes']), 'files'=>(int)$storage['files']],
    ],
    'host_filesystem' => [
        'total_bytes' => $hostTotal === false ? null : (int)$hostTotal,
        'free_bytes' => $hostFree === false ? null : (int)$hostFree,
        'total' => $hostTotal === false ? 'N/A' : $formatBytes((int)$hostTotal),
        'free' => $hostFree === false ? 'N/A' : $formatBytes((int)$hostFree),
        'diagnostic_only' => true,
    ],
    'cache' => 'refreshed',
    'generated_at' => date(DATE_ATOM),
];

@file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
