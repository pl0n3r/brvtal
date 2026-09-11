<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/route.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cases = [
    ['/api/index.php/events/12/lineup?x=1', '/api/index.php', 'events', 12, 'lineup'],
    ['/api/events/12/lineup', '/api/index.php', 'events', 12, 'lineup'],
    ['/events/12/lineup', '/api/index.php', 'events', 12, 'lineup'],
    ['/api/auth', '/api/index.php', 'auth', null, ''],
    ['/api/events/42', '/api/index.php', 'events', 42, '42'],
    ['/api/public', '/api/index.php', 'public', null, ''],
];

foreach ($cases as [$uri, $script, $resource, $id, $action]) {
    $route = brvtal_api_parse_route($uri, $script);
    expect($route['resource'] === $resource, "Resource mismatch for {$uri}");
    expect($route['id'] === $id, "ID mismatch for {$uri}");
    expect($route['action'] === $action, "Action mismatch for {$uri}");
}

$index = file_get_contents(__DIR__ . '/../api/index.php');
$public = file_get_contents(__DIR__ . '/../api/public.php');
expect(is_string($index) && is_string($public), 'API sources must be readable');

$delegatePos = strpos($index, "if(\$resource==='public')");
$authGatePos = strpos($index, 'brvtal_admin_require();');
expect($delegatePos !== false, 'api/index.php must handle the public compatibility route');
expect(strpos($index, "require __DIR__ . '/public.php';", $delegatePos) !== false, 'Public compatibility route must delegate to api/public.php');
expect($authGatePos !== false && $delegatePos < $authGatePos, 'Public compatibility route must execute before admin authentication');

expect(str_contains($public, "WHERE setting_key IN ('site','social','appearance','theme.active')"), 'Public settings must use an explicit allowlist');
expect(!str_contains($public, "SELECT setting_key,setting_value,is_json FROM settings ORDER BY setting_key"), 'Public API must never select all settings without filtering');
expect(str_contains($public, "WHERE status IN ('active','sold_out')"), 'Public API must expose only active/sold-out ticket types');
expect(str_contains($public, "\$event['ticket_types']"), 'Public events must include ticket types');
expect(str_contains($public, "\$event['lineup']"), 'Public events must include lineup data');

fwrite(STDOUT, "BRVTAL API contract tests passed.\n");
