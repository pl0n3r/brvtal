<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_home.php';

function public_home_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$now = new DateTimeImmutable('2026-09-14 12:00:00');
$selected = brvtal_public_select_next_experience([
    ['id'=>1,'title'=>'Past featured','slug'=>'past-featured','event_date'=>'2026-09-13 23:00:00','status'=>'published','featured'=>1,'sort_order'=>0],
    ['id'=>2,'title'=>'Nearest regular','slug'=>'nearest-regular','event_date'=>'2026-09-20 22:00:00','status'=>'published','featured'=>0,'sort_order'=>0],
    ['id'=>3,'title'=>'TENSION & NOISE','slug'=>'tension-midnight','event_date'=>'2026-12-12 23:00:00','status'=>'tickets_available','featured'=>1,'sort_order'=>2,'city'=>'Medellín','venue'=>'Club X','description'=>'BRVTAL × SIGNAL UNIT','cover_image'=>'/uploads/media/tension.webp','public_ticket_url'=>'https://tickets.example.com/tension','ticket_types'=>[
        ['name'=>'Preventa','price'=>'45000.00','currency'=>'COP','status'=>'active'],
        ['name'=>'Door','price'=>'60000.00','currency'=>'COP','status'=>'active'],
    ]],
    ['id'=>4,'title'=>'Private draft','slug'=>'private-draft','event_date'=>'2026-09-15 22:00:00','status'=>'draft','featured'=>1,'sort_order'=>0],
    ['id'=>5,'title'=>'Later featured','slug'=>'later-featured','event_date'=>'2027-01-01 01:00:00','status'=>'upcoming','featured'=>1,'sort_order'=>0],
], $now);

public_home_expect(is_array($selected) && (int)$selected['id'] === 3, 'Next Experience must prefer the nearest eligible featured event and ignore past/private records');

$nearest = brvtal_public_select_next_experience([
    ['id'=>7,'title'=>'Later','event_date'=>'2026-11-01 22:00:00','status'=>'published','featured'=>0,'sort_order'=>0],
    ['id'=>6,'title'=>'Sooner','event_date'=>'2026-10-01 22:00:00','status'=>'upcoming','featured'=>0,'sort_order'=>0],
], $now);
public_home_expect(is_array($nearest) && (int)$nearest['id'] === 6, 'Without a featured event, Next Experience must choose the nearest active event');

$index = (string)file_get_contents(__DIR__ . '/../index.html');
$indexPhp = (string)file_get_contents(__DIR__ . '/../index.php');
public_home_expect($index !== '' && $indexPhp !== '', 'Home sources must be readable');

$rendered = brvtal_public_render_next_experience($index, $selected);
$start = strpos($rendered, '<section class="genesis scene');
$end = $start === false ? false : strpos($rendered, '</section>', $start);
public_home_expect($start !== false && $end !== false, 'Rendered Home must preserve the Next Experience section');
$section = substr($rendered, $start, $end - $start);

public_home_expect(str_contains($section, 'data-scene="EXPERIENCE"'), 'Next Experience scene must no longer identify itself as legacy Genesis');
public_home_expect(str_contains($section, 'data-text="TENSION &amp; NOISE"') && str_contains($section, '>TENSION &amp; NOISE</h2>'), 'Event title must come from CMS data and be escaped');
public_home_expect(str_contains($section, 'TICKETS AVAILABLE.'), 'Public lifecycle status must drive the event tag when useful');
public_home_expect(
    str_contains($section, 'data-c5-fact="date" data-label="DATE">12.12.2026</span>')
    && str_contains($section, 'data-c5-fact="time" data-label="TIME">23:00</span>'),
    'Event date and time must render from CMS data with authored fact hooks'
);
public_home_expect(str_contains($section, 'MEDELLÍN / CLUB X'), 'Event city and venue must render from CMS data');
public_home_expect(str_contains($section, 'href="/events/tension-midnight"'), 'Next Experience CTA must use the canonical public event route');
public_home_expect(
    str_contains($section, 'data-c5-experience-artwork')
    && str_contains($section, 'src="/uploads/media/tension.webp"')
    && str_contains($section, 'alt="TENSION &amp; NOISE event artwork"'),
    'Next Experience artwork must use canonical Event media with meaningful fallback semantics'
);
public_home_expect(str_contains($section, 'class="c5-experience-authored"') || str_contains($section, ' c5-experience-authored'), 'Next Experience must expose the authored Concept 05 surface');
public_home_expect(str_contains($section, 'BRVTAL × SIGNAL UNIT'), 'Administrable Event description must remain available as the editorial note');
public_home_expect(str_contains($section, 'PREVENTA') && str_contains($section, '45.000 COP'), 'Canonical Ticket Type price must project into the event takeover');
public_home_expect(str_contains($section, 'DOOR') && str_contains($section, '60.000 COP'), 'A second canonical Ticket Type may provide the secondary Door price');
public_home_expect(str_contains($section, 'href="https://tickets.example.com/tension"'), 'Valid canonical ticket URL must power the Tickets action');
public_home_expect(
    str_contains($rendered, '<strong data-c5-next-label>TENSION &amp; NOISE</strong>'),
    'Overlay navigation must follow the selected event instead of staying on Genesis'
);
public_home_expect(
    str_contains($rendered, 'class="c5-header-ticket magnetic"')
        && str_contains($rendered, 'href="https://tickets.example.com/tension"'),
    'Valid Next Experience ticketing must project into the authored desktop header'
);
$heroPos = strpos($rendered, '<section class="hero scene');
$experiencePos = strpos($rendered, '<section class="genesis scene');
$manifestoPos = strpos($rendered, '<section class="manifesto scene');
public_home_expect(
    $heroPos !== false && $experiencePos !== false && $manifestoPos !== false
    && $heroPos < $experiencePos && $experiencePos < $manifestoPos,
    'Next Experience must sit immediately after the Hero before the legacy manifesto section'
);

$unsafe = brvtal_public_render_next_experience($index, [
    'id'=>9,
    'title'=>'<script>alert(1)</script>',
    'slug'=>'safe-slug',
    'event_date'=>'2026-10-10 20:30:00',
    'status'=>'published',
    'city'=>'Pereira',
]);
public_home_expect(
    !str_contains($unsafe, '<script>alert(1)</script>')
        && str_contains($unsafe, '&lt;script&gt;alert(1)&lt;/script&gt;'),
    'CMS event text must be HTML-escaped before Home rendering'
);
public_home_expect(
    !str_contains($unsafe, 'class="c5-header-ticket'),
    'Event without a valid public ticket destination must not create a header Tickets CTA'
);

$fallback = brvtal_public_render_next_experience($index, null);
$fallbackStart = strpos($fallback, '<section class="genesis scene');
$fallbackEnd = $fallbackStart === false ? false : strpos($fallback, '</section>', $fallbackStart);
public_home_expect($fallbackStart !== false && $fallbackEnd !== false, 'Fallback Next Experience section must remain present');
$fallbackSection = substr($fallback, $fallbackStart, $fallbackEnd - $fallbackStart);
public_home_expect(str_contains($fallbackSection, '>NEXT SIGNAL</h2>'), 'No eligible event must render a neutral Next Experience fallback');
public_home_expect(str_contains($fallbackSection, 'NEW DATE TO BE ANNOUNCED.'), 'Neutral fallback must not advertise legacy event copy');
public_home_expect(
    str_contains($fallbackSection, 'data-c5-fact="date" data-label="DATE">DATE TBA</span>')
    && str_contains($fallbackSection, 'data-c5-fact="time" data-label="TIME">TIME TBA</span>')
    && str_contains($fallbackSection, 'data-c5-fact="location" data-label="LOCATION">LOCATION TBA</span>'),
    'Neutral fallback must not reuse legacy date/location data'
);
public_home_expect(str_contains($fallbackSection, 'VIEW EVENTS'), 'Neutral fallback must keep a useful Events CTA');
public_home_expect(
    str_contains($fallbackSection, 'class="genesis-bg c5-experience-artwork"')
    && str_contains($fallbackSection, 'data-c5-experience-artwork')
    && !str_contains($fallbackSection, '<img'),
    'Neutral fallback must suppress legacy Genesis artwork without inventing media'
);

public_home_expect(str_contains($indexPhp, "require_once __DIR__ . '/config/public_home.php';"), 'Canonical Home delivery must load the Next Experience renderer');
$renderPos = strpos($indexPhp, 'brvtal_public_render_next_experience($html, $nextExperience)');
$dedupePos = strpos($indexPhp, 'brvtal_public_dedupe_decorative_assets($html)');
public_home_expect($renderPos !== false && $dedupePos !== false && $renderPos < $dedupePos, 'CMS Next Experience must render before decorative asset fallback transforms');
public_home_expect(str_contains($indexPhp, 'brvtal_public_next_experience(db())'), 'Canonical Home must select Next Experience from the public Event lifecycle policy');

echo "BRVTAL Public Home contract tests passed.\n";
