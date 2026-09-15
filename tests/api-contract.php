<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/route.php';
require_once __DIR__ . '/../api/pages-contract.php';
require_once __DIR__ . '/../config/event_lifecycle.php';
require_once __DIR__ . '/../config/public_health.php';

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

expect(brvtal_page_content_json_error('') === null, 'Empty Page content JSON must remain allowed for incomplete drafts');
expect(brvtal_page_content_json_error('{"text":"Manifesto"}') === null, 'Object Page content JSON must be accepted');
expect(brvtal_page_content_json_error('[]') === null, 'Array Page content JSON must be accepted');
expect(brvtal_page_content_json_error('{"text":') === 'INVALID_PAGE_CONTENT_JSON', 'Malformed Page content JSON must be rejected');
expect(brvtal_page_content_json_error('"text"') === 'INVALID_PAGE_CONTENT_JSON', 'Scalar Page content JSON must be rejected because the public renderer consumes arrays');
expect(brvtal_page_content_json_error('123') === 'INVALID_PAGE_CONTENT_JSON', 'Numeric Page content JSON must be rejected because the public renderer consumes arrays');
expect(brvtal_page_publication_error(['status'=>'draft','locale'=>'es']) === null, 'Spanish drafts must remain editable');
expect(brvtal_page_publication_error(['status'=>'published','locale'=>'en']) === null, 'Published English Pages must remain valid');
expect(brvtal_page_publication_error(['status'=>'published','locale'=>'es']) === 'PAGE_PUBLIC_LOCALE_MUST_BE_EN', 'Published Pages must use the public English locale');

$lifecycleNow = new DateTimeImmutable('2026-09-15 12:00:00');
$lifecycleStamp = '2026-09-15 12:00:00';
$publishedPatch = brvtal_event_lifecycle_patch(['status'=>'draft'], ['status'=>'published'], $lifecycleNow);
expect(($publishedPatch['published_at'] ?? null) === $lifecycleStamp, 'first public Event transition must stamp published_at');
$legacyCancelPatch = brvtal_event_lifecycle_patch(['status'=>'published','published_at'=>null,'cancelled_at'=>null], ['status'=>'cancelled'], $lifecycleNow);
expect(($legacyCancelPatch['published_at'] ?? null) === $lifecycleStamp, 'historical transition from a legacy active Event must preserve proof of publication');
expect(($legacyCancelPatch['cancelled_at'] ?? null) === $lifecycleStamp, 'cancelled Event transition must stamp cancelled_at');
$finishedPatch = brvtal_event_lifecycle_patch(['status'=>'tickets_available','published_at'=>'2026-09-01 09:30:00','finished_at'=>null], ['status'=>'finished'], $lifecycleNow);
expect(($finishedPatch['published_at'] ?? null) === null, 'existing published_at must not be rewritten into the patch');
expect(($finishedPatch['finished_at'] ?? null) === $lifecycleStamp, 'finished Event transition must stamp finished_at');
$archiveDraftPatch = brvtal_event_lifecycle_patch(['status'=>'draft','published_at'=>null], ['status'=>'archived'], $lifecycleNow);
expect(!array_key_exists('published_at', $archiveDraftPatch), 'draft-to-archive must not invent prior public visibility');
$repairPatch = brvtal_event_lifecycle_patch(['status'=>'sold_out','published_at'=>null], ['status'=>'sold_out'], $lifecycleNow);
expect(($repairPatch['published_at'] ?? null) === $lifecycleStamp, 'saving a legacy active Event must repair missing published_at');
$serverOwnedPatch = brvtal_event_lifecycle_patch(['status'=>'draft'], ['title'=>'Safe','published_at'=>'2000-01-01 00:00:00','cancelled_at'=>'2000-01-01 00:00:00'], $lifecycleNow);
expect($serverOwnedPatch === ['title'=>'Safe'], 'lifecycle timestamps must remain server-owned when status is unchanged');

expect(brvtal_public_health_request('/api/health.php'), 'Standalone public health endpoint must be recognized');
expect(brvtal_public_health_request('/api/health'), 'Clean public health endpoint must be recognized');
expect(brvtal_public_health_request('/api/index.php/health?probe=1'), 'Compatibility public health route must be recognized');
expect(!brvtal_public_health_request('/api/dashboard'), 'Authenticated API routes must not be treated as public health');
$healthPayload = brvtal_public_health_sanitize([
    'ok'=>true,
    'status'=>'healthy',
    'database'=>'connected',
    'driver'=>'mysql',
    'server'=>'11.4.5-MariaDB',
    'php'=>'8.5.0',
    'latency_ms'=>12.3,
]);
expect(!array_key_exists('driver', $healthPayload), 'Public health sanitizer must remove database driver fingerprinting');
expect(!array_key_exists('server', $healthPayload), 'Public health sanitizer must remove exact database server version');
expect(!array_key_exists('php', $healthPayload), 'Public health sanitizer must remove exact PHP version');
expect(($healthPayload['database'] ?? null) === 'connected', 'Public health sanitizer must preserve generic database availability');
expect(($healthPayload['latency_ms'] ?? null) === 12.3, 'Public health sanitizer must preserve operational latency');

$index = file_get_contents(__DIR__ . '/../api/index.php');
$public = file_get_contents(__DIR__ . '/../api/public.php');
$health = file_get_contents(__DIR__ . '/../api/health.php');
$bootstrap = file_get_contents(__DIR__ . '/../config/bootstrap.php');
$publicApp = file_get_contents(__DIR__ . '/../js/app.js');
$router = file_get_contents(__DIR__ . '/../.htaccess');
$discadminIndex = file_get_contents(__DIR__ . '/../discadmin/index.php');
$pageContractJs = file_get_contents(__DIR__ . '/../discadmin/pages-publication-contract.js');
expect(is_string($index) && is_string($public) && is_string($health) && is_string($bootstrap) && is_string($publicApp) && is_string($router) && is_string($discadminIndex) && is_string($pageContractJs), 'API and Page contract sources must be readable');

$delegatePos = strpos($index, "if(\$resource==='public')");
$authGatePos = strpos($index, 'brvtal_admin_require();');
expect($delegatePos !== false, 'api/index.php must handle the public compatibility route');
expect(strpos($index, "require __DIR__ . '/public.php';", $delegatePos) !== false, 'Public compatibility route must delegate to api/public.php');
expect($authGatePos !== false && $delegatePos < $authGatePos, 'Public compatibility route must execute before admin authentication');

expect(str_contains($public, "WHERE setting_key IN ('site','social','appearance','theme.active')"), 'Public settings must use an explicit allowlist');
expect(!str_contains($public, "SELECT setting_key,setting_value,is_json FROM settings ORDER BY setting_key"), 'Public API must never select all settings without filtering');
expect(str_contains($publicApp, "'/api/public.php'"), 'Public frontend must include the production public PHP endpoint');
expect(strpos($publicApp, "'/api/public.php'") < strpos($publicApp, "'/api/public'"), 'Production public PHP endpoint must be attempted before compatibility aliases');
expect(str_contains($router, 'RewriteRule ^api/public/?$ api/public.php [L,QSA,NC]'), 'Clean public API aliases must delegate to api/public.php');
expect(str_contains($router, 'RewriteCond %{QUERY_STRING} (?:^|&)(?:route|action)=public(?:&|$) [NC]'), 'Query-string public API aliases must be explicitly recognized');
expect(str_contains($router, 'RewriteRule ^api/index\.php$ api/public.php [L,QSA,NC]'), 'Query-string public API aliases must delegate to api/public.php before admin routing');
expect(str_contains($public, "WHERE status IN ('active','sold_out')"), 'Public API must expose only active/sold-out ticket types');
expect(str_contains($public, 'available_from,available_until'), 'Public API ticket query must hydrate availability windows');
expect(str_contains($public, 'brvtal_public_ticket_type_is_available($ticket)'), 'Public API must apply the canonical Ticket Type availability policy');
expect(str_contains($public, "\$event['ticket_types']"), 'Public events must include ticket types');
expect(str_contains($public, "\$event['lineup']"), 'Public events must include lineup data');

expect(!str_contains($health, 'PDO::ATTR_SERVER_VERSION'), 'Standalone public health endpoint must not read exact database server version');
expect(!str_contains($health, 'PDO::ATTR_DRIVER_NAME'), 'Standalone public health endpoint must not expose database driver fingerprinting');
expect(!str_contains($health, 'PHP_VERSION'), 'Standalone public health endpoint must not expose exact PHP version');
expect(str_contains($bootstrap, 'brvtal_public_health_request'), 'JSON response boundary must recognize public health requests');
expect(str_contains($bootstrap, 'brvtal_public_health_sanitize'), 'JSON response boundary must sanitize compatibility health responses');

expect(str_contains($index, "require_once __DIR__ . '/../config/event_lifecycle.php';"), 'Core API must load the canonical Event lifecycle mutation policy');
expect(str_contains($index, "if(\$resource==='events')\$p=brvtal_event_lifecycle_patch([],\$p);"), 'Event creation must derive lifecycle timestamps server-side');
expect(str_contains($index, "if(\$resource==='events'&&\$before!==null)\$p=brvtal_event_lifecycle_patch(\$before,\$p);"), 'Event updates must derive lifecycle timestamps from the locked previous state');
expect(!str_contains($index, "'featured','published_at','cancelled_at','finished_at','archive_year'"), 'Event lifecycle timestamps must not remain directly writable payload fields');

expect(str_contains($index, "require_once __DIR__ . '/pages-contract.php';"), 'Core API must load the CMS Page publication contract');
expect(str_contains($index, "if(\$resource==='pages'&&!array_key_exists('locale',\$p))\$p['locale']='en';"), 'Core API must default new CMS Pages to English');
expect(str_contains($index, 'array_replace($before,$p)'), 'Partial Page PUT validation must combine the locked existing state with the patch');
expect(str_contains($index, 'brvtal_page_content_json_error'), 'Core API must validate Page content JSON');
expect(str_contains($index, 'brvtal_page_publication_error'), 'Core API must validate Page publication locale');
expect(str_contains($discadminIndex, '/discadmin/pages-publication-contract.js'), 'DISCADMIN shell must load the Page publication enhancement');
expect(str_contains($pageContractJs, "page.locale = 'en'"), 'New Page form enhancement must default missing locale to English');

fwrite(STDOUT, "BRVTAL API contract tests passed.\n");

require __DIR__ . '/totp-enrollment-contract.php';
require __DIR__ . '/deployment-traceability-contract.php';
require __DIR__ . '/system-status-contract.php';
