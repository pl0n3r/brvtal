<?php
declare(strict_types=1);

function admin_logs_cache_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ADMIN LOGS CACHE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$source = (string)file_get_contents(__DIR__ . '/../discadmin/logs.php');

admin_logs_cache_assert(str_contains($source, 'brvtal_admin_require();'), 'debug logs remain authenticated');
admin_logs_cache_assert(
    str_contains($source, "header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');"),
    'authenticated log responses must explicitly disable storage'
);
admin_logs_cache_assert(
    str_contains($source, "header('Pragma: no-cache');"),
    'authenticated log responses keep legacy no-cache compatibility'
);
admin_logs_cache_assert(
    str_contains($source, "header('X-Content-Type-Options: nosniff');"),
    'authenticated log responses keep nosniff protection'
);
admin_logs_cache_assert(
    str_contains($source, "header('X-Robots-Tag: noindex, nofollow, noarchive');"),
    'authenticated log responses disable indexing at HTTP level'
);

$authPos = strpos($source, 'brvtal_admin_require();');
$cachePos = strpos($source, "header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');");
$actionPos = strpos($source, "\$action = (string)(\$_GET['action'] ?? '');");
admin_logs_cache_assert(
    is_int($authPos) && is_int($cachePos) && is_int($actionPos) && $authPos < $cachePos && $cachePos < $actionPos,
    'no-store policy must apply after authentication and before every log action branch'
);

admin_logs_cache_assert(
    str_contains($source, "=== 'download' && is_file("),
    'download action remains inside the shared response policy'
);
admin_logs_cache_assert(
    str_contains($source, "=== 'clear'"),
    'clear action remains inside the shared response policy'
);
admin_logs_cache_assert(
    str_contains($source, '<meta name="robots" content="noindex,nofollow,noarchive">'),
    'HTML view keeps the existing robots meta fallback'
);

echo "BRVTAL admin logs cache contract passed.\n";
