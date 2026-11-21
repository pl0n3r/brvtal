<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_stages.php';
function stageAssert(bool $yes,string $why):void {
    if (!$yes) throw new RuntimeException('T22: '.$why);
}
$stages=[
 ['code'=>'early_bird','price_cop'=>15000,'cumulative_capacity'=>2,
  'opens_at_local'=>'2026-12-01 09:00:00','closes_at_local'=>'2026-12-04 20:00:00'],
 ['code'=>'preventa','price_cop'=>20000,'cumulative_capacity'=>5,
  'opens_at_local'=>'2026-12-02 09:00:00','closes_at_local'=>'2026-12-04 21:00:00'],
 ['code'=>'general','price_cop'=>30000,'cumulative_capacity'=>10,
  'opens_at_local'=>'2026-12-04 21:00:00','closes_at_local'=>'2026-12-04 23:59:59']
];
$now=static fn(string $s):DateTimeImmutable=>new DateTimeImmutable($s,new DateTimeZone('America/Bogota'));
stageAssert(brvtalTicketStageCurrent($stages,0,$now('2026-11-30 21:00:00'))===null,'before all windows');
stageAssert(brvtalTicketStageCurrent($stages,0,$now('2026-12-01 09:00:00'))['code']==='early_bird','inclusive opening');
stageAssert(brvtalTicketStageCurrent($stages,2,$now('2026-12-01 10:00:00'))===null,'no oversell before next stage');
stageAssert(brvtalTicketStageCurrent($stages,2,$now('2026-12-02 09:00:00'))['code']==='preventa','exhaustion transition');
stageAssert(brvtalTicketStageCurrent($stages,0,$now('2026-12-02 10:00:00'))['code']==='preventa','time transition');
stageAssert(brvtalTicketStageCurrent($stages,5,$now('2026-12-03 10:00:00'))===null,'quota fully consumed');
stageAssert(brvtalTicketStageCurrent($stages,5,$now('2026-12-04 21:00:00'))['code']==='general','new stage');
stageAssert(brvtalTicketStageCurrent($stages,10,$now('2026-12-04 21:00:00'))===null,'zero availability');
stageAssert(brvtalTicketStageCurrent($stages,0,$now('2026-12-05 00:00:00'))===null,'after last close');
foreach ([
  [array_replace($stages[0],['price_cop'=>-1]),$stages[1]],
  [array_replace($stages[0],['opens_at_local'=>'2026-02-30 09:00:00'])],
  [array_replace($stages[0],['cumulative_capacity'=>5]),array_replace($stages[1],['cumulative_capacity'=>2])],
  [$stages[0],$stages[0]]
] as $invalid) {
    try { brvtalTicketStageInput($invalid); throw new RuntimeException('invalid stage accepted'); }
    catch (InvalidArgumentException) {}
}
$quote = brvtalTicketStageQuote($stages,0,2,4,$now('2026-12-01 09:00:00'));
stageAssert($quote['code']==='early_bird' && $quote['unit_price_cop']===15000
    && $quote['subtotal_cop']===30000 && $quote['available']===2,
    'quote uses exact COP and stage allocation');
$quote = brvtalTicketStageQuote($stages,2,2,3,$now('2026-12-02 09:00:00'));
stageAssert($quote['code']==='preventa' && $quote['subtotal_cop']===40000,
    'stage rollover uses server price and cumulative capacity');
foreach ([
    [$stages,0,3,5,$now('2026-12-01 09:00:00')],
    [$stages,2,1,0,$now('2026-12-02 09:00:00')],
    [$stages,0,1,10,$now('2026-11-30 09:00:00')],
    [[array_replace($stages[0],['price_cop'=>10000000])],0,2,2,$now('2026-12-01 09:00:00')],
] as $notSaleable) {
    try {
        brvtalTicketStageQuote(...$notSaleable);
        throw new RuntimeException('stage quote accepted unavailable purchase');
    } catch (DomainException) {}
}
try {
    brvtalTicketStageQuote($stages,-1,1,2,$now('2026-12-01 09:00:00'));
    throw new RuntimeException('negative inventory accepted');
} catch (InvalidArgumentException) {}
$read=(string)file_get_contents(__DIR__.'/../../api/ticketing/admin-stages.php');
stageAssert(str_contains($read,'brvtal_admin_require_csrf'),'admin POST CSRF required');
stageAssert(str_contains($read,"'settings'"),'admin settings permission required');
stageAssert(str_contains((string)file_get_contents(__DIR__.'/../../config/ticketing_stages.php'),
    'BRVTAL_INTEGRATION_TESTS'),'writes must be synthetic only');
stageAssert(!str_contains($read,'ticket_order_items'),'API must not rewrite order snapshots');
$checkoutSource = (string)file_get_contents(__DIR__.'/../../config/public_ticket_checkout.php');
stageAssert(str_contains($checkoutSource, "BRVTAL_INTEGRATION_TESTS")
    && str_contains($checkoutSource, "STAGE_TRANSACTION_REQUIRED")
    && str_contains($checkoutSource, "FOR UPDATE"),
    'stage checkout quote remains synthetic and serialized');
stageAssert(str_contains($checkoutSource, "brvtal_public_ticket_checkout_stage_quote("),
    'server-side quote is invoked in checkout before order insertion');
stageAssert(str_contains($checkoutSource, "unit_price_cop,subtotal_cop"),
    'idempotent checkout reads the immutable item price snapshot');
$core = (string)file_get_contents(__DIR__.'/../../discadmin/content-core.js');
stageAssert(str_contains($core,"import('/discadmin/ticketing-stages.js')"),
    'stage editor mounted inside existing event ticket type shell');
stageAssert(str_contains($core,'Number(currentEvent?.id)'),
    'stage editor only for persisted event with valid identity');
echo "T22 stage windows, quotas, snapshots and input guards OK (synthetic).".PHP_EOL;
