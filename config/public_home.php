<?php
declare(strict_types=1);

require_once __DIR__ . '/public_visibility.php';

function brvtal_public_select_next_experience(array $events, ?DateTimeImmutable $now = null): ?array
{
    $now ??= new DateTimeImmutable('now');
    $today = $now->setTime(0, 0, 0);
    $activeStatuses = array_flip(brvtal_public_event_statuses()['active']);
    $eligible = [];

    foreach ($events as $event) {
        if (!is_array($event)) continue;
        $status = strtolower(trim((string)($event['status'] ?? '')));
        if (!isset($activeStatuses[$status])) continue;

        $eventDate = null;
        $rawDate = trim((string)($event['event_date'] ?? ''));
        if ($rawDate !== '') {
            try { $eventDate = new DateTimeImmutable($rawDate); }
            catch (Throwable) { $eventDate = null; }
        }
        if ($eventDate !== null && $eventDate < $today) continue;

        $event['_brvtal_next_date'] = $eventDate?->format('Y-m-d H:i:s');
        $eligible[] = $event;
    }

    if ($eligible === []) return null;

    usort($eligible, static function (array $a, array $b): int {
        $featured = (int)($b['featured'] ?? 0) <=> (int)($a['featured'] ?? 0);
        if ($featured !== 0) return $featured;

        $aDate = (string)($a['_brvtal_next_date'] ?? '9999-12-31 23:59:59');
        $bDate = (string)($b['_brvtal_next_date'] ?? '9999-12-31 23:59:59');
        return [$aDate, (int)($a['sort_order'] ?? 0), (int)($a['id'] ?? 0)]
            <=> [$bDate, (int)($b['sort_order'] ?? 0), (int)($b['id'] ?? 0)];
    });

    $selected = $eligible[0];
    unset($selected['_brvtal_next_date']);
    return $selected;
}

function brvtal_public_home_http_url(mixed $value): string
{
    $url = trim((string)$value);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) return '';
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}

function brvtal_public_next_experience_ticket_url(PDO $pdo, array $event, ?DateTimeImmutable $now = null): string
{
    if (!brvtal_public_event_allows_ticketing($event, $now)) return '';

    $direct = brvtal_public_home_http_url($event['ticket_url'] ?? '');
    if ($direct !== '') return $direct;

    try {
        $statement = $pdo->prepare(
            "SELECT external_url,status,available_from,available_until,sort_order,id
             FROM event_ticket_types
             WHERE event_id=? AND status='active'
             ORDER BY sort_order ASC,id ASC"
        );
        $statement->execute([(int)($event['id'] ?? 0)]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $ticket) {
            if (!brvtal_public_ticket_type_is_available($ticket, $now)) continue;
            $url = brvtal_public_home_http_url($ticket['external_url'] ?? '');
            if ($url !== '') return $url;
        }
    } catch (Throwable) {
        // Ticket types are an enhancement. The Event remains renderable without them.
    }

    return '';
}

function brvtal_public_next_experience_lineup(PDO $pdo, int $eventId): array
{
    if ($eventId < 1) return [];

    try {
        $statement = $pdo->prepare(
            "SELECT a.name,a.slug,ea.role,ea.lineup_order
             FROM event_artists ea
             JOIN artists a ON a.id=ea.artist_id
             WHERE ea.event_id=? AND a.status='published'
             ORDER BY ea.lineup_order ASC,a.name ASC"
        );
        $statement->execute([$eventId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function brvtal_public_next_experience(PDO $pdo, ?DateTimeImmutable $now = null): ?array
{
    $statuses = brvtal_public_event_statuses()['active'];
    $placeholders = brvtal_public_sql_placeholders($statuses);
    $statement = $pdo->prepare(
        "SELECT id,title,slug,event_date,venue,city,cover_image,ticket_url,featured,status,sort_order
         FROM events
         WHERE status IN ({$placeholders})"
    );
    $statement->execute($statuses);
    $selected = brvtal_public_select_next_experience($statement->fetchAll(PDO::FETCH_ASSOC), $now);
    if ($selected === null) return null;

    $selected['lineup'] = brvtal_public_next_experience_lineup($pdo, (int)($selected['id'] ?? 0));
    $selected['public_ticket_url'] = brvtal_public_next_experience_ticket_url($pdo, $selected, $now);
    return $selected;
}

function brvtal_public_home_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function brvtal_public_home_upper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function brvtal_public_next_experience_date_parts(?array $event): array
{
    $raw = trim((string)($event['event_date'] ?? ''));
    if ($raw === '') return ['DATE TBA', 'TIME TBA'];

    try {
        $date = new DateTimeImmutable($raw);
        return [$date->format('d.m.Y'), $date->format('H:i')];
    } catch (Throwable) {
        return ['DATE TBA', 'TIME TBA'];
    }
}

function brvtal_public_next_experience_tag(?array $event): string
{
    if ($event === null) return 'NEW DATE TO BE ANNOUNCED.';

    $status = strtolower(trim((string)($event['status'] ?? '')));
    return match ($status) {
        'sold_out' => 'SOLD OUT.',
        'last_tickets' => 'LAST TICKETS.',
        'tickets_available' => 'TICKETS AVAILABLE.',
        'upcoming' => 'UPCOMING.',
        default => 'NEXT SIGNAL.',
    };
}

function brvtal_public_home_concept05_foundation(string $html): string
{
    if (!str_contains($html, 'css/public-concept05-tokens.css')) {
        $html = str_replace(
            '</head>',
            "  <link rel=\"stylesheet\" href=\"css/public-concept05-tokens.css\">\n</head>",
            $html
        );
    }
    if (!str_contains($html, 'js/public-concept05-motion.js')) {
        $html = str_replace(
            '</body>',
            "  <script src=\"js/public-concept05-motion.js\" defer></script>\n</body>",
            $html
        );
    }
    if (preg_match('~<body([^>]*)>~', $html, $m) === 1 && !str_contains($m[1], 'data-concept=')) {
        $html = preg_replace('~<body([^>]*)>~', '<body$1 data-concept="05">', $html, 1) ?? $html;
    }

    return $html;
}

/**
 * Concept 05 Home dressing (Issue #584, parent #583): applies the canonical visual
 * language to the existing, real Home sections/data hooks. Adds the
 * dressing stylesheet, an inline desktop header nav, numbered
 * editorial labels on the real sections and a persistent mobile
 * bottom nav — all reusing existing anchors/routes, no fake content.
 */
function brvtal_public_home_concept05_dressing(string $html): string
{
    $html = brvtal_public_home_concept05_foundation($html);

    if (!str_contains($html, 'css/public-concept05-home.css')) {
        $html = str_replace(
            '</head>',
            "  <link rel=\"stylesheet\" href=\"css/public-concept05-home.css\">\n</head>",
            $html
        );
    }

    $brandLink = '<a class="brand magnetic" href="#top" data-cursor="HOME"><span data-site-name>BRVTAL</span><small data-site-tagline>RAVE TILL GRAVE</small></a>';
    if (str_contains($html, $brandLink) && !str_contains($html, 'c5-header-nav')) {
        $headerNav = '<nav class="c5-header-nav" aria-label="Primary"><a href="#events">NIGHTS</a><a href="#artists">ARTISTS</a><a href="#sets">SOUND</a><a href="/releases">RECORDS</a><a href="#transmissions">JOURNAL</a><a href="#media">CONNECTED</a></nav>';
        $html = str_replace($brandLink, $brandLink . $headerNav, $html);
    }

    $sectionLabels = [
        'class="genesis scene' => '01',
        'class="events scene' => '02',
        'class="artists scene' => '03',
        'class="sets scene' => '04',
        'class="media scene' => '05',
        'class="scene transmissions"' => '06',
    ];
    foreach ($sectionLabels as $needle => $index) {
        if (str_contains($html, $needle) && !str_contains($html, $needle . ' c5-numbered')) {
            $html = str_replace($needle, $needle . ' c5-numbered', $html);
        }
    }
    // home-phase-a-experience is appended by brvtal_public_render_next_experience()
    // after this runs on the Next Experience path, so also cover that variant.
    if (str_contains($html, 'class="genesis scene home-phase-a-experience') && !str_contains($html, 'class="genesis scene home-phase-a-experience c5-numbered')) {
        $html = str_replace(
            'class="genesis scene home-phase-a-experience',
            'class="genesis scene home-phase-a-experience c5-numbered',
            $html
        );
    }

    if (!str_contains($html, 'c5-bottom-nav')) {
        $bottomNav = '<nav class="c5-bottom-nav" aria-label="Primary mobile">'
            . '<a href="#events">NIGHTS</a>'
            . '<a href="#artists">ARTISTS</a>'
            . '<a href="#sets">SOUND</a>'
            . '<a href="/releases">RECORDS</a>'
            . '<a href="#transmissions">JOURNAL</a>'
            . '</nav>';
        $html = str_replace('</body>', '  ' . $bottomNav . "\n</body>", $html);
    }

    return $html;
}

function brvtal_public_home_identity(string $html): string
{
    $html = brvtal_public_home_concept05_dressing($html);

    if (!str_contains($html, 'css/public-home-phase-a.css')) {
        $html = str_replace(
            '</head>',
            "  <link rel=\"stylesheet\" href=\"css/public-home-phase-a.css\">\n</head>",
            $html
        );
    }

    $html = str_replace(
        '<section class="hero scene" data-scene="CORE" data-index="01">',
        '<section class="hero scene home-phase-a-hero" data-scene="CORE" data-index="01">',
        $html
    );
    $html = str_replace(
        '<div class="eyebrow mono">BRVTAL / PEREIRA / COLOMBIA / 2026</div>',
        '<div class="eyebrow mono">UNDERGROUND ELECTRONIC CULTURE / PEREIRA / COLOMBIA</div>',
        $html
    );

    $heroSub = '<div class="hero-sub"><span data-site-tagline>RAVE TILL GRAVE</span><span>EST. 2026</span></div>';
    if (str_contains($html, $heroSub) && !str_contains($html, 'class="hero-declaration"')) {
        $html = str_replace(
            $heroSub,
            $heroSub . '<div class="hero-declaration"><span class="mono">BRVTAL / CULTURAL SIGNAL</span><strong>EVENTS / SOUND / ARTISTS / ARCHIVE</strong><p>BUILT IN PEREIRA. CONNECTED THROUGH UNDERGROUND ELECTRONIC CULTURE.</p></div>',
            $html
        );
    }

    return $html;
}

function brvtal_public_next_experience_lineup_markup(?array $event): string
{
    $lineup = is_array($event['lineup'] ?? null) ? $event['lineup'] : [];
    $names = [];
    foreach ($lineup as $artist) {
        $name = trim((string)($artist['name'] ?? ''));
        if ($name !== '') $names[] = brvtal_public_home_escape($name);
        if (count($names) >= 8) break;
    }
    if ($names === []) return '';

    return '<div class="experience-lineup"><span class="mono">LINEUP</span><p>' . implode(' <i>/</i> ', $names) . '</p></div>';
}

function brvtal_public_render_next_experience(string $html, ?array $event): string
{
    $html = brvtal_public_home_identity($html);

    $sectionStart = strpos($html, '<section class="genesis scene');
    if ($sectionStart === false) return $html;
    $sectionEnd = strpos($html, '</section>', $sectionStart);
    if ($sectionEnd === false) return $html;
    $sectionEnd += strlen('</section>');

    $title = trim((string)($event['title'] ?? ''));
    $slug = trim((string)($event['slug'] ?? ''));
    $city = trim((string)($event['city'] ?? ''));
    $venue = trim((string)($event['venue'] ?? ''));
    $cover = trim((string)($event['cover_image'] ?? ''));

    if ($title === '') {
        $title = 'NEXT SIGNAL';
        $slug = '';
        $city = '';
        $venue = '';
        $cover = '';
        $event = null;
    }

    [$dateLabel, $timeLabel] = brvtal_public_next_experience_date_parts($event);
    $locationParts = array_values(array_filter([$city, $venue], static fn(string $value): bool => $value !== ''));
    $location = $locationParts ? brvtal_public_home_upper(implode(' / ', $locationParts)) : 'LOCATION TBA';
    $href = $slug !== '' ? '/events/' . rawurlencode($slug) : '#events';
    $cta = $event ? 'ENTER EXPERIENCE' : 'VIEW EVENTS';
    $ticketUrl = $event ? brvtal_public_home_http_url($event['public_ticket_url'] ?? '') : '';

    $safeTitle = brvtal_public_home_escape($title);
    $safeHref = brvtal_public_home_escape($href);
    $safeLocation = brvtal_public_home_escape($location);
    $safeTag = brvtal_public_home_escape(brvtal_public_next_experience_tag($event));

    $section = substr($html, $sectionStart, $sectionEnd - $sectionStart);
    $section = str_replace('class="genesis scene', 'class="genesis scene home-phase-a-experience', $section);
    $section = str_replace('data-scene="GENESIS"', 'data-scene="EXPERIENCE"', $section);
    $section = preg_replace(
        '~<div class="eyebrow mono">.*?</div>~s',
        '<div class="eyebrow mono">NEXT EXPERIENCE / BRVTAL</div>',
        $section,
        1
    ) ?? $section;
    $section = preg_replace(
        '~<h2\b[^>]*>.*?</h2>~s',
        '<h2 data-text="' . $safeTitle . '">' . $safeTitle . '</h2>',
        $section,
        1
    ) ?? $section;
    $section = preg_replace(
        '~<p class="genesis-tag">.*?</p>~s',
        '<p class="genesis-tag">' . $safeTag . '</p>',
        $section,
        1
    ) ?? $section;
    $section = preg_replace(
        '~<div class="genesis-data mono">.*?</div>~s',
        '<div class="genesis-data mono"><span>' . brvtal_public_home_escape($dateLabel) . '</span><span>' . brvtal_public_home_escape($timeLabel) . '</span><span>' . $safeLocation . '</span></div>',
        $section,
        1
    ) ?? $section;

    $actions = '<div class="experience-actions"><a class="enter magnetic" href="' . $safeHref . '" data-cursor="ENTER">' . $cta . ' <span>↗</span></a>';
    if ($ticketUrl !== '') {
        $actions .= '<a class="ticket-cta magnetic" href="' . brvtal_public_home_escape($ticketUrl) . '" target="_blank" rel="noopener" data-cursor="TICKETS">TICKETS <span>↗</span></a>';
    }
    $actions .= '</div>';

    $lineupMarkup = brvtal_public_next_experience_lineup_markup($event);
    $section = preg_replace(
        '~<a class="enter magnetic"[^>]*>.*?</a>~s',
        $lineupMarkup . $actions,
        $section,
        1
    ) ?? $section;

    $background = '<div class="genesis-bg" style="background-image:none"></div>';
    if ($cover !== '') {
        $safeCover = brvtal_public_home_escape($cover);
        $background = '<div class="genesis-bg" style="background-image:none"><img src="' . $safeCover . '" alt="" aria-hidden="true" loading="lazy" decoding="async"></div>';
    }
    $section = str_replace('<div class="genesis-bg"></div>', $background, $section);

    $html = substr($html, 0, $sectionStart) . $section . substr($html, $sectionEnd);
    $navLabel = $event ? $safeTitle : 'NEXT';
    $html = str_replace(
        '<a href="#genesis"><span>02</span>GENESIS</a>',
        '<a href="#genesis"><span>02</span>' . $navLabel . '</a>',
        $html
    );

    return $html;
}
