<?php
declare(strict_types=1);

require_once __DIR__ . '/public_translation_overlay.php';
require_once __DIR__ . '/public_translation_deepl_transport.php';

/**
 * One-page DeepL trial. Preparing the branch does not authorize a provider
 * call. The operator gates are OFF by default and do not substitute for the
 * independently verified legal, backup and D-043 production receipts.
 */
function brvtalDeepLOnePageTrialGate(
    string $locale,
    string $routeType,
    string $routeSlug,
    callable $env
): bool {
    if ($locale !== 'en' || $routeType !== 'pages'
        || preg_match('/^[a-z0-9-]{1,190}$/D', $routeSlug) !== 1
    ) {
        return false;
    }
    $allowed = $env('BRVTAL_DEEPL_TRIAL_PAGE_SLUG');
    return is_string($allowed)
        && preg_match('/^[a-z0-9-]{1,190}$/D', $allowed) === 1
        && hash_equals($allowed, $routeSlug)
        && $env('BRVTAL_DEEPL_ENABLED') === '1'
        && $env('BRVTAL_DEEPL_TRIAL_OPERATIONAL_PERMIT') === '1'
        && is_string($env('DEEPL_AUTH_KEY'))
        && trim((string)$env('DEEPL_AUTH_KEY')) !== '';
}

/**
 * Fail closed before ANY cache or provider invocation. No HTML, emails,
 * long digit strings, links or URLs are eligible for a provider trial.
 */
function brvtalDeepLOnePageTrialSafeSource(array $entity): bool
{
    foreach (brvtalPublicEditorialOverlayTranslatableFields($entity) as $field) {
        $raw = $entity[$field] ?? '';
        if (!is_string($raw)) {
            return false;
        }
        if (trim($raw) === '') {
            continue;
        }
        if (brvtalPublicEditorialOverlayText($raw) === null
            || brvtalPublicTranslationPlainText($raw) !== trim($raw)
            || preg_match('/(?:@|https?:\/\/|www\.|[0-9]{7,}|\+?[0-9][0-9 ()+.-]{7,}[0-9])/iu', $raw) === 1
        ) {
            return false;
        }
    }
    return true;
}

/**
 * Synthetic-testable projection, with a caller-injected adapter only.
 * @param callable(string):(?string) $env
 * @param callable(array):(?string) $cacheRead
 * @param callable(array,string):void $cacheWrite
 */
function brvtalDeepLOnePageTrialProject(
    array $page,
    string $locale,
    string $routeType,
    string $routeSlug,
    callable $env,
    ?BrvtalPublicTranslationAdapter $adapter,
    callable $cacheRead,
    callable $cacheWrite,
    bool $isPublic,
    bool $trusted
): array {
    if (!brvtalDeepLOnePageTrialGate($locale, $routeType, $routeSlug, $env)
        || $adapter === null || !empty($page['degraded'])
    ) {
        return $page;
    }
    $source = $page['entity'] ?? null;
    if (!is_array($source)
        || ($source['route_type'] ?? null) !== 'pages'
        || ($source['slug'] ?? null) !== $routeSlug
        || ($source['status'] ?? null) !== 'published'
        || !brvtalPublicEditorialOverlayEligible($source, $isPublic, $trusted)
        || !brvtalDeepLOnePageTrialSafeSource($source)
    ) {
        return $page;
    }
    try {
        $overlay = brvtalPublicEditorialOverlayResolve(
            $source, 'en', $adapter, $cacheRead, $cacheWrite,
            $isPublic, $trusted
        );
        if (!is_array($overlay)) {
            return $page;
        }
        $applied = brvtalPublicEditorialOverlayApply($source, $overlay, $isPublic, $trusted);
        if (!is_array($applied)) {
            return $page;
        }
        $page['entity'] = $applied['entity'];
        return $page;
    } catch (Throwable) {
        return $page;
    }
}

/** Runtime wiring: all gates are disabled by default. */
function brvtalDeepLOnePageTrialForRequest(
    array $page,
    string $locale,
    string $routeType,
    string $routeSlug,
    PDO $pdo
): array {
    $env = static function (string $key): ?string {
        $value = getenv($key);
        return is_string($value) ? $value : null;
    };
    if (!brvtalDeepLOnePageTrialGate($locale, $routeType, $routeSlug, $env)) {
        return $page;
    }
    $adapter = BrvtalDeepLHttpTransport::adapterFromEnvironment(null, $env);
    if ($adapter === null) {
        return $page;
    }
    return brvtalDeepLOnePageTrialProject(
        $page, $locale, $routeType, $routeSlug, $env, $adapter,
        static fn(array $identity): ?string => brvtalPublicTranslationCacheRead($pdo, $identity),
        static function (array $identity, string $text) use ($pdo): void {
            brvtalPublicTranslationCacheWrite($pdo, $identity, $text);
        },
        true,
        true
    );
}
