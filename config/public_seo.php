<?php
declare(strict_types=1);

require_once __DIR__ . '/seo_defaults.php';
require_once __DIR__ . '/public_visibility.php';

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

function brvtal_public_seo_entity(PDO $pdo, string $type, string $slug): ?array
{
    $eventStatuses = brvtal_public_visible_event_statuses();
    $eventWhere = 'status IN (' . brvtal_public_sql_placeholders($eventStatuses) . ')';
    $definitions = [
        'events' => ['events', 'title', 'description', 'cover_image', $eventWhere, 'MusicEvent', $eventStatuses],
        'artists' => ['artists', 'name', 'bio', 'photo', "status='published'", 'MusicGroup', []],
        'sets' => ['sets_media', 'title', 'description', 'cover_image', "status='published'", 'MusicRecording', []],
        'releases' => ['releases', 'title', 'description', 'artwork', "status='published'", 'MusicAlbum', []],
        'blog' => ['blog_posts', 'title', 'excerpt', 'cover_image', "status='published'", 'BlogPosting', []],
        'pages' => ['pages', 'title', 'content_json', "''", "status='published' AND locale='en'", 'WebPage', []],
    ];
    if (!isset($definitions[$type]) || !preg_match('/^[a-z0-9-]{1,190}$/', $slug)) return null;
    [$table, $titleField, $descriptionField, $imageField, $where, $schemaType, $whereParameters] = $definitions[$type];
    $imageSelect = $imageField === "''" ? "'' AS image" : "`{$imageField}` AS image";
    $eventSelect = $type === 'events' ? ',status,event_date,published_at' : '';
    $sql = "SELECT id,slug,`{$titleField}` AS title,`{$descriptionField}` AS description,seo_title,seo_description,{$imageSelect}{$eventSelect} FROM `{$table}` WHERE slug=? AND {$where} LIMIT 1";
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute(array_merge([$slug], $whereParameters));
        $row = $statement->fetch();
    } catch (Throwable) {
        return null;
    }
    if (!$row || ($type === 'events' && !brvtal_public_event_is_visible($row))) return null;
    $row['schema_type'] = $schemaType;
    $row['route_type'] = $type;
    return $row;
}

function brvtal_public_seo_document(?array $entity, string $base): array
{
    $siteName = 'BRVTAL';
    $path = $entity ? '/' . $entity['route_type'] . '/' . rawurlencode((string)$entity['slug']) : '/';
    $canonical = $base . $path;

    $customTitle = trim((string)($entity['seo_title'] ?? ''));
    $fallbackTitle = brvtal_seo_default_title($entity['title'] ?? '');
    $title = $entity ? ($customTitle !== '' ? brvtal_seo_truncate(brvtal_seo_plain_text($customTitle), 190) : $fallbackTitle) : 'BRVTAL — Rave till Grave';
    if ($entity && !str_contains(strtoupper($title), 'BRVTAL')) $title .= ' — BRVTAL';

    $customDescription = trim((string)($entity['seo_description'] ?? ''));
    $description = $customDescription !== ''
        ? brvtal_seo_truncate(brvtal_seo_plain_text($customDescription), 320)
        : brvtal_seo_default_description($entity['description'] ?? '', 160);
    if ($description === '') $description = 'BRVTAL — Rave till Grave. Underground electronic music, experiences and events from Colombia.';

    $image = brvtal_public_absolute_url((string)($entity['image'] ?? ''), $base);
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
