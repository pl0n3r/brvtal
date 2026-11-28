<?php
declare(strict_types=1);

return static function (PDO $server, string $dsn, string $user, string $pass): void {
    $name = 'brvtal_test_marketing_' . bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $db = new PDO($dsn . ';dbname=' . $name, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $migration = 'migration_marketing_consent_01.sql';
        $sql = (string)file_get_contents(__DIR__ . '/../../../database/' . $migration);
        $proof = static fn(): array => brvtalMigrationSchemaProof($db, $migration);
        migrations_it_assert(brvtalMigrationProofDisposition($migration,$proof()) === 'apply', 'marketing table absent');
        brvtalMigrationAssertReconciliationSql($migration,$sql,$proof());
        $db->exec($sql);
        migrations_it_assert($proof()['complete'], 'marketing schema proof complete');
        $insert = $db->prepare("INSERT INTO marketing_consents(email,consent_version,origin,unsubscribe_hash) VALUES ('fixture@example.test','v1','registration',?)");
        $insert->execute([str_repeat('a',64)]);
        $reject = static function (callable $operation, string $message): void {
            try {
                $operation();
            } catch (PDOException $e) {
                migrations_it_assert($e->getCode() === '23000', $message);
                return;
            }
            throw new RuntimeException($message);
        };
        $reject(static fn() => $insert->execute([str_repeat('b',64)]), 'active email uniqueness');
        $reject(static fn() => $db->exec('UPDATE marketing_consents SET active_slot=NULL'), 'active slot cannot bypass revocation');
        $db->exec('UPDATE marketing_consents SET revoked_at_utc=UTC_TIMESTAMP(6),active_slot=NULL');
        $insert->execute([str_repeat('b',64)]);
        $before = $db->query('SELECT * FROM marketing_consents ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $db->exec($sql);
        migrations_it_assert(brvtalMigrationProofDisposition($migration,$proof()) === 'baseline', 'marketing repeat baseline');
        migrations_it_assert($before === $db->query('SELECT * FROM marketing_consents ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'marketing repeat preserves historical grants and revocation');
        $db->exec('ALTER TABLE marketing_consents DROP INDEX uq_marketing_unsubscribe');
        migrations_it_assert(!$proof()['complete'], 'partial marketing schema cannot pass proof');
    } finally {
        $server->exec('DROP DATABASE ' . $name);
    }
    echo "BRVTAL marketing consent migration MariaDB integration passed.\n";
};
