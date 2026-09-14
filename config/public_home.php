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

function brvtal_public_next_experience(PDO $pdo, ?DateTimeImmutable $now = null): ?array
{
    $statuses = brvtal_public_event_statuses()['active'];
    $placeholders = brvtal_public_sql_placeholders($statuses);
    $statement = $pdo->prepare(
        "SELECT id,title,slug,event_date,venue,city,cover_image,featured,status,sort_order
         FROM events
         WHERE status IN ({$placeholders})"
    );
    $statement->execute($statuses);
    return brvtal_public_select_next_experience($statement->fetchAll(PDO::FETCH_ASSOC), $now);
}

function brvtal_public_home_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
        default => 'BRVTAL EXPERIENCE.',
    };
}

function brvtal_public_render_next_experience(string $html, ?array $event): string
{
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
    $location = $locationParts ? mb_strtoupper(implode(' / ', $locationParts), 'UTF-8') : 'LOCATION TBA';
    $href = $slug !== '' ? '/events/' . rawurlencode($slug) : '#events';
    $cta = $event ? 'ENTER EXPERIENCE' : 'VIEW EVENTS';

    $safeTitle = brvtal_public_home_escape($title);
    $safeHref = brvtal_public_home_escape($href);
    $safeLocation = brvtal_public_home_escape($location);
    $safeTag = brvtal_public_home_escape(brvtal_public_next_experience_tag($event));

    $section = substr($html, $sectionStart, $sectionEnd - $sectionStart);
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
    $section = preg_replace(
        '~<a class="enter magnetic"[^>]*>.*?</a>~s',
        '<a class="enter magnetic" href="' . $safeHref . '" data-cursor="ENTER">' . $cta . ' <span>↗</span></a>',
        $section,
        1
    ) ?? $section;

    $background = '<div class="genesis-bg" style="background-image:none"></div>';
    if ($cover !== '') {
        $safeCover = brvtal_public_home_escape($cover);
        $background = '<div class="genesis-bg" style="background-image:none"><img src="' . $safeCover . '" alt="" aria-hidden="true" loading="lazy" decoding="async" style="width:100%;height:100%;object-fit:cover;display:block"></div>';
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
