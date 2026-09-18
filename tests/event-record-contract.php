<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_visibility.php';
require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/public_page.php';

/** Fail the Event Record contract with one precise diagnostic. */
function event_record_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "EVENT RECORD CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$now = new DateTimeImmutable('2026-09-16 12:00:00');
event_record_expect(!brvtal_public_event_is_historical([
    'status' => 'tickets_available',
    'event_date' => '2026-09-20 21:00:00',
], $now), 'future active Event must stay in active presentation mode');
event_record_expect(brvtal_public_event_is_historical([
    'status' => 'published',
    'event_date' => '2026-08-14 21:00:00',
], $now), 'past public Event must become a historical record even before manual status cleanup');
event_record_expect(brvtal_public_event_is_historical([
    'status' => 'cancelled',
    'event_date' => '2026-10-01 21:00:00',
    'published_at' => '2026-08-01 12:00:00',
], $now), 'published cancelled Event must remain a historical public record');
event_record_expect(!brvtal_public_event_is_historical([
    'status' => 'draft',
    'event_date' => '2026-08-14 21:00:00',
], $now), 'private draft must never gain historical public presentation');
$requestClockA = brvtal_public_event_policy_now();
$requestClockB = brvtal_public_event_policy_now();
event_record_expect($requestClockA === $requestClockB, 'implicit Event lifecycle and ticket policy must share one request clock');

$source = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
$archive = (string)file_get_contents(__DIR__ . '/../api/public-archive.php');
$css = (string)file_get_contents(__DIR__ . '/../css/public-event-record.css');
event_record_expect(str_contains($archive, 'brvtal_public_event_is_historical($event, $now)'), 'Archive and Event Record must share lifecycle classification');
event_record_expect(str_contains($source, 'brvtal_public_event_is_historical($detail)'), 'canonical Event page must use lifecycle classification');
event_record_expect(str_contains($source, "sets_media WHERE event_id=? AND status='published'"), 'Event Record must reuse explicit published Set relation');
event_record_expect(
    brvtalPublicTransmissionRelationType('events') === 'event',
    'Event Record must use the canonical explicit Blog relation type'
);
event_record_expect(str_contains($source, 'brvtal_public_memories_for_entity($pdo, $type, $id)'), 'Event Record must source Memories only through the explicit structured Memory relation backend');
event_record_expect(str_contains($source, 'public-event-record.css'), 'Event pages must load the dedicated Event Record visual layer');
event_record_expect(str_contains($css, 'body.entity-page--event:before'), 'Event Record keeps BRVTAL grain/signal texture');
event_record_expect(str_contains($css, '--event-signal:#b6ff00'), 'Event Record keeps acid green as signal fallback rather than page background');
event_record_expect(str_contains($css, '@media(max-width:620px)'), 'Event Record must have a dedicated mobile layout');
event_record_expect(str_contains($css, 'prefers-reduced-motion:reduce'), 'Event Record must honor reduced motion');

$seo = [
    'title' => 'GENESIS — BRVTAL',
    'description' => 'Event record fixture',
    'canonical' => 'https://www.brvtal.com.co/events/genesis',
    'image' => 'https://example.com/genesis.jpg',
    'schema' => ['@type' => 'MusicEvent'],
];
$historicalPage = [
    'entity' => [
        'id' => 14,
        'route_type' => 'events',
        'title' => 'GENESIS',
        'description' => 'BRVTAL x RANDOM KORE',
        'status' => 'finished',
        'accent' => '#b6ff00',
    ],
    'facts' => ['DATE'=>'14.08.2026 / 21:00','LOCATION'=>'PEREIRA / COLOMBIA','STATUS'=>'FINISHED','RECORD'=>'ARCHIVE / 2026'],
    'links' => [],
    'record' => ['state'=>'historical','year'=>'2026','status'=>'FINISHED'],
    'related' => [
        'LINEUP' => [['title'=>'PL0N3R','slug'=>'pl0n3r','route_type'=>'artists','image'=>'','meta'=>'DJ']],
        'SETS' => [['title'=>'GENESIS LIVE SET','slug'=>'genesis-live','route_type'=>'sets','image'=>'','meta'=>'SOUNDCLOUD']],
        'TRANSMISSIONS' => [['title'=>'GENESIS RECAP','slug'=>'genesis-recap','route_type'=>'blog','image'=>'','meta'=>'16.08.2026']],
        'MEMORIES' => [['title'=>'GENESIS FLOOR','image'=>'','url'=>'/#media','meta'=>'MEMORY / IMAGE']],
    ],
    'degraded' => false,
];
$historicalHtml = brvtal_public_entity_page($historicalPage, $seo);
event_record_expect(str_contains($historicalHtml, 'data-event-record-state="historical"'), 'historical Event must identify its record state in markup');
event_record_expect(str_contains($historicalHtml, 'EVENT RECORD / BRVTAL'), 'historical Event hero must frame the page as Event Record');
event_record_expect(str_contains($historicalHtml, 'EVENT RECORD / FINISHED'), 'historical record band must retain lifecycle status');
event_record_expect(str_contains($historicalHtml, 'LINEUP / RECORD / 01'), 'historical lineup must be framed as archived record');
event_record_expect(str_contains($historicalHtml, 'RECORDED SETS / 01'), 'historical Sets must be framed as recorded material');
event_record_expect(str_contains($historicalHtml, 'TRANSMISSIONS / 01'), 'explicit editorial relation must surface in Event Record');
event_record_expect(str_contains($historicalHtml, 'MEMORIES / 01'), 'explicit Memory relation must surface in Event Record');
event_record_expect(str_contains($historicalHtml, 'href="/#media"'), 'Event Record Memory must link back to the public Memories archive');
event_record_expect(!str_contains($historicalHtml, '>TICKETS ↗<'), 'historical Event must not regain stale commercial CTA');

$activePage = $historicalPage;
$activePage['entity']['status'] = 'tickets_available';
$activePage['record'] = ['state'=>'active','year'=>'2026','status'=>'TICKETS AVAILABLE'];
$activePage['facts']['STATUS'] = 'TICKETS AVAILABLE';
unset($activePage['facts']['RECORD']);
$activePage['links'] = ['TICKETS'=>'https://tickets.example.com/genesis'];
$activePage['related'] = ['LINEUP'=>$historicalPage['related']['LINEUP']];
$activeHtml = brvtal_public_entity_page($activePage, $seo);
event_record_expect(str_contains($activeHtml, 'data-event-record-state="active"'), 'active Event must identify active presentation state');
event_record_expect(str_contains($activeHtml, 'EVENT SIGNAL / TICKETS AVAILABLE'), 'active Event band must expose real lifecycle status');
event_record_expect(str_contains($activeHtml, '>TICKETS ↗<'), 'active Event keeps valid commercial CTA supplied by lifecycle policy');
event_record_expect(str_contains($activeHtml, '← BACK TO EVENTS'), 'active Event returns to Events while historical Event returns to Archive framing');

if (getenv('BRVTAL_INTEGRATION_TESTS') === '1') {
    $dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
    event_record_expect((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'integration database name must start with brvtal_test');

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
        (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
        $dbName
    );
    $pdo = new PDO($dsn, (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'), (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("CREATE TEMPORARY TABLE events (
        id INT PRIMARY KEY,
        event_date DATETIME NULL,
        venue VARCHAR(180) NULL,
        city VARCHAR(120) NULL,
        status VARCHAR(30) NOT NULL,
        skin VARCHAR(60) NULL,
        accent VARCHAR(30) NULL,
        ticket_url VARCHAR(700) NULL,
        ticket_instructions TEXT NULL,
        published_at DATETIME NULL,
        finished_at DATETIME NULL,
        cancelled_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TEMPORARY TABLE artists (
        id INT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        slug VARCHAR(190) NOT NULL,
        photo VARCHAR(500) NULL,
        status VARCHAR(30) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TEMPORARY TABLE event_artists (
        event_id INT NOT NULL,
        artist_id INT NOT NULL,
        lineup_order INT NOT NULL DEFAULT 0,
        role VARCHAR(80) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TEMPORARY TABLE sets_media (
        id INT PRIMARY KEY,
        title VARCHAR(180) NOT NULL,
        slug VARCHAR(190) NOT NULL,
        cover_image VARCHAR(500) NULL,
        platform VARCHAR(30) NOT NULL,
        event_id INT NULL,
        status VARCHAR(30) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TEMPORARY TABLE blog_posts (
        id INT PRIMARY KEY,
        title VARCHAR(180) NOT NULL,
        slug VARCHAR(190) NOT NULL,
        cover_image VARCHAR(500) NULL,
        published_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        status VARCHAR(30) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TEMPORARY TABLE blog_post_relations (
        post_id INT NOT NULL,
        related_type VARCHAR(30) NOT NULL,
        related_id INT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TEMPORARY TABLE event_ticket_types (
        id INT PRIMARY KEY,
        event_id INT NOT NULL,
        name VARCHAR(180) NOT NULL,
        description TEXT NULL,
        price DECIMAL(10,2) NULL,
        currency VARCHAR(10) NULL,
        external_url VARCHAR(700) NULL,
        status VARCHAR(30) NOT NULL,
        available_from DATETIME NULL,
        available_until DATETIME NULL,
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $today = new DateTimeImmutable('today');
    $futureDate = $today->modify('+30 days')->setTime(21, 0)->format('Y-m-d H:i:s');
    $draftDate = $today->modify('+60 days')->setTime(21, 0)->format('Y-m-d H:i:s');
    $pastDate = $today->modify('-30 days')->setTime(21, 0)->format('Y-m-d H:i:s');
    $publishedAt = $today->modify('-90 days')->setTime(12, 0)->format('Y-m-d H:i:s');
    $finishedAt = $today->modify('-29 days')->setTime(5, 0)->format('Y-m-d H:i:s');

    $eventInsert = $pdo->prepare('INSERT INTO events(id,event_date,venue,city,status,skin,accent,ticket_url,ticket_instructions,published_at,finished_at,cancelled_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    $eventInsert->execute([101,$futureDate,'Active Venue','Pereira','tickets_available','CORE','#ff1f35','https://tickets.example.com/active','active payment',$publishedAt,null,null]);
    $eventInsert->execute([102,$pastDate,'Archive Venue','Pereira','finished','CORE','#b6ff00','https://tickets.example.com/stale','stale payment',$publishedAt,$finishedAt,null]);
    $eventInsert->execute([103,$draftDate,'Draft Venue','Pereira','draft','CORE','#ff1f35','https://tickets.example.com/draft','draft payment',null,null,null]);

    $artistInsert = $pdo->prepare('INSERT INTO artists(id,name,slug,photo,status) VALUES(?,?,?,?,?)');
    $artistInsert->execute([201,'PUBLIC ARTIST','public-artist','', 'published']);
    $artistInsert->execute([202,'PRIVATE ARTIST','private-artist','', 'draft']);
    $lineupInsert = $pdo->prepare('INSERT INTO event_artists(event_id,artist_id,lineup_order,role) VALUES(?,?,?,?)');
    foreach ([101,102,103] as $eventId) {
        $lineupInsert->execute([$eventId,201,1,'DJ']);
        $lineupInsert->execute([$eventId,202,2,'DJ']);
    }

    $setInsert = $pdo->prepare('INSERT INTO sets_media(id,title,slug,cover_image,platform,event_id,status,sort_order,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())');
    $setId = 300;
    foreach ([101,102,103] as $eventId) {
        $setInsert->execute([++$setId,'PUBLIC SET ' . $eventId,'public-set-' . $eventId,'','soundcloud',$eventId,'published',1]);
        $setInsert->execute([++$setId,'PRIVATE SET ' . $eventId,'private-set-' . $eventId,'','soundcloud',$eventId,'draft',2]);
    }

    $postInsert = $pdo->prepare('INSERT INTO blog_posts(id,title,slug,cover_image,published_at,updated_at,status) VALUES(?,?,?,?,?,?,?)');
    $relationInsert = $pdo->prepare('INSERT INTO blog_post_relations(post_id,related_type,related_id,sort_order) VALUES(?,?,?,?)');
    $postId = 400;
    foreach ([101,102,103] as $eventId) {
        $publishedPostId = ++$postId;
        $draftPostId = ++$postId;
        $postInsert->execute([$publishedPostId,'PUBLIC TRANSMISSION ' . $eventId,'public-transmission-' . $eventId,'',$publishedAt,$publishedAt,'published']);
        $postInsert->execute([$draftPostId,'PRIVATE TRANSMISSION ' . $eventId,'private-transmission-' . $eventId,'',null,$publishedAt,'draft']);
        $relationInsert->execute([$publishedPostId,'event',$eventId,1]);
        $relationInsert->execute([$draftPostId,'event',$eventId,2]);
    }

    $ticketInsert = $pdo->prepare('INSERT INTO event_ticket_types(id,event_id,name,description,price,currency,external_url,status,available_from,available_until,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $ticketInsert->execute([501,101,'ACTIVE TICKET','Public offer',20000,'COP','https://tickets.example.com/type','active',null,null,1]);
    $ticketInsert->execute([502,101,'PRIVATE TICKET','Draft offer',10000,'COP','https://tickets.example.com/private','draft',null,null,2]);
    $ticketInsert->execute([503,102,'STALE TICKET','Historical offer',20000,'COP','https://tickets.example.com/stale-type','active',null,null,1]);
    $ticketInsert->execute([504,103,'DRAFT EVENT TICKET','Draft event offer',20000,'COP','https://tickets.example.com/draft-type','active',null,null,1]);

    $entity = static fn(int $id, string $title, string $slug): array => [
        'id' => $id,
        'route_type' => 'events',
        'title' => $title,
        'description' => $title . ' description',
        'slug' => $slug,
    ];
    $activeData = brvtal_public_page_data($pdo, $entity(101, 'ACTIVE EVENT', 'active-event'));
    $historicalData = brvtal_public_page_data($pdo, $entity(102, 'HISTORICAL EVENT', 'historical-event'));
    $draftData = brvtal_public_page_data($pdo, $entity(103, 'DRAFT EVENT', 'draft-event'));

    event_record_expect(($activeData['record']['state'] ?? '') === 'active', 'MariaDB active Event must hydrate as active');
    event_record_expect(($activeData['links']['TICKETS'] ?? '') === 'https://tickets.example.com/active', 'MariaDB active Event must retain canonical ticket link');
    event_record_expect(count($activeData['related']['TICKETS'] ?? []) === 1 && ($activeData['related']['TICKETS'][0]['title'] ?? '') === 'ACTIVE TICKET', 'MariaDB active Event must expose only public ticket types');
    event_record_expect(($historicalData['record']['state'] ?? '') === 'historical', 'MariaDB past finished Event must hydrate as historical');
    event_record_expect(!isset($historicalData['links']['TICKETS']), 'MariaDB historical Event must suppress stale ticket link');
    event_record_expect(empty($historicalData['related']['TICKETS']), 'MariaDB historical Event must not query stale ticket types');
    event_record_expect(!brvtal_public_event_is_visible(['status'=>'draft','event_date'=>$draftDate]), 'MariaDB draft fixture must remain outside canonical public visibility');
    event_record_expect(empty($draftData['links']['TICKETS']) && empty($draftData['related']['TICKETS']), 'MariaDB draft Event page-data must never expose commercial actions');

    foreach ([$activeData,$historicalData,$draftData] as $pageData) {
        $lineupTitles = array_column($pageData['related']['LINEUP'] ?? [], 'title');
        $setTitles = array_column($pageData['related']['SETS'] ?? [], 'title');
        $transmissionTitles = array_column($pageData['related']['TRANSMISSIONS'] ?? [], 'title');
        event_record_expect(count($lineupTitles) === 1 && !in_array('PRIVATE ARTIST', $lineupTitles, true), 'MariaDB Event Record must exclude unpublished lineup artists');
        event_record_expect(count($setTitles) === 1 && count(array_filter($setTitles, static fn(string $title): bool => str_starts_with($title, 'PRIVATE'))) === 0, 'MariaDB Event Record must exclude unpublished Sets');
        event_record_expect(count($transmissionTitles) === 1 && count(array_filter($transmissionTitles, static fn(string $title): bool => str_starts_with($title, 'PRIVATE'))) === 0, 'MariaDB Event Record must exclude unpublished Transmissions');
    }

    $integrationSeo = [
        'title' => 'Historical Event — BRVTAL',
        'description' => 'MariaDB Event Record fixture',
        'canonical' => 'https://www.brvtal.com.co/events/historical-event',
        'image' => '',
        'schema' => ['@type' => 'MusicEvent'],
    ];
    $integrationHtml = brvtal_public_entity_page($historicalData, $integrationSeo);
    event_record_expect(str_contains($integrationHtml, 'data-event-record-state="historical"'), 'MariaDB hydrated Event must render historical state');
    event_record_expect(str_contains($integrationHtml, 'PUBLIC TRANSMISSION 102'), 'MariaDB hydrated Event must render published Transmission');
    event_record_expect(!str_contains($integrationHtml, 'PRIVATE TRANSMISSION 102'), 'MariaDB hydrated Event must not render unpublished Transmission');
    event_record_expect(!str_contains($integrationHtml, '>TICKETS ↗<'), 'MariaDB hydrated historical Event must not render ticket CTA');
}

echo "BRVTAL Event Record contract tests passed.\n";
