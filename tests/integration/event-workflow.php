<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/event-workflow-lib.php';

function event_workflow_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "EVENT WORKFLOW INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL Event workflow integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
event_workflow_it_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database name must start with brvtal_test');
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    $dbName
);
$pdo = new PDO($dsn, (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'), (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''), [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);

$pdo->exec("CREATE TEMPORARY TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    event_date DATETIME NULL,
    archive_year SMALLINT UNSIGNED NULL,
    venue VARCHAR(180) NULL,
    city VARCHAR(120) NULL,
    description TEXT NULL,
    skin VARCHAR(60) NULL,
    accent VARCHAR(30) NULL,
    cover_image VARCHAR(500) NULL,
    ticket_url VARCHAR(700) NULL,
    ticket_instructions TEXT NULL,
    ticket_qr VARCHAR(500) NULL,
    featured TINYINT NOT NULL DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    finished_at DATETIME NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB");
$pdo->exec("CREATE TEMPORARY TABLE event_ticket_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(500) NULL,
    price DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'COP',
    external_url VARCHAR(700) NULL,
    payment_instructions TEXT NULL,
    qr_image VARCHAR(500) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    available_from DATETIME NULL,
    available_until DATETIME NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB");
$pdo->exec("CREATE TEMPORARY TABLE artists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL
) ENGINE=InnoDB");
$pdo->exec("CREATE TEMPORARY TABLE event_artists (
    event_id INT UNSIGNED NOT NULL,
    artist_id INT UNSIGNED NOT NULL,
    lineup_order INT NOT NULL DEFAULT 0,
    role VARCHAR(80) NULL,
    PRIMARY KEY(event_id,artist_id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TEMPORARY TABLE event_timetable_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    artist_id INT UNSIGNED NULL,
    label VARCHAR(180) NULL,
    starts_at_utc DATETIME NOT NULL,
    ends_at_utc DATETIME NOT NULL,
    timezone VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB");

$pdo->prepare('INSERT INTO artists(name) VALUES(?)')->execute(['PL0N3R CI']);
$artistId = (int)$pdo->lastInsertId();

$validRequest = brvtal_event_workflow_request([
    'event'=>[
        'title'=>'Atomic Event CI',
        'slug'=>'atomic-event-ci',
        'status'=>'published',
        'event_date'=>'2026-09-16 21:00',
        'city'=>'Pereira',
    ],
    'ticket_types'=>[[
        'name'=>'Preventa',
        'price'=>'20000',
        'status'=>'active',
        'currency'=>'COP',
        'available_from'=>'2026-09-01 10:00',
    ]],
    'lineup'=>[['artist_id'=>$artistId,'lineup_order'=>0,'role'=>'Live Set']],
]);
$result = brvtal_event_workflow_apply($pdo, $validRequest);
$eventId = (int)$result['event']['id'];
event_workflow_it_assert($eventId > 0, 'valid workflow must create an Event');
event_workflow_it_assert(count($result['ticket_types']) === 1, 'valid workflow must create its Ticket Type');
event_workflow_it_assert(count($result['lineup']) === 1 && (int)$result['lineup'][0]['artist_id'] === $artistId, 'valid workflow must persist lineup');
event_workflow_it_assert(trim((string)$result['event']['published_at']) !== '', 'publishing through workflow must stamp published_at');

$ticketId = (int)$result['ticket_types'][0]['id'];

$timetableAudit = [];
$timetableRequest = brvtal_event_workflow_request([
    'event'=>[
        'id'=>$eventId,
        'title'=>'Atomic Event CI',
        'slug'=>'atomic-event-ci',
        'status'=>'published',
        'event_date'=>'2026-09-16 21:00',
        'city'=>'Pereira',
    ],
    'ticket_types'=>[[
        'id'=>$ticketId,
        'name'=>'Preventa',
        'price'=>'20000',
        'status'=>'active',
        'currency'=>'COP',
        'available_from'=>'2026-09-01 10:00',
    ]],
    'lineup'=>[['artist_id'=>$artistId,'lineup_order'=>0,'role'=>'Live Set']],
    'timetable'=>[
        [
            'artist_id'=>$artistId,
            'starts_at'=>'2026-09-16 21:00',
            'ends_at'=>'2026-09-16 22:00',
            'timezone'=>'America/Bogota',
            'status'=>'approved',
        ],
        [
            'label'=>'Guest transition',
            'starts_at'=>'2026-09-16 22:00',
            'ends_at'=>'2026-09-16 22:30',
            'timezone'=>'America/Bogota',
            'status'=>'draft',
        ],
    ],
]);
$scheduled = brvtal_event_workflow_apply(
    $pdo,
    $timetableRequest,
    static function (
        string $action,
        string $resource,
        int $id,
        ?array $before,
        ?array $after,
        array $meta,
        ?string $label
    ) use (&$timetableAudit): void {
        $timetableAudit[] = compact('action','resource','id','before','after','meta','label');
    }
);
event_workflow_it_assert(count($scheduled['timetable']) === 2, 'workflow must persist timetable in the same transaction');
event_workflow_it_assert(
    count(array_filter($timetableAudit, static fn(array $row): bool => $row['action'] === 'timetable_create')) === 2,
    'timetable creates must be audited'
);
$firstTimetableId = (int)$scheduled['timetable'][0]['id'];
$secondTimetableId = (int)$scheduled['timetable'][1]['id'];

$timetableAudit = [];
$edited = brvtal_event_workflow_apply(
    $pdo,
    brvtal_event_workflow_request([
        'event'=>[
            'id'=>$eventId,
            'title'=>'Atomic Event CI',
            'slug'=>'atomic-event-ci',
            'status'=>'published',
            'event_date'=>'2026-09-16 21:00',
            'city'=>'Pereira',
        ],
        'ticket_types'=>[[
            'id'=>$ticketId,
            'name'=>'Preventa',
            'price'=>'20000',
            'status'=>'active',
            'currency'=>'COP',
            'available_from'=>'2026-09-01 10:00',
        ]],
        'lineup'=>[['artist_id'=>$artistId,'lineup_order'=>0,'role'=>'Live Set']],
        'timetable'=>[
            [
                'id'=>$firstTimetableId,
                'artist_id'=>$artistId,
                'label'=>'must not become artist authority',
                'starts_at'=>'2026-09-16 21:15',
                'ends_at'=>'2026-09-16 22:15',
                'timezone'=>'America/Bogota',
                'status'=>'approved',
            ],
            [
                'label'=>'Closing',
                'starts_at'=>'2026-09-16 22:15',
                'ends_at'=>'2026-09-16 23:15',
                'timezone'=>'America/Bogota',
                'status'=>'approved',
            ],
        ],
    ]),
    static function (
        string $action,
        string $resource,
        int $id,
        ?array $before,
        ?array $after,
        array $meta,
        ?string $label
    ) use (&$timetableAudit): void {
        $timetableAudit[] = compact('action','resource','id','before','after','meta','label');
    }
);
event_workflow_it_assert(count($edited['timetable']) === 2, 'edited timetable must contain exactly two slots');
event_workflow_it_assert($edited['timetable'][0]['label'] === null, 'linked Artist slots must not retain label authority');
$actions = array_column($timetableAudit, 'action');
event_workflow_it_assert(in_array('timetable_update', $actions, true), 'timetable updates must be audited');
event_workflow_it_assert(in_array('timetable_create', $actions, true), 'timetable creates must be audited during edit');
event_workflow_it_assert(in_array('timetable_delete', $actions, true), 'timetable deletes must be audited during edit');
event_workflow_it_assert(
    (int)$pdo->query("SELECT COUNT(*) FROM event_timetable_items WHERE id={$secondTimetableId}")->fetchColumn() === 0,
    'omitted timetable rows must be deleted atomically'
);

$beforeRollbackEvent = $pdo->query("SELECT title FROM events WHERE id={$eventId}")->fetchColumn();
$beforeRollbackTicket = $pdo->query("SELECT price FROM event_ticket_types WHERE id={$ticketId}")->fetchColumn();
$beforeRollbackLineup = (int)$pdo->query("SELECT COUNT(*) FROM event_artists WHERE event_id={$eventId}")->fetchColumn();
$beforeRollbackTimetable = $pdo->query(
    "SELECT CONCAT(id,':',COALESCE(artist_id,0),':',COALESCE(label,''),':',starts_at_utc,':',ends_at_utc,':',status) "
    . "FROM event_timetable_items WHERE event_id={$eventId} ORDER BY id"
)->fetchAll(PDO::FETCH_COLUMN);
$timetableLateFailure = false;
try {
    brvtal_event_workflow_apply($pdo, brvtal_event_workflow_request([
        'event'=>[
            'id'=>$eventId,
            'title'=>'Should rollback timetable failure',
            'slug'=>'atomic-event-ci',
            'status'=>'published',
            'event_date'=>'2026-09-16 21:00',
            'city'=>'Pereira',
        ],
        'ticket_types'=>[[
            'id'=>$ticketId,
            'name'=>'Preventa changed before timetable failure',
            'price'=>'23000',
            'status'=>'active',
        ]],
        'lineup'=>[],
        'timetable'=>[[
            'artist_id'=>999999,
            'starts_at'=>'2026-09-16 23:30',
            'ends_at'=>'2026-09-17 00:30',
            'timezone'=>'America/Bogota',
            'status'=>'approved',
        ]],
    ]));
} catch (InvalidArgumentException $e) {
    $timetableLateFailure = $e->getMessage() === 'TIMETABLE_ARTIST_NOT_FOUND';
}
event_workflow_it_assert($timetableLateFailure, 'missing timetable Artist must fail after earlier writes are staged');
event_workflow_it_assert($pdo->query("SELECT title FROM events WHERE id={$eventId}")->fetchColumn() === $beforeRollbackEvent, 'timetable failure must roll back Event mutation');
event_workflow_it_assert($pdo->query("SELECT price FROM event_ticket_types WHERE id={$ticketId}")->fetchColumn() === $beforeRollbackTicket, 'timetable failure must roll back Ticket mutation');
event_workflow_it_assert((int)$pdo->query("SELECT COUNT(*) FROM event_artists WHERE event_id={$eventId}")->fetchColumn() === $beforeRollbackLineup, 'timetable failure must roll back lineup mutation');
event_workflow_it_assert(
    $pdo->query(
        "SELECT CONCAT(id,':',COALESCE(artist_id,0),':',COALESCE(label,''),':',starts_at_utc,':',ends_at_utc,':',status) "
        . "FROM event_timetable_items WHERE event_id={$eventId} ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN) === $beforeRollbackTimetable,
    'timetable failure must roll back timetable mutation'
);

$overlapRejected = false;
try {
    brvtal_event_workflow_request([
        'event'=>['id'=>$eventId,'title'=>'Overlap','status'=>'draft'],
        'ticket_types'=>[],
        'lineup'=>[],
        'timetable'=>[
            ['label'=>'A','starts_at'=>'2026-09-16 20:00','ends_at'=>'2026-09-16 21:30','timezone'=>'America/Bogota'],
            ['label'=>'B','starts_at'=>'2026-09-16 21:00','ends_at'=>'2026-09-16 22:00','timezone'=>'America/Bogota'],
        ],
    ]);
} catch (InvalidArgumentException $e) {
    $overlapRejected = $e->getMessage() === 'TIMETABLE_OVERLAP';
}
event_workflow_it_assert($overlapRejected, 'overlapping timetable must fail before workflow mutation');
event_workflow_it_assert($pdo->query("SELECT title FROM events WHERE id={$eventId}")->fetchColumn() === $beforeRollbackEvent, 'overlap rejection must leave Event untouched');
event_workflow_it_assert($pdo->query("SELECT price FROM event_ticket_types WHERE id={$ticketId}")->fetchColumn() === $beforeRollbackTicket, 'overlap rejection must leave Ticket untouched');
event_workflow_it_assert((int)$pdo->query("SELECT COUNT(*) FROM event_artists WHERE event_id={$eventId}")->fetchColumn() === $beforeRollbackLineup, 'overlap rejection must leave lineup untouched');

$updateRequest = brvtal_event_workflow_request([
    'event'=>[
        'id'=>$eventId,
        'title'=>'Atomic Event CI updated',
        'slug'=>'atomic-event-ci',
        'status'=>'published',
        'event_date'=>'2026-09-16 21:00',
        'city'=>'Pereira',
    ],
    'ticket_types'=>[[
        'id'=>$ticketId,
        'name'=>'Preventa updated',
        'price'=>'22000',
        'status'=>'active',
        'external_url'=>'https://example.com/tickets',
    ]],
    'lineup'=>[['artist_id'=>$artistId,'lineup_order'=>0,'role'=>'Live Set']],
]);
$updated = brvtal_event_workflow_apply($pdo, $updateRequest);
event_workflow_it_assert((string)$updated['ticket_types'][0]['currency'] === 'COP', 'partial Ticket update must preserve hidden currency metadata');
event_workflow_it_assert((string)$updated['ticket_types'][0]['available_from'] === '2026-09-01 10:00:00', 'partial Ticket update must preserve hidden availability metadata');

$eventCountBefore = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
$ticketCountBefore = (int)$pdo->query('SELECT COUNT(*) FROM event_ticket_types')->fetchColumn();
$lateFailure = false;
try {
    brvtal_event_workflow_apply($pdo, brvtal_event_workflow_request([
        'event'=>[
            'title'=>'Rollback Event CI',
            'slug'=>'rollback-event-ci',
            'status'=>'draft',
        ],
        'ticket_types'=>[['name'=>'Should rollback','price'=>'10000','status'=>'active']],
        'lineup'=>[['artist_id'=>999999,'lineup_order'=>0,'role'=>'Ghost']],
    ]));
} catch (InvalidArgumentException $e) {
    $lateFailure = $e->getMessage() === 'LINEUP_ARTIST_NOT_FOUND';
}
event_workflow_it_assert($lateFailure, 'missing lineup Artist must fail after Event/Ticket writes are staged');
event_workflow_it_assert((int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() === $eventCountBefore, 'late workflow failure must roll back Event insert');
event_workflow_it_assert((int)$pdo->query('SELECT COUNT(*) FROM event_ticket_types')->fetchColumn() === $ticketCountBefore, 'late workflow failure must roll back Ticket insert');
event_workflow_it_assert((int)$pdo->query("SELECT COUNT(*) FROM events WHERE slug='rollback-event-ci'")->fetchColumn() === 0, 'rolled-back Event must not persist');

$missingDateRejected = false;
try {
    brvtal_event_workflow_apply($pdo, brvtal_event_workflow_request([
        'event'=>['title'=>'Incomplete public Event','slug'=>'incomplete-public-event','status'=>'published','city'=>'Pereira'],
        'ticket_types'=>[],
        'lineup'=>[],
    ]));
} catch (InvalidArgumentException $e) {
    $missingDateRejected = $e->getMessage() === 'EVENT_DATE_REQUIRED';
}
event_workflow_it_assert($missingDateRejected, 'non-draft Event without date must fail server-side');
event_workflow_it_assert((int)$pdo->query("SELECT COUNT(*) FROM events WHERE slug='incomplete-public-event'")->fetchColumn() === 0, 'invalid public Event must not persist');

echo "BRVTAL Event workflow integration passed.\n";
