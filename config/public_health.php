<?php
declare(strict_types=1);

function brvtal_public_health_request(string $requestUri): bool
{
    $path = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($path)) return false;

    return preg_match('#^/api/(?:index\.php/)?health(?:\.php)?/?$#i', $path) === 1;
}

function brvtal_public_health_sanitize(array $payload): array
{
    unset($payload['driver'], $payload['server'], $payload['php']);
    return $payload;
}
