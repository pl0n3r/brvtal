<?php
declare(strict_types=1);

require_once __DIR__ . '/seo_defaults.php';
require_once __DIR__ . '/public_visibility.php';
require_once __DIR__ . '/public_routes.php';
require_once __DIR__ . '/page_content.php';
require_once __DIR__ . '/public_settings.php';
require_once __DIR__ . '/seo_workspace.php';

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
    $home = brvtalSeoWorkspaceStaticValues($pdo, 'home');
    return [
        'site_title'=>(string)$home['effective_title'],
        'description'=>(string)$home['effective_description'],
        'share_image'=>(string)$home['effective_image_source'],
    ];
}


/**
 * Normalize real SQL DATE/DATETIME values without assuming a server timezone.
 * The legacy editorial columns store local calendar values, not UTC instants.
 */
function brvtal_public_schema_date(mixed $value): ?string
{
    if (!is_string($value) && !is_int($value)) return null;
    $value = trim((string)$value);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/D', $value, $parts)) {
        return null;
    }
    if (!checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) return null;
    $date = $parts[1] . '-' . $parts[2] . '-' . $parts[3];
    if (!isset($parts[4])) return $date;
    if ((int)$parts[4] > 23 || (int)$parts[5] > 59 || (int)($parts[6] ?? 0) > 59) return null;
    return $date . 'T' . $parts[4] . ':' . $parts[5] . ':' . ($parts[6] ?? '00');
}

/** Never advertise javascript:, relative URLs or credential-bearing URLs. */
function brvtal_public_schema_external_url(mixed $value): ?string
{
    if (!is_string($value)) return null;
    $url = trim($value);
    if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    $host = strtolower(trim((string)$parts['host'], '[]'));
    if (!str_contains($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return null;
    if (filter_var($host, FILTER_VALIDATE_IP) !== false
        && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return null;
    }
    return $url;
}

/**
 * Hydrate only published artists for entity JSON-LD. Incomplete optional
 * relation tables must not turn a public page into an error or leak a draft.
 *
 * @return list<array{name:string,slug:string}>
 */
function brvtal_public_schema_artists(PDO $pdo, string $type, int $id): array
{
    if ($id < 1) return [];
    $queries = [
        'events' => "SELECT a.name,a.slug FROM event_artists ea
            JOIN artists a ON a.id=ea.artist_id AND a.status='published'
            WHERE ea.event_id=? ORDER BY ea.lineup_order,a.name",
        'sets' => "SELECT name,slug FROM artists WHERE id=? AND status='published' LIMIT 1",
        'releases' => "SELECT a.name,a.slug FROM release_artists ra
            JOIN artists a ON a.id=ra.artist_id AND a.status='published'
            WHERE ra.release_id=? ORDER BY ra.sort_order,a.name",
    ];
    if (!isset($queries[$type])) return [];
    try {
        $stmt = $pdo->prepare($queries[$type]);
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    $artists = [];
    foreach ($rows as $row) {
        $name = brvtal_seo_plain_text($row['name'] ?? '');
        $slug = trim((string)($row['slug'] ?? ''));
        if ($name === '' || !preg_match('/^[a-z0-9-]{1,190}$/D', $slug)) continue;
        $artists[] = ['name' => $name, 'slug' => $slug];
    }
    return $artists;
}

/** @return list<array{@type:string,name:string,url:string}> */
function brvtal_public_schema_artist_nodes(array $entity, string $base): array
{
    $candidates = $entity['schema_artists'] ?? [];
    if (!is_array($candidates)) return [];
    $nodes = [];
    foreach ($candidates as $artist) {
        if (!is_array($artist)) continue;
        $name = brvtal_seo_plain_text($artist['name'] ?? '');
        $slug = trim((string)($artist['slug'] ?? ''));
        if ($name === '' || !preg_match('/^[a-z0-9-]{1,190}$/D', $slug)) continue;
        $nodes[] = ['@type' => 'MusicGroup', 'name' => $name,
            'url' => $base . '/artists/' . rawurlencode($slug)];
    }
    return $nodes;
}

/**
 * Enrich only with facts confirmed by the canonical public entity lookup.
 * Fall back to a generic WebPage when a rich type would assert missing facts.
 */
function brvtal_public_schema_enrich(array $schema, array $entity, string $base): array
{
    $type = (string)($entity['route_type'] ?? '');
    $artists = brvtal_public_schema_artist_nodes($entity, $base);

    if ($type === 'events') {
        $date = brvtal_public_schema_date($entity['event_date'] ?? null);
        $venue = brvtal_seo_plain_text($entity['venue'] ?? '');
        $city = brvtal_seo_plain_text($entity['city'] ?? '');
        if ($date === null || ($venue === '' && $city === '')) {
            $schema['@type'] = 'WebPage';
            return $schema;
        }
        $schema['startDate'] = $date;
        $schema['location'] = ['@type' => 'Place',
            'name' => implode(' / ', array_filter([$venue, $city],
                static fn(string $part): bool => $part !== ''))];
        if ($city !== '') {
            $schema['location']['address'] = ['@type' => 'PostalAddress',
                'addressLocality' => $city];
        }
        $status = strtolower(trim((string)($entity['status'] ?? '')));
        $state = $status === 'cancelled' ? 'EventCancelled'
            : (brvtal_public_event_is_historical($entity) ? 'EventCompleted' : 'EventScheduled');
        $schema['eventStatus'] = 'https://schema.org/' . $state;
        if ($artists !== []) $schema['performer'] = $artists;
    } elseif ($type === 'artists') {
        $sameAs = [];
        foreach (['instagram_url', 'soundcloud_url', 'website_url'] as $field) {
            $external = brvtal_public_schema_external_url($entity[$field] ?? null);
            if ($external !== null) $sameAs[] = $external;
        }
        if ($sameAs !== []) $schema['sameAs'] = array_values(array_unique($sameAs));
    } elseif ($type === 'sets') {
        $external = brvtal_public_schema_external_url($entity['external_url'] ?? null);
        if ($artists === [] && $external === null) {
            $schema['@type'] = 'WebPage';
            return $schema;
        }
        if ($artists !== []) $schema['byArtist'] = $artists;
        if ($external !== null) $schema['sameAs'] = $external;
    } elseif ($type === 'releases') {
        $date = brvtal_public_schema_date($entity['release_date'] ?? null);
        if ($date === null && $artists === []) {
            $schema['@type'] = 'WebPage';
            return $schema;
        }
        if ($date !== null) $schema['datePublished'] = $date;
        if ($artists !== []) $schema['byArtist'] = $artists;
        $catalog = brvtal_seo_plain_text($entity['catalog_number'] ?? '');
        if ($catalog !== '') $schema['identifier'] = $catalog;
        $sameAs = [];
        foreach (['spotify_url', 'soundcloud_url', 'bandcamp_url', 'youtube_url', 'beatport_url'] as $field) {
            $external = brvtal_public_schema_external_url($entity[$field] ?? null);
            if ($external !== null) $sameAs[] = $external;
        }
        if ($sameAs !== []) $schema['sameAs'] = array_values(array_unique($sameAs));
    } elseif ($type === 'blog') {
        $date = brvtal_public_schema_date($entity['published_at'] ?? null);
        if ($date === null) {
            $schema['@type'] = 'WebPage';
            return $schema;
        }
        $schema['headline'] = brvtal_seo_plain_text($entity['title'] ?? '');
        $schema['mainEntityOfPage'] = $schema['url'];
        $schema['datePublished'] = $date;
        $modified = brvtal_public_schema_date($entity['updated_at'] ?? null);
        if ($modified !== null && $modified >= $date) $schema['dateModified'] = $modified;
        $schema['publisher'] = ['@type' => 'Organization', 'name' => 'BRVTAL'];
    } elseif ($type === 'pages' && ($entity['locale'] ?? '') === 'en') {
        $schema['inLanguage'] = 'en';
    }
    return $schema;
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
    // Whitelisted columns from the existing published content-family tables.
    $eventSelect = match ($type) {
        'events' => ',status,event_date,published_at,venue,city',
        'artists' => ',instagram_url,soundcloud_url,website_url',
        'sets' => ',external_url,artist_id',
        'releases' => ',release_date,catalog_number,spotify_url,soundcloud_url,bandcamp_url,youtube_url,beatport_url',
        'blog' => ',published_at,updated_at',
        'pages' => ',locale',
        default => '',
    };
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
    if (in_array($type, ['events', 'sets', 'releases'], true)) {
        $artistId = $type === 'sets' ? (int)($row['artist_id'] ?? 0) : (int)$row['id'];
        $row['schema_artists'] = brvtal_public_schema_artists($pdo, $type, $artistId);
    }
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
    if ($entity !== null) $schema = brvtal_public_schema_enrich($schema, $entity, $base);
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
