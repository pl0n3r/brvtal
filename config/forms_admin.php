<?php
declare(strict_types=1);

/** Build-ahead: activating real reads requires the separately approved privacy gate. */
function brvtalFormsAdminEnabled(array $config): bool
{
    return ($config['forms']['admin_read_enabled'] ?? null) === true
        && ($config['forms']['privacy_approved'] ?? null) === true;
}

function brvtalFormsAdminRequire(PDO $pdo, int $actor): void
{
    $q = $pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1');
    $q->execute([$actor]);
    if (!in_array($q->fetchColumn(), ['owner', 'superadmin'], true)) {
        throw new DomainException('FORBIDDEN');
    }
}

function brvtalFormsAdminDate(string $value, bool $exclusiveEnd = false): string
{
    if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)) {
        throw new InvalidArgumentException('INVALID_FILTER');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('America/Bogota'));
    if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01' || $value > '9999-12-29') {
        throw new InvalidArgumentException('INVALID_FILTER');
    }
    return ($exclusiveEnd ? $date->modify('+1 day') : $date)
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
}

/** Build only allowlisted predicates; all user values are bound parameters. */
function brvtalFormsAdminFilters(array $input): array
{
    foreach ($input as $key => $value) {
        if (!is_string($key) || !is_string($value) || strlen($value)>128) {
            throw new InvalidArgumentException('INVALID_FILTER');
        }
    }
    $view = $input['view'] ?? 'catalog';
    $allowed = match ($view) {
        'catalog' => ['view'],
        'list' => ['view', 'form_key', 'state', 'from', 'to', 'limit', 'cursor'],
        'detail' => ['view', 'form_key', 'entry_id'],
        default => throw new InvalidArgumentException('INVALID_FILTER'),
    };
    if (array_diff(array_keys($input), $allowed)) throw new InvalidArgumentException('INVALID_FILTER');
    if ($view === 'catalog') return [$view, [], [], 50];
    $form = $input['form_key'] ?? '';
    if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $form)) {
        throw new InvalidArgumentException('INVALID_FILTER');
    }
    $where = ['e.form_key=?'];
    $args = [$form];
    if ($view === 'detail') {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $input['entry_id'] ?? '')) throw new InvalidArgumentException('INVALID_FILTER');
        return [$view, [...$where, 'e.entry_id=UNHEX(?)'], [...$args, $input['entry_id']], 1];
    }
    if (isset($input['state'])) {
        if (!in_array($input['state'], ['received', 'reviewed', 'redacted'], true)) throw new InvalidArgumentException('INVALID_FILTER');
        $where[]='e.state=?';
        $args[]=$input['state'];
    }
    if (isset($input['from'], $input['to']) && $input['from']>$input['to']) throw new InvalidArgumentException('INVALID_FILTER');
    foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
        if (isset($input[$key])) {
            $where[]='e.created_at_utc'.$op.'?';
            $args[]=brvtalFormsAdminDate($input[$key], $key==='to');
        }
    }
    $limit=$input['limit'] ?? '50';
    if (!preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/', $limit)
        || (isset($input['cursor']) && !preg_match('/\A[a-f0-9]{32}\z/', $input['cursor']))) {
        throw new InvalidArgumentException('INVALID_FILTER');
    }
    return [$view, $where, $args, (int)$limit];
}

/** Project historical fields rather than returning raw definition or unapproved payload keys. */
function brvtalFormsAdminHistorical(array $row): array
{
    $definition=json_decode($row['definition_json'], true, 32, JSON_THROW_ON_ERROR);
    $payload=$row['payload_json'] === null ? [] : json_decode($row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
    $fields=[];
    foreach (($definition['fields'] ?? []) as $field) {
        $id=$field['id'] ?? null;
        $type=$field['type'] ?? null;
        if (!is_string($id) || !preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/', $id)
            || preg_match('/password|secret|token|csrf|credential|authorization|payment|card|qr|api[_-]?key/i', $id)
            || !in_array($type, ['text', 'email', 'textarea', 'select', 'radio', 'checkbox', 'date', 'consent', 'tel'], true)) continue;
        $value=$payload[$id] ?? null;
        if (is_array($value)) $value=array_values(array_filter($value, static fn($v) => is_scalar($v)));
        elseif ($value !== null && !is_scalar($value)) $value=null;
        $fields[]=['id' => $id, 'label' => is_string($field['label'] ?? null)?$field['label']:$id,
            'type' => $type, 'value' => $value];
    }
    unset($row['definition_json'], $row['payload_json']);
    return $row+['fields' => $fields];
}

function brvtalFormsAdminRead(PDO $pdo, array $input): array
{
    [$view, $where, $args, $limit]=brvtalFormsAdminFilters($input);
    if ($view === 'catalog') {
        $rows=$pdo->query("SELECT v.form_key,v.latest_version,COALESCE(c.total,0) AS total,COALESCE(c.new_count,0) AS new_count
            FROM (SELECT form_key,MAX(version_number) AS latest_version FROM form_definition_versions GROUP BY form_key) v
            LEFT JOIN (SELECT form_key,COUNT(*) AS total,SUM(state='received') AS new_count FROM form_entries GROUP BY form_key) c
            ON c.form_key=v.form_key ORDER BY v.form_key")->fetchAll(PDO::FETCH_ASSOC);
        return ['items' => $rows];
    }
    $predicate=implode(' AND ', $where);
    $columns='LOWER(HEX(e.entry_id)) AS entry_id,e.form_key,e.version_number,e.state,e.created_at_utc';
    if ($view === 'detail') {
        $q=$pdo->prepare("SELECT $columns,e.notice_version,e.purpose_key,e.retention_until_utc,e.payload_json,v.definition_json
            FROM form_entries e JOIN form_definition_versions v ON v.form_key=e.form_key
            AND v.version_number=e.version_number AND v.definition_sha256=e.definition_sha256 WHERE $predicate");
        $q->execute($args);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('ENTRY_NOT_FOUND');
        $q=$pdo->prepare('SELECT action_key,state,created_at_utc FROM form_notification_intents WHERE entry_id=UNHEX(?) ORDER BY action_key');
        $q->execute([$row['entry_id']]);
        return brvtalFormsAdminHistorical($row)+['notifications' => $q->fetchAll(PDO::FETCH_ASSOC)];
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM form_entries e WHERE $predicate");
    $q->execute($args);
    $total=(int)$q->fetchColumn();
    if (isset($input['cursor'])) {
        $q=$pdo->prepare("SELECT e.created_at_utc FROM form_entries e WHERE $predicate AND e.entry_id=UNHEX(?)");
        $q->execute([...$args, $input['cursor']]);
        $date=$q->fetchColumn();
        if (!is_string($date)) throw new InvalidArgumentException('INVALID_FILTER');
        $predicate.=' AND (e.created_at_utc<? OR (e.created_at_utc=? AND e.entry_id<UNHEX(?)))';
        $args=[...$args, $date, $date, $input['cursor']];
    }
    $q=$pdo->prepare("SELECT $columns FROM form_entries e WHERE $predicate ORDER BY e.created_at_utc DESC,e.entry_id DESC LIMIT ".($limit+1));
    $q->execute($args);
    $items=$q->fetchAll(PDO::FETCH_ASSOC);
    $more=count($items)>$limit;
    if ($more) array_pop($items);
    return ['items' => $items, 'total' => $total, 'has_more' => $more, 'next_cursor' => $more?$items[count($items)-1]['entry_id']:null];
}
