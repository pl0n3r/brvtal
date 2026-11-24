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
    ['id'=>1,'order_id'=>1,'status'=>'approved','provider'=>'wompi','provider_transaction_id'=>'TX-1',
     'gross_amount_cop'=>90000,'fee_amount_cop'=>3000,
     'fee_vat_cop'=>570,'withheld_amount_cop'=>0,'net_amount_cop'=>86430],
    ['id'=>2,'order_id'=>1,'status'=>'approved','provider'=>'wompi','provider_transaction_id'=>'TX-2',
     'gross_amount_cop'=>90000,'fee_amount_cop'=>9999,
     'fee_vat_cop'=>0,'withheld_amount_cop'=>0,'net_amount_cop'=>0],
    ['id'=>3,'order_id'=>2,'status'=>'pending','provider'=>'wompi','provider_transaction_id'=>null,
     'gross_amount_cop'=>20000,'fee_amount_cop'=>null,
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
t20(count($s['transactions'])===1 && $s['transactions'][0]['transaction_id']==='TX-1',
    'one payment evidence for repeated approvals');
t20($s['capacity']===null && $s['checkins']===null,'optional operational fields unknown without database');
t20($s['expenses_cop']===null && $s['profit_cop']===null
    && $s['settlement_status']==='unverified','no invented profitability or reconciliation');
$payments[0]['fee_vat_cop']=null;
$missing=brvtalFinanceSummarize($orders,$items,$payments)[0];
t20($missing['fee_vat_cop']===null && $missing['difference_cop']===null,
    'missing provider fee VAT remains unknown');
t20(brvtalTicketingCan('admin','finance') && brvtalTicketingCan('admin','export')
    && !brvtalTicketingCan('door','finance') && !brvtalTicketingCan('door','export'),
    'financial RBAC');
$payments[0]['gross_amount_cop']=89999;
$invalid=brvtalFinanceSummarize($orders,$items,$payments)[0];
t20($invalid['paid_orders']===0 && $invalid['unverified_orders']===2,
    'provider gross mismatch cannot certify revenue');
$posOrder=['id'=>5,'event_id'=>9,'status'=>'paid','total_cop'=>20000,'currency'=>'COP'];
$posItem=['order_id'=>5,'ticket_type_id'=>7,'quantity'=>1,'subtotal_cop'=>20000];
$posSale=['id'=>15,'order_id'=>5,'event_id'=>9,'ticket_type_id'=>7,'quantity'=>1,
    'amount_cop'=>20000,'method'=>'cash','status'=>'paid'];
$summary=brvtalFinanceSummarize([$posOrder],[$posItem],[],[$posSale])[0];
t20($summary['pos_cop']===20000 && $summary['pos_orders']===1
    && $summary['approved_cop']===0 && $summary['paid_orders']===1,
    'POS counted apart from Wompi');
t20($summary['fee_cop']===0 && $summary['payout_cop']===0
    && $summary['transactions'][0]['fee_cop']===null,
    'no invented POS commission or settlement');
$bad=$posSale;$bad['amount_cop']=20001;
$invalidPos=brvtalFinanceSummarize([$posOrder],[$posItem],[],[$bad])[0];
t20($invalidPos['unverified_orders']===1 && $invalidPos['pos_cop']===0,
    'mismatched cash evidence rejected');
$terminal=$posSale;$terminal['method']='terminal';
$terminalSummary=brvtalFinanceSummarize([$posOrder],[$posItem],[],[$terminal])[0];
t20($terminalSummary['pos_cop']===20000,'operator-confirmed terminal accepted without provider fee');
echo "T20 finance + POS synthetic invariants OK\n";
// T21 synthetic MariaDB regressions execute in the existing finance integration harness.
require __DIR__.'/ticketing-refunds.php';
