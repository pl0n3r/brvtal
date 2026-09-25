<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations.php';

/**
 * Resolve the Factory-facing readiness state from exact runtime identity and
 * the canonical migration registry snapshot. This helper is pure/read-only.
 */
function brvtalFactoryReadinessState(bool $exact, ?string $releaseSha, array $migrationStatus): array
{
    $safeSha = null;
    if (
        $exact
        && is_string($releaseSha)
        && preg_match('/^[a-f0-9]{40}$/i', $releaseSha) === 1
    ) {
        $safeSha = strtolower($releaseSha);
    }

    $schemaUpToDate = false;
    try {
        brvtalMigrationVerifyPlanStatus($migrationStatus, '__NONE__');
        $schemaUpToDate = true;
    } catch (Throwable) {
        // Health is observational and fail-closed; it never repairs schema state.
    }

    $ready = $safeSha !== null && $schemaUpToDate;

    return [
        'ready' => $ready,
        'status' => $ready ? 'ok' : 'degraded',
        'health_status' => $ready ? 'healthy' : 'degraded',
        'release_sha' => $safeSha,
        'schema_up_to_date' => $schemaUpToDate,
    ];
}

function brvtalMigrationReadinessSummary(array $status): array
{
    $counts = [
        'applied' => 0,
        'pending' => 0,
        'checksum_mismatch' => 0,
        'unknown' => 0,
    ];

    $rows = $status['migrations'] ?? [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $state = is_array($row) ? (string)($row['state'] ?? '') : '';
            if (array_key_exists($state, $counts)) {
                $counts[$state]++;
            } else {
                $counts['unknown']++;
            }
        }
    } else {
        $counts['unknown']++;
    }

    $orphans = $status['orphaned_records'] ?? [];
    return [
        'registry_exists' => ($status['registry_exists'] ?? false) === true,
        'applied' => $counts['applied'],
        'pending' => $counts['pending'],
        'checksum_mismatch' => $counts['checksum_mismatch'],
        'unknown' => $counts['unknown'],
        'orphaned_records' => is_array($orphans) ? count($orphans) : 1,
    ];
}
