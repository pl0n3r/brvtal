<?php
declare(strict_types=1);

return static function (PDO $server, string $dsn, string $user, string $pass): void {
    $name = 'brvtal_test_forms_management_' . bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $db = new PDO($dsn . ';dbname=' . $name, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $root = __DIR__ . '/../../../database/';
        $db->exec((string)file_get_contents($root . 'migration_forms_entries_01.sql'));
        $migration = 'migration_forms_management_01.sql';
        $sql = (string)file_get_contents($root . $migration);
        $proof = static fn(): array => brvtalMigrationSchemaProof($db, $migration);
        migrations_it_assert(brvtalMigrationProofDisposition($migration, $proof()) === 'apply', 'management absent');
        brvtalMigrationAssertReconciliationSql($migration, $sql, $proof());
        $db->exec($sql);
        migrations_it_assert($proof()['complete'], 'complete management proof');

        $id = str_repeat('a', 16);
        $created = '2026-11-01 00:00:00.000000';
        $until = '2026-12-01 00:00:00.000000';
        $definition = '{"fields":["subject"]}';
        $digest = hash('sha256', $definition, true);
        $db->prepare('INSERT INTO form_definition_versions VALUES (?,?,?,?,?)')
            ->execute(['contact', 1, $digest, $definition, $created]);
        $db->prepare('INSERT INTO form_entries VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $id, 'contact', 1, $digest, str_repeat('k', 32), '{"subject":"synthetic"}',
            $created, 'fixture', 'fixture-notice', 'fixture-policy', $until, 'received', null,
        ]);
        $db->prepare('INSERT INTO form_notification_intents VALUES (?,?,?,?)')
            ->execute([$id, 'notify', 'pending', $created]);
        $snapshot = static fn(): array => [
            $db->query('SELECT * FROM form_entries')->fetchAll(PDO::FETCH_ASSOC),
            $db->query('SELECT * FROM form_notification_intents')->fetchAll(PDO::FETCH_ASSOC),
        ];
        $before = $snapshot();
        $insert = $db->prepare('INSERT INTO form_entry_management VALUES (?,?,?,?,?,?,?)');
        $insert->execute([$id, 1, 'in_progress', 'transition', 1, $created, null]);
        $insert->execute([$id, 2, 'in_progress', 'note', 1, $created, 'Synthetic internal note']);
        $reject = static function (array $values) use ($insert): void {
            try {
                $insert->execute($values);
            } catch (PDOException $exception) {
                migrations_it_assert(in_array((int)($exception->errorInfo[1] ?? 0),
                    [1062, 1406, 1452, 4025, 3819], true), 'expected management constraint');
                return;
            }
            migrations_it_assert(false, 'invalid management record accepted');
        };
        $reject([$id, 2, 'resolved', 'transition', 1, $created, null]);
        $reject([$id, 0, 'resolved', 'transition', 1, $created, null]);
        $reject([$id, 3, 'resolved', 'transition', 0, $created, null]);
        $reject([$id, 3, 'redacted', 'transition', 1, $created, null]);
        $reject([$id, 3, 'spam', 'unknown', 1, $created, null]);
        $reject([$id, 3, 'archived', 'transition', 1, $created, 'not allowed']);
        $reject([$id, 3, 'resolved', 'note', 1, $created, '  ']);
        $reject([$id, 3, 'resolved', 'note', 1, $created, str_repeat('x', 1001)]);
        $reject([str_repeat('z', 16), 1, 'new', 'note', 1, $created, 'orphan']);
        $db->exec($sql);
        migrations_it_assert(brvtalMigrationProofDisposition($migration, $proof()) === 'baseline', 'repeat DDL');
        migrations_it_assert($snapshot() === $before, 'capture and notification unchanged');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM form_entry_management')->fetchColumn() === 2,
            'both revisions preserved');
        try {
            $db->exec('DELETE FROM form_entries');
            migrations_it_assert(false, 'entry deletion must be restricted');
        } catch (PDOException $exception) {
            migrations_it_assert((int)($exception->errorInfo[1] ?? 0) === 1451, 'no cascading history deletion');
        }
        // Demonstrate the schema supports selective note suppression without losing audit identity.
        $db->exec("UPDATE form_entry_management SET note_text=NULL WHERE action_kind='note'");
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM form_entry_management WHERE note_text IS NULL')->fetchColumn() === 2,
            'note text may be cleared, revision retained');
        migrations_it_assert($snapshot() === $before, 'note clearing does not rewrite capture');
    } finally {
        $server->exec('DROP DATABASE ' . $name);
    }
    echo "BRVTAL forms management schema MariaDB fixture passed.\n";
};
