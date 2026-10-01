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
