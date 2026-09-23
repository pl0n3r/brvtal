<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_visibility.php';
require_once __DIR__ . '/../config/page_content.php';
require_once __DIR__ . '/../api/pages-contract.php';

function public_pages_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC ENTITY PAGES CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$entry = (string)file_get_contents(__DIR__ . '/../index.php');
$delivery = (string)file_get_contents(__DIR__ . '/../config/public_entity_delivery.php');
$pages = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
$seo = (string)file_get_contents(__DIR__ . '/../config/public_seo.php');
$pageContractUi = (string)file_get_contents(__DIR__ . '/../discadmin/pages-publication-contract.js');
$unavailable = (string)file_get_contents(__DIR__ . '/../config/public_unavailable.php');
$css = (string)file_get_contents(__DIR__ . '/../css/public-entity.css');

public_pages_expect(str_contains($entry, 'brvtalPublicEntityDocument('), 'entity routes must use the canonical delivery pipeline');
public_pages_expect(str_contains($delivery, 'brvtal_public_entity_page('), 'canonical delivery must use the dedicated public renderer');
foreach (['events','artists','releases','blog','pages'] as $type) {
    public_pages_expect(str_contains($pages, "\$type === '{$type}'"), "renderer must hydrate {$type}");
}
foreach (['event_artists','event_ticket_types','release_artists','blog_post_relations'] as $relation) {
    public_pages_expect(str_contains($pages, $relation), "public pages must use {$relation} relationships");
}
public_pages_expect(substr_count($pages, "status='published'") >= 5, 'related content must remain limited to published records');
public_pages_expect(!str_contains($pages, "status<>'draft'"), 'set pages must not reveal events outside the canonical public status list');
public_pages_expect(str_contains($pages, 'brvtal_public_visible_event_statuses()'), 'event relationships must use the canonical public event status policy');
public_pages_expect(str_contains($pages, 'brvtal_public_sql_placeholders($eventStatuses)'), 'event relationship queries must use prepared placeholders for the canonical status policy');
public_pages_expect(!str_contains($pages, "status IN ('published','upcoming','tickets_available','last_tickets','sold_out','cancelled','finished','archived')"), 'public entity pages must not duplicate the Event visibility allowlist');
public_pages_expect(str_contains($pages, 'brvtal_public_event_allows_ticketing($detail)'), 'Event pages must derive ticket CTA visibility from the canonical commercial lifecycle policy');
public_pages_expect(str_contains($pages, "if (\$allowsTicketing)"), 'Event ticket types must only be queried for commercially active Events');
public_pages_expect(str_contains($pages, 'available_from,available_until'), 'Event page Ticket Type query must hydrate availability windows');
public_pages_expect(str_contains($pages, 'brvtal_public_ticket_type_is_available($ticket)'), 'Event pages must apply the canonical Ticket Type availability policy');
public_pages_expect(str_contains($pages, "\$detail['ticket_url'] = null"), 'historical Event page data must clear stale ticket URLs');
public_pages_expect(str_contains($pages, "\$detail['ticket_instructions'] = null"), 'historical Event page data must clear stale ticket instructions');
public_pages_expect(!str_contains($pages, "(\$href ?: '#')"), 'cards without a destination must not be dead links');
public_pages_expect(str_contains($pages, "['events', 'artists', 'sets']"), 'only Home sections that really exist may be used as entity back-link anchors');
public_pages_expect(str_contains($pages, "'← BACK HOME'"), 'entities without a matching Home section must return to Home');
public_pages_expect(!str_contains($pages, 'href="/#{$routeType}"'), 'renderer must not generate route-type anchors blindly');
public_pages_expect(str_contains($pages, 'htmlspecialchars'), 'editorial content must be escaped before HTML rendering');
public_pages_expect(str_contains($pages, "rel=\"noopener noreferrer\""), 'external calls to action must isolate their browsing context');
public_pages_expect(str_contains($css, '@media(max-width:620px)'), 'entity pages must include a mobile layout');
public_pages_expect(str_contains($css, 'prefers-reduced-motion:reduce'), 'entity pages must respect reduced motion');

$pageBlocks = '{"blocks":[{"type":"heading","content":"About BRVTAL"},{"type":"paragraph","content":"Rave till Grave"},{"type":"text","text":"Pereira / Colombia"}]}';
public_pages_expect(brvtal_page_content_structure_error($pageBlocks) === null, 'supported Page blocks must satisfy the canonical structure contract');
public_pages_expect(brvtal_page_content_plain_text($pageBlocks) === "About BRVTAL\n\nRave till Grave\n\nPereira / Colombia", 'supported Page blocks must render to deterministic public text');
public_pages_expect(brvtal_page_content_plain_text('{"text":"Manifesto"}') === 'Manifesto', 'legacy top-level Page text must remain supported');
public_pages_expect(brvtal_page_content_plain_text('{"blocks":[{"type":"embed","content":"<iframe>unsafe</iframe>"}]}') === '', 'unsupported Page blocks must never leak raw structure or markup into public text');
public_pages_expect(brvtal_page_content_structure_error('{"blocks":[{"type":"embed","content":"x"}]}') === 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED', 'unsupported Page blocks must be rejected for publication');
public_pages_expect(brvtal_page_publication_error(['status'=>'draft','locale'=>'en','content_json'=>'{"blocks":[{"type":"embed","content":"x"}]}']) === null, 'draft Pages may retain incomplete/unsupported future editor data');
public_pages_expect(brvtal_page_publication_error(['status'=>'published','locale'=>'en','content_json'=>$pageBlocks]) === null, 'published English Pages must accept the supported block contract');
public_pages_expect(brvtal_page_publication_error(['status'=>'published','locale'=>'en','content_json'=>'{"blocks":[{"type":"embed","content":"x"}]}']) === 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED', 'published Pages must reject structures the public renderer cannot display');
public_pages_expect(str_contains($seo, "require_once __DIR__ . '/page_content.php';"), 'public SEO resolution must load the canonical Page content contract');
public_pages_expect(str_contains($seo, "if (\$type === 'pages')") && str_contains($seo, 'brvtal_page_content_plain_text'), 'Page SEO/entity descriptions must normalize content_json before public delivery');
public_pages_expect(str_contains($pageContractUi, 'text · paragraph · heading blocks'), 'DISCADMIN must document the supported Page block types instead of promising an undefined builder');

public_pages_expect(str_contains($pages, 'bool $required = false'), 'public entity query helper must distinguish required from optional reads');
public_pages_expect(str_contains($pages, 'catch (Throwable $e)'), 'public entity query helper must retain query exceptions');
public_pages_expect(str_contains($pages, 'PUBLIC_ENTITY_QUERY_ERROR'), 'public entity query failures must be observable in server logs');
public_pages_expect(str_contains($pages, 'brvtal_page_query_degraded(true)'), 'optional query failures must mark the page degraded');
public_pages_expect(str_contains($pages, 'if ($required)') && str_contains($pages, 'throw $e;'), 'required query failures must propagate instead of becoming empty rows');
public_pages_expect(substr_count($pages, '[$id], true)') >= 6, 'each canonical entity type must treat its essential detail read as required');
public_pages_expect(str_contains($pages, 'brvtal_page_query_degraded(false)'), 'each entity hydration must reset degradation state');
public_pages_expect(str_contains($pages, '$data[\'degraded\'] = brvtal_page_query_degraded();'), 'entity hydration must surface optional query degradation to delivery');
public_pages_expect(str_contains($pages, 'DATA STATUS / DEGRADED'), 'partially degraded entity pages must visibly disclose incomplete connected data');
public_pages_expect(str_contains($pages, '<meta name="robots" content="noindex, follow">'), 'partially degraded entity pages must not be indexed');
public_pages_expect(str_contains($entry, "require_once __DIR__ . '/config/public_unavailable.php';"), 'entity delivery must load the dedicated unavailable renderer');
public_pages_expect(str_contains($entry, 'PUBLIC_ENTITY_DATA_ERROR'), 'essential entity hydration failures must be logged at the delivery boundary');
public_pages_expect(str_contains($entry, 'http_response_code(503)'), 'essential entity hydration failures must return HTTP 503');
public_pages_expect(str_contains($entry, "header('Retry-After: 60')"), '503 entity responses must tell clients when to retry');
public_pages_expect(str_contains($entry, "header('X-Robots-Tag: noindex, follow')"), 'entity degradation must set a noindex response header');
public_pages_expect(str_contains($entry, "header('X-BRVTAL-Data-State: degraded')"), 'partial entity degradation must be observable in response headers');
public_pages_expect(substr_count($entry, "header('Cache-Control: no-store')") >= 2, 'essential and partial entity failures must not be cached');
public_pages_expect(str_contains($unavailable, 'DATA TEMPORARILY UNAVAILABLE'), 'essential failures must render an explicit unavailable experience');
public_pages_expect(str_contains($unavailable, '503 / RETRY LATER'), 'unavailable renderer must communicate retry semantics');
public_pages_expect(str_contains($unavailable, '<meta name="robots" content="noindex, follow">'), 'unavailable renderer must remain noindex');

$ticketNow = new DateTimeImmutable('2026-09-15 12:00:00');
public_pages_expect(brvtal_public_event_allows_ticketing([
    'status' => 'tickets_available',
    'event_date' => '2026-09-20 21:00:00',
], $ticketNow), 'future active Event must retain ticket actions');
public_pages_expect(brvtal_public_event_allows_ticketing([
    'status' => 'sold_out',
    'event_date' => '2026-09-20 21:00:00',
], $ticketNow), 'future sold-out Event remains in the active commercial lifecycle');
public_pages_expect(!brvtal_public_event_allows_ticketing([
    'status' => 'cancelled',
    'event_date' => '2026-09-20 21:00:00',
    'published_at' => '2026-08-01 12:00:00',
], $ticketNow), 'cancelled Event must not expose ticket actions');
public_pages_expect(!brvtal_public_event_allows_ticketing([
    'status' => 'published',
    'event_date' => '2026-09-10 21:00:00',
], $ticketNow), 'past Event must not expose ticket actions even before its status is updated');
public_pages_expect(!brvtal_public_event_allows_ticketing([
    'status' => 'archived',
    'event_date' => '2026-08-01 21:00:00',
], $ticketNow), 'archived Event must not expose ticket actions');

public_pages_expect(brvtal_public_ticket_type_is_available([
    'status' => 'active',
    'available_from' => null,
    'available_until' => null,
], $ticketNow), 'active Ticket Type without bounds must remain public');
public_pages_expect(brvtal_public_ticket_type_is_available([
    'status' => 'sold_out',
    'available_from' => '2026-09-15 10:00:00',
    'available_until' => '2026-09-15 14:00:00',
], $ticketNow), 'sold-out Ticket Type inside its window must remain visible');
public_pages_expect(brvtal_public_ticket_type_is_available([
    'status' => 'active',
    'available_from' => '2026-09-15 12:00:00',
    'available_until' => '2026-09-15 12:00:00',
], $ticketNow), 'Ticket Type availability boundaries must be inclusive');
public_pages_expect(!brvtal_public_ticket_type_is_available([
    'status' => 'active',
    'available_from' => '2026-09-15 12:00:01',
], $ticketNow), 'Ticket Type must stay private before available_from');
public_pages_expect(!brvtal_public_ticket_type_is_available([
    'status' => 'active',
    'available_until' => '2026-09-15 11:59:59',
], $ticketNow), 'Ticket Type must disappear after available_until');
public_pages_expect(!brvtal_public_ticket_type_is_available([
    'status' => 'draft',
    'available_from' => '2026-09-15 10:00:00',
    'available_until' => '2026-09-15 14:00:00',
], $ticketNow), 'draft Ticket Type must remain private even inside its window');
public_pages_expect(!brvtal_public_ticket_type_is_available([
    'status' => 'active',
    'available_from' => 'not-a-date',
], $ticketNow), 'malformed non-empty Ticket Type windows must fail closed');

echo "BRVTAL public entity pages contract tests passed.\n";