<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_visibility.php';

function public_pages_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC ENTITY PAGES CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$entry = (string)file_get_contents(__DIR__ . '/../index.php');
$pages = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
$css = (string)file_get_contents(__DIR__ . '/../css/public-entity.css');

public_pages_expect(str_contains($entry, 'brvtal_public_entity_page'), 'entity routes must use the dedicated public renderer');
foreach (['events','artists','releases','blog'] as $type) {
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


echo "BRVTAL public entity pages contract tests passed.\n";
