<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/ticketing_finance.php';
require_once __DIR__.'/../../config/ticketing_staff_permissions.php';
function t20(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException('T20: '.$label);
}
$orders=[
    ['id'=>1,'event_id'=>9,'status'=>'paid','total_cop'=>90000,'currency'=>'COP'],
    ['id'=>2,'event_id'=>9,'status'=>'pending','total_cop'=>20000,'currency'=>'COP'],
    ['id'=>3,'event_id'=>9,'status'=>'refunded','total_cop'=>10000,'currency'=>'COP'],
    ['id'=>4,'event_id'=>9,'status'=>'paid','total_cop'=>5000,'currency'=>'COP']
];
$items=[
    ['order_id'=>1,'ticket_type_id'=>7,'quantity'=>2,'subtotal_cop'=>100000],
    ['order_id'=>2,'ticket_type_id'=>7,'quantity'=>1,'subtotal_cop'=>20000],
    ['order_id'=>3,'ticket_type_id'=>7,'quantity'=>1,'subtotal_cop'=>10000],
    ['order_id'=>4,'ticket_type_id'=>7,'quantity'=>1,'subtotal_cop'=>5000]
];
$payments=[
    ['id'=>1,'order_id'=>1,'status'=>'approved','fee_amount_cop'=>3000,
     'fee_vat_cop'=>570,'withheld_amount_cop'=>0,'net_amount_cop'=>86430],
    ['id'=>2,'order_id'=>1,'status'=>'approved','fee_amount_cop'=>9999,
     'fee_vat_cop'=>0,'withheld_amount_cop'=>0,'net_amount_cop'=>0],
    ['id'=>3,'order_id'=>2,'status'=>'pending','fee_amount_cop'=>null,
     'fee_vat_cop'=>null,'withheld_amount_cop'=>null,'net_amount_cop'=>null]
];
$r=brvtalFinanceSummarize($orders,$items,$payments);
t20(count($r)===1,'one event');
$s=$r[0];
t20($s['approved_cop']===90000 && $s['gross_cop']===100000 && $s['discount_cop']===10000,
    'COP gross and discount are separate from approved net sales');
t20($s['paid_orders']===1 && $s['pending_orders']===1 && $s['refunded_orders']===1
    && $s['unverified_orders']===1,'duplicate webhook and unverified order not double counted');
t20($s['fee_cop']===3000 && $s['fee_vat_cop']===570 && $s['payout_cop']===86430
    && $s['difference_cop']===0,'actual fee and VAT are read, not guessed');
t20($s['expenses_cop']===null && $s['profit_cop']===null
    && $s['settlement_status']==='unverified','no invented profitability or reconciliation');
$payments[0]['fee_vat_cop']=null;
$missing=brvtalFinanceSummarize($orders,$items,$payments)[0];
t20($missing['fee_vat_cop']===null && $missing['difference_cop']===null,
    'missing provider fee VAT remains unknown');
t20(brvtalTicketingCan('admin','finance') && brvtalTicketingCan('admin','export')
    && !brvtalTicketingCan('door','finance') && !brvtalTicketingCan('door','export'),
    'financial RBAC');
echo "T20 finance synthetic invariants OK\n";
