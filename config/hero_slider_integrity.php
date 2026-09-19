<?php
declare(strict_types=1);

require_once __DIR__ . '/media.php';

const BRVTAL_HERO_SLIDER_SETTING_KEY = 'home.hero.slider';

/**
 * Validate one Hero Slider media reference.
 *
 * Local references must be canonical published Media Library assets whose
 * source file still exists under /uploads. HTTPS references are explicitly
 * external and are not fetched server-side.
 *
 * @param callable(string): ?array $resolveLocal
 * @return array{error:string,field:string}|null
 */
function brvtal_hero_slider_media_reference_error(
    mixed $value,
    string $expectedType,
    string $field,
    bool $required,
    callable $resolveLocal
): ?array {
    $reference = trim((string)$value);
    if ($reference === '') {
        return $required
            ? ['error' => 'HERO_SLIDER_MEDIA_REQUIRED', 'field' => $field]
            : null;
    }

    if (preg_match('#^https://#i', $reference) === 1) {
        return null;
    }

    if (!str_starts_with($reference, '/uploads/')
        || str_contains($reference, '..')
        || str_contains($reference, "\0")
    ) {
        return ['error' => 'HERO_SLIDER_MEDIA_REFERENCE_INVALID', 'field' => $field];
    }

    $media = $resolveLocal($reference);
    if (!is_array($media)) {
        return ['error' => 'HERO_SLIDER_MEDIA_NOT_REGISTERED', 'field' => $field];
    }
    if ((string)($media['status'] ?? '') !== 'published') {
        return ['error' => 'HERO_SLIDER_MEDIA_NOT_PUBLISHED', 'field' => $field];
    }
    if ((string)($media['type'] ?? '') !== $expectedType) {
        return ['error' => 'HERO_SLIDER_MEDIA_TYPE_MISMATCH', 'field' => $field];
    }
    if (($media['exists'] ?? false) !== true) {
        return ['error' => 'HERO_SLIDER_MEDIA_FILE_MISSING', 'field' => $field];
    }

    return null;
}

/**
 * Validate all media-bearing fields that can become public.
 *
 * Disabled slides remain editable drafts. Enabled slides require a valid
 * desktop source; optional overrides/layer assets are validated when present.
 *
 * @param callable(string): ?array $resolveLocal
 * @return array{error:string,field:string}|null
 */
function brvtal_hero_slider_config_error(array $config, callable $resolveLocal): ?array
{
    $slides = is_array($config['slides'] ?? null) ? array_slice($config['slides'], 0, 20) : [];

    foreach ($slides as $slideIndex => $slide) {
        if (!is_array($slide) || ($slide['enabled'] ?? true) === false) {
            continue;
        }

        $mediaType = ($slide['mediaType'] ?? '') === 'video' ? 'video' : 'image';
        $prefix = 'slides.' . $slideIndex;

        foreach ([
            ['desktopSrc', $mediaType, true],
            ['mobileSrc', $mediaType, false],
            ['poster', 'image', false],
        ] as [$field, $expectedType, $required]) {
            if ($field === 'poster' && $mediaType !== 'video') {
                continue;
            }
            $error = brvtal_hero_slider_media_reference_error(
                $slide[$field] ?? '',
                $expectedType,
                $prefix . '.' . $field,
                $required,
                $resolveLocal
            );
            if ($error !== null) {
                return $error;
            }
        }

        $layers = is_array($slide['layers'] ?? null) ? array_slice($slide['layers'], 0, 12) : [];
        foreach ($layers as $layerIndex => $layer) {
            if (!is_array($layer) || !in_array(($layer['type'] ?? ''), ['image', 'logo'], true)) {
                continue;
            }
            foreach (['src', 'mobileSrc'] as $field) {
                $error = brvtal_hero_slider_media_reference_error(
                    $layer[$field] ?? '',
                    'image',
                    $prefix . '.layers.' . $layerIndex . '.' . $field,
                    false,
                    $resolveLocal
                );
                if ($error !== null) {
                    return $error;
                }
            }
        }
    }

    return null;
}

/** @return array{error:string,field:string}|null */
function brvtal_hero_slider_setting_error(PDO $pdo, string $value, int $isJson): ?array
{
    if ($isJson !== 1) {
        return ['error' => 'HERO_SLIDER_JSON_REQUIRED', 'field' => 'setting_value'];
    }

    $config = json_decode($value, true);
    if (!is_array($config)) {
        return ['error' => 'INVALID_SETTING_JSON', 'field' => 'setting_value'];
    }

    $statement = $pdo->prepare(
        'SELECT id,type,file_path,status FROM media WHERE file_path=? ORDER BY id ASC LIMIT 1'
    );

    $resolveLocal = static function (string $path) use ($statement): ?array {
        $statement->execute([$path]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $absolute = brvtal_media_local_absolute($path);
        $row['exists'] = $absolute !== null && is_file($absolute);
        return $row;
    };

    return brvtal_hero_slider_config_error($config, $resolveLocal);
}
