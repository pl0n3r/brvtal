<?php
// @brvtal-ci database
declare(strict_types=1);

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1'
    || getenv('BRVTAL_TEST_DB_HOST') !== '127.0.0.1'
    || preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', (string) getenv('BRVTAL_TEST_DB_NAME')) !== 1) {
    exit(1);
}
try {
    $port = (int) (getenv('BRVTAL_TEST_DB_PORT') ?: '3306');
    $name = getenv('BRVTAL_TEST_DB_NAME');
    $pdo = new PDO("mysql:host=127.0.0.1;port={$port};dbname={$name};charset=utf8mb4",
        (string) getenv('BRVTAL_TEST_DB_USER'), (string) getenv('BRVTAL_TEST_DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ((int) $pdo->query('SELECT 1')->fetchColumn() !== 1) {
        exit(1);
    }
    echo "BRVTAL_MARKED_DATABASE_PASS: marked-discovery-smoke.php\n";
} catch (Throwable) {
    exit(1);
}
