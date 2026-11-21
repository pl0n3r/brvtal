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
t21(brvtalRefundProrate(25000,1,4)===6250,'partial refund integer COP');
t21(brvtalRefundProrate(100,2,3)===66,'partial refund floors without float');
t21(brvtalRefundProrate(1000000000000,500000000000,1000000000000)===500000000000,
    '12-digit COP and per-entry prices do not overflow PHP integers');
t21(brvtalRefundProrate(1000000000000,1000000000000,1000000000000)===1000000000000,
    'exact full refund at bound');
t21Reject(static fn()=>brvtalRefundProrate(100,101,100),'AMOUNT_UNVERIFIED');
t21Reject(static fn()=>brvtalRefundProrate(100,1,0),'AMOUNT_UNVERIFIED');
t21Reject(static fn()=>brvtalRefundProrate(100,1,1000),'AMOUNT_UNVERIFIED');

// Both list surfaces must fail closed on non-synthetic databases, including GET.
$refundCode=(string)file_get_contents(__DIR__.'/../../config/ticketing_refunds.php');
$adminCode=(string)file_get_contents(__DIR__.'/../../api/ticketing/admin-refunds.php');
t21(str_contains($refundCode,"function brvtalRefundBuyerList(PDO \$db,int \$buyer):array {\n    brvtalRefundGuard(\$db);"),
    'buyer refund GET remains sandbox-only');
t21(str_contains($adminCode,'$db=db(); brvtalRefundGuard($db);'),
    'staff refund GET remains sandbox-only');
t21(strpos($refundCode,"if (\$order['status']!=='paid') throw new DomainException('ORDER_NOT_REFUNDABLE');")
    > strpos($refundCode,'if ($existing) {'),'idempotent replay precedes paid-status gate');

$testWompi = ['mode'=>'sandbox','private'=>'prv_test_synthetic_only'];
$testTx = 'trx_synthetic_01';
$testRef = 'BRVTAL-T21-1';
$testPayload = brvtalRefundSandboxV2Payload($testWompi,$testTx,500,'Solicitud sintética',$testRef);
t21($testPayload['endpoint']==='https://sandbox.wompi.co/v1/refunds',
    'refund V2 must use the sandbox-only endpoint');
t21($testPayload['body']['amount_in_cents']===50000,
    'refund amounts use cents without floating-point rounding');
t21(!str_contains(json_encode($testPayload,JSON_THROW_ON_ERROR),'prv_test_'),
    'private sandbox key must not appear in the request payload');
t21Reject(static fn()=>brvtalRefundSandboxV2Payload(
    ['mode'=>'sandbox','private'=>'prv_prod_fake'],$testTx,500,'Motivo',$testRef
),'REFUND_SANDBOX_ONLY');
t21Reject(static fn()=>brvtalRefundSandboxV2Payload(
    ['mode'=>'production','private'=>'prv_test_synthetic_only'],$testTx,500,'Motivo',$testRef
),'REFUND_SANDBOX_ONLY');
$receipt = static fn(string $status,int $amount,string $reference):string => json_encode(['data'=>[
    'status'=>$status,'transaction_id'=>$testTx,'amount_in_cents'=>$amount,
    'reference'=>$reference,'v2_refund_id'=>'v2_refund_synthetic_01'
]],JSON_THROW_ON_ERROR);
$confirmed = brvtalRefundSandboxV2Receipt(201,$receipt('APPROVED',50000,$testRef),$testTx,500,$testRef);
t21($confirmed['state']==='confirmed','provider must confirm an exact approved refund');
$declined = brvtalRefundSandboxV2Receipt(201,$receipt('DECLINED',50000,$testRef),$testTx,500,$testRef);
t21($declined['state']==='failed','declined refund must not revoke tickets');
t21Reject(static fn()=>brvtalRefundSandboxV2Receipt(
    201,$receipt('APPROVED',50100,$testRef),$testTx,500,$testRef
),'REFUND_PROVIDER_UNVERIFIED');
t21Reject(static fn()=>brvtalRefundSandboxV2Receipt(
    201,$receipt('APPROVED',50000,'other_ref'),$testTx,500,$testRef
),'REFUND_PROVIDER_UNVERIFIED');


t21(brvtalRefundProviderNextStatus('approved','confirmed')==='refunded',
    'approved sandbox receipt is the only route to provider-settled');
t21(brvtalRefundProviderNextStatus('processing','confirmed')==='refunded',
    'pending sandbox receipt can later be approved');
t21(brvtalRefundProviderNextStatus('approved','failed')==='failed',
    'failed sandbox refund never triggers credential revocation');
t21(brvtalRefundProviderNextStatus('failed','confirmed')==='failed',
    'late contradictory approved event cannot revoke failed refund');
t21(brvtalRefundProviderNextStatus('refunded','failed')==='refunded',
    'late failure cannot undo a confirmed refund');
t21Reject(static fn()=>brvtalRefundProviderNextStatus('requested','confirmed'),
    'REFUND_INVALID_STATE');
t21Reject(static fn()=>brvtalRefundProviderNextStatus('approved','untrusted'),
    'REFUND_PROVIDER_UNVERIFIED');


$captured = [];
$mock = static function(string $url,array $headers,array $body) use (&$captured,$receipt,$testRef):array {
    $captured=['url'=>$url,'headers'=>$headers,'body'=>$body];
    return ['status'=>201,'body'=>$receipt('APPROVED',50000,$testRef)];
};
$exchange=brvtalRefundSandboxExchange($testWompi,$testTx,500,'Motivo sintético',$testRef,$mock);
t21($exchange['status']===201,'sandbox server transport returns validated HTTP receipt');
t21($captured['url']==='https://sandbox.wompi.co/v1/refunds',
    'server transport cannot move to production host');
t21($captured['headers'][0]==='Authorization: Bearer '.$testWompi['private'],
    'test-only private bearer is server-side');
t21($captured['body']['amount_in_cents']===50000,
    'only database-validated amount is passed to provider');
t21(!str_contains(json_encode($exchange,JSON_THROW_ON_ERROR),'prv_test_'),
    'transport never returns the private bearer');
t21Reject(static fn()=>brvtalRefundSandboxExchange(
    ['mode'=>'production','private'=>'prv_prod_fake'],$testTx,500,'Motivo sintético',$testRef,$mock
),'REFUND_SANDBOX_ONLY');

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
