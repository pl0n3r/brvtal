<?php
declare(strict_types=1);

return static function (PDO $server, string $dsn, string $user, string $pass): void {
    $name = 'brvtal_test_rsvp_' . bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $db = new PDO($dsn . ';dbname=' . $name, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $root = __DIR__ . '/../../../database/';
        $db->exec('CREATE TABLE events (id INT UNSIGNED PRIMARY KEY, title VARCHAR(180) NOT NULL) ENGINE=InnoDB');
        $db->exec("INSERT INTO events VALUES (1,'Synthetic RSVP'),(2,'Other event'),(3,'No configuration')");
        foreach (['forms_entries', 'forms_registry'] as $part) {
            $db->exec((string)file_get_contents($root . 'migration_' . $part . '_01.sql'));
        }
        $now = '2026-11-01 00:00:00.000000';
        $until = '2026-12-01 00:00:00.000000';
        $definition = '{"fields":[]}';
        $digest = hash('sha256', $definition, true);
        $public = str_repeat('p', 16);
        $first = str_repeat('a', 16);
        $second = str_repeat('b', 16);
        $db->prepare('INSERT INTO form_definition_versions VALUES (?,?,?,?,?)')
            ->execute(['rsvp', 1, $digest, $definition, $now]);
        $db->prepare('INSERT INTO form_publication_policies VALUES (?,?,?,?,?,?,?)')
            ->execute(['rsvp', 1, $digest, 'fixture-notice', '{}', '{}', $now]);
        $db->prepare('INSERT INTO form_definition_registry VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute(['rsvp', $public, 'rsvp', 1, '{}', 'active', 1, $now, $now]);
        $entry = $db->prepare('INSERT INTO form_entries VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ([$first, $second] as $id) {
            $entry->execute([$id, 'rsvp', 1, $digest, hash('sha256', $id, true), '{}',
                $now, 'fixture', 'fixture-notice', 'fixture-policy', $until, 'received', null]);
        }
        $snapshot = static fn(): array => [
            $db->query('SELECT * FROM events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $db->query('SELECT * FROM form_entries ORDER BY entry_id')->fetchAll(PDO::FETCH_ASSOC),
            $db->query('SELECT * FROM form_definition_registry ORDER BY form_key')->fetchAll(PDO::FETCH_ASSOC),
            $db->query('SELECT * FROM form_publication_policies ORDER BY form_key,version_number')->fetchAll(PDO::FETCH_ASSOC),
        ];
        $before = $snapshot();
        $migration = 'migration_forms_rsvp_01.sql';
        $sql = (string)file_get_contents($root . $migration);
        $proof = static fn(): array => brvtalMigrationSchemaProof($db, $migration);
        $disposition = static fn(): string => brvtalMigrationProofDisposition($migration, $proof());
        migrations_it_assert($disposition() === 'apply', 'RSVP absent');
        brvtalMigrationAssertReconciliationSql($migration, $sql, $proof());
        // Artificial partial schema is recognized as blocked, not baselined.
        $db->exec(explode(';', $sql, 2)[0]);
        migrations_it_assert($disposition() === 'blocked', 'RSVP partial proof');
        $db->exec('DROP TABLE event_rsvp_forms');
        migrations_it_assert($disposition() === 'apply', 'RSVP absent after fixture reset');
        $db->exec($sql);
        migrations_it_assert($disposition() === 'baseline' && $proof()['complete'], 'RSVP complete proof');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM event_rsvp_forms')->fetchColumn() === 0,
            'existing events disabled by default');
        $configure = $db->prepare('INSERT INTO event_rsvp_forms VALUES (?,?,?)');
        $link = $db->prepare('INSERT INTO event_rsvp_entries VALUES (?,?)');
        $configure->execute([1, $public, 2]);
        $configure->execute([2, $public, null]);
        $link->execute([$first, 1]);
        $link->execute([$second, 1]);
        $reject = static function (PDOStatement $statement, array $values, array $codes): void {
            try {
                $statement->execute($values);
            } catch (PDOException $error) {
                migrations_it_assert(in_array((int)($error->errorInfo[1] ?? 0), $codes, true), 'RSVP expected constraint');
                return;
            }
            migrations_it_assert(false, 'invalid RSVP row accepted');
        };
        $reject($configure, [3, $public, 0], [4025, 3819]);
        $reject($configure, [3, $public, -1], [1264, 4025, 3819]);
        $reject($configure, [99, $public, null], [1452]);
        $reject($configure, [3, str_repeat('z', 16), null], [1452]);
        $reject($configure, [1, $public, null], [1062]);
        $reject($link, [$first, 2], [1062]);
        $reject($link, [str_repeat('z', 16), 1], [1452]);
        $reject($db->prepare('UPDATE event_rsvp_entries SET event_id=? WHERE entry_id=?'), [99, $first], [1452]);
        $db->exec($sql);
        migrations_it_assert($disposition() === 'baseline' && $snapshot() === $before, 'repeat preserves original data');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM event_rsvp_forms WHERE capacity=2 OR capacity IS NULL')->fetchColumn() === 2,
            'finite and unlimited configurations preserved');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM event_rsvp_entries WHERE event_id=1')->fetchColumn() === 2,
            'both links preserved');
        $db->exec('DELETE FROM event_rsvp_forms WHERE event_id=1');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM event_rsvp_entries WHERE event_id=1')->fetchColumn() === 2,
            'disable retains historical associations');
        $reject($db->prepare('DELETE FROM events WHERE id=?'), [1], [1451]);
        $reject($db->prepare('DELETE FROM form_entries WHERE entry_id=?'), [$first], [1451]);
        $reject($db->prepare('DELETE FROM form_definition_registry WHERE public_id=?'), [$public], [1451]);
        $db->prepare("UPDATE form_entries SET state='redacted',payload_json=NULL,redacted_at_utc=? WHERE entry_id=?")
            ->execute([$now, $first]);
        migrations_it_assert((int)$db->query("SELECT COUNT(*) FROM event_rsvp_entries r JOIN form_entries e ON e.entry_id=r.entry_id
            WHERE e.state='redacted' AND e.payload_json IS NULL")->fetchColumn() === 1, 'redaction preserves only opaque linkage');
        migrations_it_assert((int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME IN ('event_rsvp_forms','event_rsvp_entries')")->fetchColumn() === 5, 'no payload columns');
    } finally {
        $server->exec('DROP DATABASE ' . $name);
    }
    echo "BRVTAL RSVP schema MariaDB fixture passed.\n";
};
