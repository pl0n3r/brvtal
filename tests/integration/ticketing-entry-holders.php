<?php
// @brvtal-ci database
declare(strict_types=1);
require_once __DIR__ . '/../../config/ticketing_entry_holders.php';

function holderAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Entry holder fixture: '.$message);
}
function holderReject(callable $operation, string $code): void
{
    try { $operation(); } catch (RuntimeException|DomainException $e) {
        holderAssert($e->getMessage()===$code, 'expected '.$code);
        return;
    }
    throw new RuntimeException('Unexpected holder projection');
}
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1' || getenv('BRVTAL_TEST_DB_HOST') !== '127.0.0.1'
    || preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', (string)getenv('BRVTAL_TEST_DB_NAME')) !== 1) {
    throw new RuntimeException('Disposable loopback MariaDB required');
}
$port = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: '3306');
$dsn = "mysql:host=127.0.0.1;port={$port};charset=utf8mb4";
$user = (string)getenv('BRVTAL_TEST_DB_USER'); $pass = (string)getenv('BRVTAL_TEST_DB_PASS');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$server = new PDO($dsn,$user,$pass,$options);
$name = 'brvtal_test_holder_read_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE '.$name);
try {
    $db = new PDO($dsn.';dbname='.$name,$user,$pass,$options);
    holderAssert($db->query('SELECT DATABASE()')->fetchColumn()===$name, 'isolated database');
    $directory = __DIR__.'/../../database/';
    $db->exec('CREATE TABLE events (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('CREATE TABLE event_ticket_types (id INT UNSIGNED PRIMARY KEY,event_id INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $db->exec((string)file_get_contents($directory.'migration_ticketing_orders_01.sql'));
    $db->exec((string)file_get_contents($directory.'migration_ticketing_entries_01.sql'));
    $db->exec('INSERT INTO events VALUES (1),(2)');
    $db->exec('INSERT INTO event_ticket_types VALUES (1,1),(2,2)');
    $db->exec("INSERT INTO ticket_buyers (id,public_id,status,email_verified_at) VALUES
        (1,UNHEX(REPEAT('01',16)),'guest',NULL),(2,UNHEX(REPEAT('02',16)),'active',UTC_TIMESTAMP(6)),
        (3,UNHEX(REPEAT('03',16)),'blocked',UTC_TIMESTAMP(6))");
    $db->exec("INSERT INTO ticket_orders (id,public_id,buyer_id,event_id,checkout_key_hash,status,total_cop) VALUES
        (1,UNHEX(REPEAT('11',16)),1,1,REPEAT('a',64),'paid',180000),
        (2,UNHEX(REPEAT('22',16)),2,2,REPEAT('b',64),'paid',30000)");
    $db->exec("INSERT INTO ticket_order_items (id,order_id,event_id,ticket_type_id,ticket_name_snapshot,quantity,unit_price_cop,subtotal_cop) VALUES
        (1,1,1,1,'Fixture',6,30000,180000),(2,2,2,2,'Fixture',1,30000,30000)");
    $entry = $db->prepare('INSERT INTO ticket_entries (id,public_id,order_item_id,event_id,ticket_type_id,unit_number,status,issued_at_utc) VALUES (?,?,1,1,1,?,?,UTC_TIMESTAMP(6))');
    foreach (['issued','issued','issued','used','revoked','refunded'] as $i=>$state) {
        $id = $i+1; $entry->execute([$id,str_repeat(chr($id),16),$id,$state]);
    }
    $db->exec("INSERT INTO ticket_entry_credentials (entry_id,version,token_hash,created_at_utc) VALUES (1,1,UNHEX(REPEAT('12',32)),UTC_TIMESTAMP(6))");
    $read = static fn(int $id): array => brvtal_ticket_entry_holder($db,$id,1,1,1);
    holderReject(fn()=> $read(1),'ENTRY_HOLDER_UNAVAILABLE');
    $sql = (string)file_get_contents($directory.'migration_ticketing_entry_holders_01.sql');
    $parts = explode('CREATE TABLE IF NOT EXISTS ticket_entry_transfer_requests',$sql,2);
    holderAssert(count($parts)===2,'partial schema fixture');
    $db->exec($parts[0]);
    holderReject(fn()=> $read(1),'ENTRY_HOLDER_UNAVAILABLE');
    $db->exec($sql);
    $db->exec('INSERT INTO ticket_entry_holders VALUES (1,2,1,UTC_TIMESTAMP(6)),(2,3,4,UTC_TIMESTAMP(6)),(4,2,2,UTC_TIMESTAMP(6))');
    $db->exec("INSERT INTO ticket_entry_transfer_requests (entry_id,from_buyer_id,to_buyer_id,expected_revision,requested_at_utc,expires_at_utc)
        VALUES (3,1,2,0,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 DAY)");
    $snapshot = static fn(): array => array_map(static fn(string $table): array => $db->query('SELECT * FROM '.$table.' ORDER BY '.($table==='ticket_entry_holders'?'entry_id':'id'))->fetchAll(),
        ['ticket_orders','ticket_order_items','ticket_entries','ticket_entry_credentials','ticket_entry_holders','ticket_entry_transfer_requests']);
    $before = $snapshot();
    $a = $read(1); $b = $read(2); $legacy = $read(3);
    holderAssert($a['financial_buyer_id']===1 && $a['holder_buyer_id']===2 && $a['holder_revision']===1
        && $a['holder_source']==='explicit' && $a['holder_verified'] && $a['credential_state_eligible'],'explicit holder separate from original buyer');
    holderAssert($b['financial_buyer_id']===1 && $b['holder_buyer_id']===3 && $b['holder_revision']===4
        && $b['holder_account_status']==='blocked','distinct units and blocked account are not authorization');
    holderAssert($legacy['holder_buyer_id']===1 && $legacy['holder_revision']===0 && $legacy['holder_source']==='legacy'
        && $legacy['holder_account_status']==='guest' && !$legacy['holder_verified'],'guest legacy, pending request does not transfer');
    foreach ([4=>'used',5=>'revoked',6=>'refunded'] as $id=>$status) {
        $row = $read($id);
        holderAssert($row['entry_status']===$status && !$row['credential_state_eligible'],'terminal state is not credential eligibility');
    }
    holderAssert(array_keys($a)===['entry_id','event_id','order_id','order_item_id','financial_buyer_id','holder_buyer_id',
        'holder_revision','holder_source','holder_account_status','holder_verified','entry_status','order_status','credential_state_eligible'],'minimal projection only');
    foreach ([[1,2,1,1],[1,1,2,1],[1,1,1,2],[99,1,1,1],[0,1,1,1]] as $scope) {
        holderReject(fn()=> brvtal_ticket_entry_holder($db,...$scope),'ENTRY_HOLDER_SCOPE_INVALID');
    }
    holderAssert($snapshot()===$before,'projection leaves financial, transfer and credential rows unchanged');
    $db->exec("UPDATE ticket_orders SET status='refunded' WHERE id=1");
    holderAssert(!$read(1)['credential_state_eligible'],'non-paid order cannot be credential eligible');
    $db->exec('UPDATE ticket_entries SET unit_number=99 WHERE id=3');
    holderReject(fn()=> $read(3),'ENTRY_HOLDER_SCOPE_INVALID');
    $db->exec('UPDATE ticket_entries SET ticket_type_id=2 WHERE id=1');
    holderReject(fn()=> $read(1),'ENTRY_HOLDER_SCOPE_INVALID');
    $db->exec('UPDATE ticket_entries SET ticket_type_id=1 WHERE id=1');
    // Corruption fixture only: an explicit orphan must not fall back to buyer 1.
    $db->exec('SET FOREIGN_KEY_CHECKS=0');
    $db->exec('UPDATE ticket_entry_holders SET buyer_id=99 WHERE entry_id=1');
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    holderReject(fn()=> $read(1),'ENTRY_HOLDER_SCOPE_INVALID');
} finally {
    $db = null;
    $server->exec('DROP DATABASE '.$name);
}
echo "BRVTAL_MARKED_DATABASE_PASS: ticketing-entry-holders.php\n";
