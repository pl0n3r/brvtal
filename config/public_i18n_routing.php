<?php
declare(strict_types=1);

require_once __DIR__ . '/public_i18n.php';

/** Only Spanish public URLs resolve. Legacy /en URLs redirect at .htaccess. */
function brvtalPublicRouteLocale(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return 'es';
    }
    return is_string($value) && strtolower(trim($value)) === 'es' ? 'es' : null;
}

/** @return array{locale:string,path:string,spanish_path:string}|null */
function brvtalPublicLocalizedRoute(
    string $locale,
    string $type = '',
    string $slug = '',
    string $page = ''
): ?array {
    if ($locale !== 'es') {
        return null;
    }
    $type = trim($type);
    $slug = trim($slug);
    $page = trim($page);
    if ($page !== '') {
        if (!in_array($page, ['contact', 'privacy'], true) || $type !== '' || $slug !== '') {
            return null;
        }
        $path = '/' . $page;
    } elseif ($type !== '' || $slug !== '') {
        if (!in_array($type, ['events', 'artists', 'sets', 'releases', 'blog', 'pages'], true)
            || !preg_match('/^[a-z0-9-]{1,190}$/D', $slug)) {
            return null;
        }
        $path = '/' . $type . '/' . rawurlencode($slug);
    } else {
        $path = '/';
    }
    return ['locale' => 'es', 'path' => $path, 'spanish_path' => $path];
}

/** @return array{locale:string,canonical:string,paths:array{es:string},alternates:array{}}|null */
function brvtalPublicLocalizedRouteUrls(
    string $base,
    string $locale,
    string $type = '',
    string $slug = '',
    string $page = ''
): ?array {
    $route = brvtalPublicLocalizedRoute($locale, $type, $slug, $page);
    if ($route === null) {
        return null;
    }
    return [
        'locale' => 'es',
        'canonical' => rtrim($base, '/') . $route['path'],
        'paths' => ['es' => $route['path']],
        'alternates' => [],
    ];
}

function brvtalPublicApplyLocalizedSeo(array $seo, array $route): array
{
    $canonical = (string)($route['canonical'] ?? '');
    if ($canonical === '' || ($route['locale'] ?? null) !== 'es') {
        return $seo;
    }
    $seo['canonical'] = $canonical;
    unset($seo['alternates']);
    $seo['route_locale'] = 'es';
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
    if ($locale !== 'es') {
        return $html;
    }
    return preg_replace_callback(
        '/<html\b([^>]*)>/i',
        static function (array $match): string {
            $attributes = preg_replace(
                '/\s+(?:lang|data-route-locale|data-route-path-es|data-route-path-en)=(["\']).*?\1/i',
                '',
                (string)$match[1]
            ) ?? (string)$match[1];
            return '<html lang="es"' . $attributes . '>';
        },
        $html,
        1
    ) ?? $html;
}
