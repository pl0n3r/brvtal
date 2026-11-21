<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_refunds.php';
function t21(bool $ok,string $message):void {
    if(!$ok) throw new RuntimeException('T21: '.$message);
}
function t21Reject(callable $f,string $expected):void {
    try { $f(); } catch(DomainException $e) {
        t21($e->getMessage()===$expected,'expected '.$expected); return;
    }
    throw new RuntimeException('T21: expected rejection '.$expected);
}
$reflect=new ReflectionFunction('brvtalRefundGuard');
t21($reflect->getNumberOfParameters()===1,'guard requires DB');
t21(brvtalTicketingCan('superadmin','refund'),'superadmin refund');
t21(!brvtalTicketingCan('admin','refund'),'staff role denied');
t21(!brvtalTicketingCan('door','refund'),'door denied');
// Both list surfaces must fail closed on non-synthetic databases, including GET.
$refundCode=(string)file_get_contents(__DIR__.'/../../config/ticketing_refunds.php');
$adminCode=(string)file_get_contents(__DIR__.'/../../api/ticketing/admin-refunds.php');
t21(str_contains($refundCode,"function brvtalRefundBuyerList(PDO \$db,int \$buyer):array {\n    brvtalRefundGuard(\$db);"),
    'buyer refund GET remains sandbox-only');
t21(str_contains($adminCode,'$db=db(); brvtalRefundGuard($db);'),
    'staff refund GET remains sandbox-only');
t21(strpos($refundCode,"if (\$order['status']!=='paid') throw new DomainException('ORDER_NOT_REFUNDABLE');")
    > strpos($refundCode,'if ($existing) {'),'idempotent replay precedes paid-status gate');
$schema=(string)file_get_contents(__DIR__.'/../../database/migration_ticketing_refunds_01.sql');
t21(str_contains($schema,'uq_ticket_refund_claim'),'no double-refund claim');
t21(str_contains($schema,'ticket_refund_adjustments'),'immutable finance witness');
t21(str_contains($schema,'ticket_refund_audit'),'audit witness');
t21(!preg_match('/\bDROP\b|\bTRUNCATE\b|\bDELETE\s+FROM\b/i',$schema),'additive schema');
if(getenv('BRVTAL_INTEGRATION_TESTS')!=='1') {
    echo "T21 refund contract checks OK; disposable database tests not enabled.\n"; exit(0);
}
$name=(string)getenv('BRVTAL_TEST_DB_NAME');
t21((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D',$name),'disposable DB namespace');
$pdo=new PDO('mysql:host='.(getenv('BRVTAL_TEST_DB_HOST')?:'127.0.0.1').';port='.(getenv('BRVTAL_TEST_DB_PORT')?:'3306').';dbname='.$name,
    getenv('BRVTAL_TEST_DB_USER')?:'root',getenv('BRVTAL_TEST_DB_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
brvtalRefundGuard($pdo);
putenv('BRVTAL_INTEGRATION_TESTS=0');
t21Reject(static fn()=>brvtalRefundGuard($pdo),'REFUND_SANDBOX_ONLY');
echo "T21 refund guard checks OK.\n";
