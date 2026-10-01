<?php
declare(strict_types=1);

require_once __DIR__ . '/public_i18n.php';

/**
 * Public locale routing contract.
 *
 * Spanish keeps the established unprefixed public URLs. English uses /en
 * variants. /es aliases are redirected at the web-server boundary so there is
 * only one Spanish URL for every public resource.
 */

function brvtalPublicRouteLocale(mixed $value): ?string
{
    if ($value === null) {
        return 'es';
    }
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return 'es';
    }

    return brvtalPublicI18nNormalizeLocale($value);
}

/**
 * @return array{locale:string,path:string,spanish_path:string}|null
 */
function brvtalPublicLocalizedRoute(
    string $locale,
    string $type = '',
    string $slug = '',
    string $page = ''
): ?array {
    $locale = brvtalPublicI18nNormalizeLocale($locale) ?? '';
    if ($locale === '') {
        return null;
    }

    $type = trim($type);
    $slug = trim($slug);
    $page = trim($page);

    if ($page !== '') {
        if ($page !== 'contact' || $type !== '' || $slug !== '') {
            return null;
        }
        $spanishPath = '/contact';
    } elseif ($type !== '' || $slug !== '') {
        $allowedTypes = ['events', 'artists', 'sets', 'releases', 'blog', 'pages'];
        if (
            !in_array($type, $allowedTypes, true)
            || !preg_match('/^[a-z0-9-]{1,190}$/D', $slug)
        ) {
            return null;
        }
        $spanishPath = '/' . $type . '/' . rawurlencode($slug);
    } else {
        $spanishPath = '/';
    }

    $path = $locale === 'en'
        ? '/en' . ($spanishPath === '/' ? '' : $spanishPath)
        : $spanishPath;

    return [
        'locale' => $locale,
        'path' => $path,
        'spanish_path' => $spanishPath,
    ];
}

/**
 * @return array{
 *   locale:string,
 *   canonical:string,
 *   paths:array{es:string,en:string},
 *   alternates:array{es:string,en:string,x-default:string}
 * }|null
 */
function brvtalPublicLocalizedRouteUrls(
    string $base,
    string $locale,
    string $type = '',
    string $slug = '',
    string $page = ''
): ?array {
    $base = rtrim($base, '/');
    $current = brvtalPublicLocalizedRoute($locale, $type, $slug, $page);
    $spanish = brvtalPublicLocalizedRoute('es', $type, $slug, $page);
    $english = brvtalPublicLocalizedRoute('en', $type, $slug, $page);
    if ($current === null || $spanish === null || $english === null) {
        return null;
    }

    $paths = [
        'es' => $spanish['path'],
        'en' => $english['path'],
    ];
    $alternates = [
        'es' => $base . $paths['es'],
        'en' => $base . $paths['en'],
        'x-default' => $base . $paths['es'],
    ];

    return [
        'locale' => $current['locale'],
        'canonical' => $base . $current['path'],
        'paths' => $paths,
        'alternates' => $alternates,
    ];
}

function brvtalPublicApplyLocalizedSeo(array $seo, array $route): array
{
    $canonical = (string)($route['canonical'] ?? '');
    $locale = brvtalPublicI18nNormalizeLocale($route['locale'] ?? null);
    $alternates = $route['alternates'] ?? null;
    if ($canonical === '' || $locale === null || !is_array($alternates)) {
        return $seo;
    }

    $seo['canonical'] = $canonical;
    $seo['alternates'] = $alternates;
    $seo['route_locale'] = $locale;

    if (is_array($seo['schema'] ?? null)) {
        $seo['schema']['url'] = $canonical;
        if (isset($seo['schema']['mainEntityOfPage'])) {
            $seo['schema']['mainEntityOfPage'] = $canonical;
        }
    }

    return $seo;
}

function brvtalPublicApplyLocalizedDocument(
    string $html,
    string $locale,
    array $paths = [],
    bool $routeLocaleIsExplicit = false
): string {
    $locale = brvtalPublicI18nNormalizeLocale($locale);
    if ($locale === null) {
        return $html;
    }

    $pathEs = is_string($paths['es'] ?? null) ? $paths['es'] : '/';
    $pathEn = is_string($paths['en'] ?? null) ? $paths['en'] : '/en';
    foreach ([$pathEs, $pathEn] as $path) {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $html;
        }
    }

    $escape = static fn(string $value): string => htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    return preg_replace_callback(
        '/<html\b([^>]*)>/i',
        static function (array $match) use (
            $locale,
            $pathEs,
            $pathEn,
            $routeLocaleIsExplicit,
            $escape
        ): string {
            $attributes = (string)$match[1];
            $attributes = preg_replace(
                '/\s+(?:lang|data-route-locale|data-route-path-es|data-route-path-en)=(["\']).*?\1/i',
                '',
                $attributes
            ) ?? $attributes;

            $routeLocale = $routeLocaleIsExplicit
                ? ' data-route-locale="' . $escape($locale) . '"'
                : '';

            return '<html lang="' . $escape($locale) . '"'
                . $routeLocale
                . ' data-route-path-es="' . $escape($pathEs) . '"'
                . ' data-route-path-en="' . $escape($pathEn) . '"'
                . $attributes
                . '>';
        },
        $html,
        1
    ) ?? $html;
}
