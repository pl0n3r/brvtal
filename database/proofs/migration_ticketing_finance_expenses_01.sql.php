<?php
declare(strict_types=1);

$requirements = [];
foreach (['expense'=>'event_expenses', 'budget'=>'event_budgets'] as $kind => $table) {
    $columns = ['id','event_id','category','amount_cop','entry_date','status','supersedes_id','created_at_utc'];
    if ($kind === 'expense') {
        $columns[] = 'concept';
    }
    array_push($requirements, ...brvtalMigrationObjectRequirements(
        $table, $columns, ["idx_{$kind}_event_category"], true
    ));
    $status = $kind === 'expense' ? "enum('committed','paid','void')" : "enum('active','void')";
    array_push(
        $requirements,
        "unique-index:$table.PRIMARY=id",
        "unique-index:$table.uq_{$kind}_supersedes=supersedes_id",
        "column-nullable:$table.supersedes_id",
        "column-type:$table.entry_date=date",
        "column-type:$table.status=$status",
        "column-type:$table.category=enum('artistas','lugar','produccion','publicidad','logistica','otros')",
        "check-constraint:$table.ck_{$kind}_amount",
        "fk-reference:$table.fk_{$kind}_event=event_id:events.id",
        "fk-reference:$table.fk_{$kind}_previous=supersedes_id:$table.id"
    );
}
return $requirements;
