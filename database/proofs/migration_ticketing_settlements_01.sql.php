<?php
declare(strict_types=1);

$requirements = array_merge(
    brvtalMigrationObjectRequirements('ticket_settlements',
        ['id','payout_reference','payout_date','file_sha256','status','imported_by','imported_at_utc','rolled_back_by','rolled_back_at_utc'],
        ['idx_settlement_status_date'], true),
    brvtalMigrationObjectRequirements('ticket_settlement_lines',
        ['id','settlement_id','line_reference','transaction_reference','gross_cop','fee_cop','fee_vat_cop','withheld_cop','adjustment_cop','credited_cop'],
        ['idx_settlement_transaction'], true),
    [
        'unique-index:ticket_settlements.PRIMARY=id',
        'unique-index:ticket_settlements.uq_settlement_reference=payout_reference',
        'unique-index:ticket_settlements.uq_settlement_hash=file_sha256',
        'column-type:ticket_settlements.payout_date=date',
        'column-type:ticket_settlements.file_sha256=char(64)',
        "column-type:ticket_settlements.status=enum('imported','rolled_back')",
        'column-nullable:ticket_settlements.rolled_back_by',
        'column-nullable:ticket_settlements.rolled_back_at_utc',
        'unique-index:ticket_settlement_lines.PRIMARY=id',
        'unique-index:ticket_settlement_lines.uq_settlement_line=settlement_id,line_reference',
        'fk-reference:ticket_settlement_lines.fk_settlement_line_batch=settlement_id:ticket_settlements.id',
    ]
);
foreach (['gross_cop','fee_cop','fee_vat_cop','withheld_cop','adjustment_cop'] as $column) {
    $requirements[] = 'column-nullable:ticket_settlement_lines.' . $column;
}
return $requirements;
