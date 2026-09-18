<?php
declare(strict_types=1);

require_once __DIR__ . '/public_routes.php';

const BRVTAL_INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';
const BRVTAL_INDEXNOW_ORIGIN = 'https://www.brvtal.com.co';
const BRVTAL_INDEXNOW_KEY_PATH = '/indexnow-key.txt';

/** @return array{enabled:bool,key:string} */
function brvtal_indexnow_settings(): array
{
    global $config;
    $settings = is_array($config['indexnow'] ?? null) ? $config['indexnow'] : [];
    $envKey = getenv('BRVTAL_INDEXNOW_KEY');
    $key = trim(is_string($envKey) && $envKey !== '' ? $envKey : (string)($settings['key'] ?? ''));

    return [
        'enabled' => ($settings['enabled'] ?? true) !== false,
        'key' => $key,
    ];
}

function brvtal_indexnow_valid_key(string $key): bool
{
    return preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) === 1;
}

function brvtal_indexnow_key(): string
{
    $settings = brvtal_indexnow_settings();
    if (!$settings['enabled'] || !brvtal_indexnow_valid_key($settings['key'])) {
        return '';
    }
    return $settings['key'];
}

function brvtal_indexnow_key_location(): string
{
    return BRVTAL_INDEXNOW_ORIGIN . BRVTAL_INDEXNOW_KEY_PATH;
}

function brvtal_indexnow_resource_supported(string $resource): bool
{
    return array_key_exists($resource, brvtal_public_content_definitions());
}

function brvtal_indexnow_row_is_public(string $resource, ?array $row): bool
{
    if ($row === null || !brvtal_indexnow_resource_supported($resource)) {
        return false;
    }
    $slug = trim((string)($row['slug'] ?? ''));
    if ($slug === '') {
        return false;
    }
    if ($resource === 'events') {
        return brvtal_public_event_is_visible($row);
    }
    if ($resource === 'pages') {
        return strtolower(trim((string)($row['status'] ?? ''))) === 'published'
            && strtolower(trim((string)($row['locale'] ?? 'en'))) === 'en';
    }
    return strtolower(trim((string)($row['status'] ?? ''))) === 'published';
}

function brvtal_indexnow_public_url(string $resource, ?array $row): ?string
{
    if (!brvtal_indexnow_row_is_public($resource, $row)) {
        return null;
    }
    $slug = trim((string)$row['slug']);
    return BRVTAL_INDEXNOW_ORIGIN . '/' . rawurlencode($resource) . '/' . rawurlencode($slug);
}

/** @return list<string> */
function brvtal_indexnow_transition_urls(string $resource, ?array $before, ?array $after): array
{
    $beforeUrl = brvtal_indexnow_public_url($resource, $before);
    $afterUrl = brvtal_indexnow_public_url($resource, $after);
    $urls = [];

    if ($beforeUrl !== null && $beforeUrl !== $afterUrl) {
        $urls[] = $beforeUrl;
    }
    if ($afterUrl !== null) {
        $urls[] = $afterUrl;
    } elseif ($beforeUrl !== null && $urls === []) {
        $urls[] = $beforeUrl;
    }

    return array_values(array_unique($urls));
}

function brvtal_indexnow_canonical_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string)($parts['host'] ?? '')) !== 'www.brvtal.com.co'
        || isset($parts['user'])
        || isset($parts['pass'])
        || (isset($parts['port']) && (int)$parts['port'] !== 443)
    ) {
        return null;
    }
    return $url;
}

/**
 * @param list<string> $urls
 * @param null|callable(string,array):array $transport
 * @return array{attempted:bool,ok:bool,status:?int,urls:list<string>,reason:?string}
 */
function brvtal_indexnow_submit_urls(array $urls, ?callable $transport = null): array
{
    $key = brvtal_indexnow_key();
    $clean = [];
    foreach ($urls as $url) {
        $canonical = brvtal_indexnow_canonical_url((string)$url);
        if ($canonical !== null) {
            $clean[$canonical] = true;
        }
    }
    $clean = array_keys($clean);

    if ($key === '') {
        return ['attempted'=>false,'ok'=>false,'status'=>null,'urls'=>$clean,'reason'=>'not_configured'];
    }
    if ($clean === []) {
        return ['attempted'=>false,'ok'=>true,'status'=>null,'urls'=>[],'reason'=>'no_urls'];
    }

    $payload = [
        'host' => 'www.brvtal.com.co',
        'key' => $key,
        'keyLocation' => brvtal_indexnow_key_location(),
        'urlList' => $clean,
    ];

    try {
        $result = $transport !== null
            ? $transport(BRVTAL_INDEXNOW_ENDPOINT, $payload)
            : brvtal_indexnow_http_post(BRVTAL_INDEXNOW_ENDPOINT, $payload);
        $status = isset($result['status']) ? (int)$result['status'] : 0;
        $ok = in_array($status, [200, 202], true);
        if (function_exists('brvtal_log')) {
            brvtal_log(
                $ok ? 'INDEXNOW' : 'INDEXNOW_WARN',
                $ok ? 'IndexNow URLs submitted' : 'IndexNow submission was not accepted',
                ['status'=>$status,'count'=>count($clean)]
            );
        }
        return [
            'attempted'=>true,
            'ok'=>$ok,
            'status'=>$status > 0 ? $status : null,
            'urls'=>$clean,
            'reason'=>$ok ? null : 'http_error',
        ];
    } catch (Throwable $e) {
        if (function_exists('brvtal_log')) {
            brvtal_log('INDEXNOW_WARN', 'IndexNow submission failed without blocking editorial save', [
                'class'=>get_class($e),
                'message'=>$e->getMessage(),
                'count'=>count($clean),
            ]);
        }
        return ['attempted'=>true,'ok'=>false,'status'=>null,'urls'=>$clean,'reason'=>'transport_error'];
    }
}

/** @return array{status:int} */
function brvtal_indexnow_http_post(string $endpoint, array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('INDEXNOW_JSON_ENCODE_ERROR');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('INDEXNOW_CURL_UNAVAILABLE');
    }

    $ch = curl_init($endpoint);
    if ($ch === false) {
        throw new RuntimeException('INDEXNOW_CURL_INIT_FAILED');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 400,
        CURLOPT_TIMEOUT_MS => 1200,
        CURLOPT_USERAGENT => 'BRVTAL-IndexNow/1.0',
    ]);
    $result = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === false && $status === 0) {
        throw new RuntimeException('INDEXNOW_TRANSPORT_ERROR' . ($error !== '' ? ': ' . $error : ''));
    }
    return ['status'=>$status];
}

/**
 * Submit the public URL transition after a successful editorial mutation.
 * This boundary is intentionally fail-open: IndexNow can never roll back or
 * change the result of the already-committed editorial write.
 *
 * @return array{attempted:bool,ok:bool,status:?int,urls:list<string>,reason:?string}
 */
function brvtal_indexnow_notify_transition(
    string $resource,
    ?array $before,
    ?array $after,
    ?callable $transport = null
): array {
    try {
        return brvtal_indexnow_submit_urls(
            brvtal_indexnow_transition_urls($resource, $before, $after),
            $transport
        );
    } catch (Throwable $e) {
        if (function_exists('brvtal_log')) {
            brvtal_log('INDEXNOW_WARN', 'IndexNow transition failed open', [
                'resource'=>$resource,
                'class'=>get_class($e),
                'message'=>$e->getMessage(),
            ]);
        }
        return ['attempted'=>false,'ok'=>false,'status'=>null,'urls'=>[],'reason'=>'transition_error'];
    }
}
