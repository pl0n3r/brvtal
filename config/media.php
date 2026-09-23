<?php
declare(strict_types=1);

/**
 * BRVTAL Media Engine helpers.
 *
 * Keeps the original upload immutable, derives optional image variants on the
 * filesystem, and computes references from existing content before deletion.
 * No database migration is required for this first Media Engine phase.
 */

function brvtal_media_upload_root(): string
{
    return dirname(__DIR__) . '/uploads';
}

function brvtal_media_public_upload_path(string $absolutePath): ?string
{
    $root = realpath(brvtal_media_upload_root());
    $real = realpath($absolutePath);
    if ($root === false || $real === false) {
        return null;
    }
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $real = str_replace('\\', '/', $real);
    if ($real !== $root && !str_starts_with($real, $root . '/')) {
        return null;
    }
    return '/uploads' . substr($real, strlen($root));
}

function brvtal_media_local_absolute(?string $publicPath): ?string
{
    $path = trim((string)$publicPath);
    if ($path === '' || !str_starts_with($path, '/uploads/')) {
        return null;
    }
    if (str_contains($path, '..') || str_contains($path, "\0")) {
        return null;
    }

    $root = realpath(brvtal_media_upload_root());
    if ($root === false) {
        return null;
    }

    $candidate = dirname(__DIR__) . '/' . ltrim($path, '/');
    $real = realpath($candidate);
    if ($real === false) {
        return null;
    }

    $root = rtrim(str_replace('\\', '/', $root), '/');
    $real = str_replace('\\', '/', $real);
    if ($real !== $root && !str_starts_with($real, $root . '/')) {
        return null;
    }
    return $real;
}

/**
 * Classify one image reference without performing any remote network request.
 *
 * External HTTP(S) references are structurally usable because availability is
 * a browser concern; local uploads are usable only when the file resolves
 * inside the uploads root and is a decodable image.
 *
 * @return array{valid:bool,usable:bool,kind:string}
 */
function brvtalMediaImageReferenceState(mixed $value): array
{
    static $cache = [];

    $reference = trim((string)$value);
    if (isset($cache[$reference])) {
        return $cache[$reference];
    }

    if ($reference === '') {
        $result = ['valid' => true, 'usable' => false, 'kind' => 'empty'];
        $cache[$reference] = $result;
        return $result;
    }

    if (filter_var($reference, FILTER_VALIDATE_URL) !== false) {
        $scheme = strtolower((string)parse_url($reference, PHP_URL_SCHEME));
        $hasCredentials = parse_url($reference, PHP_URL_USER) !== null
            || parse_url($reference, PHP_URL_PASS) !== null;
        $valid = in_array($scheme, ['http', 'https'], true) && !$hasCredentials;
        $result = [
            'valid' => $valid,
            'usable' => $valid,
            'kind' => $valid ? 'external' : 'invalid',
        ];
        $cache[$reference] = $result;
        return $result;
    }

    $decodedReference = rawurldecode($reference);
    if (!str_starts_with($reference, '/uploads/')
        || !str_starts_with($decodedReference, '/uploads/')
        || str_contains($decodedReference, '..')
        || str_contains($reference, '\\')
        || str_contains($reference, "\0")) {
        $result = ['valid' => false, 'usable' => false, 'kind' => 'invalid'];
        $cache[$reference] = $result;
        return $result;
    }

    $absolute = brvtal_media_local_absolute($reference);
    if ($absolute === null || !is_file($absolute)) {
        $result = ['valid' => true, 'usable' => false, 'kind' => 'local_missing'];
        $cache[$reference] = $result;
        return $result;
    }

    if (@getimagesize($absolute) === false) {
        $result = ['valid' => true, 'usable' => false, 'kind' => 'local_not_image'];
        $cache[$reference] = $result;
        return $result;
    }

    $result = ['valid' => true, 'usable' => true, 'kind' => 'local_image'];
    $cache[$reference] = $result;
    return $result;
}

/** Return whether one image reference is usable by public visual surfaces. */
function brvtalMediaImageReferenceUsable(mixed $value): bool
{
    return brvtalMediaImageReferenceState($value)['usable'];
}

function brvtal_media_make_public_readable(?string $publicPath): void
{
    $absolute = brvtal_media_local_absolute($publicPath);
    if ($absolute !== null && is_file($absolute)) {
        @chmod($absolute, 0644);
    }
}

function brvtal_media_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_media_sidecar_path(string $absoluteOriginal): string
{
    $info = pathinfo($absoluteOriginal);
    return $info['dirname'] . '/' . $info['filename'] . '.media.json';
}

function brvtal_media_read_sidecar(?string $publicPath): ?array
{
    $absolute = brvtal_media_local_absolute($publicPath);
    if ($absolute === null) {
        return null;
    }
    $sidecar = brvtal_media_sidecar_path($absolute);
    if (!is_file($sidecar)) {
        return null;
    }
    $decoded = json_decode((string)@file_get_contents($sidecar), true);
    return is_array($decoded) ? $decoded : null;
}

function brvtal_media_dimensions(?string $publicPath): ?array
{
    $absolute = brvtal_media_local_absolute($publicPath);
    if ($absolute === null || !is_file($absolute)) {
        return null;
    }
    $info = @getimagesize($absolute);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        return null;
    }
    return ['width' => (int)$info[0], 'height' => (int)$info[1]];
}

function brvtal_media_asset_payload(array $row): array
{
    $row['id'] = (int)($row['id'] ?? 0);
    $row['file_size'] = (int)($row['file_size'] ?? 0);
    $row['engine'] = null;
    $row['dimensions'] = null;
    $row['warnings'] = [];

    if (($row['type'] ?? '') === 'image') {
        brvtal_media_make_public_readable((string)($row['file_path'] ?? ''));
        $sidecar = brvtal_media_read_sidecar((string)($row['file_path'] ?? ''));
        if ($sidecar !== null) {
            $row['engine'] = $sidecar;
            if (isset($sidecar['original']['width'], $sidecar['original']['height'])) {
                $row['dimensions'] = [
                    'width' => (int)$sidecar['original']['width'],
                    'height' => (int)$sidecar['original']['height'],
                ];
            }
            if (is_array($sidecar['variants'] ?? null)) {
                foreach ($sidecar['variants'] as $variant) {
                    brvtal_media_make_public_readable((string)($variant['path'] ?? ''));
                }
            }
        }
        if ($row['dimensions'] === null) {
            $row['dimensions'] = brvtal_media_dimensions((string)($row['file_path'] ?? ''));
        }
        if (is_array($row['dimensions'])) {
            $w = (int)$row['dimensions']['width'];
            $h = (int)$row['dimensions']['height'];
            if ($w < 1200 || $h < 1200) {
                $row['warnings'][] = 'LOW_RESOLUTION';
            }
            $row['quality'] = brvtal_media_quality_guidance($w, $h);
        }
        if ($row['file_size'] > 12 * 1024 * 1024) {
            $row['warnings'][] = 'LARGE_FILE';
        }
    }

    return $row;
}

function brvtal_media_quality_guidance(int $width, int $height): array
{
    $contexts = [
        'square' => ['label'=>'GRID / AVATAR', 'width'=>800, 'height'=>800],
        'card' => ['label'=>'CONTENT CARD', 'width'=>1200, 'height'=>900],
        'hero' => ['label'=>'EVENT HERO', 'width'=>1920, 'height'=>1080],
    ];
    foreach ($contexts as &$context) {
        $context['ready'] = $width >= $context['width'] && $height >= $context['height'];
    }
    unset($context);
    return ['grade'=>($width >= 1920 && $height >= 1080 ? 'excellent' : ($width >= 1200 && $height >= 900 ? 'good' : 'limited')), 'contexts'=>$contexts];
}

function brvtal_media_usage(PDO $pdo, array $media): array
{
    $path = trim((string)($media['file_path'] ?? ''));
    if ($path === '') {
        return [];
    }

    $refs = [];
    $exact = [
        ['table' => 'events', 'field' => 'cover_image', 'label' => 'EVENT', 'title' => 'title'],
        ['table' => 'events', 'field' => 'ticket_qr', 'label' => 'EVENT QR', 'title' => 'title'],
        ['table' => 'artists', 'field' => 'photo', 'label' => 'ARTIST', 'title' => 'name'],
        ['table' => 'sets_media', 'field' => 'cover_image', 'label' => 'SET', 'title' => 'title'],
        ['table' => 'event_ticket_types', 'field' => 'qr_image', 'label' => 'TICKET QR', 'title' => 'name'],
        ['table' => 'releases', 'field' => 'artwork', 'label' => 'RELEASE', 'title' => 'title'],
        ['table' => 'blog_posts', 'field' => 'cover_image', 'label' => 'BLOG', 'title' => 'title'],
    ];

    foreach ($exact as $def) {
        try {
            $sql = sprintf(
                'SELECT id, `%s` AS ref_title FROM `%s` WHERE `%s` = ? LIMIT 100',
                $def['title'],
                $def['table'],
                $def['field']
            );
            $st = $pdo->prepare($sql);
            $st->execute([$path]);
            foreach ($st->fetchAll() as $row) {
                $refs[] = [
                    'resource' => $def['label'],
                    'id' => (int)$row['id'],
                    'field' => $def['field'],
                    'title' => (string)($row['ref_title'] ?? ''),
                ];
            }
        } catch (Throwable) {
            // Optional tables/columns may not exist on older installations.
        }
    }

    $contains = [
        ['table' => 'pages', 'field' => 'content_json', 'label' => 'PAGE', 'title' => 'title'],
        ['table' => 'settings', 'field' => 'setting_value', 'label' => 'SETTING', 'title' => 'setting_key'],
    ];
    $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $path) . '%';

    foreach ($contains as $def) {
        try {
            $idExpr = $def['table'] === 'settings' ? '0 AS id' : 'id';
            $sql = sprintf(
                "SELECT %s, `%s` AS ref_title FROM `%s` WHERE `%s` LIKE ? ESCAPE '\\\\' LIMIT 100",
                $idExpr,
                $def['title'],
                $def['table'],
                $def['field']
            );
            $st = $pdo->prepare($sql);
            $st->execute([$needle]);
            foreach ($st->fetchAll() as $row) {
                $refs[] = [
                    'resource' => $def['label'],
                    'id' => (int)($row['id'] ?? 0),
                    'field' => $def['field'],
                    'title' => (string)($row['ref_title'] ?? ''),
                ];
            }
        } catch (Throwable) {
            // Optional structures should not make the media library unavailable.
        }
    }

    return $refs;
}

function brvtal_media_create_image_resource(string $absolute, string $mime): GdImage|false
{
    if (!extension_loaded('gd')) {
        return false;
    }
    return match ($mime) {
        'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($absolute) : false,
        'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($absolute) : false,
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absolute) : false,
        default => false,
    };
}

function brvtal_media_write_variant(GdImage $source, int $sourceWidth, int $sourceHeight, string $target, int $targetWidth, int $targetHeight, bool $crop = false, float $focalX = .5, float $focalY = .5): bool
{
    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$canvas) {
        return false;
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);

    if ($crop) {
        $targetRatio = $targetWidth / $targetHeight;
        $sourceRatio = $sourceWidth / $sourceHeight;
        $cropWidth = $sourceRatio > $targetRatio ? (int)round($sourceHeight * $targetRatio) : $sourceWidth;
        $cropHeight = $sourceRatio > $targetRatio ? $sourceHeight : (int)round($sourceWidth / $targetRatio);
        $sx = (int)round(max(0, min($sourceWidth - $cropWidth, ($sourceWidth * $focalX) - ($cropWidth / 2))));
        $sy = (int)round(max(0, min($sourceHeight - $cropHeight, ($sourceHeight * $focalY) - ($cropHeight / 2))));
        imagecopyresampled($canvas, $source, 0, 0, $sx, $sy, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
    } else {
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    }

    $ok = function_exists('imagewebp') && @imagewebp($canvas, $target, 82);
    imagedestroy($canvas);
    if ($ok) {
        @chmod($target, 0644);
    }
    return $ok;
}

function brvtal_media_generate_variants(string $absoluteOriginal, string $mime, array $focalPoint = ['x'=>.5, 'y'=>.5]): array
{
    $info = @getimagesize($absoluteOriginal);
    $width = is_array($info) ? (int)($info[0] ?? 0) : 0;
    $height = is_array($info) ? (int)($info[1] ?? 0) : 0;
    $result = [
        'version' => 2,
        'generated_at' => date(DATE_ATOM),
        'status' => 'original_only',
        'original' => [
            'path' => brvtal_media_public_upload_path($absoluteOriginal),
            'width' => $width,
            'height' => $height,
            'mime_type' => $mime,
        ],
        'variants' => [],
        'focal_point' => ['x'=>max(0, min(1, (float)($focalPoint['x'] ?? .5))), 'y'=>max(0, min(1, (float)($focalPoint['y'] ?? .5)))],
    ];

    if ($width < 1 || $height < 1 || $width * $height > 40000000) {
        $result['reason'] = 'IMAGE_DIMENSIONS_UNSUPPORTED';
        return $result;
    }

    $source = brvtal_media_create_image_resource($absoluteOriginal, $mime);
    if (!$source || !function_exists('imagewebp')) {
        $result['reason'] = 'GD_WEBP_UNAVAILABLE';
        return $result;
    }

    $infoPath = pathinfo($absoluteOriginal);
    $base = $infoPath['dirname'] . '/' . $infoPath['filename'];

    $focalX = $result['focal_point']['x'];
    $focalY = $result['focal_point']['y'];
    $squareSize = min(800, max(1, min($width, $height)));
    $squarePath = $base . '--square-' . $squareSize . '.webp';
    if (brvtal_media_write_variant($source, $width, $height, $squarePath, $squareSize, $squareSize, true, $focalX, $focalY)) {
        $result['variants']['square'] = [
            'path' => brvtal_media_public_upload_path($squarePath),
            'width' => $squareSize,
            'height' => $squareSize,
            'mime_type' => 'image/webp',
        ];
    }

    foreach (['card'=>[1200,900], 'hero'=>[1920,1080]] as $name => [$wantedWidth,$wantedHeight]) {
        $scale = min(1, $width / $wantedWidth, $height / $wantedHeight);
        $targetWidth = max(1, (int)floor($wantedWidth * $scale));
        $targetHeight = max(1, (int)floor($wantedHeight * $scale));
        $targetPath = $base . '--' . $name . '-' . $targetWidth . 'x' . $targetHeight . '.webp';
        if (brvtal_media_write_variant($source, $width, $height, $targetPath, $targetWidth, $targetHeight, true, $focalX, $focalY)) {
            $result['variants'][$name] = ['path'=>brvtal_media_public_upload_path($targetPath),'width'=>$targetWidth,'height'=>$targetHeight,'mime_type'=>'image/webp'];
        }
    }

    // Every static JPEG/PNG upload gets one preserve-aspect WebP suitable for
    // generic public <img> delivery. Originals remain untouched and authoritative.
    if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
        $displayWidth = min(1920, $width);
        $displayHeight = max(1, (int)round($height * ($displayWidth / $width)));
        $displayPath = $base . '--display-' . $displayWidth . 'x' . $displayHeight . '.webp';
        if (brvtal_media_write_variant($source, $width, $height, $displayPath, $displayWidth, $displayHeight, false)) {
            $result['variants']['display'] = [
                'path' => brvtal_media_public_upload_path($displayPath),
                'width' => $displayWidth,
                'height' => $displayHeight,
                'mime_type' => 'image/webp',
            ];
        }
    }

    foreach ([1280, 1920] as $targetWidth) {
        if ($width <= $targetWidth) {
            continue;
        }
        $targetHeight = max(1, (int)round($height * ($targetWidth / $width)));
        $targetPath = $base . '--w' . $targetWidth . '.webp';
        if (brvtal_media_write_variant($source, $width, $height, $targetPath, $targetWidth, $targetHeight, false)) {
            $result['variants']['w' . $targetWidth] = [
                'path' => brvtal_media_public_upload_path($targetPath),
                'width' => $targetWidth,
                'height' => $targetHeight,
                'mime_type' => 'image/webp',
            ];
        }
    }

    imagedestroy($source);
    if ($result['variants'] !== []) {
        $result['status'] = 'ready';
    }
    return $result;
}

function brvtal_media_store_sidecar(string $absoluteOriginal, array $metadata): void
{
    $sidecar = brvtal_media_sidecar_path($absoluteOriginal);
    @file_put_contents(
        $sidecar,
        json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    @chmod($sidecar, 0640);
}

function brvtal_media_remove_generated_variants(string $absoluteOriginal, array $keep = []): void
{
    $sidecar = brvtal_media_read_sidecar(brvtal_media_public_upload_path($absoluteOriginal));
    $preserved = array_column($keep, 'path');
    foreach (($sidecar['variants'] ?? []) as $variant) {
        if (in_array((string)($variant['path'] ?? ''), $preserved, true)) continue;
        $path = brvtal_media_local_absolute((string)($variant['path'] ?? ''));
        if ($path !== null && is_file($path)) @unlink($path);
    }
}

function brvtal_media_delete_files(array $media): array
{
    $deleted = [];
    $absolute = brvtal_media_local_absolute((string)($media['file_path'] ?? ''));
    if ($absolute === null) {
        return $deleted;
    }

    $sidecarData = brvtal_media_read_sidecar((string)$media['file_path']);
    if (is_array($sidecarData['variants'] ?? null)) {
        foreach ($sidecarData['variants'] as $variant) {
            $variantAbsolute = brvtal_media_local_absolute((string)($variant['path'] ?? ''));
            if ($variantAbsolute !== null && is_file($variantAbsolute) && @unlink($variantAbsolute)) {
                $deleted[] = (string)$variant['path'];
            }
        }
    }

    $sidecar = brvtal_media_sidecar_path($absolute);
    if (is_file($sidecar)) {
        @unlink($sidecar);
    }
    if (is_file($absolute) && @unlink($absolute)) {
        $deleted[] = (string)$media['file_path'];
    }
    return $deleted;
}