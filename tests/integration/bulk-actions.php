<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/bulk-actions-lib.php';

function bulk_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "BULK ACTIONS INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL bulk actions integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
bulk_it_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database name must start with brvtal_test');

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

$pdo->exec('DROP TEMPORARY TABLE IF EXISTS events');
$pdo->exec("CREATE TEMPORARY TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    event_date DATETIME NULL,
    city VARCHAR(120) NULL,
    published_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    finished_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach (['artists','sets_media','pages'] as $table) {
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$table}");
    $pdo->exec("CREATE TEMPORARY TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY,status VARCHAR(30) NOT NULL DEFAULT 'draft') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
foreach (['releases','blog_posts'] as $table) {
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$table}");
    $pdo->exec("CREATE TEMPORARY TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY,status VARCHAR(30) NOT NULL DEFAULT 'draft',published_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// #173: the full selected Event set is validated before any bulk status mutation.
$pdo->exec("INSERT INTO events(title,status) VALUES ('Incomplete bulk Event','draft')");
$incompleteEventId = (int)$pdo->lastInsertId();
$dateRejected = false;
try {
    brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
        'resource'=>'events',
        'status'=>'published',
        'ids'=>[$incompleteEventId],
    ]));
} catch (InvalidArgumentException $e) {
    $dateRejected = $e->getMessage() === 'EVENT_DATE_REQUIRED';
}
bulk_it_assert($dateRejected, 'bulk publishing an Event without event_date must be rejected');
bulk_it_assert(
    (string)$pdo->query("SELECT status FROM events WHERE id={$incompleteEventId}")->fetchColumn() === 'draft',
    'rejected bulk publication must preserve draft status'
);

$pdo->prepare('UPDATE events SET event_date=? WHERE id=?')->execute(['2026-10-31 21:00:00', $incompleteEventId]);
$cityRejected = false;
try {
    brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
        'resource'=>'events',
        'status'=>'published',
        'ids'=>[$incompleteEventId],
    ]));
} catch (InvalidArgumentException $e) {
    $cityRejected = $e->getMessage() === 'EVENT_CITY_REQUIRED';
}
bulk_it_assert($cityRejected, 'bulk publishing an Event without city must be rejected');
bulk_it_assert(
    (string)$pdo->query("SELECT status FROM events WHERE id={$incompleteEventId}")->fetchColumn() === 'draft',
    'city validation failure must not modify Event status'
);

$pdo->prepare('UPDATE events SET city=? WHERE id=?')->execute(['Pereira', $incompleteEventId]);
brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource'=>'events',
    'status'=>'published',
    'ids'=>[$incompleteEventId],
]));
bulk_it_assert(
    (string)$pdo->query("SELECT status FROM events WHERE id={$incompleteEventId}")->fetchColumn() === 'published',
    'complete Event must publish through Bulk Actions'
);

$pdo->exec("INSERT INTO events(title,status,event_date,city) VALUES
    ('Bulk Event A','draft','2026-11-01 20:00:00','Pereira'),
    ('Bulk Event B','draft','2026-11-02 20:00:00','Pereira')");
$eventIds = $pdo->query("SELECT id FROM events WHERE title IN ('Bulk Event A','Bulk Event B') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$result = brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource' => 'events',
    'action' => 'set_status',
    'status' => 'published',
    'ids' => $eventIds,
]));
bulk_it_assert($result['matched'] === 2, 'events bulk action must match both selected rows');
bulk_it_assert((int)$pdo->query("SELECT COUNT(*) FROM events WHERE id IN (" . implode(',', array_map('intval', $eventIds)) . ") AND status='published'")->fetchColumn() === 2, 'events bulk action must publish both valid rows');
bulk_it_assert((int)$pdo->query("SELECT COUNT(*) FROM events WHERE id IN (" . implode(',', array_map('intval', $eventIds)) . ") AND published_at IS NOT NULL")->fetchColumn() === 2, 'bulk publishing Events must stamp published_at');
$firstPublishedAt = (string)$pdo->query('SELECT published_at FROM events WHERE id=' . (int)$eventIds[0])->fetchColumn();
brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource'=>'events',
    'status'=>'archived',
    'ids'=>[(int)$eventIds[0]],
]));
$archivedEvent = $pdo->query('SELECT status,published_at FROM events WHERE id=' . (int)$eventIds[0])->fetch(PDO::FETCH_ASSOC);
bulk_it_assert(($archivedEvent['status'] ?? '') === 'archived', 'published Event must support archive in bulk');
bulk_it_assert((string)($archivedEvent['published_at'] ?? '') === $firstPublishedAt, 'archiving an already public Event must preserve published_at');

$pdo->exec("INSERT INTO events(title,status,event_date,city) VALUES ('Complete draft archive','draft','2026-12-01 20:00:00','Pereira')");
$draftArchiveId = (int)$pdo->lastInsertId();
brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource'=>'events',
    'status'=>'archived',
    'ids'=>[$draftArchiveId],
]));
$draftArchivedPublishedAt = $pdo->query("SELECT published_at FROM events WHERE id={$draftArchiveId}")->fetchColumn();
bulk_it_assert($draftArchivedPublishedAt === null, 'bulk draft-to-archive must not invent publication history');

$pdo->exec("INSERT INTO releases(status,published_at) VALUES ('draft',NULL),('published','2026-01-02 03:04:05')");
$releaseIds = $pdo->query('SELECT id FROM releases ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource'=>'releases',
    'status'=>'published',
    'ids'=>[(int)$releaseIds[0]],
]));
$publishedRelease = $pdo->query('SELECT status,published_at FROM releases ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
bulk_it_assert(($publishedRelease['status'] ?? '') === 'published', 'bulk Release publish must update status');
bulk_it_assert(trim((string)($publishedRelease['published_at'] ?? '')) !== '', 'bulk Release publish must stamp published_at');
$releasePublishedAt = (string)$publishedRelease['published_at'];
brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource'=>'releases',
    'status'=>'archived',
    'ids'=>$releaseIds,
]));
bulk_it_assert((int)$pdo->query("SELECT COUNT(*) FROM releases WHERE status='archived'")->fetchColumn() === 2, 'releases must support archive in bulk');
bulk_it_assert((string)$pdo->query('SELECT published_at FROM releases ORDER BY id LIMIT 1')->fetchColumn() === $releasePublishedAt, 'archiving a Release must preserve its original published_at');
bulk_it_assert((string)$pdo->query('SELECT published_at FROM releases ORDER BY id DESC LIMIT 1')->fetchColumn() === '2026-01-02 03:04:05', 'bulk archive must preserve an existing Release published_at');

$pdo->exec("INSERT INTO blog_posts(status,published_at) VALUES ('draft',NULL)");
$blogId = (int)$pdo->lastInsertId();
brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource'=>'blog',
    'status'=>'published',
    'ids'=>[$blogId],
]));
$publishedBlog = $pdo->query("SELECT status,published_at FROM blog_posts WHERE id={$blogId}")->fetch(PDO::FETCH_ASSOC);
bulk_it_assert(($publishedBlog['status'] ?? '') === 'published', 'bulk Blog publish must update status');
bulk_it_assert(trim((string)($publishedBlog['published_at'] ?? '')) !== '', 'bulk Blog publish must stamp published_at');
$blogPublishedAt = (string)$publishedBlog['published_at'];
brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource'=>'blog',
    'status'=>'archived',
    'ids'=>[$blogId],
]));
bulk_it_assert((string)$pdo->query("SELECT published_at FROM blog_posts WHERE id={$blogId}")->fetchColumn() === $blogPublishedAt, 'archiving Blog must preserve its original published_at');

$invalidStatusRejected = false;
try {
    brvtal_bulk_normalize_request(['resource'=>'artists','status'=>'archived','ids'=>[1]]);
} catch (InvalidArgumentException $e) {
    $invalidStatusRejected = $e->getMessage() === 'INVALID_BULK_STATUS';
}
bulk_it_assert($invalidStatusRejected, 'artists must reject archive because it is not an allowed lifecycle state');

$pdo->exec("INSERT INTO pages(status) VALUES ('draft')");
$pageId = (int)$pdo->lastInsertId();
$rollbackVerified = false;
try {
    brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
        'resource'=>'pages',
        'status'=>'published',
        'ids'=>[$pageId,999999],
    ]));
} catch (RuntimeException $e) {
    $rollbackVerified = $e->getMessage() === 'BULK_ITEMS_NOT_FOUND';
}
bulk_it_assert($rollbackVerified, 'missing IDs must reject the entire bulk operation');
$afterRollback = (string)$pdo->query("SELECT status FROM pages WHERE id={$pageId}")->fetchColumn();
bulk_it_assert($afterRollback === 'draft', 'missing IDs must rollback and preserve the existing status');

$limitRejected = false;
try {
    brvtal_bulk_normalize_request(['resource'=>'blog','status'=>'draft','ids'=>range(1,101)]);
} catch (InvalidArgumentException $e) {
    $limitRejected = $e->getMessage() === 'BULK_LIMIT_EXCEEDED';
}
bulk_it_assert($limitRejected, 'more than 100 IDs must be rejected');

echo "BRVTAL Bulk Actions MariaDB integration tests passed.\n";
