<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/admin-read-plan.php';

function setsPageAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ADMIN SETS PAGINATION INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL Sets pagination integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
setsPageAssert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database name must start with brvtal_test');
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    $dbName
);
$pdo = new PDO(
    $dsn,
    (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'),
    (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
);

$pdo->exec('DROP TEMPORARY TABLE IF EXISTS sets_media');
$pdo->exec(
    'CREATE TEMPORARY TABLE sets_media ('
    . 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,'
    . 'title VARCHAR(180) NOT NULL,slug VARCHAR(190) NOT NULL UNIQUE,'
    . 'artist_id INT UNSIGNED NULL,event_id INT UNSIGNED NULL,'
    . 'platform VARCHAR(20) NOT NULL DEFAULT "soundcloud",'
    . 'external_url VARCHAR(700) NOT NULL,embed_url VARCHAR(700) NULL,'
    . 'cover_image VARCHAR(255) NULL,description TEXT NULL,'
    . 'status VARCHAR(20) NOT NULL DEFAULT "draft",sort_order INT NOT NULL DEFAULT 0,'
    . 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$insert = $pdo->prepare(
    'INSERT INTO sets_media(title,slug,artist_id,event_id,external_url,description,status,sort_order) VALUES(?,?,?,?,?,?,?,?)'
);
for ($index = 1; $index <= 130; $index++) {
    $insert->execute([
        "Set {$index}",
        "set-{$index}",
        ($index % 5) + 1,
        ($index % 7) + 1,
        "https://example.test/sets/{$index}",
        str_repeat("wide-description-{$index}-", 300),
        $index % 3 === 0 ? 'published' : 'draft',
        131 - $index,
    ]);
}

$pagePlan = brvtalAdminCollectionPagination('sets', ['page'=>'2','page_size'=>'50']);
setsPageAssert(is_array($pagePlan) && $pagePlan['error'] === null, 'page plan must be valid');
$pagination = brvtalAdminCollectionPaginationMeta(2, 50, 130);
$rows = brvtalAdminFetchSetsPage($pdo, $pagePlan, $pagination);
setsPageAssert(count($rows) === 50, 'second page must materialize exactly 50 full rows');
setsPageAssert((int)$rows[0]['sort_order'] === 51, 'second page must begin at canonical sort position 51');
setsPageAssert((int)$rows[49]['sort_order'] === 100, 'second page must end at canonical sort position 100');
setsPageAssert(strlen((string)$rows[0]['description']) > 1000, 'full wide Set records must survive materialization');

for ($i = 1, $count = count($rows); $i < $count; $i++) {
    $previous = $rows[$i - 1];
    $current = $rows[$i];
    $ordered = (int)$previous['sort_order'] < (int)$current['sort_order']
        || ((int)$previous['sort_order'] === (int)$current['sort_order'] && (int)$previous['id'] <= (int)$current['id']);
    setsPageAssert($ordered, 'materialized rows must retain sort_order,id ordering');
}

$searchPlan = brvtalAdminCollectionPagination('sets', ['page'=>'1','page_size'=>'50','q'=>'Set 12']);
setsPageAssert(is_array($searchPlan) && $searchPlan['error'] === null, 'search page plan must be valid');
$countStatement = $pdo->prepare('SELECT COUNT(*) FROM sets_media' . $searchPlan['where_sql']);
$countStatement->execute($searchPlan['params']);
$searchTotal = (int)$countStatement->fetchColumn();
$searchRows = brvtalAdminFetchSetsPage($pdo, $searchPlan, brvtalAdminCollectionPaginationMeta(1, 50, $searchTotal));
setsPageAssert($searchTotal > 0 && count($searchRows) === $searchTotal, 'search pagination must preserve matching rows');

echo "BRVTAL Sets late-materialization integration tests passed.\n";
