<?php
declare(strict_types=1);
require_once __DIR__.'/../api/event-workflow-lib.php';
require_once __DIR__.'/../config/public_visibility.php';
function status_expect(bool $yes,string $reason): void {if(!$yes){fwrite(STDERR,"EVENT STATUS CONTRACT FAILED: $reason\n");exit(1);}}
$map=[
  'draft'=>'draft','published'=>'published','upcoming'=>'published',
  'tickets_available'=>'published','last_tickets'=>'published','sold_out'=>'published',
  'finished'=>'published','archived'=>'published','cancelled'=>'cancelled'
];
foreach($map as $legacy=>$ui) {
  status_expect(brvtal_event_editor_publication($legacy)===$ui,"legacy mapping $legacy");
  status_expect(brvtal_event_workflow_event(['status'=>$legacy])['status']===$legacy,"server accepts legacy $legacy without rewriting existing data");
}
status_expect(brvtal_event_editor_publication('tickets_available',true)==='scheduled','future schedule shown as scheduled');
$now=new DateTimeImmutable('2026-11-20 12:00:00');
foreach(['published','upcoming','tickets_available','last_tickets','sold_out'] as $legacy){
  status_expect(!brvtal_public_event_is_historical(['status'=>$legacy,'event_date'=>'2026-12-10 21:00:00'],$now),"future $legacy stays active");
}
status_expect(brvtal_public_event_is_historical(['status'=>'finished','event_date'=>'2026-11-01 21:00:00'],$now),'historical remains archival');
$statusSource=file_get_contents(__DIR__.'/../discadmin/content-core.php');
$workflowSource=file_get_contents(__DIR__.'/../discadmin/event-workflow.js');
status_expect(substr_count($statusSource,'class="step" data-step=')===4,'exactly five wizard steps');
status_expect(str_contains($workflowSource,"'scheduled'?'published'"),'scheduled UI persists canonical published status');
status_expect(str_contains($workflowSource,"publish_at:"),'not-before schedule stays virtual');
echo "Event publication mapping contract OK\n";
