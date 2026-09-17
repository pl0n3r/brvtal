<?php
declare(strict_types=1);

require_once __DIR__ . '/seo_defaults.php';
require_once __DIR__ . '/public_visibility.php';
require_once __DIR__ . '/public_routes.php';
require_once __DIR__ . '/page_content.php';
require_once __DIR__ . '/public_settings.php';

function brvtal_public_base_url(array $config): string
{
    $url = rtrim((string)($config['app']['base_url'] ?? 'https://www.brvtal.com.co'), '/');
    if (!preg_match('#^https://#i', $url)) return 'https://www.brvtal.com.co';
    return $url === 'https://brvtal.com.co' ? 'https://www.brvtal.com.co' : $url;
}

function brvtal_public_absolute_url(string $value, string $base): string
{
    $value = trim($value);
    if ($value === '') return $base . '/assets/brvtal-logo.jpeg';
    if (preg_match('#^https?://#i', $value)) return $value;
    return $base . '/' . ltrim($value, '/');
}

/** @return array{site_title:string,description:string,share_image:string} */
function brvtal_public_global_seo(PDO $pdo): array
{
    $setting = brvtal_config_setting_json($pdo, 'seo');
    $title = brvtal_seo_truncate(brvtal_seo_plain_text($setting['site_title'] ?? ''), 190);
    $description = brvtal_seo_truncate(brvtal_seo_plain_text($setting['description'] ?? ''), 320);
    $shareImage = trim((string)($setting['share_image'] ?? $setting['og_image'] ?? ''));
    if ($shareImage !== '' && !preg_match('#^(?:https?://|/)#i', $shareImage)) $shareImage = '';
    return ['site_title'=>$title,'description'=>$description,'share_image'=>$shareImage];
}

function brvtal_public_seo_entity(PDO $pdo, string $type, string $slug): ?array
{
    $definitions = brvtal_public_content_definitions();
    if (!isset($definitions[$type]) || !preg_match('/^[a-z0-9-]{1,190}$/', $slug)) return null;

    $definition = $definitions[$type];
    $table = $definition['table'];
    $titleField = $definition['title_field'];
    $descriptionField = $definition['description_field'];
    $imageField = $definition['image_field'];
    $where = $definition['where'];
    $schemaType = $definition['schema_type'];
    $whereParameters = $definition['parameters'];
    $imageSelect = $imageField === '' ? "'' AS image" : "`{$imageField}` AS image";
    $eventSelect = $definition['event_visibility']
        ? ',status,event_date,published_at'
        : '';
    $sql = "SELECT id,slug,`{$titleField}` AS title,"
        . "`{$descriptionField}` AS description,seo_title,seo_description,"
        . "{$imageSelect}{$eventSelect} FROM `{$table}` "
        . "WHERE slug=? AND {$where} LIMIT 1";
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute(array_merge([$slug], $whereParameters));
        $row = $statement->fetch();
    } catch (Throwable) {
        return null;
    }
    $requiresEventVisibility = $definition['event_visibility'];
    if (!$row || ($requiresEventVisibility && !brvtal_public_event_is_visible($row))) return null;
    if ($type === 'pages') {
        $row['description'] = brvtal_page_content_plain_text($row['description'] ?? '');
    }
    $row['schema_type'] = $schemaType;
    $row['route_type'] = $type;
    return $row;
}

function brvtal_public_seo_document(?array $entity, string $base, array $defaults = []): array
{
    $siteName = 'BRVTAL';
    $path = $entity ? '/' . $entity['route_type'] . '/' . rawurlencode((string)$entity['slug']) : '/';
    $canonical = $base . $path;

    $customTitle = trim((string)($entity['seo_title'] ?? ''));
    $fallbackTitle = brvtal_seo_default_title($entity['title'] ?? '');
    if ($entity) {
        $title = $customTitle !== '' ? brvtal_seo_truncate(brvtal_seo_plain_text($customTitle), 190) : $fallbackTitle;
        if (!str_contains(strtoupper($title), 'BRVTAL')) $title .= ' — BRVTAL';
    } else {
        $title = brvtal_seo_truncate(brvtal_seo_plain_text($defaults['site_title'] ?? ''), 190);
        if ($title === '') $title = 'BRVTAL — Rave till Grave';
    }

    $customDescription = trim((string)($entity['seo_description'] ?? ''));
    if ($entity) {
        $description = $customDescription !== ''
            ? brvtal_seo_truncate(brvtal_seo_plain_text($customDescription), 320)
            : brvtal_seo_default_description($entity['description'] ?? '', 160);
    } else {
        $description = brvtal_seo_truncate(brvtal_seo_plain_text($defaults['description'] ?? ''), 320);
    }
    if ($description === '') $description = 'BRVTAL — Rave till Grave. Underground electronic music, experiences and events from Colombia.';

    $imageSource = $entity ? (string)($entity['image'] ?? '') : (string)($defaults['share_image'] ?? '');
    $image = brvtal_public_absolute_url($imageSource, $base);
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => $entity['schema_type'] ?? 'Organization',
        'name' => $entity['title'] ?? $siteName,
        'url' => $canonical,
        'image' => $image,
        'description' => $description,
    ];
    return compact('title', 'description', 'canonical', 'image', 'schema');
}

function brvtal_public_not_found_seo(string $base, string $type, string $slug): array
{
    $segments = array_values(array_filter([
        trim($type) !== '' ? rawurlencode(trim($type)) : null,
        trim($slug) !== '' ? rawurlencode(trim($slug)) : null,
    ], static fn(mixed $value): bool => is_string($value) && $value !== ''));
    $path = '/' . implode('/', $segments);
    if ($path === '/') $path = '/404';

    $canonical = rtrim($base, '/') . $path;
    $title = 'Resource Not Found — BRVTAL';
    $description = 'The requested BRVTAL public resource could not be found.';
    $image = brvtal_public_absolute_url('', $base);
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => 'Resource Not Found',
        'url' => $canonical,
        'image' => $image,
        'description' => $description,
    ];
    return compact('title', 'description', 'canonical', 'image', 'schema');
}

function brvtal_public_seo_tags(array $seo): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $json = json_encode($seo['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    return implode("\n  ", [
        '<link rel="canonical" href="' . $escape($seo['canonical']) . '">',
        '<meta property="og:type" content="' . (($seo['schema']['@type'] ?? '') === 'BlogPosting' ? 'article' : 'website') . '">',
        '<meta property="og:site_name" content="BRVTAL">',
        '<meta property="og:title" content="' . $escape($seo['title']) . '">',
        '<meta property="og:description" content="' . $escape($seo['description']) . '">',
        '<meta property="og:url" content="' . $escape($seo['canonical']) . '">',
        '<meta property="og:image" content="' . $escape($seo['image']) . '">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . $escape($seo['title']) . '">',
        '<meta name="twitter:description" content="' . $escape($seo['description']) . '">',
        '<meta name="twitter:image" content="' . $escape($seo['image']) . '">',
        '<script type="application/ld+json">' . $json . '</script>',
    ]);
}
