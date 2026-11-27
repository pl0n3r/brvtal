<?php
declare(strict_types=1);

return static function (PDO $server,string $dsn,string $user,string $pass): void {
    $name='brvtal_test_promoters_'.bin2hex(random_bytes(6));
    $server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    try {
        $db=new PDO($dsn.';dbname='.$name,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false]);
        $db->exec('CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY,total_cop BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $db->exec('INSERT INTO ticket_orders VALUES (1,10000),(2,20000)');
        $migration='migration_ticketing_promoters_01.sql';
        $sql=(string)file_get_contents(__DIR__.'/../../../database/'.$migration);
        $proof=static fn():array=>brvtalMigrationSchemaProof($db,$migration);
        migrations_it_assert(brvtalMigrationProofDisposition($migration,$proof())==='apply','promoter schema absent');
        brvtalMigrationAssertReconciliationSql($migration,$sql,$proof());
        $db->exec($sql);
        migrations_it_assert($proof()['complete'],'promoter schema proof');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM ticket_orders WHERE promoter_code_id IS NULL')->fetchColumn()===2,'legacy attribution nullable');
        $db->exec("INSERT INTO promoters(id,promoter_key) VALUES (1,'FIXTURE1')");
        $db->exec("INSERT INTO promoter_codes(id,promoter_id,code) VALUES (1,1,'REFTEST1')");
        $db->exec('UPDATE ticket_orders SET promoter_code_id=1 WHERE id=1');
        $db->exec($sql);
        migrations_it_assert(brvtalMigrationProofDisposition($migration,$proof())==='baseline','promoter DDL repeated');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM ticket_orders WHERE id=1 AND promoter_code_id=1 AND total_cop=10000')->fetchColumn()===1,'attribution and totals preserved');
        migrations_it_assert((int)$db->query('SELECT COUNT(*) FROM ticket_orders WHERE id=2 AND promoter_code_id IS NULL AND total_cop=20000')->fetchColumn()===1,'unattributed history preserved');
        foreach (["INSERT INTO promoter_codes(promoter_id,code) VALUES (1,'REFTEST1')",
            'UPDATE ticket_orders SET promoter_code_id=999 WHERE id=2'] as $invalid) {
            try { $db->exec($invalid); throw new RuntimeException('invalid attribution constraint accepted'); }
            catch (PDOException $e) { migrations_it_assert($e->getCode()==='23000','unique/FK rejection'); }
        }
    } finally { $server->exec('DROP DATABASE '.$name); }
};
