<?php
declare(strict_types=1);
// @brvtal-ci database
require_once __DIR__.'/../../config/ticketing_pos_receipts.php';

function receiptsCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('POS receipt fixture: '.$message);
}
function receiptsReject(callable $call, string $code): void {
    try { $call(); } catch (LogicException $e) {
        receiptsCheck($e->getMessage()===$code, 'unexpected error'); return;
    }
    throw new RuntimeException('Expected '.$code);
}
function receiptsFixture(PDO $db): void {
    $db->exec('CREATE TABLE admins (id INT UNSIGNED PRIMARY KEY,staff_role VARCHAR(32) NOT NULL,is_active TINYINT NOT NULL) ENGINE=InnoDB');
    $db->exec("INSERT INTO admins VALUES (1,'owner',1),(2,'superadmin',1),(3,'owner',0)");
    $db->exec('CREATE TABLE ticket_orders (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('INSERT INTO ticket_orders VALUES (1),(2),(3),(4)');
}
function receiptsExercise(PDO $db): void {
    $a=brvtalPosDestinationCreate($db,1,'transfer','Cuenta ···1234');
    $b=brvtalPosDestinationCreate($db,1,'transfer','Cuenta ···5678');
    $c=brvtalPosDestinationCreate($db,1,'terminal','Datáfono barra');
    receiptsReject(fn()=>brvtalPosDestinationCreate($db,2,'transfer','Cuenta'), 'POS_DESTINATION_FORBIDDEN');
    receiptsReject(fn()=>brvtalPosDestinationDeactivate($db,3,$a), 'POS_DESTINATION_FORBIDDEN');
    receiptsReject(fn()=>brvtalPosDestinationCreate($db,1,'transfer','Cuenta 1234 5678'), 'POS_DESTINATION_ALIAS');
    receiptsReject(fn()=>brvtalPosDestinationCreate($db,1,'cash','Caja'), 'POS_RECEIPT_METHOD');
    $hash=str_repeat('a',64);
    receiptsReject(fn()=>brvtalPosReceiptRegister($db,1,'transfer',$a,'ref-01',$hash),'POS_RECEIPT_TRANSACTION');
    $db->beginTransaction();
    brvtalPosReceiptRegister($db,1,'transfer',$a,' ref-01  a ',$hash);
    brvtalPosReceiptRegister($db,2,'transfer',$b,'REF-01 A',$hash);
    brvtalPosReceiptRegister($db,3,'terminal',$c,'REF-01 A',$hash);
    $db->commit();
    $db->beginTransaction();
    try {
        brvtalPosReceiptRegister($db,4,'transfer',$a,'REF-01 A',$hash);
        throw new RuntimeException('Duplicate accepted');
    } catch (PDOException $e) { receiptsCheck((int)$e->errorInfo[1]===1062,'unique scope'); }
    finally { $db->rollBack(); }
    $db->beginTransaction();
    receiptsReject(fn()=>brvtalPosReceiptRegister($db,4,'terminal',$a,'REF-02',$hash),'POS_DESTINATION_UNAVAILABLE');
    brvtalPosReceiptRegister($db,4,'transfer',$a,'REF-01-A',$hash);
    $db->rollBack();
    receiptsCheck(brvtalPosReceiptFind($db,'transfer',$a,'REF-01-A')===null,'rollback');
    brvtalPosDestinationDeactivate($db,1,$a);
    receiptsCheck(count(brvtalPosDestinations($db,'transfer'))===1,'active only');
    receiptsCheck((int)brvtalPosReceiptFind($db,'transfer',$a,'ref-01 a')['order_id']===1,'inactive historical receipt');
    $db->beginTransaction();
    receiptsReject(fn()=>brvtalPosReceiptRegister($db,4,'transfer',$a,'REF-02',$hash),'POS_DESTINATION_UNAVAILABLE');
    $db->rollBack();
    receiptsCheck((int)$db->query('SELECT COUNT(*) FROM ticket_pos_payment_receipts')->fetchColumn()===3,'three confirmed receipts');
    $q=$db->prepare('SELECT deactivated_by_admin_id FROM ticket_pos_payment_destinations WHERE id=?');
    $q->execute([$a]);
    receiptsCheck((int)$q->fetchColumn()===1,'deactivation audited');
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
receiptsCheck(brvtalPosReceiptNormalize(' ref-01  a ')==='REF-01 A','normalize');
receiptsCheck(brvtalPosReceiptNormalize('ref-01/a')!==brvtalPosReceiptNormalize('ref-01-a'),'separators');
receiptsCheck(brvtalPosReceiptNormalize('ref-001')!==brvtalPosReceiptNormalize('ref-01'),'leading zeros');
receiptsReject(fn()=>brvtalPosReceiptNormalize("REF\n001"),'POS_RECEIPT_REFERENCE');
if (getenv('BRVTAL_INTEGRATION_TESTS')!=='1' || getenv('BRVTAL_TEST_DB_HOST')!=='127.0.0.1') throw new RuntimeException('Disposable MariaDB required');
$prefix=(string)getenv('BRVTAL_TEST_DB_NAME');
receiptsCheck(preg_match('/\Abrvtal_test[a-zA-Z0-9_]*\z/D',$prefix)===1,'test namespace');
$name=$prefix.'_receipts_'.bin2hex(random_bytes(4));
$dsn='mysql:host='.(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1').';port='.(int)(getenv('BRVTAL_TEST_DB_PORT')?:3306).';charset=utf8mb4';
$user=(string)(getenv('BRVTAL_TEST_DB_USER')?:'root'); $pass=(string)getenv('BRVTAL_TEST_DB_PASS');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$user,$pass,$options); $server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
try {
    $db=new PDO($dsn.';dbname='.$name,$user,$pass,$options); receiptsFixture($db);
    $db->exec((string)file_get_contents(__DIR__.'/../../database/migration_ticketing_pos_receipts_01.sql'));
    receiptsExercise($db);
} finally { $server->exec('DROP DATABASE '.$name); }
echo "BRVTAL_MARKED_DATABASE_PASS: ticketing-pos-receipts.php\n";
