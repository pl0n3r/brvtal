<?php
declare(strict_types=1);

require_once __DIR__ . '/public_translation.php';
require_once __DIR__ . '/public_visibility.php';

/**
 * Editorial overlays are projections over the canonical Spanish entity.
 * They never create a second editorial record or replace the source identity.
 */

function brvtalPublicEditorialOverlayRoutes(): array
{
    return ['events', 'artists', 'sets', 'releases', 'blog', 'pages'];
}

function brvtalPublicEditorialOverlayEligible(
    array $source,
    bool $isPublic,
    bool $trusted
): bool {
    if (!$isPublic || !$trusted) {
        return false;
    }

    $routeType = trim((string)($source['route_type'] ?? ''));
    $sourceId = (int)($source['id'] ?? 0);
    $slug = trim((string)($source['slug'] ?? ''));
    if (
        !in_array($routeType, brvtalPublicEditorialOverlayRoutes(), true)
        || $sourceId < 1
        || !preg_match('/^[a-z0-9-]{1,190}$/D', $slug)
    ) {
        return false;
    }

    if (array_key_exists('trusted', $source) && $source['trusted'] !== true) {
        return false;
    }
    if (
        !empty($source['private'])
        || !empty($source['is_private'])
        || (
            array_key_exists('visibility', $source)
            && strtolower(trim((string)$source['visibility'])) !== 'public'
        )
    ) {
        return false;
    }

    if (array_key_exists('status', $source)) {
        if ($routeType === 'events') {
            if (!brvtal_public_event_is_visible($source)) {
                return false;
            }
        } elseif (strtolower(trim((string)$source['status'])) !== 'published') {
            return false;
        }
    }

    return true;
}

function brvtalPublicEditorialOverlayNormalizeSourceText(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (!is_scalar($value)) {
        throw new InvalidArgumentException('INVALID_EDITORIAL_SOURCE_TEXT');
    }

    return str_replace(["\r\n", "\r"], "\n", (string)$value);
}

/**
 * Build the complete canonical source snapshot consumed by the overlay layer.
 * Blog body is owned by public_page_data(), so callers must pass the full page
 * payload rather than silently hashing only SEO/excerpt fields.
 */
function brvtalPublicEditorialOverlaySourceFromPage(array $page): array
{
    $entity = $page['entity'] ?? null;
    if (!is_array($entity)) {
        throw new InvalidArgumentException('INVALID_EDITORIAL_PAGE_SOURCE');
    }

    $source = $entity;
    if (($source['route_type'] ?? '') === 'blog') {
        $record = $page['record'] ?? null;
        if (!is_array($record) || !array_key_exists('body_html', $record)) {
            throw new InvalidArgumentException('MISSING_EDITORIAL_BLOG_BODY');
        }
        $source['body'] = brvtalPublicEditorialOverlayNormalizeSourceText(
            $record['body_html']
        );
    }

    return $source;
}

/**
 * @return array{
 *   source_identity:string,
 *   source_hash:string,
 *   source_locale:string,
 *   route_type:string,
 *   source_id:int
 * }
 */
function brvtalPublicEditorialOverlaySourceIdentity(array $source): array
{
    $routeType = trim((string)($source['route_type'] ?? ''));
    $sourceId = (int)($source['id'] ?? 0);
    $slug = trim((string)($source['slug'] ?? ''));
    if (
        !in_array($routeType, brvtalPublicEditorialOverlayRoutes(), true)
        || $sourceId < 1
        || !preg_match('/^[a-z0-9-]{1,190}$/D', $slug)
    ) {
        throw new InvalidArgumentException('INVALID_EDITORIAL_SOURCE_IDENTITY');
    }
    if ($routeType === 'blog' && !array_key_exists('body', $source)) {
        throw new InvalidArgumentException('MISSING_EDITORIAL_BLOG_BODY');
    }

    $sourceFields = [];
    foreach (['title', 'description', 'seo_title', 'seo_description', 'body'] as $field) {
        $sourceFields[$field] = brvtalPublicEditorialOverlayNormalizeSourceText(
            $source[$field] ?? ''
        );
    }

    $canonical = [
        'route_type' => $routeType,
        'source_id' => $sourceId,
        'slug' => $slug,
        'fields' => $sourceFields,
    ];
    $encoded = json_encode(
        $canonical,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    return [
        'source_identity' => $routeType . ':' . $sourceId,
        'source_hash' => hash('sha256', $encoded),
        'source_locale' => 'es',
        'route_type' => $routeType,
        'source_id' => $sourceId,
    ];
}

/** @return list<string> */
function brvtalPublicEditorialOverlayTranslatableFields(array $source): array
{
    $routeType = trim((string)($source['route_type'] ?? ''));
    if (!in_array($routeType, brvtalPublicEditorialOverlayRoutes(), true)) {
        return [];
    }

    // Phase 1 policy protects entity names/titles for the music-domain families.
    // Blog and CMS Page editorial titles are not in that protected-field list.
    return in_array($routeType, ['blog', 'pages'], true)
        ? ['title', 'description', 'seo_title', 'seo_description']
        : ['description', 'seo_description'];
}

function brvtalPublicEditorialOverlayText(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (
        $value === ''
        || strlen($value) > 10000
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
        || str_contains($value, '<')
        || str_contains($value, '>')
    ) {
        return null;
    }
    return $value;
}

/**
 * Resolve a source-bound English overlay using the existing provider-neutral
 * translation/cache contract. Any partial/fallback result fails closed.
 *
 * @param callable(array):(?string) $cacheRead
 * @param callable(array,string):void $cacheWrite
 * @return array{
 *   version:int,
 *   source_identity:string,
 *   source_hash:string,
 *   source_locale:string,
 *   target_locale:string,
 *   route_type:string,
 *   source_id:int,
 *   fields:array<string,string>
 * }|null
 */
function brvtalPublicEditorialOverlayResolve(
    array $source,
    string $targetLocale,
    BrvtalPublicTranslationAdapter $adapter,
    callable $cacheRead,
    callable $cacheWrite,
    bool $isPublic,
    bool $trusted
): ?array {
    if (!brvtalPublicEditorialOverlayEligible($source, $isPublic, $trusted)) {
        return null;
    }

    $targetLocale = brvtalPublicI18nNormalizeLocale($targetLocale) ?? '';
    if ($targetLocale !== 'en') {
        return null;
    }

    try {
        $identity = brvtalPublicEditorialOverlaySourceIdentity($source);
    } catch (InvalidArgumentException) {
        return null;
    }
    $translatedFields = [];
    foreach (brvtalPublicEditorialOverlayTranslatableFields($source) as $field) {
        $sourceText = brvtalPublicEditorialOverlayNormalizeSourceText(
            $source[$field] ?? ''
        );
        if (trim($sourceText) === '') {
            continue;
        }

        $resolved = brvtalPublicTranslationResolve(
            $sourceText,
            $targetLocale,
            $adapter,
            $cacheRead,
            $cacheWrite
        );
        if (
            ($resolved['translated'] ?? false) !== true
            || ($resolved['locale'] ?? '') !== 'en'
        ) {
            return null;
        }

        $safeText = brvtalPublicEditorialOverlayText($resolved['text'] ?? null);
        if ($safeText === null) {
            return null;
        }
        $translatedFields[$field] = $safeText;
    }

    if ($translatedFields === []) {
        return null;
    }

    return [
        'version' => 1,
        'source_identity' => $identity['source_identity'],
        'source_hash' => $identity['source_hash'],
        'source_locale' => 'es',
        'target_locale' => 'en',
        'route_type' => $identity['route_type'],
        'source_id' => $identity['source_id'],
        'fields' => $translatedFields,
    ];
}

/**
 * Apply a previously resolved overlay only while its exact Spanish source hash
 * and publication/trust boundary still match.
 *
 * @return array{
 *   entity:array,
 *   source_identity:string,
 *   source_hash:string,
 *   source_locale:string,
 *   target_locale:string
 * }|null
 */
function brvtalPublicEditorialOverlayApply(
    array $source,
    array $overlay,
    bool $isPublic,
    bool $trusted
): ?array {
    if (!brvtalPublicEditorialOverlayEligible($source, $isPublic, $trusted)) {
        return null;
    }

    $expectedKeys = [
        'version',
        'source_identity',
        'source_hash',
        'source_locale',
        'target_locale',
        'route_type',
        'source_id',
        'fields',
    ];
    $actualKeys = array_keys($overlay);
    sort($expectedKeys);
    sort($actualKeys);
    if ($actualKeys !== $expectedKeys || ($overlay['version'] ?? null) !== 1) {
        return null;
    }

    try {
        $identity = brvtalPublicEditorialOverlaySourceIdentity($source);
    } catch (InvalidArgumentException) {
        return null;
    }
    if (
        ($overlay['source_identity'] ?? '') !== $identity['source_identity']
        || ($overlay['source_hash'] ?? '') !== $identity['source_hash']
        || ($overlay['source_locale'] ?? '') !== 'es'
        || ($overlay['target_locale'] ?? '') !== 'en'
        || ($overlay['route_type'] ?? '') !== $identity['route_type']
        || ($overlay['source_id'] ?? null) !== $identity['source_id']
        || !is_array($overlay['fields'])
    ) {
        return null;
    }

    $allowed = array_flip(brvtalPublicEditorialOverlayTranslatableFields($source));
    $projected = $source;
    foreach ($overlay['fields'] as $field => $value) {
        if (!is_string($field) || !isset($allowed[$field])) {
            return null;
        }
        $safeText = brvtalPublicEditorialOverlayText($value);
        if ($safeText === null) {
            return null;
        }
        $projected[$field] = $safeText;
    }

    if ($overlay['fields'] === []) {
        return null;
    }

    return [
        'entity' => $projected,
        'source_identity' => $identity['source_identity'],
        'source_hash' => $identity['source_hash'],
        'source_locale' => 'es',
        'target_locale' => 'en',
    ];
}
