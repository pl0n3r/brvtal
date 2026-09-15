<?php
declare(strict_types=1);

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
public_pages_expect(!str_contains($pages, "(\$href ?: '#')"), 'cards without a destination must not be dead links');
public_pages_expect(str_contains($pages, "['events', 'artists', 'sets']"), 'only Home sections that really exist may be used as entity back-link anchors');
public_pages_expect(str_contains($pages, "'← BACK HOME'"), 'entities without a matching Home section must return to Home');
public_pages_expect(!str_contains($pages, 'href="/#{$routeType}"'), 'renderer must not generate route-type anchors blindly');
public_pages_expect(str_contains($pages, 'htmlspecialchars'), 'editorial content must be escaped before HTML rendering');
public_pages_expect(str_contains($pages, "rel=\"noopener noreferrer\""), 'external calls to action must isolate their browsing context');
public_pages_expect(str_contains($css, '@media(max-width:620px)'), 'entity pages must include a mobile layout');
public_pages_expect(str_contains($css, 'prefers-reduced-motion:reduce'), 'entity pages must respect reduced motion');

echo "BRVTAL public entity pages contract tests passed.\n";
