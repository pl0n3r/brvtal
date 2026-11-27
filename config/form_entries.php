<?php
declare(strict_types=1);

/** Explicit configuration only; an unset flag preserves legacy delivery. */
function brvtal_contact_capture_policy(array $config): ?array
{
    $policy = $config['contact']['capture'] ?? null;
    if ($policy === null || (is_array($policy) && ($policy['enabled'] ?? false) === false)) return null;
    if (!is_array($policy) || ($policy['enabled'] ?? null) !== true
        || ($policy['privacy_approved'] ?? null) !== true
        || !is_int($policy['retention_days'] ?? null) || $policy['retention_days'] < 1
        || $policy['retention_days'] > 36500) {
        throw new RuntimeException('CONTACT_CAPTURE_UNAVAILABLE');
    }
    foreach (['notice_version', 'retention_policy_key'] as $field) {
        if (!is_string($policy[$field] ?? null) || !preg_match('/\A[a-zA-Z0-9_.-]{1,64}\z/', $policy[$field])) {
            throw new RuntimeException('CONTACT_CAPTURE_UNAVAILABLE');
        }
    }
    return $policy;
}

/** Accept a random browser intent, never the CAPTCHA token or a subject identifier. */
function brvtal_contact_capture_key(mixed $key): string
{
    if (!is_string($key) || !preg_match('/\A[a-f0-9]{32}\z/', $key)) {
        throw new InvalidArgumentException('CONTACT_INTENT_INVALID');
    }
    return hash('sha256', 'contact:' . $key, true);
}

/** Capture only the four fields already validated by the public contact endpoint. */
function brvtal_contact_capture(PDO $pdo, array $data, mixed $key, array $config): bool
{
    $policy = brvtal_contact_capture_policy($config);
    if ($policy === null || $pdo->inTransaction()) throw new RuntimeException('CONTACT_CAPTURE_UNAVAILABLE');
    $digest = brvtal_contact_capture_key($key);
    $limits = ['name' => [2,100], 'email' => [1,254], 'subject' => [2,140], 'message' => [10,5000]];
    $payload = [];
    foreach ($limits as $field => [$min, $max]) {
        $value = $data[$field] ?? null;
        if (!is_string($value) || strlen($value) > $max * 4 || preg_match('/\A.{' . $min . ',' . $max . '}\z/us', $value) !== 1
            || trim($value) === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('CONTACT_INTENT_INVALID');
        }
        $payload[$field] = $value;
    }
    if (strlen($payload['email']) > 254 || !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('CONTACT_INTENT_INVALID');
    }
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    // Native Contact keeps its established 5000-character limit; this is not a builder-authored form.
    $fields = [];
    foreach (['name'=>['Nombre','text'], 'email'=>['Email','email'], 'subject'=>['Asunto','text'], 'message'=>['Mensaje','textarea']] as $field => [$label,$type]) {
        $fields[] = ['id'=>$field,'order'=>count($fields)+1,'label'=>$label,'type'=>$type,
            'required'=>true,'max_length'=>$limits[$field][1]];
    }
    $definition = json_encode(['form_key'=>'contact', 'version_number'=>1, 'fields'=>$fields], JSON_THROW_ON_ERROR);
    $definitionDigest = hash('sha256', $definition, true);
    $entryId = random_bytes(16);
    $created = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $now = $created->format('Y-m-d H:i:s.u');
    $until = $created->modify('+' . $policy['retention_days'] . ' days')->format('Y-m-d H:i:s.u');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO form_definition_versions VALUES ('contact',1,?,?,?) ON DUPLICATE KEY UPDATE form_key=form_key")
            ->execute([$definitionDigest,$definition,$now]);
        $version = $pdo->query("SELECT definition_sha256 FROM form_definition_versions WHERE form_key='contact' AND version_number=1 FOR UPDATE")->fetchColumn();
        if (!is_string($version) || !hash_equals($definitionDigest,$version)) throw new RuntimeException('CONTACT_CAPTURE_UNAVAILABLE');
        $fresh = brvtalFormEntryPersist($pdo, ['entry_id'=>$entryId,'form_key'=>'contact','version_number'=>1,
            'definition_sha256'=>$definitionDigest,'idempotency_digest'=>$digest,'payload_json'=>$json,
            'created_at_utc'=>$now,'purpose_key'=>'contact_capture','notice_version'=>$policy['notice_version'],
            'retention_policy_key'=>$policy['retention_policy_key'],'retention_until_utc'=>$until], ['contact_notify']);
        $pdo->commit();
        return $fresh;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Called inside the transaction holding the immutable version/registry lock. */
function brvtalFormEntryPersist(PDO $pdo, array $entry, array $actions): bool
{
    $pdo->prepare("INSERT INTO form_entries (entry_id,form_key,version_number,definition_sha256,idempotency_digest,
        payload_json,created_at_utc,purpose_key,notice_version,retention_policy_key,retention_until_utc,state)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'received') ON DUPLICATE KEY UPDATE entry_id=entry_id")
        ->execute(array_values($entry));
    $q = $pdo->prepare("SELECT * FROM form_entries WHERE form_key=? AND idempotency_digest=? FOR UPDATE");
    $q->execute([$entry['form_key'], $entry['idempotency_digest']]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $conflict = $entry['form_key'] === 'contact' ? 'CONTACT_INTENT_CONFLICT' : 'FORM_INTENT_CONFLICT';
    if (!$row || $row['state'] === 'redacted' || (int)$row['version_number'] !== $entry['version_number']) throw new DomainException($conflict);
    foreach (['definition_sha256','payload_json','purpose_key','notice_version','retention_policy_key'] as $key) {
        if (!is_string($row[$key]) || !hash_equals($row[$key], $entry[$key])) throw new DomainException($conflict);
    }
    $fresh = hash_equals($entry['entry_id'], $row['entry_id']);
    if ($fresh) {
        $q = $pdo->prepare("INSERT INTO form_notification_intents VALUES (?,?,'pending',?)");
        foreach ($actions as $action) $q->execute([$row['entry_id'], $action, $entry['created_at_utc']]);
    } else {
        $q = $pdo->prepare("SELECT action_key FROM form_notification_intents WHERE entry_id=? ORDER BY action_key");
        $q->execute([$row['entry_id']]);
        if ($q->fetchAll(PDO::FETCH_COLUMN) !== $actions) throw new RuntimeException('FORM_CAPTURE_UNAVAILABLE');
    }
    return $fresh;
}

function brvtalFormsCaptureEnabled(array $config): bool
{
    return ($config['forms']['public_submit_enabled'] ?? null) === true
        && ($config['forms']['privacy_approved'] ?? null) === true;
}

/** Server allowlist is tied to one version and approved immutable policy, never a client-supplied scope. */
function brvtalFormsPublished(PDO $pdo, array $config, string $id, int $version): array
{
    $approved = $config['forms']['public_submit_policies'][$id] ?? null;
    if (!brvtalFormsCaptureEnabled($config) || !is_array($approved)
        || !is_int($approved['retention_days'] ?? null) || $approved['retention_days'] < 1
        || $approved['retention_days'] > 36500) throw new RuntimeException('FORM_CAPTURE_UNAVAILABLE');
    $q = $pdo->prepare("SELECT r.form_key,r.state,r.published_version_number,v.definition_json,v.definition_sha256,
        p.notice_version,p.privacy_policy_json,p.delivery_policy_json FROM form_definition_registry r
        JOIN form_definition_versions v ON v.form_key=r.form_key AND v.version_number=r.published_version_number
        JOIN form_publication_policies p ON p.form_key=v.form_key AND p.version_number=v.version_number
        AND p.definition_sha256=v.definition_sha256 WHERE r.public_id=UNHEX(?)"
        . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['state'] !== 'active' || (int)$row['published_version_number'] !== $version) throw new DomainException('FORM_VERSION_CONFLICT');
    if ($row['form_key'] === 'contact' || ($approved['version_number'] ?? null) !== $version
        || !hash_equals($row['definition_sha256'], hash('sha256', $row['definition_json'], true))) throw new RuntimeException('FORM_CAPTURE_UNAVAILABLE');
    require_once __DIR__ . '/form_schema.php';
    $definition = json_decode($row['definition_json'], true, 16, JSON_THROW_ON_ERROR);
    $policy = json_decode($row['privacy_policy_json'], true, 16, JSON_THROW_ON_ERROR);
    $delivery = json_decode($row['delivery_policy_json'], true, 16, JSON_THROW_ON_ERROR);
    try { $fields = brvtalFormSchemaValidate($definition); }
    catch (InvalidArgumentException) { throw new RuntimeException('FORM_CAPTURE_UNAVAILABLE'); }
    if ($definition['form_key'] !== $row['form_key'] || $definition['version_number'] !== $version
        || !is_array($policy) || count($policy) !== 5 || !brvtalFormSchemaCopy($policy['form_title'] ?? null, 120)
        || !brvtalFormSchemaCopy($policy['notice_text'] ?? null, 4000)
        || ($policy['notice_version'] ?? null) !== $row['notice_version']
        || !in_array($delivery, [['actions'=>[]], ['actions'=>['form_entry_received']]], true)) throw new RuntimeException('FORM_CAPTURE_UNAVAILABLE');
    foreach (['purpose_key','retention_policy_key','notice_version'] as $key) {
        if (!is_string($policy[$key] ?? null) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/D', $policy[$key])
            || $policy[$key] !== ($approved[$key] ?? null)) throw new RuntimeException('FORM_CAPTURE_UNAVAILABLE');
    }
    $consent = array_filter($fields, static fn(array $f): bool => $f['type'] === 'consent'
        && $f['purpose'] === 'privacy' && $f['notice_version'] === $policy['notice_version']);
    if (count($consent) !== 1) throw new RuntimeException('FORM_CAPTURE_UNAVAILABLE');
    return [$definition, $row['definition_sha256'], $policy, $delivery['actions'], $approved['retention_days']];
}

function brvtalFormsSubmit(PDO $pdo, array $config, string $id, int $version, string $key, string $raw): void
{
    if ($pdo->inTransaction() || !preg_match('/\A[a-f0-9]{32}\z/D', $id)
        || !preg_match('/\A[a-f0-9]{32}\z/D', $key)) throw new InvalidArgumentException('FORM_PAYLOAD_INVALID');
    $pdo->beginTransaction();
    try {
        [$definition,$digest,$policy,$actions,$days] = brvtalFormsPublished($pdo,$config,$id,$version);
        $values = brvtalFormPayloadValidate($definition,$definition['form_key'],$version,$raw);
        foreach ($values as $value) if (is_string($value) && preg_match('~[<>]|(?:https?|javascript|data|vbscript|file):|//~iu', $value) !== 0) brvtalFormPayloadFail();
        $json = json_encode((object)$values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        brvtalFormEntryPersist($pdo, ['entry_id'=>random_bytes(16),'form_key'=>$definition['form_key'],
            'version_number'=>$version,'definition_sha256'=>$digest,'idempotency_digest'=>hash('sha256','forms:'.$id.':'.$key,true),
            'payload_json'=>$json,'created_at_utc'=>$now->format('Y-m-d H:i:s.u'),'purpose_key'=>$policy['purpose_key'],
            'notice_version'=>$policy['notice_version'],'retention_policy_key'=>$policy['retention_policy_key'],
            'retention_until_utc'=>$now->modify('+'.$days.' days')->format('Y-m-d H:i:s.u')], $actions);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
