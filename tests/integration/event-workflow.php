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
