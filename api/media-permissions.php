<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/media.php';

brvtal_admin_require();
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    brvtal_media_json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}
brvtal_admin_require_csrf();

function brvtal_media_make_publicly_readable(string $absolutePath): bool
{
    $root = realpath(brvtal_media_upload_root());
    $real = realpath($absolutePath);
    if ($root === false || $real === false || !is_file($real)) {
        return false;
    }

    $root = rtrim(str_replace('\\', '/', $root), '/');
    $real = str_replace('\\', '/', $real);
    if (!str_starts_with($real, $root . '/')) {
        return false;
    }

    $dir = dirname($real);
    while ($dir !== $root && str_starts_with($dir, $root . '/')) {
        @chmod($dir, 0755);
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    @chmod($root, 0755);
    return @chmod($real, 0644);
}

try {
    $pdo = db();
    $rows = $pdo->query("SELECT id,file_path,type FROM media WHERE file_path LIKE '/uploads/%' ORDER BY id ASC")->fetchAll();
    $checked = 0;
    $repaired = 0;

    foreach ($rows ?: [] as $row) {
        $path = (string)($row['file_path'] ?? '');
        $absolute = brvtal_media_local_absolute($path);
        if ($absolute === null) {
            continue;
        }
        $checked++;
        if (brvtal_media_make_publicly_readable($absolute)) {
            $repaired++;
        }

        if (($row['type'] ?? '') !== 'image') {
            continue;
        }
        $sidecar = brvtal_media_read_sidecar($path);
        if (!is_array($sidecar['variants'] ?? null)) {
            continue;
        }
        foreach ($sidecar['variants'] as $variant) {
            $variantAbsolute = brvtal_media_local_absolute((string)($variant['path'] ?? ''));
            if ($variantAbsolute !== null) {
                $checked++;
                if (brvtal_media_make_publicly_readable($variantAbsolute)) {
                    $repaired++;
                }
            }
        }
    }

    brvtal_media_json_response([
        'ok' => true,
        'checked' => $checked,
        'repaired' => $repaired,
    ]);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('MEDIA_PERMISSION_ERROR', 'Unable to repair public media permissions', [
            'class' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
    brvtal_media_json_response(['ok' => false, 'error' => 'MEDIA_PERMISSION_REPAIR_FAILED'], 500);
}
