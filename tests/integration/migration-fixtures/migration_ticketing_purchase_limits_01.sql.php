<?php
declare(strict_types=1);

return static function (PDO $server, string $dsn, string $user, string $pass): void {
    $dbName = 'brvtal_test_purchase_limits_' . bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE ' . $dbName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $db = new PDO($dsn . ';dbname=' . $dbName, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec('CREATE TABLE events (id INT UNSIGNED PRIMARY KEY, title VARCHAR(180) NOT NULL)');
        $db->exec('CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL, price DECIMAL(12,2) NULL,
            FOREIGN KEY (event_id) REFERENCES events(id))');
        $db->exec("INSERT INTO events VALUES (1,'Synthetic event'),(2,'Another synthetic event')");
        $db->exec('INSERT INTO event_ticket_types VALUES (10,1,20000),(11,1,35000),(12,2,25000)');
        $migration = 'migration_ticketing_purchase_limits_01.sql';
        $sql = (string)file_get_contents(__DIR__ . '/../../../database/' . $migration);
        $proof = static fn(): array => brvtalMigrationSchemaProof($db, $migration);
        migrations_it_assert(brvtalMigrationProofDisposition($migration, $proof()) === 'apply', 'purchase limits absent');
        brvtalMigrationAssertReconciliationSql($migration, $sql, $proof());
        $db->exec($sql);
        migrations_it_assert($proof()['complete'], 'purchase limits unsigned nullable proof');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM events WHERE max_per_buyer IS NULL')->fetchColumn() === 2,
            'legacy event defaults');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM event_ticket_types WHERE min_per_order IS NULL AND max_per_order IS NULL')->fetchColumn() === 3,
            'legacy type defaults');
        $db->exec('UPDATE events SET max_per_buyer=10 WHERE id=1');
        $db->exec('UPDATE event_ticket_types SET min_per_order=2,max_per_order=4 WHERE id=10');
        $db->exec('UPDATE event_ticket_types SET max_per_order=6 WHERE id=11');
        $db->exec($sql);
        migrations_it_assert(brvtalMigrationProofDisposition($migration, $proof()) === 'baseline', 'repeat DDL complete');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM events WHERE id=1 AND max_per_buyer=10 AND title=\'Synthetic event\'')->fetchColumn() === 1,
            'repeat DDL preserves buyer limit and title');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM event_ticket_types WHERE id=10 AND min_per_order=2 AND max_per_order=4 AND price=20000')->fetchColumn() === 1,
            'repeat DDL preserves both type limits and price');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM event_ticket_types WHERE id=11 AND min_per_order IS NULL AND max_per_order=6 AND price=35000')->fetchColumn() === 1,
            'repeat DDL preserves independently nullable bounds');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM event_ticket_types WHERE id=12 AND min_per_order IS NULL AND max_per_order IS NULL AND price=25000')->fetchColumn() === 1,
            'other event remains unchanged');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM events WHERE id=2 AND max_per_buyer IS NULL')->fetchColumn() === 1,
            'other event buyer limit remains NULL');
        inventoryPurchaseLimitsContract();
        $limits = [];
        foreach ($db->query('SELECT id,min_per_order,max_per_order FROM event_ticket_types WHERE event_id=1')->fetchAll() as $row) {
            $limits[(int)$row['id']] = [
                'min_per_order' => $row['min_per_order'] === null ? null : (int)$row['min_per_order'],
                'max_per_order' => $row['max_per_order'] === null ? null : (int)$row['max_per_order'],
            ];
        }
        $buyerMax = (int)$db->query('SELECT max_per_buyer FROM events WHERE id=1')->fetchColumn();
        migrations_it_assert(brvtal_ticket_inventory_purchase_limits($limits, [10 => 4, 11 => 2], $buyerMax, 4)['allowed'],
            'persisted limits allow exact buyer boundary');
        migrations_it_assert(brvtal_ticket_inventory_purchase_limits($limits, [10 => 4, 11 => 2], $buyerMax, 5)['reason'] === 'above_buyer_max',
            'persisted limits reject cumulative excess');
    } finally {
        $server->exec('DROP DATABASE ' . $dbName);
    }
    echo "BRVTAL purchase limits migration and policy MariaDB integration passed.\n";
};
