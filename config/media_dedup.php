<?php
declare(strict_types=1);

/**
 * Exact-content Media deduplication helpers.
 *
 * The schema migration is intentionally explicit. Reads remain compatible with
 * legacy installations; new physical uploads must not silently bypass exact
 * deduplication after the feature ships.
 */

function brvtal_media_dedup_schema_state(PDO $pdo): array
{
    $column = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS " .
        "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media' AND COLUMN_NAME='content_hash'"
    )->fetchColumn() === 1;

    $uniqueIndex = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS " .
        "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media' " .
        "AND INDEX_NAME='uq_media_content_hash' AND NON_UNIQUE=0"
    )->fetchColumn() >= 1;

    return [
        'column' => $column,
        'unique_index' => $uniqueIndex,
        'ready' => $column && $uniqueIndex,
        'migration' => 'migration_media_content_hash_01.sql',
    ];
}

function brvtal_media_dedup_select_columns(PDO $pdo): string
{
    $columns = 'id,type,title,file_path,mime_type,file_size,alt_text,status,created_at';
    if (brvtal_media_dedup_schema_state($pdo)['column']) {
        $columns .= ',content_hash';
    }
    return $columns;
}

function brvtal_media_content_hash(string $path): string
{
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('MEDIA_HASH_SOURCE_MISSING');
    }
    $hash = hash_file('sha256', $path);
    if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
        throw new RuntimeException('MEDIA_HASH_FAILED');
    }
    return $hash;
}

function brvtal_media_find_by_content_hash(PDO $pdo, string $hash): ?array
{
    if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
        throw new InvalidArgumentException('INVALID_MEDIA_CONTENT_HASH');
    }
    $state = brvtal_media_dedup_schema_state($pdo);
    if (!$state['column']) {
        return null;
    }

    $sql = 'SELECT ' . brvtal_media_dedup_select_columns($pdo) .
        ' FROM media WHERE content_hash=? ORDER BY id ASC LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function brvtal_media_is_unique_hash_conflict(Throwable $error): bool
{
    if (!$error instanceof PDOException) {
        return false;
    }
    $driverCode = (int)($error->errorInfo[1] ?? 0);
    return $driverCode === 1062
        && str_contains(strtolower($error->getMessage()), 'uq_media_content_hash');
}

/**
 * Look for an exact duplicate without hashing the whole legacy library.
 *
 * Existing hashed rows are O(1) through the unique index. Legacy NULL rows are
 * considered only when file_size + mime_type match, and filesystem hashing is
 * bounded to 32 local candidates / 128 MiB per request. If that bounded scan
 * cannot prove the candidate set complete, callers must fail closed rather than
 * create a possible duplicate.
 *
 * @return array{
 *   duplicate:?array,
 *   source:?string,
 *   scan_complete:bool,
 *   hashed_candidates:int,
 *   skipped_candidates:int,
 *   bytes_hashed:int
 * }
 */
function brvtal_media_find_duplicate(
    PDO $pdo,
    string $incomingHash,
    int $fileSize,
    string $mimeType
): array {
    if (preg_match('/^[a-f0-9]{64}$/', $incomingHash) !== 1) {
        throw new InvalidArgumentException('INVALID_MEDIA_CONTENT_HASH');
    }
    if ($fileSize < 1 || $mimeType === '') {
        throw new InvalidArgumentException('INVALID_MEDIA_DEDUP_CANDIDATE');
    }

    $state = brvtal_media_dedup_schema_state($pdo);
    if (!$state['ready']) {
        return [
            'duplicate' => null,
            'source' => null,
            'scan_complete' => false,
            'hashed_candidates' => 0,
            'skipped_candidates' => 0,
            'bytes_hashed' => 0,
        ];
    }

    $existing = brvtal_media_find_by_content_hash($pdo, $incomingHash);
    if ($existing !== null) {
        return [
            'duplicate' => $existing,
            'source' => 'indexed',
            'scan_complete' => true,
            'hashed_candidates' => 0,
            'skipped_candidates' => 0,
            'bytes_hashed' => 0,
        ];
    }

    $candidateLimit = 65;
    $maxHashedCandidates = 32;
    $maxHashBytes = 128 * 1024 * 1024;
    $sql = 'SELECT ' . brvtal_media_dedup_select_columns($pdo) .
        ' FROM media WHERE content_hash IS NULL AND file_size=? AND mime_type=? ' .
        'ORDER BY id ASC LIMIT ' . $candidateLimit;
    $st = $pdo->prepare($sql);
    $st->execute([$fileSize, $mimeType]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $hasMoreRows = count($rows) === $candidateLimit;
    if ($hasMoreRows) {
        array_pop($rows);
    }

    $hashedCandidates = 0;
    $skippedCandidates = 0;
    $bytesHashed = 0;

    foreach ($rows as $row) {
        $absolute = brvtal_media_local_absolute((string)($row['file_path'] ?? ''));
        if ($absolute === null || !is_file($absolute)) {
            $skippedCandidates++;
            continue;
        }

        $actualSize = (int)(@filesize($absolute) ?: 0);
        if ($actualSize !== $fileSize) {
            $skippedCandidates++;
            continue;
        }

        if ($hashedCandidates >= $maxHashedCandidates || ($bytesHashed + $actualSize) > $maxHashBytes) {
            return [
                'duplicate' => null,
                'source' => null,
                'scan_complete' => false,
                'hashed_candidates' => $hashedCandidates,
                'skipped_candidates' => $skippedCandidates,
                'bytes_hashed' => $bytesHashed,
            ];
        }

        $candidateHash = brvtal_media_content_hash($absolute);
        $hashedCandidates++;
        $bytesHashed += $actualSize;

        try {
            $update = $pdo->prepare('UPDATE media SET content_hash=? WHERE id=? AND content_hash IS NULL');
            $update->execute([$candidateHash, (int)$row['id']]);
        } catch (PDOException $error) {
            if (!brvtal_media_is_unique_hash_conflict($error)) {
                throw $error;
            }
        }

        if (hash_equals($incomingHash, $candidateHash)) {
            $winner = brvtal_media_find_by_content_hash($pdo, $incomingHash);
            if ($winner !== null) {
                return [
                    'duplicate' => $winner,
                    'source' => 'legacy_backfill',
                    'scan_complete' => true,
                    'hashed_candidates' => $hashedCandidates,
                    'skipped_candidates' => $skippedCandidates,
                    'bytes_hashed' => $bytesHashed,
                ];
            }
        }
    }

    return [
        'duplicate' => null,
        'source' => null,
        'scan_complete' => !$hasMoreRows,
        'hashed_candidates' => $hashedCandidates,
        'skipped_candidates' => $skippedCandidates,
        'bytes_hashed' => $bytesHashed,
    ];
}
