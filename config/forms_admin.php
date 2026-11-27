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
        'list' => ['view', 'form_key', 'state', 'management_state', 'from', 'to', 'limit', 'cursor'],
        'detail' => ['view', 'form_key', 'entry_id'],
        default => throw new InvalidArgumentException('INVALID_FILTER'),
    };
    if (array_diff(array_keys($input), $allowed)) {
        throw new InvalidArgumentException('INVALID_FILTER');
    }
    if ($view === 'catalog') return [$view, [], [], 50];
    $form = $input['form_key'] ?? '';
    if (!preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $form)) {
        throw new InvalidArgumentException('INVALID_FILTER');
    }
    $where = ['e.form_key=?'];
    $args = [$form];
    if ($view === 'detail') {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $input['entry_id'] ?? '')) {
            throw new InvalidArgumentException('INVALID_FILTER');
        }
        return [$view, [...$where, 'e.entry_id=UNHEX(?)'], [...$args, $input['entry_id']], 1];
    }
    if (isset($input['state'])) {
        if (!in_array($input['state'], ['received', 'reviewed', 'redacted'], true)) {
            throw new InvalidArgumentException('INVALID_FILTER');
        }
        $where[]='e.state=?';
        $args[]=$input['state'];
    }
    if (isset($input['management_state'])) {
        if (!in_array($input['management_state'], brvtalFormsManagementStates(), true)) {
            throw new InvalidArgumentException('INVALID_FILTER');
        }
        $where[] = brvtalFormsManagementStateSql().'=?';
        $args[] = $input['management_state'];
    }
    if (isset($input['from'], $input['to']) && $input['from']>$input['to']) {
        throw new InvalidArgumentException('INVALID_FILTER');
    }
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
        if (is_array($value)) $value=array_values(array_filter($value, is_scalar(...)));
        elseif ($value !== null && !is_scalar($value)) $value=null;
        $fields[]=['id' => $id, 'label' => is_string($field['label'] ?? null)?$field['label']:$id,
            'type' => $type, 'value' => $value];
    }
    unset($row['definition_json'], $row['payload_json']);
    return $row+['fields' => $fields];
}

function brvtalFormsAdminRead(PDO $pdo, array $input): array
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('FORMS_ADMIN_UNAVAILABLE');
    }
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $pdo->beginTransaction();
    try {
        $result = brvtalFormsAdminReadSnapshot($pdo, $input);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function brvtalFormsAdminReadSnapshot(PDO $pdo, array $input): array
{
    [$view, $where, $args, $limit]=brvtalFormsAdminFilters($input);
    if ($view === 'catalog') {
        $rows=$pdo->query("SELECT v.form_key,v.latest_version,COALESCE(c.total,0) AS total,COALESCE(c.new_count,0) AS new_count
            FROM (SELECT form_key,MAX(version_number) AS latest_version FROM form_definition_versions GROUP BY form_key) v
            LEFT JOIN (SELECT e.form_key,COUNT(*) AS total,
            SUM(CASE WHEN ".brvtalFormsManagementStateSql()."='new' THEN 1 ELSE 0 END) AS new_count
            FROM form_entries e ".brvtalFormsManagementJoin()." GROUP BY e.form_key) c
            ON c.form_key=v.form_key ORDER BY v.form_key")->fetchAll(PDO::FETCH_ASSOC);
        return ['items' => $rows];
    }
    $predicate=implode(' AND ', $where);
    $join = brvtalFormsManagementJoin();
    $columns='LOWER(HEX(e.entry_id)) AS entry_id,e.form_key,e.version_number,e.state,e.created_at_utc,'
        .brvtalFormsManagementStateSql().' AS management_state,'
        .'CASE WHEN '.brvtalFormsManagementLiveSql().' THEN COALESCE(m.revision,0) END AS revision';
    if ($view === 'detail') {
        $q=$pdo->prepare("SELECT $columns,e.notice_version,e.purpose_key,e.retention_until_utc,e.payload_json,v.definition_json
            FROM form_entries e $join JOIN form_definition_versions v ON v.form_key=e.form_key
            AND v.version_number=e.version_number AND v.definition_sha256=e.definition_sha256 WHERE $predicate");
        $q->execute($args);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('ENTRY_NOT_FOUND');
        }
        $q=$pdo->prepare('SELECT action_key,state,created_at_utc FROM form_notification_intents WHERE entry_id=UNHEX(?) ORDER BY action_key');
        $q->execute([$row['entry_id']]);
        $notifications = $q->fetchAll(PDO::FETCH_ASSOC);
        $notes = [];
        if ($row['revision'] !== null) {
            $q = $pdo->prepare("SELECT revision,actor_admin_id,created_at_utc,note_text
                FROM form_entry_management WHERE entry_id=UNHEX(?) AND action_kind='note'
                AND note_text IS NOT NULL ORDER BY revision");
            $q->execute([$row['entry_id']]);
            $notes = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        return brvtalFormsAdminHistorical($row)+['notifications' => $notifications, 'notes' => $notes];
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM form_entries e $join WHERE $predicate");
    $q->execute($args);
    $total=(int)$q->fetchColumn();
    if (isset($input['cursor'])) {
        $q=$pdo->prepare('SELECT created_at_utc FROM form_entries WHERE form_key=? AND entry_id=UNHEX(?)');
        $q->execute([$input['form_key'], $input['cursor']]);
        $date=$q->fetchColumn();
        if (!is_string($date)) {
            throw new InvalidArgumentException('INVALID_FILTER');
        }
        $predicate.=' AND (e.created_at_utc<? OR (e.created_at_utc=? AND e.entry_id<UNHEX(?)))';
        $args=[...$args, $date, $date, $input['cursor']];
    }
    $q=$pdo->prepare("SELECT $columns FROM form_entries e $join WHERE $predicate
        ORDER BY e.created_at_utc DESC,e.entry_id DESC LIMIT ".($limit+1));
    $q->execute($args);
    $items=$q->fetchAll(PDO::FETCH_ASSOC);
    $more=count($items)>$limit;
    if ($more) array_pop($items);
    return ['items' => $items, 'total' => $total, 'has_more' => $more, 'next_cursor' => $more?$items[count($items)-1]['entry_id']:null];
}

function brvtalFormsManagementStates(): array
{
    return ['new', 'in_progress', 'resolved', 'archived', 'spam'];
}

function brvtalFormsManagementLiveSql(): string
{
    return "(e.state<>'redacted' AND e.retention_until_utc>UTC_TIMESTAMP(6))";
}

function brvtalFormsManagementStateSql(): string
{
    return "CASE WHEN ".brvtalFormsManagementLiveSql()."
        THEN COALESCE(m.workflow_state,IF(e.state='received','new','in_progress')) END";
}

function brvtalFormsManagementJoin(): string
{
    return 'LEFT JOIN form_entry_management m ON m.entry_id=e.entry_id AND m.revision=
        (SELECT MAX(h.revision) FROM form_entry_management h WHERE h.entry_id=e.entry_id)';
}

/** Strict action contract; the note is supplied explicitly, never copied from the entry. */
function brvtalFormsManagementInput(array $input): array
{
    $action = $input['action'] ?? null;
    $keys = ['action', 'form_key', 'entry_id', 'expected_revision'];
    $valueKey = match ($action) {
        'transition' => 'state',
        'note' => 'note',
        default => throw new InvalidArgumentException('INVALID_ACTION'),
    };
    if (array_diff(array_keys($input), [...$keys, $valueKey])
        || count($input) !== 5
        || !is_string($input['form_key'] ?? null) || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $input['form_key'])
        || !is_string($input['entry_id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/', $input['entry_id'])
        || !is_int($input['expected_revision'] ?? null) || $input['expected_revision'] < 0
        || $input['expected_revision'] >= 4294967295 || !is_string($input[$valueKey] ?? null)) {
        throw new InvalidArgumentException('INVALID_ACTION');
    }
    $value = trim($input[$valueKey]);
    if ($action === 'transition' ? !in_array($value, brvtalFormsManagementStates(), true)
        : (strlen($value) > 4000 || !preg_match('/\A.{1,1000}\z/us', $value)
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value))) {
        throw new InvalidArgumentException('INVALID_ACTION');
    }
    return [$action, $input['form_key'], $input['entry_id'], $input['expected_revision'], $value];
}

function brvtalFormsAdminAction(PDO $pdo, int $actor, array $input): array
{
    [$action, $form, $entry, $expected, $value] = brvtalFormsManagementInput($input);
    if ($pdo->inTransaction()) {
        throw new RuntimeException('FORMS_ADMIN_UNAVAILABLE');
    }
    $pdo->beginTransaction();
    try {
        // Lock the acting account and parent row; redaction and all actions serialize on the parent.
        $q = $pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 FOR UPDATE');
        $q->execute([$actor]);
        if (!in_array($q->fetchColumn(), ['owner', 'superadmin'], true)) {
            throw new DomainException('FORBIDDEN');
        }
        $q = $pdo->prepare('SELECT e.state,'.brvtalFormsManagementLiveSql().' AS live
            FROM form_entries e WHERE e.form_key=? AND e.entry_id=UNHEX(?) FOR UPDATE');
        $q->execute([$form, $entry]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('ENTRY_NOT_FOUND');
        }
        if (!(bool)$row['live']) {
            throw new DomainException('ENTRY_UNAVAILABLE');
        }
        $q = $pdo->prepare('SELECT revision,workflow_state FROM form_entry_management
            WHERE entry_id=UNHEX(?) ORDER BY revision DESC LIMIT 1 FOR UPDATE');
        $q->execute([$entry]);
        $last = $q->fetch(PDO::FETCH_ASSOC);
        $revision = (int)($last['revision'] ?? 0);
        if ($revision !== $expected) {
            throw new DomainException('STALE_REVISION');
        }
        $state = $last['workflow_state'] ?? ($row['state'] === 'received' ? 'new' : 'in_progress');
        if ($action === 'transition') {
            $allowed = match ($state) {
                'new' => ['in_progress', 'spam'],
                'in_progress' => ['resolved', 'spam'],
                'resolved' => ['archived', 'in_progress'],
                'spam' => ['in_progress', 'archived'],
                'archived' => ['in_progress'],
                default => [],
            };
            if (!in_array($value, $allowed, true)) {
                throw new DomainException('INVALID_TRANSITION');
            }
            $state = $value;
        }
        // Recheck retention using database time immediately before append.
        $q = $pdo->prepare('INSERT INTO form_entry_management
            (entry_id,revision,workflow_state,action_kind,actor_admin_id,created_at_utc,note_text)
            SELECT e.entry_id,?,?,?,?,UTC_TIMESTAMP(6),? FROM form_entries e
            WHERE e.entry_id=UNHEX(?) AND e.form_key=? AND '.brvtalFormsManagementLiveSql());
        $q->execute([$revision+1, $state, $action, $actor, $action === 'note' ? $value : null, $entry, $form]);
        if ($q->rowCount() !== 1) {
            throw new DomainException('ENTRY_UNAVAILABLE');
        }
        $pdo->commit();
        return ['entry_id' => $entry, 'management_state' => $state, 'revision' => $revision+1];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
