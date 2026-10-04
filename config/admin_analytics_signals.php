<?php
declare(strict_types=1);

const BRVTAL_GA4_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const BRVTAL_GA4_DATA_BASE = 'https://analyticsdata.googleapis.com';
const BRVTAL_GA4_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
const BRVTAL_GA4_MAX_BYTES = 262144;
const BRVTAL_GA4_CACHE_TTL_SECONDS = 300;
const BRVTAL_GA4_STALE_MAX_SECONDS = 3600;

/** @return array{property_id:string,client_email:string,private_key:string}|null */
function brvtalAnalyticsConfig(?array $override = null): ?array
{
    $propertyId = trim((string)($override['property_id'] ?? getenv('BRVTAL_GA4_PROPERTY_ID') ?: ''));
    $clientEmail = trim((string)($override['client_email'] ?? getenv('BRVTAL_GA4_CLIENT_EMAIL') ?: ''));
    $privateKey = trim((string)($override['private_key'] ?? getenv('BRVTAL_GA4_PRIVATE_KEY') ?: ''));

    if ($propertyId === '' || $clientEmail === '' || $privateKey === '') {
        return null;
    }
    $privateKey = str_replace('\\n', "\n", $privateKey);
    if (
        preg_match('/^[0-9]{1,32}$/', $propertyId) !== 1
        || filter_var($clientEmail, FILTER_VALIDATE_EMAIL) === false
        || !str_contains($privateKey, '-----BEGIN PRIVATE KEY-----')
        || !str_contains($privateKey, '-----END PRIVATE KEY-----')
    ) {
        throw new RuntimeException('GA4_CONFIG_INVALID');
    }

    return [
        'property_id'=>$propertyId,
        'client_email'=>$clientEmail,
        'private_key'=>$privateKey,
    ];
}

function brvtalAnalyticsBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function brvtalAnalyticsJwt(array $config, int $now): string
{
    $header = brvtalAnalyticsBase64Url(json_encode(
        ['alg'=>'RS256', 'typ'=>'JWT'],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));
    $claims = brvtalAnalyticsBase64Url(json_encode([
        'iss'=>$config['client_email'],
        'scope'=>BRVTAL_GA4_SCOPE,
        'aud'=>BRVTAL_GA4_TOKEN_URL,
        'iat'=>$now,
        'exp'=>$now + 3000,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $input = $header . '.' . $claims;
    $key = openssl_pkey_get_private($config['private_key']);
    if ($key === false) {
        throw new RuntimeException('GA4_AUTH_FAILED');
    }
    $signature = '';
    if (!openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('GA4_AUTH_FAILED');
    }
    return $input . '.' . brvtalAnalyticsBase64Url($signature);
}

/** @return array{status:int,body:string} */
function brvtalAnalyticsRequest(string $method, string $url, array $headers, string $body): array
{
    $allowed = $url === BRVTAL_GA4_TOKEN_URL
        || preg_match(
            '#^https://analyticsdata\.googleapis\.com/v1beta/properties/[0-9]{1,32}:runReport$#',
            $url
        ) === 1;
    if (!$allowed || strtoupper($method) !== 'POST') {
        throw new RuntimeException('GA4_URL_NOT_ALLOWED');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('GA4_TRANSPORT_UNAVAILABLE');
    }

    $responseBody = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST=>'POST',
        CURLOPT_POSTFIELDS=>$body,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_CONNECTTIMEOUT=>2,
        CURLOPT_TIMEOUT=>5,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$responseBody): int {
            if (strlen($responseBody) + strlen($chunk) > BRVTAL_GA4_MAX_BYTES) {
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($ok === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('GA4_READ_FAILED');
    }
    return ['status'=>$status, 'body'=>$responseBody];
}

/** @return array<string,mixed> */
function brvtalAnalyticsDecode(array $response): array
{
    $body = $response['body'] ?? null;
    if (!is_string($body) || strlen($body) > BRVTAL_GA4_MAX_BYTES) {
        throw new RuntimeException('GA4_INVALID_RESPONSE');
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('GA4_INVALID_RESPONSE');
    }
    return $decoded;
}

function brvtalAnalyticsMetricValue(mixed $value): int
{
    if (!is_string($value) || preg_match('/^[0-9]+$/', $value) !== 1) {
        throw new RuntimeException('GA4_INVALID_RESPONSE');
    }
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
    if ($number === false) {
        throw new RuntimeException('GA4_INVALID_RESPONSE');
    }
    return $number;
}

/** @return array{users:int,sessions:int,views:int} */
function brvtalAnalyticsSummary(array $payload): array
{
    $rows = $payload['rows'] ?? null;
    if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
        throw new RuntimeException('GA4_INVALID_RESPONSE');
    }
    $metrics = $rows[0]['metricValues'] ?? null;
    if (!is_array($metrics) || count($metrics) < 3) {
        throw new RuntimeException('GA4_INVALID_RESPONSE');
    }
    return [
        'users'=>brvtalAnalyticsMetricValue($metrics[0]['value'] ?? null),
        'sessions'=>brvtalAnalyticsMetricValue($metrics[1]['value'] ?? null),
        'views'=>brvtalAnalyticsMetricValue($metrics[2]['value'] ?? null),
    ];
}

/** @return list<array{path:string,views:int}> */
function brvtalAnalyticsTopPages(array $payload): array
{
    $rows = $payload['rows'] ?? [];
    if (!is_array($rows)) {
        throw new RuntimeException('GA4_INVALID_RESPONSE');
    }
    $items = [];
    foreach (array_slice($rows, 0, 5) as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('GA4_INVALID_RESPONSE');
        }
        $path = $row['dimensionValues'][0]['value'] ?? null;
        $views = $row['metricValues'][0]['value'] ?? null;
        if (!is_string($path) || $path === '' || strlen($path) > 200) {
            throw new RuntimeException('GA4_INVALID_RESPONSE');
        }
        $items[] = ['path'=>$path, 'views'=>brvtalAnalyticsMetricValue($views)];
    }
    return $items;
}

/** @return list<array{category:string,users:int}> */
function brvtalAnalyticsDevices(array $payload): array
{
    $rows = $payload['rows'] ?? [];
    if (!is_array($rows)) {
        throw new RuntimeException('GA4_INVALID_RESPONSE');
    }
    $items = [];
    foreach (array_slice($rows, 0, 5) as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('GA4_INVALID_RESPONSE');
        }
        $category = $row['dimensionValues'][0]['value'] ?? null;
        $users = $row['metricValues'][0]['value'] ?? null;
        if (
            !is_string($category)
            || preg_match('/^[A-Za-z0-9 _-]{1,40}$/', $category) !== 1
        ) {
            throw new RuntimeException('GA4_INVALID_RESPONSE');
        }
        $items[] = ['category'=>strtolower($category), 'users'=>brvtalAnalyticsMetricValue($users)];
    }
    return $items;
}

function brvtalAnalyticsCacheFile(): string
{
    return dirname(__DIR__) . '/storage/admin-analytics-signals-cache.json';
}

/** @return array<string,mixed>|null */
function brvtalAnalyticsCacheReadFrom(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '' || strlen($raw) > BRVTAL_GA4_MAX_BYTES) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function brvtalAnalyticsCacheWriteTo(string $path, string $prefix, array $record): void
{
    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($json) > BRVTAL_GA4_MAX_BYTES) {
        return;
    }
    $tmp = tempnam(dirname($path), $prefix);
    if ($tmp === false) {
        return;
    }
    try {
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0600);
        @rename($tmp, $path);
    } finally {
        if (is_file($tmp)) {
            @unlink($tmp);
        }
    }
}

/** @return array<string,mixed>|null */
function brvtalAnalyticsCacheRead(): ?array
{
    return brvtalAnalyticsCacheReadFrom(brvtalAnalyticsCacheFile());
}

function brvtalAnalyticsCacheWrite(array $record): void
{
    brvtalAnalyticsCacheWriteTo(brvtalAnalyticsCacheFile(), '.ga4-', $record);
}

/** @return array<string,mixed> */
function brvtalAnalyticsEmpty(string $state): array
{
    $normalized = in_array($state, ['NOT CONFIGURED', 'UNAVAILABLE'], true)
        ? $state
        : 'UNAVAILABLE';
    return [
        'status'=>$normalized === 'NOT CONFIGURED' ? 'not_configured' : 'unavailable',
        'freshness'=>$normalized === 'NOT CONFIGURED' ? 'not_configured' : 'unavailable',
        'state'=>$normalized,
        'metrics'=>['users'=>null, 'sessions'=>null, 'views'=>null],
        'previous'=>null,
        'top_pages'=>[],
        'devices'=>[],
        'source_at'=>null,
        'cached'=>false,
        'read_only'=>true,
    ];
}

function brvtalAnalyticsValidMetrics(mixed $metrics): bool
{
    return is_array($metrics)
        && is_int($metrics['users'] ?? null) && $metrics['users'] >= 0
        && is_int($metrics['sessions'] ?? null) && $metrics['sessions'] >= 0
        && is_int($metrics['views'] ?? null) && $metrics['views'] >= 0;
}

/** @return array<string,mixed>|null */
function brvtalAnalyticsCacheCandidate(
    mixed $cache,
    string $propertyId,
    int $now,
    int $maxAge,
    string $state
): ?array {
    if (!is_array($cache) || ($cache['property_id'] ?? null) !== $propertyId) {
        return null;
    }
    $storedAt = $cache['stored_at'] ?? null;
    $data = $cache['data'] ?? null;
    if (!is_int($storedAt) || $storedAt > $now || ($now - $storedAt) > $maxAge || !is_array($data)) {
        return null;
    }
    if (
        !brvtalAnalyticsValidMetrics($data['metrics'] ?? null)
        || !is_array($data['top_pages'] ?? null)
        || !is_array($data['devices'] ?? null)
        || !is_string($data['source_at'] ?? null)
    ) {
        return null;
    }
    $data['state'] = $state;
    $data['status'] = 'available';
    $data['freshness'] = $state === 'STALE' ? 'stale' : 'fresh';
    $data['cached'] = true;
    $data['read_only'] = true;
    return $data;
}

function brvtalAnalyticsAccessToken(
    array $config,
    int $now,
    callable $request,
    ?callable $assertionFactory = null
): string {
    $assertion = ($assertionFactory ?? 'brvtalAnalyticsJwt')($config, $now);
    if (!is_string($assertion) || $assertion === '') {
        throw new RuntimeException('GA4_AUTH_FAILED');
    }

    $tokenPayload = brvtalAnalyticsDecode($request(
        'POST',
        BRVTAL_GA4_TOKEN_URL,
        ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'=>$assertion,
        ], '', '&', PHP_QUERY_RFC3986)
    ));
    $accessToken = $tokenPayload['access_token'] ?? null;
    if (!is_string($accessToken) || trim($accessToken) === '') {
        throw new RuntimeException('GA4_AUTH_FAILED');
    }
    return $accessToken;
}

/** @return callable(array<string,mixed>):array<string,mixed> */
function brvtalAnalyticsReportRunner(callable $request, array $config, string $accessToken): callable
{
    $runUrl = BRVTAL_GA4_DATA_BASE . '/v1beta/properties/' . $config['property_id'] . ':runReport';
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
    ];

    return static function (array $body) use ($request, $runUrl, $headers): array {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > BRVTAL_GA4_MAX_BYTES) {
            throw new RuntimeException('GA4_REQUEST_TOO_LARGE');
        }
        return brvtalAnalyticsDecode($request('POST', $runUrl, $headers, $encoded));
    };
}

/** @return array<string,mixed> */
function brvtalAdminAnalyticsSignals(
    ?callable $requester = null,
    ?callable $cacheReader = null,
    ?callable $cacheWriter = null,
    ?callable $clock = null,
    ?callable $assertionFactory = null,
    ?array $configOverride = null
): array {
    $request = $requester ?? 'brvtalAnalyticsRequest';
    $readCache = $cacheReader ?? 'brvtalAnalyticsCacheRead';
    $writeCache = $cacheWriter ?? 'brvtalAnalyticsCacheWrite';
    $now = (int)(($clock ?? time(...))());

    try {
        $config = brvtalAnalyticsConfig($configOverride);
        if ($config === null) {
            return brvtalAnalyticsEmpty('NOT CONFIGURED');
        }

        $cache = $readCache();
        $freshCache = brvtalAnalyticsCacheCandidate(
            $cache,
            $config['property_id'],
            $now,
            BRVTAL_GA4_CACHE_TTL_SECONDS,
            'FRESH'
        );
        if ($freshCache !== null) {
            return $freshCache;
        }

        $accessToken = brvtalAnalyticsAccessToken($config, $now, $request, $assertionFactory);
        $run = brvtalAnalyticsReportRunner($request, $config, $accessToken);
        $metrics = ['activeUsers'=>new stdClass(), 'sessions'=>new stdClass(), 'screenPageViews'=>new stdClass()];
        $current = brvtalAnalyticsSummary($run([
            'dateRanges'=>[['startDate'=>'7daysAgo', 'endDate'=>'yesterday']],
            'metrics'=>array_map(static fn(string $name): array => ['name'=>$name], array_keys($metrics)),
        ]));
        $previous = null;
        try {
            $previous = brvtalAnalyticsSummary($run([
                'dateRanges'=>[['startDate'=>'14daysAgo', 'endDate'=>'8daysAgo']],
                'metrics'=>array_map(static fn(string $name): array => ['name'=>$name], array_keys($metrics)),
            ]));
        } catch (Throwable) {
            $previous = null;
        }
        $topPages = brvtalAnalyticsTopPages($run([
            'dateRanges'=>[['startDate'=>'7daysAgo', 'endDate'=>'yesterday']],
            'dimensions'=>[['name'=>'pagePath']],
            'metrics'=>[['name'=>'screenPageViews']],
            'orderBys'=>[['metric'=>['metricName'=>'screenPageViews'], 'desc'=>true]],
            'limit'=>5,
        ]));
        $devices = brvtalAnalyticsDevices($run([
            'dateRanges'=>[['startDate'=>'7daysAgo', 'endDate'=>'yesterday']],
            'dimensions'=>[['name'=>'deviceCategory']],
            'metrics'=>[['name'=>'activeUsers']],
            'orderBys'=>[['metric'=>['metricName'=>'activeUsers'], 'desc'=>true]],
            'limit'=>5,
        ]));

        $sourceAt = gmdate('c', $now);
        $data = [
            'status'=>'available',
            'freshness'=>'fresh',
            'state'=>'FRESH',
            'metrics'=>$current,
            'previous'=>$previous,
            'top_pages'=>$topPages,
            'devices'=>$devices,
            'source_at'=>$sourceAt,
            'cached'=>false,
            'read_only'=>true,
        ];
        try {
            $writeCache([
                'property_id'=>$config['property_id'],
                'stored_at'=>$now,
                'data'=>$data,
            ]);
        } catch (Throwable) {
            // Reporting remains usable when the bounded cache cannot be written.
        }
        return $data;
    } catch (Throwable) {
        try {
            $config ??= brvtalAnalyticsConfig($configOverride);
            if (is_array($config)) {
                $stale = brvtalAnalyticsCacheCandidate(
                    $readCache(),
                    $config['property_id'],
                    $now,
                    BRVTAL_GA4_STALE_MAX_SECONDS,
                    'STALE'
                );
                if ($stale !== null) {
                    return $stale;
                }
            }
        } catch (Throwable) {
            // Fall through to a secret-safe unavailable state.
        }
        return brvtalAnalyticsEmpty('UNAVAILABLE');
    }
}
