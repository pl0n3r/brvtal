<?php
declare(strict_types=1);

return array_merge(
    brvtalMigrationObjectRequirements('event_expenses',
        ['id','event_id','category','concept','amount_cop','entry_date','status','supersedes_id','created_at_utc'],
        ['idx_expense_event_category'], true),
    [
        'unique-index:event_expenses.PRIMARY=id',
        'unique-index:event_expenses.uq_expense_supersedes=supersedes_id',
        'column-nullable:event_expenses.supersedes_id',
        'column-type:event_expenses.entry_date=date',
        'check-constraint:event_expenses.ck_expense_amount',
        'fk-reference:event_expenses.fk_expense_event=event_id:events.id',
        'fk-reference:event_expenses.fk_expense_previous=supersedes_id:event_expenses.id',
    ],
    brvtalMigrationObjectRequirements('event_budgets',
        ['id','event_id','category','amount_cop','entry_date','status','supersedes_id','created_at_utc'],
        ['idx_budget_event_category'], true),
    [
        'unique-index:event_budgets.PRIMARY=id',
        'unique-index:event_budgets.uq_budget_supersedes=supersedes_id',
        'column-nullable:event_budgets.supersedes_id',
        'column-type:event_budgets.entry_date=date',
        'check-constraint:event_budgets.ck_budget_amount',
        'fk-reference:event_budgets.fk_budget_event=event_id:events.id',
        'fk-reference:event_budgets.fk_budget_previous=supersedes_id:event_budgets.id',
    ],
);
