<?php
declare(strict_types=1);

/**
 * BRVTAL explicit migration-state helpers.
 *
 * This library never runs migrations automatically. Callers must select a
 * concrete migration file and opt into a write operation explicitly.
 */

function brvtal_migrations_discover(string $directory): array
{
    $directory = rtrim($directory, DIRECTORY_SEPARATOR);
    $files = glob($directory . DIRECTORY_SEPARATOR . 'migration_*.sql') ?: [];
    $migrations = [];

    foreach ($files as $path) {
        if (!is_file($path)) {
            continue;
        }

        $name = basename($path);
        if (!preg_match('/^migration_[a-z0-9_]+\.sql$/', $name)) {
            continue;
        }

        $migrations[$name] = $path;
    }

    ksort($migrations, SORT_NATURAL);
    return $migrations;
}

function brvtal_migration_checksum(string $path): string
{
    $checksum = hash_file('sha256', $path);
    if (!is_string($checksum) || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
        throw new RuntimeException('MIGRATION_CHECKSUM_FAILED');
    }
    return $checksum;
}

function brvtalMigrationAssertAdditiveSql(string $sql): void
{
    if (trim($sql) === '') {
        throw new RuntimeException('MIGRATION_SQL_EMPTY');
    }

    $sanitized = '';
    $length = strlen($sql);
    for ($index = 0; $index < $length; ) {
        $char = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($char === '/' && $next === '*') {
            $sanitized .= ' ';
            $index += 2;
            while ($index < $length && !($sql[$index] === '*' && $index + 1 < $length && $sql[$index + 1] === '/')) {
                $sanitized .= ($sql[$index] === "\n" || $sql[$index] === "\r") ? $sql[$index] : ' ';
                $index++;
            }
            $index = min($length, $index + 2);
            continue;
        }

        if (($char === '-' && $next === '-') || $char === '#') {
            $sanitized .= ' ';
            $index += $char === '#' ? 1 : 2;
            while ($index < $length && $sql[$index] !== "\n" && $sql[$index] !== "\r") {
                $sanitized .= ' ';
                $index++;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $sanitized .= ' ';
            $index++;
            while ($index < $length) {
                $current = $sql[$index];
                if ($current === '\\') {
                    $sanitized .= ' ';
                    $index += min(2, $length - $index);
                    continue;
                }
                if ($current === $quote) {
                    if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                        $sanitized .= '  ';
                        $index += 2;
                        continue;
                    }
                    $sanitized .= ' ';
                    $index++;
                    break;
                }
                $sanitized .= ($current === "\n" || $current === "\r") ? $current : ' ';
                $index++;
            }
            continue;
        }

        $sanitized .= $char;
        $index++;
    }

    $destructivePatterns = [
        '/\\bDROP\\b/i',
        '/\\bTRUNCATE\\b/i',
        '/\\bDELETE\\b/i',
        '/\\bREPLACE\\s+INTO\\b/i',
        '/\\bCREATE\\s+OR\\s+REPLACE\\b/i',
        '/\\bRENAME\\s+TABLE\\b/i',
        '/\\bALTER\\s+TABLE\\b[^;]*\\b(?:RENAME|CHANGE|MODIFY)\\b/is',
    ];
    foreach ($destructivePatterns as $pattern) {
        if (preg_match($pattern, $sanitized) === 1) {
            throw new RuntimeException('MIGRATION_NON_ADDITIVE_SQL');
        }
    }
}
function brvtal_migration_registry_exists(PDO $pdo): bool
{
    $statement = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES " .
        "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'"
    );
    return (int)$statement->fetchColumn() === 1;
}

function brvtal_migration_records(PDO $pdo): array
{
    if (!brvtal_migration_registry_exists($pdo)) {
        return [];
    }

    $rows = $pdo->query(
        'SELECT migration_name, checksum_sha256, applied_at, applied_by, deploy_sha ' .
        'FROM schema_migrations ORDER BY migration_name'
    )->fetchAll(PDO::FETCH_ASSOC);

    $records = [];
    foreach ($rows as $row) {
        $records[(string)$row['migration_name']] = $row;
    }
    return $records;
}

function brvtal_migration_status(PDO $pdo, string $directory): array
{
    $files = brvtal_migrations_discover($directory);
    $registryExists = brvtal_migration_registry_exists($pdo);
    $records = $registryExists ? brvtal_migration_records($pdo) : [];
    $rows = [];

    foreach ($files as $name => $path) {
        $checksum = brvtal_migration_checksum($path);
        $record = $records[$name] ?? null;
        $state = 'pending';

        if (is_array($record)) {
            $state = hash_equals((string)$record['checksum_sha256'], $checksum)
                ? 'applied'
                : 'checksum_mismatch';
        }

        $rows[] = [
            'migration' => $name,
            'state' => $state,
            'checksum_sha256' => $checksum,
            'applied_at' => $record['applied_at'] ?? null,
            'applied_by' => $record['applied_by'] ?? null,
            'deploy_sha' => $record['deploy_sha'] ?? null,
        ];
    }

    $orphaned = [];
    foreach ($records as $name => $record) {
        if (!isset($files[$name])) {
            $orphaned[] = [
                'migration' => $name,
                'state' => 'record_without_file',
                'checksum_sha256' => (string)$record['checksum_sha256'],
                'applied_at' => $record['applied_at'] ?? null,
                'applied_by' => $record['applied_by'] ?? null,
                'deploy_sha' => $record['deploy_sha'] ?? null,
            ];
        }
    }

    return [
        'registry_exists' => $registryExists,
        'migrations' => $rows,
        'orphaned_records' => $orphaned,
    ];
}


function brvtal_migration_verify_plan_status(array $status, string $expected): void
{
    if ($expected !== '__NONE__' && !preg_match('/^migration_[a-z0-9_]+\\.sql$/', $expected)) {
        throw new InvalidArgumentException('INVALID_MIGRATION_NAME');
    }
    if (($status['registry_exists'] ?? false) !== true) {
        throw new RuntimeException('MIGRATION_REGISTRY_MISSING');
    }
    if (($status['orphaned_records'] ?? []) !== []) {
        throw new RuntimeException('MIGRATION_PLAN_DB_DRIFT');
    }

    $rows = $status['migrations'] ?? null;
    if (!is_array($rows)) {
        throw new RuntimeException('MIGRATION_PLAN_DB_DRIFT');
    }

    $pending = [];
    $expectedSeen = $expected === '__NONE__';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('MIGRATION_PLAN_DB_DRIFT');
        }
        $name = (string)($row['migration'] ?? '');
        $state = (string)($row['state'] ?? '');
        if (!preg_match('/^migration_[a-z0-9_]+\\.sql$/', $name)) {
            throw new RuntimeException('MIGRATION_PLAN_DB_DRIFT');
        }
        if ($name === $expected) {
            $expectedSeen = true;
        }
        if ($state === 'applied') {
            continue;
        }
        if ($state === 'pending') {
            $pending[] = $name;
            continue;
        }
        if ($state === 'checksum_mismatch') {
            throw new RuntimeException('MIGRATION_CHECKSUM_MISMATCH');
        }
        throw new RuntimeException('MIGRATION_PLAN_DB_DRIFT');
    }

    if (!$expectedSeen) {
        throw new RuntimeException('MIGRATION_PLAN_DB_DRIFT');
    }
    if ($expected === '__NONE__') {
        if ($pending !== []) {
            throw new RuntimeException('MIGRATION_PLAN_DB_DRIFT');
        }
        return;
    }

    if ($pending !== [] && $pending !== [$expected]) {
        throw new RuntimeException('MIGRATION_PLAN_DB_DRIFT');
    }
}

function brvtal_migration_record(
    PDO $pdo,
    string $migrationName,
    string $checksum,
    ?string $actor,
    ?string $deploySha
): void {
    if (!preg_match('/^migration_[a-z0-9_]+\.sql$/', $migrationName)) {
        throw new InvalidArgumentException('INVALID_MIGRATION_NAME');
    }
    if (!preg_match('/^[a-f0-9]{64}$/', $checksum)) {
        throw new InvalidArgumentException('INVALID_MIGRATION_CHECKSUM');
    }
    if ($deploySha !== null && $deploySha !== '' && !preg_match('/^[a-f0-9]{7,40}$/i', $deploySha)) {
        throw new InvalidArgumentException('INVALID_DEPLOY_SHA');
    }

    $statement = $pdo->prepare(
        'INSERT INTO schema_migrations(migration_name, checksum_sha256, applied_by, deploy_sha) ' .
        'VALUES(?,?,?,?)'
    );
    $statement->execute([
        $migrationName,
        $checksum,
        $actor !== null && $actor !== '' ? substr($actor, 0, 120) : null,
        $deploySha !== null && $deploySha !== '' ? $deploySha : null,
    ]);
}

function brvtal_migration_apply_file(
    PDO $pdo,
    string $path,
    ?string $actor = null,
    ?string $deploySha = null
): array {
    if (!is_file($path)) {
        throw new RuntimeException('MIGRATION_FILE_MISSING');
    }

    $name = basename($path);
    if (!preg_match('/^migration_[a-z0-9_]+\.sql$/', $name)) {
        throw new InvalidArgumentException('INVALID_MIGRATION_NAME');
    }

    $checksum = brvtal_migration_checksum($path);
    $registryExists = brvtal_migration_registry_exists($pdo);

    if ($registryExists) {
        $statement = $pdo->prepare(
            'SELECT checksum_sha256 FROM schema_migrations WHERE migration_name=? LIMIT 1'
        );
        $statement->execute([$name]);
        $recorded = $statement->fetchColumn();
        if (is_string($recorded) && $recorded !== '') {
            if (!hash_equals($recorded, $checksum)) {
                throw new RuntimeException('MIGRATION_CHECKSUM_MISMATCH');
            }
            return ['migration' => $name, 'status' => 'already_applied', 'checksum_sha256' => $checksum];
        }
    } elseif ($name !== 'migration_schema_migrations_01.sql') {
        throw new RuntimeException('MIGRATION_REGISTRY_MISSING');
    }

    $sql = file_get_contents($path);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('MIGRATION_SQL_EMPTY');
    }
    // Historical/manual migrations keep their explicit operator path. The
    // automatic Factory planner invokes brvtalMigrationAssertAdditiveSql()
    // before selecting any migration for unattended deployment.
    $pdo->exec($sql);

    if (!brvtal_migration_registry_exists($pdo)) {
        throw new RuntimeException('MIGRATION_REGISTRY_NOT_CREATED');
    }

    brvtal_migration_record($pdo, $name, $checksum, $actor, $deploySha);

    return ['migration' => $name, 'status' => 'applied', 'checksum_sha256' => $checksum];
}

function brvtal_migration_baseline_file(
    PDO $pdo,
    string $path,
    ?string $actor = null,
    ?string $deploySha = null
): array {
    if (!brvtal_migration_registry_exists($pdo)) {
        throw new RuntimeException('MIGRATION_REGISTRY_MISSING');
    }
    if (!is_file($path)) {
        throw new RuntimeException('MIGRATION_FILE_MISSING');
    }

    $name = basename($path);
    if (!preg_match('/^migration_[a-z0-9_]+\.sql$/', $name)) {
        throw new InvalidArgumentException('INVALID_MIGRATION_NAME');
    }

    $checksum = brvtal_migration_checksum($path);
    $statement = $pdo->prepare(
        'SELECT checksum_sha256 FROM schema_migrations WHERE migration_name=? LIMIT 1'
    );
    $statement->execute([$name]);
    $recorded = $statement->fetchColumn();

    if (is_string($recorded) && $recorded !== '') {
        if (!hash_equals($recorded, $checksum)) {
            throw new RuntimeException('MIGRATION_CHECKSUM_MISMATCH');
        }
        return ['migration' => $name, 'status' => 'already_applied', 'checksum_sha256' => $checksum];
    }

    brvtal_migration_record($pdo, $name, $checksum, $actor, $deploySha);
    return ['migration' => $name, 'status' => 'baselined', 'checksum_sha256' => $checksum];
}
