<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api/public-archive.php';

function archive_it_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC ARCHIVE INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL public archive integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
archive_it_expect((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database name must start with brvtal_test');

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

$pdo->exec("CREATE TEMPORARY TABLE archive_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180),
    event_date DATETIME NULL,
    archive_year SMALLINT UNSIGNED NULL,
    status VARCHAR(30),
    sort_order INT NOT NULL DEFAULT 0,
    ticket_url VARCHAR(700) NULL,
    ticket_instructions TEXT NULL,
    ticket_qr VARCHAR(500) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$insert = $pdo->prepare('INSERT INTO archive_events(title,event_date,archive_year,status,ticket_url,ticket_instructions,ticket_qr) VALUES(?,?,?,?,?,?,?)');
$insert->execute(['Future Published','2026-10-01 21:00:00',2026,'published','https://example.com/future','pay','/future.png']);
$insert->execute(['Tickets Available','2026-09-20 21:00:00',2026,'tickets_available','https://example.com/tickets','pay','/tickets.png']);
$insert->execute(['Past Published','2026-08-07 21:00:00',null,'published','https://example.com/past','pay','/past.png']);
$insert->execute(['Finished','2026-07-01 21:00:00',2026,'finished','https://example.com/finished','pay','/finished.png']);
$insert->execute(['Cancelled','2026-12-01 21:00:00',2026,'cancelled','https://example.com/cancelled','pay','/cancelled.png']);
$insert->execute(['Private Draft','2026-12-20 21:00:00',2026,'draft','https://example.com/draft','pay','/draft.png']);

$sql = "SELECT id,title,event_date,archive_year,status,sort_order,ticket_url,ticket_instructions,ticket_qr
        FROM archive_events
        WHERE status IN ('published','upcoming','tickets_available','last_tickets','sold_out','cancelled','finished','archived')
        ORDER BY event_date ASC,sort_order ASC,id ASC";
$rows = $pdo->query($sql)->fetchAll();
archive_it_expect(count($rows) === 5, 'visibility query must include all public lifecycle rows and exclude draft');
archive_it_expect(!in_array('draft', array_column($rows,'status'), true), 'draft leaked from public visibility query');
archive_it_expect(in_array('tickets_available', array_column($rows,'status'), true), 'tickets_available must remain public');
archive_it_expect(in_array('finished', array_column($rows,'status'), true), 'finished event must remain discoverable');

foreach ($rows as &$row) $row['ticket_types'] = [['id'=>1,'status'=>'active']];
unset($row);
$partition = brvtal_public_partition_events($rows, new DateTimeImmutable('2026-09-11 12:00:00'));

archive_it_expect(array_column($partition['active'],'title') === ['Tickets Available','Future Published'], 'active lifecycle partition is incorrect');
archive_it_expect(array_column($partition['archive'],'title') === ['Cancelled','Past Published','Finished'], 'archive lifecycle partition is incorrect');

foreach ($partition['archive'] as $event) {
    archive_it_expect($event['ticket_url'] === null, 'archived ticket_url must be removed');
    archive_it_expect($event['ticket_instructions'] === null, 'archived payment instructions must be removed');
    archive_it_expect($event['ticket_qr'] === null, 'archived QR must be removed');
    archive_it_expect($event['ticket_types'] === [], 'archived ticket types must be removed');
}

archive_it_expect($partition['years'] === [2026], 'archive year index must be generated from historical events');

echo "BRVTAL Public Archive MariaDB integration tests passed.\n";
