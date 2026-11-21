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
$read=(string)file_get_contents(__DIR__.'/../../api/ticketing/admin-stages.php');
stageAssert(str_contains($read,'brvtal_admin_require_csrf'),'admin POST CSRF required');
stageAssert(str_contains($read,"'settings'"),'admin settings permission required');
stageAssert(str_contains((string)file_get_contents(__DIR__.'/../../config/ticketing_stages.php'),
    'BRVTAL_INTEGRATION_TESTS'),'writes must be synthetic only');
stageAssert(!str_contains($read,'ticket_order_items'),'API must not rewrite order snapshots');
echo "T22 stage windows, quotas, snapshots and input guards OK (synthetic).".PHP_EOL;
