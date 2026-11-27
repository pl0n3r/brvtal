<?php
declare(strict_types=1);

return [
    'table:ticket_wompi_reconcile_claims',
    'column:ticket_wompi_reconcile_claims.attempt_id',
    'unique-index:ticket_wompi_reconcile_claims.PRIMARY=attempt_id',
    'fk-reference:ticket_wompi_reconcile_claims.fk_wompi_claim_attempt=attempt_id:ticket_payment_attempts.id',
    "column-type:ticket_wompi_reconcile_claims.environment=enum('sandbox','production')",
    'column-type:ticket_wompi_reconcile_claims.last_started_at_utc=datetime(6)',
    'column-type:ticket_wompi_reconcile_claims.claim_id=binary(16)',
    'column-nullable:ticket_wompi_reconcile_claims.claim_id',
    'column-type:ticket_wompi_reconcile_claims.expires_at_utc=datetime(6)',
    'column-nullable:ticket_wompi_reconcile_claims.expires_at_utc',
    'index:ticket_wompi_reconcile_claims.idx_wompi_claim_expiry',
    'check-constraint:ticket_wompi_reconcile_claims.chk_wompi_claim_environment',
    'check-constraint:ticket_wompi_reconcile_claims.chk_wompi_claim_lease',
    'table:ticket_wompi_reconcile_budget',
    "column-type:ticket_wompi_reconcile_budget.environment=enum('sandbox','production')",
    'unique-index:ticket_wompi_reconcile_budget.PRIMARY=environment',
    'column-type:ticket_wompi_reconcile_budget.window_started_at_utc=datetime(6)',
    'column-not-null-default:ticket_wompi_reconcile_budget.request_count=0',
    'check-constraint:ticket_wompi_reconcile_budget.chk_wompi_budget_environment',
    'check-constraint:ticket_wompi_reconcile_budget.chk_wompi_budget_count',
];
