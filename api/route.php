<?php
declare(strict_types=1);

/**
 * Parse API routes consistently whether PHP receives a direct index.php path
 * (/api/index.php/events/12/lineup) or a rewritten path (/api/events/12/lineup).
 *
 * @return array{resource:string,id:?int,action:string,segments:array<int,string>}
 */
function brvtal_api_parse_route(string $requestUri, string $scriptName): array
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

    return [
        'resource' => $resource,
        'id' => $id,
        'action' => $action,
        'segments' => $segments,
    ];
}
