<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/public-related.php';

function related_it_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "RELATED CONTENT INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL Related Content integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
related_it_expect((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database name must start with brvtal_test');

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

$pdo->exec("CREATE TEMPORARY TABLE related_artists (
    id INT PRIMARY KEY,
    name VARCHAR(180),
    status VARCHAR(30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TEMPORARY TABLE related_events (
    id INT PRIMARY KEY,
    title VARCHAR(180),
    status VARCHAR(30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TEMPORARY TABLE related_sets (
    id INT PRIMARY KEY,
    title VARCHAR(180),
    artist_id INT NULL,
    event_id INT NULL,
    platform VARCHAR(30),
    external_url VARCHAR(700),
    status VARCHAR(30),
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("INSERT INTO related_artists VALUES
    (1,'PUBLIC ARTIST','published'),
    (2,'PRIVATE ARTIST','draft')");
$pdo->exec("INSERT INTO related_events VALUES
    (10,'PUBLIC EVENT','published'),
    (11,'PRIVATE EVENT','draft')");
$pdo->exec("INSERT INTO related_sets(id,title,artist_id,event_id,platform,external_url,status) VALUES
    (20,'PUBLIC LINKS',1,10,'soundcloud','https://example.com/public','published'),
    (21,'PRIVATE LINKS',2,11,'soundcloud','https://example.com/private','published')");

$rows = $pdo->query(
    "SELECT s.id,s.title,
            CASE WHEN a.id IS NULL THEN NULL ELSE s.artist_id END AS artist_id,
            s.event_id,s.platform,s.external_url,a.name AS artist_name,NULL AS event_title
     FROM related_sets s
     LEFT JOIN related_artists a ON a.id=s.artist_id AND a.status='published'
     WHERE s.status='published'
     ORDER BY s.sort_order ASC,s.created_at DESC"
)->fetchAll();

related_it_expect(count($rows) === 2, 'both published Sets must remain independently public');
related_it_expect((int)$rows[0]['artist_id'] === 1 || (int)$rows[1]['artist_id'] === 1, 'published artist relation must survive the SQL join');

$artists = [['id'=>1,'name'=>'PUBLIC ARTIST']];
$activeEvents = [['id'=>10,'title'=>'PUBLIC EVENT','lineup'=>[['artist_id'=>1]]]];
$archiveEvents = [];
$sets = brvtal_public_sanitize_set_relations($rows, $artists, $activeEvents, $archiveEvents);

$publicSet = null;
$privateSet = null;
foreach ($sets as $set) {
    if ((int)$set['id'] === 20) $publicSet = $set;
    if ((int)$set['id'] === 21) $privateSet = $set;
}
related_it_expect(is_array($publicSet) && is_array($privateSet), 'test Sets must be addressable by ID');
related_it_expect($publicSet['artist_id'] === 1 && $publicSet['artist_name'] === 'PUBLIC ARTIST', 'public artist relation must be preserved');
related_it_expect($publicSet['event_id'] === 10 && $publicSet['event_title'] === 'PUBLIC EVENT', 'public event relation must be resolved after lifecycle visibility');
related_it_expect($privateSet['artist_id'] === null && $privateSet['artist_name'] === null, 'private artist ID/name must be removed from public Set payload');
related_it_expect($privateSet['event_id'] === null && $privateSet['event_title'] === null, 'private event ID/title must be removed from public Set payload');

$releases = [['id'=>30,'title'=>'PUBLIC RELEASE','artists'=>[['artist_id'=>1,'name'=>'PUBLIC ARTIST']]]];
$graph = brvtal_public_related_graph($activeEvents, $archiveEvents, $artists, $sets, $releases);
related_it_expect($graph['events']['10']['artists'] === [1], 'event roster edge must be built from public lineup');
related_it_expect($graph['events']['10']['sets'] === [20], 'event Set edge must exclude sanitized private relation');
related_it_expect($graph['artists']['1']['sets'] === [20], 'artist Set edge must exclude sanitized private relation');
related_it_expect($graph['artists']['1']['releases'] === [30], 'artist Release edge must be built from public release artists');
related_it_expect($graph['sets']['21']['artist'] === null && $graph['sets']['21']['event'] === null, 'private relation IDs must not reappear in the graph');

echo "BRVTAL Related Content MariaDB integration tests passed.\n";
