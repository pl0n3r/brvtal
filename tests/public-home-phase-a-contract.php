<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_home.php';

function phase_a_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$now = new DateTimeImmutable('2026-09-16 12:00:00');
$selected = brvtal_public_select_next_experience([
    [
        'id' => 1,
        'title' => 'PAST',
        'event_date' => '2026-09-15 22:00:00',
        'status' => 'published',
        'featured' => 1,
        'sort_order' => 0,
    ],
    [
        'id' => 2,
        'title' => 'NEXT',
        'event_date' => '2026-10-01 22:00:00',
        'status' => 'tickets_available',
        'featured' => 0,
        'sort_order' => 0,
    ],
], $now);
phase_a_assert(($selected['id'] ?? null) === 2, 'past events must not win Next Experience');

phase_a_assert(
    brvtal_public_home_http_url('https://tickets.example.com/rave') === 'https://tickets.example.com/rave',
    'https ticket URL must be accepted'
);
phase_a_assert(
    brvtal_public_home_http_url('javascript:alert(1)') === '',
    'non-http ticket URL must be rejected'
);

$template = (string)file_get_contents(__DIR__ . '/../index.html');
$event = [
    'id' => 9,
    'title' => 'SIGNAL TEST',
    'slug' => 'signal-test',
    'event_date' => '2026-11-21 22:00:00',
    'city' => 'Pereira',
    'venue' => 'Warehouse 09',
    'cover_image' => '/uploads/events/signal.jpg',
    'status' => 'last_tickets',
    'public_ticket_url' => 'https://tickets.example.com/signal',
    'lineup' => [
        ['name' => 'PL0N3R'],
        ['name' => 'DNL5'],
    ],
];
$rendered = brvtal_public_render_next_experience($template, $event);

phase_a_assert(str_contains($rendered, 'css/public-home-phase-a.css'), 'Phase A stylesheet must be attached');
phase_a_assert(str_contains($rendered, 'UNDERGROUND ELECTRONIC CULTURE / PEREIRA / COLOMBIA'), 'Home must state cultural origin');
phase_a_assert(str_contains($rendered, 'EVENTS / SOUND / ARTISTS / ARCHIVE'), 'Home must communicate the BRVTAL ecosystem');
phase_a_assert(str_contains($rendered, 'home-phase-a-experience'), 'Next Experience must receive Phase A treatment');
phase_a_assert(str_contains($rendered, 'SIGNAL TEST'), 'Next Experience must use backend Event title');
phase_a_assert(str_contains($rendered, 'PEREIRA / WAREHOUSE 09'), 'Next Experience must use backend location');
phase_a_assert(str_contains($rendered, 'PL0N3R <i>/</i> DNL5'), 'Next Experience must render structured lineup names');
phase_a_assert(str_contains($rendered, 'href="https://tickets.example.com/signal"'), 'valid public ticket URL must render a Tickets CTA');
phase_a_assert(str_contains($rendered, 'TICKETS <span>↗</span>'), 'Tickets CTA must be explicit');

$invalidTicket = $event;
$invalidTicket['public_ticket_url'] = 'javascript:alert(1)';
$invalidRendered = brvtal_public_render_next_experience($template, $invalidTicket);
phase_a_assert(!str_contains($invalidRendered, 'class="ticket-cta'), 'invalid ticket URL must not render a Tickets CTA');

echo "PASS public-home-phase-a-contract\n";
