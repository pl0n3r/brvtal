<?php
declare(strict_types=1);

/**
 * Public i18n foundation for BRVTAL.
 *
 * Spanish is the canonical editorial locale. Public settings may select the
 * effective available locale set only from the supported allowlist; the
 * approved default remains Spanish regardless of stale/raw configuration.
 * Provider-backed editorial translation and the public selector are separate
 * leaves (#825 and #826).
 */

function brvtalPublicI18nSupportedLocales(): array
{
    return ['es', 'en'];
}

function brvtalPublicI18nNormalizeLocale(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $locale = strtolower(trim($value));
    return in_array($locale, brvtalPublicI18nSupportedLocales(), true)
        ? $locale
        : null;
}

function brvtalPublicI18nPolicy(array $site): array
{
    $supported = brvtalPublicI18nSupportedLocales();
    $defaultLocale = 'es';

    $requested = $site['available_locales'] ?? $supported;
    $available = [];

    if (is_array($requested)) {
        foreach ($requested as $candidate) {
            $locale = brvtalPublicI18nNormalizeLocale($candidate);
            if ($locale !== null && !in_array($locale, $available, true)) {
                $available[] = $locale;
            }
        }
    }

    if ($available === []) {
        $available = $supported;
    }

    if (!in_array($defaultLocale, $available, true)) {
        array_unshift($available, $defaultLocale);
    }

    return [
        'canonical_locale' => 'es',
        'default_locale' => $defaultLocale,
        'available_locales' => $available,
    ];
}

function brvtalPublicI18nCatalog(): array
{
    return [
        'es' => [
            'nav.events' => 'EVENTOS',
            'nav.artists' => 'ARTISTAS',
            'nav.sets' => 'SETS / SONIDO',
            'nav.media' => 'MEDIA',
            'nav.contact' => 'CONTACTO',
            'search.history' => 'BUSCAR HISTORIA',
            'status.empty' => 'SIN RESULTADOS',
        ],
        'en' => [
            'nav.events' => 'EVENTS',
            'nav.artists' => 'ARTISTS',
            'nav.sets' => 'SETS / SOUND',
            'nav.media' => 'MEDIA',
            'nav.contact' => 'CONTACT',
            'search.history' => 'SEARCH HISTORY',
            'status.empty' => 'NO RESULTS',
        ],
    ];
}

function brvtalPublicI18nProtectedTerms(): array
{
    return [
        'exact' => [
            'BRVTAL',
            'RAVE TILL GRAVE',
            'GENESIS',
            'RANDOM KORE',
        ],
        'music_taxonomy' => [
            'HARD TECHNO',
            'RAW',
            'INDUSTRIAL',
            'FRENCHCORE',
            'HARDCORE',
            'TERRORCORE',
            'UPTEMPO',
            'SCHRANZ',
            'PSYTRANCE',
        ],
        'entity_fields' => [
            'artists[].name',
            'events[].name',
            'sets[].title',
            'releases[].title',
        ],
    ];
}

function brvtalPublicI18nPayload(array $site): array
{
    return [
        'version' => 1,
        ...brvtalPublicI18nPolicy($site),
        'catalog' => brvtalPublicI18nCatalog(),
        'protected_terms' => brvtalPublicI18nProtectedTerms(),
    ];
}
