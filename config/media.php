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

const BRVTAL_MEDIA_VARIANT_POLICY_VERSION = 3;
const BRVTAL_MEDIA_VARIANT_GENERATOR_VERSION = 3;
const BRVTAL_MEDIA_WEBP_QUALITY = 82;

function brvtalMediaVariantPolicy(): array
{
    return [
        'version' => BRVTAL_MEDIA_VARIANT_POLICY_VERSION,
        'generator' => 'gd-webp',
        'generator_version' => BRVTAL_MEDIA_VARIANT_GENERATOR_VERSION,
        'webp_quality' => BRVTAL_MEDIA_WEBP_QUALITY,
    ];
}

function brvtalMediaOriginalIdentity(
    string $absoluteOriginal,
    string $mime,
    int $width,
    int $height
): array {
    $bytes = max(0, (int)(@filesize($absoluteOriginal) ?: 0));
    $hash = @hash_file('sha256', $absoluteOriginal);
    return [
        'path' => brvtal_media_public_upload_path($absoluteOriginal),
        'width' => $width,
        'height' => $height,
        'mime_type' => $mime,
        'bytes' => $bytes,
        'sha256' => is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null,
    ];
}

function brvtalMediaSiblingPublicPath(string $absoluteOriginal, string $target): ?string
{
    $originalPublic = brvtal_media_public_upload_path($absoluteOriginal);
    if ($originalPublic === null) {
        return null;
    }
    $directory = dirname($originalPublic);
    return ($directory === '/' ? '' : $directory) . '/' . basename($target);
}

function brvtalMediaVariantSpecs(
    string $absoluteOriginal,
    string $mime,
    int $width,
    int $height
): array {
    $infoPath = pathinfo($absoluteOriginal);
    $base = $infoPath['dirname'] . '/' . $infoPath['filename'];
    $specs = [];

    $squareSize = min(800, max(1, min($width, $height)));
    $squarePath = $base . '--square-' . $squareSize . '.webp';
    $specs['square'] = [
        'absolute_path' => $squarePath,
        'path' => brvtalMediaSiblingPublicPath($absoluteOriginal, $squarePath),
        'width' => $squareSize,
        'height' => $squareSize,
        'mime_type' => 'image/webp',
        'crop' => true,
    ];

    foreach (['card'=>[1200,900], 'hero'=>[1920,1080]] as $name => [$wantedWidth,$wantedHeight]) {
        $scale = min(1, $width / $wantedWidth, $height / $wantedHeight);
        $targetWidth = max(1, (int)floor($wantedWidth * $scale));
        $targetHeight = max(1, (int)floor($wantedHeight * $scale));
        $targetPath = $base . '--' . $name . '-' . $targetWidth . 'x' . $targetHeight . '.webp';
        $specs[$name] = [
            'absolute_path' => $targetPath,
            'path' => brvtalMediaSiblingPublicPath($absoluteOriginal, $targetPath),
            'width' => $targetWidth,
            'height' => $targetHeight,
            'mime_type' => 'image/webp',
            'crop' => true,
        ];
    }

    if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
        $displayWidth = min(1920, $width);
        $displayHeight = max(1, (int)round($height * ($displayWidth / $width)));
        $displayPath = $base . '--display-' . $displayWidth . 'x' . $displayHeight . '.webp';
        $specs['display'] = [
            'absolute_path' => $displayPath,
            'path' => brvtalMediaSiblingPublicPath($absoluteOriginal, $displayPath),
            'width' => $displayWidth,
            'height' => $displayHeight,
            'mime_type' => 'image/webp',
            'crop' => false,
        ];
    }

    foreach ([1280, 1920] as $targetWidth) {
        if ($width <= $targetWidth) {
            continue;
        }
        $targetHeight = max(1, (int)round($height * ($targetWidth / $width)));
        $key = 'w' . $targetWidth;

        if (
            $targetWidth === 1920
            && isset($specs['display'])
            && (int)$specs['display']['width'] === $targetWidth
            && (int)$specs['display']['height'] === $targetHeight
        ) {
            $specs[$key] = [
                'absolute_path' => $specs['display']['absolute_path'],
                'path' => $specs['display']['path'],
                'width' => $targetWidth,
                'height' => $targetHeight,
                'mime_type' => 'image/webp',
                'crop' => false,
                'alias_of' => 'display',
            ];
            continue;
        }

        $targetPath = $base . '--' . $key . '.webp';
        $specs[$key] = [
            'absolute_path' => $targetPath,
            'path' => brvtalMediaSiblingPublicPath($absoluteOriginal, $targetPath),
            'width' => $targetWidth,
            'height' => $targetHeight,
            'mime_type' => 'image/webp',
            'crop' => false,
        ];
    }

    return $specs;
}

function brvtalMediaVariantPolicyMatches(array $previous, array $policy): bool
{
    $old = is_array($previous['policy'] ?? null) ? $previous['policy'] : [];
    return (int)($old['version'] ?? 0) === (int)$policy['version']
        && (string)($old['generator'] ?? '') === (string)$policy['generator']
        && (int)($old['generator_version'] ?? 0) === (int)$policy['generator_version']
        && (int)($old['webp_quality'] ?? 0) === (int)$policy['webp_quality'];
}

function brvtalMediaOriginalMatches(array $previous, array $original): bool
{
    $old = is_array($previous['original'] ?? null) ? $previous['original'] : [];
    $hash = (string)($original['sha256'] ?? '');
    return $hash !== ''
        && hash_equals((string)($old['sha256'] ?? ''), $hash)
        && (string)($old['path'] ?? '') === (string)($original['path'] ?? '')
        && (int)($old['width'] ?? 0) === (int)($original['width'] ?? 0)
        && (int)($old['height'] ?? 0) === (int)($original['height'] ?? 0)
        && (string)($old['mime_type'] ?? '') === (string)($original['mime_type'] ?? '')
        && (int)($old['bytes'] ?? -1) === (int)($original['bytes'] ?? -2);
}

function brvtalMediaFocalMatches(array $previous, array $focal): bool
{
    $old = is_array($previous['focal_point'] ?? null) ? $previous['focal_point'] : [];
    return abs((float)($old['x'] ?? -1) - (float)$focal['x']) < 0.000001
        && abs((float)($old['y'] ?? -1) - (float)$focal['y']) < 0.000001;
}

function brvtalMediaVariantMetadataMatches(array $previousVariant, array $spec): bool
{
    return !isset($previousVariant['alias_of'])
        && (string)($previousVariant['path'] ?? '') === (string)($spec['path'] ?? '')
        && (int)($previousVariant['width'] ?? 0) === (int)($spec['width'] ?? 0)
        && (int)($previousVariant['height'] ?? 0) === (int)($spec['height'] ?? 0)
        && (string)($previousVariant['mime_type'] ?? '') === 'image/webp';
}

function brvtalMediaVariantFileValid(array $spec): bool
{
    $path = (string)($spec['absolute_path'] ?? '');
    if ($path === '' || !is_file($path) || (int)(@filesize($path) ?: 0) < 1) {
        return false;
    }
    $info = @getimagesize($path);
    return is_array($info)
        && (int)($info[0] ?? 0) === (int)($spec['width'] ?? 0)
        && (int)($info[1] ?? 0) === (int)($spec['height'] ?? 0)
        && strtolower((string)($info['mime'] ?? '')) === 'image/webp';
}

/**
 * @param callable(array<string,mixed>):bool|null $validator
 */
function brvtalMediaVariantDecision(
    array $previousVariant,
    array $spec,
    bool $sharedCompatible,
    bool $focalMatches,
    ?callable $validator = null
): string {
    $metadataCompatible = $sharedCompatible
        && brvtalMediaVariantMetadataMatches($previousVariant, $spec)
        && (!(bool)($spec['crop'] ?? false) || $focalMatches);

    if (!$metadataCompatible) {
        return 'generate';
    }

    $isValid = ($validator ?? 'brvtalMediaVariantFileValid')($spec);
    return $isValid ? 'reuse' : 'repair';
}

function brvtalMediaVariantEntry(
    array $spec,
    string $state,
    string $now,
    array $previousVariant = [],
    ?string $measurementPath = null
): array {
    $measure = $measurementPath ?? (string)($spec['absolute_path'] ?? '');
    $entry = [
        'path' => $spec['path'] ?? null,
        'width' => (int)($spec['width'] ?? 0),
        'height' => (int)($spec['height'] ?? 0),
        'mime_type' => 'image/webp',
        'bytes' => max(0, (int)(@filesize($measure) ?: 0)),
        'state' => $state,
    ];

    if ($state === 'reused') {
        $generatedAt = trim((string)($previousVariant['generated_at'] ?? ''));
        if ($generatedAt !== '') {
            $entry['generated_at'] = $generatedAt;
        }
        $entry['reused_at'] = $now;
    } else {
        $entry['generated_at'] = $now;
    }

    return $entry;
}

function brvtalMediaVariantFootprint(array $variants): int
{
    $paths = [];
    foreach ($variants as $variant) {
        $path = trim((string)($variant['path'] ?? ''));
        if ($path === '' || isset($paths[$path])) {
            continue;
        }
        $paths[$path] = max(0, (int)($variant['bytes'] ?? 0));
    }
    return array_sum($paths);
}

function brvtalMediaWriteVariant(
    GdImage $source,
    int $sourceWidth,
    int $sourceHeight,
    string $target,
    int $targetWidth,
    int $targetHeight,
    bool $crop = false,
    float $focalX = .5,
    float $focalY = .5,
    int $quality = BRVTAL_MEDIA_WEBP_QUALITY
): bool {
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

    $ok = function_exists('imagewebp') && @imagewebp($canvas, $target, $quality);
    imagedestroy($canvas);
    if ($ok) {
        @chmod($target, 0644);
    }
    return $ok;
}

function brvtalMediaGenerateVariants(
    string $absoluteOriginal,
    string $mime,
    array $focalPoint = ['x'=>.5, 'y'=>.5]
): array {
    $info = @getimagesize($absoluteOriginal);
    $width = is_array($info) ? (int)($info[0] ?? 0) : 0;
    $height = is_array($info) ? (int)($info[1] ?? 0) : 0;
    $now = date(DATE_ATOM);
    $focal = [
        'x' => max(0, min(1, (float)($focalPoint['x'] ?? .5))),
        'y' => max(0, min(1, (float)($focalPoint['y'] ?? .5))),
    ];
    $policy = brvtalMediaVariantPolicy();
    $original = brvtalMediaOriginalIdentity($absoluteOriginal, $mime, $width, $height);
    $publicOriginal = (string)($original['path'] ?? '');
    $previous = $publicOriginal !== '' ? (brvtal_media_read_sidecar($publicOriginal) ?? []) : [];

    $result = [
        'version' => 3,
        'generated_at' => (string)($previous['generated_at'] ?? $now),
        'updated_at' => $now,
        'status' => 'original_only',
        'policy' => $policy,
        'original' => $original,
        'variants' => [],
        'focal_point' => $focal,
        'optimization' => [
            'state' => 'original_only',
            'generated' => [],
            'repaired' => [],
            'reused' => [],
            'aliases' => [],
            'rolled_back' => [],
            'physical_variant_count' => 0,
            'logical_variant_count' => 0,
            'physical_bytes' => 0,
            'generated_bytes_this_run' => 0,
            'reused_bytes_this_run' => 0,
            'run_at' => $now,
        ],
        '_staged_files' => [],
    ];

    if ($width < 1 || $height < 1) {
        $result['reason'] = 'IMAGE_DIMENSIONS_UNSUPPORTED';
        return $result;
    }

    $specs = brvtalMediaVariantSpecs($absoluteOriginal, $mime, $width, $height);
    $sharedCompatible = brvtalMediaVariantPolicyMatches($previous, $policy)
        && brvtalMediaOriginalMatches($previous, $original);
    $focalMatches = brvtalMediaFocalMatches($previous, $focal);
    $pending = [];

    foreach ($specs as $name => $spec) {
        if (isset($spec['alias_of'])) {
            continue;
        }

        $previousVariant = is_array($previous['variants'][$name] ?? null)
            ? $previous['variants'][$name]
            : [];
        $decision = brvtalMediaVariantDecision(
            $previousVariant,
            $spec,
            $sharedCompatible,
            $focalMatches
        );

        if ($decision === 'reuse') {
            $entry = brvtalMediaVariantEntry($spec, 'reused', $now, $previousVariant);
            $result['variants'][$name] = $entry;
            $result['optimization']['reused'][] = $name;
            $result['optimization']['reused_bytes_this_run'] += (int)$entry['bytes'];
            continue;
        }

        $pending[$name] = [
            'spec' => $spec,
            'state' => $decision === 'repair' ? 'repaired' : 'generated',
            'previous' => $previousVariant,
        ];
    }

    $source = false;
    if ($pending !== [] && $width * $height <= 40000000) {
        $source = brvtal_media_create_image_resource($absoluteOriginal, $mime);
    }

    if ($pending !== [] && (!$source || !function_exists('imagewebp'))) {
        $result['reason'] = match (true) {
            $width * $height > 40000000 => 'IMAGE_DIMENSIONS_UNSUPPORTED',
            !extension_loaded('gd') || !function_exists('imagewebp') => 'GD_WEBP_UNAVAILABLE',
            default => 'IMAGE_DECODE_FAILED',
        };
    }

    if ($source instanceof GdImage) {
        foreach ($pending as $name => $work) {
            $spec = $work['spec'];
            $target = (string)$spec['absolute_path'];
            $temporary = $target . '.tmp-' . bin2hex(random_bytes(6));
            $ok = brvtalMediaWriteVariant(
                $source,
                $width,
                $height,
                $temporary,
                (int)$spec['width'],
                (int)$spec['height'],
                (bool)$spec['crop'],
                $focal['x'],
                $focal['y'],
                (int)$policy['webp_quality']
            );
            if (!$ok || !is_file($temporary)) {
                @unlink($temporary);
                continue;
            }

            $state = (string)$work['state'];
            $entry = brvtalMediaVariantEntry(
                $spec,
                $state,
                $now,
                (array)$work['previous'],
                $temporary
            );
            $result['variants'][$name] = $entry;
            $result['_staged_files'][] = [
                'logical_name' => $name,
                'temporary' => $temporary,
                'target' => $target,
            ];
            $bucket = $state === 'repaired' ? 'repaired' : 'generated';
            $result['optimization'][$bucket][] = $name;
            $result['optimization']['generated_bytes_this_run'] += (int)$entry['bytes'];
        }
        imagedestroy($source);
    }

    foreach ($specs as $name => $spec) {
        $aliasOf = (string)($spec['alias_of'] ?? '');
        if ($aliasOf === '' || !isset($result['variants'][$aliasOf])) {
            continue;
        }
        $target = $result['variants'][$aliasOf];
        $result['variants'][$name] = [
            'path' => $target['path'],
            'width' => (int)$spec['width'],
            'height' => (int)$spec['height'],
            'mime_type' => 'image/webp',
            'bytes' => (int)($target['bytes'] ?? 0),
            'state' => 'alias',
            'alias_of' => $aliasOf,
            'generated_at' => $target['generated_at'] ?? null,
            'reused_at' => $target['reused_at'] ?? null,
            'aliased_at' => $now,
        ];
        $result['optimization']['aliases'][$name] = $aliasOf;
    }

    $logicalCount = count($result['variants']);
    $expectedCount = count($specs);
    $physicalPaths = [];
    foreach ($result['variants'] as $variant) {
        $path = trim((string)($variant['path'] ?? ''));
        if ($path !== '') {
            $physicalPaths[$path] = true;
        }
    }

    $result['optimization']['logical_variant_count'] = $logicalCount;
    $result['optimization']['physical_variant_count'] = count($physicalPaths);
    $result['optimization']['physical_bytes'] = brvtalMediaVariantFootprint($result['variants']);

    if ($logicalCount === $expectedCount && $expectedCount > 0) {
        $result['status'] = 'ready';
        $hasGenerated = $result['optimization']['generated'] !== []
            || $result['optimization']['repaired'] !== [];
        $hasReused = $result['optimization']['reused'] !== [];
        $result['optimization']['state'] = $hasGenerated && $hasReused
            ? 'mixed'
            : ($hasGenerated ? 'generated' : 'reused');
        unset($result['reason']);
    } elseif ($logicalCount > 0) {
        $result['status'] = 'partial';
        $result['optimization']['state'] = 'partial';
        $result['reason'] = $result['reason'] ?? 'VARIANT_GENERATION_PARTIAL';
    }

    if (($result['status'] ?? '') !== 'ready') {
        $rolledBack = [];
        foreach ((array)$result['_staged_files'] as $staged) {
            $name = (string)($staged['logical_name'] ?? '');
            if ($name !== '') {
                $rolledBack[$name] = true;
                unset($result['variants'][$name]);
            }
            @unlink((string)($staged['temporary'] ?? ''));
        }

        foreach ($result['variants'] as $name => $variant) {
            $aliasOf = (string)($variant['alias_of'] ?? '');
            if ($aliasOf !== '' && isset($rolledBack[$aliasOf])) {
                unset($result['variants'][$name]);
            }
        }

        $result['optimization']['rolled_back'] = array_keys($rolledBack);
        $result['optimization']['generated'] = [];
        $result['optimization']['repaired'] = [];
        $result['optimization']['generated_bytes_this_run'] = 0;
        $physicalPaths = [];
        foreach ($result['variants'] as $variant) {
            $path = trim((string)($variant['path'] ?? ''));
            if ($path !== '') {
                $physicalPaths[$path] = true;
            }
        }
        $result['optimization']['logical_variant_count'] = count($result['variants']);
        $result['optimization']['physical_variant_count'] = count($physicalPaths);
        $result['optimization']['physical_bytes'] = brvtalMediaVariantFootprint($result['variants']);
        $result['_staged_files'] = [];
    }

    return $result;
}

function brvtalMediaRollbackStagedVariants(array $committed, array $pending): void
{
    foreach (array_reverse($committed) as $entry) {
        $target = (string)($entry['target'] ?? '');
        $backup = (string)($entry['backup'] ?? '');
        if ($target !== '' && is_file($target)) {
            @unlink($target);
        }
        if ($backup !== '' && is_file($backup)) {
            @rename($backup, $target);
        }
    }
    foreach ($pending as $entry) {
        $temporary = (string)($entry['temporary'] ?? '');
        if ($temporary !== '' && is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function brvtalMediaStoreSidecar(string $absoluteOriginal, array $metadata): bool
{
    $sidecar = brvtal_media_sidecar_path($absoluteOriginal);
    $stagedFiles = is_array($metadata['_staged_files'] ?? null)
        ? $metadata['_staged_files']
        : [];
    unset($metadata['_staged_files']);

    $encoded = json_encode(
        $metadata,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    if (!is_string($encoded)) {
        brvtalMediaRollbackStagedVariants([], $stagedFiles);
        return false;
    }

    $sidecarTemporary = $sidecar . '.tmp-' . bin2hex(random_bytes(6));
    if (@file_put_contents($sidecarTemporary, $encoded, LOCK_EX) === false) {
        @unlink($sidecarTemporary);
        brvtalMediaRollbackStagedVariants([], $stagedFiles);
        return false;
    }
    @chmod($sidecarTemporary, 0640);

    $committed = [];
    foreach ($stagedFiles as $index => $entry) {
        $temporary = (string)($entry['temporary'] ?? '');
        $target = (string)($entry['target'] ?? '');
        if ($temporary === '' || $target === '' || !is_file($temporary)) {
            @unlink($sidecarTemporary);
            brvtalMediaRollbackStagedVariants($committed, array_slice($stagedFiles, $index));
            return false;
        }

        $backup = '';
        if (is_file($target)) {
            $backup = $target . '.bak-' . bin2hex(random_bytes(6));
            if (!@rename($target, $backup)) {
                @unlink($sidecarTemporary);
                brvtalMediaRollbackStagedVariants($committed, array_slice($stagedFiles, $index));
                return false;
            }
        }

        if (!@rename($temporary, $target)) {
            if ($backup !== '') {
                @rename($backup, $target);
            }
            @unlink($sidecarTemporary);
            brvtalMediaRollbackStagedVariants($committed, array_slice($stagedFiles, $index));
            return false;
        }
        @chmod($target, 0644);
        $committed[] = ['target'=>$target, 'backup'=>$backup];
    }

    if (!@rename($sidecarTemporary, $sidecar)) {
        @unlink($sidecarTemporary);
        brvtalMediaRollbackStagedVariants($committed, []);
        return false;
    }
    @chmod($sidecar, 0640);

    foreach ($committed as $entry) {
        $backup = (string)($entry['backup'] ?? '');
        if ($backup !== '' && is_file($backup)) {
            @unlink($backup);
        }
    }
    return true;
}

function brvtalMediaRemoveGeneratedVariants(
    string $absoluteOriginal,
    array $keep = [],
    ?array $previousSidecar = null
): void {
    $sidecar = $previousSidecar ?? brvtal_media_read_sidecar(
        brvtal_media_public_upload_path($absoluteOriginal)
    );
    $preserved = array_fill_keys(array_filter(array_column($keep, 'path')), true);
    $seen = [];
    foreach (($sidecar['variants'] ?? []) as $variant) {
        $publicPath = (string)($variant['path'] ?? '');
        if ($publicPath === '' || isset($preserved[$publicPath]) || isset($seen[$publicPath])) {
            continue;
        }
        $seen[$publicPath] = true;
        $path = brvtal_media_local_absolute($publicPath);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
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