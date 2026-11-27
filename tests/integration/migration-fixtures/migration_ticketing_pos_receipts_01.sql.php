<?php
declare(strict_types=1);
require_once __DIR__.'/../ticketing-pos-receipts.php';

return static function (PDO $server, string $dsn, string $user, string $pass): void {
    $name='brvtal_test_pos_receipts_'.bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    try {
        $db=new PDO($dsn.';dbname='.$name,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        receiptsFixture($db);
        $migration='migration_ticketing_pos_receipts_01.sql';
        $sql=(string)file_get_contents(__DIR__.'/../../../database/'.$migration);
        $proof=static fn():array=>brvtalMigrationSchemaProof($db,$migration);
        migrations_it_assert(brvtalMigrationProofDisposition($migration,$proof())==='apply','receipt storage absent');
        brvtalMigrationAssertReconciliationSql($migration,$sql,$proof());
        $db->exec($sql);
        migrations_it_assert($proof()['complete'],'receipt storage proof');
        receiptsExercise($db);
        $snapshot=static fn():array=>[
            $db->query('SELECT * FROM ticket_pos_payment_destinations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $db->query('SELECT * FROM ticket_pos_payment_receipts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
        $before=$snapshot(); $db->exec($sql);
        migrations_it_assert(brvtalMigrationProofDisposition($migration,$proof())==='baseline','receipt DDL repeated');
        migrations_it_assert($snapshot()===$before,'repeated migration preserves catalog and receipts');
    } finally { $server->exec('DROP DATABASE '.$name); }
};
