<?php
declare(strict_types=1);

require_once __DIR__ . '/form_schema.php';
require_once __DIR__ . '/admin_activity.php';

function brvtalFormDefinitionRequire(PDO $pdo, int $actor, bool $lock = false): void
{
    $q = $pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$actor]);
    if (!in_array($q->fetchColumn(), ['owner', 'superadmin'], true)) throw new DomainException('FORBIDDEN');
}

function brvtalFormDefinitionFields(array $data, array $allowed): void
{
    if (count($data) !== count($allowed) || array_diff($allowed, array_keys($data))) brvtalFormSchemaFail();
}

function brvtalFormDefinitionJson(array $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function brvtalFormDefinitionDraft(mixed $draft, string $key, bool $publish = false): array
{
    if (!is_array($draft)) brvtalFormSchemaFail();
    brvtalFormDefinitionFields($draft, ['title', 'fields', 'privacy', 'delivery']);
    if (!brvtalFormSchemaCopy($draft['title'], 120) || !is_array($draft['fields']) || !array_is_list($draft['fields'])) brvtalFormSchemaFail();
    $definition = ['form_key'=>$key, 'version_number'=>1, 'fields'=>$draft['fields']];
    $fields = ($publish || $draft['fields'] !== []) ? brvtalFormSchemaValidate($definition) : [];
    $privacy = $draft['privacy'];
    if ($privacy !== null || $publish) {
        if (!is_array($privacy)) brvtalFormSchemaFail();
        brvtalFormDefinitionFields($privacy, ['notice_version','notice_text','purpose_key','retention_policy_key']);
        if (!is_string($privacy['notice_version']) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $privacy['notice_version'])
            || !brvtalFormSchemaCopy($privacy['notice_text'], 4000)) brvtalFormSchemaFail();
        foreach (['purpose_key','retention_policy_key'] as $name) {
            if (!is_string($privacy[$name]) || !preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $privacy[$name])) brvtalFormSchemaFail();
        }
    }
    $delivery = $draft['delivery'];
    if (!is_array($delivery)) brvtalFormSchemaFail();
    brvtalFormDefinitionFields($delivery, ['actions']);
    // Internal intent only; no recipient, template content, external URL or transport.
    if (!in_array($delivery['actions'], [[], ['form_entry_received']], true)) brvtalFormSchemaFail();
    if ($publish) {
        $consent = array_filter($fields, static fn(array $f): bool => $f['type'] === 'consent' && $f['purpose'] === 'privacy'
            && $f['notice_version'] === $privacy['notice_version']);
        if (count($consent) !== 1) brvtalFormSchemaFail();
    }
    if (strlen(brvtalFormDefinitionJson($draft)) > 65536) brvtalFormSchemaFail();
    return $draft;
}

function brvtalFormDefinitionRow(PDO $pdo, string $key, bool $lock = false): array
{
    $q = $pdo->prepare('SELECT form_key, LOWER(HEX(public_id)) AS public_id, slug, revision, draft_json, state, published_version_number
        FROM form_definition_registry WHERE form_key=?' . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$key]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new DomainException('FORM_NOT_FOUND');
    $row['revision'] = (int)$row['revision'];
    $row['published_version_number'] = $row['published_version_number'] === null ? null : (int)$row['published_version_number'];
    $row['draft'] = json_decode($row['draft_json'], true, 16, JSON_THROW_ON_ERROR);
    unset($row['draft_json']);
    return $row;
}

function brvtalFormDefinitionRead(PDO $pdo, int $actor, array $input): array
{
    brvtalFormDefinitionRequire($pdo, $actor);
    if (array_key_exists('form_key', $input)) {
        brvtalFormDefinitionFields($input, ['form_key']);
        if (!brvtalFormSchemaId($input['form_key'])) brvtalFormSchemaFail();
        return brvtalFormDefinitionRow($pdo, $input['form_key']);
    }
    if ($input !== [] && (array_keys($input) !== ['after'] || !brvtalFormSchemaId($input['after']))) brvtalFormSchemaFail();
    $q = $pdo->prepare('SELECT form_key, LOWER(HEX(public_id)) AS public_id, slug, revision, state, published_version_number
        FROM form_definition_registry WHERE form_key>? ORDER BY form_key LIMIT 101');
    $q->execute([$input['after'] ?? '']);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > 100;
    if ($more) array_pop($rows);
    return ['items'=>$rows, 'next_cursor'=>$more ? $rows[count($rows)-1]['form_key'] : null];
}

function brvtalFormDefinitionInsert(PDO $pdo, string $key, string $slug, array $draft): void
{
    $pdo->prepare('INSERT INTO form_definition_registry (form_key,public_id,slug,draft_json,created_at_utc,updated_at_utc)
        VALUES (?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))')->execute([$key,random_bytes(16),$slug,brvtalFormDefinitionJson($draft)]);
}

function brvtalFormDefinitionPublish(PDO $pdo, string $key, array $draft): int
{
    $draft = brvtalFormDefinitionDraft($draft, $key, true);
    $q = $pdo->prepare('SELECT COALESCE(MAX(version_number),0) FROM form_definition_versions WHERE form_key=?');
    $q->execute([$key]);
    $version = (int)$q->fetchColumn() + 1;
    $definition = ['form_key'=>$key,'version_number'=>$version,'fields'=>$draft['fields']];
    brvtalFormSchemaValidate($definition);
    $json = brvtalFormDefinitionJson($definition);
    $digest = hash('sha256', $json, true);
    $pdo->prepare('INSERT INTO form_definition_versions VALUES (?,?,?,?,UTC_TIMESTAMP(6))')->execute([$key,$version,$digest,$json]);
    // Snapshot the display title with the notice context; never read it from a later draft.
    $policy = $draft['privacy'] + ['form_title'=>$draft['title']];
    $pdo->prepare('INSERT INTO form_publication_policies VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))')
        ->execute([$key,$version,$digest,$policy['notice_version'],brvtalFormDefinitionJson($policy),brvtalFormDefinitionJson($draft['delivery'])]);
    return $version;
}

function brvtalFormDefinitionWrite(PDO $pdo, int $actor, array $input): array
{
    $action = $input['action'] ?? null;
    $required = match ($action) {
        'create' => ['action','slug','draft'],
        'save' => ['action','form_key','revision','slug','draft'],
        'publish','disable' => ['action','form_key','revision'],
        'duplicate' => ['action','form_key','revision','slug'],
        default => throw new InvalidArgumentException('INVALID_ACTION'),
    };
    brvtalFormDefinitionFields($input, $required);
    if (array_key_exists('slug', $input) && (!is_string($input['slug']) || strlen($input['slug']) > 128
        || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $input['slug']))) brvtalFormSchemaFail();
    if ($action !== 'create' && (!brvtalFormSchemaId($input['form_key']) || !is_int($input['revision'])
        || $input['revision'] < 1 || $input['revision'] >= 4294967295)) brvtalFormSchemaFail();
    $pdo->beginTransaction();
    try {
        brvtalFormDefinitionRequire($pdo, $actor, true);
        $key = $input['form_key'] ?? 'f_' . bin2hex(random_bytes(16));
        $row = $action === 'create' ? null : brvtalFormDefinitionRow($pdo, $key, true);
        if ($row !== null && $row['revision'] !== $input['revision']) throw new DomainException('FORM_CONFLICT');
        if (in_array($action, ['create','duplicate'], true)) {
            if ($action === 'duplicate') $key = 'f_' . bin2hex(random_bytes(16));
            $draft = brvtalFormDefinitionDraft($action === 'create' ? $input['draft'] : $row['draft'], $key);
            brvtalFormDefinitionInsert($pdo, $key, $input['slug'], $draft);
        } else {
            $draft = $action === 'save' ? brvtalFormDefinitionDraft($input['draft'], $key) : $row['draft'];
            $published = $action === 'publish' ? brvtalFormDefinitionPublish($pdo, $key, $draft) : $row['published_version_number'];
            $state = match ($action) {'publish'=>'active', 'disable'=>'disabled', default=>$row['state']};
            $q = $pdo->prepare('UPDATE form_definition_registry SET slug=?,draft_json=?,state=?,published_version_number=?,
                revision=revision+1,updated_at_utc=UTC_TIMESTAMP(6) WHERE form_key=? AND revision=?');
            $q->execute([$input['slug'] ?? $row['slug'],brvtalFormDefinitionJson($draft),$state,$published,$key,$row['revision']]);
            if ($q->rowCount() !== 1) throw new DomainException('FORM_CONFLICT');
        }
        $result = brvtalFormDefinitionRow($pdo, $key);
        if (brvtal_activity_record($pdo, 'form_' . $action, 'form_definitions', null, null, null,
            ['form_key'=>$key,'revision'=>$result['revision'],'published_version'=>$result['published_version_number']]) === null) {
            throw new RuntimeException('FORM_AUDIT_UNAVAILABLE');
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) throw new DomainException('FORM_CONFLICT');
        throw $e;
    }
}
