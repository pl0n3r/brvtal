<?php
declare(strict_types=1);

require_once __DIR__ . '/public_settings.php';
require_once __DIR__ . '/public_routes.php';

const BRVTAL_INDEXNOW_SETTING_KEY = 'indexnow';
const BRVTAL_INDEXNOW_KEY_LOCATION = '/indexnow-key.txt';
const BRVTAL_INDEXNOW_DEFAULT_ENDPOINT = 'https://api.indexnow.org/indexnow';

function brvtal_indexnow_key_valid(string $key): bool
{
    return preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) === 1;
}

/** @return array{error:string,field:string}|null */
function brvtal_indexnow_setting_error(string $rawValue, int $isJson): ?array
{
    if ($isJson !== 1) {
        return ['error' => 'INDEXNOW_JSON_REQUIRED', 'field' => 'is_json'];
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return ['error' => 'INVALID_SETTING_JSON', 'field' => 'setting_value'];
    }

    $enabledRaw = $decoded['enabled'] ?? false;
    if (!is_bool($enabledRaw) && !in_array($enabledRaw, [0, 1, '0', '1'], true)) {
        return ['error' => 'INDEXNOW_ENABLED_INVALID', 'field' => 'setting_value'];
    }

    $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOL);
    $key = trim((string)($decoded['key'] ?? ''));
    if ($key !== '' && !brvtal_indexnow_key_valid($key)) {
        return ['error' => 'INDEXNOW_KEY_INVALID', 'field' => 'setting_value'];
    }
    if ($enabled && $key === '') {
        return ['error' => 'INDEXNOW_KEY_REQUIRED', 'field' => 'setting_value'];
    }

    return null;
}

/** @return array{enabled:bool,key:string} */
function brvtal_indexnow_setting(PDO $pdo): array
{
    $setting = brvtal_config_setting_json($pdo, BRVTAL_INDEXNOW_SETTING_KEY);
    $key = trim((string)($setting['key'] ?? ''));
    $enabled = filter_var($setting['enabled'] ?? false, FILTER_VALIDATE_BOOL);

    if (!brvtal_indexnow_key_valid($key)) {
        return ['enabled' => false, 'key' => ''];
    }

    return ['enabled' => $enabled, 'key' => $key];
}

function brvtal_indexnow_base_url(): string
{
    global $config;

    $base = rtrim(trim((string)($config['app']['base_url'] ?? 'https://www.brvtal.com.co')), '/');
    if (preg_match('#^http://(?:127\.0\.0\.1|localhost)(?::\d+)?$#i', $base)) {
        return $base;
    }
    if ($base === 'https://brvtal.com.co') {
        return 'https://www.brvtal.com.co';
    }
    if (!preg_match('#^https://#i', $base)) {
        return 'https://www.brvtal.com.co';
    }

    return $base;
}

function brvtal_indexnow_endpoint(): string
{
    global $config;

    $candidate = trim((string)($config['indexnow']['endpoint'] ?? ''));
    if ($candidate !== ''
        && filter_var($candidate, FILTER_VALIDATE_URL)
        && preg_match('#^https?://#i', $candidate)) {
        return $candidate;
    }

    return BRVTAL_INDEXNOW_DEFAULT_ENDPOINT;
}

function brvtal_indexnow_row_is_public(string $resource, array $row): bool
{
    if ($resource === 'events') {
        return brvtal_public_event_is_visible($row);
    }
    if ($resource === 'pages') {
        return brvtal_public_page_is_visible($row);
    }

    return in_array($resource, ['artists', 'sets', 'releases', 'blog'], true)
        && strtolower(trim((string)($row['status'] ?? ''))) === 'published';
}

function brvtal_indexnow_row_path(string $resource, array $row): ?string
{
    $routes = [
        'events' => 'events',
        'artists' => 'artists',
        'sets' => 'sets',
        'releases' => 'releases',
        'blog' => 'blog',
        'pages' => 'pages',
    ];
    if (!isset($routes[$resource])) {
        return null;
    }

    $slug = trim((string)($row['slug'] ?? ''));
    if ($slug === '' || preg_match('/^[a-z0-9-]{1,190}$/', $slug) !== 1) {
        return null;
    }

    return '/' . $routes[$resource] . '/' . rawurlencode($slug);
}

/** @return list<string> */
function brvtal_indexnow_change_urls(string $resource, ?array $before, ?array $after): array
{
    $base = brvtal_indexnow_base_url();
    $urls = [];
    $touchedPublic = false;

    foreach ([$before, $after] as $row) {
        if (!is_array($row) || !brvtal_indexnow_row_is_public($resource, $row)) {
            continue;
        }

        $path = brvtal_indexnow_row_path($resource, $row);
        if ($path === null) {
            continue;
        }

        $urls[$base . $path] = true;
        $touchedPublic = true;
    }

    if ($touchedPublic) {
        $urls[$base . '/'] = true;
    }

    return array_keys($urls);
}

function brvtal_indexnow_fetch_entity(PDO $pdo, string $resource, int $id): ?array
{
    $tables = [
        'events' => 'events',
        'artists' => 'artists',
        'sets' => 'sets_media',
        'releases' => 'releases',
        'blog' => 'blog_posts',
        'pages' => 'pages',
    ];
    if ($id < 1 || !isset($tables[$resource])) {
        return null;
    }

    $table = $tables[$resource];
    $statement = $pdo->prepare("SELECT * FROM `{$table}` WHERE id=? LIMIT 1");
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function brvtal_indexnow_notify_entity_id(PDO $pdo, string $resource, int $id): void
{
    $row = brvtal_indexnow_fetch_entity($pdo, $resource, $id);
    if ($row !== null) {
        brvtal_indexnow_notify_change($pdo, $resource, null, $row);
    }
}

function brvtal_indexnow_notify_event_id(PDO $pdo, int $eventId): void
{
    brvtal_indexnow_notify_entity_id($pdo, 'events', $eventId);
}

function brvtal_indexnow_notify_change(PDO $pdo, string $resource, ?array $before, ?array $after): void
{
    if ($resource === 'ticket_types') {
        $eventId = (int)(($after['event_id'] ?? null) ?: ($before['event_id'] ?? 0));
        if ($eventId > 0) {
            brvtal_indexnow_notify_event_id($pdo, $eventId);
        }
        return;
    }

    brvtal_indexnow_enqueue_urls($pdo, brvtal_indexnow_change_urls($resource, $before, $after));
}

function brvtal_indexnow_notify_home(PDO $pdo): void
{
    brvtal_indexnow_enqueue_urls($pdo, [brvtal_indexnow_base_url() . '/']);
}

function brvtal_indexnow_notify_setting(PDO $pdo, string $settingKey): void
{
    if ($settingKey === BRVTAL_INDEXNOW_SETTING_KEY || $settingKey === 'analytics') {
        return;
    }

    $publicSetting = in_array($settingKey, ['site', 'social', 'seo', 'appearance', 'home.hero.slider'], true)
        || str_starts_with($settingKey, 'theme.');
    if (!$publicSetting) {
        return;
    }

    brvtal_indexnow_notify_home($pdo);
}

/** @param list<string> $urls */
function brvtal_indexnow_enqueue_urls(PDO $pdo, array $urls): void
{
    if ($urls === []) {
        return;
    }

    $setting = brvtal_indexnow_setting($pdo);
    if (!$setting['enabled'] || $setting['key'] === '') {
        return;
    }

    $base = brvtal_indexnow_base_url();
    $host = (string)(parse_url($base, PHP_URL_HOST) ?? '');
    if ($host === '') {
        return;
    }

    $normalized = [];
    foreach ($urls as $url) {
        $url = trim((string)$url);
        if ($url === '') {
            continue;
        }
        $urlHost = (string)(parse_url($url, PHP_URL_HOST) ?? '');
        if ($urlHost !== $host) {
            continue;
        }
        $normalized[$url] = true;
        if (count($normalized) >= 10000) {
            break;
        }
    }
    if ($normalized === []) {
        return;
    }

    if (!isset($GLOBALS['brvtal_indexnow_pending']) || !is_array($GLOBALS['brvtal_indexnow_pending'])) {
        $GLOBALS['brvtal_indexnow_pending'] = [
            'endpoint' => brvtal_indexnow_endpoint(),
            'host' => $host,
            'key' => $setting['key'],
            'keyLocation' => $base . BRVTAL_INDEXNOW_KEY_LOCATION,
            'urls' => [],
        ];
    }

    foreach (array_keys($normalized) as $url) {
        $GLOBALS['brvtal_indexnow_pending']['urls'][$url] = true;
    }

    if (!empty($GLOBALS['brvtal_indexnow_shutdown_registered'])) {
        return;
    }
    $GLOBALS['brvtal_indexnow_shutdown_registered'] = true;

    register_shutdown_function(static function (): void {
        $pending = $GLOBALS['brvtal_indexnow_pending'] ?? null;
        if (!is_array($pending) || empty($pending['urls'])) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        brvtal_indexnow_submit([
            'endpoint' => (string)($pending['endpoint'] ?? BRVTAL_INDEXNOW_DEFAULT_ENDPOINT),
            'host' => (string)($pending['host'] ?? ''),
            'key' => (string)($pending['key'] ?? ''),
            'keyLocation' => (string)($pending['keyLocation'] ?? ''),
            'urls' => array_keys((array)$pending['urls']),
        ]);
    });
}

/** @param array{endpoint:string,host:string,key:string,keyLocation:string,urls:list<string>} $payload */
function brvtal_indexnow_submit(array $payload): void
{
    if ($payload['host'] === '' || !brvtal_indexnow_key_valid($payload['key']) || $payload['urls'] === []) {
        return;
    }

    $body = json_encode([
        'host' => $payload['host'],
        'key' => $payload['key'],
        'keyLocation' => $payload['keyLocation'],
        'urlList' => array_slice(array_values(array_unique($payload['urls'])), 0, 10000),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) {
        return;
    }

    $status = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $curl = curl_init($payload['endpoint']);
        if ($curl === false) {
            return;
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'BRVTAL-IndexNow/1.0',
        ]);
        curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = (string)curl_error($curl);
        curl_close($curl);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json; charset=utf-8\r\nUser-Agent: BRVTAL-IndexNow/1.0\r\n",
                'content' => $body,
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);
        @file_get_contents($payload['endpoint'], false, $context);
        $headers = $http_response_header ?? [];
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string)$headers[0], $match)) {
            $status = (int)$match[1];
        }
    }

    if (!in_array($status, [200, 202], true) && function_exists('brvtal_log')) {
        brvtal_log('INDEXNOW_SUBMIT_FAILED', 'IndexNow submission failed without blocking editorial save', [
            'status' => $status,
            'url_count' => count($payload['urls']),
            'error' => $error,
        ]);
    }
}
