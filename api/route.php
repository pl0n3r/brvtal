<?php
declare(strict_types=1);

/**
 * Parse API routes consistently whether PHP receives a direct index.php path
 * (/api/index.php/events/12/lineup) or a rewritten path (/api/events/12/lineup).
 *
 * Known resources fail closed when the URL shape is not one of their explicit
 * collection, item or supported action forms. This prevents an unknown segment
 * from silently falling through to collection CRUD.
 *
 * Legacy Media mutations also fail closed here so the canonical
 * `/api/media-library.php` boundary is the only write/delete path. GET reads of
 * the generic Media resource remain compatible.
 *
 * @return array{resource:string,id:?int,action:string,segments:array<int,string>}
 */
function brvtal_api_parse_route(string $requestUri, string $scriptName, ?string $requestMethod = null): array
{
    $path = trim((string)(parse_url($requestUri, PHP_URL_PATH) ?? '/'), '/');
    $script = trim($scriptName, '/');

    if ($script !== '' && str_starts_with($path, $script)) {
        $path = trim(substr($path, strlen($script)), '/');
    } else {
        $scriptDir = trim(str_replace('\\', '/', dirname('/' . $script)), '/.');
        if ($scriptDir !== '' && ($path === $scriptDir || str_starts_with($path, $scriptDir . '/'))) {
            $path = trim(substr($path, strlen($scriptDir)), '/');
        }
    }

    $segments = $path === '' ? [] : array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
    $resource = $segments[0] ?? '';
    $id = isset($segments[1]) && ctype_digit($segments[1]) ? (int)$segments[1] : null;
    $action = $segments[2] ?? ($segments[1] ?? '');

    $singleSegmentResources = ['health', 'auth', 'public', 'dashboard', 'upload', 'settings'];
    $itemResources = ['events', 'artists', 'sets', 'media', 'pages', 'ticket_types'];
    $validShape = true;

    if (in_array($resource, $singleSegmentResources, true)) {
        $validShape = count($segments) === 1;
    } elseif (in_array($resource, $itemResources, true)) {
        $validShape = count($segments) === 1
            || (count($segments) === 2 && isset($segments[1]) && ctype_digit($segments[1]))
            || ($resource === 'events'
                && count($segments) === 3
                && isset($segments[1], $segments[2])
                && ctype_digit($segments[1])
                && $segments[2] === 'lineup');
    }

    $method = strtoupper(trim((string)($requestMethod ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'))));
    $legacyMediaMutation = $resource === 'upload'
        || ($resource === 'media' && $method !== 'GET');

    if (!$validShape || $legacyMediaMutation) {
        $resource = '';
        $id = null;
        $action = '';
    }

    return [
        'resource' => $resource,
        'id' => $id,
        'action' => $action,
        'segments' => $segments,
    ];
}
