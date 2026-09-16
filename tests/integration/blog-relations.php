<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/blog-relations.php';

function blog_rel_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "BLOG RELATIONS INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL Blog relations integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
blog_rel_it_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database name must start with brvtal_test');

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

foreach (['events','artists','sets_media','releases'] as $table) {
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS {$table}");
    $pdo->exec("CREATE TEMPORARY TABLE {$table} (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, label VARCHAR(80) NULL) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO {$table}(label) VALUES ('fixture')");
}

$relations = [
    ['related_type'=>'event','related_id'=>1],
    ['related_type'=>'artist','related_id'=>1],
    ['related_type'=>'set','related_id'=>1],
    ['related_type'=>'release','related_id'=>1],
    ['related_type'=>'artist','related_id'=>1],
];

$pdo->beginTransaction();
brvtal_blog_lock_relation_targets($pdo, $relations);
$pdo->commit();

$missingRejected = false;
$pdo->beginTransaction();
try {
    brvtal_blog_lock_relation_targets($pdo, [
        ['related_type'=>'artist','related_id'=>1],
        ['related_type'=>'release','related_id'=>999999],
    ]);
} catch (InvalidArgumentException $e) {
    $missingRejected = $e->getMessage() === 'BLOG_RELATION_NOT_FOUND';
    $pdo->rollBack();
}
blog_rel_it_assert($missingRejected, 'missing relation target must be rejected');
blog_rel_it_assert(!$pdo->inTransaction(), 'missing target path must be rollback-safe');

$invalidRejected = false;
try {
    brvtal_blog_lock_relation_targets($pdo, [['related_type'=>'unknown','related_id'=>1]]);
} catch (InvalidArgumentException $e) {
    $invalidRejected = $e->getMessage() === 'INVALID_BLOG_RELATION';
}
blog_rel_it_assert($invalidRejected, 'unknown relation type must be rejected by the integrity helper');

$zeroRejected = false;
try {
    brvtal_blog_lock_relation_targets($pdo, [['related_type'=>'event','related_id'=>0]]);
} catch (InvalidArgumentException $e) {
    $zeroRejected = $e->getMessage() === 'INVALID_BLOG_RELATION';
}
blog_rel_it_assert($zeroRejected, 'non-positive relation IDs must be rejected by the integrity helper');

echo "BRVTAL Blog relation integrity integration passed.\n";
