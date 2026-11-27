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
        $pdo->prepare("INSERT INTO form_entries (entry_id,form_key,version_number,definition_sha256,idempotency_digest,payload_json,created_at_utc,purpose_key,notice_version,retention_policy_key,retention_until_utc,state) VALUES (?,'contact',1,?,?,?,?,'contact_capture',?,?,?,'received') ON DUPLICATE KEY UPDATE entry_id=entry_id")
            ->execute([$entryId,$definitionDigest,$digest,$json,$now,$policy['notice_version'],$policy['retention_policy_key'],$until]);
        $select = $pdo->prepare("SELECT entry_id,payload_json,state FROM form_entries WHERE form_key='contact' AND idempotency_digest=? FOR UPDATE");
        $select->execute([$digest]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['state'] === 'redacted' || !is_string($row['payload_json']) || !hash_equals($row['payload_json'],$json)) {
            throw new DomainException('CONTACT_INTENT_CONFLICT');
        }
        $fresh = hash_equals($entryId,$row['entry_id']);
        if ($fresh) {
            $pdo->prepare("INSERT INTO form_notification_intents VALUES (?,'contact_notify','pending',?)")->execute([$entryId,$now]);
        } else {
            $intent = $pdo->prepare("SELECT state FROM form_notification_intents WHERE entry_id=? AND action_key='contact_notify'");
            $intent->execute([$row['entry_id']]);
            if ($intent->fetchColumn() === false) throw new RuntimeException('CONTACT_CAPTURE_UNAVAILABLE');
        }
        $pdo->commit();
        return $fresh;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
