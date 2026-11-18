<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_analytics_signals.php';
require_once __DIR__ . '/public_visibility.php';

const BRVTAL_EVENT_ANALYTICS_CACHE_TTL_SECONDS = 300;
const BRVTAL_EVENT_ANALYTICS_STALE_MAX_SECONDS = 3600;

/** @return array{key:string,start:string,end:string} */
function brvtalEventAnalyticsWindow(): array
{
    return [
        'key'=>'7d',
        'start'=>'7daysAgo',
        'end'=>'yesterday',
    ];
}

/**
 * Resolve the only public analytics identity accepted for an Event.
 *
 * Identity is derived from the persisted Event, never from an arbitrary URL.
 * Optional route fields and caller assertions are treated as consistency checks.
 *
 * @return array{event_id:int,canonical_path:string}|null
 */
function brvtalEventAnalyticsIdentity(array $event, ?string $claimedCanonicalPath = null): ?array
{
    $rawId = $event['id'] ?? null;
    if (is_int($rawId)) {
        $eventId = $rawId;
    } elseif (is_string($rawId) && preg_match('/^[1-9][0-9]{0,18}$/', $rawId) === 1) {
        $eventId = (int)$rawId;
    } else {
        return null;
    }
    if ($eventId <= 0) {
        return null;
    }

    $slug = trim((string)($event['slug'] ?? ''));
    if (
        $slug === ''
        || strlen($slug) > 190
        || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1
        || !brvtal_public_event_is_visible($event)
    ) {
        return null;
    }

    $canonicalPath = '/events/' . $slug;
    foreach (['canonical_path', 'canonical_route', 'route'] as $field) {
        if (!array_key_exists($field, $event)) {
            continue;
        }
        $asserted = trim((string)$event[$field]);
        if ($asserted !== '' && $asserted !== $canonicalPath) {
            return null;
        }
    }
    if ($claimedCanonicalPath !== null && trim($claimedCanonicalPath) !== $canonicalPath) {
        return null;
    }

    return ['event_id'=>$eventId, 'canonical_path'=>$canonicalPath];
}

function brvtalEventAnalyticsCacheFile(): string
{
    return dirname(__DIR__) . '/storage/event-analytics-signals-cache.json';
}

/** @return array<string,mixed>|null */
function brvtalEventAnalyticsCacheRead(): ?array
{
    return brvtalAnalyticsCacheReadFrom(brvtalEventAnalyticsCacheFile());
}

function brvtalEventAnalyticsCacheWrite(array $record): void
{
    brvtalAnalyticsCacheWriteTo(brvtalEventAnalyticsCacheFile(), '.event-ga4-', $record);
}

/** @return array<string,mixed> */
function brvtalEventAnalyticsEmpty(string $state, ?array $identity = null): array
{
    $normalized = $state === 'NOT CONFIGURED' ? 'NOT CONFIGURED' : 'UNAVAILABLE';
    $window = brvtalEventAnalyticsWindow();
    return [
        'status'=>$normalized === 'NOT CONFIGURED' ? 'not_configured' : 'unavailable',
        'freshness'=>$normalized === 'NOT CONFIGURED' ? 'not_configured' : 'unavailable',
        'state'=>$normalized,
        'event_id'=>$identity['event_id'] ?? null,
        'canonical_path'=>$identity['canonical_path'] ?? null,
        'window'=>$window,
        'metrics'=>['users'=>null, 'sessions'=>null, 'views'=>null],
        'previous'=>null,
        'source'=>'ga4',
        'source_at'=>null,
        'cached'=>false,
        'read_only'=>true,
    ];
}

/** @return array<string,mixed> */
function brvtalEventAnalyticsAvailable(
    array $identity,
    array $metrics,
    ?array $previous,
    string $sourceAt,
    string $state,
    bool $cached
): array {
    return [
        'status'=>'available',
        'freshness'=>$state === 'STALE' ? 'stale' : 'fresh',
        'state'=>$state === 'STALE' ? 'STALE' : 'FRESH',
        'event_id'=>$identity['event_id'],
        'canonical_path'=>$identity['canonical_path'],
        'window'=>brvtalEventAnalyticsWindow(),
        'metrics'=>$metrics,
        'previous'=>$previous,
        'source'=>'ga4',
        'source_at'=>$sourceAt,
        'cached'=>$cached,
        'read_only'=>true,
    ];
}

/** @return array<string,mixed>|null */
function brvtalEventAnalyticsCacheCandidate(
    mixed $cache,
    string $propertyId,
    array $identity,
    int $now,
    int $maxAge,
    string $state
): ?array {
    if (
        !is_array($cache)
        || ($cache['property_id'] ?? null) !== $propertyId
        || ($cache['event_id'] ?? null) !== $identity['event_id']
        || ($cache['canonical_path'] ?? null) !== $identity['canonical_path']
        || ($cache['window_key'] ?? null) !== brvtalEventAnalyticsWindow()['key']
    ) {
        return null;
    }

    $storedAt = $cache['stored_at'] ?? null;
    $data = $cache['data'] ?? null;
    if (
        !is_int($storedAt)
        || $storedAt > $now
        || ($now - $storedAt) > $maxAge
        || !is_array($data)
        || !brvtalAnalyticsValidMetrics($data['metrics'] ?? null)
    ) {
        return null;
    }

    $previous = $data['previous'] ?? null;
    if ($previous !== null && !brvtalAnalyticsValidMetrics($previous)) {
        return null;
    }
    $sourceAt = $data['source_at'] ?? null;
    if (!is_string($sourceAt) || $sourceAt === '' || strlen($sourceAt) > 64) {
        return null;
    }

    return brvtalEventAnalyticsAvailable(
        $identity,
        $data['metrics'],
        $previous,
        $sourceAt,
        $state,
        true
    );
}

/** @return array<string,mixed> */
function brvtalEventAnalyticsSignals(
    array $event,
    ?string $claimedCanonicalPath = null,
    ?callable $requester = null,
    ?callable $cacheReader = null,
    ?callable $cacheWriter = null,
    ?callable $clock = null,
    ?callable $assertionFactory = null,
    ?array $configOverride = null
): array {
    $identity = brvtalEventAnalyticsIdentity($event, $claimedCanonicalPath);
    if ($identity === null) {
        return brvtalEventAnalyticsEmpty('UNAVAILABLE');
    }

    $request = $requester ?? 'brvtalAnalyticsRequest';
    $readCache = $cacheReader ?? 'brvtalEventAnalyticsCacheRead';
    $writeCache = $cacheWriter ?? 'brvtalEventAnalyticsCacheWrite';
    $now = (int)(($clock ?? time(...))());

    try {
        $config = brvtalAnalyticsConfig($configOverride);
        if ($config === null) {
            return brvtalEventAnalyticsEmpty('NOT CONFIGURED', $identity);
        }

        $cache = $readCache();
        $freshCache = brvtalEventAnalyticsCacheCandidate(
            $cache,
            $config['property_id'],
            $identity,
            $now,
            BRVTAL_EVENT_ANALYTICS_CACHE_TTL_SECONDS,
            'FRESH'
        );
        if ($freshCache !== null) {
            return $freshCache;
        }

        $accessToken = brvtalAnalyticsAccessToken($config, $now, $request, $assertionFactory);
        $run = brvtalAnalyticsReportRunner($request, $config, $accessToken);

        $window = brvtalEventAnalyticsWindow();
        $metrics = ['activeUsers', 'sessions', 'screenPageViews'];
        $filter = [
            'filter'=>[
                'fieldName'=>'pagePath',
                'stringFilter'=>[
                    'matchType'=>'EXACT',
                    'value'=>$identity['canonical_path'],
                    'caseSensitive'=>true,
                ],
            ],
        ];
        $metricSpec = array_map(static fn(string $name): array => ['name'=>$name], $metrics);
        $current = brvtalAnalyticsSummary($run([
            'dateRanges'=>[['startDate'=>$window['start'], 'endDate'=>$window['end']]],
            'dimensionFilter'=>$filter,
            'metrics'=>$metricSpec,
        ]));
        $previous = null;
        try {
            $previous = brvtalAnalyticsSummary($run([
                'dateRanges'=>[['startDate'=>'14daysAgo', 'endDate'=>'8daysAgo']],
                'dimensionFilter'=>$filter,
                'metrics'=>$metricSpec,
            ]));
        } catch (Throwable) {
            $previous = null;
        }

        $sourceAt = gmdate('c', $now);
        $data = brvtalEventAnalyticsAvailable(
            $identity,
            $current,
            $previous,
            $sourceAt,
            'FRESH',
            false
        );
        try {
            $writeCache([
                'property_id'=>$config['property_id'],
                'event_id'=>$identity['event_id'],
                'canonical_path'=>$identity['canonical_path'],
                'window_key'=>$window['key'],
                'stored_at'=>$now,
                'data'=>[
                    'metrics'=>$current,
                    'previous'=>$previous,
                    'source_at'=>$sourceAt,
                ],
            ]);
        } catch (Throwable) {
            // Reporting remains usable when the bounded cache cannot be written.
        }
        return $data;
    } catch (Throwable) {
        try {
            $config ??= brvtalAnalyticsConfig($configOverride);
            if (is_array($config)) {
                $stale = brvtalEventAnalyticsCacheCandidate(
                    $readCache(),
                    $config['property_id'],
                    $identity,
                    $now,
                    BRVTAL_EVENT_ANALYTICS_STALE_MAX_SECONDS,
                    'STALE'
                );
                if ($stale !== null) {
                    return $stale;
                }
            }
        } catch (Throwable) {
            // Fall through to the same secret-safe unavailable state as Analytics.
        }
        return brvtalEventAnalyticsEmpty('UNAVAILABLE', $identity);
    }
}
