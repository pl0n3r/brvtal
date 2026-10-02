<?php
declare(strict_types=1);

require_once __DIR__ . '/public_i18n.php';

/**
 * Provider-agnostic public translation adapter.
 *
 * Implementations may use an external provider later, but credentials and
 * provider-specific payloads never belong in the cache contract or result.
 */
interface BrvtalPublicTranslationAdapter
{
    public function version(): string;

    public function translate(string $source, string $sourceLocale, string $targetLocale): string;
}

function brvtalPublicTranslationSourceHash(string $source): string
{
    return hash('sha256', $source);
}

/**
 * @return array{
 *   source_hash:string,
 *   source_locale:string,
 *   target_locale:string,
 *   translator_version:string,
 *   cache_key:string
 * }
 */
function brvtalPublicTranslationCacheIdentity(
    string $source,
    string $sourceLocale,
    string $targetLocale,
    string $translatorVersion
): array {
    $sourceLocale = brvtalPublicI18nNormalizeLocale($sourceLocale) ?? '';
    $targetLocale = brvtalPublicI18nNormalizeLocale($targetLocale) ?? '';
    $translatorVersion = trim($translatorVersion);

    if ($sourceLocale === '' || $targetLocale === '' || $translatorVersion === '') {
        throw new InvalidArgumentException('INVALID_TRANSLATION_CACHE_IDENTITY');
    }
    if (strlen($translatorVersion) > 120) {
        throw new InvalidArgumentException('INVALID_TRANSLATOR_VERSION');
    }

    $sourceHash = brvtalPublicTranslationSourceHash($source);
    $cacheKey = hash(
        'sha256',
        implode("\0", [$sourceHash, $sourceLocale, $targetLocale, $translatorVersion])
    );

    return [
        'source_hash' => $sourceHash,
        'source_locale' => $sourceLocale,
        'target_locale' => $targetLocale,
        'translator_version' => $translatorVersion,
        'cache_key' => $cacheKey,
    ];
}

function brvtalPublicTranslationCacheRead(PDO $pdo, array $identity): ?string
{
    $statement = $pdo->prepare(
        'SELECT translated_text FROM public_translation_cache ' .
        'WHERE source_hash=? AND source_locale=? AND target_locale=? AND translator_version=? LIMIT 1'
    );
    $statement->execute([
        (string)$identity['source_hash'],
        (string)$identity['source_locale'],
        (string)$identity['target_locale'],
        (string)$identity['translator_version'],
    ]);
    $value = $statement->fetchColumn();
    return is_string($value) ? $value : null;
}

function brvtalPublicTranslationCacheWrite(PDO $pdo, array $identity, string $translatedText): void
{
    $statement = $pdo->prepare(
        'INSERT INTO public_translation_cache(' .
        'source_hash,source_locale,target_locale,translator_version,translated_text' .
        ') VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE ' .
        'translated_text=VALUES(translated_text),updated_at=CURRENT_TIMESTAMP'
    );
    $statement->execute([
        (string)$identity['source_hash'],
        (string)$identity['source_locale'],
        (string)$identity['target_locale'],
        (string)$identity['translator_version'],
        $translatedText,
    ]);
}

/**
 * Resolve one canonical-Spanish public string.
 *
 * Cache/provider failures never expose exception/provider details. Provider
 * failure falls back to the original Spanish text as the canonical safe value.
 *
 * @param callable(array):(?string) $cacheRead
 * @param callable(array,string):void $cacheWrite
 * @return array{text:string,locale:string,source:string,translated:bool}
 */
function brvtalPublicTranslationResolve(
    string $spanishSource,
    string $targetLocale,
    BrvtalPublicTranslationAdapter $adapter,
    callable $cacheRead,
    callable $cacheWrite
): array {
    $target = brvtalPublicI18nNormalizeLocale($targetLocale) ?? 'es';
    if ($target === 'es') {
        return [
            'text' => $spanishSource,
            'locale' => 'es',
            'source' => 'canonical',
            'translated' => false,
        ];
    }

    try {
        $identity = brvtalPublicTranslationCacheIdentity(
            $spanishSource,
            'es',
            $target,
            $adapter->version()
        );
        $cached = $cacheRead($identity);
        if (is_string($cached) && $cached !== '') {
            return [
                'text' => $cached,
                'locale' => $target,
                'source' => 'cache',
                'translated' => true,
            ];
        }

        $translated = $adapter->translate($spanishSource, 'es', $target);
        if (trim($translated) === '') {
            throw new RuntimeException('EMPTY_TRANSLATION');
        }
        $cacheWrite($identity, $translated);

        return [
            'text' => $translated,
            'locale' => $target,
            'source' => 'provider',
            'translated' => true,
        ];
    } catch (Throwable) {
        return [
            'text' => $spanishSource,
            'locale' => 'es',
            'source' => 'fallback',
            'translated' => false,
        ];
    }
}

/** Return provider/cache output only when it is safe public plain text. */
function brvtalPublicTranslationPlainText(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $text = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (
        $text === ''
        || strlen($text) > 10000
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)
        || str_contains($text, '<')
        || str_contains($text, '>')
    ) {
        return null;
    }
    return $text;
}

/**
 * Project one canonical Spanish record through the shared translation/cache pipeline.
 * Projection is atomic: one missing/invalid English field returns the whole Spanish
 * source unchanged, so a surface never mixes partial locales or duplicates records.
 *
 * @param callable(array):(?string) $cacheRead
 * @param callable(array,string):void $cacheWrite
 * @return array{record:array,surface:string,requested_locale:string,resolved_locale:string,source:string,translated:bool,translated_fields:list<string>}
 */
function brvtalPublicTranslationProjectRecord(
    array $canonicalRecord,
    string $surface,
    string $targetLocale,
    BrvtalPublicTranslationAdapter $adapter,
    callable $cacheRead,
    callable $cacheWrite
): array {
    $surface = strtolower(trim($surface));
    $target = brvtalPublicI18nNormalizeLocale($targetLocale) ?? 'es';
    $fields = brvtalPublicI18nEditorialFields($surface);

    $canonical = static fn(string $source): array => [
        'record' => $canonicalRecord,
        'surface' => $surface,
        'requested_locale' => $target,
        'resolved_locale' => 'es',
        'source' => $source,
        'translated' => false,
        'translated_fields' => [],
    ];

    if ($target !== 'en') {
        return $canonical('canonical');
    }
    if ($fields === []) {
        return $canonical('unsupported_surface');
    }

    $projected = $canonicalRecord;
    $translatedFields = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $canonicalRecord) || $canonicalRecord[$field] === null) {
            continue;
        }
        if (!is_string($canonicalRecord[$field])) {
            return $canonical('fallback');
        }
        $sourceText = $canonicalRecord[$field];
        if (trim($sourceText) === '') {
            continue;
        }

        $resolved = brvtalPublicTranslationResolve(
            $sourceText,
            'en',
            $adapter,
            $cacheRead,
            $cacheWrite
        );
        if (
            ($resolved['translated'] ?? false) !== true
            || ($resolved['locale'] ?? '') !== 'en'
        ) {
            return $canonical('fallback');
        }
        $safeText = brvtalPublicTranslationPlainText($resolved['text'] ?? null);
        if ($safeText === null) {
            return $canonical('fallback');
        }

        $projected[$field] = $safeText;
        $translatedFields[] = $field;
    }

    return [
        'record' => $projected,
        'surface' => $surface,
        'requested_locale' => 'en',
        'resolved_locale' => 'en',
        'source' => $translatedFields === [] ? 'canonical' : 'translation',
        'translated' => $translatedFields !== [],
        'translated_fields' => $translatedFields,
    ];
}
