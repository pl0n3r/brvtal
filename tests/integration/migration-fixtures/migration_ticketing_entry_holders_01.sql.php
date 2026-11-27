<?php
declare(strict_types=1);

return static function (PDO $server, string $dsn, string $user, string $pass): void {
    $db = 'brvtal_test_holders_' . bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE ' . $db);
    $pdo = null;
    $other = null;
    try {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        $pdo = new PDO($dsn . ';dbname=' . $db, $user, $pass, $options);
        $other = new PDO($dsn . ';dbname=' . $db, $user, $pass, $options);
        migrations_it_assert($pdo->query('SELECT DATABASE()')->fetchColumn() === $db, 'isolated holder fixture');
        $directory = __DIR__ . '/../../../database';
        $name = 'migration_ticketing_entry_holders_01.sql';
        $sql = (string)file_get_contents($directory . '/' . $name);
        $pdo->exec('CREATE TABLE events (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY, event_id INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $pdo->exec((string)file_get_contents($directory . '/migration_ticketing_orders_01.sql'));
        $pdo->exec((string)file_get_contents($directory . '/migration_ticketing_entries_01.sql'));
        $pdo->exec('INSERT INTO events VALUES (1)');
        $pdo->exec('INSERT INTO event_ticket_types VALUES (1,1)');
        $pdo->exec("INSERT INTO ticket_buyers (id,public_id) VALUES (1,UNHEX(REPEAT('01',16))),(2,UNHEX(REPEAT('02',16))),(3,UNHEX(REPEAT('03',16)))");
        $pdo->exec("INSERT INTO ticket_orders (id,public_id,buyer_id,event_id,checkout_key_hash,status,total_cop) VALUES (1,UNHEX(REPEAT('11',16)),1,1,REPEAT('a',64),'paid',90000)");
        $pdo->exec("INSERT INTO ticket_order_items (id,order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop) VALUES (1,1,1,1,'Fixture',3,30000,90000)");
        $pdo->exec("INSERT INTO ticket_payment_attempts (id,order_id,provider,provider_reference,idempotency_key_hash,status,gross_amount_cop) VALUES (1,1,'fixture','fixture-payment',REPEAT('b',64),'approved',90000)");
        $now = '2026-01-01 00:00:00';
        $later = '2026-01-02 00:00:00';
        $entry = $pdo->prepare('INSERT INTO ticket_entries (id,public_id,order_item_id,event_id,ticket_type_id,unit_number,issued_at_utc) VALUES (?,?,1,1,1,?,?)');
        $credential = $pdo->prepare('INSERT INTO ticket_entry_credentials (entry_id,version,token_hash,created_at_utc) VALUES (?,1,?,?)');
        foreach ([1,2,3] as $id) {
            $entry->execute([$id, str_repeat(chr($id),16), $id, $now]);
            $credential->execute([$id, hash('sha256', 'synthetic-fixture-' . $id, true), $now]);
        }
        $snapshot = static fn(): array => array_map(
            static fn(string $table): array => $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(),
            ['ticket_orders','ticket_order_items','ticket_payment_attempts','ticket_entries','ticket_entry_credentials']
        );
        $before = $snapshot();
        $disposition = static fn(): string => brvtalMigrationProofDisposition($name, brvtalMigrationSchemaProof($pdo, $name));
        migrations_it_assert($disposition() === 'apply', 'holder schema absent, not legacy permission');
        brvtalMigrationAssertReconciliationSql($name, $sql, brvtalMigrationSchemaProof($pdo, $name));
        $parts = explode('CREATE TABLE IF NOT EXISTS ticket_entry_transfer_requests', $sql, 2);
        migrations_it_assert(count($parts) === 2, 'fixture identifies partial schema');
        $pdo->exec($parts[0]);
        migrations_it_assert($disposition() === 'blocked', 'partial holder schema fails closed');
        $pdo->exec($sql);
        migrations_it_assert($disposition() === 'baseline', 'full holder proof');
        $reject = static function (callable $operation, array $codes, string $reason): void {
            try {
                $operation();
            } catch (PDOException $exception) {
                migrations_it_assert(in_array((int)($exception->errorInfo[1] ?? 0), $codes, true), $reason);
                return;
            }
            migrations_it_assert(false, $reason . ': unexpectedly accepted');
        };
        $holder = $pdo->prepare('INSERT INTO ticket_entry_holders VALUES (?,?,?,?)');
        $holder->execute([1,2,1,$now]);
        $holder->execute([2,3,1,$now]);
        $reject(static fn() => $holder->execute([1,3,1,$now]), [1062], 'one holder per entry');
        $reject(static fn() => $holder->execute([3,2,0,$now]), [4025,3819], 'positive holder revision');
        $reject(static fn() => $holder->execute([99,2,1,$now]), [1452], 'unknown entry');
        $reject(static fn() => $holder->execute([3,99,1,$now]), [1452], 'unknown buyer');
        // This is a fixture-only legacy projection after complete schema proof and valid order join.
        $effective = static fn(): array => array_map('intval', $pdo->query(
            'SELECT COALESCE(h.buyer_id,o.buyer_id) FROM ticket_entries e JOIN ticket_order_items i ON i.id=e.order_item_id '
            . 'JOIN ticket_orders o ON o.id=i.order_id LEFT JOIN ticket_entry_holders h ON h.entry_id=e.id ORDER BY e.id'
        )->fetchAll(PDO::FETCH_COLUMN));
        migrations_it_assert($effective() === [2,3,1], 'independent holders on same order and legitimate legacy absence');
        $insertSql = 'INSERT INTO ticket_entry_transfer_requests (entry_id,from_buyer_id,to_buyer_id,expected_revision,requested_at_utc,expires_at_utc) VALUES (?,?,?,?,?,?)';
        $request = $pdo->prepare($insertSql);
        $competing = $other->prepare($insertSql);
        $other->exec('SET SESSION innodb_lock_wait_timeout=1');
        $pdo->beginTransaction();
        $request->execute([1,2,3,1,$now,$later]);
        // An independent connection competes while the unique generated key is locked.
        $reject(static fn() => $competing->execute([1,2,1,1,$now,$later]), [1205], 'concurrent request waits on unique key');
        $pdo->commit();
        $reject(static fn() => $competing->execute([1,2,1,1,$now,$later]), [1062], 'second pending request rejected');
        migrations_it_assert($effective() === [2,3,1], 'pending is not a transfer');
        $pdo->exec("UPDATE ticket_entry_transfer_requests SET status='rejected',resolved_at_utc='2026-01-01 01:00:00' WHERE entry_id=1");
        $request->execute([1,2,1,1,$now,$later]);
        $request->execute([3,1,2,0,$now,$later]);
        $reject(static fn() => $request->execute([2,3,3,1,$now,$later]), [4025,3819], 'self transfer rejected');
        $reject(static fn() => $request->execute([99,2,3,1,$now,$later]), [1452], 'orphan request entry');
        $reject(static fn() => $request->execute([2,99,3,1,$now,$later]), [1452], 'orphan sender');
        $reject(static fn() => $request->execute([2,3,99,1,$now,$later]), [1452], 'orphan recipient');
        $reject(static fn() => $request->execute([2,3,1,1,$later,$now]), [4025,3819], 'expiry follows request');
        $reject(static fn() => $pdo->exec("UPDATE ticket_entry_transfer_requests SET status='accepted' WHERE status='pending'"), [4025,3819], 'terminal request requires resolution timestamp');
        $reject(static fn() => $pdo->exec('DELETE FROM ticket_buyers WHERE id=2'), [1451], 'holder/request references restrict deletion');
        $pdo->exec("UPDATE ticket_entry_transfer_requests SET status='expired',resolved_at_utc='2026-01-02 00:00:00' WHERE entry_id=3");
        $request->execute([3,1,3,0,$now,$later]);
        $pdo->exec($sql);
        migrations_it_assert($disposition() === 'baseline', 'idempotent DDL proof');
        migrations_it_assert((int)$pdo->query('SELECT COUNT(*) FROM ticket_entry_transfer_requests')->fetchColumn() === 4, 'historical rejected and expired requests preserved');
        migrations_it_assert((int)$pdo->query("SELECT COUNT(*) FROM ticket_entry_transfer_requests WHERE status='pending'")->fetchColumn() === 2, 'one pending per entry');
        migrations_it_assert($effective() === [2,3,1] && $snapshot() === $before, 'order, buyer, item, price, payment, entry and QR unchanged');
        $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ticket_entry_holders','ticket_entry_transfer_requests')")->fetchAll(PDO::FETCH_COLUMN);
        migrations_it_assert(count($columns) === 14 && !array_filter($columns, static fn(string $column): bool => preg_match('/email|address|secret|token|qr|password/i', $column) === 1), 'no copied secret or personal profile columns');
        echo "BRVTAL entry holder schema MariaDB integration passed.\n";
    } finally {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $other = null;
        $pdo = null;
        $server->exec('DROP DATABASE ' . $db);
    }
};
