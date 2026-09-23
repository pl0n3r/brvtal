<?php
declare(strict_types=1);

require_once __DIR__ . '/artist_collective_membership.php';

/** Public framing reads the same canonical current-membership field as DISCADMIN. */
function brvtal_public_artist_roster_status(array $artist): string
{
    return brvtal_artist_collective_membership_value($artist) ? 'member' : 'network';
}

/** Public facts describe current membership only; historical periods remain internal audit data. */
function brvtal_public_artist_membership_facts(array $artist): array
{
    return brvtal_artist_collective_membership_value($artist)
        ? ['BRVTAL' => 'COLLECTIVE MEMBER']
        : [];
}

/** Split related Events using the canonical public Event lifecycle policy. */
function brvtal_public_artist_partition_events(array $events): array
{
    $groups = ['upcoming' => [], 'historical' => []];
    foreach ($events as $event) {
        $groups[brvtal_public_event_is_historical($event) ? 'historical' : 'upcoming'][] = $event;
    }

    $eventTime = static function (array $event): int {
        $value = trim((string)($event['event_date'] ?? ''));
        $timestamp = $value === '' ? false : strtotime($value);
        return $timestamp === false ? PHP_INT_MAX : $timestamp;
    };
    usort($groups['upcoming'], static fn(array $a, array $b): int => $eventTime($a) <=> $eventTime($b));
    usort($groups['historical'], static fn(array $a, array $b): int => $eventTime($b) <=> $eventTime($a));
    return $groups;
}

/** Enrich the canonical Artist page with current membership and explicit connected content. */
function brvtal_public_artist_enhance_page(PDO $pdo, array $page): array
{
    if (($page['entity']['route_type'] ?? '') !== 'artists') return $page;

    $id = (int)($page['entity']['id'] ?? 0);
    if ($id < 1) return $page;

    $membership = brvtal_artist_collective_membership_sql($pdo);
    $detail = brvtal_page_row(
        $pdo,
        "SELECT {$membership} AS is_collective_member FROM artists WHERE id=? LIMIT 1",
        [$id],
        true
    );
    $page['entity'] = array_merge($page['entity'], $detail);
    $page['facts'] = brvtal_public_artist_membership_facts($page['entity']);

    $eventGroups = brvtal_public_artist_partition_events($page['related']['EVENTS'] ?? []);
    $sets = $page['related']['SETS'] ?? [];
    $releases = $page['related']['RELEASES'] ?? [];
    $transmissions = brvtal_page_rows(
        $pdo,
        "SELECT bp.title,bp.slug,bp.cover_image AS image,DATE_FORMAT(COALESCE(bp.published_at,bp.updated_at),'%d.%m.%Y') AS meta,'blog' AS route_type
         FROM blog_post_relations rel
         JOIN blog_posts bp ON bp.id=rel.post_id AND bp.status='published'
         WHERE rel.related_type='artist' AND rel.related_id=?
         ORDER BY rel.sort_order,COALESCE(bp.published_at,bp.updated_at) DESC,bp.id DESC",
        [$id]
    );

    $page['related'] = array_filter([
        'UPCOMING EVENTS' => $eventGroups['upcoming'],
        'PAST NIGHTS' => $eventGroups['historical'],
        'SETS' => $sets,
        'RELEASES' => $releases,
        'TRANSMISSIONS' => $transmissions,
    ]);
    $page['degraded'] = brvtal_page_query_degraded();
    return $page;
}

/** Apply Artist-specific framing without creating a second page renderer. */
function brvtal_public_artist_decorate_html(string $html, array $page): string
{
    if (($page['entity']['route_type'] ?? '') !== 'artists') return $html;

    $status = brvtal_public_artist_roster_status($page['entity']);
    $stylesheet = '<link rel="stylesheet" href="/css/public-entity.css">';
    $html = str_replace(
        $stylesheet,
        $stylesheet . "\n  <link rel=\"stylesheet\" href=\"/css/public-roster.css\">",
        $html
    );
    $html = str_replace(
        '<body class="entity-page">',
        '<body class="entity-page entity-page--artist entity-page--artist-' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" data-artist-roster-status="' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">',
        $html
    );
    $html = str_replace('← BACK TO ARCHIVE', '← BACK TO ROSTER', $html);
    $html = str_replace('ABOUT / INFORMATION', 'ARTIST / IDENTITY', $html);
    return $html;
}
