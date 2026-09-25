<?php
declare(strict_types=1);
// Rector probe #686; removed before merge.

/**
 * Request-local database schema catalog.
 *
 * Shared-hosting information_schema reads are relatively expensive. Load the
 * table list once per PDO connection/request and answer subsequent checks from
 * memory instead of issuing one query per feature/table.
 *
 * @return array<string,true>
 */
function brvtalSchemaTables(PDO $pdo): array
{
    static $catalogs = [];
    $key = spl_object_id($pdo);
    if (isset($catalogs[$key])) {
        return $catalogs[$key];
    }

    $statement = $pdo->query(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()'
    );
    if (!$statement instanceof PDOStatement) {
        throw new RuntimeException('SCHEMA_CATALOG_UNAVAILABLE');
    }

    $tables = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $name = (string)$table;
        if ($name !== '') {
            $tables[$name] = true;
        }
    }

    $catalogs[$key] = $tables;
    return $tables;
}

function brvtalSchemaTableExists(PDO $pdo, string $table): bool
{
    return isset(brvtalSchemaTables($pdo)[$table]);
}
