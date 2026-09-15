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
$pdo->exec("CREATE TEMPORARY TABLE events (id INT AUTO_INCREMENT PRIMARY KEY,status VARCHAR(30) NOT NULL DEFAULT 'draft',published_at DATETIME NULL,cancelled_at DATETIME NULL,finished_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach (['artists','sets_media','pages','releases','blog_posts'] as $table) {
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$table}");
    $pdo->exec("CREATE TEMPORARY TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY,status VARCHAR(30) NOT NULL DEFAULT 'draft') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$pdo->exec("INSERT INTO events(status) VALUES ('draft'),('draft')");
$eventIds = $pdo->query('SELECT id FROM events ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$result = brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource' => 'events',
    'action' => 'set_status',
    'status' => 'published',
    'ids' => $eventIds,
]));
bulk_it_assert($result['matched'] === 2, 'events bulk action must match both selected rows');
bulk_it_assert((int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='published'")->fetchColumn() === 2, 'events bulk action must publish both rows');
bulk_it_assert((int)$pdo->query("SELECT COUNT(*) FROM events WHERE published_at IS NOT NULL")->fetchColumn() === 2, 'bulk publishing Events must stamp published_at');
$firstPublishedAt = (string)$pdo->query('SELECT published_at FROM events ORDER BY id LIMIT 1')->fetchColumn();
brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource'=>'events',
    'status'=>'archived',
    'ids'=>[(int)$eventIds[0]],
]));
$archivedEvent = $pdo->query('SELECT status,published_at FROM events ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
bulk_it_assert(($archivedEvent['status'] ?? '') === 'archived', 'published Event must support archive in bulk');
bulk_it_assert((string)($archivedEvent['published_at'] ?? '') === $firstPublishedAt, 'archiving an already public Event must preserve published_at');
$pdo->exec("INSERT INTO events(status) VALUES ('draft')");
$draftArchiveId = (int)$pdo->lastInsertId();
brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource'=>'events',
    'status'=>'archived',
    'ids'=>[$draftArchiveId],
]));
$draftArchivedPublishedAt = $pdo->query("SELECT published_at FROM events WHERE id={$draftArchiveId}")->fetchColumn();
bulk_it_assert($draftArchivedPublishedAt === null, 'bulk draft-to-archive must not invent publication history');

$pdo->exec("INSERT INTO releases(status) VALUES ('draft'),('published')");
$releaseIds = $pdo->query('SELECT id FROM releases ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
brvtal_bulk_apply($pdo, brvtal_bulk_normalize_request([
    'resource' => 'releases',
    'status' => 'archived',
    'ids' => $releaseIds,
]));
bulk_it_assert((int)$pdo->query("SELECT COUNT(*) FROM releases WHERE status='archived'")->fetchColumn() === 2, 'releases must support archive in bulk');

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
