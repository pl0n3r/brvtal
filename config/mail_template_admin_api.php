<?php
declare(strict_types=1);

require_once __DIR__ . '/mail_template_store.php';
require_once __DIR__ . '/ticketing_staff_permissions.php';

/** Only mail-capable DISCADMIN staff may read; only owner may mutate. */
function brvtalMailAdminRequire(string $role, bool $write): void
{
    if (!brvtalTicketingCan($role, 'mail')) {
        throw new DomainException('FORBIDDEN');
    }
    if ($write && !in_array(strtolower(trim($role)),
        ['superadmin','owner','root','platform_owner'], true)) {
        throw new DomainException('FORBIDDEN');
    }
}

/** Strict request shape: unknown input fields and loosely cast ids are rejected. */
function brvtalMailAdminOnlyFields(array $input, array $expected): void
{
    $keys = array_keys($input);
    sort($keys);
    sort($expected);
    if ($keys !== $expected) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_INPUT_INVALID');
    }
}

function brvtalMailAdminPositiveId(mixed $value): int
{
    if (!is_int($value) || $value < 1 || $value > 2147483647) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_ID_INVALID');
    }
    return $value;
}

function brvtalMailAdminRevision(mixed $value): int
{
    if (!is_int($value) || $value < 0 || $value > 2147483646) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_REVISION_INVALID');
    }
    return $value;
}

/** @return array<string,mixed> */
function brvtalMailAdminParseGet(array $query): array
{
    $view = $query['view'] ?? null;
    if (!is_string($view) || !in_array($view, ['catalog','active','draft','history'], true)) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_VIEW_INVALID');
    }
    if ($view === 'catalog') {
        brvtalMailAdminOnlyFields($query, ['view']);
        return ['view'=>'catalog'];
    }
    $expected = $view === 'draft' ? ['view','key','scope','version_id'] : ['view','key','scope'];
    brvtalMailAdminOnlyFields($query, $expected);
    if (!is_string($query['key']) || !is_string($query['scope'])) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_KEY_INVALID');
    }
    brvtal_mail_template_definition($query['key'], $query['scope']);
    $parsed = ['view'=>$view, 'key'=>$query['key'], 'scope'=>$query['scope']];
    if ($view === 'draft') {
        $raw = $query['version_id'];
        if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,9}$/D', $raw)
            || (float)$raw > 2147483647) {
            throw new InvalidArgumentException('MAIL_TEMPLATE_VERSION_INVALID');
        }
        $parsed['version_id'] = (int)$raw;
    }
    return $parsed;
}

/** @return array<string,mixed> */
function brvtalMailAdminParsePost(array $payload): array
{
    $action = $payload['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['save_draft','publish','restore','preview'], true)) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_ACTION_INVALID');
    }
    $fields = match ($action) {
        'save_draft' => ['action','key','scope','template_copy','revision'],
        'publish' => ['action','key','scope','version_id','revision'],
        'restore' => ['action','key','scope','previous_version_id','revision'],
        'preview' => ['action','key','scope','template_copy'],
    };
    brvtalMailAdminOnlyFields($payload, $fields);
    if (!is_string($payload['key']) || !is_string($payload['scope'])) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_KEY_INVALID');
    }
    brvtal_mail_template_definition($payload['key'], $payload['scope']);
    if (isset($payload['revision'])) {
        $payload['revision'] = brvtalMailAdminRevision($payload['revision']);
    }
    foreach (['version_id','previous_version_id'] as $id) {
        if (array_key_exists($id, $payload)) {
            $payload[$id] = brvtalMailAdminPositiveId($payload[$id]);
        }
    }
    if (in_array($action, ['save_draft','preview'], true)
        && (!is_array($payload['template_copy']) || array_is_list($payload['template_copy']))) {
        throw new InvalidArgumentException('MAIL_TEMPLATE_DOCUMENT_INVALID');
    }
    return $payload;
}

/**
 * No arbitrary media URL. Only already-published local image assets can appear
 * in a draft or a preview; failures deny the request rather than allow.
 */
function brvtalMailAdminMediaAuthorized(PDO $pdo): callable
{
    return static function (int $id) use ($pdo): bool {
        $stmt = $pdo->prepare("SELECT id FROM media WHERE id=? AND status='published' AND type='image' LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    };
}

/** The preview is plain structured copy, with only a synthetic event variable. */
function brvtalMailAdminPreview(array $document): array
{
    $result = $document;
    foreach (['subject','preheader','title','message','cta','footer'] as $field) {
        if (isset($result[$field]) && is_string($result[$field])) {
            $result[$field] = str_replace('{{event_name}}', 'Evento de prueba', $result[$field]);
        }
    }
    return ['template_copy'=>$result, 'synthetic'=>true, 'mail_sent'=>false];
}

/**
 * Auditing is metadata-only. Tests inject an audit sink; normal requests use
 * Admin Activity and fail closed if its schema is not available before writing.
 * Never pass document/body, request or recipient data to the logger.
 */
function brvtalMailAdminAudit(
    PDO $pdo, string $action, string $key, string $scope, array $result,
    ?callable $audit = null
): void {
    $metadata = ['key'=>$key, 'scope'=>$scope, 'version_id'=>(int)$result['version_id'],
        'revision'=>(int)$result['revision']];
    if ($audit !== null) {
        $audit($action, $metadata);
        return;
    }
    require_once __DIR__ . '/admin_activity.php';
    brvtal_activity_record($pdo, $action, 'mail_templates', null, null, null, $metadata, $key);
}

/** @return array<string,mixed> */
function brvtalMailAdminDispatch(
    PDO $pdo, string $role, int $actorId, string $method, array $request,
    ?callable $audit = null
): array {
    brvtalMailAdminRequire($role, false);
    if ($actorId < 1) {
        throw new DomainException('FORBIDDEN');
    }
    if ($method === 'GET') {
        $view = $request['view'];
        if ($view === 'catalog') {
            $catalog = [];
            foreach (brvtal_mail_template_catalog() as $key => $definition) {
                $catalog[] = ['key'=>$key, 'scope'=>$definition['scope'],
                    'allowed_variables'=>$definition['allowed_variables'],
                    'defaults'=>$definition['defaults']];
            }
            return ['items'=>$catalog];
        }
        $key = $request['key']; $scope = $request['scope'];
        if ($view === 'draft') {
            $draft = brvtal_mail_template_read_draft($pdo, $key, $scope, $request['version_id']);
            if ($draft === null) throw new DomainException('MAIL_TEMPLATE_VERSION_UNKNOWN');
            return $draft;
        }
        if ($view === 'history') {
            return ['items'=>brvtal_mail_template_versions($pdo, $key, $scope)];
        }
        if ($view === 'active') {
            $resolved = brvtal_mail_template_resolve($pdo, $key, $scope);
            try {
                $st = $pdo->prepare('SELECT revision,published_version_id FROM mail_template_registry
                    WHERE template_key=? AND scope=? LIMIT 1');
                $st->execute([$key, $scope]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                if ($e->getCode() !== '42S02') throw $e;
                $row = false;
            }
            return $resolved + ['revision'=>is_array($row) ? (int)$row['revision'] : 0,
                'version_id'=>is_array($row) && $row['published_version_id'] !== null
                    ? (int)$row['published_version_id'] : null];
        }
        throw new InvalidArgumentException('MAIL_TEMPLATE_VIEW_INVALID');
    }
    if ($method !== 'POST') {
        throw new InvalidArgumentException('MAIL_TEMPLATE_METHOD_INVALID');
    }
    $action = $request['action'];
    $key = $request['key']; $scope = $request['scope'];
    $canMedia = brvtalMailAdminMediaAuthorized($pdo);
    if ($action === 'preview') {
        $document = brvtal_mail_template_validate($key, $scope, $request['template_copy'], $canMedia);
        return brvtalMailAdminPreview($document);
    }
    brvtalMailAdminRequire($role, true);
    if ($audit === null) {
        require_once __DIR__ . '/admin_activity.php';
        if (!brvtal_activity_schema_ready($pdo)) {
            throw new RuntimeException('MAIL_TEMPLATE_AUDIT_UNAVAILABLE');
        }
    }
    $result = match ($action) {
        'save_draft' => brvtal_mail_template_save_draft(
            $pdo, $key, $scope, $request['template_copy'], $actorId, $request['revision'], $canMedia),
        'publish' => brvtal_mail_template_publish(
            $pdo, $key, $scope, $request['version_id'], $actorId, $request['revision'], $canMedia),
        'restore' => brvtal_mail_template_rollback(
            $pdo, $key, $scope, $request['previous_version_id'], $actorId, $request['revision'], $canMedia),
        default => throw new InvalidArgumentException('MAIL_TEMPLATE_ACTION_INVALID'),
    };
    brvtalMailAdminAudit($pdo, $action, $key, $scope, $result, $audit);
    return $result + ['mail_sent'=>false];
}
