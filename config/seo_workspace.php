<?php
declare(strict_types=1);

require_once __DIR__ . '/seo_defaults.php';
require_once __DIR__ . '/public_routes.php';
require_once __DIR__ . '/public_settings.php';

const BRVTAL_SEO_WORKSPACE_SETTING_KEY = 'seo';

/**
 * Static public destinations whose metadata is not backed by an entity row.
 *
 * @return array<string,array{
 *   type:string,label:string,path:string,default_title:string,
 *   default_description:string,default_image:string
 * }>
 */
function brvtalSeoWorkspaceStaticDefinitions(): array
{
    return [
        'home' => [
            'type'=>'HOME',
            'label'=>'Home',
            'path'=>'/',
            'default_title'=>'BRVTAL — Rave till Grave',
            'default_description'=>
                'BRVTAL — Rave till Grave. Underground electronic music, '
                . 'experiences and events from Colombia.',
            'default_image'=>'/assets/brvtal-logo.jpeg',
        ],
        'contact' => [
            'type'=>'CONTACT',
            'label'=>'Contact',
            'path'=>'/contact',
            'default_title'=>'Contact — BRVTAL',
            'default_description'=>
                'Contact BRVTAL for bookings, collaborations, events, media '
                . 'and general inquiries from Pereira, Colombia.',
            'default_image'=>'/assets/brvtal-logo.jpeg',
        ],
    ];
}

/**
 * Reuse the canonical public content registry as the entity SEO registry.
 *
 * @return array<string,array{
 *   table:string,title:string,description:string,image:string,schema_type:string
 * }>
 */
function brvtalSeoWorkspaceEntityDefinitions(): array
{
    $definitions = [];
    foreach (brvtal_public_content_definitions() as $resource => $definition) {
        $definitions[$resource] = [
            'table'=>(string)$definition['table'],
            'title'=>(string)$definition['title_field'],
            'description'=>(string)$definition['description_field'],
            'image'=>(string)$definition['image_field'],
            'schema_type'=>(string)$definition['schema_type'],
        ];
    }
    return $definitions;
}

/**
 * Normalize an optional social image override.
 *
 * Only HTTP(S) URLs and existing public asset namespaces are accepted. Empty
 * input means AUTO. The helper intentionally does not accept arbitrary root
 * paths because static SEO is not a generic URL injection surface.
 */
function brvtalSeoWorkspaceImageValue(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $value)) {
        if (strlen($value) > 700 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $parts = parse_url($value);
        if (
            !is_array($parts)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return '';
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        return in_array($scheme, ['http','https'], true) ? $value : '';
    }

    if (!str_starts_with($value, '/uploads/') && !str_starts_with($value, '/assets/')) {
        return '';
    }
    if (
        str_contains($value, "\0")
        || str_contains($value, "\r")
        || str_contains($value, "\n")
        || str_contains($value, '..')
        || str_contains($value, '?')
        || str_contains($value, '#')
    ) {
        return '';
    }
    return mb_substr($value, 0, 700);
}

/** @return array{seo_title:string,seo_description:string,share_image:string} */
function brvtalSeoWorkspaceStaticOverrides(?PDO $pdo, string $key): array
{
    $definitions = brvtalSeoWorkspaceStaticDefinitions();
    if (!isset($definitions[$key])) {
        throw new InvalidArgumentException('INVALID_STATIC_ROUTE');
    }

    $setting = $pdo instanceof PDO
        ? brvtal_config_setting_json($pdo, BRVTAL_SEO_WORKSPACE_SETTING_KEY)
        : [];
    $routes = is_array($setting['routes'] ?? null) ? $setting['routes'] : [];
    $routeConfigured = array_key_exists($key, $routes) && is_array($routes[$key] ?? null);
    $route = $routeConfigured ? $routes[$key] : [];

    if ($key === 'home' && !$routeConfigured) {
        $route = [
            'title'=>$setting['site_title'] ?? '',
            'description'=>$setting['description'] ?? '',
            'share_image'=>$setting['share_image'] ?? ($setting['og_image'] ?? ''),
        ];
    }

    return [
        'seo_title'=>(string)(brvtalSeoOverrideValue($route['title'] ?? '', 190) ?? ''),
        'seo_description'=>(string)(brvtalSeoOverrideValue($route['description'] ?? '', 320) ?? ''),
        'share_image'=>brvtalSeoWorkspaceImageValue($route['share_image'] ?? ''),
    ];
}

/**
 * Return raw overrides and the renderer-ready static fallback values.
 *
 * @return array{
 *   key:string,type:string,label:string,path:string,mode:string,
 *   seo_title:string,seo_description:string,share_image:string,
 *   automatic_title:string,automatic_description:string,automatic_image_source:string,
 *   effective_title:string,effective_description:string,effective_image_source:string
 * }
 */
function brvtalSeoWorkspaceStaticValues(?PDO $pdo, string $key): array
{
    $definitions = brvtalSeoWorkspaceStaticDefinitions();
    if (!isset($definitions[$key])) {
        throw new InvalidArgumentException('INVALID_STATIC_ROUTE');
    }

    $definition = $definitions[$key];
    $overrides = brvtalSeoWorkspaceStaticOverrides($pdo, $key);
    $manualCount = count(array_filter(
        [$overrides['seo_title'],$overrides['seo_description'],$overrides['share_image']],
        static fn(string $value): bool => $value !== ''
    ));
    $mode = $manualCount === 0 ? 'AUTO' : ($manualCount === 3 ? 'MANUAL' : 'MIXED');

    return [
        'key'=>$key,
        'type'=>$definition['type'],
        'label'=>$definition['label'],
        'path'=>$definition['path'],
        'mode'=>$mode,
        ...$overrides,
        'automatic_title'=>(string)$definition['default_title'],
        'automatic_description'=>(string)$definition['default_description'],
        'automatic_image_source'=>(string)$definition['default_image'],
        'effective_title'=>$overrides['seo_title'] !== ''
            ? $overrides['seo_title']
            : (string)$definition['default_title'],
        'effective_description'=>$overrides['seo_description'] !== ''
            ? $overrides['seo_description']
            : (string)$definition['default_description'],
        'effective_image_source'=>$overrides['share_image'] !== ''
            ? $overrides['share_image']
            : (string)$definition['default_image'],
    ];
}

function brvtalSeoWorkspaceAbsoluteUrl(string $source, string $base): string
{
    if (preg_match('#^https?://#i', $source)) {
        return $source;
    }
    return rtrim($base, '/') . '/' . ltrim($source, '/');
}

/**
 * Add canonical and absolute image values for a static public destination.
 *
 * @return array<string,mixed>
 */
function brvtalSeoWorkspaceStaticState(?PDO $pdo, string $key, string $base): array
{
    $state = brvtalSeoWorkspaceStaticValues($pdo, $key);
    $path = (string)$state['path'];
    $state['canonical'] = rtrim($base, '/') . ($path === '/' ? '/' : $path);
    $state['automatic_image'] = brvtalSeoWorkspaceAbsoluteUrl(
        (string)$state['automatic_image_source'],
        $base
    );
    $state['effective_image'] = brvtalSeoWorkspaceAbsoluteUrl(
        (string)$state['effective_image_source'],
        $base
    );
    return $state;
}

/**
 * Persist static-route SEO inside the existing JSON-backed SEO setting.
 *
 * Unknown sibling values are preserved. Home mirrors its route override into
 * the legacy flat keys so older readers cannot resurrect a cleared override.
 *
 * @return array{changed:bool,state:array<string,mixed>}
 */
function brvtalSeoWorkspacePersistStatic(
    PDO $pdo,
    string $key,
    array $body,
    string $base
): array {
    $definitions = brvtalSeoWorkspaceStaticDefinitions();
    if (!isset($definitions[$key])) {
        throw new InvalidArgumentException('INVALID_STATIC_ROUTE');
    }

    $seoTitle = brvtalSeoOverrideValue($body['seo_title'] ?? '', 190);
    $seoDescription = brvtalSeoOverrideValue($body['seo_description'] ?? '', 320);
    $rawImage = trim((string)($body['share_image'] ?? ''));
    $shareImage = brvtalSeoWorkspaceImageValue($rawImage);
    if ($rawImage !== '' && $shareImage === '') {
        throw new InvalidArgumentException('INVALID_SHARE_IMAGE');
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            'SELECT setting_value,is_json FROM settings WHERE setting_key=? LIMIT 1 FOR UPDATE'
        );
        $lock->execute([BRVTAL_SEO_WORKSPACE_SETTING_KEY]);
        $row = $lock->fetch(PDO::FETCH_ASSOC);

        $setting = [];
        if (is_array($row)) {
            if ((int)($row['is_json'] ?? 0) !== 1) {
                throw new RuntimeException('SEO_SETTING_INVALID');
            }
            $decoded = json_decode((string)($row['setting_value'] ?? ''), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('SEO_SETTING_INVALID');
            }
            $setting = $decoded;
        }

        $before = $setting;
        $routes = is_array($setting['routes'] ?? null) ? $setting['routes'] : [];
        $routes[$key] = [
            'title'=>$seoTitle ?? '',
            'description'=>$seoDescription ?? '',
            'share_image'=>$shareImage,
        ];
        $setting['routes'] = $routes;

        if ($key === 'home') {
            $setting['site_title'] = $seoTitle ?? '';
            $setting['description'] = $seoDescription ?? '';
            $setting['share_image'] = $shareImage;
            unset($setting['og_image']);
        }

        $changed = $before !== $setting;
        if ($changed) {
            $json = json_encode(
                $setting,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if (!is_string($json)) {
                throw new RuntimeException('SEO_SETTING_ENCODE_FAILED');
            }
            $save = $pdo->prepare(
                'INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,1) '
                . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_json=1'
            );
            $save->execute([BRVTAL_SEO_WORKSPACE_SETTING_KEY, $json]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return [
        'changed'=>$changed,
        'state'=>brvtalSeoWorkspaceStaticState($pdo, $key, $base),
    ];
}
