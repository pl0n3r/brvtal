<?php
declare(strict_types=1);

/**
 * Canonical current membership for an Artist.
 *
 * is_collective_member is the only current-state authority once the migration
 * is present. Legacy lifecycle columns are read only as a deployment bridge
 * and for historical recovery; they are never preferred over the boolean.
 */
function brvtal_artist_collective_membership_value(array $artist): bool
{
    if (array_key_exists('is_collective_member', $artist)) {
        return (int)$artist['is_collective_member'] === 1;
    }
    return strtolower(trim((string)($artist['collective_status'] ?? 'none'))) === 'active';
}

/** Normalize the one writable membership field. @return array{payload:array,error:?array} */
function brvtal_artist_collective_normalize_payload(array $payload): array
{
    if (!array_key_exists('is_collective_member', $payload)) {
        return ['payload' => $payload, 'error' => null];
    }

    $value = $payload['is_collective_member'];
    if (is_bool($value)) {
        $payload['is_collective_member'] = $value ? 1 : 0;
        return ['payload' => $payload, 'error' => null];
    }
    if ((is_int($value) || is_float($value)) && ((float)$value === 0.0 || (float)$value === 1.0)) {
        $payload['is_collective_member'] = (int)$value;
        return ['payload' => $payload, 'error' => null];
    }
    if (is_string($value) && in_array(trim($value), ['0', '1'], true)) {
        $payload['is_collective_member'] = (int)trim($value);
        return ['payload' => $payload, 'error' => null];
    }

    return [
        'payload' => $payload,
        'error' => ['error' => 'INVALID_COLLECTIVE_MEMBERSHIP', 'field' => 'is_collective_member'],
    ];
}

function brvtal_artist_collective_column_exists(PDO $pdo, string $column): bool
{
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artists' AND COLUMN_NAME=?"
    );
    $st->execute([$column]);
    return $cache[$key] = ((int)$st->fetchColumn() === 1);
}

function brvtal_artist_collective_membership_schema_ready(PDO $pdo): bool
{
    return brvtal_artist_collective_column_exists($pdo, 'is_collective_member');
}

function brvtal_artist_collective_membership_sql(PDO $pdo, string $prefix = ''): string
{
    if (brvtal_artist_collective_membership_schema_ready($pdo)) {
        return $prefix . 'is_collective_member';
    }
    if (brvtal_artist_collective_column_exists($pdo, 'collective_status')) {
        return "CASE WHEN {$prefix}collective_status='active' THEN 1 ELSE 0 END";
    }
    return '0';
}

function brvtal_artist_collective_membership_enrich_row(array $row): array
{
    if (!array_key_exists('is_collective_member', $row)) {
        $row['is_collective_member'] = brvtal_artist_collective_membership_value($row) ? 1 : 0;
    }
    return $row;
}

function brvtal_artist_collective_membership_enrich_result(array $result): array
{
    if ($result === []) return [];
    if (array_is_list($result)) {
        return array_map(
            static fn(array $row): array => brvtal_artist_collective_membership_enrich_row($row),
            $result
        );
    }
    return brvtal_artist_collective_membership_enrich_row($result);
}

/**
 * Persist the canonical field when available. During the short pre-migration
 * deploy bridge, translate it to legacy active/none so Artist edits keep
 * working and the later migration can backfill losslessly.
 */
function brvtal_artist_collective_membership_storage_payload(PDO $pdo, array $payload): array
{
    if (!array_key_exists('is_collective_member', $payload)) return $payload;
    if (brvtal_artist_collective_membership_schema_ready($pdo)) return $payload;

    $member = (int)$payload['is_collective_member'] === 1;
    unset($payload['is_collective_member']);
    if (brvtal_artist_collective_column_exists($pdo, 'collective_status')) {
        $payload['collective_status'] = $member ? 'active' : 'none';
        return $payload;
    }
    if ($member) {
        throw new RuntimeException('COLLECTIVE_MEMBERSHIP_SCHEMA_MISSING');
    }
    return $payload;
}

function brvtal_artist_collective_state_changed(?array $before, ?array $after): bool
{
    return $after !== null
        && brvtal_artist_collective_membership_value($before ?? [])
            !== brvtal_artist_collective_membership_value($after);
}

function brvtal_artist_collective_datetime(mixed $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') return null;
    foreach (['Y-m-d H:i:s', 'Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date) continue;
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) continue;
        if ($date->format($format) !== $raw) continue;
        return $date->format('Y-m-d H:i:s');
    }
    return null;
}

function brvtal_artist_collective_history_schema_ready(PDO $pdo): bool
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (($ready[$key] ?? false) === true) return true;
    try {
        $pdo->query('SELECT 1 FROM artist_collective_history LIMIT 0');
        return $ready[$key] = true;
    } catch (PDOException) {
        return false;
    }
}

function brvtal_artist_collective_latest_history(PDO $pdo, int $artistId, bool $openOnly = false): ?array
{
    $sql = 'SELECT id,artist_id,status,started_at,ended_at,created_by FROM artist_collective_history WHERE artist_id=?';
    if ($openOnly) $sql .= ' AND ended_at IS NULL';
    $sql .= ' ORDER BY started_at DESC,id DESC LIMIT 1 FOR UPDATE';
    $st = $pdo->prepare($sql);
    $st->execute([$artistId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function brvtal_artist_collective_insert_history(
    PDO $pdo,
    int $artistId,
    string $status,
    string $startedAt,
    ?string $endedAt,
    ?int $createdBy,
    ?string $note = null
): void {
    $st = $pdo->prepare(
        'INSERT INTO artist_collective_history(artist_id,status,started_at,ended_at,note,created_by) VALUES(?,?,?,?,?,?)'
    );
    $st->execute([$artistId, $status, $startedAt, $endedAt, $note, $createdBy]);
}

/**
 * Keep history as an audit trail derived from the checkbox. History never
 * drives current membership and no lifecycle dates/statuses remain writable.
 */
function brvtal_artist_collective_sync_history(
    PDO $pdo,
    string $action,
    int $artistId,
    ?array $before,
    ?array $after,
    ?int $createdBy,
    ?DateTimeImmutable $now = null
): void {
    if ($artistId < 1 || $after === null || !in_array($action, ['create', 'update'], true)) return;
    if ($action === 'update' && !brvtal_artist_collective_state_changed($before, $after)) return;
    if (!brvtal_artist_collective_history_schema_ready($pdo)) {
        throw new RuntimeException('COLLECTIVE_HISTORY_SCHEMA_MISSING');
    }

    $wasMember = brvtal_artist_collective_membership_value($before ?? []);
    $isMember = brvtal_artist_collective_membership_value($after);
    if (!$wasMember && !$isMember) return;

    $actorId = $createdBy !== null && $createdBy > 0 ? $createdBy : null;
    $nowValue = ($now ?? new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $open = brvtal_artist_collective_latest_history($pdo, $artistId, true);

    if ($isMember) {
        if ($open) return;
        brvtal_artist_collective_insert_history(
            $pdo,
            $artistId,
            'active',
            $nowValue,
            null,
            $actorId,
            $action === 'create' ? 'Membership enabled on Artist creation' : 'Membership enabled'
        );
        return;
    }

    if (!$wasMember) return;
    if ($open) {
        $st = $pdo->prepare(
            "UPDATE artist_collective_history
             SET status='alumni',ended_at=?,note=COALESCE(note,?)
             WHERE id=?"
        );
        $st->execute([$nowValue, 'Membership disabled', (int)$open['id']]);
        return;
    }

    $legacyStart = brvtal_artist_collective_datetime($before['collective_joined_at'] ?? null) ?? $nowValue;
    brvtal_artist_collective_insert_history(
        $pdo,
        $artistId,
        'alumni',
        $legacyStart,
        $nowValue,
        $actorId,
        'Recovered historical period while disabling membership'
    );
}
