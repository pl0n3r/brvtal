<?php
declare(strict_types=1);

/**
 * Canonical current-state rules for Artist collective membership.
 *
 * Lifecycle edits are intentionally atomic: whenever status or membership dates
 * are touched, callers must submit the complete trio so partial updates cannot
 * create a state that is impossible to interpret later.
 *
 * @return array{payload:array,error:?array}
 */
function brvtal_artist_collective_normalize_payload(array $payload): array
{
    $fields = ['collective_status', 'collective_joined_at', 'collective_left_at'];
    $touched = false;
    foreach ($fields as $field) {
        if (array_key_exists($field, $payload)) {
            $touched = true;
            break;
        }
    }
    if (!$touched) return ['payload' => $payload, 'error' => null];

    foreach ($fields as $field) {
        if (!array_key_exists($field, $payload)) {
            return [
                'payload' => $payload,
                'error' => ['error' => 'COLLECTIVE_LIFECYCLE_FIELDS_REQUIRED', 'field' => $field],
            ];
        }
    }

    $status = strtolower(trim((string)$payload['collective_status']));
    if (!in_array($status, ['none', 'active', 'alumni'], true)) {
        return [
            'payload' => $payload,
            'error' => ['error' => 'INVALID_COLLECTIVE_STATUS', 'field' => 'collective_status'],
        ];
    }
    $payload['collective_status'] = $status;

    $joined = trim((string)($payload['collective_joined_at'] ?? ''));
    $left = trim((string)($payload['collective_left_at'] ?? ''));
    $joined = $joined === '' ? null : $joined;
    $left = $left === '' ? null : $left;
    $payload['collective_joined_at'] = $joined;
    $payload['collective_left_at'] = $left;

    if ($status === 'none') {
        if ($joined !== null || $left !== null) {
            return [
                'payload' => $payload,
                'error' => ['error' => 'COLLECTIVE_NONE_DATES_NOT_ALLOWED', 'field' => $joined !== null ? 'collective_joined_at' : 'collective_left_at'],
            ];
        }
        return ['payload' => $payload, 'error' => null];
    }

    if ($joined === null) {
        return [
            'payload' => $payload,
            'error' => ['error' => 'COLLECTIVE_JOINED_AT_REQUIRED', 'field' => 'collective_joined_at'],
        ];
    }

    if ($status === 'active') {
        if ($left !== null) {
            return [
                'payload' => $payload,
                'error' => ['error' => 'COLLECTIVE_ACTIVE_LEFT_AT_NOT_ALLOWED', 'field' => 'collective_left_at'],
            ];
        }
        return ['payload' => $payload, 'error' => null];
    }

    if ($left === null) {
        return [
            'payload' => $payload,
            'error' => ['error' => 'COLLECTIVE_LEFT_AT_REQUIRED', 'field' => 'collective_left_at'],
        ];
    }
    if ($left < $joined) {
        return [
            'payload' => $payload,
            'error' => ['error' => 'COLLECTIVE_DATE_ORDER_INVALID', 'field' => 'collective_left_at'],
        ];
    }

    return ['payload' => $payload, 'error' => null];
}

/** Return true when the persisted membership state changed in a meaningful way. */
function brvtal_artist_collective_state_changed(?array $before, ?array $after): bool
{
    if ($after === null) return false;
    foreach (['collective_status', 'collective_joined_at', 'collective_left_at'] as $field) {
        $left = trim((string)($before[$field] ?? ''));
        $right = trim((string)($after[$field] ?? ''));
        if ($left !== $right) return true;
    }
    return false;
}

/** Resolve a membership date as a MariaDB DATETIME string. */
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

/** Ensure the Content Core membership-history table is queryable before accepting traceable changes. */
function brvtal_artist_collective_history_schema_ready(PDO $pdo): bool
{
    static $ready = [];
    $key = spl_object_id($pdo);
    if (($ready[$key] ?? false) === true) return true;

    try {
        $pdo->query('SELECT 1 FROM artist_collective_history LIMIT 0');
        $ready[$key] = true;
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** Fetch the most recent history period under the caller's existing transaction. */
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

/** Fetch and lock the most recently ended membership period. */
function brvtal_artist_collective_latest_closed_history(PDO $pdo, int $artistId): ?array
{
    $st = $pdo->prepare(
        'SELECT id,artist_id,status,started_at,ended_at,created_by
         FROM artist_collective_history
         WHERE artist_id=? AND ended_at IS NOT NULL
         ORDER BY ended_at DESC,id DESC
         LIMIT 1 FOR UPDATE'
    );
    $st->execute([$artistId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Insert one traceable collective-membership period. */
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
 * Synchronize the durable membership history from a successful Artist create/update.
 *
 * This runs inside the same transaction as the Artist mutation and Admin Activity.
 * Existing legacy rows without history are repaired on their next lifecycle edit.
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

    $previousStatus = strtolower(trim((string)($before['collective_status'] ?? 'none')));
    $nextStatus = strtolower(trim((string)($after['collective_status'] ?? 'none')));
    $joinedAt = brvtal_artist_collective_datetime($after['collective_joined_at'] ?? null);
    $leftAt = brvtal_artist_collective_datetime($after['collective_left_at'] ?? null);
    $actorId = $createdBy !== null && $createdBy > 0 ? $createdBy : null;
    $nowValue = ($now ?? new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    if ($nextStatus === 'none') {
        if ($previousStatus !== 'active') return;
        $open = brvtal_artist_collective_latest_history($pdo, $artistId, true);
        $legacyStart = brvtal_artist_collective_datetime($before['collective_joined_at'] ?? null);
        if ($open) {
            $st = $pdo->prepare("UPDATE artist_collective_history SET status='alumni',ended_at=?,note=COALESCE(note,?) WHERE id=?");
            $st->execute([$nowValue, 'Auto-closed when collective status changed to none', (int)$open['id']]);
        } elseif ($legacyStart !== null) {
            brvtal_artist_collective_insert_history(
                $pdo,
                $artistId,
                'alumni',
                $legacyStart,
                $nowValue,
                $actorId,
                'Recovered legacy active period while leaving collective'
            );
        }
        return;
    }

    if ($joinedAt === null) {
        throw new RuntimeException('COLLECTIVE_HISTORY_START_MISSING');
    }

    if ($nextStatus === 'active') {
        if ($previousStatus === 'active') {
            $open = brvtal_artist_collective_latest_history($pdo, $artistId, true);
            if ($open) {
                $st = $pdo->prepare("UPDATE artist_collective_history SET status='active',started_at=?,ended_at=NULL WHERE id=?");
                $st->execute([$joinedAt, (int)$open['id']]);
                return;
            }
        } else {
            $latestClosed = brvtal_artist_collective_latest_closed_history($pdo, $artistId);
            $latestEndedAt = trim((string)($latestClosed['ended_at'] ?? ''));
            if ($latestEndedAt !== '' && $joinedAt < $latestEndedAt) {
                throw new DomainException('COLLECTIVE_HISTORY_OVERLAP');
            }
        }
        brvtal_artist_collective_insert_history($pdo, $artistId, 'active', $joinedAt, null, $actorId);
        return;
    }

    if ($leftAt === null) {
        throw new RuntimeException('COLLECTIVE_HISTORY_END_MISSING');
    }

    if ($previousStatus === 'active') {
        $open = brvtal_artist_collective_latest_history($pdo, $artistId, true);
        if ($open) {
            $st = $pdo->prepare("UPDATE artist_collective_history SET status='alumni',started_at=?,ended_at=? WHERE id=?");
            $st->execute([$joinedAt, $leftAt, (int)$open['id']]);
            return;
        }
    }

    if ($previousStatus === 'alumni') {
        $latest = brvtal_artist_collective_latest_history($pdo, $artistId, false);
        if ($latest && $latest['ended_at'] !== null) {
            $st = $pdo->prepare("UPDATE artist_collective_history SET status='alumni',started_at=?,ended_at=? WHERE id=?");
            $st->execute([$joinedAt, $leftAt, (int)$latest['id']]);
            return;
        }
    }

    brvtal_artist_collective_insert_history($pdo, $artistId, 'alumni', $joinedAt, $leftAt, $actorId);
}
