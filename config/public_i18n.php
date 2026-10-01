<?php
declare(strict_types=1);

/**
 * Public i18n foundation for BRVTAL.
 *
 * Spanish is the canonical editorial locale. Public settings may select the
 * effective default/available locale set only from the supported allowlist.
 * Provider-backed editorial translation and the public selector are separate
 * leaves (#825 and #826).
 */

function brvtal_public_i18n_supported_locales(): array
{
    return ['es', 'en'];
}

function brvtal_public_i18n_normalize_locale(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $locale = strtolower(trim($value));
    return in_array($locale, brvtal_public_i18n_supported_locales(), true)
        ? $locale
        : null;
}

function brvtal_public_i18n_policy(array $site): array
{
    $supported = brvtal_public_i18n_supported_locales();
    $defaultLocale = brvtal_public_i18n_normalize_locale($site['default_locale'] ?? null) ?? 'es';

    $requested = $site['available_locales'] ?? $supported;
    $available = [];

    if (is_array($requested)) {
        foreach ($requested as $candidate) {
            $locale = brvtal_public_i18n_normalize_locale($candidate);
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

function brvtal_public_i18n_catalog(): array
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

function brvtal_public_i18n_protected_terms(): array
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

function brvtal_public_i18n_payload(array $site): array
{
    return [
        'version' => 1,
        ...brvtal_public_i18n_policy($site),
        'catalog' => brvtal_public_i18n_catalog(),
        'protected_terms' => brvtal_public_i18n_protected_terms(),
    ];
}
